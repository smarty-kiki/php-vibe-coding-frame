#!/bin/bash

ROOT_DIR="$(cd "$(dirname $0)" && pwd)"/../..

# 可选参数：第 1 个为容器 80 端口映射到宿主机的端口，第 2 个为容器 3306 端口映射到宿主机的端口，不传则默认 80 3306
HTTP_PORT="${1:-80}"
MYSQL_PORT="${2:-3306}"

for port in "$HTTP_PORT" "$MYSQL_PORT"; do
    if ! [[ "$port" =~ ^[0-9]+$ ]] || [ "$port" -lt 1 ] || [ "$port" -gt 65535 ]; then
        echo "无效端口：$port" >&2
        echo "用法：$0 [HTTP端口] [MySQL端口]（缺省 80 3306）" >&2
        exit 1
    fi
done

sudo docker run --rm -ti -p ${HTTP_PORT}:80 -p ${MYSQL_PORT}:3306 -p 12345:12345 -p 12346:12346 --name php-vibe-coding-frame \
    -v $ROOT_DIR/:/var/www/php-vibe-coding-frame \
    -v ~/.claude:/root/.claude \
    -v ~/.claude.json:/root/.claude.json \
    -e 'PRJ_HOME=/var/www/php-vibe-coding-frame' \
    -e 'ENV=development' \
    -e 'TIMEZONE=Asia/Shanghai' \
    -e 'BEFORE_START_SHELL=/var/www/php-vibe-coding-frame/project/tool/development/before_env_start.sh' \
    -e 'AFTER_START_SHELL=/var/www/php-vibe-coding-frame/project/tool/development/after_env_start.sh' \
    registry.cn-shenzhen.aliyuncs.com/smarty/harness_engineering_php_env start
