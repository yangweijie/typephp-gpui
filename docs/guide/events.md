# 事件与回调

事件从 gpui 原生层跨 C-ABI 边界回到 PHP 主循环分派。有两条路：

## 1. 基础控件事件（ScriptView 路径）

`El::button('go')->id('go')->onClick()` 声明一个回调 op；bridge 为其分配 callbackId 并记
`(elementId, type)`。gpui 里点击触发 patched `ScriptView::OnClick` → 调弱符号
`gpuiEnqueueClick`（shim 提供强实现）→ 经 `boxForView` 反查窗口 Box2 → 入队一条 `GpuiEvent`。

PHP 侧注册 handler：

```php
$app->on('go', 'click', function (array $e): void {
    // $e 有 type / id / value 三个键；值随事件走（点击是连击数，指针是 CSV）
    // 改外部状态即可，下一帧 view 闭包读到新值自动重渲染
    $count++;
});
```

主循环每帧 `pump` + `poll` 取事件，`dispatch` 按 `(id, type)` 找 handler 调用。
`App::onAny($fn)` 收所有事件。arity 自适应：写 `function ()` 就不传参，
写 `function (array $e)` 传的是**整个事件数组**（不是 `value` 本身）。

支持类型：`click` / `change`（input/textarea/slider）/ `hover` / `key_down` 等。
链式标记与事件名的对应关系是 `on_hover` → `hover`、`on_key_down` → `key_down`：
bridge 剥掉 `on_` 前缀后作为 `(id, type)` 的路由键（`gpui_bridge.cc:49`），而交给
materialize 的 op 名**必须保留 `on_` 前缀**（它逐字比较 `"on_click"`，`materialize.cpp:233`）。
这两套名字曾经被混用成一整套短名，结果是真实鼠标/键盘一个事件都进不来。

## 1a. 指针与滚轮（`on_mouse_*` / `on_scroll_wheel` / `on_key_up`）

上游一共认 24 个 `on_*` 回调名，我们发出并**实测送达**的是这 14 个：
`on_click` `on_change` `on_hover` `on_key_down`（原有四个）加下面这十个。

| 糖 | 事件 type | `value` |
|---|---|---|
| `onMouseDownLeft/Right/Middle()` | `mouse_down_left` / `_right` / `_middle` | `"x,y,button,clicks,mods"` |
| `onMouseUpLeft/Right/Middle()` | `mouse_up_left` / `_right` / `_middle` | 同上（`clicks` 恒 1） |
| `onMouseDownOut()` | `mouse_down_out` | `"x,y,button,clicks,mods"` |
| `onMouseMove()` | `mouse_move` | `"x,y,pressed,button,mods"` |
| `onScrollWheel()` | `scroll_wheel` | `"dx,dy,x,y,mods"`（DIP，一格 48） |
| `onKeyUp()` | `key_up` | 键名或字符（与 `key_down` 同一条钩子） |

`button` 是上游 `gpui.h` 里 `MouseButton` 的序号（Left=0 / Right=1 / Middle=2 /
NavigateBack=3 / NavigateForward=4）；`mods` 是位掩码
`control=1 alt=2 shift=4 platform(Command)=8 function(Fn)=16`。
GpuiEvent 只有一个 value 槽，所以这里定的是**字段顺序**而不是类型。

这三类 handler 的投递**依赖 `cmd/patch-mouse-view.sh`**：materialize 给它们挂的是
`Listen(cx, &ScriptView::OnScriptMouse*, cbid)`（`materialize.cpp:2701-2735`），
而未打补丁的函数体会走 `DispatchMouseButton` / `DispatchScrollWheel` / `DispatchMouseMove`
→ QuickJS。PHP 宿主没有脚本引擎，实测的现场是：**摘掉补丁重编（amalgam 里钩子名 0 处），
真按下那一刻进程 SIGSEGV（rc=139）** —— 与 #53 的 `key_down` 现场同形。
所以这一族不是"没接就不生效"，而是"接了但没补丁会崩"；补丁已进 `bin/gpui` 的清单单一来源，
`gpui doctor` / `verifyPatches()` 缺位即中止构建。
与 click/key 一样的已知代价：脚本端那对 `propagate` / `stopPropagation` 语义在这里没有，
事件是否继续向外由宿主决定。

