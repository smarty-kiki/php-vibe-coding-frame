<?php

// 连接池复用，支持 TCP 和 Unix Socket
function _redis_connection(array $config)
{
    static $container = [];

    if (empty($config)) {

        foreach ($container as $connection) {
            $connection->close();
        }

        return $container = [];
    } else {

        $is_sock = isset($config['sock']);

        $sign = $is_sock ?
            $config['sock'] . $config['timeout']:
            $config['host'] . $config['port'] . $config['timeout'];

        if (empty($container[$sign])) {

            $redis = new Redis();

            if ($is_sock) {
                $redis->connect($config['sock'], $config['timeout']);
            } else {
                $redis->connect($config['host'], $config['port'], $config['timeout']);
            }

            if (isset($config['auth'])) {
                $redis->auth($config['auth']);
            }

            $container[$sign] = $redis;
        } else {
            $redis = $container[$sign];
        }

        if (isset($config['database'])) {
            $redis->select($config['database']);
        }

        if (isset($config['options'])) {
            foreach ($config['options'] as $key => $value) {
                $redis->setOption($key, $value);
            }
        }

        return $redis;
    }
}

function _redis_cache_closure($config_key, closure $closure)
{
    $config = config_midware('redis', $config_key);

    $redis = _redis_connection($config);

    _redis_trace_setname($redis);

    return call_user_func($closure, $redis);
}

// 连接名带上当前 trace（Redis 7+ 的 SLOWLOG / CLIENT LIST 据此归属请求）；
// 连接在常驻进程里跨任务复用，只有期望值变化时才真的发 CLIENT SETNAME
function _redis_trace_setname($redis)
{
    static $last_name = null;

    $name = is_null(trace_id()) ? 'app' : 'trace:'.substr(trace_id(), 0, 24);

    if ($name === $last_name) {
        return;
    }

    try {
        $redis->rawCommand('CLIENT', 'SETNAME', $name);
    } catch (throwable $ex) {
        // 连接命名只是旁路信息，失败不影响缓存读写
    }

    $last_name = $name;
}

function cache_get($key, $config_key = 'default')
{
    return _redis_cache_closure($config_key, function ($redis) use ($key) {

        return $redis->get($key);

    });
}

// 返回 key => value 关联数组，不存在的 key 值为 false
function cache_multi_get(array $keys, $config_key = 'default')
{
    return _redis_cache_closure($config_key, function ($redis) use ($keys) {

        $values = $redis->mGet($keys);

        return array_combine($keys, $values);
    });
}

function cache_set($key, $value, $expires = 0, $config_key = 'default')
{
    return _redis_cache_closure($config_key, function ($redis) use ($key, $value, $expires) {

        if ($expires) {
            return $redis->set($key, $value, $expires);
        } else {
            return $redis->set($key, $value);
        }
    });
}

// NX — key 不存在时才设置
function cache_add($key, $value, $expires = 0, $config_key = 'default')
{
    return _redis_cache_closure($config_key, function ($redis) use ($key, $value, $expires) {

        if ($expires) {
            return $redis->set($key, $value, ['nx', 'ex' => $expires]);
        } else {
            return $redis->setNx($key, $value);
        }
    });
}

// XX — key 存在时才替换
function cache_replace($key, $value, $expires = 0, $config_key = 'default')
{
    return _redis_cache_closure($config_key, function ($redis) use ($key, $value, $expires) {

        if ($expires) {
            return $redis->set($key, $value, ['xx', 'ex' => $expires]);
        } else {
            return $redis->set($key, $value, ['xx']);
        }
    });
}

function cache_delete($key, $config_key = 'default')
{
    return _redis_cache_closure($config_key, function ($redis) use ($key) {

        return $redis->del($key);
    });
}

function cache_multi_delete(array $keys, $config_key = 'default')
{
    return _redis_cache_closure($config_key, function ($redis) use ($keys) {

        return $redis->del($keys);
    });
}

// 值相等才删除（Lua 里比较与删除是原子的），返回删除条数：1 表示确实删掉了自己的值，0 表示值已被别人改写
function cache_compare_delete($key, $value, $config_key = 'default')
{
    return _redis_cache_closure($config_key, function ($redis) use ($key, $value) {

        return $redis->eval(
            "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end",
            [$key, _redis_serialized_value($redis, $value)],
            1
        );
    });
}

// 值等于预期时才替换（Lua 里比较与替换是原子的），返回是否替换成功；expires 为 0 时不设过期
function cache_compare_set($key, $expect, $value, $expires = 0, $config_key = 'default')
{
    return _redis_cache_closure($config_key, function ($redis) use ($key, $expect, $value, $expires) {

        $script = "if redis.call('get', KEYS[1]) == ARGV[1] then"
            ." redis.call('set', KEYS[1], ARGV[2])"
            ." if tonumber(ARGV[3]) > 0 then redis.call('expire', KEYS[1], tonumber(ARGV[3])) end"
            ." return 1 else return 0 end";

        return $redis->eval(
            $script,
            [$key, _redis_serialized_value($redis, $expect), _redis_serialized_value($redis, $value), $expires],
            1
        );
    });
}

