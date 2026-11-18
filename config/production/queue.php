<?php

// 映射为带项目名的真实对象：与开发环境的 default 隔开，多项目共用一套队列也不串（项目名随 naming_project.sh 替换）
// 环境配置是整份配置、不与 config/queue.php 合并，新增字段时两边要同步
return [

    // ---- beanstalk 驱动 ----

    'tubes' => [
        'default' => 'php-vibe-coding-frame-default',
    ],

    // ---- kafka 驱动 ----

    'topics' => [
        'default' => 'php-vibe-coding-frame-default',
    ],

    // 死信 topic = 真实 topic + 后缀（queue:dead-letter 人工重投）
    'dead_letter_suffix' => '_dead',

    'consumer' => [

        // 消费组名前缀：建新项目时随 naming_project.sh 替换，避免多项目共用一个集群时串组
        'group_prefix' => 'php-vibe-coding-frame',

        // 无已提交 offset 时的起点：earliest（队列语义，worker 不在时投递的任务不丢）/ latest（当事件流用）
        'auto_offset_reset' => 'earliest',

        // 单条消息的处理上限（含进程内重试等待）：超时被判离场、触发再均衡，也是再均衡的等待上限——
        // 不能为长任务一味调大，长耗时动作应拆小或改用死信 + 定时重放
        'max_poll_interval_ms' => 300000,

        'session_timeout_ms' => 45000,

        // 每轮消费的阻塞等待（秒），到点回到循环做内存检查与信号响应
        'consume_timeout' => 5,
    ],
];
