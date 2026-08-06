<?php

// 示例：把 text 参数逐字流式返回
// 访问：/sse/echo?text=hello（GET，EventSource）或 POST JSON {"text":"hello"}
sse_route('/echo', function ($params) {
    $text = $params['text'] ?? 'hello';

    foreach (str_split($text) as $char) {
        yield $char;
        usleep(1000000); // 模拟流式节奏
    }

    yield true; // 约定：流结束，框架立即关闭连接
});
