## file index（frame/ 目录核心文件）

这个目录下的文件是框架文件，很核心很重要，大模型只可以读取、不可以修改。

### base_function.php — 基础工具函数库

数组操作：`array_get`（点号分隔路径）、`array_set`、`array_exists`、`array_forget`、`array_build`、`array_indexed`、`array_list`、`array_transfer`

字符串操作：`str_tail_cut`、`str_head_cut`、`str_middle_cut`、`starts_with`、`ends_with`、`str_finish`、`str_begin`

配置系统：`config_dir`（注册配置目录）、`config`（按文件名加载并缓存配置，支持环境覆盖）、`config_midware`（从配置中解析中间件资源，结构为 `midwares -> resources`）、`config_preload`、`env`、`is_env`

HTTP 请求工具：`http`（cURL 封装，支持 retry/timeout/callback；调用方未显式带时自动透传 `traceparent` / `X-Request-Id`）、`http_json`、`http_xml`

日期时间：`datetime`（默认 `Y-m-d H:i:s.v`，精确到毫秒；要秒级显式传第二参）、`datetime_diff`

其他：`instance`（单例工厂）、`value`、`dd`（var_dump + die）、`trace`、`json`、`not_empty`/`not_null`/`all_empty`/`all_null`/`all_not_empty`/`all_not_null`/`has_empty`/`has_null`、`is_url`、`unparse_url`、`url_transfer`、`option_define`/`has_option`（位运算选项）、`closure_id`

### orm_entity.php — ORM 实体与 DAO

**entity 抽象类**：
- 内置字段：`id`、`version`、`create_time`、`update_time`、`delete_time`
- `structs`（数据库原始值）与 `attributes`（当前值）分离，通过 `just_updated()` 判断脏数据
- 实现 `JsonSerializable`、`Serializable`
- `__get`：访问器自动调用 `get_{property}()` 方法或延迟加载关联关系
- `__set`：调用 `prepare_set_{property}()` 预处理 + struct_validators 校验（支持 enum 和 reg/function 验证器）
- 关系定义：`has_one`、`belongs_to`、`has_many`（每个关系自动附加 `_with_deleted` 变体）
- 软删除：`delete()`、`restore()`（提交时生成 UPDATE 清空 `delete_time`；撤销本请求内未提交的删除则无 SQL）、`force_delete()`，通过 `delete_time` 字段实现

**null_entity**：空对象模式，id=0，所有属性访问返回自身/null，避免 null 判断

**relationship_ref 体系**：`has_one`、`belongs_to`、`has_many` 三个关系类，支持单个加载（`load`）、批量加载（`batch_load`）、更新外键关联（`update`）

**dao 基类**：
- 命名约定：`{EntityName}_dao`，通过 `dao()` 函数获取实例
- `db_config_key` 默认 `entity`（实体读写走 `config/mysql.php` 的 `entity` midware），跨库的 DAO 覆盖它
- `with_deleted` 控制是否包含软删除记录
- 查询方法：`find`、`find_by_column`、`find_by_foreign_key`、`find_all_by_foreign_keys`、`find_all`、`find_all_paginated_by_current_page_and_column` 等，find/find_by_xxx 方法获取的是单个实体，find_all/find_all_by_xxx 方法获取的是数组，数组 key 是对象 id，value 是 dao 对应的实体对象
- 例外：`find_all_paginated_by_current_page_and_column` / `find_all_paginated_by_current_page_and_condition` 返回的是 `['list' => 实体数组, 'pagination' => [...] ]` 关联数组，不是实体数组本身，也不是 list() 可解构的索引数组
- 软删除过滤：`find_by_column` / `find_all_by_column` 在 `$columns` 中自动补 `delete_time is null`；两个分页方法的 count 侧（`count_by_condition` → `with_deleted_where_sql_and()`，在条件前注入）与 list 侧（`find_all_by_condition` + `with_deleted_and_sql()`，在条件后注入）口径一致，注入前先用括号固定调用方条件的边界，含 `or` 的条件不会绕过过滤；`with_deleted` 为真时两侧都不过滤
- 软删除条件注入入口：`with_deleted_and_sql()`（接在已有 where 之后）/ `with_deleted_where_sql()`（无其他 where 时）/ `with_deleted_where_sql_and()`（后面还要接条件时），DAO 子类拼自定义 SQL 统一用这三个方法，禁止手写 `delete_time is null`
- SQL dump：`dump_insert_sql`、`dump_update_sql`、`dump_delete_sql`（供 UnitOfWork 使用）
- 行转实体时自动剥离系统字段到对象属性，剩余字段存入 `structs`

