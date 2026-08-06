# CLAUDE.md

## 目录定位

`public/` 是 Web 根目录，包含框架的四个入口文件，按请求类型分流：

| 入口 | 请求 | 响应 |
|------|------|------|
| `index.php` | `/` 普通请求 | 只出 HTML 页面 |
| `api.php` | `/api/*` | 只出 JSON |
| `cli.php` | CLI | 命令行 |
| `sse.php` | `/sse/*` | 流式 `text/event-stream` |

nginx/php-fpm 将请求路由到此目录；API 请求由 nginx 的 `location ^~ /api/` 分流到 `api.php`，SSE 流式请求由 `location ^~ /sse/` 分流到 `sse.php`。

## 入口文件

### index.php（页面入口）

请求生命周期：
```
nginx / → public/index.php → bootstrap.php → 注册错误/异常处理器
  → 注册 if_verify（unit_of_work 包裹） → 加载 controller/ → not_found()
```

关键行为：
- `if_verify` 将所有路由闭包包裹在 `unit_of_work()` 中，自动提交 Entity 变更并管理事务
- **页面入口只接受字符串返回值**：返回字符串 → HTML 响应；返回非字符串被判为编程错误抛 500，提示迁移到 `controller_api/`
- 异常处理一律渲染 HTML 错误页（`view/error/500.php`），404 一律渲染 HTML 404 页，不再区分 AJAX/普通请求
- 业务异常（`business_exception`）记录日志模块为 `business_exception`，其他异常记录完整堆栈
- 当前只加载 `controller/base.php`，新增页面路由文件需在此手动 `include`

### api.php（API 入口）

请求生命周期：
```
nginx /api/* → public/api.php → bootstrap.php → 注册错误/异常处理器
  → OPTIONS 预检 → 注册 if_verify（unit_of_work 包裹） → 加载 controller_api/ → not_found()
```

关键行为：
- **只服务 `/api/*`**：路由规则以 `/api/` 开头（如 `if_get('/api/user/*', ...)`），nginx 的 `location ^~ /api/` 分流到此
- `if_verify` 将路由闭包包裹在 `unit_of_work()` 中；**任意返回值统一包装成 `{code, msg, data}` JSON 响应**
- 异常处理一律返回 JSON 错误结构，404 一律返回 JSON，不再区分请求类型
- OPTIONS 预检返回 204 + CORS 头；所有响应带 `Access-Control-Allow-Origin: *`
- 不加载 `view_blade.php`，路由闭包内不可使用 `render()`
- 当前只加载 `controller_api/base.php`，新增 API 路由文件需在此手动 `include`

### cli.php（CLI 入口）

命令行入口，用于运行迁移、队列 worker 等后台任务。

```
cli.php → bootstrap.php → 加载 cli_command + view_blade
  → 注册命令（migrate、entity、queue） → command_not_found()
```

已注册的命令：
- `migration/migrate.php` — 数据库迁移
- `entity.php` — Entity 相关操作
- `queue/queue.php` — 队列 worker

### sse.php（SSE 服务入口）

**SSE 流式响应入口**（Server-Sent Events），**运行在 PHP-FPM 上**——nginx 将 `/sse/*` 通过 `location ^~ /sse/` 直接 fastcgi 到本文件（`SCRIPT_FILENAME=$document_root/sse.php`），每请求执行一次，每个流占用一个 FPM worker。无需独立进程、无需 supervisor。

```
nginx /sse/* → location ^~ /sse/ → fastcgi_pass php-fpm → public/sse.php（每请求一个流）
```

加载链：
```
public/sse.php → bootstrap.php → + frame/sse.php
  → 直接 include controller_sse/ 业务文件（controller_sse/echo.php 等，无聚合器）
  → _sse_handle_request()
```

关键行为：
- **业务逻辑在根目录 `controller_sse/`**：新增事件文件后在 `public/sse.php` 中追加一行 `include SSE_DIR.'/xxx.php';`（镜像 index.php 直接 include controller 的写法），`SSE_DIR` 常量在本入口定义
- 请求方法不区分 GET/POST：浏览器 `EventSource` 只支持 GET（query 传参）；POST 传 JSON body 配合 `fetch` 流式读取
- 流式约定：路由闭包返回 Generator，每个 yield 发一个 SSE data 事件（`echo` + `flush()`）；**`yield true`（严格 bool）＝ 流结束**，框架立即关闭流
- 框架已处理：`set_time_limit(0)`、关闭输出缓冲、`display_errors off`（防 notice 污染流）、客户端断开检测（`connection_aborted`）
- **无自动保活**：长时间无数据会触发 nginx `fastcgi_read_timeout`（部署配置 3600s），handler 应在等待时主动 yield / `sse_send`
- 部署：nginx 分流见 `project/config/{env}/nginx/*.conf` 的 `location ^~ /sse/`（`fastcgi_buffering off` + 关 gzip）；FPM pool 需 `request_terminate_timeout=0`（默认），并发能力由 `pm.max_children` 决定

## assets/ 目录

静态资源目录，存放项目生成的 CSS、JS 及素材文件，由 nginx 直接返回（不经过 PHP-FPM），缓存 30 天。

```
assets/
├── css/
├── js/
└── img/
```

Blade 模板中通过 `/assets/` 绝对路径引用。
