#!/bin/bash

ROOT_DIR="$(cd "$(dirname $0)" && pwd)"/../../..


ln -fs $ROOT_DIR/project/config/development/nginx/php-vibe-coding-frame.conf /etc/nginx/sites-enabled/default
ln -fs $ROOT_DIR/project/config/development/supervisor/php-vibe-coding-frame_queue_worker.conf /etc/supervisor/conf.d/queue_worker.conf
ln -fs $ROOT_DIR/project/config/development/supervisor/queue_job_watch.conf /etc/supervisor/conf.d/queue_job_watch.conf
ln -fs $ROOT_DIR/project/config/development/php_fpm_pool/sse.conf /etc/php/8.4/fpm/pool.d/sse.conf
