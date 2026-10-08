<?php

// 日志统一 JSON Lines（一行一条、UTF-8）：字段口径对齐全链路 trace（W3C Trace Context），
// trace_id / span_id / parent_span_id 取当前上下文（frame/trace.php），没有上下文时为 null。
// 不截断 message / stack——截断是采集/存储层的职责，源头截断不可逆、会让排障时缺堆栈。
function _log_write($path, $level, $channel, $message, array $context = [])
{
    $log = config('log');

    $record = array_merge([
        // 真毫秒：gmdate() 只接受整数时间戳，'.v' 会恒为 000；传 microtime(true) 的 float 在
        // PHP 8.1+ 又会弃用告警且仍丢小数，故用 DateTimeImmutable 取当前时刻
        '@timestamp' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
        'level' => $level,
        'channel' => $channel,
        'message' => $message,
        'trace_id' => trace_id(),
        'span_id' => trace_span_id(),
        'parent_span_id' => trace_parent_span_id(),
        'service' => $log['service'] ?? '',
        'env' => env(),
        'host' => gethostname() ?: '',
    ], $context);

    error_log(json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n", 3, $path);
}

function log_exception(throwable $ex)
{
    _log_write(config('log')['exception_path'], 'error', 'exception', $ex->getMessage(), [
        'exception' => get_class($ex),
        'file' => $ex->getFile().':'.$ex->getLine(),
        'stack' => $ex->getTraceAsString(),
    ]);
}

function log_notice($message)
{
    _log_write(config('log')['notice_path'], 'notice', 'notice', $message);
}

function log_module($module, $message)
{
    _log_write(config('log')['module_path'], 'info', $module, $message);
}
