# CLAUDE.md

## project/ 目录定位

部署与基础设施配置目录，管理开发环境启动、生产部署脚本、以及 nginx/supervisor 配置模板。

## 目录结构

```
project/
  config/
    development/
      nginx/          # 开发环境 nginx site 配置
      supervisor/     # 开发环境 supervisor queue_worker + job_watch
      bash/           # CLI 自动补全脚本
    production/
      caddy/          # 生产环境 caddy 配置（域名 + TLS）
      nginx/          # 生产环境 nginx site 配置
      supervisor/     # 生产环境 supervisor queue_worker
      cron.d/         # 生产环境定时任务（部署时拷到 /etc/cron.d/）
    test/
      nginx/          # 测试环境 nginx site 配置
      caddy/          # 测试环境 caddy 配置（测试域名 + TLS）
      supervisor/     # 测试环境 supervisor queue_worker
      php_fpm_pool/   # 测试环境 SSE 独立 pool
      cron.d/         # 测试环境定时任务（启动/部署时拷到 /etc/cron.d/）
  tool/
    classmap.sh                  # 生成自动加载类映射文件（autoload.php）
    naming_project.sh            # 一键重命名项目引用
    start_development_server.sh  # Docker 启动开发环境
    start_test_server.sh         # Docker 启动测试环境（8081 / 13306）
    clickhouse_migrate.sh        # ClickHouse 建库 + 跑分析库迁移（测试与生产共用，不可达自动跳过）
    development/
      after_env_start.sh         # 开发容器启动后初始化（日志、数据库、迁移）
      queue_job_watch_by_md5.sh  # 文件变更检测自动重启队列 worker
    production/
      after_push.sh              # 部署后步骤（caddy reload → migrate → 日志目录 → 定时任务 → worker → 清缓存）
      check_update.sh            # git pull 检测变更，自动触发 after_push（带 flock 防并发）
    test/
      before_env_start.sh        # 测试容器启动前：建日志目录与文件（含权限）+ 链接配置 + 装定时任务
      after_env_start.sh         # 测试容器启动后：建 default_test 库与 test_user 账号、跑迁移、ClickHouse 初始化
      after_push.sh              # 测试环境部署后步骤（reload → migrate → 定时任务 → worker → 清模板缓存）
      reset_data.sh              # 重置测试数据（重建 default_test 库 + 清测试 Redis db，需 --yes）
```

## 关键脚本说明

### tool/start_development_server.sh
Docker 容器启动开发环境，挂载整个项目到 `/var/www/php-vibe-coding-frame`，映射 nginx 和 supervisor 配置，设置 `ENV=development`。容器启动后自动执行 `after_env_start.sh`。

### tool/naming_project.sh <新名称>
将配置、脚本及各目录 CLAUDE.md 中所有 `php-vibe-coding-frame` 占位符替换为新项目名，同时重命名配置文件。创建新项目后首次使用前运行一次即可。

### tool/classmap.sh <目录>

框架不使用 composer autoload，而是依赖一套基于约定扫描的类自动加载机制。
`classmap.sh` 扫描目标目录，生成 `autoload.php`，当 PHP 引用不存在的类时，
`spl_autoload_register` 通过生成的类名→文件路径映射找到对应文件并 `include`。

**哪些目录需要生成**：

| 目录 | autoload.php 位置 | 加载链 |
|------|-------------------|--------|
| `util/` | `util/autoload.php` | `bootstrap.php` → `util/load.php` → `util/autoload.php` |
| `domain/` | `domain/autoload.php` | `bootstrap.php` → `domain/load.php` → `domain/autoload.php` |

> `frame/` 目录不走 classmap，核心文件在 `bootstrap.php` 中显式 `include`。
> `command/queue/queue_job/` 目录较小（仅 `demo.php`），在 `load.php` 中直接 `include`，暂未使用 classmap。

**如何运行**：

