#!/bin/bash

# 重置测试环境数据：重建测试库并重跑迁移、清空测试 Redis db
# 只动 config/test 指向的库与 db，且库名必须以 _test 结尾——防止 ENV 配错时误清开发库或生产库

ROOT_DIR="$(cd "$(dirname $0)" && pwd)"/../../..

if [ "$1" != "--yes" ]; then

    echo "本脚本会重建测试库并清空测试 Redis db，确认请加 --yes"
    exit 1
fi

DB_NAME=`ENV=test php -r "include '$ROOT_DIR/bootstrap.php'; echo config_midware('mysql', 'default')['database'];"`

REDIS_HOST=`ENV=test php -r "include '$ROOT_DIR/bootstrap.php'; echo config_midware('redis', 'default')['host'] ?? '127.0.0.1';"`
REDIS_PORT=`ENV=test php -r "include '$ROOT_DIR/bootstrap.php'; echo config_midware('redis', 'default')['port'] ?? 6379;"`
REDIS_DB=`ENV=test php -r "include '$ROOT_DIR/bootstrap.php'; echo config_midware('redis', 'default')['database'] ?? 0;"`

case "$DB_NAME" in
    *_test) ;;
    *) echo "库名 {$DB_NAME} 不以 _test 结尾，拒绝执行（检查 config/test/mysql.php）"; exit 1 ;;
esac

echo "重建数据库 {$DB_NAME} ..."
mysql -e "drop database if exists \`$DB_NAME\`; create database \`$DB_NAME\`;"

# 迁移与业务命令一样用 www-data 跑，日志归属保持一致
runuser -u www-data -- /bin/sh -c "ENV=test /usr/bin/php $ROOT_DIR/public/cli.php migrate:install"
runuser -u www-data -- /bin/sh -c "ENV=test /usr/bin/php $ROOT_DIR/public/cli.php migrate"

echo "清空 Redis {$REDIS_HOST}:{$REDIS_PORT} 的 db {$REDIS_DB} ..."
redis-cli -h $REDIS_HOST -p $REDIS_PORT -n $REDIS_DB flushdb

echo "完成：测试库已重建并跑完迁移，测试 Redis db 已清空"
