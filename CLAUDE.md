# CLAUDE.md

## 项目概述

单层 MVC PHP 框架，专为 PHP-FPM 快速开发场景设计。无 DI 容器、无注解、无 YAML 路由配置——路由即闭包，控制器即函数，依赖通过 `include` 显式加载，无 Composer autoload。

## 架构概览

```
nginx → public/index.php → bootstrap.php（加载 frame/） → 注册错误处理
  → 注册 if_verify（unit_of_work 包裹） → 加载 controller/ → 路由匹配 → 响应

cli  → public/cli.php    → bootstrap.php（加载 frame/） → 加载 command/ → 命令匹配
```

核心设计理念：
- **路由闭包的返回值决定响应格式**：数组 → JSON，字符串 → HTML
- **所有控制器闭包默认包裹在 `unit_of_work()` 中**，自动提交实体变更并处理事务
- **`$_SERVER['ENV']`** 控制环境（development/production），配置自动按环境合并

## 目录结构

```
controller/       # HTTP 路由定义（闭包，按模块拆分文件）
domain/           # 领域层：Entity（ActiveRecord）、DAO
frame/            # 框架核心库（ORM、DB、Cache、Queue、Blade、日志）
config/           # PHP 数组配置 + ENV 环境覆盖（development/production）
command/          # CLI 命令（migrate、queue、entity）
public/           # Web 根目录（index.php HTTP 入口、cli.php CLI 入口）
view/             # Blade 模板（.php 模板 + blade/ 编译缓存）
interceptor/      # 拦截器（请求前置/后置逻辑）
project/          # 部署配置（nginx、supervisor、docker）
util/             # 工具类（外部能力封装：支付、短信、OSS 等）
```

### 加载链

```
bootstrap.php
  ├── frame/base_function.php     # 数组/字符串/HTTP/配置/日期工具函数
  ├── frame/orm_entity.php        # Entity 基类 + DAO 基类 + 关系系统 + 本地缓存
  ├── frame/otherwise.php         # 断言与异常
  ├── frame/database_mysql.php    # PDO MySQL（读写分离、事务）
  ├── frame/cache_redis.php       # Redis（连接池、KV/Hash/List/Bitmap）
  ├── frame/queue_beanstalk.php   # Beanstalkd（socket 协议实现）
  ├── frame/orm_unitofwork.php    # 工作单元 + Redis ID 生成器
  ├── frame/log.php               # 日志（微秒精度时间戳）
  ├── config_dir()                # 注册 config/ 目录
  ├── util/load.php               # 工具类（外部 SDK）
  └── domain/load.php             # 领域层（Entity + DAO + Knowledge）
```

## controller/ 路由编写

路由函数定义在 `frame/php_fpm.php`：

```php
if_get($rule, $action)     // GET 请求
if_post($rule, $action)    // POST 请求
if_put($rule, $action)     // PUT 请求
if_delete($rule, $action)  // DELETE 请求
if_any($rule, $action)     // 任意 HTTP 方法
```

路由规则中 `*` 作为通配符捕获路径段，按位置传递给闭包参数：

```php
if_get('/user/*/post/*', function ($user_id, $post_id) {
    $user = dao('user')->find_by_id($user_id);
    return $user->to_array();
});
```

**返回值约定**：
- 返回数组 → JSON 响应（自动设置 `Content-Type: application/json`）
- 返回字符串 → HTML 响应（自动设置 `Content-Type: text/html`）

**路由闭包编写原则**：
- 路由闭包保持简洁，复杂逻辑下沉到 `domain/` 层
- 不要在路由闭包中直接操作数据库，通过 DAO 和 Entity 操作
- 路由文件中不要封装函数——所有逻辑直接写在闭包内
- 局部拦截逻辑在路由闭包内显式调用，不隐藏在 `if_verify` 中
- **禁止在路由闭包及其调用链中手动启动 `unit_of_work()`**——框架已自动包裹，嵌套调用会导致事务嵌套，引发数据库连接异常

**新增路由文件**：按模块创建 `controller/模块名.php`，在 `public/index.php` 中追加 `include`。