**本地缓存**：`local_cache_get`/`local_cache_set`/`local_cache_delete`/`local_cache_flush_all`，按 `{entity_type}_{id}` 键缓存

**辅助函数**：`input_entity`（从请求中获取实体）、`relationship_batch_load`（链式批量加载关联，如 `a.b.c`）

### orm_unitofwork.php — 工作单元与 ID 生成

**unit_of_work**：
- 执行闭包期间追踪所有本地缓存中的实体变更
- 根据实体状态（`just_new`/`just_updated`/`just_deleted`/`just_restored`/`just_force_deleted`）生成相应 SQL
- 多 SQL 时自动包装事务
- 写库走 `entity` midware（`unit_of_work_db_config_key()`，默认与 dao 的 `db_config_key` 对齐；key 不匹配的实体不在本单元提交）
- 乐观锁：update 使用 `version = :old_version` 条件，受影响行数 !== 1 则抛异常
- 支持 `if_unit_of_work_executed` 和 `if_unit_of_work_disturbed` 回调

**generate_id**：基于 Redis `INCR` 的分布式 ID 生成器，内存中批量取号减少 Redis 调用

### database_mysql.php — MySQL 数据库层

- PDO 连接池（按 DSN+用户名+密码 识别），支持 TCP 端口和 Unix Socket 两种连接方式
- 读写分离：`read`/`write`/`schema` 三种连接类型，配置中可指定不同主机
- `db_force_type_write`：事务中强制走写库
- 核心函数：`db_query`、`db_query_first`、`db_query_column`、`db_query_value`、`db_write`、`db_insert`（返回 lastInsertId）、`db_update`、`db_delete`、`db_structure`
- `db_transaction`：自动 begin/commit/rollback，事务期间强制走写库
- `_mysql_sql_binds`：支持数组值自动展开为 IN 子句；返回前统一前置 `trace_sql_comment()`（`/* trace_id=… span=… */ `），所有 DML 在服务端日志里可按 trace 关联（`db_structure` 的 DDL 不经此处、不注入）
- Simple 系列：`db_simple_insert`、`db_simple_multi_insert`、`db_simple_update`、`db_simple_multi_update`（CASE WHEN 批量更新）、`db_simple_delete`、`db_simple_query`、`db_simple_query_first`、`db_simple_query_column`、`db_simple_query_indexed`、`db_simple_query_value`
- `db_simple_where_sql`：从关联数组生成 WHERE 子句，支持 =/in/is null/is not null/not in
- `db_close` 清理所有连接

### cache_redis.php — Redis 缓存层

- Redis 连接池，支持 TCP/Socket 连接、auth 认证、database 切换、自定义 options
- 基础操作：`cache_get`、`cache_multi_get`、`cache_set`（含过期）、`cache_add`（nx）、`cache_replace`（xx）、`cache_delete`、`cache_multi_delete`、`cache_compare_delete`（值相等才删）、`cache_compare_set`（值等于预期才替换，可带过期）——两个 compare 都是 Lua 原子比较；eval 传参不走序列化，比较值按连接 serializer 编码后比对
- 计数器：`cache_increment`、`cache_decrement`（可设过期）
- Hash：`cache_hmset`、`cache_hmget`
- List：`cache_lpush`、`cache_blpop`（阻塞弹出）
- Bitmap：`cache_setbit`、`cache_getbit`、`cache_bitcount`、`cache_bitop`、`cache_bitpos`
- 其他：`cache_keys`、`cache_rename`、`cache_close`
- `_redis_cache_closure`（唯一取连接入口）在回调前按当前 trace 发 `CLIENT SETNAME trace:{trace_id 前 24 位}`——与上次相同则不发（不增加常态往返），无 trace 上下文时不设置；slowlog 里能按 client name 把慢命令归属到请求