还**没接**的十个（`on_confirm` `on_dismiss` `on_step` `on_resize` `on_open_change`
`on_item_click` `on_item_secondary_click` `on_link_click` `on_token_click` `on_token_hover`）
不是缺糖：它们的 callbackId 被存进各部件自己的 binding（如 `binding->onConfirm`），
触发方在 base 组件里，走的是另一条尚未验证的投递路。没量到送达之前不给糖——
那是 #66 那类"看起来能用、实际一个像素都不动"的假接口。`token` 更不是事件，
它是**要返回内容**的渲染回调，与 `El::list` 的 renderer 同一类（见 #29/#35）。

## 1b. 部件自己发出的事件（`on_confirm` / `on_dismiss` / `on_open_change` / `on_step` / `on_resize` …）

这批与上面两族不同的地方是：callbackId 不挂在元素监听器上，而是**存进部件自己的 binding**
（`ShellSelectBinding` 等），由部件在合适的时刻调 `runtime->DispatchSignal/Change/String()` 发出来。
好消息是这条路**不需要新补丁**：打过补丁的 `ShellRuntime::Dispatch` 开头就有 shim 那条路
（`entry->shimId` 非零 ⇒ `gpuiEnqueueShimCallback` 入本窗口事件队列，完全不碰 QuickJS）。

坏消息是**送达至今没量到**。探针是 showcase 的独立模式：

```bash
php bin/gpui run showcase --probe-events
```

它的读数（2026-10-13，隔离进程、前台已确认 `active=true key=true bundled=true`）：

```
posted=20 open=0 confirm=0 dismiss=0 step=0 resize=0 | confirmPosted=false cancelPosted=false | evSeen=timer{tick} click{btn-plain,btn-p,…}
```

怎么读这份数：20 次真点击投递成功、其中 6 次落到了同一节里的按钮上（`click{...}` 就是证据），
但 Select / Combobox 那几行没有回任何 `open_change`。另外这一轮只渲染了第 3 节，
`step`（数字输入在第 2 节）与 `resize`（可伸缩面板在第 4 节）**根本没被触发条件覆盖**——
这两个 0 是探针自己的漏洞，不是结论。

内建动作派发这一条已经有了确定的结果（2026-10-13，同一支探针里量的）：
开着 dialog、焦点陷阱生效之后 `dispatchAction('ui::Cancel')` 会把框关掉——
读数是 `cancelGate trap=195604379 dispatched=true closed=true`，
而且它不依赖任何输入，所以已经进 `--selftest` 当硬门（负向对照：把 shim 里那次
"按原名字再解析一遍"的回退路摘掉，就变 `dispatched=false closed=false`、rc=1）。
Select 那几行仍然是 0，原因不同：动作派发从**焦点节点**出发，而自动缝没法把焦点放进 Select
（要焦点就得真点击或 Tab 到位，这一环与 #56 同源）。

所以现在的诚实状态是：这五个名字**在册、op 发得出去、宿主侧尚未量到送达**。
探针留在仓库里（`runEventProbe`），任何一次改动之后重跑就能刷新这份读数；
在它报非零之前，别把这一族当"能用"写进代码里。

三条踩过的坑，写在源头免得再踩：

1. 探针**不能塞进 `--selftest` 的中途**：真点击会点开弹层，弹层一开 gpui 就持续重画，
   于是"空转 40 帧只画 1 次"那条脏驱动门会红——红的原因与被量的事无关。
2. 也不能放到所有门**之后**：那时进程已经不在前台，整段只投递成功 1 次，
   报出来的 0 是"没有余量"而不是"不通"。
3. 不要用命中表当点击靶子（`hitProbe(i)` 逐个点矩形中心）：表里含窗口 resize 边那一类矩形，
   点它会让 AppKit 的 `-[NSWindow _mouseHysteresisCheck:]` 等下一个事件而不返回，整轮挂死
   （实测 rc=124 被看门狗掐掉）。

