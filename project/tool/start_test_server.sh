#!/bin/bash

ROOT_DIR="$(cd "$(dirname $0)" && pwd)"/../..

# 测试环境使用 harness 的测试专用镜像：在开发环境基础上内置 Playwright、Chromium、
# Xvfb/noVNC 与前端覆盖率工具。8080 是前端测试观测入口（只读实时画面 / 产物 / 覆盖率 / trace），
# 与项目的 80 端口互不影响；宿主机访问需要发布该端口。
#
# 容器内跑的是开发环境配置（ENV=development、development 的启动钩子）。
# 注意：80、3306 与开发环境启动脚本使用相同映射，两个容器不能同时启动；
# 需要同时运行时自行调整端口和容器名。
#
# claude 配置：数据目录照挂，主配置用 CLAUDE_CONFIG_DIR 放进这个目录；不要单文件挂载
# ~/.claude.json（与宿主互写会打架，详见 start_development_server.sh）
sudo docker run --rm -ti -p 80:80 -p 3306:3306 -p 8080:8080 -p 12345:12345 -p 12346:12346 --name php-vibe-coding-frame_test \
    -v $ROOT_DIR/:/var/www/php-vibe-coding-frame \
    -v ~/.claude:/root/.claude \
    -e 'CLAUDE_CONFIG_DIR=/root/.claude' \
    -e 'PRJ_HOME=/var/www/php-vibe-coding-frame' \
    -e 'ENV=development' \
    -e 'TIMEZONE=Asia/Shanghai' \
    -e 'BEFORE_START_SHELL=/var/www/php-vibe-coding-frame/project/tool/development/before_env_start.sh' \
    -e 'AFTER_START_SHELL=/var/www/php-vibe-coding-frame/project/tool/development/after_env_start.sh' \
    registry.cn-shenzhen.aliyuncs.com/smarty/harness_engineering_php_env_for_test start