### lock_cache.php — 分布式锁（基于 Redis）

- `singly_run($key, $expire_second, closure $closure, ?closure $fail_closure = null)` — 互斥执行：并发调用只有一个能进入闭包执行，执行完主动释放；其余调用方不等待，直接返回 `fail_closure` 的结果（缺省 null）
- `serially_run($key, $expire_second, $wait_second, closure $closure, ?closure $fail_closure = null)` — 排队串行执行：并发调用按到达顺序排队，前一个闭包执行完把锁交接给阻塞最久的下一个，认领到交接才执行闭包；`wait_second` 秒内等不到交接则返回 `fail_closure` 的结果（排队超时的唯一出口），不执行闭包
- **排队保序**：交接是「锁值从持有方 token 原子换成交接标记（`cache_compare_set`）→ 唤醒一个等待方 → 它认领标记后才执行」。交接期间锁键一直存在，新调用方抢不到、只能排队，所以正常路径不会被插队
- 锁的值有三态：持有方 token / 交接标记（等待认领，短 TTL `LOCK_CACHE_HANDOFF_EXPIRE`）/ 键不存在（空闲）。只有「不存在」时新调用方才能直接执行——锁到期、持有方崩溃后的恢复属于这种
- 排队超时的调用方不写任何键、不做清理，退出不影响后面排队的调用方；等待期间只阻塞在 `serially_run_wake_` 的 BLPOP 上
- 两个函数互斥都靠同一套锁：`cache_add`（SET NX EX）原子抢锁，交接与释放都按 token 校验（`cache_compare_set` / `cache_compare_delete`）。锁 key 前缀 `singly_run_` / `serially_run_lock_`；`serially_run` 另有只用于唤醒的短 TTL 信号键 `serially_run_wake_`（LPUSH/BLPOP，信号丢失只让等待方退化成等超时，不影响互斥）。cache 调用统一走 redis 的 `lock` midware（`config/redis.php` 的 `midwares -> resources`），与其他缓存 key 隔离
- **锁只在创建 / 认领时带 TTL、不续期**：持有方抛异常靠 `finally` 交接，被 kill / 进程消失靠 `expire_second` 到期自动解锁，抢锁失败的调用方不会给它续期。`expire_second` 必须大于闭包的最长执行时间，否则闭包还没跑完锁就到期了，会出现并发执行
- 交接与释放都会校验持有方身份（值不是自己的 token 就不动）：闭包跑超 `expire_second`、锁已被下一个调用方拿到时，先来的调用方不会动到对方的锁
- 异常路径退化成先到先得、不再保证顺序：持锁方崩溃（已排队的等待方按 `wait_second` 超时失败，`expire_second` 到期后新调用方可进入）、认领方崩溃或交接中断（交接标记到期即自愈：`LOCK_CACHE_HANDOFF_EXPIRE` 秒内锁自然空出，新调用方即可接手）
- 等待中的调用方会一直占着执行线程（FPM worker）直到拿到锁或超时，容量规划按 `wait_second` × 并发等待数 估算
- `expire_second` 与 `wait_second` 必须大于 0（0 分别是「锁永不过期」与「永久阻塞」），传入 0 直接抛 `LOCK_CACHE_EXPIRE` / `LOCK_CACHE_WAIT`

### clickhouse.php — ClickHouse 分析库

