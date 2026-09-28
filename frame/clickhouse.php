<?php

// ClickHouse HTTP 接口客户端（默认 8123 端口），HTTP 无长连接状态，每次请求按配置拼 URL 直接发
// 绑定值走 param_* 查询串 + SQL 里的 {name:Type} 占位符，由 ClickHouse 服务端做转义，避免拼接 SQL

// 解析连接配置并补齐默认值
function _clickhouse_config($config_key)
{
    $config = config_midware('clickhouse', $config_key);

    $config['database'] = $config['database'] ?? 'default';
    $config['username'] = $config['username'] ?? 'default';
    $config['password'] = $config['password'] ?? '';
    $config['timeout'] = $config['timeout'] ?? 10;
    $config['settings'] = $config['settings'] ?? [];

    return $config;
}

// $data 非 null 时作为 POST body 传输（批量写入场景），此时 SQL 改走 query 参数
// 返回 [响应体, 解析后的 X-ClickHouse-Summary 响应头]
function _clickhouse_request(array $config, $sql, array $binds, $data = null)
{
    $query = $config['settings'];

    if (not_empty($config['database'])) {
        $query['database'] = $config['database'];
    }

    foreach ($binds as $key => $value) {
        $query['param_'.$key] = $value;
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
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$summary) {

                if (0 === stripos($line, 'X-ClickHouse-Summary:')) {
                    $summary = json_decode(trim(explode(':', $line, 2)[1]), true);
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

    return [$raw, $summary];
}

function _clickhouse_closure($config_key, closure $closure)
{
    return call_user_func($closure, _clickhouse_config($config_key));
}

// 查询返回关联数组列表，SQL 中用 {name:Type} 占位符接收 binds，如 where id = {id:UInt64}
// 结果按 JSONEachRow 解析，UInt64/Int64 默认以字符串返回，需要数字时在 settings 里关掉 output_format_json_quote_64bit_integers
function ch_query($sql, array $binds = [], $config_key = 'default')
{
    return _clickhouse_closure($config_key, function ($config) use ($sql, $binds) {

        list($raw) = _clickhouse_request($config, str_finish($sql, ' format JSONEachRow'), $binds);

        $rows = [];

        foreach (explode("\n", trim($raw)) as $line) {

            if (not_empty($line)) {
                $rows[] = json_decode($line, true);
            }
        }

        return $rows;
    });
}

// 自动追加 limit 1，未找到返回 false
function ch_query_first($sql, array $binds = [], $config_key = 'default')
{
    $rows = ch_query($sql.' limit 1', $binds, $config_key);

    return $rows[0] ?? false;
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

// 建表、改写（ALTER TABLE ... UPDATE/DELETE）等语句，返回本次写入行数
function ch_write($sql, array $binds = [], $config_key = 'default')
{
    return _clickhouse_closure($config_key, function ($config) use ($sql, $binds) {

        list(, $summary) = _clickhouse_request($config, $sql, $binds);

        return (int) ($summary['written_rows'] ?? 0);
    });
}

// 批量写入：$rows 为字段一致的关联数组列表，按行 JSON 编码后作为 body 传输，无需拼占位符
function ch_insert_rows($table, array $rows, $config_key = 'default')
{
    if (empty($rows)) {
        return 0;
    }

    $lines = [];

    foreach ($rows as $row) {
        $lines[] = json($row);
    }

    return _clickhouse_closure($config_key, function ($config) use ($table, $lines) {

        $sql = 'insert into `'.$table.'` format JSONEachRow';

        list(, $summary) = _clickhouse_request($config, $sql, [], implode("\n", $lines)."\n");

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
