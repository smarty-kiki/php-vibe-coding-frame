<?php

// init
include __DIR__.'/../bootstrap.php';
include FRAME_DIR.'/cli_command.php';

define('COMMAND_DIR', ROOT_DIR.'/command');

// init miss match handler
if_command_not_found(function ($rules, $descriptions) {
    echo "未匹配到命令，支持以下命令:\n";
    foreach ($rules as $num => $rule) {
        echo str_pad($rule, 50, ' ').$descriptions[$num]."\n";
    }
});

// registe command
include COMMAND_DIR.'/migration/migrate.php';
include COMMAND_DIR.'/entity.php';
include COMMAND_DIR.'/queue/queue.php';

// trigger
command_not_found();
