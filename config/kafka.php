<?php

// Kafka 连接配置：与 beanstalk.php 同构的 midwares → resources 模式，框架固定取 queue midware、不暴露 config_key
// 要按环境换 brokers 就在环境目录放一份完整的 kafka.php（如 config/production/kafka.php），环境配置不与本文件合并；
// 运行环境需装 php-rdkafka 扩展

return [

    'midwares' => [
        'default' => 'local',
        'queue' => 'local',
    ],

    'resources' => [
        'local' => [
            'brokers' => '127.0.0.1:9092',
            'timeout' => 5,           // 连接、元数据与 offset 查询超时（秒）
            // 投递后等回执的上限（秒）：必须大于 message_timeout，否则会把「可能仍会送达」的消息误报成失败
            'flush_timeout' => 15,
            // 单条消息的投递超时（秒）：只有 broker 不可达或 topic 不存在才等满
            'message_timeout' => 10,
        ],
    ],
];
