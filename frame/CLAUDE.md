## file index（frame/ 目录核心文件）

这个目录下的文件是框架文件，很核心很重要，大模型只可以读取、不可以修改。

### base_function.php — 基础工具函数库

数组操作：`array_get`（点号分隔路径）、`array_set`、`array_exists`、`array_forget`、`array_build`、`array_indexed`、`array_list`、`array_transfer`

字符串操作：`str_tail_cut`、`str_head_cut`、`str_middle_cut`、`starts_with`、`ends_with`、`str_finish`、`str_begin`

配置系统：`config_dir`（注册配置目录）、`config`（按文件名加载并缓存配置，支持环境覆盖）、`config_midware`（从配置中解析中间件资源，结构为 `midwares -> resources`）、`config_preload`、`env`、`is_env`

HTTP 请求工具：`http`（cURL 封装，支持 retry/timeout/callback）、`http_json`、`http_xml`

日期时间：`datetime`、`datetime_diff`

其他：`instance`（单例工厂）、`value`、`dd`（var_dump + die）、`trace`、`json`、`not_empty`/`not_null`/`all_empty`/`all_null`/`all_not_empty`/`all_not_null`/`has_empty`/`has_null`、`is_url`、`unparse_url`、`url_transfer`、`option_define`/`has_option`（位运算选项）、`closure_id`

### orm_entity.php — ORM 实体与 DAO

**entity 抽象类**：
- 内置字段：`id`、`version`、`create_time`、`update_time`、`delete_time`
- `structs`（数据库原始值）与 `attributes`（当前值）分离，通过 `just_updated()` 判断脏数据
- 实现 `JsonSerializable`、`Serializable`
- `__get`：访问器自动调用 `get_{property}()` 方法或延迟加载关联关系
- `__set`：调用 `prepare_set_{property}()` 预处理 + struct_validators 校验（支持 enum 和 reg/function 验证器）
- 关系定义：`has_one`、`belongs_to`、`has_many`（每个关系自动附加 `_with_deleted` 变体）
- 软删除：`delete()`、`restore()`、`force_delete()`，通过 `delete_time` 字段实现

**null_entity**：空对象模式，id=0，所有属性访问返回自身/null，避免 null 判断

**relationship_ref 体系**：`has_one`、`belongs_to`、`has_many` 三个关系类，支持单个加载（`load`）、批量加载（`batch_load`）、更新外键关联（`update`）

**dao 基类**：
- 命名约定：`{EntityName}_dao`，通过 `dao()` 函数获取实例
- `with_deleted` 控制是否包含软删除记录
- 查询方法：`find`、`find_by_column`、`find_by_foreign_key`、`find_all_by_foreign_keys`、`find_all`、`find_all_paginated_by_current_page_and_column` 等，find/find_by_xxx 方法获取的是单个实体，find_all/find_all_by_xxx 方法获取的是数组，数组 key 是对象 id，value 是 dao 对应的实体对象
- SQL dump：`dump_insert_sql`、`dump_update_sql`、`dump_delete_sql`（供 UnitOfWork 使用）
- 行转实体时自动剥离系统字段到对象属性，剩余字段存入 `structs`

**本地缓存**：`local_cache_get`/`local_cache_set`/`local_cache_delete`/`local_cache_flush_all`，按 `{entity_type}_{id}` 键缓存

**辅助函数**：`input_entity`（从请求中获取实体）、`relationship_batch_load`（链式批量加载关联，如 `a.b.c`）

### orm_unitofwork.php — 工作单元与 ID 生成

**unit_of_work**：
- 执行闭包期间追踪所有本地缓存中的实体变更
- 根据实体状态（`just_new`/`just_updated`/`just_deleted`/`just_force_deleted`）生成相应 SQL
- 多 SQL 时自动包装事务
- 乐观锁：update 使用 `version = :old_version` 条件，受影响行数 !== 1 则抛异常
- 支持 `if_unit_of_work_executed` 和 `if_unit_of_work_disturbed` 回调

**generate_id**：基于 Redis `INCR` 的分布式 ID 生成器，内存中批量取号减少 Redis 调用

