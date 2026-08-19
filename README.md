# php-vibe-coding-frame

单层 MVC PHP 框架，专为 PHP-FPM 快速开发设计。无 DI 容器、无注解、无 YAML 路由配置、无 Composer autoload——路由即闭包，控制器即函数，写完即跑。

## 为什么这个框架对 Vibe Coding 友好

Vibe Coding 的核心是 **从想法到代码的路径最短**——没有仪式感，没有概念包袱，所见即所得。这个框架在每个关键路径上都为此设计：

- **零启动成本**：一行 `include` 注册路由，一条命令生成 Entity/DAO/迁移文件，Docker 一键启动开发环境。不需要理解 ServiceProvider、Facade、Container binding。
- **意图即结果**：闭包返回数组就是 JSON，返回字符串就是 HTML。不需要 `response()->json()`、不需要 `view()` 包装。AI 或人——看一眼代码就知道输出是什么。
- **没有魔法**：没有 `__call` 代理、没有 Facade 静态代理、没有注解解析。Ctrl+Click 能追到源码，grep 能找到所有引用。AI 工具的静态分析不会被框架的元编程绕晕。
- **一个文件就是一个功能**：路由、参数校验、业务调用全在一个闭包内，不需要在 Controller → Service → Repository 的调用链中跳转。修改一个功能，改一个文件就够。
- **自动持久化**：`unit_of_work()` 自动包裹所有路由闭包，创建 Entity、赋属性即可，闭包结束自动写入数据库。不用记 `save()`、`flush()` 调用时机。心智负担趋近于零。

简单说：**框架退后一步，让意图站到前面。** 这套设计在 AI 辅助编程场景下的优势尤为明显——没有因为框架复杂性导致 AI 幻觉的土壤，prompt 到代码的转换率极高。

### 传统可维护性封装，在 Vibe Coding 下可以剪枝

过去十年，软件工程积累了大量「为了人类可维护性」而生的模式：接口隔离、依赖反转、分层架构、DI 容器。这些模式的核心假设是——**人类需要将大型项目切成小块，分而治之，跨长周期维护**。

但在 AI 辅助编程下，这个前提发生了根本变化：

- **AI 可以在单一文件中理解和修改全部逻辑**，不需要通过接口契约来隔离认知负荷。Controller → Service → Repository 的分层，对人来说是导航地图，对 AI 来说是额外的上下文噪音。
- **代码的生成和修改成本急剧下降**，一个功能文件随时可以重写。过去为了「以后可能要换数据库」「以后可能要拆微服务」而预留的接口抽象和扩展点，在 vibe coding 中变成了多余的耦合对象。
- **分层越多，AI 幻觉注入点越多**。每多一层间接调用，AI 就多一次猜错路径的机会。扁平直接的代码——函数调用就是那个函数，类就是那个类——AI 的理解准确率最高。

这个框架在这些堆栈上主动做了减法：

| 传统封装 | 这里的替代 | 为什么够用 |
|---------|-----------|----------|
| 路由文件 + Controller 类分离 | 路由闭包一个文件写完 | AI 一次性看到请求进来的全部逻辑，不需要来回翻文件 |
| Service 层 | 复杂逻辑下沉 `domain/knowledge/` | 只有一个下沉方向，没有横向分层 |
| DI 容器 / 自动注入 | 显式 `include` + 函数调用 | AI 不会在容器绑定的隐式依赖上迷路 |
| Repository 接口 + 实现 | DAO 直接继承，调用方式统一 | 只有一个数据库，不需要接口隔离 |
| DTO / FormRequest | `input()` / `input_json()` 直接取值 | 入参一次性消费，不需要类型化的中间对象 |
| 事件 + 监听器 | `if_unit_of_work_executed()` 钩子 | 只有成功/失败两个时刻，不需要事件总线 |

**这些不是阉割，是剪枝。** 当 AI 能处理系统复杂度时，很多过去必须有的中间层变成了纯粹的摩擦成本。这个框架的目标就是把摩擦降到最低——让你（和 AI）写的每一个字符都直接作用于业务意图。

## 设计理念

