#!/bin/bash

# 重置测试环境数据：重建测试库并重跑迁移、清空测试 Redis db
# 只动 config/test 指向的库与 db。测试与生产的库名相同（都带项目名），没法再用库名辨别环境：
# 脚本只应在测试服务器上执行，且要求调用方显式带 ENV=test

ROOT_DIR="$(cd "$(dirname $0)" && pwd)"/../../..

if [ "$ENV" != "test" ]; then

    echo "拒绝执行：请在测试服务器上显式带 ENV=test 运行（ENV=test bash $0 --yes）"
    exit 1
fi

if [ "$1" != "--yes" ]; then

    echo "本脚本会重建测试库并清空测试 Redis db，确认请加 --yes"
    exit 1
fi

DB_NAME=`ENV=test php -r "include '$ROOT_DIR/bootstrap.php'; echo config_midware('mysql', 'default')['database'];"`

REDIS_HOST=`ENV=test php -r "include '$ROOT_DIR/bootstrap.php'; echo config_midware('redis', 'default')['host'] ?? '127.0.0.1';"`
REDIS_PORT=`ENV=test php -r "include '$ROOT_DIR/bootstrap.php'; echo config_midware('redis', 'default')['port'] ?? 6379;"`
REDIS_DB=`ENV=test php -r "include '$ROOT_DIR/bootstrap.php'; echo config_midware('redis', 'default')['database'] ?? 0;"`

echo "在 $(hostname) 上重建数据库 {$DB_NAME} ...（确认这是测试服务器）"
mysql -e "drop database if exists \`$DB_NAME\`; create database \`$DB_NAME\`;"

# 迁移与业务命令一样用 www-data 跑，日志归属保持一致
runuser -u www-data -- /bin/sh -c "ENV=test /usr/bin/php $ROOT_DIR/public/cli.php migrate:install"
runuser -u www-data -- /bin/sh -c "ENV=test /usr/bin/php $ROOT_DIR/public/cli.php migrate"

echo "清空 Redis {$REDIS_HOST}:{$REDIS_PORT} 的 db {$REDIS_DB} ..."
redis-cli -h $REDIS_HOST -p $REDIS_PORT -n $REDIS_DB flushdb

echo "完成：测试库已重建并跑完迁移，测试 Redis db 已清空"