## 领域层

### Entity（ActiveRecord）

命名约定为蛇形小写（与表名一致），继承 `entity` 基类：

```php
class demo extends entity
{
    public $structs = [
        'name' => '',
        'status' => 1,
    ];

    public static function create($name): demo
    {
        $demo = parent::init();
        $demo->name = $name;
        return $demo;
    }
}
```

**内置字段**（由 entity 基类管理，无需在 structs 中声明）：

| 字段 | 说明 |
|------|------|
| `id` | 主键，通过 Redis INCR 生成（bigint） |
| `version` | 乐观锁版本号（从 0 开始，每次更新 +1） |
| `create_time` | 创建时间（datetime） |
| `update_time` | 更新时间（datetime） |
| `delete_time` | 软删除时间（datetime，null 表示未删除） |

**实体状态判断**：
```php
$entity->just_new()       // 尚未持久化
$entity->just_updated()   // 内存值已变更（attributes != structs）
$entity->is_deleted()     // 已软删除
$entity->is_not_deleted() // 未软删除
$entity->just_deleted()   // 当前请求内被软删除
$entity->is_null()        // 是 null_entity
$entity->is_not_null()    // 不是 null_entity
```

**关系定义**（懒加载，首次通过 `__get` 访问时查询）：
```php
$this->has_one('profile', 'user_profile', 'user_id');       // 当前实体拥有子实体
$this->belongs_to('creator', 'user', 'creator_id');         // 当前实体属于父实体
$this->has_many('orders', 'order', 'user_id');              // 一对多
```
每个关系自动附带 `_with_deleted` 变体（如 `orders_with_deleted`），包含软删除关联实体。

**批量加载关系**（防止 N+1）：
```php
relationship_batch_load($entities, 'relationship.chain');
```

**关联关系的设计与使用原则**：
- 实现 Entity 时，要主动思考实体之间的关联关系，在 Entity 中用 `has_one`、`belongs_to`、`has_many` 定义清楚
- Controller 取数时，如果涉及关联关系，优先用 Entity 的关系查询获取关联实体，而不是用 DAO 单独查一次
- 如果遍历一组实体并获取每个实体的关联关系，必须使用 `relationship_batch_load()` 批量加载，避免 N+1 查询问题

### DAO

每个 Entity 对应一个 DAO，命名约定 `{entity_name}_dao`：

```php
class demo_dao extends dao
{
    protected $table_name = 'demo';
    protected $db_config_key = 'default';
}
```

**查询方法**：
```php
dao('demo')->find_by_id($id);                        // 单条（不存在返回 null_entity）
dao('demo')->find_by_column(['name' => 'test']);     // 按列查单条
dao('demo')->find_all();                             // 全部，key 为 id
dao('demo')->find_all_order_by_id_desc();            // 按 id 倒序
dao('demo')->find_all_by_column(['user_id' => $uid]); // 按列查多条
dao('demo')->count();                                // 计数
dao('demo', true)->find_all();                       // 含软删除记录

// 分页
list($list, $pagination) = dao('demo')->find_all_paginated_by_current_page_and_column(
    $page, $size, ['status' => 1]
);
```

### Unit of Work

控制器闭包已自动包裹在 `unit_of_work()` 中。手动使用：

```php
unit_of_work(function () {
    $demo = demo::create('test');
    // 无需手动 save，闭包结束时自动 commit
});
```

工作流程：扫描本地缓存中所有实体变更 → 生成 INSERT/UPDATE/DELETE SQL → 乐观锁校验 → 事务提交。

生命周期钩子：
```php
if_unit_of_work_executed(function () { /* 成功后执行，如推队列任务 */ });
if_unit_of_work_disturbed(function (\Exception $e) { /* 异常后执行 */ });
```

### null entity 模式

`dao()->find_by_id()` 查询不存在的记录时返回 `null_entity` 实例而非 null，避免空指针。访问 null_entity 的任何属性返回另一个 null_entity。