- 走 ClickHouse **HTTP 接口**（默认 8123 端口），用框架自带的 `http()` 收发，无长连接、无连接池；认证走 `X-ClickHouse-User` / `X-ClickHouse-Key` 请求头
- 绑定值不做字符串拼接：绑定走 `param_*` 查询串 + SQL 里的 `{name:Type}` 占位符，由 ClickHouse 服务端负责转义；标量原样交给 `http_build_query`，PHP 数组（Array / Map）会编码成 ClickHouse 字面量 `['a','b']` / `{'k':'v'}`，元素内的引号、反斜杠、换行按 ClickHouse 规则转义
- 查询输出格式走 URL 参数 `default_format=JSONEachRow`，**不在 SQL 末尾拼 ` format JSONEachRow`**——拼接方式会被 SQL 末尾的 `--` 行注释一起注释掉，查询退化成默认 TSV 后仍被当 JSON 解析，静默返回坏数据；`ch_query` 收到 `X-ClickHouse-Format` 响应头发现实际格式不是 JSONEachRow 时直接抛异常
- 查询：`ch_query`（返回关联数组列表）、`ch_query_first`（SQL 未带 limit 时自动追加 `limit 1`，未找到返回 null）、`ch_query_column`、`ch_query_value`（未命中返回 null）
- 写入：`ch_write`（建表 / INSERT / mutation）、`ch_insert_rows`（批量写入，数据按行 JSON 编码作请求体，SQL 走 `query` 参数）
- 其他：`ch_ping`（健康检查，连接或认证失败返回 false）
- **写入返回值不可当成功判据**：返回值取自 `X-ClickHouse-Summary` 响应头的 `written_rows`，DDL 与 mutation 恒为 0，服务端开启 `async_insert` 时 insert 也可能报 0
- 非 200 响应一律抛异常，异常信息带上原始错误文本；异常只带基础地址，查询串里的 `param_*` 绑定值不进日志
- 不做请求重试：ClickHouse 的写请求重试可能造成重复写入
- 连接配置由 `_clickhouse_config` 解析，缺 `host` / `port` 或 `config_key` 写错时抛 `CLICKHOUSE_CONFIG`；`config_midware` 也会在 `midwares` / `resources` 缺项时直接报出缺失的那一层
- **精度安全默认**：配置 `settings` 里默认打开 `output_format_json_quote_64bit_integers` 与 `output_format_json_quote_decimals`，64 位整数与 Decimal 以字符串返回——JSON 里它们会退化成 double，超过 2^53 的值静默丢精度；要数字类型在 `config/clickhouse.php` 的 `settings` 里显式设为 0 覆盖
- 批量写入的每一行**键必须一致**：缺键的列 ClickHouse 会静默填默认值（DateTime64 变 1970-01-01），多余的键静默丢弃；单次请求只发一批数据，大批量应按 chunk 分批调用
- 写入超 `2^53` 的浮点整数会被拦下抛 `CLICKHOUSE_INT_OVERFLOW`（PHP 里它已经是 float，精度在进入框架前就丢了），要写这么大的整数请以字符串传入

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
- `route($rule)`：将 URL 路径与规则匹配（`*` 为通配符，替换为 `([^/]+?)` 并整体 `^...$` 锚定，故只匹配单个路径段、至少一个字符），返回 `[matched, args]`
- 路由是「按注册顺序匹配、**首个命中即执行并 `exit`**」，无条件遍历全部规则；因此静态路由必须注册在同位置的通配路由之前，否则被通配规则吞掉
- `if_any`/`if_get`/`if_post`/`if_put`/`if_delete`：HTTP 方法路由
- `if_not_found` / `not_found`：404 处理
- `matched_rule`：获取当前匹配的路由规则
- `if_verify`：路由验证拦截器（在所有路由匹配后、action 执行前调用；闭包的返回值即响应体；只允许注册一次，重复注册抛 `IF_VERIFY_ALREADY_REGISTERED`）
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

- `log_exception($ex)`：记录异常到 exception 日志（channel 为 `exception`，附异常类、`file:line`、堆栈）
- `log_notice($message)`：记录通知到 notice 日志（channel 为 `notice`）
- `log_module($module, $message)`：记录模块日志（channel 为模块名）
- 输出 **JSON Lines**（一行一个 JSON 对象，字段顺序固定）：`@timestamp`（UTC ISO8601 毫秒）、`level`、`channel`、`message`、`trace_id` / `span_id` / `parent_span_id`（无上下文为 null）、`service`（取 `config('log')['service']`）、`env`、`host`（gethostname）
- 编码用 `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE`；**不对内容做截断**（截断属于采集/存储层，源头截断不可逆）；写入仍是 `error_log()` 到配置路径

