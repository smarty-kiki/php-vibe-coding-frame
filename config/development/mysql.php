<?php

return [

    'resources' => [

        'local' => [
            'database' => 'default',
            'username' => 'default',
            'password' => 'password',

            // 开发环境的 MySQL 在开发容器内，走本机 socket（数据库在独立机器上时换成 TCP 形式）
            'read' => [
                '/var/run/mysqld/mysqld.sock',
            ],
            'write' => [
                '/var/run/mysqld/mysqld.sock',
            ],
            'schema' => [
                '/var/run/mysqld/mysqld.sock',
            ],
        ],
    ],
];
