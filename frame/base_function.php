<?php

// key 支持点号分隔多层访问（如 'data.name'），支持 * 通配符遍历子数组
function array_get($array, $key, $default = null)
{
    $delimiter = '.';

    if (is_null($key)) {

        return $default;
    }

    $exploded_keys = explode($delimiter, $key);

    foreach ($exploded_keys as $i => $segment) {

        if ('*' === $segment) {

            $res_array = [];

            $new_key = implode($delimiter, array_slice($exploded_keys, $i + 1));

            foreach ($array as $k => $v) {

                $res_array[$k] = array_get($v, $new_key, $default);
            }

            return $res_array;

        } else {

            if (!is_array($array) || !array_key_exists($segment, $array)) {

                return value($default);
            }

            $array = $array[$segment];
        }
    }

    return $array;
}

// key 支持点号分隔，中间层不存在时自动创建
function array_set($array, $key, $value)
{
    $res_array = &$array;

    if (is_null($key)) {
        return $array = $value;
    }

    $keys = explode('.', $key);

    while (count($keys) > 1) {
        $key = array_shift($keys);

        if (!isset($array[$key]) || !is_array($array[$key])) {
            $array[$key] = [];
        }

        $array = &$array[$key];
    }

    $array[array_shift($keys)] = $value;

    return $res_array;
}

// key 支持点号分隔多层检查
function array_exists($array, $key)
{
    if (is_null($key)) {
        return false;
    }

    foreach (explode('.', $key) as $segment) {
        if (!is_array($array) || !array_key_exists($segment, $array)) {
            return false;
        }

        $array = $array[$segment];
    }

    return true;
}

// keys 支持点号分隔，可传字符串或字符串数组批量删除
function array_forget(&$array, $keys)
{
    $original = &$array;

    foreach ((array) $keys as $key) {
        $parts = explode('.', $key);

        while (count($parts) > 1) {
            $part = array_shift($parts);

            if (isset($array[$part]) && is_array($array[$part])) {
                $array = &$array[$part];
            }
        }

        unset($array[array_shift($parts)]);

        $array = &$original;
    }
}

function array_divide($array)
{
    return array(array_keys($array), array_values($array));
}

// 回调接收 $key, $value 返回 [新key, 新value]，新key 为 null 时追加为索引数组
function array_build($array, $callback)
{
    $results = [];

    foreach ($array as $key => $value) {

        list($innerKey, $innerValue) = call_user_func($callback, $key, $value);

        if (is_null($innerKey)) {

            $results[] = $innerValue;
        } else {
            $results[$innerKey] = $innerValue;
        }
    }

    return $results;
}

// 回调返回 [索引, 新key, 新value]，构建 $result[索引][新key] = 新value 两层分组
function array_indexed(array $array, closure $callback)
{
    $results = [];

    foreach ($array as $key => $value) {

        list($index, $innerKey, $innerValue) = call_user_func($callback, $key, $value);

        if (! isset($results[$index])) {
            $results[$index] = [];
        }

        if (is_null($innerKey)) {

            $results[$index][] = $innerValue;
        } else {
            if (! isset($results[$index][$innerKey])) {

                $results[$index][$innerKey] = [];
            }

            $results[$index][$innerKey] = $innerValue;
        }
    }

    return $results;
}

// array_get 批量版
function array_list(array $array, array $keys)
{
    if (empty($keys)) {
        return [];
    }

    $values = [];

    foreach ($keys as $key) {
        $values[] = array_get($array, $key);
    }

    return $values;
}

// rules 为 [来源key => 目标key]，来源和目标 key 均支持点号
function array_transfer(array $array, array $rules)
{
    if (empty($rules)) {
        return [];
    }

    $values = [];

    foreach ($rules as $from => $to) {
        $values = array_set($values, $to, array_get($array, $from));
    }

    return $values;
}

function str_tail_cut($string, $len, $suffix = '...')
{
    $strlen = mb_strlen($string);
    $suffixlen = mb_strlen($suffix);

    if ($strlen > $len) {
        return mb_substr($string, 0, $len - $suffixlen) . $suffix;
    }

    return $string;
}

function str_head_cut($string, $len, $prefix = '...')
{
    $strlen = mb_strlen($string);
    $prefixlen = mb_strlen($prefix);

    if ($strlen > $len) {
        return $prefix . mb_substr($string, $prefixlen - $len, $len - $prefixlen);
    }

    return $string;
}

