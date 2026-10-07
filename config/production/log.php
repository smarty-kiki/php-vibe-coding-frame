<?php

return [
    // 按项目分目录，与开发环境（/tmp/php_*.log）分开
    // 目录需要先建好并给 www-data 写权限：project/tool/production/after_push.sh 里会建
    'exception_path' => '/var/log/php-vibe-coding-frame/exception.log',
    'notice_path' => '/var/log/php-vibe-coding-frame/notice.log',
    'module_path' => '/var/log/php-vibe-coding-frame/module.log',
];
