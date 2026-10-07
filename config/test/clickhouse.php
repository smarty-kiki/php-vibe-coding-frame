<?php

return [

    'resources' => [

        'local' => [
            // 与 MySQL 同一口径：测试环境自己的库名（default_prod / default_test 的命名对齐）
            // 账号沿用基础配置里的 default（生产环境也没覆盖 ClickHouse 账号）
            'database' => 'default_test',
        ],
    ],
];
