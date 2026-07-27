# CLAUDE.md — domain/

领域层：entity（ActiveRecord）、DAO、知识库。

## 目录结构

```
domain/
  entity/           # 实体类（ActiveRecord，继承 entity 基类）
  dao/              # 数据访问对象（继承 dao 基类）
  knowledge/        # 知识库文件
  autoload.php      # 领域层类自动加载映射
  load.php          # 入口（被 bootstrap.php include）
```

## entity（ActiveRecord）

每个数据库表对应一个 entity 类，命名约定为蛇形小写（与表名一致）。

### 最小模板

```php
class demo extends entity
{
    public $structs = [
        'name' => '',
    ];

    public static function create($name): demo
    {
        $demo = parent::init();

        $demo->name = $name;

        return $demo;
    }
}
```

### 内置字段（entity 基类管理，无需在 structs 中声明）

`id` — 内存计数自增生成的主键（bigint）
`version` — 乐观锁版本号（从 0 开始，每次更新 +1）
`create_time` — 创建时间（datetime）
`update_time` — 更新时间（datetime）
`delete_time` — 软删除时间（datetime，null 表示未删除）

### 实体状态判断

```php
$entity->just_new()       // 尚未持久化（version === 0）
$entity->just_updated()   // 内存值已变更（attributes != structs）
$entity->is_deleted()     // 已软删除
$entity->is_not_deleted() // 未软删除
$entity->just_deleted()   // 当前请求内被软删除
$entity->is_null()        // 是 null_entity，往往代表没有 find 出来实体
$entity->is_not_null()        // 不是 null_entity，往往代表 find 出来了数据实体
```

### 工厂方法 create()

约定为每个 entity 编写静态 `create()` 工厂方法，必填字段作为参数：

```php
public static function create($name): demo
{
    $demo = parent::init();

    $demo->name = $name;

    return $demo;
}
```

调用 `parent::init()` 自动生成 id、设置 version=0、create_time/update_time 为当前时间。

### 软删除与硬删除

```php
$entity->delete();       // 软删除（设置 delete_time）
$entity->restore();      // 恢复软删除
$entity->force_delete(); // 标记为硬删除（下次 unit_of_work 提交时执行 DELETE FROM）
```

### JSON 序列化

`jsonSerialize()` 返回 `id, version, create_time, update_time, delete_time` + 所有属性值。控制器中直接 `return $entity` 即可输出 JSON。

## dao

每个 entity 对应一个 dao 类，命名约定：`{entity_name}_dao`。

### 最小模板

```php
class demo_dao extends dao
{
    protected $table_name = 'demo';
    protected $db_config_key = 'default';
}
```

`table_name` 与迁移创建的数据库表名一致，框架规范为单数名词而非复数名词。
`db_config_key` 对应 `config/mysql.php` 中的数据库连接 key。
dao 构造函数自动从类名推导 `$class_name`（去掉 `_dao` 后缀）。

### 查询方法

```php
// 单条查询（不存在返回 null_entity）
$entity = dao('demo')->find_by_id($id);

// 按列名查询
$entity = dao('demo')->find_by_column(['name' => 'test']);

// 多条查询，查询返回的是一个装有实体的数组，数组的 key 是对应实体的 id，方便后续通过 id 直接从数组中获得对应的实体
$entities = dao('demo')->find_all();
$entities = dao('demo')->find_all_order_by_id_desc();

// 按列名查询多条
$entities = dao('demo')->find_all_by_column(['user_id' => $user_id]);

// 分页
list($list, $pagination) = dao('demo')->find_all_paginated_by_current_page_and_column($page, $size, ['status' => 1]);

// 计数
$count = dao('demo')->count();

// 含已删除记录，dao 方法第二个参数是 with_deleted 参数
$entities = dao('demo', true)->find_all();
```

### 自定义查询方法

当查询逻辑较为复杂时，可以在对应实体的 DAO 中新增 `public` 查询方法，方法命名遵循以下约定：

- 返回**单个实体** → 以 `find_by_xxx` 命名
- 返回**实体数组** → 以 `find_all_by_xxx` 命名

