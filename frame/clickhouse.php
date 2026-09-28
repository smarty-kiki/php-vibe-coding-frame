<?php

// ClickHouse HTTP 接口客户端（默认 8123 端口），HTTP 无长连接状态，每次请求按配置拼 URL 直接发
// 绑定值走 param_* 查询串 + SQL 里的 {name:Type} 占位符，由 ClickHouse 服务端做转义，避免拼接 SQL

// 解析连接配置并补齐默认值
function _clickhouse_config($config_key)
{
    $config = config_midware('clickhouse', $config_key);

    otherwise(
        not_empty(array_get($config, 'host')),
        'clickhouse 配置缺少 host，检查 config/clickhouse.php 的 resources 与传入的 config_key：'.$config_key,
        'exception',
        'CLICKHOUSE_CONFIG');

    otherwise(
        not_empty(array_get($config, 'port')),
        'clickhouse 配置缺少 port，检查 config/clickhouse.php 的 resources 与传入的 config_key：'.$config_key,
        'exception',
        'CLICKHOUSE_CONFIG');

    $config['database'] = $config['database'] ?? 'default';
    $config['username'] = $config['username'] ?? 'default';
    $config['password'] = $config['password'] ?? '';
    $config['timeout'] = $config['timeout'] ?? 10;

    // 默认让 64 位整数与 Decimal 以字符串返回：JSON 里它们会退化成 double，超过 2^53 的值静默丢精度
    // 需要数字类型时在 config/clickhouse.php 的 settings 里显式设为 0 覆盖
    $config['settings'] = ($config['settings'] ?? []) + [
        'output_format_json_quote_64bit_integers' => 1,
        'output_format_json_quote_decimals' => 1,
    ];

    return $config;
}

// 绑定值编码：标量原样交给 http_build_query（服务端负责转义）
// 数组 / 映射要拼成 ClickHouse 字面量 ['a','b'] / {'k':'v'}——直接交给 http_build_query 会编成 param_x[0]=a，
// 服务端认不出 {x:Array(String)} 这类占位符，报 Substitution `x` is not set
function _clickhouse_param_value($value, $in_literal = false)
{
    if (is_array($value)) {

        $is_list = array_keys($value) === range(0, count($value) - 1);

        $items = [];

        foreach ($value as $key => $item) {

            $items[] = $is_list
                ? _clickhouse_param_value($item, true)
                : _clickhouse_param_quote($key).':'._clickhouse_param_value($item, true);
        }

        return $is_list ? '['.implode(',', $items).']' : '{'.implode(',', $items).'}';
    }

    if ($in_literal) {
        return is_string($value) ? _clickhouse_param_quote($value) : json($value);
    }

    return $value;
}

// 字面量里的字符串按 ClickHouse 转义规则处理：反斜杠、单引号、换行、制表符
function _clickhouse_param_quote($value)
{
    return "'".str_replace(['\\', "'", "\n", "\r", "\t"], ['\\\\', "\\'", '\\n', '\\r', '\\t'], (string) $value)."'";
}

// $data 非 null 时作为 POST body 传输（批量写入场景），此时 SQL 改走 query 参数
// $default_format 非 null 时随请求下发 default_format，作为 SQL 未显式写 format 时的默认输出格式
// 返回 [响应体, 解析后的 X-ClickHouse-Summary 响应头, X-ClickHouse-Format 响应头]
function _clickhouse_request(array $config, $sql, array $binds, $data = null, $default_format = null)
{
    $query = $config['settings'];

    if (not_empty($config['database'])) {
        $query['database'] = $config['database'];
    }

    if (not_empty($default_format)) {
        $query['default_format'] = $default_format;
    }

    foreach ($binds as $key => $value) {
        $query['param_'.$key] = _clickhouse_param_value($value);
    }

    if (is_null($data)) {
        $body = $sql;
    } else {
        $query['query'] = $sql;
        $body = $data;
    }

    // 报错只带基础地址：查询串里有 param_* 绑定值，不该进异常日志
    $base_url = 'http://'.$config['host'].':'.$config['port'].'/';

    $url = $base_url;

    if (not_empty($query)) {
        $url .= '?'.http_build_query($query);
    }

    $summary = [];
    $format = '';

    $raw = http([
        'url' => $url,
        // 写请求重试可能重复写入，这里不做重试
        'retry' => 1,
        'method' => 'POST',
        'data' => $body,
        'timeout' => $config['timeout'],
        'header' => [
            'X-ClickHouse-User: '.$config['username'],
            'X-ClickHouse-Key: '.$config['password'],
        ],
        'option' => [
            CURLOPT_RETURNTRANSFER => true,
            // 写入行数等统计只出现在响应头里，错误信息则在响应体里
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$summary, &$format) {

                if (0 === stripos($line, 'X-ClickHouse-Summary:')) {
                    $summary = json_decode(trim(explode(':', $line, 2)[1]), true);
                }

                // 实际生效的输出格式，用于确认响应体是不是按预期格式返回
                if (0 === stripos($line, 'X-ClickHouse-Format:')) {
                    $format = trim(explode(':', $line, 2)[1]);
                }

                return strlen($line);
            },
        ],
        0 => function ($raw, $code) use ($base_url) {

            if (200 !== $code) {
                throw new Exception('clickhouse '.$base_url.' ['.$code.'] '.trim($raw));
            }

            return $raw;
        },
    ]);

    return [$raw, $summary, $format];
}