function str_middle_cut($string, $len, $middle = '...')
{
    $strlen = mb_strlen($string);
    $middlelen = mb_strlen($middle);

    $res_strlen = $len - $middlelen;

    if ($res_strlen % 2) {
        $first_len  = floor($res_strlen / 2);
        $second_len = ceil($res_strlen / 2);
    } else {
        $first_len = $second_len = $res_strlen / 2;
    }

    if ($strlen > $len) {
        return mb_substr($string, 0, $first_len) . $middle . mb_substr($string, 0 - $second_len, $second_len);
    }

    return $string;
}

function dd(...$args)
{
    var_dump(...$args);
    die;
}

// 抛出异常并捕获，将调用栈输出到异常日志，用于调试
function trace($message = 'exception for trace')
{
    try {
        throw new Exception($message);
    } catch (Exception $ex) {
        log_exception($ex);
    }
}

function value($value)
{
    return $value instanceof Closure ? $value() : $value;
}

// 通过反射生成，文件路径和行号变更会影响标识
function closure_id($closure)
{
    $closure_ref = new ReflectionFunction($closure);

    return md5((string) $closure_ref);
}

function starts_with($haystack, $needles)
{
    foreach ((array) $needles as $needle) {
        if ($needle != '' && mb_strpos($haystack, $needle) === 0) {
            return true;
        }
    }

    return false;
}

function ends_with($haystack, $needles)
{
    foreach ((array) $needles as $needle)
    {
        if ((string) $needle === mb_substr($haystack, - mb_strlen($needle))) return true;
    }

    return false;
}

// 先去除末尾重复的 cap 再追加，避免重复
function str_finish($value, $cap)
{
    $quoted = preg_quote($cap, '/');

    return preg_replace('/(?:'.$quoted.')+$/', '', $value).$cap;
}

// 先去除开头重复的 cap 再前置，避免重复
function str_begin($value, $cap)
{
    $quoted = preg_quote($cap, '/');

    return $cap.preg_replace('/^(?:'.$quoted.')+/', '', $value);
}

// 额外匹配 #、//、mailto:、tel: 等特殊协议前缀
function is_url($path)
{
    if (! is_string($path)) {
        return false;
    }

    if (starts_with($path, ['#', '//', 'mailto:', 'tel:'])) {
        return true;
    }

    return filter_var($path, FILTER_VALIDATE_URL) !== false;
}

function unparse_url(array $parsed)
{
    $get = function ($key) use ($parsed) {
        return isset($parsed[$key]) ? $parsed[$key] : null;
    };

    $pass      = $get('pass');
    $user      = $get('user');
    $userinfo  = $pass !== null ? "$user:$pass" : $user;
    $port      = $get('port');
    $scheme    = $get('scheme');
    $query     = $get('query');
    $fragment  = $get('fragment');
    $authority =
        ($userinfo !== null ? "$userinfo@" : '') .
        $get('host') .
        ($port ? ":$port" : '');

    return
        (strlen($scheme) ? "$scheme:" : '') .
        (strlen($authority) ? "//$authority" : '') .
        $get('path') .
        (strlen($query) ? "?$query" : '') .
        (strlen($fragment) ? "#$fragment" : '');
}

// query 已 parse_str 为数组方便直接修改
function url_transfer($url, closure $transfer_action)
{
    $url_info = parse_url($url);

    if (isset($url_info['query'])) {
        parse_str($url_info['query'], $query_info);
        $url_info['query'] = $query_info;
    } else {
        $url_info['query'] = [];
    }
    $url_info = call_user_func($transfer_action, $url_info);
    $url_info['query'] = http_build_query($url_info['query']);

    return unparse_url($url_info);
}

function config_dir(?string $dir = null)
{
    static $container = [];

    if (! is_null($dir)) {
        $container[] = $dir;
    }

    return $container;
}

// 先加载 config/{file}.php，再用 config/{ENV}/{file}.php 覆盖，结果缓存
function config($file_name)
{
    static $configs = [];

    if (! isset($configs[$file_name])) {

        $directories = config_dir();

        $configs[$file_name] = [];

        foreach ($directories as $dir) {

            if (is_file($config_file = $dir.'/'.$file_name.'.php')) {

                $configs[$file_name] = array_replace_recursive($configs[$file_name], include $config_file);
            }

            if (is_file($env_config_file = $dir.'/'.env().'/'.$file_name.'.php')) {

                $configs[$file_name] = array_replace_recursive($configs[$file_name], include $env_config_file);
            }
        }
    }

    return $configs[$file_name];
}

