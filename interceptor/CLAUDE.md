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

## 全局拦截怎么写

`if_verify` **只允许注册一次**：入口（`public/index.php` / `public/api.php`）已经用它把路由闭包包进 `unit_of_work` 与响应处理，重复注册会抛 `IF_VERIFY_ALREADY_REGISTERED` 直接报错（不会静默顶掉入口的包装）。所以全局拦截不自行注册，而是写成函数，由入口已注册的闭包调用：

```php
// interceptor/base.php —— 校验不通过时登记 redirect 并返回 false
function verify_global()
{
    if (get_current_user()->is_null()) {
        redirect('/login');
        return false;
    }
    return true;
}
```

```php
// public/index.php 的 if_verify 闭包（唯一注册）——拦截调用加在这里
if (! verify_global()) {
    return null;   // 已登记 redirect：不输出响应体，随后自动 302
}
```

入口这个闭包在路由匹配之后、路由闭包执行之前调用（未匹配到路由的请求不经过它），接收当前路由闭包和参数数组，**返回值会被当作响应体输出**（`null` = 不输出）——闭包内必须自行调用 `$action` 并把结果返回，`return $action;` 会把闭包交给 `echo`，直接致命错误 `Object of class Closure could not be converted to string`。

## 文件组织

按功能模块拆分，每个文件定义一组拦截函数：

- 通用/全局拦截函数放在 `base.php`
- 按模块命名，如 `auth.php`、`ratelimit.php`、`cors.php`
- 每个文件只包含纯函数定义，无类定义

## 拦截器使用原则

### 全局拦截器 → 写进入口的 if_verify 闭包

对所有请求统一生效的逻辑，写成函数放本目录，由入口（`public/index.php` / `public/api.php`）唯一的 `if_verify` 注册调用——`if_verify` 重复注册会直接报错，不要在拦截器文件里注册。

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

- 每个文件通过 `include` 加载，加载后函数即可被入口的 `if_verify` 闭包调用
- 拦截函数保持轻量，复杂逻辑下沉到 `domain/` 或 `util/`
- 数组一律使用 `[]` 短语法
- 无类、无注解、无反射