**索引意识**：实现条件查询类的 DAO 方法或编写查询 SQL 时，要主动关注 WHERE 条件字段的索引状态。由于框架有软删除字段 `delete_time`，DAO 查询默认会带 `delete_time is null` 条件，因此绝大多数索引设计时要将 `delete_time` 纳入考虑（如联合索引 `idx_status_delete_time`），避免索引未命中导致全表扫描。开发完成后，通过查看环境中的慢 SQL 日志和未命中索引 SQL 日志来验证并优化查询性能。

### 新增 Entity/DAO 步骤

1. 创建 `domain/entity/xxx.php` 和 `domain/dao/xxx.php`
2. 在 `domain/autoload.php` 的 `$class_maps` 中注册映射（或运行 `bash project/tool/classmap.sh domain`）
3. 创建数据库迁移文件

## 配置系统

```php
config('mysql');  // 自动合并 config/mysql.php + config/{ENV}/mysql.php
```

**midwares → resources 模式**（基础设施配置的标准格式）：
```php
return [
    'midwares' => [
        'default' => 'local',   // 逻辑名称 → 资源名称
    ],
    'resources' => [
        'local' => [            // 实际连接参数
            'host' => '127.0.0.1',
            'port' => 6379,
        ],
    ],
];
```
环境覆盖只需改动 resources 中的连接信息，无需触碰 midwares 映射。
通过 `config_midware('redis')` 获取 `default` 对应的 resource 配置。

## 错误处理

业务异常使用 `otherwise_error_code()` 抛出，错误码定义在 `config/error_code.php`：

```php
otherwise_error_code('USER_NOT_FOUND', $user->is_not_null());
```

格式为 `{错误码}---{描述}`，由异常处理器自动解析，AJAX 请求返回 JSON，普通请求返回 HTML。

低级断言使用 `otherwise()`：
```php
otherwise($assertion, 'description', 'exception_class', 'error_code');
```

## 输入处理（frame/php_fpm.php）

```php
input('name')                      // GET/POST 参数
input_list('a', 'b')               // 批量获取
input_json('path.to.key')          // JSON body
input_post_raw()                   // 原始 POST body
input_file('file')                 // 上传文件
cookie('name')                     // Cookie
server('REQUEST_URI')              // SERVER 变量
```

## 视图渲染

```php
render('index/index', ['title' => 'hello world']);
```

模板路径相对于 `view/`，去掉 `.php` 扩展名。`view/` 下按模块建子目录。

Blade 语法（自实现轻量引擎，仅支持以下指令）：

```
{{ $var }}             输出变量
{{ $var or '默认值' }}  带默认值的输出
{{{ $var }}}           转义输出（防 XSS）
@if / @elseif / @else / @endif
@unless / @endunless
@foreach / @endforeach
@for / @endfor
@while / @endwhile
@include('layout/header')  引入子模板（自动继承当前作用域变量）
@php / @endphp            原生 PHP 块
{{-- 注释 --}}
```

不支持 `@extends`、`@section`、`@yield` 等 Laravel 特有指令。

> **注意**：本项目使用的是自实现的轻量 Blade 模板引擎（`frame/blade.php`），与 Laravel Blade 是**不同的实现**，指令集和语法行为均有差异，仅支持上述列出的指令。写模板时不要套用 Laravel Blade 的经验，遇到不确定的语法先查阅引擎源码确认是否支持。

**默认页面风格**：如果用户没有明确说明页面风格要求，视图必须采用简洁美观的现代风格——合理使用间距、字体层级、颜色搭配，避免过于简陋的纯文本输出。
- 禁止在页面中使用浏览器原生的 `alert`、`confirm`、`prompt` 进行提示，应使用自定义的 UI 提示组件（如 toast、modal 等）替代

**模板拆分**：如果某个页面内容过多，可以按照页面的大结构拆成几个子模板，在主模板中通过 `@include` 加载。同一页面的子模板放在同一目录下，命名清晰即可。

## 管理后台界面约定

