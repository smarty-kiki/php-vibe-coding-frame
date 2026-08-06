<?php

ini_set('display_errors', 'on');
date_default_timezone_set('Asia/Shanghai');

define('ROOT_DIR', __DIR__);
define('FRAME_DIR', ROOT_DIR.'/frame');
define('DOMAIN_DIR', ROOT_DIR.'/domain');
define('UTIL_DIR', ROOT_DIR.'/util');
define('QUEUE_JOB_DIR', ROOT_DIR.'/command/queue/queue_job');

include FRAME_DIR.'/base_function.php';
include FRAME_DIR.'/orm_entity.php';
include FRAME_DIR.'/otherwise.php';
include FRAME_DIR.'/database_mysql.php';
include FRAME_DIR.'/cache_redis.php';
include FRAME_DIR.'/queue_beanstalk.php';
include FRAME_DIR.'/orm_unitofwork.php';
include FRAME_DIR.'/log.php';

config_dir(ROOT_DIR.'/config');

include UTIL_DIR.'/load.php';
include DOMAIN_DIR.'/load.php';
include QUEUE_JOB_DIR.'/load.php';
