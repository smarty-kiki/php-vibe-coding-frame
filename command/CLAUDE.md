# CLAUDE.md

## 项目概述

本目录是框架中的 `command/` 命令行目录。所有 CLI 命令通过 `public/cli.php` 入口文件加载执行。命令通过 `command()` 函数注册，根据第一个 CLI 参数匹配并分发到对应的闭包。

## 命令机制

- **注册方式**: `command($rule, $description, closure $action)` —— 当第一个 CLI 参数匹配 `$rule` 时，执行闭包并退出进程。
- **参数解析**: 从 `$argv` 解析 —— `--key=value` 表示字符串值，`-key` 表示布尔 true。通过 `command_paramater($key, $default)` 获取参数。
- **交互式输入**: `command_read($prompt, $default, $options)` 用于文本输入或选项选择；`command_read_bool($prompt, $default)` 用于 y/n 确认。
- **未匹配命令**: 触发 `if_command_not_found()` 回调，默认打印所有已注册命令的名称和描述。
- **写数据必须手动包 `unit_of_work()`**: CLI 入口不注册 `if_verify`，**没有自动包裹**。命令里用 Entity 写数据要自己包，否则改动静默丢弃；长跑命令按批分段包，不要裹整条命令。
- **在 crontab 里跑的命令**: 项目统一用 `project/config/{test,production}/cron.d/` 管理定时任务，业务命令一律以 `www-data` 身份执行（与 web、队列 worker 同身份，日志归属一致）；cron 的环境里没有 `ENV`，命令要写全 `ENV=test` / `ENV=production` 与 `/usr/bin/php` 绝对路径，漏了 `ENV` 会退回 production 打到生产库上——细节见 `project/CLAUDE.md` 的「定时任务（cron）」。

## 目录结构

```
command/
├── CLAUDE.md
├── entity.php              # entity:restep-last-id — 刷新 ID 生成器缓存
├── migration/
│   ├── migrate.php              # MySQL 迁移命令
│   ├── migrate_clickhouse.php   # ClickHouse 迁移命令
│   ├── sql/
│   │   └── merged/              # 合并后的迁移归档（按 MD5 签名去重）
│   └── clickhouse_sql/          # ClickHouse 迁移 SQL 文件
└── queue/
    ├── queue.php           # Beanstalk 队列管理命令
    └── queue_job/          # 队列任务定义
        ├── load.php        # 任务加载器（include 所有 job 文件）
        └── demo.php        # 示例任务
```

## 数据库迁移系统 (`migration/migrate.php`)

迁移 SQL 文件存放在 `migration/sql/` 目录下，文件名以时间戳为前缀。每个文件使用 `# up` 和 `# down` 标记区分正向迁移和回滚 SQL，语句以 `;` 分隔。

**新增迁移文件规范：**

- **迁移文件**：放置在 `command/migration/sql/` 目录。文件名格式为 `YYYY_mm_dd_HH_MM_SS_描述.sql`（如 `2026_06_06_10_30_00_add_user_table.sql`）。手动创建时严格按此规则命名，全部下划线分隔，描述使用英文蛇形小写，**时分秒必须填写当前实际时间，禁止使用 `00_00_00` 占位**。
- **SQL 文件格式**：必须包含 `# up` 和 `# down` 两部分，分别编写正向迁移和回滚 SQL，每条语句以 `;` 结尾。示例参见 `command/migration/sql/2026_02_06_23_38_20_demo.sql`。

迁移脚本是「禁止绕过框架写库」的显式例外之一（见 `domain/CLAUDE.md`）：DDL 与一次性数据修复本就不走 ORM。迁移里涉及**数据修复**的语句，请注明原因与影响范围。

**命令列表：**

| 命令 | 说明 |
|---|---|
| `migrate:install` | 创建 `migrations` 追踪表 |
| `migrate:uninstall` | 删除 `migrations` 追踪表 |
| `migrate` | 执行待迁移文件 |
| `migrate:dry-run` | 展示将会执行的 SQL，不真正执行 |
| `migrate:make --name=xxx` | 对比数据库当前结构与迁移文件定义结构的差异，生成补差迁移 SQL 文件 |
| `migrate:make-merge` | 将所有已有迁移文件合并为一个合并迁移，原始文件归档到 `merged/` 目录，通过 MD5 签名去重 |
| `migrate:generate-diff` | 生成临时迁移与正式迁移之间的差异变更 SQL |
| `migrate:rollback` | 回滚最近一批迁移 |
| `migrate:reset` | 回滚全部迁移 |

**关键常量**: `MIGRATION_DIR`、`MIGRATION_MERGED_DIR`、`MIGRATION_TABLE`（值为 `migrations`）。

追踪表结构：`id`（自增）、`migration`（文件名）、`batch`（批次号，整数）。

## ClickHouse 迁移系统 (`migration/migrate_clickhouse.php`)

与 MySQL 版并列的一套，文件格式沿用同一套 `# up` / `# down` 约定和 `YYYY_mm_dd_HH_MM_SS_描述.sql` 命名，SQL 文件放 `command/migration/clickhouse_sql/`，两套迁移互不干扰。追踪表建在 ClickHouse 自身（不是 MySQL），配置走 `config_midware('clickhouse', 'migrate')`。

**命令列表：**

| 命令 | 说明 |
|---|---|
| `clickhouse:install` | 创建 `migrations` 追踪表 |
| `clickhouse:uninstall` | 删除 `migrations` 追踪表 |
| `clickhouse:migrate` | 按文件名顺序执行待迁移文件 |
| `clickhouse:dry-run` | 展示将会执行的 up SQL，不真正执行 |
| `clickhouse:rollback` | 回滚最近一批 |
| `clickhouse:reset` | 回滚全部 |
| `clickhouse:make --name=xxx` | 生成空迁移模板文件（正文是注释示例，**需手工填写 SQL**，不做结构对比） |
| `clickhouse:status` | 列出已应用（含批次）与待应用文件 |

