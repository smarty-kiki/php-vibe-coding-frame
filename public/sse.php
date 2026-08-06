<?php

// init
include __DIR__.'/../bootstrap.php';
include FRAME_DIR.'/sse.php';

define('SSE_DIR', ROOT_DIR.'/controller_sse');

// init sse 业务文件，开发者在此实现流式业务逻辑
include SSE_DIR.'/echo.php';

// 处理一次 SSE 请求：由 PHP-FPM 执行（nginx 将 /sse/* 分流到此入口），每请求一个流
_sse_handle_request();
