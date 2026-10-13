# 与上游 gpui-kit 的能力对照

本项目链接的是 gpui-kit 的 C++ 移植树（`gpui-kit-cpp`），概念与 API 名称以
[gpui-kit.com/docs](https://gpui-kit.com/docs/) 为准。这页回答两个问题：

1. 上游文档里描述的能力，**PHP 侧到底能不能用**；
2. 不能用的时候，是**没做**、**走了另一条路**、还是**做了但不生效**。

判定口径只有一条：一个能力算"已实现"，当且仅当 PHP 能发出**上游认得的符号**
（kind 名、catalog 导出名、op 名）。上游对不认的名字有两种下场，见第 5 节 —— 
"发了但没生效"和"没发"现象一样，所以必须分开列。

本页的数字来自脚本抽表（不是人肉数数）：上游 descriptor 表、`ComponentKind` 枚举、
`materialize.cpp` 的 op 名链，与 `src/Element.php` 的糖逐个对比得出。

## 1. 组件面

| 面 | 上游 | PHP 可达 | 说明 |
|---|---|---|---|
| `ComponentKind` 枚举值 | 66 | 46 | 46 项在 `kKindNames`；`Registered`/`ChildView` 走专用 ABI；剩 18 个 PHP 表达不了 |
| catalog 组件 | 126 个 descriptor / 137 个导出名 / 301 个方法 | 22 个有专用工厂；其余经 `El::catalog(导出名, 构造实参)` 可达 | 通用工厂是 2026-10-13 补的。没有专用工厂的那批仍缺 PHP 侧的实参提示与枚举预校验，名字写错要靠 `App::registeredError()` 才看得见 |

**同一组件的两张脸**：base 的 `ComponentKind::Button` 只有点击与文本；画廊里带圆角、底色、
变体的那个是 catalog 的 `Button`（13 个方法：`label tooltip loading size outline primary
secondary danger success warning ghost link compact`；注意它**没有** `variant` 方法，
`variant(enum)` 属于 DropdownButton / Tag / Bubble / Marker / GroupBox / TabBar）。
我们的 `El::button()` 走前者，`El::catalogButton()` 走后者。
35 个导出名有同名 kind，但**能力不等价**——kind 版没有 catalog 版的方法表。

**已经能用的那批**（`El::catalog()` 一通就有）：Accordion 家族、Pagination、Rating、
Stepper/StepperItem、TabBar、Settings 四件套、Tree/TreeItem、Separator 三件套、Badge、Tag、
Label、Kbd、Toolbar、StatusBar、Skeleton、Spinner、ShimmerText、Chat 家族、Empty 六件套、
InputGroupText、五种图表（Area/Bar/Line/Pie/Radar）、DropdownButton、GroupBox、
DescriptionList/Item、CommandGroup/Item/Separator、NativeMenu 三件套、Resizable、Clipboard、
Questionnaire 家族、TableFooter、VForm —— 但**没有专用工厂就意味着没有文档化的实参形状**，
用之前先对着上游 descriptor（`php cmd/upstream-vocab.php` 能打印）。

**还需要上游 entity 状态句柄的 16 个**（`SchemaEntity("CommandState"/"DataTableState"/
"EditorState"/"DiffState"/"MessageScrollerState"/"CarouselState"/"CalendarState"/
"TimeFieldState"/"ColorPickerState"/"ScrollbarHandle"…)`，我们目前没有造句柄的 API）：
Command、DataTable、Diff、Editor、MessageScroller、Scroll、Carousel 六件套、
QuestionnaireInput、ColorPicker、Calendar、TimeField。

**看起来缺、其实走了别的路**：Dialog / AlertDialog → `App::openDialog()`；Sheet →
`App::openSheet()`；Notification → `App::notify()`；Tooltip → 元素上的 `tooltip` op；
剪贴板 → `App::clipboardText()` / `setClipboardText()`；FpsMonitor → `App::showFpsHud()`。

## 2. kind 表里表达不了的 18 个枚举值

Accordion、AccordionHeader、AccordionPanel、AccordionTrigger、AvatarImage、AvatarFallback、
DockArea、DockContent、FpsMonitor、HVirtualList、VVirtualList、UniformList、Pagination、
PathFill、PathStroke、SliderTrack、SliderIndicator、SliderThumb。

其中 Slider 与 Accordion 两族是"**父建得出来、子建不出来**"：我们有 `slider` 与
`accordion_item`，却没有 track/indicator/thumb 与 accordion/header/panel/trigger，
写出去的树在上游是残缺的。
另有三项在表里却**全仓无工厂、无示例、无测试**：`scrollbar`、`table_row`、`table_cell`。

## 3. op 面：上游认 101 个名字，我们的糖只发得出 17 个

`method()` / `callback()` / `style()` / `paramStyle()` 都是 private，所以可发的 op 名 = 糖的集合。

已接：`id` `disabled` `selected` `checked` `value` `size` `tooltip` `href`
`overflow_scroll` / `overflow_x_scroll` / `overflow_y_scroll`，回调 4 个
（`on_click` `on_change` `on_hover` `on_key_down`）。

**没接的整族**（都在 `materialize.cpp` 的行为链里，上游文档各自有专页）：

| 族 | 名字 | 上游文档页 |
|---|---|---|
| 焦点与无障碍 | `track_focus` `content_focus_handle` `tab_stop` `tab_index` `role` `aria_label` `aria_selected` `aria_level` `aria_active_descendant` `key_context` | focus / accessibility |

（`track_focus` / `content_focus_handle` 这两个自 2026-10-13 起由 bridge 为 Select 发出；
**元素级的 PHP 侧还没有糖**，其余九个也还没接。）
| 浮层行为 | `open` `default_open` `overlay_closable` `open_delay` `close_delay` `on_open_change` | — |
| 事件 | `on_key_up` `on_mouse_down_*` `on_mouse_up_*` `on_mouse_down_out` `on_mouse_move` `on_scroll_wheel` `on_confirm` `on_dismiss` `on_step` `on_resize` `on_item_click` `on_item_secondary_click` `on_link_click` `token` `on_token_click` `on_token_hover` | event |
| 动画 | `transition(prop, ms, delay, easing)` `spring(prop, response, damping, epsilon)`，可动画属性只有 `opacity/w/h/left/top` | animation |
| 自定义绘制 | `move_to` `line_to` `curve_to` `cubic_bezier_to` `arc_to` `close` + `PathFill`/`PathStroke` | paint |
| 表格结构 | `row_count` `column_count`（另需 `Component.index`，我们的 C ABI 没有这个槽位） | — |
| 滚动控制 | `mode` `scroll_size` `viewport_from_layout` `overflow_scrollbar` / `_x_` / `_y_` `track_scroll` `with_item_to_measure_index` | — |
| 杂项 | `pressed` `indeterminate` `start` `controls_right` `panel_visible` `panel_size` `size_range` `set_position` `keep_mounted` `selectable` `scrollable` `mouse_button` `frame_budget` `axis` | — |

样式词表：上游 `style.cpp` 的 nullary 关键字有 **137** 个，我们发了 **25** 个且**全部在册**
（0 个拼错）；`grid`、`self_*`、`content_*`、`whitespace_nowrap`、`text_ellipsis`、`line_through`、
`aspect_square`、`border_dashed`、`text_xs…3xl`、`font_thin…black`、`cursor_*`、`debug*` 没有糖。

## 4. 当前已知不生效的 API（文档写了但别当能用）

`El::primary()` / `secondary()` / `danger()` / `ghost()` / `outline()` —— **2026-10-13 已修**。
它们以前发的是 `method('variant', [...])` / `method('outline')`：上游行为链两个名字都不认，
带参未知名落在静默丢弃那一格，于是五个糖一个像素都不改变（旧基线
`tests/baseline/showcase/tree3.png` 里六颗按钮无差别就是现场）。现在它们发 catalog
`Button` 真正的零参方法（`registered_method: primary/ghost/…`），并且**只**对 catalog 节点开放，
打在 kind 节点上抛 `InvalidArgumentException`。同一批修掉的还有 bridge 里写错的
`"focus"` / `"content_focus"`（正确名字是 `track_focus` / `content_focus_handle`，
Select 的焦点句柄此前从未真的交出去过）。

`El::table()` 系列不是语义表格：我们从没发 `row_count`/`column_count`，`table_row`/`table_cell`
没有工厂，且 `TableRow`/`TableHead`/`TableCell` 的构造要读 `component.index`
（`materialize.cpp:1838-1842`）而 `gpui_shim_push(win, kind, text, value, id)` 没有 index 槽位。
示例里的"表格"实际是 Table 节点塞文本子元素。像素基线是自录的，所以这条不会红。

## 5. 为什么"发了没生效"是可能的：静默与响亮的分界

上游在记账时就决定了这个 op 会不会报错（`runtime.cpp:3700-3703`）：

- 未知**零参**名 → 记成 `NullaryStyle` → `materialize.cpp:2576` 写错误（**响**，`gpui_last_error` 读得到）；
- 未知**带参**名 → 记成 `Method` → `ResolveBehavior` 的 if/else 链在 `materialize.cpp:451-454`
  **以无 else 收尾**（**静默丢**，没有任何通道）；
- 带参但拼错的样式名 → `materialize.cpp:2580` 记 `invalid style call`（响）。

对照另外两条路：kind 名不认我们已改成拒收 + `lastError`；catalog 名/实参错走 `request->Fail`，
`App::registeredError()` 读得到。**第三条哑路（op 名）由 `cmd/check-upstream-parity.php` 盯着**：
它从 vendored 的上游树抽 kind / op / 槽名 / catalog 四张表，再扫我们源码与 bridge 里硬编码的
每一个名字，有对不上的就红（`composer parity:upstream`，也在 CI 与 Pest 里各挂了一处）。

## 6. 上游文档的概念页 → 我们的对应物

| 上游页 | 状态 | 我们的对应物 / 缺口 |
|---|---|---|
| installation / getting-started / packaging | 🟡 | `php bin/gpui build` + bundle；打包见 [CLI](./cli)，无 auto-update |
| window / multi-window | 🟡 | 有 `App::createWindow()`、`windowState()`，文档此前没写 |
| entity / context / render / render-once / task / global / element-id / view-cache | ⛔ 概念不对应 | Rust 侧写视图的抽象；我们换成"PHP 数组 + 每帧重算 + 脏驱动"，见 [架构](./architecture) |
| style | 🟡 | 参数化样式覆盖较全；nullary 关键字 25/137，见第 3 节 |
| focus | ❌ | 焦点 op 一个没发 ⇒ dialog 的焦点陷阱从未成立（已知 bug #62 的根因） |
| action | 🟡 | `App::dispatchAction()` + 菜单动作路由已通；无 `bind_keys` 式键位表 |
| keybinding | ❌ | 只有系统级全局快捷键（`onHotkey`），没有应用内键位/和弦 |
| event | 🟡 | 24 个回调接了 4 个 |
| animation | ❌ | `transition`/`spring` 整族未接 |
| paint / geometry / text-system | 🟡 | 布局与文本可用；无路径绘制（PathFill/PathStroke 未接） |
| accessibility | ❌ | `role`/`aria_*`/`tab_stop` 未接 |
| image / assets / fonts | 🟡 | 内嵌图标表可用（`El::icon`，路径写错静默不画）；无字体装载、无 http 图片策略 |
| i18n | ❌ | 无 locale 机制 |
| system-notification | ✅ | `App::notify()` + OS 通知（见 [系统能力](./system-capabilities)） |
| webview | ✅ | WKWebView 经 ChildView 挂载，含可见性回收 |
| native-extension | ⛔ 不适用 | 我们就是宿主，不需要脚本侧 host module |
| webassembly / mobile | ❌ | 只出 macOS/原生桌面产物 |
| test / fps | ✅ | `--selftest` / `--shot` / shotdiff / `showFpsHud()`，见 [测试与门禁](./testing) |
| design-guides / coding-guides / comparison | 🟡 | 设计令牌走 `App::setTheme()`；语义 token 表未暴露给 PHP |
