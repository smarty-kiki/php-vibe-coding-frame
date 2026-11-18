<?php

return [
    'midwares' => [
        'migrate' => 'local',
        'entity' => 'local',
        'default' => 'local',
    ],

    'resources' => [

        'local' => [
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'database' => 'default',
            'username' => 'default',
            'password' => 'password',

            // 连接端点由各环境自己声明（config/{env}/mysql.php）：host => port 走 TCP，字符串值走 unix socket。
            // 环境配置是整份配置、不与本文件合并，基础层这里保持为空占位（环境文件需自行写全三个端点）
            'read' => [],
            'write' => [],
            'schema' => [],

            'options' => [
                PDO::ATTR_CASE => PDO::CASE_NATURAL,
                PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
            ],
        ],
    ],
];
