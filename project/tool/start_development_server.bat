@echo off
set LAST_DIR=%cd%
cd /d %~dp0

set ROOT_DIR=%cd%\..\..

docker run --rm -ti -p 80:80 -p 3306:3306 -p 12345:12345 -p 12346:12346 --name php-vibe-coding-frame ^
    -v %ROOT_DIR%/:/var/www/php-vibe-coding-frame ^
    -v %USERPROFILE%/.claude:/root/.claude ^
    -v %USERPROFILE%/.claude.json:/root/.claude.json ^
    -e PRJ_HOME=/var/www/php-vibe-coding-frame ^
    -e ENV=development ^
    -e TIMEZONE=Asia/Shanghai ^
    -e BEFORE_START_SHELL=/var/www/php-vibe-coding-frame/project/tool/development/before_env_start.sh ^
    -e AFTER_START_SHELL=/var/www/php-vibe-coding-frame/project/tool/development/after_env_start.sh ^
    registry.cn-shenzhen.aliyuncs.com/smarty/harness_engineering_php_env start

cd /d %LAST_DIR%
