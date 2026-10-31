# 事件与回调

事件从 gpui 原生层跨 C-ABI 边界回到 PHP 主循环分派。有两条路：

## 1. 基础控件事件（ScriptView 路径）

`El::button('go')->id('go')->onClick()` 声明一个回调 op；bridge 为其分配 callbackId 并记
`(elementId, type)`。gpui 里点击触发 patched `ScriptView::OnClick` → 调弱符号
`gpuiEnqueueClick`（shim 提供强实现）→ 经 `boxForView` 反查窗口 Box2 → 入队一条 `GpuiEvent`。

PHP 侧注册 handler：

```php
$app->on('go', 'click', function (array $e): void {
    ExampleState::$v['count']++;
});
```

`$e` 含 type / id / value；值随事件走。示例状态保存在共享静态表，下一帧 view 读取新值，
不依赖 nano 不支持的引用捕获。

主循环每帧 `pump` + `poll` 取事件，`dispatch` 按 `(id, type)` 找 handler 调用。
`App::onAny($type, $fn)` 收所有元素的指定类型事件，不是省略类型的全事件订阅。
arity 自适应：写 `function ()` 就不传参，写 `function (array $e)` 传的是**整个事件数组**（不是 `value` 本身）。

支持类型：`click` / `change`（input/textarea/slider）/ `hover` / `key_down` 等。
链式标记与事件名的对应关系是 `on_hover` → `hover`、`on_key_down` → `key_down`：
bridge 剥掉 `on_` 前缀后作为 `(id, type)` 的路由键（`gpui_bridge.cc:49`），而交给
materialize 的 op 名**必须保留 `on_` 前缀**（它逐字比较 `"on_click"`，`materialize.cpp:233`）。
这两套名字曾经被混用成一整套短名，结果是真实鼠标/键盘一个事件都进不来。

## 1a. 指针与滚轮（`on_mouse_*` / `on_scroll_wheel` / `on_key_up`）

上游一共认 24 个 `on_*` 回调名，我们发出并**实测送达**的是这 20 个：
`on_click` `on_change` `on_hover` `on_key_down`（原有四个）、下面这十个，
再加上部件级的 `on_open_change` `on_confirm` `on_dismiss` `on_step`，
以及虚拟列表的 `on_item_click` / `on_item_secondary_click`（见 1b）。

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
这些指针事件在 `value` 字符串里约定**字段顺序**；虚拟列表右键另有结构化载荷，见下节。

这三类 handler 的投递**依赖 `cmd/patch-mouse-view.sh`**：materialize 给它们挂的是
`Listen(cx, &ScriptView::OnScriptMouse*, cbid)`（`materialize.cpp:2701-2735`），
而未打补丁的函数体会走 `DispatchMouseButton` / `DispatchScrollWheel` / `DispatchMouseMove`
→ QuickJS。PHP 宿主没有脚本引擎，实测的现场是：**摘掉补丁重编（amalgam 里钩子名 0 处），
真按下那一刻进程 SIGSEGV（rc=139）** —— 与 #53 的 `key_down` 现场同形。
所以这一族不是"没接就不生效"，而是"接了但没补丁会崩"；补丁已进 `bin/gpui` 的清单单一来源，
`gpui doctor` / `verifyPatches()` 缺位即中止构建。
与 click/key 一样的已知代价：脚本端那对 `propagate` / `stopPropagation` 语义在这里没有，
事件是否继续向外由宿主决定。