## 1c. 真输入的验收缝（`realClick` / `realMouseMove` / `realKey`）

自检里的合成事件走 `gpui_test_event`：按 callbackId 直接入队，**绕过 gpui 的 hit-test 与
behavior 解析**——也就是说"监听器根本没挂到元素上"这类错，在合成事件下永远是绿的。
所以交互能力还有一条只认真实路径的验收：

```php
$app->realClick(100, 104);      // 走 -[NSWindow sendEvent:]，hit-test/behavior/focus 全走
$app->loop(2);                  // 事件下一帧才派发
$app->realMouseMove(300, 522);  // → hover
$app->realKey(9, 'g');          // kVK_ANSI_G → 打给当前聚焦的输入框
$app->inputProbe();             // ['n'=>paint 登记了几颗真 Input, x/y/w/h=>最后一颗的框]
```

返回值 = "这一趟 PHP 队列里确实多出了事件"。`showcase --selftest` 用它断言三件事：
点导航能换节、hover 能打中 `onHover` 的元素、点输入框后打字能回到 PHP。
`inputProbe()['n'] > 0` 单独断言"输入框是真的 Input，不是静默降级出来的 Div"。

### 命中表探针（`hitProbe`）：为什么不能按 y 猜"哪一行点不动"

真实鼠标先过 gpui 的 `paint.hits` 这张表，命中了才轮得到 behavior/回调。所以"那一行点不动"
有四五种成因，长得一模一样：表里没这条、这条不在那个位置、位置对但监听没挂、
**表外面还有东西挡着**。按 y 扫屏幕猜行位置会把四种统一伪装成同一个症状——
`hitProbe($index)` 读的就是点击真正查的那张表：

```php
$p = $app->hitProbe();          // ['n'=>命中矩形条数]
$h = $app->hitProbe($i);        // + x/y/w/h、id、path、click(0/1)、parent
```

`showcase` 的导航验收因此改成表驱动：先量出 13 颗按钮各自的矩形，再逐颗点自己的中心，
断言"13 条矩形、13 次都进 PHP、到达 13 个不同节号"。

最后那种成因踩过一次真的：渲染过带 WebView 的那一节之后，侧栏上面 6 行再也点不动，
而 `hitProbe` 里 13 条矩形、`click=1` 全都在——因为 `ChildView` 背后那颗 WKWebView 是**常驻的
原生 NSView**，元素从树上换掉之后它仍挂在窗口上、停在最后一次的矩形里，AppKit 的 `hitTest`
会先命中它，gpui 的元素从此收不到那一片的鼠标。现在 shim 每帧 materialize 前后各扫一次
（`WebViewFrameVisibility`）：这一帧没渲染到的那颗就 `hidden=YES`，AppKit 会跳过隐藏视图，
鼠标就还给下面的元素了。

## 2. 保留态取值（Input / Slider）

带 id 的 input/textarea/numberInput/otpInput/slider 在 render 时建 retained 实体（跨帧稳定）。
**id 必须在节点的 `id` 字段上**：`El::input('name')` 与 `El::input()->id('name')` 现在等价；
建不出 retained 时 materialize 是 `return Div(cx->a)`——不报错、`registeredError()` 也是空的，
只是这颗控件静默变成一段文字（不聚焦、不能打字、点它没事件）。事件只带
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

## 7b. 菜单动作（`action`）

菜单有两条来源，动作回到 PHP 是同一件事件、同一个写法：

```php
// ① 元素树里：El::menuBar / El::menu / El::menuItem($label, $actionId)
// ② 应用级（macOS 顶部菜单条）：
$app->setAppMenus([
    ['name' => 'File', 'items' => [          // 菜单条用 name，行用 action
        ['label' => '新建', 'action' => 'file.new'],
        ['separator' => true],               // 分隔符没有 action
        ['label' => '关于本应用'],             // 纯标题项也没有 action
    ]],
]);

$app->onAny('action', function (array $e): void {
    // $e['value'] 就是声明时写的那个 action id
});
```

