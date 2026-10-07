#!/bin/bash

ROOT_DIR="$(cd "$(dirname $0)" && pwd)"/../../..

ln -fs $ROOT_DIR/project/config/test/nginx/php-vibe-coding-frame.conf /etc/nginx/sites-enabled/default
/usr/sbin/service nginx reload
# 用域名 + TLS（caddy）时，把上面两行换成：
# ln -fs $ROOT_DIR/project/config/test/caddy/php-vibe-coding-frame.Caddyfile /etc/caddy/3.php-vibe-coding-frame-test.Caddyfile
# /usr/sbin/service caddy reload

# 会产生日志的命令都用 www-data 跑：与 web、队列 worker 同一身份，谁写日志都是 www-data
# ENV=test 不能省：不设 ENV 时 env() 会退回 production，迁移会打到生产的库上
runuser -u www-data -- /bin/sh -c "ENV=test /usr/bin/php $ROOT_DIR/public/cli.php migrate:install"
runuser -u www-data -- /bin/sh -c "ENV=test /usr/bin/php $ROOT_DIR/public/cli.php migrate"

# 定时任务：拷贝而不是软链——cron 会校验 /etc/cron.d 下文件的属主与权限
cp -f $ROOT_DIR/project/config/test/cron.d/php-vibe-coding-frame /etc/cron.d/php-vibe-coding-frame
chown root:root /etc/cron.d/php-vibe-coding-frame
chmod 644 /etc/cron.d/php-vibe-coding-frame

ln -fs $ROOT_DIR/project/config/test/supervisor/php-vibe-coding-frame_queue_worker.conf /etc/supervisor/conf.d/php-vibe-coding-frame_queue_worker.conf
/usr/bin/supervisorctl update
/usr/bin/supervisorctl restart php-vibe-coding-frame_queue_worker:*

# 模板改了要清编译缓存（config/test/blade.php 的 compiled_cache 是开着的）
chmod 777 $ROOT_DIR/view/blade
rm -rf $ROOT_DIR/view/blade/*.php