上面那句"还**没接**的十个"订正于 2026-10-13：糖现在有了（`onConfirm()` `onDismiss()` `onStep()`
`onResize()` `onOpenChange()` `onItemClick()` `onItemSecondaryClick()` `onLinkClick()`
`onTokenClick()` `onTokenHover()`，见下面 1b），其中 `on_open_change` `on_confirm` `on_dismiss`
`on_step` 从 2026-10-14 起**实测送达**（`selftest choices` 的 `openChangeDelivered` 与
`confirmDismissDelivered`、`selftest input` 的 `stepDelivered` 三条硬门）。列表级
`on_item_click` / `on_item_secondary_click` 另由 `selftest table` 的 `virtualItemEvents` 验证；
其余仍记在“名字在册、送达未量”。为什么先发糖不算错：callbackId 走的是部件自己的 binding
（`ShellSelectBinding` 等），触发方在 base 组件里，我们这边能确证的只有"名字发得出去、
对得上上游词表"——那条门现在住在 `php bin/gpui selftest catalog`
（`componentCallbackOps — 发出=5 去重后=5`）。
`on_token_click` / `on_token_hover` 在上游 schema 中是普通 `void` 事件监听器——
**批4（2026-10-31）已量化送达**：`App::inputReplaceToken($id, $tokenId, $text, $label)`
（InputReplaceWithToken，base/input.cpp:5780）给 retained 输入造 token 后，
两事件经 DispatchTokenClick/Hover → Dispatch → DispatchArgs 的 shimId 早返回入队
（与 click 同一条队列），`gpui_selftest_fire_registered` 送达断言在 kit selftest 的
tokenDeliveryRender 门（fire=true、delivered ✓）；native 鼠标命中 token 的
hit-testing 属真人 HID。**注意队列类型是短名** `token_click`/`token_hover`
（eventType 剥 on_ 前缀），`App::on` 与 fire 都用短名。
另一个名为 `token` 的方法才是**要返回 Element 或 null** 的渲染回调，不能把三者混为一谈
——它的宿主接线见下节。`on_link_click` 的消费端是 **TextView 的 markdown 链接**
（materialize.cpp:1728 OnLinkWithContext；base kind Link 的 click 语义是打开 href，
不消费 on_link_click）——送达同样已量化（linkDelivery 门，fire=true ✓；native
realClick 已实测到达 OnTextLink 派发点，down/up 帧界悬垂在当前机器态下崩，
见批3 环境记录）。

### token 渲染回调的宿主接线（批4）

`token` 渲染回调的 shim 接线走了两支补丁：`cmd/patch-token-render.sh` 在
RenderInlineToken 顶部加 shimId 分叉（weak gpuiRenderShimToken），shim 侧
"php::call 处理器 → 元素描述 → emitTree 进 batch → ShellMaterializeSpec"（与
gpuiRenderShimItems 同形）。两个坑都已钉死：① shim 的重前缀会把 `token` 变成
`on_token`（上游 op 名就是无前缀的 `token`，逐字比较落空）——特判豁免；
② 元素回调的批4 分叉在 `cmd/patch-host-element-cb.sh`（BuildComponentElement 的
shimId 分叉，覆盖 MessageScroller render_item / DataTable render_cell / Select
render_row 同缝），batch 分配在 cx->a 帧 arena——**不能**走 JS 路的
RenderSnapshot/frameSnapshots（shim 表项的 entry->view 是 PhpView 实体，
`Get()` 模板标签对 ScriptView 无类型检查，Vec 拿到垃圾分配器，实测 malloc abort）。
验收边界：回调链路全通（tokenDeliveryRender 的 renderCalls=2630、
messageRowRender 的 renderCalls=15 为证）；chip/行的像素落位依赖上游
BoundsOut 的多帧沉降，无头+当前机器态下不稳定，降为 advisory 读数。

## 1b. 部件自己发出的事件（`on_confirm` / `on_dismiss` / `on_open_change` / `on_step` / `on_resize` …）

这批与上面两族不同的地方是：callbackId 不挂在元素监听器上，而是**存进部件自己的 binding**
（`ShellSelectBinding` 等），由部件在合适的时刻发出来。

