#!/bin/bash

ROOT_DIR="$(cd "$(dirname $0)" && pwd)"/../..

sudo docker run --rm -ti -p 80:80 -p 3306:3306 -p 12345:12345 -p 12346:12346 --name php-vibe-coding-frame \
    -v $ROOT_DIR/:/var/www/php-vibe-coding-frame \
    -v $ROOT_DIR/project/config/development/nginx/php-vibe-coding-frame.conf:/etc/nginx/sites-enabled/default \
    -v $ROOT_DIR/project/config/development/supervisor/php-vibe-coding-frame_queue_worker.conf:/etc/supervisor/conf.d/queue_worker.conf \
    -v $ROOT_DIR/project/config/development/supervisor/queue_job_watch.conf:/etc/supervisor/conf.d/queue_job_watch.conf \
    -v $ROOT_DIR/project/config/development/php_fpm_pool/sse.conf:/etc/php/8.4/fpm/pool.d/sse.conf \
    -v ~/.claude:/root/.claude \
    -v ~/.claude.json:/root/.claude.json \
    -e 'PRJ_HOME=/var/www/php-vibe-coding-frame' \
    -e 'ENV=development' \
    -e 'TIMEZONE=Asia/Shanghai' \
    -e 'AFTER_START_SHELL=/var/www/php-vibe-coding-frame/project/tool/development/after_env_start.sh' \
    registry.cn-shenzhen.aliyuncs.com/smarty/harness_engineering_php_env start
