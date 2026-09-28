# controller/ CLAUDE.md

路由定义目录，**只放页面路由**（返回 HTML）。根 CLAUDE.md 中已包含路由编写通用规则，本文件仅补充本目录特有的信息。

接口（API）路由请放 `controller_api/` 目录（路由以 `/api/` 开头），由 `public/api.php` 加载。

## 当前文件

```
base.php          # 基础路由：首页、健康检查
```

## 新增路由文件

按模块拆分，命名 `模块名.php`（如 `user.php`），在 `public/index.php` 中对应的 `include CONTROLLER_DIR.'/base.php'` 后面追加一行 `include`。

## 路由闭包返回值约定

页面入口（`public/index.php`）**只接受字符串返回值**，框架将字符串直接作为响应体并自动设置 `Content-Type: text/html`。若页面路由返回非字符串（数组/Entity 等），会被判为编程错误抛出 500 并提示迁移到 `controller_api/`。

```php
// 返回 HTML 页面
if_get('/', function () {
    return render('index/index', ['title' => 'hello world']);
});
```

`render('模板名', $data)` 读取 `view/` 下的 Blade 模板并返回 HTML 字符串，符合上述字符串返回约定。

模板名取 `view/` 目录之后的相对路径，**去掉末尾的 `.php` 扩展名**。例如模板文件为 `view/index/index.php`，模板名为 `'index/index'`。

## 注意事项

路由文件中**不要封装函数**，所有逻辑直接写在路由闭包内：

- 复杂的数据操作和业务对象操作，封装到 `domain/knowledge` 中。
- 拦截类逻辑（如登录状态获取与判断），放到 `interceptor` 中。

路由闭包逻辑中要遵循防御式编程原则，要**尽可能把请求输入数据的异常情况拦截**，要积极使用 otherwise/otherwise_error_code 方法放行正确情况拦截错误情况。

## 变量

路由通配符 `*` 匹配单个路径段，按位置传递给闭包参数：

```php
if_get('/user/*/post/*', function ($user_id, $post_id) { ... });
```
