#!/bin/bash

# 测试环境的 ClickHouse 初始化：可达就建库 + 跑分析库迁移，不可达就跳过
# 由 after_env_start.sh 以 www-data 身份调用（ENV=test 由调用方通过环境传入）

ROOT_DIR="$(cd "$(dirname $0)" && pwd)"/../../..

if ! /usr/bin/php -r "include '$ROOT_DIR/bootstrap.php'; try { exit(ch_ping() ? 0 : 1); } catch (throwable \$e) { exit(1); }"; then

    echo 'ClickHouse 不可达，跳过建库与 clickhouse:install / clickhouse:migrate'
    exit 0
fi

/usr/bin/php -r "include '$ROOT_DIR/bootstrap.php'; ch_write('create database if not exists default_test');"
/usr/bin/php $ROOT_DIR/public/cli.php clickhouse:install
/usr/bin/php $ROOT_DIR/public/cli.php clickhouse:migrate
