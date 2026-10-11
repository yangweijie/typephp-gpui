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

## 6. 通知的 `click` / `close`

`App::notify()` 传了 `$id` 才会接线：卡片被点 → `type='click'`，卡片被关闭按钮或退场动画
收掉 → `type='close'`，都带同一个 `id`。

```php
$app->on('saved', 'click', function () use ($app): void {
    $app->openSheet($detailSheet, 'right');   // 点卡片跳到详情
});
$app->on('saved', 'close', function () use ($app): void {
    unset($state['pending']['saved']);       // 自己记下"这条已经没了"
});
$app->notify('已保存', '3 个文件写好了', 'success', 'top_right', 5000, 'saved');
```

三条约定：

- **不传 `$id` 就没有事件。** 只想在窗口里闪一句话，用 `App::toast()`；要能点、能追状态，
  才用通知。
- **`close` 是"这张卡已经不在了"，不是"用户点了关闭"。** 超时自动退场、`dismissNotification()`、
  `clearNotifications()` 三条路都走同一个退场动画，动画走完才把条目从列表里摘掉并回调，
  所以它比 `timeoutMs` 晚约 200ms（`kToastExitMs`）。别拿它当"用户看到了"的度量。
  同理，一条通知的在场时间是 `400ms 入场 + timeoutMs + 200ms 退场`，不是 `timeoutMs`。
- **一次 `push` 注册两个路由键。** `click` 与 `close` 各是一条 listener。点卡片不会让卡片
  消失，所以 `click` 之后两个键都留着；只有 `close` 会把这一对一起摘掉——注册表的大小
  只跟活通知成正比。

## 7. 窗口外的事件（`tray` / `hotkey`）

这两条来自窗口之外：菜单栏里的托盘项、系统级的全局快捷键。它们照样走同一条入队/`poll`
通道，所以 PHP 侧的写法与别的事件没有区别。

```php
$app->tray(['显示窗口', '退出'], 'tray-main', '示例应用');
$app->on('tray-main', 'tray', function (array $e): void {
    // $e['value'] 是被点的菜单项文字；没挂菜单的托盘项 value 为 null
});

$app->onHotkey('focus', 105, ['cmd', 'shift']);   // F13
$app->on('focus', 'hotkey', function (): void { /* … */ });
```

三条约定：

- **挂了菜单，点击就被 `NSMenu` 吃掉**；想让"点图标本身"成为事件，就把 `$labels` 传空数组。
- **快捷键的 mods 用名字**（`cmd`/`shift`/`option`/`control`），位或是 `App::onHotkey` 里算的。
  写错名字直接抛异常，不会静默变成"注册了一个不响的键"。
- **`hotkey` 能被合成、`tray` 不能。** `App::fireHotkeyForTest($i)` 是自检用的接缝，它验
  派发链；系统级"在别的 app 有焦点时仍然响"只能人按。`tray` 连这条接缝都没有（要真点菜单），
  所以它只有 existence-green。

## 生命周期与幂等

- 回调按 `(elementId, type)` **幂等复用**：每帧重建同结构不增长（live app 友好）。
- `(id, type)` 是路由键，一对一映射到一个 handler，跨帧复用安全。
- 错误（如 catalog 构造失败）经 `App::registeredError()` 回传，不只藏在 `debug_tree`。

## 下一步

- [测试与门禁](/guide/testing) 看这些链路如何被 `--selftest` 覆盖
