<?php

return [

    'resources' => [

        'local' => [
            // 测试环境的库名 / 账号 / 密码统一为带项目名的 php-vibe-coding-frame，
            // 建新项目时由 project/tool/naming_project.sh 替换
            'database' => 'php-vibe-coding-frame',
            'username' => 'php-vibe-coding-frame',
            'password' => 'php-vibe-coding-frame',

            // 数据库在独立机器上时，把 read / write / schema 换成 TCP 形式（写法参考 config/production/mysql.php）
        ],
    ],
];
