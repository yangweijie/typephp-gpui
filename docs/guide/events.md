# 事件与回调

事件从 gpui 原生层跨 C-ABI 边界回到 PHP 主循环分派。有两条路：

## 1. 基础控件事件（ScriptView 路径）

`El::button('go')->id('go')->onClick()` 声明一个回调 op；bridge 为其分配 callbackId 并记
`(elementId, type)`。gpui 里点击触发 patched `ScriptView::OnClick` → 调弱符号
`gpuiEnqueueClick`（shim 提供强实现）→ 经 `boxForView` 反查窗口 Box2 → 入队一条 `GpuiEvent`。

PHP 侧注册 handler：

```php
$app->on('go', 'click', function (mixed $value): void {
    // 改外部状态；下一帧 view 闭包读到新值自动重渲染
    $count++;
});
```

主循环每帧 `pump` + `poll` 取事件，`dispatch` 按 `(id, type)` 找 handler 调用。
`App::onAny($fn)` 收所有事件。arity 自适应（handler 可省 `$value` 参数）。

支持类型：`click` / `change`（input/textarea/slider）/ `hover` / `key_down` 等。

## 2. 保留态取值（Input / Slider）

带 id 的 input/textarea/slider 在 render 时建 retained 实体（跨帧稳定）。事件只带
RetainedEntry 本地 id，shim 反查回 elementId + 读当前值入队：

```php
$app->render(El::input()->id('name')->placeholder('Your name')->onChange());
$app->on('name', 'change', fn ($v) => $app->text('name'));   // $v 即新文本
$app->setInput('name', 'prefill');                            // 程序化设值
```

## 3. 定时器

```php
$app->on('tick', 'timer', function (): void { $frame++; });
$app->setTimer('tick', 1000);   // 每 1000ms 入队一条 timer 事件；setTimer('tick', 0) 取消
```

底层 `WindowSetInterval` + tick 监听强符号 `gpuiEnqueueTimer`。

## 4. catalog 组件回调（Registered 路径，Stage D）

catalog 组件把回调存成 `ComponentArgument.callback`（一个 runtime CallbackId）。点击时
gpui 走 `ComponentCallback::InvokeWith` → 命中 `shimId` 旁路（`cmd/patch-registered-cb.sh`）
→ `gpuiEnqueueShimCallback` → 入队 → 同一条 poll/dispatch 链。

```php
$app->render(
    El::dropdownMenu('dd', 'Actions')->item('New', 'dd-new')
);
$app->on('dd-new', 'click', function (): void { /* 菜单项被点 */ });
```

`->item($label, $routeId, $type='click')` 内部把回调编码成 `El::cb($type, $routeId)`
（`['__cb__', type, id]`），bridge 经 `gpui_shim_new_callback` 登记 runtime 合成回调。

## 5. 异步服务事件（`http` / `dirChange`）

系统服务的答案不走"调用即返回"，而是排进同一条事件队列——因为 `HttpSend` 阻塞、
目录变更来自 OS 线程，两者都不能在渲染期间同步给 PHP。

```php
$app->on('api', 'http', function () use ($app): void {
    $r = $app->httpResponse('api');   // ['ok','status','body','contentType']，读一次即销毁
    if ($r !== null && $r['ok']) { $state['json'] = $r['body']; }
});
$app->httpGet('https://example.com/api', 'api');

$app->on('themes', 'dirChange', function () use ($app): void {
    $app->toast('主题目录变了', '重新载入');   // 只说"变了"，不说哪个文件
});
$watch = $app->watchDir('/path/to/themes', 'themes');
// 不要了：$app->unwatchDir($watch);
```

两条约定：

- **事件只报"有结果了"，内容另取。** `GpuiEvent` 的字符串槽只有 256 字节，装不下应答体，
  所以 `http` 事件带的是 status，正文用 `httpResponse($id)` 拿；它是**一次性**的，读完即释放，
  第二次读返回 `null`。收到事件就该读掉，不读的应答会攒在窗口上。
- **目录变更是合并过的。** 一串连续变更（编辑器保存就是好几个）只通知一次，且不带文件名——
  上游唯一的用法（主题热重载）是整目录重扫，所以这里也不假装知道是哪个文件。

`dirChange` 的 `onChange` 由 gpui post 到主线程（`dir_watch.h:16`），`http` 的 `done` 也保证
在主线程被调（`http.h:100`），所以入队不需要锁；但两者都要**泵一下循环**才落地——
`httpGet()` 返回 true 只表示"排上了"。

## 生命周期与幂等

- 回调按 `(elementId, type)` **幂等复用**：每帧重建同结构不增长（live app 友好）。
- `(id, type)` 是路由键，一对一映射到一个 handler，跨帧复用安全。
- 错误（如 catalog 构造失败）经 `App::registeredError()` 回传，不只藏在 `debug_tree`。

## 下一步

- [测试与门禁](/guide/testing) 看这些链路如何被 `--selftest` 覆盖
