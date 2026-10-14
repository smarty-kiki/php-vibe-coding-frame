#!/bin/bash

ROOT_DIR="$(cd "$(dirname $0)" && pwd)"/../..

# claude 配置：数据目录照挂（会话历史、backups 继续与宿主共用），主配置改用 CLAUDE_CONFIG_DIR 放进这个目录
# （容器 /root/.claude/.claude.json ⇔ 宿主 ~/.claude/.claude.json）；不再单文件挂载 ~/.claude.json——
# 单文件 bind mount 会和宿主侧互写打架（容器报 not found、宿主文件被清成 0 字节），不要加回来
sudo docker run --rm -ti -p 80:80 -p 3306:3306 -p 12345:12345 -p 12346:12346 --name php-vibe-coding-frame \
    -v $ROOT_DIR/:/var/www/php-vibe-coding-frame \
    -v ~/.claude:/root/.claude \
    -e 'CLAUDE_CONFIG_DIR=/root/.claude' \
    -e 'PRJ_HOME=/var/www/php-vibe-coding-frame' \
    -e 'ENV=development' \
    -e 'TIMEZONE=Asia/Shanghai' \
    -e 'BEFORE_START_SHELL=/var/www/php-vibe-coding-frame/project/tool/development/before_env_start.sh' \
    -e 'AFTER_START_SHELL=/var/www/php-vibe-coding-frame/project/tool/development/after_env_start.sh' \
    registry.cn-shenzhen.aliyuncs.com/smarty/harness_engineering_php_env start