| 原则 | 说明 |
|------|------|
| **显式优于隐式** | 依赖靠 `include`，配置靠 PHP 数组，路由靠闭包。没有"魔法"，所有行为可见。 |
| **约定优于配置** | Entity 类名即表名、DAO 命名即 `{entity}_dao`、返回数组即 JSON。默认行为覆盖 90% 场景。 |
| **简单优于灵活** | 单一 MVC 层，无中间件栈、无服务容器、无 Provider。够用就好，不设可扩展点。 |
| **纯函数 + 静态方法** | 无类实例化的 DI，无反射，无注解扫描。所有能力通过函数和静态方法暴露。 |

## 架构概览

```
nginx → public/index.php → bootstrap.php（加载 frame/ 核心库）
  → 注册错误/异常处理
  → if_verify（unit_of_work 自动包裹）
  → 加载 controller/ 路由文件
  → 路由匹配 → 执行闭包 → 响应

cli  → public/cli.php → bootstrap.php → 加载 command/ → 命令匹配
```

核心行为：
- **路由闭包返回数组 → JSON 响应**，返回字符串 → HTML 响应
- **所有控制器闭包默认包裹在 `unit_of_work()` 中**，实体变更自动提交，无需手动 `save()`
- **`$_SERVER['ENV']`** 控制环境（development/production），配置自动按环境合并覆盖

## 10 秒看到 Hello World

```bash
git clone <repo-url> my-project && cd my-project
sh project/tool/start_development_server.sh   # 需要 Docker + 输入 sudo 密码
```

打开浏览器访问 `http://localhost`，看到 "hello world" 页面。

> 映射了 80 和 3306 端口，若端口冲突可修改 `project/tool/start_development_server.sh`。

---

## 快速上手

### 1. 新建一个路由

在 `controller/` 下创建文件，例如 `controller/user.php`：

```php
<?php

if_get('/user/*', function ($user_id) {
    return dao('user')->find($user_id); // 返回实体 → JSON
});

if_post('/user', function () {
    $name = input('name');
    return user::create($name); // Entity 实现了 JsonSerializable → JSON
});

if_get('/user/list', function () {
    $users = dao('user')->find_all();
    return render('user/list', ['users' => $users]);  // render() 返回 HTML 字符串
});
```

然后在 `public/index.php` 中加入一行 `include`：

```php
include CONTROLLER_DIR.'/base.php';
include CONTROLLER_DIR.'/user.php';  // 新增
```

### 2. 新建一个 Entity + DAO

**Entity**（`domain/entity/user.php`）：

```php
<?php

class user extends entity
{
    public $structs = [
        'name'   => '',
    ];

    public static function create(string $name): user
    {
        $user = parent::init();
        $user->name = $name;
        return $user;
    }
}
```

**DAO**（`domain/dao/user.php`）：

```php
<?php

class user_dao extends dao
{
    protected $table_name = 'user';
    protected $db_config_key = 'default';
}
```

**注册类映射**——运行一条命令即可：

```bash
sh project/tool/classmap.sh domain
```

### 3. 创建数据库表

```bash
php public/cli.php migrate:make --name=create_user_table
```

自动生成的 SQL 文件为 `command/migration/sql/` 下带时间戳前缀的文件（如 `2026_08_19_10_30_00_create_user_table.sql`），内容：

```sql
# up
create table `user` (
    `id` bigint(20) not null,
    `version` int(11) not null default 0,
    `create_time` datetime not null,
    `update_time` datetime not null,
    `delete_time` datetime default null,
    `name` varchar(255) not null default '',
    primary key (`id`)
) engine=InnoDB default charset=utf8mb4;

# down
drop table `user`;
```

执行迁移：

```bash
php public/cli.php migrate
```

### 4. 渲染页面

创建模板 `view/user/list.php`：

```html
@include('layout/header')

<h1>用户列表</h1>

@foreach ($users as $user)
    <div>
        <span>{{ $user->name }}</span>
    </div>
@endforeach

@include('layout/footer')
```

---

## 核心概念

### 路由

路由函数定义在 `frame/php_fpm.php`，闭包直接写在 `controller/` 文件中：