**2026-10-14 之前这一节写的"这条路不需要新补丁"是错的**，而且错的代价是一次很难复现的崩溃。
机制是：`ResolveBehavior`（`materialize.cpp:230-300`）把 `op.callback` **原样**塞进 behavior，
而 `gpui_shim_callback` 给的是 **Box2 自己的 callbackId**——runtime 的回调表里从来没有它的条目
（只有 catalog 那条 `gpui_shim_new_callback` → `PushShimCallback` 会登记一个带 `shimId` 的合成条目）。
于是部件调 `runtime->DispatchChange/Signal/String(那个 id)` 时，`CallbackArena::Get()` 扫一张
没有该项的表，扫不到就静默丢——这就是"op 发得出、送达从未量到"的机制级原因。
而这条扫描在 `impl` 已经不健康时会直接段错误：`examples/choices` 连跑 6 次崩 4 次，
三份崩溃报告同一条栈（`DispatchArgs` ← `ScriptView::OnSelectAction` ← `WindowDispatchActionFrom`），
反汇编逐字对得上 `for (i < len(entries)) { if (entry->committed && …) }` 那一句。

修法是 `cmd/patch-select-view.sh`：跟着点击/按键/指针那三支的既有形状，**在 view 层换投递点**，
把 7 处 binding 派发改成宿主弱钩子（`gpuiEnqueueChange` 带 bool、新增 `gpuiEnqueueSignal` 无载荷、
新增 `gpuiEnqueueString` 带字符串），完全不进回调表。实测（`php bin/gpui selftest choices`）：

```
  ok   openChangeDelivered — focus=-1001 SelectDown=true open_change 到 PHP=true 值=true confirm/dismiss=0/0
```

`值=true` 是这条链第一次带着载荷到 PHP（旧写法只能拿到"有事件"）。修之前同一支的读数是
`actionAfterFocus(读数) — SelectDown=true … open_change=0 evSeen=（空）`；
补丁留在原地、把焦点拿掉，`componentEventDelivery` 那一行仍然是 `evSeen=（空）`——
所以"要焦点"与"要补丁"是两个各自成立的前提，`App::focusElement()`（同日加，
按 bridge 造 handle 的 `id+":t"` / `id+":c"` 命名规矩把焦点放进部件）负责前者。

`confirm` / `dismiss` 同日也量到了，但它们多一个前提：binding 里的 `open` 位取自
`behavior.open`，而 `SelectActionOf` 是按这个位解释动作的——`open=false` 时 `Confirm`
被读成"再开一次"、`Cancel` 被读成"不归我管"（`base/select.cpp:43-49`），所以那颗糖必须由
PHP 侧声明。补上 `El::open(bool)`（`method('open', [$v])`）之后，`examples/choices` 渲染
第二棵把 `ch-select` 声明成开着，读数：

```
  ok   confirmDismissDelivered — open=true 那棵 focus=-1001 Confirm=true confirm 到 PHP=true dismiss 到 PHP=true open_change 收尾=false
```

这条门做过负向对照：只把 `->open(...)` 摘掉（补丁留在原地），同一条就变
`confirm 到 PHP=false dismiss 到 PHP=false open_change 收尾=true`、rc=1——
`open_change 收尾=true` 正是"Confirm 被读成再开一次"的样子。

下面两类的旧缺口原因不同：列表事件现已接通；resize 仍有物理输入验收边界，不能笼统写成“补丁没打”。

- `on_item_click` / `on_item_secondary_click`：现在由宿主虚拟列表行包装挂监听器，
  不再依赖只能调 QuickJS 的列表渲染路。`value` 为行根 id（未给 id 时为全局行号字符串）。
  右键事件另带 `button='right'`、`click_count`、窗口坐标 `position`、行内坐标 `local_position`
  和布尔修饰键表 `modifiers`。`examples/table` 的 `virtualItemEvents` 通过真实 AppKit 点击/右键验证
  key、坐标及左键不触发右键事件；`virtualRowListeners` 另验证行自己挂的监听器。
