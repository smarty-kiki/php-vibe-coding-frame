<?php

header('Access-Control-Allow-Origin: *');

// 跨域预检：浏览器对非简单请求（POST/PUT/DELETE 等）先发 OPTIONS，直接 204 + CORS 放行
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    exit;
}

// init
include __DIR__.'/../bootstrap.php';
include FRAME_DIR.'/php_fpm.php';

define('API_DIR', ROOT_DIR.'/controller_api');

set_error_handler('http_err_action', E_ALL);
set_exception_handler('http_ex_action');
register_shutdown_function('http_fatal_err_action');

// init 404 handler
if_not_found(function () {

    header('Content-type: application/json');

    return json([
        'code' => 404,
        'msg' => 'Not Found',
        'data' => [],
    ]);
});

// init 50x handler
if_has_exception(function ($ex) {

    $error_info = otherwise_get_error_info($ex);

    if ($ex instanceof business_exception) {
        log_module('business_exception', $error_info['message']);
    } else {
        log_exception($ex);
    }

    header('Content-type: application/json');

    return json([
        'code' => $error_info['code'],
        'msg' => $error_info['message'],
        'data' => [],
    ]);
});

if_verify(function ($action, $args) {

    return unit_of_work(function () use ($action, $args) {

        $data = call_user_func_array($action, $args);

        if (has_redirect()) {
            return;
        }

        header('Content-type: application/json');

        return json([
            'code' => 0,
            'msg' => '',
            'data' => $data,
        ]);
    });
});

// init interceptor

// init controller
include API_DIR.'/base.php';

// trigger
not_found();
