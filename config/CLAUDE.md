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
| `mysql.php` | 数据库连接 | 定义 midwares 到 resources 的映射，resources 中配置连接参数（socket 或 host/port）、读写分离、PDO options |
| `redis.php` | Redis 连接 | 同上 midwares → resources 模式，支持 host/port 或 sock 连接、auth 认证、database 选择和 Redis options |
| `clickhouse.php` | ClickHouse 连接 | 同上 midwares → resources 模式，配置 host/port、账号密码、database、超时与随请求下发的 `settings`；`midwares` 含 `default`（业务查询）与 `migrate`（`clickhouse:*` 迁移命令），默认都指向 `local`。`settings` 会被框架补上 `output_format_json_quote_64bit_integers` / `_decimals` 两项精度安全默认（64 位整数与 Decimal 以字符串返回），此处显式设 0 可覆盖 |
| `beanstalk.php` | Beanstalkd 队列 | midwares → resources 模式，配置 host/port/timeout |
| `blade.php` | Blade 模板引擎 | 配置 `compiled_path`（编译后模板存放目录，指向 `ROOT_DIR.'/view/blade/'`） |
| `log.php` | 日志 | 配置三类日志路径：`exception_path`、`notice_path`、`module_path` |
| `error_code.php` | 错误码 | 定义 `错误码 => 文案` 的键值对，文案中可用 `{param}` 占位符，由 `otherwise_error_code()` 配合使用 |

### 环境覆盖目录

```
config/
├── development/        # ENV=development 时生效
│   ├── mysql.php       # 覆盖数据库连接（如开发环境使用 root 账号）
│   └── blade.php       # 关闭模板编译缓存（`compiled_cache => false`）
├── test/               # ENV=test 时生效（独立测试服务器）
│   ├── mysql.php       # 测试环境自己的库与账号（default_test / test_user / test_password）
│   ├── clickhouse.php  # 同一口径，库名 default_test
│   ├── log.php         # 日志落 /var/log/php-vibe-coding-frame/，与开发环境分开
│   └── blade.php       # 开启模板编译缓存（贴近生产）
├── production/         # ENV=production 时生效
│   ├── mysql.php       # 覆盖数据库连接（读写分离、线上账号密码）
│   ├── clickhouse.php  # 库名 default_prod（与 MySQL 命名对齐）
│   ├── log.php         # 日志落 /var/log/php-vibe-coding-frame/
│   └── blade.php       # 开启模板编译缓存（`compiled_cache => true`）
└── .gitkeep            # 空目录占位
```

### 测试环境（ENV=test）

独立测试服务器用（测试同学验收、上线前走查），取值贴近生产，但数据、日志、Redis 都与开发环境隔开：

- **数据隔离**：MySQL 用 `default_test` 库 + `test_user` / `test_password` 账号（命名与生产的 `default_prod` / `prod_user` / `prod_password` 对齐）；ClickHouse 用 `default_test` 库；Redis 用测试服务器自己的实例，配置不做覆盖
- **Redis 不要与开发环境共用**：缓存、**分布式锁**与 **ID 发号器**都在 Redis 上，共用会把发号器游标互相推高，最终主键冲突。真要为省资源共用一台，必须补一个 `config/test/redis.php` 把 `database` 换成独立 db index
- **日志隔离**：测试与生产都落 `/var/log/php-vibe-coding-frame/`（`exception.log` / `notice.log` / `module.log`），开发环境仍在 `/tmp/php_*.log`。目录由 `project/tool/{test,production}/` 的启动/部署脚本建好——**PHP 不会自建目录**，换机器部署时别忘了这一步；权限按「目录 2775 + 文件 664 + 属主 www-data」设，让 web 与 crontab 里的 CLI 都写得进去（细节见 `project/CLAUDE.md`）
- **模板编译缓存开着**（与生产一致）：改完模板要清一次 `view/blade/*.php`，部署脚本 `project/tool/test/after_push.sh` 里已带
- **队列（beanstalkd）无法按环境隔离**：tube 由业务侧 `queue_job()` 的定义决定，配置层管不到。测试环境用独立的 beanstalkd 实例，或让业务侧给测试环境用不同的 tube 名

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