### database_mysql.php — MySQL 数据库层

- PDO 连接池（按 DSN+用户名+密码 识别），支持 TCP 端口和 Unix Socket 两种连接方式
- 读写分离：`read`/`write`/`schema` 三种连接类型，配置中可指定不同主机
- `db_force_type_write`：事务中强制走写库
- 核心函数：`db_query`、`db_query_first`、`db_query_column`、`db_query_value`、`db_write`、`db_insert`（返回 lastInsertId）、`db_update`、`db_delete`、`db_structure`
- `db_transaction`：自动 begin/commit/rollback，事务期间强制走写库
- `_mysql_sql_binds`：支持数组值自动展开为 IN 子句
- Simple 系列：`db_simple_insert`、`db_simple_multi_insert`、`db_simple_update`、`db_simple_multi_update`（CASE WHEN 批量更新）、`db_simple_delete`、`db_simple_query`、`db_simple_query_first`、`db_simple_query_column`、`db_simple_query_indexed`、`db_simple_query_value`
- `db_simple_where_sql`：从关联数组生成 WHERE 子句，支持 =/in/is null/is not null/not in
- `db_close` 清理所有连接

### cache_redis.php — Redis 缓存层

- Redis 连接池，支持 TCP/Socket 连接、auth 认证、database 切换、自定义 options
- 基础操作：`cache_get`、`cache_multi_get`、`cache_set`（含过期）、`cache_add`（nx）、`cache_replace`（xx）、`cache_delete`、`cache_multi_delete`
- 计数器：`cache_increment`、`cache_decrement`（可设过期）
- Hash：`cache_hmset`、`cache_hmget`
- List：`cache_lpush`、`cache_blpop`（阻塞弹出）
- Bitmap：`cache_setbit`、`cache_getbit`、`cache_bitcount`、`cache_bitop`、`cache_bitpos`
- 其他：`cache_keys`、`cache_rename`、`cache_close`

### view_blade.php — Blade 模板引擎

- 自定义 stream wrapper（`blade://` schema）实现模板编译，支持缓存编译结果到 .php 文件
- `blade()`：编译 Blade 语法到 PHP，编译步骤链：includes → comments → escaped_echos → echos → openings → closings → else → unless → endunless → php_code
- 支持的指令：`@if`/`@elseif`/`@else`/`@endif`、`@unless`/`@endunless`、`@foreach`/`@endforeach`、`@for`/`@endfor`、`@while`/`@endwhile`、`@include`、`@php`/`@endphp`、`{{ }}`（echo）、`{{{ }}}`（escaped echo）、`{{-- --}}`（注释）
- `blade_eval()`：直接求值模板字符串
- `blade_view_compiler()`：编译视图文件，支持缓存编译结果，返回可 include 的 PHP 文件路径

### cli_command.php — CLI 命令行系统

- `_command_prepare_arguments`：解析 `-x`（布尔）和 `--key=value` 格式参数
- `command_paramater($key)`：读取命令行参数，不存在且有 default 则返回 default，否则报错退出
- `command($rule, $description, $action)`：注册并匹配命令
- `command_not_found`：收集所有注册的命令，在无匹配时展示帮助
- `if_command_not_found`：自定义无匹配命令时的行为
- `command_read`：带 readline 支持的交互式输入，支持选项菜单
- `command_read_bool`：y/n 确认输入
- `command_read_completions`：自定义 tab 补全

### php_fpm.php — HTTP 层（PHP-FPM/SAPI）

**路由**：
- `route($rule)`：将 URL 路径与规则匹配（`*` 为通配符），返回 `[matched, args]`
- `if_any`/`if_get`/`if_post`/`if_put`/`if_delete`：HTTP 方法路由
- `if_not_found` / `not_found`：404 处理
- `matched_rule`：获取当前匹配的路由规则
- `if_verify`：路由验证拦截器（在所有路由匹配后、action 执行前调用）
- `redirect` / `trigger_redirect`：301/302 重定向

