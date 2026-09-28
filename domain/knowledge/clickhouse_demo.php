<?php

// ClickHouse 使用 demo 的数据访问封装（页面入口与 API 入口共用）
//
// 覆盖 clickhouse.php 的全部公开函数：ch_ping / ch_query / ch_query_first / ch_query_column /
// ch_query_value / ch_write / ch_insert_rows，表结构见
// command/migration/clickhouse_sql/*_create_demo_user_event_table.sql
//
// 使用要点（写法不是随手写的）：
//   1. 绑定值走 {name:Type} 占位符 + 数组参数，标量直接传，数组（Array / Map）传 PHP 数组即可，不拼 SQL
//   2. 64 位整数与 Decimal 默认以字符串返回（框架 settings 里的安全默认），要数字类型就在
//      config/clickhouse.php 的 settings 里把 output_format_json_quote_64bit_integers / _decimals 设为 0
//   3. ch_query_first 未命中返回 null，SQL 自己带 limit 时不会再追加
//   4. ch_insert_rows 单次请求只发一批数据：批量写入按 chunk 分批调用，避免一次拼出过大的 body
//   5. ch_insert_rows 每一行的字段必须完全一致：缺字段 ClickHouse 会静默填默认值（DateTime 变成 1970）
//   6. update / delete 是异步 mutation：要立刻读到结果就得带 mutations_sync

// 单次批量写入的行数上限，避免一个请求拼出过大的 body
define('CLICKHOUSE_DEMO_CHUNK_SIZE', 5000);

define('CLICKHOUSE_DEMO_EVENT_TYPES', ['view', 'search', 'add_to_cart', 'pay', 'refund']);
define('CLICKHOUSE_DEMO_CHANNELS', ['app', 'web', 'h5', 'mini']);

// 连通性检查：连接或认证失败返回 false，不抛异常
function clickhouse_demo_status(): array
{
    $connected = ch_ping();

    return [
        'connected' => $connected,
        'version' => $connected ? ch_query_value('version', 'select version() as `version`') : null,
    ];
}

// 概览指标：ch_query_value 取单个值，空结果返回 null
function clickhouse_demo_overview(): array
{
    return [
        'total' => (int) ch_query_value(
            'c',
            'select count() as `c` from `demo_user_event`'),

        'today' => (int) ch_query_value(
            'c',
            'select count() as `c` from `demo_user_event` where `event_date` = today()'),

        'user_count' => (int) ch_query_value(
            'c',
            'select uniqExact(`user_id`) as `c` from `demo_user_event`'),

        // 这里的 toString 是显式写法：Decimal 默认也以字符串返回，加不加都不丢精度
        'amount' => ch_query_value(
            'a',
            'select toString(sum(`amount`)) as `a` from `demo_user_event`'),
    ];
}

// 按天趋势：演示参数绑定 {name:Type}，值走 param_* 查询串由 ClickHouse 服务端转义，不做 SQL 拼接
function clickhouse_demo_daily_trend($days): array
{
    return ch_query(
        'select
            `event_date`,
            count() as `count`,
            toString(sum(`amount`)) as `amount`
        from `demo_user_event`
        where `event_date` >= today() - {days:UInt32}
        group by `event_date`
        order by `event_date`',
        ['days' => $days - 1]);
}

// 事件类型分布：ch_query 取明细，ch_query_column 取单列
function clickhouse_demo_event_type_stats(): array
{
    return [
        'rows' => ch_query(
            'select
                `event_type`,
                count() as `count`,
                toString(sum(`amount`)) as `amount`
            from `demo_user_event`
            group by `event_type`
            order by `count` desc'),

        'types' => ch_query_column(
            'event_type',
            'select distinct `event_type` from `demo_user_event` order by `event_type`'),
    ];
}

// 事件明细分页：可选筛选条件按需拼占位符，绑定值始终走 params
function clickhouse_demo_find_events($page, $size, $event_type = ''): array
{
    $condition = '';
    $binds = [];

    if ($event_type !== '') {
        $condition = 'where `event_type` = {event_type:String}';
        $binds['event_type'] = $event_type;
    }

    $count = (int) ch_query_value(
        'c',
        'select count() as `c` from `demo_user_event` '.$condition,
        $binds);

    $list = ch_query(
        'select
            `event_time`,
            `user_id`,
            `event_type`,
            `channel`,
            toString(`amount`) as `amount`,
            `duration_ms`,
            `props`
        from `demo_user_event`
        '.$condition.'
        order by `event_time` desc
        limit {limit:UInt32} offset {offset:UInt32}',
        $binds + [
            'limit' => $size,
            'offset' => ($page - 1) * $size,
        ]);

    return [
        'list' => $list,
        'pagination' => [
            'page' => $page,
            'size' => $size,
            'count' => $count,
            'pages' => (int) ceil($count / $size),
        ],
    ];
}

// 最新一条：ch_query_first 未命中返回 null
// 这条 SQL 自己带了 order by 但不带 limit，由 ch_query_first 补 limit 1
function clickhouse_demo_find_latest(): array
{
    return ['latest' => ch_query_first(
        'select
            `event_time`,
            `user_id`,
            `event_type`,
            `channel`,
            toString(`amount`) as `amount`
        from `demo_user_event`
        order by `event_time` desc')];
}

// 造数：批量写入，按 chunk 分批调 ch_insert_rows
function clickhouse_demo_insert_seed_rows($rows): int
{
    $written = 0;

    for ($offset = 0; $offset < $rows; $offset += CLICKHOUSE_DEMO_CHUNK_SIZE) {

        $list = [];

        for ($i = 0; $i < min(CLICKHOUSE_DEMO_CHUNK_SIZE, $rows - $offset); $i++) {

            $event_time = date('Y-m-d H:i:s', time() - mt_rand(0, 14 * 86400));
            $event_type = CLICKHOUSE_DEMO_EVENT_TYPES[mt_rand(0, count(CLICKHOUSE_DEMO_EVENT_TYPES) - 1)];

            // 每行的键必须完全一致，缺键的列会被 ClickHouse 静默填默认值
            $list[] = [
                'event_date' => substr($event_time, 0, 10),
                'event_time' => $event_time,
                'user_id' => mt_rand(1, 500),
                'event_type' => $event_type,
                // Decimal 用字符串传，避免浮点数在 JSON 里变成科学计数法
                'amount' => $event_type === 'pay' ? number_format(mt_rand(1, 200000) / 100, 2, '.', '') : '0.00',
                'channel' => CLICKHOUSE_DEMO_CHANNELS[mt_rand(0, count(CLICKHOUSE_DEMO_CHANNELS) - 1)],
                'duration_ms' => mt_rand(10, 3000),
                'props' => json(['path' => '/p/'.mt_rand(1, 50), 'ip' => '10.0.'.mt_rand(0, 255).'.'.mt_rand(1, 254)]),
                'create_time' => datetime(),
            ];
        }

        $written += ch_insert_rows('demo_user_event', $list);
    }

    return $written;
}

// 清空：ch_write 执行非查询语句，返回值取自服务端汇总，DDL 与 mutation 恒为 0，不能当成功判据
function clickhouse_demo_clear(): int
{
    return ch_write('truncate table `demo_user_event`');
}

// 清理历史数据：ClickHouse 的 update/delete 是异步 mutation
// 必须带 mutations_sync = 2（等待全部副本执行完），否则紧接着的查询仍会看到旧数据
function clickhouse_demo_purge($days): int
{
    return ch_write(
        'alter table `demo_user_event` delete where `event_date` < today() - {days:UInt32} settings mutations_sync = 2',
        ['days' => $days]);
}
