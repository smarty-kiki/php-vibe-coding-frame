<?php

// 测试环境把 tube_key 映射为带项目名的真实 tube：与开发环境的 default 隔开，
// 多个项目共用一台 beanstalkd 时也不会串队列；建新项目时 naming_project.sh 会替换项目名
return [

    'tubes' => [
        'default' => 'php-vibe-coding-frame-default',
    ],
];
