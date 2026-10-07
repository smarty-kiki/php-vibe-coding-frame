#!/bin/bash

# 本机起一个 ENV=test 的容器（与开发环境同一镜像，端口错开避免冲突）
# 容器自带一套服务：测试库（test）、测试 Redis 都在容器内部，与开发容器互不干扰

ROOT_DIR="$(cd "$(dirname $0)" && pwd)"/../..

sudo docker run --rm -ti -p 8081:80 -p 13306:3306 -p 12347:12345 -p 12348:12346 --name php-vibe-coding-frame-test \
    -v $ROOT_DIR/:/var/www/php-vibe-coding-frame \
    -e 'PRJ_HOME=/var/www/php-vibe-coding-frame' \
    -e 'ENV=test' \
    -e 'TIMEZONE=Asia/Shanghai' \
    -e 'BEFORE_START_SHELL=/var/www/php-vibe-coding-frame/project/tool/test/before_env_start.sh' \
    -e 'AFTER_START_SHELL=/var/www/php-vibe-coding-frame/project/tool/test/after_env_start.sh' \
    registry.cn-shenzhen.aliyuncs.com/smarty/harness_engineering_php_env start
