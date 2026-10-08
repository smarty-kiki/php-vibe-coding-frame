<?php

// 映射为带项目名的真实对象：与开发环境的 default 隔开，多项目共用一套队列也不串（项目名随 naming_project.sh 替换）
return [

    'tubes' => [
        'default' => 'php-vibe-coding-frame-default',
    ],

    'topics' => [
        'default' => 'php-vibe-coding-frame-default',
    ],
];