```php
class demo_dao extends dao
{
    protected $table_name = 'demo';
    protected $db_config_key = 'default';

    // 返回单个实体
    public function find_by_name_and_status($name, $status): entity
    {
        return $this->find_by_column(['name' => $name, 'status' => $status]);
    }

    // 返回实体数组
    public function find_all_by_create_time_range($start, $end): array
    {
        return $this->find_all_by_column([
            ['create_time >= ?', $start],
            ['create_time <= ?', $end],
        ]);
    }
}
```

### 变量命名

`find_by_xxx` 返回单个实体，用**单数**变量名：

```php
$user = dao('user')->find_by_id($id);
$post = dao('post')->find_by_column(['slug' => $slug]);
```

同一作用域内存在多个同类型实体时，加**语义前缀**区分：

```php
$current_user = dao('user')->find_by_id($login_user_id);
```

`find_all_by_xxx` 返回实体数组，用**复数**变量名：

```php
$users = dao('user')->find_all_by_column(['status' => 1]);
$posts = dao('post')->find_all_by_column(['category_id' => $cid]);
```

分页方法的 `$list` 同理遵循复数语义：

```php
list($users, $pagination) = dao('user')->find_all_paginated_by_current_page_and_column($page, $size, ['status' => 1]);
```

## knowledge

对实体操作、复杂业务逻辑的封装位置，以纯函数形式实现，按模块拆分文件。

### 编写方式

```php
// domain/knowledge/demo.php

function do_something_with_demo(demo $demo, $param): array
{
    $demo->status = 1;
    $related = dao('other')->find_all_by_column(['demo_id' => $demo->id]);

    return ['demo' => $demo, 'related' => $related];
}
```

- 函数命名使用蛇形小写，动词在前
- 参数和返回值使用类型声明
- 函数内部可操作 entity、调用 dao、触发 unit_of_work

### 加载

新增 knowledge 文件后，在 `load.php` 中 `include`：

```php
include __DIR__.'/autoload.php';
include __DIR__.'/knowledge/demo.php';
```

> 控制器和路由闭包中可直接调用 knowledge 函数，无需 use 或 import。

## 实体关系

在 entity 构造函数 `public function __construct()` 中定义：

```php
// 一对一（当前实体拥有子实体）
$this->has_one('profile', 'user_profile', 'user_id');

// 反向一对一（当前实体属于父实体）
$this->belongs_to('creator', 'user', 'creator_id');

// 一对多
$this->has_many('orders', 'order', 'user_id');
```

关系是懒加载的，首次通过 `__get` 访问时查询并缓存。
每个关系自动生成 `_with_deleted` 变体（如 `orders_with_deleted`）以包含软删除关联实体。

### 参数自动推导规则

`belongs_to`、`has_one`、`has_many` 的第二、三参数在多数情况下可省略，框架按以下规则自动推导：

| 方法 | 省略 entity_name 时 | 省略 foreign_key 时 |
|------|---------------------|---------------------|
| `belongs_to($name, $entity, $fk)` | 取 `$name` | 取 `{$entity}_id` |
| `has_one($name, $entity, $fk)` | 取 `$name` | 取 `{$self_entity_name}_id` |
| `has_many($name, $entity, $fk)` | 取 `$name` | 取 `{$self_entity_name}_id` |

**注意**：当关系名是复数（如 `'modules'`）但实体/表名是单数（如 `'module'`）时，必须显式传入 entity_name 参数：

```php
// 正确：关系名 'modules' 与表名 'module' 不一致，需显式指定
$this->has_many('modules', 'module');

// 正确：关系名与实体名一致，可省略
$this->belongs_to('project');
```

### 优先使用关联关系查询

当父实体已加载时，优先通过 `$parent->children` 懒加载获取子实体，而非直接调用 DAO 按外键查询：

```php
// 优先
$endpoints = $project->endpoints;
$all_use_cases = $project->use_cases;

// 不推荐（仅在需要额外过滤条件时使用）
$endpoints = dao('endpoint')->find_all_by_column(['project_id' => $project_id]);
```