**输入处理**：
- `input` / `input_safe`：读取 GET/POST 参数
- `input_list`：批量读取
- `input_json` / `input_json_list`：从 JSON raw body 读取
- `input_xml` / `input_xml_list`：从 XML raw body 读取
- `input_post_raw`：读取原始 POST body
- `input_file`：读取上传文件
- `cookie` / `cookie_safe`：读取 Cookie
- `server` / `server_safe`：读取 SERVER 变量

**视图渲染**：
- `view_path` / `view_compiler`：设置视图路径和编译器
- `render($view, $args)`：渲染视图并返回字符串
- `include_view($view, $args)`：直接 include 视图

**其他**：
- `is_https`、`is_ajax`、`uri`、`refer_uri`、`uri_info`、`ip`
- `cache_with_etag`：ETag 304 缓存
- `if_has_exception` / `http_ex_action` / `http_err_action` / `http_fatal_err_action`：异常/错误处理

### log.php — 日志模块

- `log_exception($ex)`：记录异常到 exception 日志
- `log_notice($message)`：记录通知到 notice 日志
- `log_module($module, $message)`：记录模块日志
- 所有日志带微秒精度时间戳前缀，通过 `error_log()` 写入配置指定的文件路径

### queue_beanstalk.php — Beanstalkd 队列

**连接层**：基于 fsockopen 的纯 socket 通信，实现 Beanstalkd 协议

**生产者**：`queue_push($job_name, $data, $delay)` — 序列化 job_name + data，PUT 到指定 tube

**消费者**：`queue_watch($tube, $config_key, $memory_limit)` — 无限循环 reserve + 执行 job closure，返回 true 则 delete，返回 false 按 retry 配置处理（release 或 bury）
- 支持 SIGTERM 信号优雅退出
- 内存限制保护
- `queue_finish_action`：每次循环结束时的回调

**任务定义**：`queue_job($job_name, $closure, $priority, $retry, $tube, $config_key)` — 注册 job 的闭包和参数

**其他**：`queue_status`、`queue_pause`、`queue_job_touch`（延长 job TTR）

### otherwise.php — 断言与异常

- `otherwise($assertion, $description, $exception_class, $exception_code)`：断言失败时抛异常，消息格式 `{code}---{description}`
- `business_exception`：业务异常类
- `otherwise_get_error_info` / `otherwise_get_error_message`：从异常消息中解析 code 和 message
- `otherwise_error_code($error_code, $assertion, $replace_contents)`：从 `config('error_code')` 中查找错误码对应的描述文案，支持内容替换

## code style

- 纯函数式 + 静态方法，不使用 DI 容器
- 配置通过 `config_midware` 的 `midwares -> resources` 间接引用模式
- ORM 使用 Active Record + UnitOfWork 模式，乐观锁基于 version 字段
- 软删除通过 delete_time 实现，dao 默认过滤已删除记录
- 错误消息使用 `---` 分隔错误码和描述文本，业务逻辑错误码用英文大写而非数字

## API 速查表（按场景查找）

每个场景只列首选函数，AI 生成代码时按此表决策，不需要记忆全部 API。

### 路由定义

| 我要做 | 调用 |
|--------|------|
| 注册 GET 路由 | `if_get('/path/*', function ($param) { ... })` |
| 注册 POST 路由 | `if_post('/path/*', function ($param) { ... })` |
| 注册任意方法路由 | `if_any('/path/*', function ($param) { ... })` |
| 404 处理 | `not_found(function () { ... })` |
| 全局鉴权拦截 | `if_verify(function ($action, ...$args) { return $action; })` |

路由闭包中 `*` 按位置对应闭包参数，如 `/user/*/post/*` → `function ($user_id, $post_id)`

### 读请求数据

| 我要做 | 调用 |
|--------|------|
| 读 GET/POST 参数 | `input('name', $default)` |
| 批量读参数 | `input_list('a', 'b')` |
| 读 JSON body 字段 | `input_json('path.to.key', $default)` |
| 读原始 POST body | `input_post_raw()` |
| 读上传文件 | `input_file('file', $default)` |
| 读 Cookie | `cookie('name', $default)` |
| 读 SERVER 变量 | `server('REQUEST_URI', $default)` |

### 查数据