- `on_resize`：2026-10-14 把能补的两格补了，第三格是硬墙。
  ① **糖挂错节点，已修**：上游只从**组**读 `on_resize`（`materialize.cpp:2349-2352`），
  panel 分支只取 size/range/visible（`:2361-2371`），而示例原先写在
  `El::resizablePanel(...)->onResize()` 上——一颗静默失效的糖（#66 那个形状）。
  ② **投递已接**：`cmd/patch-select-view.sh` 把 `DispatchNumbers(const float*, int)`
  在补丁里拼成 CSV 交给 `gpuiEnqueueString`，所以载荷会是 `"236,552"` 这样的串。
  ③ **触发不了，而且是物理状态**：新加了 `App::realDrag()`（`gpui_shim_real_mouse`
  的 type 2/3 = 只按下/只抬起），落点也确实是那颗把手
  （命中表 `2:57,357x9x64/p1`，起点 61,389），读数却是
  `按下=true 抬起=true 移动入队=0 split='—'`。因为 mac 层"此刻按着哪个键"读的是
  `[NSEvent pressedMouseButtons]`（`window_mac.cpp:511-520`；`mouseDragged:` 只是转给
  `mouseMoved:`，`:701-703`），合成事件伪造不了它 ⇒ gpui 造不出 `DragMoveEvent`，
  `ResizableState` 那台状态机（`base/resizable.cpp:126-198`）也不会在抬起时发 `onResized`。
  **这一格与 #56 同源：只有真人 HID 能验**，所以 `resize` 仍然记在"送达未量"。
`on_step` 同日也量到了，但它多一个前提：**那颗按钮得由宿主填内容**。
`NumberStepButton`（`materialize.cpp:960-983`）造的是 `Button::New(cx, id, …)`——
第二个参数是 **id 不是标签**，样式与内容一律从 `decrement_button`/`increment_button`
那两个装饰槽取（`IsRegisteredCommonSlot` 列着这两个名字）。槽不给内容就是一颗空按钮：
`paint.hits` 里 0x0、像素上不落字，于是"送达为 0"量的根本不是链路。
`examples/_shared/features/inputs.php` 现在把两颗槽填成 `－`/`＋`，读数：

```
  ok   stepDelivered — 命中表=10 条 [...] 落点索引=4 lastStep='decrement' elementValue(in-num)='decrement'
```

**`on_step` 的值语义（2026-10-25 起）**：`on_step` 一旦声明，上游**内建步进就关闭**——
materialize 只在 `!behavior.onStep && retained->number.hasStep` 时才把 `NumberStep*`
传给步进回调，声明了回调就是 `nullptr`。于是点 ± **只发事件、不改数**，值归脚本管：
handler 得自己 `text(id)` 读当前值、按载荷 `increment`/`decrement` 算出新值再
`setInput(id, …)` 回写 retained 实体，屏上数字才会动。只写 `ExampleState` 不回写的话，
事件链全绿、界面纹丝不动（这正是 bug B 的形状，`stepDelivered` 的判据现在含
`text('in-num')` 按 ±1 方向变化的断言）。

内建动作派发这一条已经有了确定的结果（2026-10-13，同一支探针里量的）：
开着 dialog、焦点陷阱生效之后 `dispatchAction('ui::Cancel')` 会把框关掉——
读数是 `cancelGate trap=195604379 dispatched=true closed=true`，
而且它不依赖任何输入，所以已经进 `--selftest` 当硬门（负向对照：把 shim 里那次
"按原名字再解析一遍"的回退路摘掉，就变 `dispatched=false closed=false`、rc=1）。

它读的那几件事现在各自有硬门主人：`open_change`/`confirm`/`dismiss` 在 `examples/choices`，
`step` 在 `examples/input`，`ui::Cancel` 关框在 `examples/overlay`，指针族送达与 CSV 值形状在
`examples/text`。唯一还**没有**硬门的是 `resize` 的送达——`examples/layout` 里那条是
`(读数)`，不参与 rc，因为它要的是物理按住拖动（见 #56）。

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

返回值 = "这一趟 PHP 队列里确实多出了事件"。示例的 `--selftest` 用它断言三件事：
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

`examples/chrome` 的导航验收因此是表驱动：先量出每一行各自的矩形，再逐行点自己的中心，
断言"13 条矩形、13 次都进 PHP、到达 13 个不同节号"。