// eval 的参数不走 OPT_SERIALIZER，比较值要按连接当前的 serializer 编码成存储时的字节，才能与库里的值对上
function _redis_serialized_value($redis, $value)
{
    $serializer = $redis->getOption(Redis::OPT_SERIALIZER);

    if (Redis::SERIALIZER_PHP === $serializer) {
        return serialize($value);
    }

    if (defined('Redis::SERIALIZER_JSON') && Redis::SERIALIZER_JSON === $serializer) {
        return json_encode($value);
    }

    if (defined('Redis::SERIALIZER_IGBINARY') && Redis::SERIALIZER_IGBINARY === $serializer) {
        return igbinary_serialize($value);
    }

    if (defined('Redis::SERIALIZER_MSGPACK') && Redis::SERIALIZER_MSGPACK === $serializer) {
        return msgpack_pack($value);
    }

    return $value;
}

function cache_increment($key, $number = 1, $expires = 0, $config_key = 'default')
{
    return _redis_cache_closure($config_key, function ($redis) use ($key, $number, $expires) {

        $res = $redis->incr($key, $number);

        if ($expires) {
            $redis->expire($key, $expires);
        }

        return $res;
    });
}

function cache_decrement($key, $number = 1, $expires = 0, $config_key = 'default')
{
    return _redis_cache_closure($config_key, function ($redis) use ($key, $number, $expires) {

        $res = $redis->decr($key, $number);

        if ($expires) {
            $redis->expire($key, $expires);
        }

        return $res;
    });
}

// 生产环境慎用
function cache_keys($pattern = '*', $config_key = 'default')
{
    return _redis_cache_closure($config_key, function ($redis) use ($pattern) {

        return $redis->keys($pattern);
    });
}

function cache_hmset($key, array $array, $expires = 0, $config_key = 'default')
{
    return _redis_cache_closure($config_key, function ($redis) use ($key, $array, $expires) {

        $res = $redis->hmset($key, $array);

        if ($expires) {
            $redis->expire($key, $expires);
        }

        return $res;
    });
}

function cache_hmget($key, array $fields, $config_key = 'default')
{
    return _redis_cache_closure($config_key, function ($redis) use ($key, $fields) {

        return $redis->hmget($key, $fields);

    });
}

function cache_lpush($key, $values, $expires = 0, $config_key = 'default')
{
    $values = (array) $values;

    return _redis_cache_closure($config_key, function ($redis) use ($key, $values, $expires) {

        $res = $redis->lpush($key, ...$values);

        if ($expires) {
            $redis->expire($key, $expires);
        }

        return $res;
    });
}

// wait_time=0 时永久阻塞
function cache_blpop($keys, $wait_time = 0, $config_key = 'default')
{
    $is_array = is_array($keys);

    $params = (array) $keys;

    $params[] = $wait_time;

    $res = _redis_cache_closure($config_key, function ($redis) use ($params) {

        return $redis->blpop(...$params);
    });

    if ($res) {
        return $is_array? [$res[0] => $res[1]] : $res[1];
    } else {
        return $is_array? []: null;
    }
}

function cache_setbit($key, $offset, $value, $config_key = 'default')
{
    return _redis_cache_closure($config_key, function ($redis) use ($key, $offset, $value) {

        return $redis->setbit($key, $offset, $value);
    });
}

function cache_getbit($key, $offset, $config_key = 'default')
{
    return _redis_cache_closure($config_key, function ($redis) use ($key, $offset) {

        return $redis->getbit($key, $offset);
    });
}

// start/end 为字节偏移（非位偏移），-1 表示末尾
function cache_bitcount($key, $start = 0, $end = -1, $config_key = 'default')
{
    return _redis_cache_closure($config_key, function ($redis) use ($key, $start, $end) {

        return $redis->bitcount($key, $start, $end);
    });
}

// operation: AND/OR/XOR/NOT
function cache_bitop($destkey, $operation, $keys, $config_key = 'default')
{
    $keys = (array) $keys;

    return _redis_cache_closure($config_key, function ($redis) use ($destkey, $operation, $keys) {

        return $redis->bitop($operation, $destkey, ...$keys);
    });
}

// 查找指定 bit 值首次出现的位偏移，start/end 为字节偏移
function cache_bitpos($key, $bit, $start = 0, $end = -1, $config_key = 'default')
{
    return _redis_cache_closure($config_key, function ($redis) use ($key, $bit, $start, $end) {

        return $redis->bitpos($key, $bit, $start, $end);
    });
}

function cache_rename($old_key, $new_key, $config_key = 'default')
{
    return _redis_cache_closure($config_key, function ($redis) use ($old_key, $new_key) {

        return $redis->rename($old_key, $new_key);
    });
}

function cache_close()
{
    return _redis_connection([]);
}