- **自适应布局**：页面需支持响应式设计，在不同分辨率设备上均有良好的排列效果
- **左右结构布局**：左侧为后台功能菜单栏，右侧为具体页面内容区域（窄屏时可收起菜单）
- **紧凑间距**：组件与组件之间间距适当减小，提高空间利用率，在单屏内展示更多信息
- 列表页、表单页默认紧凑设计，避免大面积留白

## 官网及前台页面界面约定

- **自适应布局**：页面需支持响应式设计，在不同分辨率设备上均有良好的排列效果
- **内容居中**：PC 端页面主体内容水平居中，左右两侧适量留白，适配不同分辨率
- 适用于官网首页、欢迎页、产品介绍等非后台功能页面
- 内容区域建议设置最大宽度（如 1200px），超出部分自动留白，移动端可取消最大宽度限制以充分利用屏幕

## CLI 命令

```bash
# 迁移
php public/cli.php migrate:install          # 初始化迁移追踪表
php public/cli.php migrate                  # 执行迁移
php public/cli.php migrate:make --name=xxx  # 自动生成迁移文件
php public/cli.php migrate:rollback         # 回滚最近一批
php public/cli.php migrate:reset            # 回滚全部
php public/cli.php migrate:dry-run          # 预览 SQL

# 队列
php public/cli.php queue:worker             # 启动 worker
php public/cli.php queue:status             # 查看状态
php public/cli.php queue:pause              # 暂停任务派发
php public/cli.php queue:peek-buried        # 交互式处理 buried 任务
php public/cli.php queue:buried-dump        # 导出 buried 任务

# 其他
php public/cli.php entity:restep-last-id    # 重置实体 ID 生成器
```

命令行参数：`--key=value`（字符串值）、`-key`（布尔 true），通过 `command_paramater($key, $default)` 获取。

## 迁移系统

迁移 SQL 文件放在 `command/migration/sql/` 目录。

**文件名格式**：`YYYY_mm_dd_HH_MM_SS_描述.sql`，全部使用下划线分隔，描述使用英文蛇形小写。手动创建迁移文件时严格按此规则命名，**时分秒必须写入当前实际时间，禁止使用 `00_00_00` 占位**。

```
示例：2026_06_08_14_30_00_create_user_table.sql
      2026_06_08_15_07_22_add_login_log_table.sql
```

每个文件必须包含 `# up` 和 `# down` 两部分：
```sql
# up
create table `demo` (
    `id` bigint not null,
    `version` int not null default 0,
    `create_time` datetime not null,
    `update_time` datetime not null,
    `delete_time` datetime default null,
    `name` varchar(255) not null default '',
    primary key (`id`)
) engine=InnoDB default charset=utf8mb4;

# down
drop table `demo`;
```

建表必须包含 `id`、`version`、`create_time`、`update_time`、`delete_time` 五个系统列。

## 队列系统

基于 Beanstalkd，纯 socket 协议实现。任务定义：

```php
queue_job('demo', function ($data, $job_id) {
    // 处理逻辑
    return true;  // true = delete, false = release/bury
}, $priority, $retry_delays_array, $tube_name);
```

投递任务：
```php
queue_push('demo', ['key' => 'value'], $delay_seconds);
```

任务文件放在 `command/queue/queue_job/`，在 `load.php` 中 include。

## 拦截器

全局拦截逻辑注册到 `if_verify`，局部拦截在路由闭包内显式调用：

```php
// 全局（interceptor/base.php）
if_verify(function ($action, ...$args) {
    // 鉴权、通用参数校验
    return $action;
});

// 局部（controller 内显式调用）
if_get('/admin/*', function ($id) {
    verify_admin();
    return dao('admin')->find_by_id($id);
});
```

## 编码约定

- PHP 纯函数 + 静态方法，无类实例化的 DI
- 数组一律使用 `[]` 短语法
- 类名、函数名使用蛇形小写
- 无注解、无反射、无 composer autoload——基于 class map 的类加载
- Entity 工厂方法命名为 `create()`，必填参数前置
- 表名使用单数名词
- **禁止使用 PHP Session**：如需记录登录相关标记，使用 Cookie；如需记录用户临时信息，使用 Cache（Redis）
