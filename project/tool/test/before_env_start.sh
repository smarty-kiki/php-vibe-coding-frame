#!/bin/bash

ROOT_DIR="$(cd "$(dirname $0)" && pwd)"/../../..

# 日志目录与文件要先于服务建好：supervisor 启动 worker 时要能打开 stdout_logfile，
# PHP 也不会自建日志目录。路径与 config/test/log.php 一致
# 权限口径：目录 2775（组内可写 + 新建文件继承 www-data 组）、文件 664，
# web（www-data）与 crontab 里的 CLI 都能追加写；cron 跑非 root 用户时，把该用户加进 www-data 组
# 只建不截断：重启不该清掉现场日志
mkdir -p /var/log/php-vibe-coding-frame
touch /var/log/php-vibe-coding-frame/exception.log \
      /var/log/php-vibe-coding-frame/notice.log \
      /var/log/php-vibe-coding-frame/module.log
chown -R www-data:www-data /var/log/php-vibe-coding-frame
chmod 2775 /var/log/php-vibe-coding-frame
chmod 664 /var/log/php-vibe-coding-frame/*.log

ln -fs $ROOT_DIR/project/config/test/nginx/php-vibe-coding-frame.conf /etc/nginx/sites-enabled/default
ln -fs $ROOT_DIR/project/config/test/supervisor/php-vibe-coding-frame_queue_worker.conf /etc/supervisor/conf.d/php-vibe-coding-frame_queue_worker.conf
ln -fs $ROOT_DIR/project/config/test/php_fpm_pool/sse.conf /etc/php/8.4/fpm/pool.d/sse.conf

# 定时任务：拷贝而不是软链——cron 会校验 /etc/cron.d 下文件的属主与权限，软链会指回仓库里的文件
cp -f $ROOT_DIR/project/config/test/cron.d/php-vibe-coding-frame /etc/cron.d/php-vibe-coding-frame
chown root:root /etc/cron.d/php-vibe-coding-frame
chmod 644 /etc/cron.d/php-vibe-coding-frame

# 需要域名 + TLS（与生产同构）时改用 caddy，把上面 nginx 那行软链撤掉后换成：
# ln -fs $ROOT_DIR/project/config/test/caddy/php-vibe-coding-frame.Caddyfile /etc/caddy/3.php-vibe-coding-frame-test.Caddyfile
