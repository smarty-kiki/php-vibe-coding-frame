<?php

// Kafka 队列连接：与 beanstalk.php 同构的 midwares → resources 模式，
// 队列子系统（queue_job / queue_watch / queue:* 命令）固定取 queue midware
// （框架内写死 QUEUE_KAFKA_MIDWARE_KEY，不暴露 config_key 参数）。
// 环境覆盖只需改 resources 里的 brokers（如 config/production/kafka.php）
//
// 运行环境需装 php-rdkafka 扩展（librdkafka 绑定），扩展与 broker 版本兼容性见其文档

return [

    'midwares' => [
        'default' => 'local',
        'queue' => 'local',
    ],

    'resources' => [
        'local' => [
            'brokers' => '127.0.0.1:9092',
            'timeout' => 5,           // 连接、元数据与 offset 查询超时（秒）
            // 投递后等回执的上限（秒）：必须大于 message_timeout，否则 flush 会先超时，
            // 把「可能仍会送达」的消息误报成投递失败
            'flush_timeout' => 15,
            // 单条消息的投递超时（秒）：投递成功的路径是毫秒级返回，只有 broker 不可达或 topic 不存在才等满这个时长
            'message_timeout' => 10,
        ],
    ],
];