最后那种成因踩过一次真的：渲染过带 WebView 的那一节之后，侧栏上面 6 行再也点不动，
而 `hitProbe` 里 13 条矩形、`click=1` 全都在——因为 `ChildView` 背后那颗 WKWebView 是**常驻的
原生 NSView**，元素从树上换掉之后它仍挂在窗口上、停在最后一次的矩形里，AppKit 的 `hitTest`
会先命中它，gpui 的元素从此收不到那一片的鼠标。现在 shim 每帧 materialize 前后各扫一次
（`WebViewFrameVisibility`）：这一帧没渲染到的那颗就 `hidden=YES`，AppKit 会跳过隐藏视图，
鼠标就还给下面的元素了。

**右键菜单（ContextMenu）没有验收缝**：`El::contextMenu()` 的右键接线在上游
`ContextMenuState::OnMouseDown`（Bubble 相位 + stopPropagation），但合成缝
`gpui_selftest_fire` 只认 click/change/hover 三类（没有 mouse_down_right），
`realClick` 也只发左键——所以 kit selftest 里它是**前提式门**：断"合成路不存在"
（fire 返回 false）这个事实本身，真右键行为要真人 HID 验收（同 Clipboard 复制的先例）。

## 2. 保留态取值（Input / Slider）

带 id 的 input/textarea/numberInput/otpInput/slider 在 render 时建 retained 实体（跨帧稳定）。
**id 必须在节点的 `id` 字段上**：`El::input('name')` 与 `El::input()->id('name')` 现在等价；
建不出 retained 时 materialize 是 `return Div(cx->a)`——不报错、`registeredError()` 也是空的，
只是这颗控件静默变成一段文字（不聚焦、不能打字、点它没事件）。事件只带
RetainedEntry 本地 id，shim 反查回 elementId + 读当前值入队：

```php
$app->render(El::input()->id('name')->placeholder('Your name')->onChange());
$app->on('name', 'change', static function () use ($app): void {
    // 读 **state 的当前文本**，不读事件载荷：合成 change（automation 的 change 动词、
    // selftest 的 fire）带过来的值是 true，信载荷会把界面画成 "Hello, 1!"
    // （2026-10-22 真窗口截图实测）。参考 gpui-kit-cpp 的 examples/input.cpp，
    // 它的 OnChange 读的也是 InputCStr(&state)。slider 没有 state 取值口，只能信载荷。
    ExampleState::$v['name'] = $app->text('name');
});
$app->setInput('name', 'prefill');
$text = $app->text('name');
```

## 3. 定时器