// midwares[name] → resource key → resources[key] 间接寻址
function config_midware($file_name, $midware_name)
{
    static $configs = [];

    $identifier = $midware_name.'_'.$file_name;

    if (! isset($configs[$identifier])) {

        $midware_config = config($file_name);

        // 配置写错时直接报出缺失的那一层，否则只会拿到 null 再抛一句难以定位的下游错误
        otherwise(
            array_exists($midware_config, 'midwares.'.$midware_name),
            '配置 '.$file_name.' 缺少 midwares.'.$midware_name.' 映射',
            'exception',
            'CONFIG_MIDWARE_NOT_FOUND');

        $resource_key = $midware_config['midwares'][$midware_name];

        otherwise(
            array_exists($midware_config, 'resources.'.$resource_key),
            '配置 '.$file_name.' 缺少 resources.'.$resource_key.'（由 midwares.'.$midware_name.' 指向）',
            'exception',
            'CONFIG_RESOURCE_NOT_FOUND');

        $configs[$identifier] = $midware_config['resources'][$resource_key];
    }

    return $configs[$identifier];
}

// 预加载全部配置文件，消除首次 config() 调用的 IO 开销
function config_preload()
{
    $dirs = config_dir();

    $config_names = [];

    foreach ($dirs as $dir) {

        $files = scandir($dir);

        $env_dir = $dir.'/'.env();

        if (is_dir($env_dir)) {

            $files = array_merge($files, scandir($env_dir));
        }

        foreach ($files as $file) {

            if ($file[0] !== '.') {

                if ((! is_dir($dir.'/'.$file)) && ! is_dir($env_dir.'/'.$file)) {

                    $config_name = pathinfo($file, PATHINFO_FILENAME);

                    if (! isset($config_names[$config_name])) {

                        $config_names[$config_name] = true;

                        config($config_name);
                    }
                }
            }
        }
    }

    return $config_names;
}

// $_SERVER['ENV'] 未设置时默认 production
function env()
{
    return $_SERVER['ENV'] ?? 'production';
}

function is_env($env)
{
    return env() === $env;
}

function not_empty($mixed)
{
    return !empty($mixed);
}

function not_null($mixed)
{
    return !is_null($mixed);
}

function all_empty(...$args)
{
    foreach ($args as $arg) {

        if (not_empty($arg)) return false;
    }

    return true;
}

function all_null(...$args)
{
    foreach ($args as $arg) {

        if (not_null($arg)) return false;
    }

    return true;
}

function all_not_empty(...$args)
{
    return ! has_empty(...$args);
}

function all_not_null(...$args)
{
    return ! has_null(...$args);
}

function has_empty(...$args)
{
    foreach ($args as $arg) {

        if (empty($arg)) return true;
    }

    return false;
}

function has_null(...$args)
{
    foreach ($args as $arg) {

        if (is_null($arg)) return true;
    }

    return false;
}

// expression 可为 null（取当前时间）、时间戳（可带小数）、时间字符串 / 相对描述
// 默认精确到毫秒（秒后 3 位），要秒级显式传第二参如 'Y-m-d H:i:s'
function datetime($expression = null, $format = 'Y-m-d H:i:s.v')
{
    // 要带微秒的当前时刻（time() 只有秒）
    if (is_null($expression)) {
        return (new DateTimeImmutable())->format($format);
    }

    if (is_numeric($expression)) {
        // date() 只接受整数时间戳（传 float 会弃用告警且丢小数），用 U.u 组装带微秒的时刻再转回默认时区
        $datetime = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6f', (float) $expression))
            ->setTimezone(new DateTimeZone(date_default_timezone_get()));
    } else {
        // 带毫秒的时间串能原样保留（strtotime 会丢小数）
        try {
            $datetime = new DateTimeImmutable($expression);
        } catch (Exception $e) {
            // 解析不了时保持老行为（时间戳 0）
            $datetime = DateTimeImmutable::createFromFormat('U.u', '0.000000')
                ->setTimezone(new DateTimeZone(date_default_timezone_get()));
        }
    }

    return $datetime->format($format);
}

// 额外支持 %td/%th/%tm/%ts 总差异占位符
function datetime_diff($datetime1, $datetime2, $format = '%ts')
{
    $interval = date_diff(
        date_create($datetime1),
        date_create($datetime2)
    );

    $res = $interval->format($format);

    $total_hours = $interval->days * 24 + $interval->h;

    $total_minutes = $total_hours * 60 + $interval->i;

    $total_seconds = $total_minutes * 60 + $interval->s;

    foreach ([
        '%td' => $interval->days,
        '%th' => $total_hours,
        '%tm' => $total_minutes,
        '%ts' => $total_seconds,
    ] as $k => $v) {
        $res = str_replace($k, $v, $res);
    }

    return $res;
}

