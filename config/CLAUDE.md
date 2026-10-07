# config/ 配置文件目录

## 配置加载机制

所有配置文件通过 `config($file_name)` 函数按需加载，该函数定义在 `frame/base_function.php`。

**加载流程：**
1. `bootstrap.php` 中通过 `config_dir(ROOT_DIR.'/config')` 注册本目录
2. 调用 `config('mysql')` 时，先加载 `config/mysql.php`（基础配置）
3. 再根据 `env()` 的值（由 `$_SERVER['ENV']` 决定，默认 `production`）加载环境覆盖文件，如 `config/production/mysql.php`
4. 环境覆盖文件通过 `array_replace_recursive` 合并到基础配置之上——只需写要覆盖的字段

**环境切换：** 在 nginx/apache 或 php-fpm 中设置 `ENV` 环境变量即可，例如 `fastcgi_param ENV development;`。

## 配置文件说明

### 基础配置文件（config/ 根目录）

| 文件 | 用途 | 说明 |
|------|------|------|
| `mysql.php` | 数据库连接 | 定义 midwares 到 resources 的映射，resources 中配置连接参数（socket 或 host/port）、读写分离、PDO options；三个 midware 名各有用途：`entity`（ORM 实体读写，dao 的 `db_config_key` 默认值）、`migrate`（`migrate:*` 迁移命令）、`default`（`db_*` 直连与工具脚本）；三者默认同指 `local`，其中 `entity` 与 `migrate` 必须指向同一个库（迁移建的表就是 ORM 要读写的那份），`default` 可自由指向别处 |
| `redis.php` | Redis 连接 | 同上 midwares → resources 模式，支持 host/port 或 sock 连接、auth 认证、database 选择和 Redis options |
| `clickhouse.php` | ClickHouse 连接 | 同上 midwares → resources 模式，配置 host/port、账号密码、database、超时与随请求下发的 `settings`；`midwares` 含 `default`（业务查询）与 `migrate`（`clickhouse:*` 迁移命令），默认都指向 `local`。`settings` 会被框架补上 `output_format_json_quote_64bit_integers` / `_decimals` 两项精度安全默认（64 位整数与 Decimal 以字符串返回），此处显式设 0 可覆盖 |
| `beanstalk.php` | Beanstalkd 队列 | midwares → resources 模式，配置 host/port/timeout；队列子系统（`queue_job` / `queue_watch` / `queue:*` 命令）固定取 `queue` midware（框架内写死 `QUEUE_BEANSTALK_MIDWARE_KEY`，不暴露 `config_key` 参数，现指向 `local`），将来给队列换独立实例时只改 `queue` 指向的 resource |
| `queue.php` | 队列映射与消费参数 | 四段，各队列实现只读自己那段：`tubes`（beanstalk：tube_key => 真实 tube 名，业务侧统一写 tube_key，各环境覆盖本文件即可换真实 tube 而业务代码不改，未映射的 key 直接报错；测试/生产已默认覆盖为带项目名的真实 tube）、`topics`（kafka：topic_key => 真实 topic 名，口径同 tubes）、`dead_letter_suffix`（kafka：死信 topic ＝ 真实 topic + 该后缀，失败重试超限的消息落这里）、`consumer`（kafka：消费组前缀、`auto_offset_reset`、`max_poll_interval_ms`、`session_timeout_ms`、每轮消费等待秒数）；常驻的 queue worker 在启动时读取映射，改完要重启 worker 生效 |
| `kafka.php` | Kafka 队列连接 | 与 `beanstalk.php` 同构的 midwares → resources 模式，配置 `brokers`、连接/元数据超时、投递的 `flush_timeout`（须大于 `message_timeout`，否则会把「可能仍会送达」的消息误报成投递失败）与 `message_timeout`；队列子系统固定取 `queue` midware（框架内写死 `QUEUE_KAFKA_MIDWARE_KEY`）。**运行环境需装 php-rdkafka 扩展**，只有项目切到 `frame/queue_kafka.php` 时才会被读取 |
| `blade.php` | Blade 模板引擎 | 配置 `compiled_path`（编译后模板存放目录，指向 `ROOT_DIR.'/view/blade/'`） |
| `log.php` | 日志 | 配置三类日志路径：`exception_path`、`notice_path`、`module_path`，以及日志的 `service` 字段（服务名，新建项目时随 naming_project.sh 替换）；日志输出为 JSON Lines、自动带 trace 上下文，格式见 `frame/CLAUDE.md` 的 log.php 条目 |
| `error_code.php` | 错误码 | 定义 `错误码 => 文案` 的键值对，文案中可用 `{param}` 占位符，由 `otherwise_error_code()` 配合使用 |