```bash
# 新增/删除/重命名 util/ 下的类后
bash project/tool/classmap.sh util

# 新增/删除/重命名 domain/ 下的 Entity 或 DAO 后
bash project/tool/classmap.sh domain
```

> 生成的 `autoload.php` 不应手动编辑——每次运行 classmap.sh 会覆盖。

**扫描约定（不满足则不会被收录）**：
- `class`、`abstract class`、`interface` 关键字必须小写，位于行首
- 关键字与类名之间为一个空格
- 类声明的左花括号 `{` 必须另起一行

示例——符合约定：
```php
class demo
{
```

示例——不符合约定（不会被收录）：
```php
class demo {
    final class demo ...
```

### tool/development/queue_job_watch_by_md5.sh
通过 md5 监控 `command/queue/queue_job/` 目录中文件的新增/修改/删除，检测到变更时自动杀死旧队列 worker，supervisor 会自动拉起新 worker。仅开发环境使用。

### tool/production/check_update.sh
生产环境通过 cron 定时执行（`*/5`，配置在 `project/config/production/cron.d/php-vibe-coding-frame`，见下面「定时任务（cron）」一节；这一条必须以 `root` 跑），`git pull` 后对比 HEAD hash，若有变更则执行 `after_push.sh`。

脚本里有 `flock` 防并发：cron 与手工触发、或迁移期残留的旧 crontab 条目同时跑时，只放一个进来——同时跑两次会重复 migrate / reload / 重启 worker。

### tool/production/after_push.sh
生产部署流程：
1. 链接 caddy 配置 → reload
2. 以 www-data 跑 `migrate:install` 和 `migrate`，再调 `clickhouse_migrate.sh` 初始化 ClickHouse（不可达自动跳过）
3. 建日志目录与文件（`/var/log/php-vibe-coding-frame/`，路径与 `config/production/log.php` 一致）：目录 2775 + 文件 664 + 属主 www-data，只建不截断
4. 把定时任务拷到 `/etc/cron.d/php-vibe-coding-frame`
5. 链接 supervisor 配置 → update + restart queue worker
6. 清空 Blade 编译缓存

### tool/test/*（测试环境）

测试环境是独立服务器，配置与应用侧的 `config/test/` 配套：

- `before_env_start.sh` —— 容器/机器启动前建好日志目录与文件（`/var/log/php-vibe-coding-frame/`，supervisor 起 worker 时要能打开日志文件，PHP 不会自建目录），链接 nginx、supervisor、SSE pool 配置（要用域名 + TLS 时改链 caddy 那份，脚本里有注释），并把定时任务装到 `/etc/cron.d/`
- `after_env_start.sh` —— 启动后建 `default_test` 库与 `test_user` 账号、跑 MySQL 迁移、调 `clickhouse_migrate.sh`（同 `tool/` 根目录那份，测试与生产共用，库名取自当前 ENV 的配置；不可达自动跳过）
- `after_push.sh` —— 每次部署后的步骤：reload → `migrate` → 装定时任务 → supervisor `update` + `restart` → 清 Blade 编译缓存
- `reset_data.sh` —— 把测试数据重置干净：重建测试库 + 重跑迁移 + 清测试 Redis db。必须显式传 `--yes`，且库名必须是 `test` 或以 `_test` 结尾（ENV 配错时拒绝执行，防止误清开发库或生产库）
- `start_test_server.sh` —— 本机用同一镜像起一个 `ENV=test` 容器（端口 8081 / 13306，避免与开发容器冲突）

> 测试环境的所有命令都要带 `ENV=test`：不设 ENV 时 `env()` 会退回 `production`，迁移与 worker 都会打到生产配置上。

### 定时任务（cron）

测试与生产的定时任务由仓库统一管理：`project/config/{test,production}/cron.d/php-vibe-coding-frame`，启动/部署脚本把它**拷贝**到 `/etc/cron.d/php-vibe-coding-frame`（不是软链——cron 会校验 `/etc/cron.d` 下文件的属主与权限，软链会指回仓库里那个文件）。