### trace.php — 全链路 trace 上下文

- 静态容器存 `trace_id`（32 位 hex）、`span_id`（16 位 hex）、`parent_span_id`，纯函数无类
- `trace_init($trace_id = null, $parent_span_id = null)`：校验 `/^[0-9a-f]{32}$/i`，合法则**原样采用**（不做大小写转换）、不合法则 `bin2hex(random_bytes(16))` 生成小写，并生成新 span_id
- `trace_begin_request()`：HTTP 入口用，按 `traceparent`（提取正则与 nginx map 逐字对齐）→ `X-Request-Id`（严格 32hex）→ 自生成的优先级取 trace_id，并 `header('X-Request-Id: ...')` 回写响应头
- `trace_id()` / `trace_span_id()` / `trace_parent_span_id()` / `trace_all()`（数组，供队列 payload）/ `trace_reset()`
- `trace_sql_comment()`：有上下文时返回 `/* trace_id=… span=… */ ` 前缀（内容全是 hex 与固定分隔符，无注入面），无上下文返回空串
- `trace_http_headers()`：出站请求头 `traceparent: 00-…-01` + `X-Request-Id`；无上下文返回空数组

### queue_beanstalk.php — Beanstalkd 队列

**连接层**：基于 fsockopen 的纯 socket 通信，实现 Beanstalkd 协议

**生产者**：`queue_push($job_name, $data, $delay)` — 序列化 job_name + data + 当前 `trace_all()`，PUT 到指定 tube

**消费者**：`queue_watch($tube_key, $memory_limit)` — 无限循环 reserve + 执行 job closure，返回 true 则 delete，返回 false 按 retry 配置处理（release 或 bury）
- 每个 job 执行前从 payload 恢复 trace 上下文（`trace_init(payload 的 trace_id / span_id)`，job 的 parent span = 投递方 span；老 payload 无 trace 字段则新起），执行完 `trace_reset()`
- 支持 SIGTERM 信号优雅退出
- 内存限制保护
- `queue_finish_action`：每次循环结束时的回调

**任务定义**：`queue_job($job_name, $closure, $priority, $retry, $tube_key)` — 注册 job 的闭包和参数

队列各函数不暴露 `$config_key` 参数，固定使用 `queue` midware（常量 `QUEUE_BEANSTALK_MIDWARE_KEY`，对应 `config/beanstalk.php` 的 midwares）；tube 参数统一是 `$tube_key`（默认 `default`），由 `queue_tube()` 按 `config/queue.php` 的 `tubes` 映射解析成真实 tube 名，未映射的 key 直接报错——各环境覆盖映射即可换真实 tube，业务代码不改

**其他**：`queue_status`、`queue_pause`、`queue_tube`（tube_key → 真实 tube）、`queue_job_touch`（延长 job TTR）

### queue_kafka.php — Kafka 队列

与 queue_beanstalk.php 并列的另一套实现（**函数同名，二选一加载**，由 bootstrap.php 决定 include 哪个；本仓库在用 beanstalk 版）。基于 php-rdkafka 扩展（运行环境需装 rdkafka），固定使用 `queue` midware（常量 `QUEUE_KAFKA_MIDWARE_KEY`，对应 `config/kafka.php`）

**与 beanstalk 版的口径差异**（按 Kafka 语义裁剪，不做兼容层）
- 取消：`$priority`、`$delay`（投递延时）、TTR（`queue_job_touch`）、`queue_pause`、bury 体系
- 新增：消费组（`group.id` ＝ 配置前缀 + topic_key，同组多 worker 自动分摊分区）、消息 key（第三个参数，决定分区、同 key 保序）、offset 查询（`queue_status` 出 lag）与重置（`queue_reset_offset` 回溯重放）、死信 topic（重试超限落 `<topic><后缀>`，`queue_raw_push` 投递整包）