```php
if_get($rule, $action)      // GET
if_post($rule, $action)     // POST
if_put($rule, $action)      // PUT
if_delete($rule, $action)   // DELETE
if_any($rule, $action)      // 任意方法
```

`*` 作为通配符捕获路径段，按位置传递给闭包参数：

```php
if_get('/post/*/comment/*', function ($post_id, $comment_id) {
    // GET /post/123/comment/456 → $post_id=123, $comment_id=456
});
```

**返回值约定**：
- 数组 → JSON（自动 `Content-Type: application/json`）
- 字符串 → HTML（自动 `Content-Type: text/html`）

**获取输入**：

```php
$name   = input('name');              // GET/POST 参数
$json   = input_json('path.to.key');  // JSON body
$raw    = input_post_raw();           // 原始 POST body
$file   = input_file('avatar');       // 上传文件
$cookie = cookie('token');            // Cookie
```

### Entity（ActiveRecord）

每个数据库表对应一个 entity 类，继承 `entity` 基类。**五个系统字段**由基类自动管理，无需在 `structs` 中声明：

| 字段 | 说明 |
|------|------|
| `id` | 主键，通过 Redis INCR 生成 |
| `version` | 乐观锁版本号，每次更新 +1 |
| `create_time` | 创建时间 |
| `update_time` | 更新时间 |
| `delete_time` | 软删除时间（null = 未删除） |

```php
class order extends entity
{
    public $structs = [
        'user_id' => 0,
        'amount'  => 0,
        'status'  => 'pending',
    ];

    public static function create(int $user_id, float $amount): order
    {
        $order = parent::init();
        $order->user_id = $user_id;
        $order->amount = $amount;
        return $order;
    }
}
```

**状态判断**：

```php
$entity->just_new()        // 尚未持久化
$entity->just_updated()    // 内存值已变更
$entity->is_deleted()      // 已软删除
$entity->is_null()         // 是 null_entity（查询无结果）
```

**关系定义**（在 `__construct()` 中声明，懒加载）：

```php
$this->has_one('profile', 'user_profile', 'user_id');      // 当前实体拥有一个子实体
$this->belongs_to('creator', 'user', 'creator_id');        // 当前实体属于一个父实体
$this->has_many('orders', 'order', 'user_id');             // 一对多

// 访问
$user->profile;               // 首次访问时查询
$user->orders_with_deleted;   // 含软删除的关联实体

// 批量加载，防止 N+1
relationship_batch_load($users, 'profile.orders');
```

**软删除与硬删除**：

```php
$entity->delete();        // 软删除（设 delete_time）
$entity->restore();       // 恢复
$entity->force_delete();  // 硬删除（执行 DELETE FROM）
```

### DAO

每个 Entity 对应一个 DAO：

```php
class order_dao extends dao
{
    protected $table_name = 'order';
    protected $db_config_key = 'default';
}
```

通过 `dao()` 函数获取实例（无需实例化）：

```php
// 单条查询（不存在返回 null_entity）
$order = dao('order')->find($id);

// 按列查询
$order = dao('order')->find_by_column(['status' => 'pending']);

// 全量查询，返回数组 key 为实体 id
$orders = dao('order')->find_all();
$orders = dao('order')->find_all_order_by_id_desc();

// 外键查询
$orders = dao('order')->find_all_by_foreign_key('user_id', $user_id);

// 分页
list($list, $pagination) = dao('order')->find_all_paginated_by_current_page_and_column(
    $page, $size, ['status' => 'paid']
);

// 含软删除记录
$orders = dao('order', true)->find_all();
```

### Unit of Work（工作单元）

控制器的所有路由闭包**已自动包裹**在 `unit_of_work()` 中，创建 Entity、修改属性后无需手动调用 `save()`——闭包结束时自动持久化。

```php
if_post('/order', function () {
    $order = order::create(input('user_id'), input('amount'));
    // 无需 $order->save()，闭包结束自动 INSERT
    return $order;
});
```

**工作流程**：扫描本地缓存中的实体变更 → 生成 INSERT/UPDATE/DELETE SQL → 乐观锁校验（`WHERE version = :old_version`）→ 事务提交。