### 环境覆盖目录

```
config/
├── development/        # ENV=development 时生效
│   ├── mysql.php       # 覆盖数据库连接（如开发环境使用 root 账号）
│   └── blade.php       # 关闭模板编译缓存（`compiled_cache => false`）
├── test/               # ENV=test 时生效（独立测试服务器）
│   ├── mysql.php       # 测试环境的库与账号（php-vibe-coding-frame，随项目名替换）
│   ├── clickhouse.php  # 库名与 MySQL 同口径（php-vibe-coding-frame，随项目名替换）
│   ├── queue.php       # 真实 tube / topic 加项目名前缀（php-vibe-coding-frame-default）
│   ├── log.php         # 日志落 /var/log/php-vibe-coding-frame/，与开发环境分开
│   └── blade.php       # 开启模板编译缓存（贴近生产）
├── production/         # ENV=production 时生效
│   ├── mysql.php       # 覆盖数据库连接（读写分离；库与账号带项目名，随项目名替换）
│   ├── clickhouse.php  # 库名与 MySQL 同口径（php-vibe-coding-frame，随项目名替换）
│   ├── queue.php       # 真实 tube / topic 加项目名前缀（php-vibe-coding-frame-default）
│   ├── log.php         # 日志落 /var/log/php-vibe-coding-frame/
│   └── blade.php       # 开启模板编译缓存（`compiled_cache => true`）
└── .gitkeep            # 空目录占位
```

### 测试环境（ENV=test）

独立测试服务器用（测试同学验收、上线前走查），取值贴近生产，但数据、日志、Redis 都与开发环境隔开：

- **数据隔离**：MySQL 与 ClickHouse 都用带项目名的库（`php-vibe-coding-frame`，建新项目时随 naming_project.sh 替换；测试与生产同名、靠各自独立的服务器隔开），MySQL 的账号与密码同为此名；Redis 用测试服务器自己的实例，配置不做覆盖
- **Redis 不要与开发环境共用**：缓存、**分布式锁**与 **ID 发号器**都在 Redis 上，共用会把发号器游标互相推高，最终主键冲突。真要为省资源共用一台，必须补一个 `config/test/redis.php` 把 `database` 换成独立 db index
- **日志隔离**：测试与生产都落 `/var/log/php-vibe-coding-frame/`（`exception.log` / `notice.log` / `module.log`），开发环境仍在 `/tmp/php_*.log`。目录由 `project/tool/{test,production}/` 的启动/部署脚本建好——**PHP 不会自建目录**，换机器部署时别忘了这一步；权限按「目录 2775 + 文件 664 + 属主 www-data」设，让 web 与 crontab 里的 CLI 都写得进去（细节见 `project/CLAUDE.md`）
- **模板编译缓存开着**（与生产一致）：改完模板要清一次 `view/blade/*.php`，部署脚本 `project/tool/test/after_push.sh` 里已带
- **队列按 tube / topic 映射隔离**：业务侧统一写 tube_key / topic_key，真实名称由 `config/queue.php` 的 `tubes` / `topics` 映射决定（可按环境覆盖）——测试与生产已默认把 `default` 映射为带项目名的 `php-vibe-coding-frame-default`（新建项目时随 naming_project.sh 替换），与开发环境的 `default` 天然隔开，共用一套 beanstalkd 或 kafka 也不会串队列（kafka 侧消费组名同样带项目名前缀，见 `consumer.group_prefix`）

部署侧（nginx / caddy / supervisor / 启动脚本）与 `project/tool/test/` 的对应关系见 `project/CLAUDE.md`。

## 新增配置文件

1. 在 `config/` 根目录创建 `xxx.php`，返回关联数组
2. 如需环境差异化，在 `config/development/` 和 `config/production/` 下创建同名文件，只写要覆盖的字段
3. 代码中通过 `config('xxx')` 获取配置数组

## 配置中间件模式（midwares → resources）

mysql、redis、beanstalk 等基础设施配置遵循统一的 **midwares → resources** 模式：

```php
return [
    'midwares' => [
        'default' => 'local',   // 逻辑名称 → 资源名称
        'entity'  => 'local',
    ],
    'resources' => [
        'local' => [            // 实际连接参数
            'host' => '...',
            'port' => 6379,
        ],
    ],
];
```

- `midwares` 定义"谁用什么资源"，多个 midware 可以指向同一个 resource
- `resources` 定义"资源的具体连接参数"
- 通过 `config_midware('redis')` 可获取 `default` 对应的 resource 配置

这个设计的目的是让环境覆盖时可以只改 resources 中的连接信息，不需要动 midwares 映射关系。
