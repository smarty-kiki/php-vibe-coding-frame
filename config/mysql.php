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
            // 这里必须留空——环境覆盖按 key 合并、删不掉基础层的 key，留了就会与环境声明的并存、随机挑一个连错
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