**手动使用**（如 CLI 脚本）：

```php
unit_of_work(function () {
    $demo = demo::create('test');
});
```

**生命周期钩子**（常用于成功后推队列任务）：

```php
if_unit_of_work_executed(function () {
    queue_push('send_notification', ['user_id' => $user->id]);
});

if_unit_of_work_disturbed(function (\Exception $e) {
    log_exception($e);
});
```

### 视图（Blade）

自实现轻量 Blade 引擎，支持以下指令：

```
{{ $var }}              输出变量
{{ $var or '默认值' }}   带默认值的输出
{{{ $var }}}            转义输出（防 XSS）

@if / @elseif / @else / @endif
@unless / @endunless
@foreach / @endforeach
@for / @endfor
@while / @endwhile

@include('layout/header')   引入子模板（自动继承变量）
@php / @endphp              原生 PHP 块
{{-- 注释 --}}
```

> 不支持 `@extends`、`@section`、`@yield` 等 Laravel 指令。使用 `@include('layout/header')` 代替布局继承。

`render()` 渲染模板并返回 HTML 字符串：

```php
return render('order/detail', [
    'order' => $order,
    'items' => $items,
]);
```

模板路径相对于 `view/`，不带 `.php` 扩展名。

### 配置

配置即 PHP 数组，通过 `config('文件名')` 加载。支持按环境覆盖：

```
config/
├── mysql.php              # 基础配置
├── redis.php
├── development/           # ENV=development 时覆盖
│   └── mysql.php
└── production/            # ENV=production 时覆盖（默认）
    └── mysql.php
```

**midwares → resources 模式**（基础设施配置的标准写法）：

```php
return [
    'midwares' => [
        'default' => 'local',   // 逻辑名 → 资源名
    ],
    'resources' => [
        'local' => [            // 实际连接参数
            'host' => '127.0.0.1',
            'port' => 6379,
        ],
    ],
];
```

环境覆盖只需改 `resources` 中的连接信息，`midwares` 映射不动。通过 `config_midware('redis')` 获取 `default` 对应的配置。

### 错误处理

```php
// 业务异常（错误码定义在 config/error_code.php）
otherwise_error_code('USER_NOT_FOUND', $user->is_not_null());

// 低级断言
otherwise($assertion, '描述信息');
```

异常由框架自动处理：AJAX 请求返回 JSON `{code, msg, data}`，普通请求返回纯文本。

### CLI 命令

```bash
# 迁移
php public/cli.php migrate              # 执行迁移
php public/cli.php migrate:make --name=xxx  # 自动生成迁移文件
php public/cli.php migrate:rollback     # 回滚最近一批
php public/cli.php migrate:reset        # 回滚全部
php public/cli.php migrate:dry-run      # 预览 SQL
php public/cli.php migrate:install      # 初始化迁移追踪表

# 队列
php public/cli.php queue:worker         # 启动 worker
php public/cli.php queue:status         # 查看状态
php public/cli.php queue:pause          # 暂停派发
php public/cli.php queue:peek-buried    # 交互式处理 buried 任务

# 实体
php public/cli.php entity:restep-last-id  # 重置 ID 生成器
```

自定义命令：

```php
command('demo:hello', '输出问候语', function () {
    $name = command_paramater('name', 'world');
    echo "Hello, $name!\n";
});
```

### 队列任务

基于 Beanstalkd，纯 socket 协议实现。

**定义任务**（`command/queue/queue_job/`）：

```php
queue_job('send_sms', function ($data, $job_id) {
    send_sms($data['phone'], $data['message']);
    return true;  // true = delete, false = release/bury
}, 10, [1, 1, 1], 'default');
//  ↑ 优先级  ↑ 重试延迟(秒)  ↑ tube
```

**投递任务**：

```php
queue_push('send_sms', ['phone' => '138...', 'message' => 'hello'], $delay_seconds = 0);
```

### 拦截器

```php
// 全局拦截（interceptor/base.php）——如鉴权
if_verify(function ($action, $args) {
    $user = get_current_user();
    if ($user->is_null()) {
        redirect('/login');
    }
    return $action;
});

// 局部拦截（controller 内显式调用）——推荐
if_get('/admin/*', function ($id) {
    verify_admin();
    return dao('user')->find($id);
});
```

