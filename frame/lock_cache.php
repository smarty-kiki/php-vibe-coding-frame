<?php

define('LOCK_CACHE_MIDWARE_KEY', 'lock');

// 唤醒信号只用于即时唤醒阻塞中的等待方，给短 TTL 自然蒸发，避免无人在等时在信号键里堆积
// 比交接标记的 TTL 长：只要标记还在认领窗内，就有一条信号能把新来的调用方带到它面前
define('LOCK_CACHE_WAKE_EXPIRE', 5);

// 交接标记的 TTL：持有方释放后，锁以标记形态等着被唤醒的等待方认领
// 同时是交接中断（唤醒信号没发出去、认领方崩溃）时的自愈窗口：到期后锁自然空出，新调用方即可接手
define('LOCK_CACHE_HANDOFF_EXPIRE', 3);

// 交接标记的值：锁的三种取值之一（持有方 token / 交接标记 / 键不存在）
define('LOCK_CACHE_HANDOFF_VALUE', 'serially_run_handoff');

// 锁的值用随机串标识持有方，交接与释放时校验，避免跑超 expire_second 后动到别人的锁
function _lock_cache_token()
{
    return bin2hex(random_bytes(16));
}

// 互斥执行：并发调用只有一个能进入闭包执行，其余调用方不等待、直接返回 fail_closure 的结果
// 锁只在创建时带 expire_second 的 TTL（SET NX EX），抢锁失败的调用方不会给它续期：
// 持有方抛异常时靠 finally 释放，被 kill 时靠到期自动解锁，都不会永久卡住后续调用方
function singly_run($key, $expire_second, closure $closure, ?closure $fail_closure = null)
{
    otherwise(
        $expire_second > 0,
        'singly_run 的 expire_second 必须大于 0：锁不带过期时间，持有方崩溃后会永久死锁',
        'exception',
        'LOCK_CACHE_EXPIRE');

    $lock_key = 'singly_run_'.$key;

    $token = _lock_cache_token();

    if (! cache_add($lock_key, $token, $expire_second, LOCK_CACHE_MIDWARE_KEY)) {

        return value($fail_closure);
    }

    try {

        return call_user_func($closure);

    } finally {

        // 值还是自己的 token 才删：跑超 expire_second 时锁可能已经被下一个调用方拿到
        cache_compare_delete($lock_key, $token, LOCK_CACHE_MIDWARE_KEY);
    }
}

// 持有锁执行闭包，执行完把锁交接给下一个等待方
// 交接是把锁值从自己的 token 原子替换成交接标记：锁键全程存在，新来的调用方抢不到，只能排队
function _lock_cache_serially_execute($lock_key, $wake_key, $token, $expire_second, closure $closure)
{
    try {

        return call_user_func($closure);

    } finally {

        // 换出交接标记后唤醒一个等待方（先阻塞的先被唤醒）
        // 换不出来说明锁已不在自己手里（跑超了 expire_second），接手方放行时会自己唤醒，这里不用管
        if (cache_compare_set($lock_key, $token, LOCK_CACHE_HANDOFF_VALUE, LOCK_CACHE_HANDOFF_EXPIRE, LOCK_CACHE_MIDWARE_KEY)) {

            cache_lpush($wake_key, 1, LOCK_CACHE_WAKE_EXPIRE, LOCK_CACHE_MIDWARE_KEY);
        }
    }
}

// 排队串行执行：并发调用按到达顺序排队，前一个闭包执行完把锁交接给阻塞最久的下一个，认领到交接才执行
// wait_second 秒内等不到交接则返回 fail_closure 的结果（排队超时的唯一出口），不执行闭包
// 排队超时不写任何键、不做清理，退出不影响后面排队的调用方
function serially_run($key, $expire_second, $wait_second, closure $closure, ?closure $fail_closure = null)
{
    otherwise(
        $expire_second > 0,
        'serially_run 的 expire_second 必须大于 0：锁不带过期时间，持有方崩溃后会永久死锁',
        'exception',
        'LOCK_CACHE_EXPIRE');

    otherwise(
        $wait_second > 0,
        'serially_run 的 wait_second 必须大于 0：cache_blpop 里 0 表示永久阻塞，会一直占住调用方',
        'exception',
        'LOCK_CACHE_WAIT');

    $lock_key = 'serially_run_lock_'.$key;
    $wake_key = 'serially_run_wake_'.$key;

    $token = _lock_cache_token();

    // 只有锁键不存在（空闲）才能直接执行：持有中、交接中（键 = 交接标记）都抢不到，只能去排队
    if (cache_add($lock_key, $token, $expire_second, LOCK_CACHE_MIDWARE_KEY)) {

        return _lock_cache_serially_execute($lock_key, $wake_key, $token, $expire_second, $closure);
    }

    $deadline = time() + $wait_second;

    while (true) {

        $remain_second = $deadline - time();

        if ($remain_second <= 0) {
            break;
        }

        // 阻塞等交接信号；等满 wait_second 还没轮到，同样按超时退出
        if (null === cache_blpop($wake_key, $remain_second, LOCK_CACHE_MIDWARE_KEY)) {
            break;
        }

        // 被唤醒（先阻塞的先被唤醒）→ 认领交接标记；标记已过期而锁空着时，直接抢也算轮到
        if (cache_compare_set($lock_key, LOCK_CACHE_HANDOFF_VALUE, $token, $expire_second, LOCK_CACHE_MIDWARE_KEY)
            || cache_add($lock_key, $token, $expire_second, LOCK_CACHE_MIDWARE_KEY)) {

            return _lock_cache_serially_execute($lock_key, $wake_key, $token, $expire_second, $closure);
        }
    }

    return value($fail_closure);
}
