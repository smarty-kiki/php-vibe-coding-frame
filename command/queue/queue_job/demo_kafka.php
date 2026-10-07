<?php

// kafka 驱动的示例任务：第一个参数是任务数据，第二个是消息元信息（topic / partition / offset / key / timestamp）
// 返回值语义与 beanstalk 版一致：true = 处理成功提交 offset，false = 按 retry 数组重试、超限落死信 topic
queue_job('demo', function ($data, $meta) {

    sleep(1);

    log_module('queue', 'demo successful!'.json($meta));

    return true;

}, [1, 1, 1], 'default');