两条 SQL 完全等价，但关联关系写法更一致、更易维护，且天然跟随实体关系变化。

### 批量加载（防止后续遍历 $entities 时共产生 N+1 条 SQL）

```php
relationship_batch_load($entities, 'relationship.chain');
```

**链式加载**（需中间实体已定义 has_many），一轮调用加载整条链上的所有关联关系：
```php
// 加载 endpoints → modules → function_items 和 pages
relationship_batch_load($endpoints, 'modules.function_items');
relationship_batch_load($endpoints, 'modules.pages');

// 关联关系已全部就位，直接访问
foreach ($endpoints as $ep) {
    foreach ($ep->modules as $mod) {
        $functions = $mod->function_items;
        $pages = $mod->pages;
    }
}
```

**按需构建索引数组**（批量加载已处理数据查询，以下只是组织数据结构的遍历）：

```php
$endpoints = dao('endpoint')->find_all_by_column(['project_id' => $pid]);
$functions = relationship_batch_load($endpoints, 'modules.function_items');
$pages = relationship_batch_load($endpoints, 'modules.pages');
```

`relationship_batch_load` 返回值取决于关系类型：`has_many` 返回子实体数组（key=子实体id），`belongs_to` 返回父实体数组（key=父实体id）。链式调用时每轮返回的数组作为下一轮的输入，最终返回链末端实体数组。

## 自动加载（autoload.php）

新增 entity 或 dao 后，必须在 `autoload.php` 的 `$class_maps` 中注册映射：

```php
$class_maps = [
    'demo_dao' => 'dao/demo.php',
    'demo'     => 'entity/demo.php',
];
```

## Unit of Work 持久化机制

**所有持久化操作通过 `unit_of_work()` 完成。** 控制器的 `if_verify` 拦截器已自动包裹，无需手动调用。

### 工作原理

1. 执行闭包期间，所有实体变更（new/update/delete）记录在本地缓存中
2. 闭包结束后扫描缓存：
   - 新实体 → `INSERT`
   - 已修改实体 → `UPDATE ... WHERE id = :id AND version = :old_version`
   - 已软删除实体 → `UPDATE ... SET delete_time = ...`
   - 已硬删除实体 → `DELETE`
3. 乐观锁：若 UPDATE 影响 0 行（version 已变更），抛出异常
4. 事务提交：多语句时自动包裹事务

### 手动使用

```php
unit_of_work(function () {
    $demo = demo::create('test');
});
// 无需手动 save，闭包结束时自动 commit
```

### 生命周期钩子

```php
if_unit_of_work_executed(function () {
    // unit of work 成功后执行
});

if_unit_of_work_disturbed(function (\Exception $e) {
    // unit of work 异常后执行
});
```
通常是需要在 controller 代码中就对新创建或修改的数据对象要抛队列任务时使用，会在工作单元提交后才执行

## null entity 模式

`dao('demo')->find_by_id($id)` 查询不存在的记录时返回 `null_entity` 实例而非 null，避免空指针：

```php
$entity = dao('demo')->find_by_id($id);
if ($entity->is_null()) {
    // 记录不存在
}
// 访问 null_entity 的任何属性返回另一个 null_entity，不会报错
```

## 与迁移的对应关系

每个 entity 对应一张数据库表，需创建迁移文件（`command/migration/sql/` 目录下）。建表 SQL 约定：

- 必须含 `id`, `version`, `create_time`, `update_time`, `delete_time` 五个系统列
- 引擎使用 InnoDB，字符集 utf8mb4

运行迁移：

```bash
php public/cli.php migrate
```

## 编码约定

- entity 类名与表名一致，蛇形小写
- dao 类名 = `{entity_name}_dao`
- 工厂方法命名为 `create()`，必填参数前置
- 数组使用 `[]` 短语法
- 不在 entity 中编写 SQL —— 复杂查询通过 dao 的 `find_by_sql` / `find_by_condition` 等方法实现
