<?php

// 队列配置：tubes 段给 beanstalk 实现、topics 与 consumer 段给 kafka 实现，
// 各实现只读自己那段——项目只会用一种队列（用哪种看 bootstrap.php 里 include 的是哪个队列文件）
//
// tube / topic 映射的口径一致：业务侧（queue_job / queue_watch / queue:* 命令）统一写左边的 key，
// 右边是队列服务里的真实名称；各环境覆盖本文件即可让同一个 key 落到不同真实对象（业务代码不用改）。
// 未映射的 key 会直接报错，避免名字打错时任务静默投进没人监听的对象
//
// 环境覆盖示例（config/development/queue.php）：
// return ['tubes' => ['default' => 'dev_default']];

return [

    // ---- beanstalk 驱动：tube_key => 真实 tube 名 ----

    'tubes' => [
        'default' => 'default',
    ],

    // ---- kafka 驱动 ----

    // topic_key => 真实 topic 名
    'topics' => [
        'default' => 'default',
    ],

    // 死信 topic = 真实 topic + 后缀，失败重试超限的消息落到这里，用 queue:dead-letter 人工重投
    'dead_letter_suffix' => '_dead',

    'consumer' => [

        // 消费组名 = 前缀 + topic_key：同一个 topic 的多个 worker 同组分摊分区；
        // 建新项目时随 naming_project.sh 替换，避免多项目共用一套 kafka 时串组
        'group_prefix' => 'php-vibe-coding-frame',

        // 没有已提交 offset 时从哪开始消费：earliest（默认，队列语义——worker 不在时投递的任务不丢）/
        // latest（只消费新消息，把 topic 当事件流用时才合适：起 worker 前投递的任务会被跳过）
        'auto_offset_reset' => 'earliest',

        // 单条消息的处理上限（含进程内重试的等待时间）：超时会被消费组判定离场、触发再均衡。
        // 它同时是再均衡的等待上限——有成员正在跑长任务时，别的 worker 加入/退出要等它跑完才能完成再均衡，
        // 所以不能为了长任务一味调大；长耗时动作应当拆小或改用死信 + 定时重放
        'max_poll_interval_ms' => 300000,

        'session_timeout_ms' => 45000,

        // 每轮消费的阻塞等待（秒），到点回到循环做内存检查与信号响应
        'consume_timeout' => 5,
    ],
];
