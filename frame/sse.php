<?php

// 请求内流结束标记（static 容器）：sse_close 置 true 后，后续 sse_send 不再输出
function _sse_closed(?bool $closed = null)
{
    static $container = false;

    if (!is_null($closed)) {
        $container = $closed;
    }

    return $container;
}

// 流式路由：匹配当前请求路径，命中则立即分发执行并结束请求（镜像 php_fpm 的 if_any，不做注册与遍历）。
// 闭包签名 ($params)，返回 Generator 时每个 yield 发一个 SSE data 事件（流式主用法）；
// 约定：yield true（严格 bool）＝ 流结束，立即关闭流（不发送数据，其后代码不再执行）；
// 闭包内也可直接调用 sse_send / sse_close；返回普通值时一次性发送后关闭，返回 true 则直接关闭。
function sse_route($path, closure $closure)
{
    if (_sse_request_path() !== $path) {
        return;
    }

    _sse_dispatch($closure, _sse_params());

    exit;
}

// 发一个 SSE 事件：data: {json}\n\n，可选 event: xxx 事件名。流结束后不再输出
function sse_send($data, $event = null)
{
    if (_sse_closed()) {
        return;
    }

    // 约定：sse_send(true)（严格 bool）＝ 流结束，关闭流，不发送数据
    if ($data === true) {
        sse_close();
        return;
    }

    $payload = is_string($data) ? $data : json($data);

    $frame = '';
    if ($event !== null) {
        $frame .= 'event: '.$event."\n";
    }
    foreach (explode("\n", $payload) as $line) {
        $frame .= 'data: '.$line."\n";
    }
    $frame .= "\n";

    // echo + flush：关闭输出缓冲后每次立即到达 nginx → 客户端
    echo $frame;
    flush();
}

// 关闭流（连接结束，客户端收到 EOF 即流结束）。FPM 模式下没有 socket 可关，
// 置流结束标记后停止输出即可，脚本结束由 FPM 关闭响应
function sse_close()
{
    _sse_closed(true);
}

// 解析请求路径：从 REQUEST_URI 取路径并剥掉 /sse 前缀，与 nginx 分流后的路由对应（业务路由不带 /sse）
function _sse_request_path()
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

    if ($path === false || $path === null) {
        $path = '/';
    }

    if (strpos($path, '/sse') === 0) {
        $path = substr($path, 4);
    }
    if ($path === '') {
        $path = '/';
    }

    return $path;
}

// 合并 query string 与 JSON POST body（不区分 GET/POST；body 非 JSON 时并入表单参数）
function _sse_params()
{
    $params = $_GET;

    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $body = json_decode($raw, true);
        if (is_array($body)) {
            $params = array_merge($params, $body);
        } else {
            $params = array_merge($params, $_POST);
        }
    }

    return $params;
}

// 一次性设置流式响应环境：头、去缓冲、去执行时间限制、关错误回显
function _sse_stream_env()
{
    // 长流不受 max_execution_time 限制
    set_time_limit(0);

    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');   // 通知 nginx 关闭该响应的缓冲（即使配置漏了 fastcgi_buffering off）
    header('Access-Control-Allow-Origin: *');

    // 关闭 PHP 输出缓冲与压缩，保证每次 flush 立即到达 nginx → 客户端
    @ini_set('output_buffering', '0');
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    ob_implicit_flush(true);

    // 关闭错误回显：notice/warning 被 echo 进流会破坏 SSE 帧（错误仍写入 FPM 错误日志）
    @ini_set('display_errors', '0');
}

// 迭代 generator：每个 yield 发一个 SSE data 事件，yield true（严格 bool）＝ 流结束。
// generator 抛出的异常冒泡到 _sse_dispatch 的 catch 统一处理
function _sse_iterate_generator($generator)
{
    while ($generator->valid() && !_sse_closed()) {

        $yielded = $generator->current();

        if ($yielded === true) {
            // 约定：yield true ＝ 流结束，不发送数据、不再推进 generator（其后的代码不执行）
            sse_close();
            return;
        }

        if ($yielded !== null) {
            sse_send($yielded);
        }

        if (connection_aborted()) {
            // 客户端已断开：停止流（FPM 默认 ignore_user_abort=false，脚本也会在下次输出时终止）
            return;
        }

        $generator->next();
    }

    // generator 自然结束（未 yield true）＝ 流结束
    sse_close();
}

// 分发：设置流式环境，经 if_verify 包装执行路由闭包，并按返回类型处理流式结果。
// Generator 每个 yield 发一个 SSE data 事件（流式主用法）；yield true（严格 bool）＝ 流结束；
// 闭包内可直接调用 sse_send / sse_close；返回普通值时一次性发送后关闭；异常走 if_has_exception 兜底
function _sse_dispatch($closure, array $params)
{
    _sse_stream_env();

    try {

        $verify = if_verify();

        if ($verify instanceof closure) {
            $result = $verify($closure, [$params]);
        } else {
            $result = call_user_func_array($closure, [$params]);
        }

        if ($result instanceof generator) {
            // 流式主用法：同步迭代，每个 yield 发一个 SSE data 事件
            _sse_iterate_generator($result);
        } elseif ($result === true) {
            // 约定：闭包直接返回 true（严格 bool）＝ 流结束，不发送数据
            sse_close();
        } elseif ($result !== null) {
            // 普通返回值：一次性发送后关闭
            sse_send($result);
            sse_close();
        } else {
            // 闭包内直接调用 sse_send / sse_close 的用法：结束后补关闭
            sse_close();
        }

    } catch (exception $exception) {

        $handler = if_has_exception();

        if ($handler instanceof closure) {
            $handler($exception);
        } else {
            log_exception($exception);
            sse_send(['error' => $exception->getMessage()]);
            sse_close();
        }
    }
}

// ===== 拦截器 / 异常 / 404 注册与触发（镜像 php_fpm，供 sse.php 入口编排） =====
// 注意：与 frame/php_fpm.php 中的同名函数是各自独立的实现，页面/API/SSE 三类入口不会同时加载两个模块。

// 路由验证拦截器：分发前包装路由闭包（如鉴权、日志），返回闭包的执行结果
function if_verify(?closure $action = null): ?closure
{
    static $container = null;

    if (! empty($action)) {
        return $container = $action;
    }

    return $container;
}

// 异常处理：路由执行抛异常时调用注册的处理器
function if_has_exception(?closure $action = null): ?closure
{
    static $container = null;

    if (! empty($action)) {
        return $container = $action;
    }

    return $container;
}

// 404 处理：未命中任何 sse_route 时由入口触发 not_found()
function if_not_found(?closure $action = null): ?closure
{
    static $container = null;

    if (! empty($action)) {
        return $container = $action;
    }

    return $container;
}

// 触发 404：入口在全部 sse_route 未命中后调用，执行 if_not_found 注册的处理
function not_found()
{
    http_response_code(404);

    $action = if_not_found();

    if ($action instanceof closure) {

        $output = $action();

        if (! is_null($output)) {
            echo $output;
        }

        exit;
    }

    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not Found';
    exit;
}
