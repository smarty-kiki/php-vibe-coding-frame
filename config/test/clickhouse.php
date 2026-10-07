<?php

return [

    'resources' => [

        'local' => [
            // 与 MySQL 同一口径：库名带项目名（建新项目时由 naming_project.sh 替换）
            // 账号沿用基础配置里的 default（生产环境也没覆盖 ClickHouse 账号）
            'database' => 'php-vibe-coding-frame',
        ],
    ],
];