链路与两个坑：

- 走的是 `NSMenuItem` 的 target/action → `AppMenuChosen(tag)` → 按行号取回 action id →
  派发到钥匙窗口 → 焦点链没人接 → 落到 app 级处理器（`AppOnAction`）→ 入 PHP 事件队列。
  挂在根元素上的 `El::OnAction` **收不到**菜单动作（实测 `WindowDispatchAction` 直接返回
  false），app 级这一层才是上游给"没有元素要的动作"留的位置。
- **键名写错不报错。** bridge 读的是菜单条 `name`、行 `action`；写成 `label`/`id`
  时菜单照样装得上去、辅助功能里也看得见，但那一行的 action 是 0，`AppMenuChosen`
  第一行就 return，点了永远没回音。`App::menuRows()` 是专门读这张表的探针：
  它返回菜单条各行携带的 action id（无动作的行不计入），装完立刻读一次就能把键名钉住。
- 动作表是进程级的、定长的（上游 `kMaxAppActions`）。超出预算时 shim 不再静默丢，
  会把原因写进 `App::lastError()`。

`--selftest` 里对应两条门：`rows=st-new/rowsOk=true`（表里装的确实是 PHP 声明的 id）与
`action=true/dispatched=true`（该 id 的动作回到了 PHP）。原生菜单行的"真点一下"进不了门禁
——`realClick` 是进程内把 `NSEvent` 投给自己的窗口，够不到系统菜单条那个窗口，
所以这一跳拆成上面两段取证。

## 真输入还差一步：应用得是"前台应用"

上面讲的都是"事件怎么路由"，但从 WindowServer 进来的真鼠标/真键盘在此之前先过一道系统门：
`NSApp.isActive` 与 `window.isKeyWindow`。这两个数不成立时——第一次点击被系统当成"激活这扇
窗口"的点击吃掉，`keyDown` 根本没有 firstResponder 可去——现象是**代码全绿而手里点不动**。
`App::windowState()` 就是把这四个数读出来（`active/key/visible/main`），外加
`bundled`（进程有没有 `.app` 身份）和 `nsappDeliveries`（事件缝里走过真
`postEvent → nextEventMatchingMask → -[NSApp sendEvent:]` 的条数）。

`php bin/gpui run`/`selftest` 会把构建产物包进 `.app` 再启动，就是因为裸二进制即使调了
`TransformProcessType` 也起不来 `isActive`。showcase 在非前台时还会直接把这条画在窗口顶上
（`front` 状态，每帧由 `windowState()` 量出来），"是不是系统那一步没成立"不必靠猜。

## 首帧的命中表：为什么"刚启动那几下"点不进去

真实点击要先过 hit-test（gpui 的 `paint.hits` 矩形表），再轮得到 behavior 解析与回调。
实测：`--liveclick` 里只跑 1 帧时，38 个采样点产生 **0** 个事件；跑到第 2 帧起稳定 18/38
且 `nav-*` 真的到达 PHP。也就是说首帧把画面画出来了，却没把命中表建起来——用户看到界面正常、
点了没反应，就是这一格。

`App::view()` 因此**注册时就先 materialize 一遍**（`run()` 也走这条路），把命中表在第一帧就
备齐；对应的回归位是 `examples/showcase --liveclick`（首帧事件数为 0 就 rc=1，CI 已跑）。
写自定义循环时要注意：`view()` 的回调现在会被立刻调一次，帧数统计要扣掉这趟预热。

### 键事件走的是 view 层的宿主钩子，不是 QuickJS

gpui 原本把键事件交给 `ScriptView → ShellRuntime::DispatchKey`，那里无条件用 QuickJS 建负载；
PHP 宿主没有脚本引擎，于是"给元素挂了 onKeyDown 之后一按真实键就段错误"。
上游补丁 `cmd/patch-key-view.sh` 按 `OnClick` 的**同一形状**处理：在 view 层就把字符交给
`gpuiEnqueueKey` 弱钩子（强实现在 shim，转成 UTF-8 入窗口队列），键事件从此不去碰 JS。
`key_down` 的 `value` 是按下的字符（UTF-8）。与点击一样的取舍：不再有脚本端的
`stopPropagation`，传播语义由宿主决定。