---

## 目录结构

```
.
├── bootstrap.php            # 框架通用加载
├── public/                  # Web 根目录（nginx root）
│   ├── index.php            # HTTP 入口
│   ├── cli.php              # CLI 入口
│   └── assets/              # 静态资源（nginx 直接返回）
├── frame/                   # 框架核心库（ORM、DB、Cache、Queue、Blade、日志）
├── controller/              # HTTP 路由定义（闭包，按模块拆分）
├── domain/                  # 领域层
│   ├── entity/              # ActiveRecord 实体
│   ├── dao/                 # 数据访问对象
│   ├── knowledge/           # 业务知识（复杂逻辑封装）
│   ├── autoload.php         # 类自动加载映射
│   └── load.php             # 领域层入口
├── config/                  # PHP 配置数组 + ENV 环境覆盖
├── command/                 # CLI 命令
│   ├── migration/           # 数据库迁移
│   │   ├── migrate.php
│   │   └── sql/             # 迁移 SQL 文件
│   └── queue/               # 队列
│       ├── queue.php
│       └── queue_job/       # 任务定义
├── view/                    # Blade 模板
│   ├── layout/              # 公共布局（header/footer）
│   └── blade/               # 编译缓存
├── interceptor/             # 拦截器（全局前置/后置逻辑）
├── util/                    # 工具类（外部能力封装：支付、短信、OSS）
└── project/                 # 部署配置（nginx、supervisor、docker）与工具脚本
    ├── config/
    │   ├── development/     # 开发环境 nginx/supervisor 配置
    │   └── production/      # 生产环境配置
    └── tool/
        ├── start_development_server.sh  # Docker 一键启动开发环境
        ├── classmap.sh                  # 生成类映射文件
        └── naming_project.sh            # 重命名项目
```

---

## 编码约定

- PHP 纯函数 + 静态方法，无类实例化的 DI
- 数组使用 `[]` 短语法
- 类名、函数名使用蛇形小写（snake_case）
- Entity 类名与表名一致（单数名词）
- DAO 类名为 `{entity_name}_dao`
- 工厂方法统一命名为 `create()`，必填参数前置
- 路由文件中不封装函数——逻辑写在闭包内，复杂逻辑下沉到 `domain/knowledge/`
- 无注解、无反射、无 Composer autoload——基于 class map 的类加载

---

## 与 Laravel 的关键差异

| | php-vibe-coding-frame | Laravel |
|------|------|------|
| 路由 | 闭包直接注册在 `controller/*.php` | `routes/web.php` 指向 Controller 类方法 |
| 控制器 | 闭包即控制器 | Controller 类 + 方法 |
| DI | 无，依赖显式 `include` | 服务容器自动注入 |
| ORM | 自定义 ActiveRecord + UoW | Eloquent |
| 配置 | PHP 数组 + 环境覆盖 | `.env` + `config/*.php` |
| 模板 | 自实现 Blade（10 个指令） | 完整 Blade + 组件系统 |
| 类加载 | class map（`spl_autoload_register`） | Composer PSR-4 |
| 启动方式 | Docker 一键启动 | `php artisan serve` / Sail |
| 适用场景 | 中小型项目、快速原型、API 服务 | 大型项目、全功能 Web 应用 |
| 启动开销 | 仅 include ~10 个核心文件 | 启动数百个类，注册数十个 ServiceProvider |
| 内存占用 | 低（无容器、无绑定、无注解缓存） | 较高（容器绑定、facade、事件监听器常驻） |
| 请求延迟 | 毫秒级，无需路由/配置缓存预热 | 生产环境必须靠路由缓存 + 配置缓存 + Octane 优化 |
| 类加载 | class map 直接 `include`，O(1) 查找 | Composer PSR-4，需扫描目录 / 转储 classmap |
| 部署形态 | PHP-FPM 原生友好，无需常驻进程 | FPM 下性能一般，生产常需 Octane/Swoole 加持 |
