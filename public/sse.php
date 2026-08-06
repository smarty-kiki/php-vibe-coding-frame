<?php

// init
include __DIR__.'/../bootstrap.php';
include FRAME_DIR.'/sse.php';

define('SSE_DIR', ROOT_DIR.'/controller_sse');

// init sse 业务文件，开发者在此实现流式业务逻辑
include SSE_DIR.'/echo.php';

// 跨域预检：浏览器对非简单请求先发 OPTIONS，直接 204 + CORS 放行
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    exit;
}

// 404：未注册的路由返回纯文本
$path = _sse_request_path();
$routes = _sse_routes();

if (! isset($routes[$path])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not Found';
    exit;
}

// 设置流式响应环境（去缓冲、关错误回显、长流不限时）
_sse_stream_env();

// 分发：执行路由闭包，按返回类型处理流式结果（nginx 将 /sse/* 分流到此入口，每请求一个流）
_sse_dispatch($routes[$path], _sse_params());