`value` 的取材按 `KeyEvent` 的定义分两种，因为它的 `vk` 与 `ch` 是**互斥**的（`gpui.h` 里写明
`vk` 是 "0 for a typed character"、`ch` 是 "0 for key down/up"）：`key_down` / `key_up` 给的是
键名 `KeyName(vk)`（如 `1`、`backspace`、`f5`），输入字符那一路给的才是码点转成的 UTF-8。
`--livetyping` 两种都钉住，读数 `key_down=[in-key] value=[—1]`——那个 `1` 就是 keycode 18 的键名。

这条路由 `examples/showcase --livetyping` 逐颗输入框验：读数
`合计=9/9 key_down=[in-key]`（每一颗都收得进字符，且挂了 `onKeyDown` 的那颗确实收到
`key_down`）——它同时是回归位：键路再断，这条就红。

顺带一条同类陷阱：`El::vFlex([...])->itemsStart()` 里的按钮只占**文字宽度**，不是整行。
showcase 的导航实测可点带只有 x=12..132（侧栏宽 210），点行右半截什么都没发生；
给满 `->w(194.0)` 之后实测 12..196。`--selftest` 的 `navBand` 断言盯着这件事。

还有一条在 AppKit 那一侧：上游的 tracking area 原本写的是 `NSTrackingActiveInKeyWindow`，
意思是**窗口不是钥匙窗口就不投递 `mouseMoved`**。于是应用没被激活（或用户切到别的 app 而鼠标
还悬在我们窗口上）时，悬停反馈一条都收不到——PHP 侧的 `on_hover` 接线本来就是通的。
上游补丁 `cmd/patch-hover-always.sh` 把它换成 `NSTrackingActiveAlways`。
这条改动只有把手移到未激活窗口上才验得出来：app 内的合成缝不受这个门槛约束，
所以它在自动门禁里既不会绿得可疑，也不会红得有用。

## 渲染是脏驱动的

`App::loop()` 每圈都做三件事：泵 + 派发事件、（若开了 `keepTryingForeground`）有界地抢一次
前台、然后**判断这一帧要不要画**。要画的四种情况：

| 判据 | 为什么 |
|---|---|
| 每轮 `loop()` 的首帧 | 首帧的 materialize 建不出命中表，必须有一帧"画完"才有可点的东西（见下节） |
| 这一圈派发过事件 | 点击/输入/定时器改了状态 |
| `windowState()` 的 active/key/visible/main 变了 | HUD 与"当前不是前台应用"那条横幅就是它的函数 |
| `$app->invalidate()` | app 自己改了状态但没有任何事件（外部定时器、跨窗口联动等） |

四条都不成立就 `continue`：不跑 view 回调、不序列化、不 materialize。

配套读数与开关：

```php
$app->keepTryingForeground(12);  // 让框架在头 12 次里替 app 抢前台；默认不开
$app->foregroundTries();         // 已经试了几次（0 = 没开）
$app->renderCount();             // 真正重画过几回
$app->frameCount();              // 循环跑了几圈
$app->invalidate();              // 标脏：下一帧必须重画
```

`keepTryingForeground` 之所以在框架里而不是 app 的每帧回调里：**帧可能被跳过**。如果
"没拿到前台就再试一次"写在 view 回调里，就会变成"没画 → 没尝试 → 永远不是前台"的死结。

## 生命周期与幂等

- 回调按 `(elementId, type)` **幂等复用**：每帧重建同结构不增长（live app 友好）。
- `(id, type)` 是路由键，一对一映射到一个 handler，跨帧复用安全。
- 错误（如 catalog 构造失败）经 `App::registeredError()` 回传，不只藏在 `debug_tree`。

## 下一步

- [测试与门禁](/guide/testing) 看这些链路如何被 `--selftest` 覆盖