function _clickhouse_closure($config_key, closure $closure)
{
    return call_user_func($closure, _clickhouse_config($config_key));
}

// 查询返回关联数组列表，SQL 中用 {name:Type} 占位符接收 binds，如 where id = {id:UInt64}
// 输出格式走 default_format 参数，不在 SQL 末尾拼 ` format JSONEachRow`：拼接方式会被 SQL 末尾的 -- 行注释吃掉，
// 查询退化成默认 TSV 后仍被当 JSON 解析，静默返回坏数据；分号结尾的 SQL 也会因拼接而变成多语句报错
function ch_query($sql, array $binds = [], $config_key = 'default')
{
    return _clickhouse_closure($config_key, function ($config) use ($sql, $binds) {

        list($raw, , $format) = _clickhouse_request($config, $sql, $binds, null, 'JSONEachRow');

        // SQL 里自己写了 format 时以它为准，此时响应体不是 JSONEachRow，按行解析只会得到坏数据，直接报错
        if (not_empty($format) && 'JSONEachRow' !== $format) {
            throw new Exception('clickhouse 返回格式为 '.$format.'，无法按 JSONEachRow 解析，请去掉 SQL 里的 format 子句');
        }

        $rows = [];

        foreach (explode("\n", trim($raw)) as $line) {

            if (not_empty($line)) {
                $rows[] = json_decode($line, true);
            }
        }

        return $rows;
    });
}

// SQL 未带 limit 时自动追加 limit 1，未找到返回 null
function ch_query_first($sql, array $binds = [], $config_key = 'default')
{
    if (! preg_match('/\blimit\b/i', $sql)) {
        $sql .= ' limit 1';
    }

    $rows = ch_query($sql, $binds, $config_key);

    return $rows[0] ?? null;
}

function ch_query_column($column, $sql, array $binds = [], $config_key = 'default')
{
    $rows = ch_query($sql, $binds, $config_key);

    $res = [];

    foreach ($rows as $row) {
        $res[] = $row[$column];
    }

    return $res;
}

function ch_query_value($value, $sql, array $binds = [], $config_key = 'default')
{
    $row = ch_query_first($sql, $binds, $config_key);

    return $row[$value] ?? null;
}

// 建表、insert、改写（ALTER TABLE ... UPDATE/DELETE）等语句
// 返回值取自服务端汇总的 written_rows：DDL 与 mutation 恒为 0，开启异步插入时也可能为 0，不能用它判断写入是否生效
function ch_write($sql, array $binds = [], $config_key = 'default')
{
    return _clickhouse_closure($config_key, function ($config) use ($sql, $binds) {

        list(, $summary) = _clickhouse_request($config, $sql, $binds);

        return (int) ($summary['written_rows'] ?? 0);
    });
}

// 每行按 JSON 编码拼成请求体
// 超过 2^53 的整数在 PHP 里已经是 float（精度在进来之前就丢了），json_encode 会输出科学计数法导致写入失败，
// 与其让服务端报一句难以定位的解析错误，不如在这里直接拦下，让人改成字符串传入
function _clickhouse_json_lines(array $rows)
{
    $lines = [];

    foreach ($rows as $row) {

        foreach ($row as $column => $value) {

            otherwise(
                ! (is_float($value) && abs($value) >= 9007199254740992),
                'clickhouse 写入字段 '.$column.' 的值超出浮点精确整数范围（2^53），请以字符串形式传入',
                'exception',
                'CLICKHOUSE_INT_OVERFLOW');
        }

        $lines[] = json($row);
    }

    return implode("\n", $lines)."\n";
}

// 批量写入：$rows 为字段一致的关联数组列表，按行 JSON 编码后作为 body 传输，无需拼占位符
// 每行的键必须一致：缺键的列 ClickHouse 会静默填默认值（DateTime 变 1970-01-01），多余的键会静默丢弃
function ch_insert_rows($table, array $rows, $config_key = 'default')
{
    if (empty($rows)) {
        return 0;
    }

    return _clickhouse_closure($config_key, function ($config) use ($table, $rows) {

        $sql = 'insert into `'.$table.'` format JSONEachRow';

        list(, $summary) = _clickhouse_request($config, $sql, [], _clickhouse_json_lines($rows));

        return (int) ($summary['written_rows'] ?? 0);
    });
}

// 健康检查，连接或认证失败返回 false
function ch_ping($config_key = 'default')
{
    $config = _clickhouse_config($config_key);

    try {

        $raw = http([
            'url' => 'http://'.$config['host'].':'.$config['port'].'/ping',
            'retry' => 1,
            'timeout' => $config['timeout'],
            'header' => [
                'X-ClickHouse-User: '.$config['username'],
                'X-ClickHouse-Key: '.$config['password'],
            ],
        ]);
    } catch (Exception $ex) {

        return false;
    }

    return trim($raw) === 'Ok.';
}