**关键常量**：`CH_MIGRATION_DIR`、`CH_MIGRATION_TABLE`（值为 `migrations`）、`CH_MIGRATION_CONFIG_KEY`（值为 `migrate`）。

追踪表结构：`migration`（文件名）、`batch`（批次号）、`create_time`，引擎 `MergeTree() order by (batch, migration)`。

**ClickHouse 特有的注意事项（与 MySQL 版的关键差异）：**

- **没有事务**：迁移 SQL 应自带 `if not exists` / `if exists`，否则失败重跑时会重放已执行的语句并报错。失败时该文件不写入追踪表，但已执行的语句不会回滚
- **`down` 的局限**：ClickHouse 只能 ALTER 列（增删改），改 `ORDER BY` / 分区键 / 引擎**无法 ALTER**，只能重建表，down 脚本会丢数据，且工具不做拦截
- **按 `;` 拆分逐条执行**：HTTP 接口不接受一次提交多条语句。已知弱点：字符串字面量里的 `;` 会被误拆，写迁移时避开。但整行 `--` 注释里的分号不影响——解析器会先剥掉整行注释再拆分
- **回滚记录走 mutation**：删除追踪记录用 `alter table ... delete ... settings mutations_sync = 2`，`mutations_sync` 必须指定，否则 mutation 默认异步，回滚后紧接着的读取仍会看到已删除的行

## 队列系统 (`queue/queue.php`)

基于 Beanstalk 协议的任务队列。任务通过 `queue_job($job_name, $closure, $priority, $retry, $tube_key)` 定义（`$tube_key` 默认 `default`，经 `config/queue.php` 的 `tubes` 映射落到真实 tube，各环境覆盖映射即可换 tube 而业务代码不改；队列固定使用 `config/beanstalk.php` 的 `queue` midware，各函数不暴露 `config_key` 参数）。各 `queue:*` 命令通过 `--tube_key` 指定 tube（默认 `default`，与任务定义同口径）。worker 消费任务时从 payload 恢复投递方的 trace 上下文（job 的 parent span = 投递方 span）、每个任务处理完清掉——队列里的日志与投递方请求可按 `trace_id` 串起来。

**命令列表：**

| 命令 | 说明 |
|---|---|
| `queue:worker` | 启动队列 worker 监听指定 tube。支持 `--tube_key`（默认 `default`）、`--memory_limit`（默认 128MB）。每个任务执行完后自动清理（本地缓存、beanstalk 连接、缓存连接、数据库连接） |
| `queue:status` | 查看队列状态 |
| `queue:pause` | 暂停队列任务派发，`--delay` 参数指定暂停秒数（默认 3600） |
| `queue:peek-buried` | 交互式处理 buried 状态任务，逐条选择 kick 或 delete |
| `queue:ready-to-buried` | 将 ready 状态任务快速转为 buried 状态（灾难恢复场景） |
| `queue:buried-dump` | 将 buried 状态任务导出到 dump 文件并从队列中删除 |
| `queue:dump-import` | 将导出的 dump 文件重新导入队列并进入 ready 状态 |

## 实体命令 (`entity.php`)

- `entity:restep-last-id` —— 扫描所有非 `migrations` 表，获取每张表的最大 `id`，通过 `cache_increment` 重置 ID 生成器的缓存键（格式为 `{表名}_last_id`）。输出表格展示变更前后的值。读写与 ORM 同源：表走 `entity` midware、游标走 `idgenter` midware。
- **`entity:restep-last-id` 的触发时机**：任何绕过框架写过库之后（数据导入、外部系统写入、运维改库、修过 `version = 0` 的存量行）都必须执行，否则 ID 生成器落后于库内最大值，后续框架 INSERT 会主键冲突。

## 新增命令

1. 在 `command/` 或其子目录中创建或编辑 PHP 文件。
2. 调用 `command('命令:名称', '描述', function () { ... })`。
3. 在 `public/cli.php` 中通过 `include COMMAND_DIR.'/你的文件.php'` 加载。

## 新增队列任务

1. 在 `command/queue/queue_job/` 中创建 PHP 文件。
2. 调用 `queue_job('任务名', $closure, $priority, $retry_delays_array, $tube_key)`。
3. 在 `command/queue/queue_job/load.php` 中 include 该文件。

## 关键依赖

- `frame/base_function.php` —— 工具函数（`array_get`、`config`、`env`、`datetime`、`http` 等）
- `frame/cli_command.php` —— 命令注册与 CLI 参数解析（`command`、`command_paramater`、`command_read`、`command_read_bool`）
- `frame/database_mysql.php` —— 数据库操作（`db_query`、`db_structure`、`db_query_value`、`db_query_column`、`db_insert`、`db_delete`）
- `frame/cache_redis.php` —— Redis 缓存操作（`cache_get`、`cache_delete`、`cache_increment`）
- `frame/queue_beanstalk.php` —— 队列操作（`queue_watch`、`queue_status`、`queue_pause`、`queue_push`、`queue_job`、`queue_finish_action` 及 beanstalk 连接原语）
- `frame/log.php` —— 日志（`log_module`、`log_exception`；JSON Lines，自动带 trace 上下文）
- `frame/trace.php` —— 全链路 trace 上下文（`trace_init`、`trace_id`；CLI 入口与队列 worker 使用）