**生产**：`queue_push($job_name, $data, $key)` — payload 用 **JSON**（信封 `{job_name, data, trace}`，便于与外部系统互通）；produce 是异步的，`_kafka_produce` 会 `poll` + `flush` 等投递回执，失败一律抛 `QUEUE_PUSH_FAILED---…`（不静默丢）

**消费**：`queue_watch($topic_key, $memory_limit, $group)` — 订阅后逐条消费，任务返回 true 提交 offset；返回 false / 抛异常则按 `$retry[已失败次数]` 秒在进程内退避重试，用尽后投递死信 topic 再提交（毒消息不卡分区）；解不出来的 payload 与未注册的任务名同样落死信
- 帧结构照搬 beanstalk 版：SIGTERM 优雅退出（退出前 `close()` 离组）、内存上限保护、每轮 `queue_finish_action_trigger()`（但**不清 consumer**——消费组会籍要跨轮保持）
- 订阅前先校验 topic 存在（不存在的 topic 会返回 err 元数据，框架直接报 `QUEUE_TOPIC_NOT_FOUND`），避免名字写错时静默空转
- 心跳由 librdkafka 后台线程发；单条消息的处理时长上限是 `max.poll.interval.ms`（config/queue.php 的 consumer 段可调），超时会被踢出消费组

**任务定义**：`queue_job($job_name, $closure, $retry, $topic_key)` — 闭包签名 `($data, $meta)`，`$meta` 含 topic / partition / offset / key / timestamp

**其他**：`queue_topic`（topic_key → 真实 topic）、`queue_dead_letter_topic`、`queue_status($topic_key, $group)`、`queue_reset_offset($topic_key, $offset, $partition, $group)`、`queue_raw_push($topic_key, $payload, $key)`、`queue_close`（替代 `beanstalk_close`）

### sse.php — SSE 流式服务

流式响应服务（Server-Sent Events）：单次 HTTP 请求，服务端分片返回 `text/event-stream`，流结束关闭连接。**运行在 PHP-FPM 上**（`public/sse.php` 由 nginx `location ^~ /sse/` 的 `SCRIPT_FILENAME` 指到，每请求执行一次），不走独立进程、无 supervisor。框架负责同步迭代 generator、关闭输出缓冲逐块 `flush()`，无需事件循环。

**公共 API**：
- `sse_route($path, closure $closure)` — 流式路由，命中当前请求路径即分发执行（不做注册与遍历）。闭包签名 `($params)`：
  - 返回 **Generator**：每个 yield 发一个 SSE data 事件，流式主用法；同步迭代，每次 yield 立即 `flush()`
  - **`yield true`（严格 bool）＝ 流结束**：不发送数据、其后的代码不再执行（不再推进 generator）
  - 调用 `sse_send()`：显式逐条推送；`sse_send(true)` 同为流结束
  - 返回普通值：一次性发送后关闭；返回 `true` 则直接关闭（流结束）
  - 未匹配路径 → HTTP 404
- `sse_send($data, $event = null)` — 发一个 SSE 事件（`event: xxx\ndata: {json}\n\n`，数据用 `json()` 编码，中文不转义）
- `sse_close()` — 结束当前流（置流结束标记，之后 `sse_send` 不再输出；脚本结束由 FPM 关闭连接）
- 客户端信息用 `$_SERVER`（如 `REMOTE_ADDR`）

**内部实现**：
- `_sse_closed()` — 请求内流结束标记（static）
- `_sse_request_path()` — 从 `REQUEST_URI` 取路径并剥 `/sse` 前缀，与 nginx 分流后的路由对应
- `_sse_params()` — 合并 `$_GET` + JSON POST body（`php://input`，body 非 JSON 时并入 `$_POST`）
- `_sse_stream_env()` — 设置流式环境：`set_time_limit(0)`、`Content-Type: text/event-stream` + `Cache-Control: no-cache` + `X-Accel-Buffering: no` + CORS、关闭输出缓冲（`output_buffering`/`zlib.output_compression`/`ob_end_clean`/`implicit_flush`）、`display_errors off`（防 notice 污染流）
- `_sse_iterate_generator($generator)` — 同步迭代：`current()` → 非 `true`/非 null 则 `sse_send` → `connection_aborted()` 检查 → `next()`；`yield true` 即停止；generator 异常冒泡到上层 catch
- `_sse_dispatch($closure, $params)` — 分发：设置流式环境，经 `if_verify` 包装执行路由闭包并按返回类型处理流式结果；`catch` → 走 `if_has_exception` 兜底（默认 `log_exception` + `sse_send(['error'=>...])` + `sse_close()`）
- `if_verify()` / `if_has_exception()` / `if_not_found()` — 拦截器/异常/404 注册容器（与 frame/php_fpm.php 同名独立实现，两类模块不会同时加载）
- `not_found()` — 触发 404：执行 `if_not_found()` 注册的处理并结束请求