文件里的两条纪律：

- **业务命令用 `www-data` 跑**（`分 时 日 月 周 www-data 命令`）：与 web、队列 worker 同一身份，谁写日志都是 www-data，不会出现属主错位
- **php 命令写全 `ENV=test` / `ENV=production` 与 `/usr/bin/php` 绝对路径**：cron 的执行环境里没有 `ENV`、`PATH` 也很短，漏了 `ENV` 会退回 production
- 部署检查 `check_update.sh` 是例外，必须以 `root` 跑（git pull、写 `/etc`、`service reload`、`supervisorctl`）

文件里带了一个 `touch /var/log/php-vibe-coding-frame/cron_heartbeat.log` 的心跳示例（`*/5 * * * *`，不需要就删掉）和业务命令示例。

### 日志目录的权限（测试与生产都要管）

业务侧的写入方（web、队列 worker、crontab 里的业务命令、部署脚本里的 `migrate`）现在统一是 `www-data`，日志归属一致；部署脚本本身以 root 跑，是唯一可能的例外：

- 目录 `2775`（组内可写 + 新建文件继承 `www-data` 组）、文件 `664`，属主 `www-data`
- 这套权限是**兜底**：万一 root 的部署脚本新建了日志文件（`root:www-data 0644`），部署时那次 `chmod` 会把它掰回来，避免之后 www-data 写不进去（`error_log` 写失败只报警告、不会中断业务，很容易静默丢日志）
- 脚本只建不截断（`touch` 而不是 `date >`）：部署与服务重启不能清掉现场日志
- 建目录/文件这一步要在服务启动之前做（`before_env_start.sh` / `after_push.sh` 里都是这个顺序）

## 配置模板惯例

- nginx/supervisor 配置文件中使用 `php-vibe-coding-frame` 作为项目名占位符
- 三个环境的配置差异：
  - nginx：开发版与测试版显式写 `fastcgi_param ENV 'development' / 'test'`（生产版不写——`env()` 默认就是 production）
  - supervisor：执行用户开发版是 `root`、测试/生产版是 `www-data`（与 web、crontab 同一身份）；开发版 `stopwaitsecs=5` 且多一个 `queue_job_watch` 程序（改代码自动重启 worker），测试版与生产版 `stopwaitsecs=60` + 日志轮转，worker 日志落 `/var/log/php-vibe-coding-frame/queue_worker.log`，测试版显式写 `environment= ENV="test"`
  - caddy：只有生产与测试环境有（域名 + TLS）；测试版域名是 `php-vibe-coding-frame-test.yao-yang.cn`，建新项目时记得替换
  - SSE pool：开发与测试环境带 `php_fpm_pool/sse.conf`（独立 SSE pool），nginx 的 `/sse/` 分流到它
- Docker 模式下 supervisor 配置直接挂载进容器，生产环境通过 `ln -fs` 链接到系统目录

## 使用流程

新项目初始化：
```bash
bash project/tool/naming_project.sh my-app    # 替换项目名占位符
bash project/tool/start_development_server.sh # 启动开发环境
```

生产部署：
```bash
# 首次部署
bash project/tool/production/after_push.sh

# 后续由 cron 定时跑 check_update.sh 自动部署（定时任务统一在 project/config/production/cron.d/ 里管理）
```

测试环境：

```bash
# 本机起一个 ENV=test 的容器（端口 8081 / 13306）
bash project/tool/test/start_test_server.sh

# 独立测试服务器：容器启动会自动跑 before_env_start.sh + after_env_start.sh
#（建日志目录与文件、链接配置、装定时任务、建 default_test 库、跑迁移）
# 之后每次部署完跑一次 after_push.sh；要把测试数据清干净时跑 reset_data.sh --yes
```
