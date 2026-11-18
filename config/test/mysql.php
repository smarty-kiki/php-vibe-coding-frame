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

            // 测试环境的库名 / 账号 / 密码统一为带项目名的 php-vibe-coding-frame，
            // 建新项目时由 project/tool/naming_project.sh 替换
            'database' => 'php-vibe-coding-frame',
            'username' => 'php-vibe-coding-frame',
            'password' => 'php-vibe-coding-frame',

            // 测试服务器上 MySQL 与 PHP 同机，走本机 socket
            // 数据库在独立机器上时，把 read / write / schema 换成 TCP 形式（写法参考 config/production/mysql.php）
            'read' => [
                '/var/run/mysqld/mysqld.sock',
            ],
            'write' => [
                '/var/run/mysqld/mysqld.sock',
            ],
            'schema' => [
                '/var/run/mysqld/mysqld.sock',
            ],

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
