@echo off
set LAST_DIR=%cd%
cd /d %~dp0

set ROOT_DIR=%cd%\..\..

rem 测试环境使用 harness 的测试专用镜像：内置 Playwright、Chromium、Xvfb/noVNC 与前端覆盖率工具。
rem 8080 是前端测试观测入口（只读实时画面 / 产物 / 覆盖率 / trace），与项目 80 端口互不影响；
rem 宿主机访问需要发布该端口。容器内跑的是开发环境配置（ENV=development、development 的启动钩子）；
rem 80、3306 与开发环境相同，两个容器不能同时启动。
rem claude 配置：数据目录照挂，主配置用 CLAUDE_CONFIG_DIR 放进目录里（容器 /root/.claude/.claude.json）；不要单文件挂载 .claude.json（与宿主互写会打架）
docker run --rm -ti -p 80:80 -p 3306:3306 -p 8080:8080 -p 12345:12345 -p 12346:12346 --name php-vibe-coding-frame_test ^
    -v %ROOT_DIR%/:/var/www/php-vibe-coding-frame ^
    -v %USERPROFILE%/.claude:/root/.claude ^
    -e CLAUDE_CONFIG_DIR=/root/.claude ^
    -e PRJ_HOME=/var/www/php-vibe-coding-frame ^
    -e ENV=development ^
    -e TIMEZONE=Asia/Shanghai ^
    -e BEFORE_START_SHELL=/var/www/php-vibe-coding-frame/project/tool/development/before_env_start.sh ^
    -e AFTER_START_SHELL=/var/www/php-vibe-coding-frame/project/tool/development/after_env_start.sh ^
    registry.cn-shenzhen.aliyuncs.com/smarty/harness_engineering_php_env_for_test start

cd /d %LAST_DIR%
