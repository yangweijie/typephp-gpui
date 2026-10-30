# 与上游 gpui-kit 的能力对照

本项目链接的是 gpui-kit 的 C++ 移植树（`gpui-kit-cpp`），概念与 API 名称以
[gpui-kit.com/docs](https://gpui-kit.com/docs/) 为准。这页回答两个问题：

1. 上游文档里描述的能力，**PHP 侧到底能不能用**；
2. 不能用的时候，是**没做**、**走了另一条路**、还是**做了但不生效**。

先检查 PHP 能否发出**上游认得的符号**（kind 名、catalog 导出名、op 名），再检查
构造、materialize、事件/取值与像素证据。**符号在册只是必要条件，不等于完整实现或交互验收。**
上游对不认的名字有两种下场，见第 5 节；“发了但没生效”和“没发”现象一样，必须分开列。

本页的数字来自脚本抽表（不是人肉数数）：上游 descriptor 表、`ComponentKind` 枚举、
`materialize.cpp` 的 op 名链，与 `src/Element.php` 的糖逐个对比得出。

## 1. 组件面

| 面 | 上游 | PHP 可达 | 说明 |
|---|---|---|---|
| `ComponentKind` 枚举值 | 66 | 51 个普通 kind 名（2026-10-28 批3 补 accordion_header/trigger/panel；2026-10-30 批3 补 accordion 根——shim 词表一行 + `El::accordion()`），另有专用 ABI | `Registered`/`ChildView`/`DockArea` 不靠普通 kind 表构造；不能用 51−66 判实际缺口 |
| catalog 组件 | 146 个导出名 / 161 个去重方法名（原树 137/158；9 个导出名——批5 的 ProgressCircle/PopupMenu/ContextMenu + 批6 的 CandlestickChart/SankeyChart/SearchableList + 批7 的 Typewriter/GradientText/AnimatedNumber——由沙箱补丁 cmd/patch-*.sh 补） | 专用工厂或 `El::catalog(导出名, 构造实参)` | 通用工厂不替代实体与同步回调。没有专用工厂的那批仍缺 PHP 侧的实参提示与枚举预校验，错误由 `App::registeredError()` 回读；去重方法数不能与各 descriptor 的方法出现次数混用 |

**同一组件的两张脸**：base 的 `ComponentKind::Button` 只有点击与文本；画廊里带圆角、底色、
变体的那个是 catalog 的 `Button`（13 个方法：`label tooltip loading size outline primary
secondary danger success warning ghost link compact`；注意它**没有** `variant` 方法，
`variant(enum)` 属于 DropdownButton / Tag / Bubble / Marker / GroupBox / TabBar）。
我们的 `El::button()` 走前者，`El::catalogButton()` 走后者。
35 个导出名有同名 kind，但**能力不等价**——kind 版没有 catalog 版的方法表。

**专用工厂与通用工厂的分界（2026-10-28 更新）**：kit 工厂批给 16 组组件补了专用工厂——
Separator 四件套（含可嵌字 label）、Tooltip（触发器组件）、Skeleton、Empty 五件套（组装式）、
Badge/Tag（含 `->variant()`/`->pill()`）、Stepper/StepperItem、Tree/TreeItem、Pagination、
Rating（readOnly 走 disabled 位）、Kbd/KbdCombo、ShimmerText、Dialog/AlertDialog/Sheet
（真按钮触发的原生窗口效果，Dialog/Sheet 必带 content 具名槽）、DescriptionList/Item、
DataTable（**结构已接、数据通道未通**，见下）、Settings 四件套、Carousel 八件套（组装式）——
签名、片段与坑见[组件 · kit 工厂批](./components#kit-工厂批-tier-a-组件的专用工厂)。
同日的「纯工厂热身」批再补五颗纯 reg() 通道的：Spinner（icon/ease 是私有枚举，各有
白名单糖）、TableFooter（**只有 registered 一条路**——ComponentKind 枚举里没有它，
行/格必须用注册侧 TableRow/TableCell）、GroupBox（variant 集 normal/fill/outline，
已并进 `->variant()` 白名单）、VForm（`'Form'` 同一构造器的第二导出名）、
InputGroupText（整个 InputGroup 组合今天拼不齐，装饰件独立渲染；见组件页）。
批2 再补六颗带前置作业的 tier-A 尾洞工厂 + 一个组合糖：Label/Toolbar/StatusBar/
Clipboard/HorizontalRadioGroup 与 SplitButton（组合形，非独立导出；SplitButton 是
DropdownMenu 的组合糖）。前置作业结论：Label 的 highlights 实参是 SchemaString
（单个匹配串，不是清单猜的数组）、StatusBar 的 left/right_content 吃 SchemaElement
（shim 缺口，只发中段）、Clipboard 的复制是部件内置行为（行为门前提式）、
HorizontalRadioGroup 只收注册侧 Radio。
批4 补嵌套结构与第二张脸：Questionnaire 四件（根→Item→Choice/Input 四层嵌套，typed children
逐层校验；事件面核对过——无选项变化回调，焦点/导航由 retained state 内部驱动，无需新 patch）、
catalog 版 Accordion（multiple/bordered/size/on_toggle；title 是 SchemaElement shim 缺口，
按项数建、不发 title）、catalog 四颗升级糖（Select/Combobox 的 rows 是"返回数据"回调
——只建结构、真数据等批6；Popover 的 content 走具名槽；TabBar 只收注册侧 Tab；
catalogHoverCard 的 trigger_element（2026-10-30 批2 订正：原句"硬缺口不进树"失真——
批5 的 element_arg 桥已把 SchemaElement 方法实参打通用，工厂加 `?self $trigger`
经 `->elementArg('trigger_element', …)` 喂入、组件可进树；上游对缺 trigger 的处置是
每帧 loud Fail "HoverCard requires trigger_element(element)"（hover_card.cpp:79-82），
是硬要求不是静默缺口）；签名里带 bool/int 默认值的可选参数
一律收实例糖——TypePhp 翻译器在混合默认值+实参不完整的签名上会生成默认值 re-assign
缺陷，示例直接翻译失败，实测）。
仍走 `El::catalog()` 通用通道的是：Accordion 根（`El::accordionItem()` 走的是 base kind 三段件，2026-10-28 批3）——批2（2026-10-30）把 CommandGroup/Item/Separator、NativeMenu 三件套、Resizable/ResizablePanel 三组从这份清单上摘掉（专用工厂 + typed children 校验已补；NativeMenuTrigger 的 on_effect_error 是 materialize 硬要求，工厂把错误回调 id 收成必填第三参；catalog 版 Resizable 的 `size`/`visible` 与 kind 面同名糖撞名，按别名惯例出 panelSize/panelSizeRange/panelVisible）——但**没有专用工厂就意味着没有文档化的实参形状**，
用之前先对着上游 descriptor（`php cmd/upstream-vocab.php` 能打印）。SplitButton 是组合糖（内部发 DropdownMenu，身份见[组件 · kit 工厂批](./components#kit-工厂批-tier-a-组件的专用工厂)）不属于 catalog 导出名。批3 补上的 Notification/DropdownButton/Bubble/Marker/Message/MessageScroller/Attachment 与图表五连、批4 的 Questionnaire/Accordion/Select/Combobox/Popover/TabBar 都已出专用工厂；
批5（第一次越出 PHP 层的批——上游改动全部经 cmd/patch-*.sh 幂等补丁落进**沙箱**
build/gpui-kit-cpp，upstream/ 原树只读）又补四颗：ProgressCircle（widget 早随 vendored
上游在树，只差 descriptor——compound/progress.cpp 五件套复刻）、PopupMenu/ContextMenu
（widget 层在树、descriptor 全缺——新建 overlays/popup_menu.cpp，rows 走普通子节点）、
EmptyHeader 的 media/title/description（shim 的 SchemaElement 实参缺口——
cmd/patch-element-arg.sh 给 bridge 加 element_arg op，`->elementArg()` 由此可喂，
emptyState 加 `$headerSlot=true` 开关走上游原生 header 槽路）。裸通道批2 后只剩 Accordion 根一组。
顺带的词表可见面修正：提取器此前认不出"具名 constexpr 方法描述符按名收进数组"的写法
（window_effects 的 `kDialogMethods={kTitle,kOnOk,…}`、图表与 Scrollbar 家族同款），
kit 工厂批的 `on_ok`/`on_close` 让它假红，修复解一层引用后去重方法名 148→**157**，
其后树同步与批5/批6/批7 补丁把总数带到 **161**（本页数字按 `php cmd/upstream-vocab.php` 现算；
沙箱侧 146 个导出名里 9 个是补丁加的）——
新增的 13 个里 PHP 真正发的是 `on_ok`/`on_close`/`pad` 三个，其余 10 个
（`grid`/`linear`/`natural`/`step_after`/`tick_margin`/`x_axis`/`scroll_axis`/`show_values`/`speed`）
是图表与 Scrollbar 家族顺带浮出的名字，PHP 侧一个都没发。批6 的三颗（CandlestickChart/SankeyChart/SearchableList）走数据回路正路，rows 回调返回真数组。批7 的三颗（Typewriter/GradientText/AnimatedNumber）是唯一从零写 C++ widget 的批，特效族视觉验收只能逐帧快照/真人。

**需要上游 entity 状态句柄的那一族，现在按可数的口径说**（旧句子写的是"还需要 16 个"，
那是 #81/#82/#84 交付之前列的清单，已失真——`check-upstream-parity --info` 打出来的真值是：
上游 `SchemaEntity` 一共声明 **15** 个 state kind）。
`php cmd/check-upstream-parity.php` 的**判定**只看 `src/`（**13** 个：InputState、OtpState、
SliderState、CalendarState、ColorPickerState、DatePickerState、TimeFieldState、
TextareaState、EditorState、DiffState、CommandState、CarouselState（kit 工厂批的
`El::carousel()` 经 El::state 使用）、DataTableState（同批的 `El::dataTable()`））
——不是因为示例侧没用，而是因为 `examples/input` 的 selftest
故意留了一颗 `NoSuchState` 当反向对照（`catalogStateFailureVisible` 门），把它一并判定
就红成设计而非红成 bug。
示例侧（含 `examples/_shared/**`）另外出现那颗对照用的 `NoSuchState`，报告里单列一行不参与判定。
**所以真正的剩余缺口是 0 个**（2026-10-30 批3 订正：上一句原写"只剩 1 个——ScrollbarHandle"，
批 3 的 `catalogScrollbar()`/`catalogScroll()` 工厂把它接上了——ScrollbarHandle 的
creator 是无参的（scroll.cpp:153-158 NewHandle 忽略实参），`gpui_shim_state` argc=0
直通 NewHostState 实测可用（kit selftest 的 scrollbarHandleProbe 门：实体 +2、
registeredError 清零、共享 handle 的 always 条像素对拍通过）。
再往前一轮的订正注："原来写 2 个——MessageScrollerState 根本没接"也是失真旧句，
`MessageScrollerState` 早随批 3 的 `El::messageScroller()` 接进了 `src/`；词表门是抽表
对比不是文本搜索。）MessageScrollerState 没通的 render_item 行渲染半边见下。
**造句柄的 API 已于 2026-10-14 打通**（#81：`cmd/patch-host-state.sh` 给上游补了宿主入口
`ShellRuntime::NewHostState`，PHP 侧是 `El::state($kind, $key, $args)`，见
[组件 · 带样式的表单族](./components#带样式的表单族要先造一颗-state-实体-catalog-input-一族)）。
**带样式的表单族八颗已实测**（2026-10-14 #82：`El::catalogInput`/`catalogNumberInput`/
`catalogOtpInput`/`catalogSlider`/`catalogColorPicker`/`catalogCalendar`/`catalogDatePicker`/
`catalogTimeField`，覆盖 15 种 state kind 里的 7 种；`examples/input` 三条门 + 像素基线）。
第二族的 `TextareaState`/`EditorState`/`DiffState`/`CommandState`/`CarouselState` 也已有
实体创建、materialize 与像素验收（`examples/catalog` tree 2 与 `examples/kit` tree 2）；
第 15 颗 `ScrollbarHandle` 批 3 已接（Scroll 视口 + Scrollbar 条共享一颗实体，
`examples/kit` tree 1 小样 + scrollbarHandleProbe 门）；这不等于所有交互/取值均已验证。
**DataTableState 的数据半边已于批 6 接通**（2026-10-30 订正：下面这段原来写"shim 回调
通道不带返回值、数据通道升 tier B"，批 6 的 `cmd/patch-host-data.sh` 宿主数据回路落地后
已失真——rows 快照经 shimId 分叉同步回填，`App::on($id, 'render_rows', …)` 注册处理器
返回真数组；正/负路门在 `examples/kit` selftest 的 `dataTableRowsCallbackSuccess` /
`dataTableRowsCallbackLoud`，探针 `stateCounts()['dataTableFails']` 保留当负路证据）。
**render_cell / render_item 的元素半边已于批 4 接通**（2026-10-31 订正：下面这句
"元素回调是 SIGBUS 的实证禁区"是批4 前的准确描述——禁区指的是 **JS 路的
ElementId 分叉**；批4 的 patch-host-element-cb.sh 在 BuildComponentElement 加了
shimId 分叉，宿主元素描述经 emitTree 进帧 arena 物化，绕开该分叉。批3 时代
"SankeyChart 实测段错误"的崩点是快照记账误用 PhpView 实体（Get<ScriptView> 无类型
检查），批4 改走帧 arena 后消除）。MessageScroller 的 render_item 真渲染证据 =
kit selftest 的 messageRowRender 门（renderCalls>0、账本清零；行像素 advisory）。
render_cell 的机制同缝已通，示例未单独接（cellText 默认渲染仍是缺省）。

**看起来缺、其实走了别的路**：Dialog / AlertDialog / Sheet —— 2026-10-28 起**两条路都在**：
`App::openDialog()`/`openSheet()` 的保留态弹窗，与真按钮触发的 catalog 工厂
`El::dialog()`/`alertDialog()`/`sheet()`（窗口效果族，见
[组件 · kit 工厂批](./components#kit-工厂批-tier-a-组件的专用工厂)）；Notification 也是
两条路——`App::notify()` 与批 3 的 catalog 工厂 `El::catalogNotification()`
（2026-10-30 订正：括号里那句"导出名尚未出工厂"是失真旧句，工厂在
src/Element.php，message/type/on_click/on_close/autohide 走方法糖）；Tooltip 同样两条路——
元素上的 `tooltip` op 与触发器组件工厂 `El::catalogTooltip()`；
剪贴板 → `App::clipboardText()` / `setClipboardText()`；FpsMonitor → `App::showFpsHud()`。

## 2. 普通 kind 表之外的 15 个枚举值

ChildView、Pagination、AvatarImage、AvatarFallback、PathFill、PathStroke、
FpsMonitor、SliderTrack、SliderIndicator、SliderThumb、DockArea、DockContent、
VVirtualList、HVirtualList、Registered。（AccordionHeader/Panel/Trigger 三件 2026-10-28
起在 kind 表里——`El::accordionItem()` 的三段部件靠它们拼装；**Accordion 根 2026-10-30
批3 也进了 kind 表**——shim `kKindNames` 登记 + `El::accordion($id, $items)`，清单从
16 减到 15。）

这是**词表差集，不是 15 个不可用功能**：Registered 按 descriptor 构造，ChildView 由原生子视图
工厂使用，DockArea 有 retained Dock 专用 ABI，FpsMonitor 有窗口服务；Pagination
另有 catalog 表达。UniformList 已在普通表里，且 `list` / `uniformList` 均有宿主同步行 renderer。

base Slider/Accordion 未暴露全部低层子 kind，不要凭空写 track/header 等未知节点来拼树。
`table_row` / `table_cell` 已有工厂、示例和 index 门；`scrollbar` 的 **catalog 版**
（Scroll/Scrollbar 二件，共享 ScrollbarHandle）2026-10-30 批3 已出专用工厂
（`catalogScroll()`/`catalogScrollbar()`），kind 表里那颗裸 `scrollbar` 仍无 kind 工厂
——catalog Scrollbar 自己拒 children/泛 style（条由共享 handle 的视口画）。

## 3. op 面：声明、投递与完整交互分开计

`method()` / `callback()` / `style()` / `paramStyle()` 都是 private，没有任意行为名的公开逃生口。
`php cmd/check-upstream-parity.php --info` 当前核得 34 个 method 名（含 bridge 硬编码；2026-10-30 批1 前是 25，浮层行为四颗 + 高级滚动五颗 op 糖把 method op 词表带到 34）、24 个 callback 名。
已接身份/值/选中、`open`、tooltip/href、表格维度、overflow 滚动与高级滚动三态、浮层行为、无障碍与焦点元数据。
这些是词表数量，不是交互验收数量；catalog 的 registered_method 又是另一张 descriptor 表。

| 族 | 剩余缺口 / 验收边界 |
|---|---|
| 浮层行为 | 元素级糖已补（2026-10-30 批1：`defaultOpen`/`overlayClosable`/`openDelayMs`/`closeDelayMs`，走 kind 节点行为链；`open_delay`/`close_delay` 的消费端只有 HoverCard，`open_delay` 越界 0–60000 在 PHP 侧拒）；工厂级糖也已在（catalogPopover 发 default_open/overlay_closable、catalogHoverCard 发 open_delay/close_delay——registered 节点走 descriptor 的 registered_method 表，与元素级是两条通道）；`open` 与 `on_open_change` 已接 |
| 事件 | 24 个名字中 20 个有原生送达证据；resize 路由已接但物理拖拽未验，link/token 送达未量，详见[事件与回调](./events) |
| Token 渲染 | 批4（2026-10-31）已接：`token` 渲染回调经 patch-token-render.sh（RenderInlineToken shimId 分叉）由宿主出元素，事件 `on_token_click`/`on_token_hover` 送达已量化（tokenDeliveryRender 门）；chip 像素落位为 advisory（BoundsOut 多帧沉降，见 events 页）。造 token 的口 = `App::inputReplaceToken`（InputReplaceWithToken） |
| 动画 | 糖已补（2026-10-30 批3：`transition($property, $ms, $delayMs, $easing)` / `spring($property, $response, $damping, $epsilon)`，method op——上游采样器、JS 糖、ops 词表本来就在，PHP 只差发名）。**实际被采样的只有五项**（materialize.cpp:900 properties 数组 opacity/width/height/left/top；width 吃 w/size 样式 op、height 吃 h/size）——"上游可动画字段不能缩写成仅五项"说的是 StyleFieldsOf 掩码表（transition()/spring() 的可动画性判断面），采样面就是五项，两个口径都在本页。easing 三值白名单 linear/ease-in/ease-in-out；跨帧动画身份 = 元素 `id` op（behavior.key → MotionIdentity 首选），要动画的元素必须 `->id()`，否则身份落 specId、退化为逐帧跳变；kit selftest 的 transitionInterpolation 门做"早期帧≠终值、多帧后=终值"的插值断言 |
| 自定义绘制 | 未接 `move_to` / `line_to` / `curve_to` / `cubic_bezier_to` / `arc_to` / `close` + PathFill/PathStroke |
| 高级滚动 | 通用糖已补（2026-10-30 批1：`overflowScrollbar`/`scrollbarMode`/`viewportFromLayout`/`scrollSize`/`withItemToMeasureIndex`，三档 mode 白名单拒未知值）。消费端分界如实记：`overflow_scrollbar`/`mode` 有真消费（ScrollMode 装配段 materialize.cpp:2803-2804，kit selftest 另有 scrollbarModePaintPair 门——结构面硬门，regionRgb 像素对拍降为 advisory：macOS 无头渲染自动隐藏原生滚动条，两盒右缘读数预期同色，"always 真的画出滚动条"需真人 HID 或 --shot 验收）；`with_item_to_measure_index` 只落 UniformList（普通 list 上游明确 log "ignored"）；`viewport_from_layout`/`scroll_size` 在 behavior 链**只解析不消费**（:386-390 记下后全树无读取点——前者的真消费端是 catalog Scrollbar descriptor，批 3 起 PHP 能造 ScrollbarHandle 了，走 `catalogScrollbar($id, …, viewportFromLayout: true)` 的 registered_method 通道）；`track_scroll` 见下句 |
| 其他行为 | `pressed` / `indeterminate` / 定位 / panel / mounting / frame-budget 等需逐 API 核对，不能因为上游有名字就假设 PHP 可任意发出 |

`choices` 已量到 open_change/confirm/dismiss，`input` 已量到 step，`table` 已量到 item 两种事件。
shell 层 Select 本来就是 combobox-role 容器，trigger 与弹层需要组合；不能把没有完整 trigger 与回调路由失败混为一谈。

（`track_focus` / `content_focus_handle` 自 2026-10-13 起由 bridge 为 Select 发出，
但**没有**元素级的 PHP 糖：这两个 op 的实参是一个 `Bridged` 焦点句柄，
只有 bridge 那侧能造出来，从 PHP 传不进去。给它造个假糖只会再产一条哑路。
`track_scroll` 同款限制（materialize.cpp:434-440：实参要 `BridgedKind::Number` 形的
EntityHandle 句柄，只有 bridge 侧造得出），批 1 的滚动糖不吞它。）

样式词表：上游 `style.cpp` 的零参关键字有 **137** 个（表是 `static const Keyword kKeywords[]`），
我们发 **135** 个且**全部在册**（0 个拼错，由 `cmd/check-upstream-parity.php` 的 2c 段静态核；
2026-10-30 批1 前是 72，差集 63 颗当日补齐）。
带参那侧：族表 `kFamilies[]` 47 个 + `kOtherParamNames` 12 个，我们发 27 个，同样全在册。

一个读表时容易上的当：`struct Keyword` 的第三个字段是 `uint32_t fields`，**它不是 arity，
也不是"这条会不会生效"**。`ApplyNullaryStyle()` 拿到条目就无条件 `apply(element)`，
`fields` 全树只被 `StyleFieldsOf()` 读一次，用途是"这个名字动了哪些被追踪字段"，
给 `transition()` / `spring()` 判可动画性。**零参关键字**那 137 条里只有 7 条非零，正好是字号那一族
`text_xs, text_sm, text_base, text_lg, text_xl, text_2xl, text_3xl`；带参那侧另有来源——
`bg` / `text_color` / `border_color` / `opacity` / `text_size` 是 `StyleFieldsOf()` 里硬编码的几个，
其余族名（`w` / `h` / `left` / `top` / …）由 `FamilyFields(family->fam)` 给掩码。
所以"能动画的属性"= 字号七档 + 那五个硬编码名 + `FamilyFields()` 为各族声明的掩码——
精确清单要读 `FamilyFields()` 本体（本轮没读它），别拿旧文档那句"可动画属性只有
opacity/w/h/left/top"当这张 C++ 表的事实：那是上游对脚本端动画的说明，来源不是同一张表。
表上方那句 "changes nothing" 说的是**空 apply**，
而实测全表空 lambda 条目 0 个。

还没糖的关键字：2026-10-30 批1 之前列的是 `col_span_full`、`row_span_full`、
`self_flex_start/_end`、`content_normal`、`overflow_x_hidden` / `overflow_y_hidden`、
`shadow_2xs/xs/xl/2xl`、`not_italic`、`text_decoration_*`、`text_left`、`flex_grow_0/1`、
`flex_shrink_0/1`、大部分 `cursor_*`、`debug*` 共 50 颗（实测点名组），加上当时没点名的
13 颗（`col/row_start/end_auto`、`content_start/end`、`justify_end`、`self_baseline`、
`font_extrabold/extralight`、`text_ellipsis_middle/start`、`whitespace_normal`），
差集实算 65 颗——批1 一天补齐 63 颗（一行糖 + 一次 `isCalled` 覆盖率门的认可，
`examples/style --selftest` 的 detached 链把 110 颗逐个真调）。现在真正剩的只有
`h_flex` / `v_flex` 两颗：它们的上游关键字是 display+方向+对齐的三合一，我们的正路是
`El::hFlex()` / `El::vFlex()` 直接造 kind 名（Element.php:143-157），本来就不走
nullary style 调用面，不算洞。

## 4. 已修正的 API 与验收边界

无障碍与焦点一族**2026-10-13 已接**（#70）：`El::role()` / `ariaLabel()` / `ariaLevel()` /
`ariaSelected()` / `ariaActiveDescendant()` / `tabIndex()` / `tabStop()` / `keyContext()` /
`selectable()` 发的是行为链上的 method op。这一族的特殊之处是**它不画任何像素**，
所以 shotdiff 看不见它——`examples/table` 的数据节给列表打了 `list` / `list_item` / `aria_selected`，
侧栏打了 `navigation`，标题打了 `heading` + `aria_level`，而两个示例的全部在册基线
（hello 18 棵 + 其余各域的全部基线）逐张 `gdfrac=0.00000`；
这些像素不变只证明没有视觉变化，**不能证明 VoiceOver 实际读出了什么**。`role()` 的名字必须在上游那 181 个里
（`Element::ROLES`，与 `shell/a11y.cpp` 的 `kRoleNames` 双向对齐，由
`cmd/check-upstream-parity.php` 钉住），因为上游那句 `if (role != None)` 对未知名字完全静默。

`El::primary()` / `secondary()` / `danger()` / `ghost()` / `outline()` —— **2026-10-13 已修**。
它们以前发的是 `method('variant', [...])` / `method('outline')`：上游行为链两个名字都不认，
带参未知名落在静默丢弃那一格，于是五个糖一个像素都不改变（旧基线
实测同一棵树带/不带 variant 的截图逐字节相同）。现在它们发 catalog
`Button` 真正的零参方法（`registered_method: primary/ghost/…`），并且**只**对 catalog 节点开放，
打在 kind 节点上抛 `InvalidArgumentException`。同一批修掉的还有 bridge 里写错的
`"focus"` / `"content_focus"`（正确名字是 `track_focus` / `content_focus_handle`，
Select 的焦点句柄此前从未真的交出去过）。

`El::table()` 系列**2026-10-13 已修**（#69）：以前我们从没发 `row_count`/`column_count`，
`table_row`/`table_cell` 在词表里却没有工厂，且 `TableRow`/`TableHead`/`TableCell` 的构造要读
`component.index`（`materialize.cpp:1838-1842`）而 `gpui_shim_push` 只有五个槽位。
现在有了 `El::tableRow()` / `El::tableCell()` / `->rowCount()` / `->columnCount()` /
`->rowIndex()` / `->columnIndex()`，ABI 也补上了 index 槽与回读口
（`App::specIndex(id)`，查无此 id 返回 -1），这条断言现在住在 `php bin/gpui selftest table`。
注意这些号只影响 **aria 读数**——上游的表格部件全是"带 role 的 div"，布局依旧归样式管。

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
| window / multi-window | 🟡 | `App::createWindow()` 支持 `['borderless','clientTitleBar']` 选项与 `windowAction()` 三动词（批5，TitleBar B 期切片）；C 期（TitleBar 出 descriptor/kind）仅立项设计，见 components 页 |
| entity / context / render / render-once / task / global / element-id / view-cache | ⛔ 概念不对应 | Rust 侧写视图的抽象；我们换成"PHP 数组 + 每帧重算 + 脏驱动"，见 [架构](./architecture) |
| style | 部分 | nullary 关键字 135/137（2026-10-30 批1 补齐差集；剩 h_flex/v_flex 走 kind 名），参数化名字 27 个，见第 3 节 |
| focus | 🟡 | `tab_index` `tab_stop` `key_context` 已接（2026-10-13）；`track_focus` / `content_focus_handle` 的实参是 `Bridged` 句柄，只有 bridge 造得出，故无 PHP 糖——但 2026-10-14 起 PHP 侧能用 `App::focusElement(id)` **按元素 id 把焦点放进**这些句柄（shim 按 `:t`/`:c` 命名规矩去找），dialog 的焦点陷阱**实测是通的**（`focusTrap()` 读到 `trap == focus == popup`），#62 已有原生 Escape 关框证据；真人 HID 仍归 #56，不能与进程内原生事件混同 |
| action | 🟡 | `App::dispatchAction()` + 菜单动作路由已通；无 `bind_keys` 式键位表 |
| keybinding | 批5（2026-10-31）已接 `bindKeys`：KeyChordsParse → ShellActionOf → KeymapBind 三步照 NativeBindKeys（整表先验）；动作无人接时 enqueue 成 action 事件。安装回执/表内查证 = bindKeysGate 硬断言；合成 cmd-key 送达 advisory（NSApp 吞无菜单行的 cmd 键，native 键盘归 HID） |
| event | 🟡 | PHP 发出 24 个在册 `on_*` 名，实测送达 20 个：原有 14 个、部件级 open_change/confirm/dismiss/step，以及宿主虚拟列表的 item_click/item_secondary_click。列表级事件由 `examples/table` 的 `virtualItemEvents` 验证（真实点击/右键、稳定行 key、坐标、左键排除），行自身回调另由 `virtualRowListeners` 验证。resize 路由和组上监听器已修，物理按住拖拽仍需人工 HID 验收；链接/token 送达批4 已量化（tokenDeliveryRender/linkDelivery 门）。见 [事件与回调](./events)。 |
| animation | ❌ | `transition`/`spring` 整族未接 |
| paint / geometry / text-system | 🟡 | 布局与文本可用；无路径绘制（PathFill/PathStroke 未接） |
| accessibility | 🟡 | `role`（上游 181 个名字全表白名单，写错当场抛）+ `aria_label` `aria_selected` `aria_level` `aria_active_descendant` `selectable` 已接。缺口：没有读屏侧回读口，验收只能是"op 发对了 + 像素一位没变"，VoiceOver 里到底读出什么得人工确认 |
| image / assets / fonts | 部分 | `El::icon` 校验 `icons/… .svg` 的路径形状，形状错抛异常；base 名不在表仍可能静默不画。无字体装载、无 http 图片策略 |
| i18n | ❌ | 无 locale 机制 |
| system-notification | 已接、可见性待验 | `App::notify()` 是应用内通知，OS 通知是 `App::osNotify()`；返回 true 不等于系统横幅可见（见 [系统能力](./system-capabilities)） |
| webview | ✅ | WKWebView 经 ChildView 挂载，含可见性回收 |
| native-extension | ⛔ 不适用 | 我们就是宿主，不需要脚本侧 host module |
| webassembly / mobile | ❌ | 只出 macOS/原生桌面产物 |
| test / fps | ✅ | `--selftest` / `--shot` / shotdiff / `showFpsHud()`，见 [测试与门禁](./testing) |
| design-guides / coding-guides / comparison | 🟡 | 设计令牌走 `App::setTheme()`；语义 token 表未暴露给 PHP |
