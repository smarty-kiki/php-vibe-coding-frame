<?php

return [

    'resources' => [

        'local' => [
            // 测试环境自己的库与账号（命名与生产的 default_prod / prod_user 对齐）
            'database' => 'default_test',
            'username' => 'test_user',
            'password' => 'test_password',

            // 数据库在独立机器上时，把 read / write / schema 换成 TCP 形式（写法参考 config/production/mysql.php）
        ],
    ],
];
