<?php

return [

    'midwares' => [
        'default' => 'local',
        'migrate' => 'local',
    ],

    'resources' => [

        'local' => [
            'host' => '127.0.0.1',
            'port' => 8123,

            'database' => 'default',
            'username' => 'default',
            'password' => '',

            // 单次 HTTP 请求超时秒数，分析型查询偏慢，默认比 redis 宽松
            'timeout' => 10,

            // 随每次请求下发的 ClickHouse 设置
            // 如关掉 output_format_json_quote_64bit_integers 可让 UInt64 以数字而非字符串返回
            'settings' => [
                // 'max_execution_time' => 30,
            ],
        ],
    ],
];
