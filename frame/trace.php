<?php

// 全链路 trace 上下文（W3C Trace Context 口径）：
// 入口取客户端传来的 trace_id（traceparent > X-Request-Id），没有就本地生成；
// 日志字段、SQL 注释、Redis 连接名、队列 payload 都从这里取，同一处理单元全链路同一个 id。
// FPM 一请求一执行、CLI 一进程多任务（worker 每个 job 重新 init），静态容器随进程用即可。

function _trace_context(?array $container = null): array
{
    static $context = [];

    if (! is_null($container)) {
        $context = $container;
    }

    return $context;
}

// 校验并原样采用（合法值不做大小写转换——与 nginx map 的口径逐字对齐，两侧选出的 id 才能逐字符一致）；
// 不合法或没传时生成小写新 id。parent_span_id 由调用方传（入站 traceparent 的 span 段 / 队列投递方的 span）
function trace_init(?string $trace_id = null, ?string $parent_span_id = null): string
{
    if (! is_null($trace_id) && preg_match('/^[0-9a-f]{32}$/i', $trace_id)) {
        $real_trace_id = $trace_id;
    } else {
        $real_trace_id = bin2hex(random_bytes(16));
    }

    _trace_context([
        'trace_id' => $real_trace_id,
        'span_id' => bin2hex(random_bytes(8)),
        'parent_span_id' => $parent_span_id,
    ]);

    return $real_trace_id;
}

// HTTP 入口初始化：客户端传了就用客户端的（优先级与 nginx 的 map 链逐字一致：traceparent > X-Request-Id > 自生成），
// 并回写响应头——Caddy 前置时透传给客户端；nginx 前置时被 fastcgi_hide_header 隐藏、由 nginx 回写同值
function trace_begin_request(): string
{
    $trace_id = null;
    $parent_span_id = null;

    $traceparent = $_SERVER['HTTP_TRACEPARENT'] ?? '';

    // 提取规则与 nginx map 的正则逐字一致（大小写不敏感），保证两侧选出同一个 id
    if (preg_match('/^[0-9a-f]{2}-([0-9a-f]{32})-.*$/i', $traceparent, $match)) {
        $trace_id = $match[1];
    }

    if (is_null($trace_id)) {

        $x_request_id = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';

        if (preg_match('/^[0-9a-f]{32}$/i', $x_request_id)) {
            $trace_id = $x_request_id;
        }
    }

    // parent span 只从严格 W3C 格式里取，取不到就为空（不影响 trace_id 的选择）
    if (preg_match('/^[0-9a-f]{2}-[0-9a-f]{32}-([0-9a-f]{16})-[0-9a-f]{2}$/i', $traceparent, $match)) {
        $parent_span_id = $match[1];
    }

    $trace_id = trace_init($trace_id, $parent_span_id);

    header('X-Request-Id: '.$trace_id);

    return $trace_id;
}

function trace_id(): ?string
{
    return _trace_context()['trace_id'] ?? null;
}

function trace_span_id(): ?string
{
    return _trace_context()['span_id'] ?? null;
}

function trace_parent_span_id(): ?string
{
    return _trace_context()['parent_span_id'] ?? null;
}

function trace_all(): array
{
    return _trace_context();
}

function trace_reset(): void
{
    _trace_context([]);
}

// SQL 前置注释（MySQL 的 general_log / slow log 靠它关联请求）：内容仅 hex 与固定分隔符，不扩大注入面；无上下文返回空串
function trace_sql_comment(): string
{
    if (is_null(trace_id())) {
        return '';
    }

    return '/* trace_id='.trace_id().' span='.trace_span_id().' */ ';
}

// 出站请求头（跨服务透传）：traceparent 的 span 段是"我"，下游拿它当 parent
function trace_http_headers(): array
{
    if (is_null(trace_id())) {
        return [];
    }

    return [
        'traceparent: 00-'.trace_id().'-'.trace_span_id().'-01',
        'X-Request-Id: '.trace_id(),
    ];
}
