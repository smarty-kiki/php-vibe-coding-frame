<?php

// 跨域预检：浏览器对非简单请求先发 OPTIONS，直接 204 + CORS 放行
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    exit;
}

// init
include __DIR__.'/../bootstrap.php';
include FRAME_DIR.'/sse.php';

// 全链路 trace：一条流一个上下文，响应头随流起始一起回写
trace_begin_request();

define('SSE_DIR', ROOT_DIR.'/controller_sse');

// 404 处理：未命中任何 sse_route 时触发
if_not_found(function () {

    header('Content-Type: text/plain; charset=utf-8');

    return 'Not Found';
});

// 异常兜底：路由执行抛异常时记日志并回传 error 事件后关闭流
if_has_exception(function ($ex) {

    // otherwise 系列断言失败的消息是 code---description 结构，属于预期内的业务分支，记模块日志；其余才是真异常
    if ($ex instanceof business_exception || str_contains($ex->getMessage(), OTHERWISE_MESSAGE_DELIMITER)) {
        log_module('business_exception', otherwise_get_error_info($ex)['message']);
    } else {
        log_exception($ex);
    }

    sse_send(['error' => $ex->getMessage()]);

    sse_close();
});

// init interceptor（if_verify 可按需注册，如鉴权）

// init sse 业务文件：sse_route 命中当前请求路径即分发执行
include SSE_DIR.'/echo.php';

// trigger
not_found();
