# CLAUDE.md

## 目录定位

拦截器目录，存放请求前置/后置逻辑。按模块拆分文件，文件中以函数形式实现拦截逻辑，在入口文件中通过 `include` 加载。

## 加载方式

**页面入口**：在 `public/index.php` 的 `// init interceptor` 注释之后引入：

```php
// init interceptor
include INTERCEPTOR_DIR.'/base.php';
```

**API 入口**：在 `public/api.php` 的 `// init interceptor` 注释之后引入（API 拦截器与页面拦截器通常不同，如 API 鉴权、限流）。页面与 API 的拦截逻辑可拆成不同文件，避免互相污染。

## 可用拦截器函数

### if_verify — 请求验证/前置拦截

`if_verify()` 注册一个闭包，在路由匹配之前执行。注册的闭包接收当前路由 handler 和参数，返回处理后的 handler：

```php
if_verify(function ($action, ...$args) {
    // 前置逻辑：鉴权、参数校验、限流等
    return $action; // 必须返回 action
});
```

## 文件组织

按功能模块拆分，每个文件定义一个或多个拦截器注册调用：

- 通用/全局拦截器放在 `base.php`
- 按模块命名，如 `auth.php`、`ratelimit.php`、`cors.php`
- 每个文件只包含纯函数调用，无类定义

## 拦截器使用原则

### 全局拦截器 → if_verify

对所有请求统一生效的逻辑，注册到 `if_verify`：

```php
if_verify(function ($action, ...$args) {
    // 全局鉴权、通用参数校验、限流等
    return $action;
});
```

### 局部拦截器 → controller 内显式调用

仅部分路由需要的拦截逻辑，在 controller 路由闭包内显式调用，确保代码中一目了然：

```php
if_get('/admin/*', function ($id) {
    verify_admin();           // 拦截器显式调用，可查
    return dao('admin')->find($id);
});
```

**Why:** 局部拦截逻辑隐藏在 `if_verify` 或单独 include 的文件中会降低可读性——读 controller 代码时看不到完整的执行链。显式调用让路由闭包自描述，无需跳转到其他文件即可理解请求处理全流程。

## 编码约定

- 每个文件通过 `include` 加载，加载即注册
- 拦截器函数（闭包）保持轻量，复杂逻辑下沉到 `domain/` 或 `util/`
- 数组一律使用 `[]` 短语法
- 无类、无注解、无反射
