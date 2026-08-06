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
├── production/         # ENV=production 时生效
│   ├── mysql.php       # 覆盖数据库连接（读写分离、线上账号密码）
│   └── blade.php       # 开启模板编译缓存（`compiled_cache => true`）
└── .gitkeep            # 空目录占位
```

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
