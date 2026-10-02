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
      nginx/          # 生产环境 nginx site 配置
      supervisor/     # 生产环境 supervisor queue_worker
  tool/
    classmap.sh                  # 生成自动加载类映射文件（autoload.php）
    naming_project.sh            # 一键重命名项目引用
    start_development_server.sh  # Docker 启动开发环境
    development/
      after_env_start.sh         # 开发容器启动后初始化（日志、数据库、迁移）
      queue_job_watch_by_md5.sh  # 文件变更检测自动重启队列 worker
    production/
      after_push.sh              # 部署后步骤（nginx reload → migrate → supervisor update）
      check_update.sh            # git pull 检测变更，自动触发 after_push
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
生产环境通过 cron 定时执行，`git pull` 后对比 HEAD hash，若有变更则执行 `after_push.sh`。

### tool/production/after_push.sh
生产部署流程：
1. 链接 nginx 配置 → reload nginx
2. 运行 `migrate:install` 和 `migrate`
3. 链接 supervisor 配置 → update + restart queue worker
4. 清空 Blade 编译缓存

## 配置模板惯例

- nginx/supervisor 配置文件中使用 `php-vibe-coding-frame` 作为项目名占位符
- 开发和生产版本的配置差异：
  - nginx 开发版多 `fastcgi_param ENV 'development'`
  - supervisor 开发版多 `queue_job_watch` 程序，且 worker 的 `stopwaitsecs` 较短（5s vs 60s）
  - 生产版 supervisor 增加日志文件轮转配置
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

# 后续通过 cron 定时运行 check_update.sh 自动部署
```