```php
$app->on('tick', 'timer', static function (): void { ExampleState::$v['ticks']++; });
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
    if ($r !== null && $r['ok']) { ExampleState::$v['json'] = $r['body']; }
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
    ExampleState::$v['savedClosed'] = true;
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
- **两条通知路**：以上是 `App::notify()`（宿主保留态，`notify()` 传 `$id` 才接线）。
  还有 catalog 组件版 `El::catalogNotification($id, $label, $message, $type, …)`
  （window_effects/mod.cpp 的 `Notification` 导出名，factory 见[组件 · kit 工厂批](/guide/components)），
  它是纯组件层的同语义节点——构造三实参 id/label/on_effect_error，message/type/
  on_click/on_close/autohide 走方法糖。两条路各自有自己的事件接线，不要混用宿主
  `push` 的保留态语义。

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

菜单有三条来源，动作回到 PHP 是同一件事件、同一个写法：

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
- **③ 批2（2026-10-30）新增：catalog 的 NativeMenu 行**——`El::nativeMenuTrigger(...)`
  里挂 `El::nativeMenuItem('复制路径', 'kit-nm-copy')`，点触发器弹 OS 原生菜单
  （keyed event effect），行选中派发 ShellAction，反查缝与上面两条是同一个
  （`gpuiAppActionHandler`，cpp-src/gpui_shim.cpp:293-302），事件 id 就是 action 名。
  与 ①② 的差别：触发器在**窗口内容里**，真点一下触发器再选行是两次真人交互
  （NSMenu 行选择是模态会话，合成点击够不到）——kit 域 selftest 只断工厂形状与
  `$app->on($action, 'action', …)` 的接线，送达证据留真人验收。

**④ 批2 A5（2026-10-31）新增：元素级 `on_action`**——`El::text(...)->id('src')
->onAction('kit::copy')`，op 的 name 就是动作名（materialize 经 `ShellActionOf`
intern，与键位表/菜单动作同表），挂 `element->OnAction` 后 `dispatchAction()`
或键位命中焦点链上的监听器，DispatchArgs 的 shim 分叉把事件还回队列
（`cmd/patch-host-action-cb.sh`）。处理器写法同上（`$app->on('src','action',…)`），
队列短名固定 `'action'`；kit `actionDeliveryGate` 的 fire_registered 硬臂
（op 挂表+队列+PHP handler）实测全绿，`dispatchAction` 臂在合成态无真人焦点时
到不了监听器 → advisory（bindKeysGate 同款）。限制：同一元素多次 `onAction`
共用同一队列通道——多动作分到不同元素。

`--selftest` 里对应两条门：`rows=st-new/rowsOk=true`（表里装的确实是 PHP 声明的 id）与
`action=true/dispatched=true`（该 id 的动作回到了 PHP）。原生菜单行的"真点一下"进不了门禁
——`realClick` 是进程内把 `NSEvent` 投给自己的窗口，够不到系统菜单条那个窗口，
所以这一跳拆成上面两段取证。

## 真输入还差一步：应用得是"前台应用"

```bash
php bin/gpui run input --wait-key 150     # 人按一下，命令自己给结论
```

`--wait-key [秒]` 是给 #56/#62 那一格用的：屏幕上写明“焦点已自动放到输入框、直接按几个
字母键、最后按 Escape”（焦点由程序预放，不用人先点窗口），窗口里逐帧把
`key_down / key_up / click / change` 与 `active / key / 在屏 / 焦点 id` 的读数画出来，
按到键就多收 8 秒让
Escape 与连续按键都进读数，最后打印一行 `WAIT-KEY …` 并以退出码给结论（0=按到了，1=没按到）。
为什么仍要人验：前台归属是跟 IDE/终端**竞争**来的，自动化不保证拿到——用 Qoder computer use
（无障碍层）量过一轮，`click(elementIndex)` 能穿过 AppKit → gpui → 补丁 → PHP
（trace 里 `click id=nav-2`），但 `pressKey`/`typeText` 之后 HUD 仍是"按键 0"，
这次 AX 点击没有取得所需的激活状态；不能把单次观测推广成自动化永远无法激活窗口。键盘派发仍需合适的 key window 与 responder。
所以这一格既不能当硬门（OS 竞争态），也不该让人去猜"我这一下到没到"——命令自己说。


上面讲的都是"事件怎么路由"，但从 WindowServer 进来的真鼠标/真键盘在此之前先过一道系统门：
`NSApp.isActive` 与 `window.isKeyWindow`。这两个数不成立时——第一次点击被系统当成"激活这扇
窗口"的点击吃掉，`keyDown` 根本没有 firstResponder 可去——现象是**代码全绿而手里点不动**。
`App::windowState()` 就是把这四个数读出来（`active/key/visible/main`），外加
`bundled`（进程有没有 `.app` 身份）、`nsappDeliveries`（事件缝里走过真
`postEvent → nextEventMatchingMask → -[NSApp sendEvent:]` 的条数）、
`appkitDeliveries`（AppKit 派发到本进程的 keyDown/leftMouseDown 条数，local monitor 计数）、
`onScreen`（WindowServer 的**在屏账本**里有没有这扇窗）与它的自证臂 `onScreenPeers`
（同一账本的在屏窗口总数）。

`php bin/gpui run` **默认跑裸二进制**（`--bundle` 才走 `.app`），`selftest` 仍走 bundle
——它断的是"产物有没有 bundle 身份"这件产物属性（`--expect-bundle`）。
**裸这扇窗只能靠真人的物理一下**：它可被合成、`active/key` 也真，但外部把前台交给它会被拒
（`System Events` 的 `set frontmost` 报 -10006），所以 `keystroke` 注入打在别的应用上；
物理点击由 WindowServer 做命中测试，点下去 session 前台才归我们。
改成默认裸二进制的理由是一条同秒实测：同一份字节 `裸 onScreen=true / bundle onScreen=false`，
而旧说法"裸二进制起不来 `isActive`"在前台重试那条补丁之后已不成立（裸也 `active=true key=true`）。
**bundle 身份只买到"自报"那一半**：
实测 `.app` 起的应用可以 `active/key/visible/main` 全真而 `onScreen=false`——窗口在窗口服务里有号、
有画面，却从不上屏，于是真人键鼠零送达（`--shot` 走进程内 `cacheDisplayInRect`，照样出正确 PNG，
所以像素门也看不出来）。拿到在屏资格要两条同时成立：**`Contents/MacOS/<exe>` 是正规文件**
（符号链接会让 `codesign` 拒签）**且 bundle 的签名把 `Info.plist` 绑进去**
（`codesign -dvv` 出 `Info.plist entries=N`）。**这两件是必要条件，不是充分条件**：
满足后本机 `onScreen` 仍会飘（同一产物两次 true、隔 20 分钟连五次 false），
所以"人按不动"要先读 `onScreen` 再说别的。反过来裸二进制在本机 `onScreen=true`
——两件事各自独立，别拿其中一个当另一个的替身。常驻检查是门 14（`composer check:bundle`）。
过去的画廊在非前台时还会直接把这条画在窗口顶上
（`front` 状态，每帧由 `windowState()` 量出来），"是不是系统那一步没成立"不必靠猜。

## 首帧的命中表：为什么"刚启动那几下"点不进去

真实点击要先过 hit-test（gpui 的 `paint.hits` 矩形表），再轮得到 behavior 解析与回调。
实测（画廊时代）：`--liveclick` 里只跑 1 帧时，38 个采样点产生 **0** 个事件；跑到第 2 帧起稳定 18/38
且 `nav-*` 真的到达 PHP。也就是说首帧把画面画出来了，却没把命中表建起来——用户看到界面正常、
点了没反应，就是这一格。今天这一格由 `examples/chrome` 的 `navRowsReachable` 把住：它**只跑一帧**
就逐行真点，点不到就 rc=1。

`App::view()` 因此**注册时就先 materialize 一遍**（`run()` 也走这条路），把命中表在第一帧就
备齐；对应的回归位是 `examples/chrome` 的 `navRowsReachable`（只跑一帧就要逐行点中，
首帧点不动就 rc=1，CI 的域循环已经跑到它）。
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
画廊的 `--livetyping`（已随画廊删除）两种都钉住，读数 `key_down=[in-key] value=[—1]`——那个 `1` 就是 keycode 18 的键名。

这条路由 `examples/input` 的 `--selftest` 逐颗输入框扫描：`realTyping` 的读数是
`index→keyPressed=… 打进字的索引=… name=…`（判据是**至少一颗**打进字——`typedIdx>=0`
即绿；CI 上焦点是竞争态，不苛求每一颗都收得进字符）；挂了 `onKeyDown` 的那颗由
`keyDownRoute` 另断（读数 `key_down 落点索引=… key=…`）——它们同时是回归位：键路再断，这两条就红。

顺带一条同类陷阱：`El::vFlex([...])->itemsStart()` 里的按钮只占**文字宽度**，不是整行。
画廊时代实测的导航可点带只有 x=12..132（侧栏宽 210），点行右半截什么都没发生；
给满 `->w(194.0)` 之后实测 12..196。今天由 `examples/chrome` 的 `navRowsReachable` 门
（w≥150 的像样行 ≥3）盯着这件事。

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
| 每轮 `loop()` 的首帧 | `view()` 已先 materialize，循环仍需完成一帧可交互的绘制 |
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