// 可传 URL 字符串（GET）或配置数组，支持 retry/timeouted/状态码回调
// 不传 method 时：有 data 为 POST，无 data 为 GET
function http($args)
{
    $request_info = [
        'url' => 'http://127.0.0.1/',
        //'method' => 'GET',
        'data' => [],
        'header' => [],
        'cookie' => [],
        'timeout' => 3,
        'retry' => 3,
        'option' => [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => 'gzip',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_12_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/60.0.3112.101 Safari/537.36',
        ],
    ];

    if (is_url($args)) {
        $request_info['url'] = $args;
    } elseif (is_array($args)) {
        $request_info = array_replace($request_info, $args);
    }

    // 全链路 trace 跨服务透传：上下文中存在 trace 且调用方没显式带同名头时，自动补 traceparent / X-Request-Id
    foreach (trace_http_headers() as $trace_header) {

        $trace_header_name = substr($trace_header, 0, strpos($trace_header, ':'));

        $has_trace_header = false;

        foreach ($request_info['header'] as $header) {
            if (stripos($header, $trace_header_name.':') === 0) {
                $has_trace_header = true;
                break;
            }
        }

        if (! $has_trace_header) {
            $request_info['header'][] = $trace_header;
        }
    }

    $ch = curl_init();

    if (
        isset($request_info['method'])
        && $request_info['method'] === 'GET'
        && ! empty($request_info['data'])
    ) {
        $request_info['url'] = url_transfer($request_info['url'], function ($url_info) use ($request_info) {
            $url_info['query'] = array_replace($url_info['query'], $request_info['data']);
            return $url_info;
        });
        $request_info['data'] = [];
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $request_info['url'],
        CURLOPT_TIMEOUT => $request_info['timeout'],
        CURLOPT_CONNECTTIMEOUT => $request_info['timeout'],
    ]);

    curl_setopt_array($ch, $request_info['option']);

    if (
        ! empty($request_info['data'])
        || (
            ! empty($request_info['method'])
            && $request_info['method'] === 'POST'
        )
    ) {

        $request_info_data =
            is_array($request_info['data']) ?
            http_build_query($request_info['data']) :
            $request_info['data'];

        curl_setopt_array($ch, [
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => $request_info_data,
        ]);
    }

    if (! empty($request_info['header'])) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $request_info['header']);
    }

    if (! empty($request_info['cookie'])) {

        $request_info_cookie = '';

        if (is_array($request_info['cookie'])) {
            $request_info_cookie = http_build_query($request_info['cookie'], '', ';').';';
        }

        curl_setopt($ch, CURLOPT_COOKIE, $request_info_cookie);
    }

    $retry = $request_info['retry'];

    while ($retry-- > 0) {

        $res = curl_exec($ch);
        $errno = curl_errno($ch);

        if (CURLE_OK === $errno) {
            break;
        }
    }

    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (0 === $code) {
        if (CURLE_OPERATION_TIMEDOUT === $errno) {
            if (! empty($request_info['timeouted']) && $request_info['timeouted'] instanceof closure) {
                return call_user_func($request_info['timeouted']);
            }
        }

        throw new Exception('http url '.$request_info['url'].' '.curl_strerror($errno));
    }

    curl_close($ch);

    // 按 HTTP 状态码或默认回调处理响应
    if (! empty($request_info[$code]) && $request_info[$code] instanceof closure) {
        return call_user_func($request_info[$code], $res, $code);
    }

    if (! empty($request_info[0]) && $request_info[0] instanceof closure) {
        return call_user_func($request_info[0], $res, $code, $errno);
    }

    return $res;
}

function http_json($args)
{
    return json_decode(http($args), true);
}

function http_xml($args)
{
    $raw_res = http($args);

    $raw_xml = simplexml_load_string($raw_res, 'SimpleXMLElement', LIBXML_NOCDATA);

    return json_decode(json_encode($raw_xml), true);
}

// 相同类名且相同构造参数返回同一实例
function instance($class_name, array $args = [])
{
    static $container = [];

    if (empty($args)) {
        $instance_identifier = $class_name;
    } else {
        $instance_identifier = $class_name.serialize($args);
    }

    if (!isset($container[$instance_identifier])) {

        $container[$instance_identifier] = new $class_name(...$args);
    }

    return $container[$instance_identifier];
}

// 默认 JSON_UNESCAPED_UNICODE 不转义中文
function json($data = [])
{
    return json_encode($data, JSON_UNESCAPED_UNICODE);
}

// 按顺序定义值为 2^0, 2^1, 2^2… 的常量，用于位运算组合
function option_define(...$options)
{
    foreach ($options as $i => $option)
    {
        define($option, pow(2, $i));
    }
}

// $options 可为多个选项的位或结果
function has_option($options, $define)
{
    return $options === ($options | $define);
}
