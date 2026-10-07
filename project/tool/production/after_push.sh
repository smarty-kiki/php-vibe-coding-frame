#!/bin/bash

ROOT_DIR="$(cd "$(dirname $0)" && pwd)"/../../..

ln -fs $ROOT_DIR/project/config/production/caddy/php-vibe-coding-frame.Caddyfile /etc/caddy/3.php-vibe-coding-frame.Caddyfile
/usr/sbin/service caddy reload

# 会产生日志的命令都用 www-data 跑：与 web、队列 worker、crontab 里的业务命令同一身份
# ENV=production 写全：不设 ENV 时 env() 也会退回 production，但显式写出来不容易看走眼
runuser -u www-data -- /bin/sh -c "ENV=production /usr/bin/php $ROOT_DIR/public/cli.php migrate:install"
runuser -u www-data -- /bin/sh -c "ENV=production /usr/bin/php $ROOT_DIR/public/cli.php migrate"

# ClickHouse 初始化（建库 + 分析库迁移；不可达会自动跳过）
runuser -u www-data -- /bin/sh -c "ENV=production /bin/bash $ROOT_DIR/project/tool/clickhouse_migrate.sh"

# 日志目录与文件（路径与 config/production/log.php 一致）
# 属主 www-data + 目录 2775 / 文件 664：业务侧（web、worker、crontab）都是 www-data，
# 这一层是兜底——部署脚本以 root 跑，万一它新建了日志文件，chmod 这一步会把它掰回来
# 只建不截断：部署不能清掉现场日志
mkdir -p /var/log/php-vibe-coding-frame
touch /var/log/php-vibe-coding-frame/exception.log \
      /var/log/php-vibe-coding-frame/notice.log \
      /var/log/php-vibe-coding-frame/module.log
chown -R www-data:www-data /var/log/php-vibe-coding-frame
chmod 2775 /var/log/php-vibe-coding-frame
chmod 664 /var/log/php-vibe-coding-frame/*.log

# 定时任务：拷贝而不是软链——cron 会校验 /etc/cron.d 下文件的属主与权限，软链会指回仓库里的文件
cp -f $ROOT_DIR/project/config/production/cron.d/php-vibe-coding-frame /etc/cron.d/php-vibe-coding-frame
chown root:root /etc/cron.d/php-vibe-coding-frame
chmod 644 /etc/cron.d/php-vibe-coding-frame

ln -fs $ROOT_DIR/project/config/production/supervisor/php-vibe-coding-frame_queue_worker.conf /etc/supervisor/conf.d/php-vibe-coding-frame_queue_worker.conf
/usr/bin/supervisorctl update
/usr/bin/supervisorctl restart php-vibe-coding-frame_queue_worker:*

chmod 777 /var/www/php-vibe-coding-frame/view/blade
rm -rf /var/www/php-vibe-coding-frame/view/blade/*.php
