#!/bin/bash

ROOT_DIR="$(cd "$(dirname $0)" && pwd)"/../../..

# 日志目录与文件由 before_env_start.sh 建（要早于服务启动）

# 测试库与账号（与 config/test/mysql.php 保持一致）
mysql -e "create database if not exists \`default_test\`;\
    GRANT ALL PRIVILEGES ON *.* TO 'test_user'@'%' IDENTIFIED BY 'test_password';\
    GRANT ALL PRIVILEGES ON *.* TO 'test_user'@'localhost' IDENTIFIED BY 'test_password';\
    FLUSH PRIVILEGES"

# 会产生日志的命令都用 www-data 跑：与 web、队列 worker 同一身份，谁写日志都是 www-data
# ENV=test 不能省：不设 ENV 时 env() 会退回 production，迁移会打到生产的库上
runuser -u www-data -- /bin/sh -c "ENV=test /usr/bin/php $ROOT_DIR/public/cli.php migrate:install"
runuser -u www-data -- /bin/sh -c "ENV=test /usr/bin/php $ROOT_DIR/public/cli.php migrate"

# ClickHouse 初始化（不可达会自动跳过）
runuser -u www-data -- /bin/sh -c "ENV=test /bin/bash $ROOT_DIR/project/tool/test/clickhouse_migrate.sh"
