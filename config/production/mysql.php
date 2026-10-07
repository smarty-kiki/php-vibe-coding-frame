<?php

return [

    'resources' => [

        'local' => [
            // 生产的库名 / 账号 / 密码统一为带项目名的 php-vibe-coding-frame，
            // 建新项目时由 project/tool/naming_project.sh 替换
            'database' => 'php-vibe-coding-frame',
            'username' => 'php-vibe-coding-frame',
            'password' => 'php-vibe-coding-frame',

            'read' => [
                '127.0.0.1' => 3306,
            ],
            'write' => [
                '127.0.0.1' => 3306,
            ],
            'schema' => [
                '127.0.0.1' => 3306,
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
