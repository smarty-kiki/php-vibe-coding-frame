#!/bin/bash

# ClickHouse 初始化：可达就建库（库名取自当前 ENV 的配置）+ 跑分析库迁移，不可达就跳过
# 测试与生产共用：由各环境的启动/部署脚本以 www-data 身份调用，ENV 由调用方通过环境传入
#（ENV=test 见 project/tool/test/after_env_start.sh，ENV=production 见 project/tool/production/after_push.sh）

ROOT_DIR="$(cd "$(dirname $0)" && pwd)"/../..

if ! /usr/bin/php -r "include '$ROOT_DIR/bootstrap.php'; try { exit(ch_ping() ? 0 : 1); } catch (throwable \$e) { exit(1); }"; then

    echo 'ClickHouse 不可达，跳过建库与 clickhouse:install / clickhouse:migrate'
    exit 0
fi

CH_DB=`/usr/bin/php -r "include '$ROOT_DIR/bootstrap.php'; echo config_midware('clickhouse', 'default')['database'];"`

# 库名来自配置，建库语句是拼出来的：先挡一道非法字符（- 是合法字符），带 - 的库名用反引号包住
case "$CH_DB" in
    ''|*[!a-zA-Z0-9_-]*) echo "ClickHouse 库名 {$CH_DB} 不合法，跳过"; exit 1 ;;
esac

CH_DB="$CH_DB" /usr/bin/php -r "include '$ROOT_DIR/bootstrap.php'; ch_write('create database if not exists \`'.getenv('CH_DB').'\`');"
/usr/bin/php $ROOT_DIR/public/cli.php clickhouse:install
/usr/bin/php $ROOT_DIR/public/cli.php clickhouse:migrate
