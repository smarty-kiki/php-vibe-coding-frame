<?php

// init
include __DIR__.'/../bootstrap.php';
include FRAME_DIR.'/php_fpm.php';
include FRAME_DIR.'/view_blade.php';

define('CONTROLLER_DIR', ROOT_DIR.'/controller');
define('VIEW_DIR', ROOT_DIR.'/view');

view_path(VIEW_DIR.'/');
view_compiler(blade_view_compiler_generate());

set_error_handler('http_err_action', E_ALL);
set_exception_handler('http_ex_action');
register_shutdown_function('http_fatal_err_action');

if_has_exception(function ($ex) {

    $error_info = otherwise_get_error_info($ex);

    if ($ex instanceof business_exception) {
        log_module('business_exception', $error_info['message']);
    } else {
        log_exception($ex);
    }

    return render('error/500', [
        'code' => $error_info['code'],
        'message' => $error_info['message'],
    ]);
});

if_verify(function ($action, $args) {

    return unit_of_work(function () use ($action, $args){

        $data = call_user_func_array($action, $args);

        if (has_redirect()) {
            return ;
        }

        if (is_string($data)) {

            header('Content-type: text/html');

            return $data;
        }

        // 页面入口只出 HTML：返回非字符串说明该路由是接口，应迁移到 controller_api/
        throw new Exception('页面路由必须返回字符串（HTML），实际返回：'.gettype($data).'，请将该路由迁移到 controller_api/ 目录');
    });
});

// init interceptor

// init 404 handler
if_not_found(function () {

    return render('error/404');
});

// init controller
include CONTROLLER_DIR.'/base.php';

// fix
not_found();