| 我要做 | 调用 |
|--------|------|
| 按 ID 查单条 | `dao('entity_name')->find_by_id($id)` — 不存在返回 null_entity |
| 按列查单条 | `dao('entity_name')->find_by_column(['key' => 'val'])` |
| 查询全部 | `dao('entity_name')->find_all()` — 返回数组，key 为 id |
| 按列查多条 | `dao('entity_name')->find_all_by_column(['key' => 'val'])` |
| 分页查询 | `dao('entity_name')->find_all_paginated_by_current_page_and_column($page, $size, $column)` — 返回 `[$list, $pagination]` |
| 计数 | `dao('entity_name')->count()` |
| 含软删除记录 | `dao('entity_name', true)->find_all()` — 第二个参数 `true` 表示 with_deleted |
| 查不存在的记录 | 用 `$entity->is_null()` 判断，不要用 `=== null` |
| DAO 自定义复杂查询 | 在 DAO 类中新增 `public` 方法，返回单个实体用 `find_by_xxx`，返回实体数组用 `find_all_by_xxx` |

### 写数据

| 我要做 | 调用 |
|--------|------|
| 创建实体 | `$entity = EntityName::create($required_param); $entity->field = 'val';` — 在 unit_of_work 内操作，不需要手动 save |
| 修改实体 | `$entity->field = 'new_val';` — 在 unit_of_work 内修改 |
| 删除实体（软删除） | `$entity->delete();` — 设置 delete_time |
| 恢复软删除 | `$entity->restore();` |
| 物理删除 | `$entity->force_delete();` |
| 批量操作事务 | `unit_of_work(function () { /* 多实体操作 */ });` |

控制器闭包已自动包裹在 unit_of_work 中，不需要手动调用。

### 返回响应

| 我要做 | 调用 |
|--------|------|
| 返回 JSON | `return $entity;` 或 `return ['key' => 'val'];` — 返回数组/Entity 自动 JSON 序列化 |
| 返回 HTML | `return render('模块/页面', ['title' => 'xxx']);` |
| 重定向 | `redirect('/path');` — 在路由闭包末尾自动触发 Location header |

### 异常与校验

| 我要做 | 调用 |
|--------|------|
| 业务断言失败 | `otherwise($assertion, '描述', '异常类名', '错误码');` |
| 返回业务错误码 | `otherwise_error_code('USER_NOT_FOUND', $entity->is_not_null());` — 自动返回 code---message 格式 |
| 自定义异常处理 | `if_has_exception(function ($ex) { ... })` |

### 视图

| 我要做 | 调用 |
|--------|------|
| 渲染 Blade 模板 | `render('模块/模板名', $data)` — 模板路径相对于 view/，去掉 .php 扩展名 |
| 引入子模板 | `@include('layout/header')` — Blade 模板内使用 |

### 配置

| 我要做 | 调用 |
|--------|------|
| 读取配置 | `config('mysql')` — 自动合并开发/生产环境配置 |
| 读取中间件资源 | `config_midware('redis')` — 从 midwares → resources 解析 |

### 日志

| 我要做 | 调用 |
|--------|------|
| 记录异常 | `log_exception($ex)` |
| 记录通知 | `log_notice('消息')` |
| 记录模块日志 | `log_module('模块名', '消息')` |

### 队列

| 我要做 | 调用 |
|--------|------|
| 投递任务 | `queue_push('job_name', ['key' => 'val'], $delay_seconds)` |
| 定义任务处理器 | `queue_job('job_name', function ($data, $job_id) { return true; }, ...)` — 任务文件放在 command/queue/queue_job/ |

### 工具函数

| 我要做 | 调用 |
|--------|------|
| 获取当前日期时间 | `datetime()` |
| 计算时间差 | `datetime_diff($time1, $time2)` |
| HTTP 请求 | `http('http://url', $params, $method, $callback, $timeout)` |
| HTTP JSON 请求 | `http_json('http://url', $data, $timeout)` |
| 数组取嵌套值 | `array_get($array, 'key.sub.key', $default)` — 支持点号分隔路径 |
| 调试输出 | `dd($var)` — var_dump + die |
