<?php

// 生产环境把 tube_key / topic_key 映射为带项目名的真实对象：与开发环境的 default 隔开，
// 多个项目共用一套 beanstalkd 或 kafka 时也不会串队列；建新项目时 naming_project.sh 会替换项目名
return [

    'tubes' => [
        'default' => 'php-vibe-coding-frame-default',
    ],

    'topics' => [
        'default' => 'php-vibe-coding-frame-default',
    ],
];