**行为要点**：
- 每个并发 SSE 连接占用一个 FPM worker，并发由 `pm.max_children` 决定；FPM pool 需 `request_terminate_timeout=0`（默认），否则长流被杀
- 客户端断开即停止迭代（`connection_aborted()` + FPM 默认 `ignore_user_abort=false`），worker 释放
- **无自动保活 `: ping`**：同步迭代下 generator 阻塞期间框架无法运行，长时间无数据依赖 nginx `fastcgi_read_timeout`（部署配置 3600s），handler 应在等待外部事件时主动 yield / `sse_send` 保活
- 异常 `log_exception` 后向当前流发 `error` 事件再关闭

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
| 全局鉴权拦截 | 写进入口（`public/index.php` / `public/api.php`）已注册的 `if_verify` 闭包（`if (! verify_global()) { return null; }`），校验函数放 `interceptor/`——`if_verify` 只允许注册一次，重复注册报错；返回 `null` 不输出响应体，`return $action` 会致命错误 |

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
| 分页查询 | `dao('entity_name')->find_all_paginated_by_current_page_and_column($page, $size, $column)` — 返回关联数组 `['list' => [...], 'pagination' => ['page_size', 'current_page', 'count', 'pages']]`，用 `$res['list']` 取实体数组，**不要用 `list()` 解构**；结果自动排除已软删除记录（count 与 list 两侧口径一致，与 dao 默认过滤口径相同） |
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
| 恢复软删除 | `$entity->restore();` — 提交时生成 UPDATE 清空 `delete_time`；已删除记录需 `dao('x', true)` 才能取到 |
| 物理删除 | `$entity->force_delete();` |
| 批量操作事务 | `unit_of_work(function () { /* 多实体操作 */ });` |

控制器闭包已自动包裹在 unit_of_work 中，不需要手动调用。

### ClickHouse（分析库）

分析型数据走 `ch_*` 函数，**不走 ORM**：没有 entity / dao / unit_of_work / 事务，纯数组进出，也不参与 `if_verify` 里那句 `unit_of_work` 的提交。

| 我要做 | 调用 |
|--------|------|
| 健康检查 | `ch_ping()` — 连接或认证失败返回 false，不抛异常 |
| 查多行 | `ch_query($sql, $binds)` — 返回关联数组列表 |
| 查单行 | `ch_query_first($sql, $binds)` — SQL 未带 limit 时自动补 `limit 1`，未命中返回 null |
| 取单列 / 取单值 | `ch_query_column('event_type', $sql, $binds)` / `ch_query_value('c', $sql, $binds)` |
| 执行非查询语句 | `ch_write($sql, $binds)` — 建表 / INSERT / mutation；返回值不能当成功判据 |
| 批量写入 | `ch_insert_rows('event', $rows)` — `$rows` 是字段一致的关联数组列表 |
| 传参 | SQL 里写 `{uid:UInt64}` 占位符，第二个参数传 `['uid' => 1]`；数组、Map 直接传 PHP 数组 |
| 换连接配置 | 各函数的最后一个参数 `$config_key`，取值是 `config/clickhouse.php` 的 `midwares` 键（默认 `default`） |
| 查询逻辑放哪 | `domain/knowledge/`，路由闭包只做入参校验与响应组装 |

