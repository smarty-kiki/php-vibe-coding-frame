#!/bin/bash

ROOT_DIR="$(cd "$(dirname $0)" && pwd)"/../../..

# 并发保护：cron 与手工触发（或迁移期残留的旧 crontab 条目）同时跑时只放一个进来，
# 同时跑两次会重复 migrate / reload / 重启 worker
exec 200>>/var/lock/php-vibe-coding-frame-deploy.lock
flock -n 200 || exit 0

cd $ROOT_DIR

BH=`git log -1 --format="%H"`
git pull origin master
git checkout -f master
AH=`git log -1 --format="%H"`

if [ $BH != $AH ];then
    /bin/bash $ROOT_DIR/project/tool/production/after_push.sh
fi
