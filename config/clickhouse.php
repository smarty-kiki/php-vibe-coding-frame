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
            // 框架默认会补上 output_format_json_quote_64bit_integers / _decimals 两项（让 64 位整数与
            // Decimal 以字符串返回，避免 JSON 走 double 丢精度）；这里显式设 0 可覆盖回数字类型
            'settings' => [
                // 'output_format_json_quote_64bit_integers' => 0,
                // 'output_format_json_quote_decimals' => 0,
                // 'max_execution_time' => 30,
            ],
        ],
    ],
];