要点：`UInt64` 与 `Decimal` 默认以字符串返回（精度安全默认）；批量写入每行键必须一致、大批量按 chunk 分批；`update` / `delete` 是异步 mutation，要立刻读到结果得带 `settings mutations_sync = 2`；细节见下方 `clickhouse.php` 条目。

### 返回响应

**按入口区分**：API 路由（`controller_api/`，以 `/api/` 开头）由 `public/api.php` 处理，任意返回值统一包装成 `{code, msg, data}` JSON；页面路由（`controller/`）由 `public/index.php` 处理，只接受字符串（HTML），返回非字符串会被判为编程错误。

| 我要做 | 调用 |
|--------|------|
| 返回 JSON（API 路由） | `return $entity;` 或 `return ['key' => 'val'];` — 返回数组/Entity 自动 JSON 序列化 |
| 返回 HTML（页面路由） | `return render('模块/页面', ['title' => 'xxx']);` |
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
| 取当前 trace 上下文 | `trace_id()` / `trace_all()` — 日志/SQL/队列已自动带上，一般不需要手取 |

### 队列

| 我要做 | 调用 |
|--------|------|
| 投递任务（beanstalk） | `queue_push('job_name', ['key' => 'val'], $delay_seconds)` |
| 定义任务处理器（beanstalk） | `queue_job('job_name', function ($data, $job_id) { return true; }, ...)` — 任务文件放在 command/queue/queue_job/ |
| 投递任务（kafka） | `queue_push('job_name', ['key' => 'val'], $partition_key)` — 第三个参数是消息 key（决定分区，同 key 保序），没有延时投递 |
| 定义任务处理器（kafka） | `queue_job('job_name', function ($data, $meta) { return true; }, $retry_delays, $topic_key)` — 失败按 retry 重试、超限落死信 topic |
| 让不同环境用不同真实 tube / topic | 业务侧写 tube_key / topic_key（如 `'default'`）；真实名称在 `config/queue.php` 的 `tubes` / `topics` 里映射，按环境覆盖 |
| 换队列实现 | 两套函数同名，改 bootstrap.php 的 include（queue_beanstalk.php ↔ queue_kafka.php），命令文件与任务定义同步换 |

### 锁（并发控制）

| 我要做 | 调用 |
|--------|------|
| 互斥执行，拿不到就跳过 | `singly_run('key', 10, function () { ... })` — 其余调用方不等待，返回 fail_closure 的结果 |
| 排队串行执行 | `serially_run('key', 60, 5, function () { ... })` — 并发调用按到达顺序排队执行（保序），`wait_second` 内等不到交接则超时走 fail_closure，退出不影响队列 |

### SSE 流式

| 我要做 | 调用 |
|--------|------|
| 匹配即分发路由 | `sse_route('/path', function ($params) { ... })` — 命中当前请求路径即分发，返回 Generator 时每个 yield 发一个 SSE 事件 |
| 显式推送事件 | `sse_send(['key' => 'val'], $event_name)` — 作用于当前连接 |
| 结束流（约定） | `yield true` / `sse_send(true)` — 严格 bool，立即关闭连接，不发数据 |
| 关闭流 | `sse_close()` |
| 分发流式结果 | `_sse_dispatch($closure, $params)` — 设置流式环境、经 `if_verify` 包装执行路由闭包并按返回类型处理流式结果 |

业务文件放根目录 `controller_sse/`，在 `public/sse.php` 中直接 include。闭包内避免单次迭代长阻塞，长时间无数据应主动 yield / `sse_send` 保活。

### 工具函数

| 我要做 | 调用 |
|--------|------|
| 获取当前日期时间 | `datetime()` — 默认精确到毫秒（`Y-m-d H:i:s.v`），要秒级显式传第二参如 `'Y-m-d H:i:s'` |
| 计算时间差 | `datetime_diff($time1, $time2)` |
| HTTP 请求 | `http('http://url', $params, $method, $callback, $timeout)` |
| HTTP JSON 请求 | `http_json('http://url', $data, $timeout)` |
| 数组取嵌套值 | `array_get($array, 'key.sub.key', $default)` — 支持点号分隔路径 |
| 调试输出 | `dd($var)` — var_dump + die |
