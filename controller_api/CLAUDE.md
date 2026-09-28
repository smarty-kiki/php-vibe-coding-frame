# controller_api/ CLAUDE.md

API 路由定义目录，由 `public/api.php` 入口加载。本目录只放**接口路由**（返回 JSON），页面路由放 `controller/`，流式路由放 `controller_sse/`。

## 路由规则约定

**路由规则必须以 `/api/` 开头**——nginx 靠 `location ^~ /api/` 把 `/api/*` 请求分流到 `public/api.php`，规则即真实 URL：

```php
if_get('/api/user/*', function ($user_id) {
    return ['id' => $user_id, 'name' => 'foo'];
});

if_post('/api/user', function () {
    $user = user::create(input('name'));
    return $user;
});
```

## 返回值约定

API 入口对路由闭包的**任意返回值**（数组、Entity、标量）统一包装成 JSON 响应体，自动设置 `Content-Type: application/json`：

```json
{"code":0,"msg":"","data":{...返回值...}}
```

- 因为 Entity 实现了 `JsonSerializable`，可直接 `return $entity`，无需手动转数组
- 业务异常由 `otherwise` / `otherwise_error_code` 抛出，统一走 JSON 错误结构（`{code, msg, data:[]}`），不再返回 HTML

## 当前文件

```
base.php          # 基础接口：错误码映射 /api/error_code_maps
```

## 新增路由文件

按模块拆分，命名 `模块名.php`（如 `user.php`），在 `public/api.php` 中对应的 `include API_DIR.'/base.php'` 后面追加一行 `include`。

## 注意事项

- API 入口不加载视图引擎，路由闭包内**不要调用 `render()`**
- 全局拦截逻辑在 `public/api.php` 的 `if_verify` 中注册；局部拦截在路由闭包内显式调用
- 跨域：入口已设置 `Access-Control-Allow-Origin: *` 并处理 OPTIONS 预检，前端可跨域调用
