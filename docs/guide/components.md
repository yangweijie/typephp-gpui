# 组件 API

`Element`（别名 `El`）是链式 builder。工厂方法造节点，样式/行为方法返回 `$this` 可继续链。
`toArray()` 递归序列化成数组交给 bridge。

## 基础原语（gpui base ComponentKind）

```php
El::div()
El::hFlex(array $children = [])   // 水平弹性容器
El::vFlex(array $children = [])   // 垂直弹性容器
El::text(string $content)
El::button(string $label)         // 标签是子 text 元素
El::link(string $label, string $href = '')
El::input(string $id = '')        // 保留态：可 onChange / App::text(id)
El::textarea(string $id = '')
El::slider(float $min = 0, float $max = 100, float $value = 0)
El::checkbox(string $label, bool $checked = false)
El::switchBox(string $label, bool $checked = false)
El::image(string $src, string $alt = '')
El::progress(float $value = 0)
El::table(string $id) / El::list(string $id) / El::tabs(string $id) / El::tab(string $label)
El::textView(string $markdown, string $id = '')   // Markdown / HTML
El::select(string $id, array $options) / El::combobox(...) / El::datePicker(string $id)
El::popover(string $trigger, El $content) / El::hoverCard(...) / El::collapsible(...)
El::avatar(string $src) / El::accordionItem(string $header, El $panel) / El::svg(string $path)
```

> **这一节全是 gpui 的 base 原语，不带组件库样式。**
> 同名的东西在上游有两张脸：base 的 `ComponentKind::Button`（只有点击与文本，没有圆角/底色/变体）
> 与 catalog 的 `Button` 组件（`label`/`size`/`primary`/`ghost`/`outline`/`loading` 等 13 个方法，
> 才是 gpui-kit.com 画廊里那个样子；注意它没有 `variant` 方法——`variant(enum)` 属于
> DropdownButton / Tag / Bubble / Marker / GroupBox / TabBar）。
> 上面这些工厂走的是**前者**。要画廊那种带样式的按钮，用 `El::catalogButton($id, $label)`
> 或通用工厂 `El::catalog($导出名, $构造实参)`；两者的差别见下面「样式与行为」一节末尾。

### C 类便捷工厂

```php
El::numberInput($id) / El::otpInput($id)
El::progressTrack(array $children = []) / El::progressIndicator()
El::tableHead(array $children) / El::tableCaption(string $text)
El::tableRow(array $children = []) / El::tableCell(array $children = [])
El::hResizable(array $children) / El::vResizable(array $children) / El::resizablePanel(?El $child)
El::popup() / El::radioGroup(array $children) / El::toggleGroup(array $children)
```

### 批3 完善点（2026-10-28）

- `El::collapsible($label, $content)`：上游 Collapsible 只读 **content** 槽——label 现在是
  折叠块的第一行（单块折叠、整块随 open 收展），不再是发错槽名的空块。受控展开靠
  `->open(true/false)` 重渲染（无回调通道）。
- `El::tab($label)`：label 自动包成 text 子节点（base Tab 的 text 只作 motion identity，
  屏上内容来自子元素）；placement 四向是 catalog TabBar 的能力面，base Tab 没有。
- `El::accordionItem($header, $panel, $changeId = null)`：三段部件契约
  （AccordionItem > AccordionHeader > AccordionTrigger，面板走 AccordionPanel），
  `$changeId` 给了就接 on_change（事件路由 `$changeId, change`）；`->open(true)` 强展
  一直是受控展开的回归锚。门：`php bin/gpui selftest catalog` 的 accordionChangeRoute。
- `El::accordion($id, $items)`（2026-10-30 批3）：base kind **根**——本体是
  Div+Id+Role(Group)（base/accordion.cpp:5-8），价值在把三段件合法挂树；shim
  `kKindNames` 补 `{"accordion", K::Accordion}` 登记（不登记节点直接拒建）。
  items 只收 accordionItem()（typed 校验）。详见 [Accordion catalog 版](#accordion-catalog-版)的
  三张表达对照。
- `El::otpInput($id, $length = 4)`：格数经桥内 otp_length op 传进 retained（上游
  CreateRetainedOtp 按任意 length 建）；读数 `App::otpLength($id)`。带样式那颗仍是
  `El::catalogOtpInput($id, $length)`。
- `El::slider($min, $max, $value)`：量程/初值经桥内 slider_min/slider_max op 进
  retained（初值用公开的 SliderSetValue 补——CreateRetainedSlider 恒从 min 起）；
  读数 `App::text($id)`。门：input selftest 的 sliderRangeInitial。
- `El::avatar($src, $fallback = '')`：默认圆形裁剪+方形比例+40px，fallback 槽放首字
  兜底（首字用 preg /u 取——nano 扩展表没有 mbstring，`mb_substr` 会让模块启动直接失败）。
  在线态色点需要 absolute 叠加，未做。
- `El::image($src, $alt)`：$alt 接进 aria_label op（以前收下即丢）。Ely 的 framed 带框
  不做组合糖（borderWidth+rounded 自己叠，见样式糖纪律）。
- `El::svg($path)`：空串/非 .svg 后缀当场拒（svg 走 SvgDraw 直读任意资源路径，没有
  icon() 那套 icons/ 前缀校验；路径对但文件不在仍是静默空白）。
- Tab/Shift-Tab 焦点漫游：上游窗口层本就挂着（gpui/window_common.cpp：没人吃的 Tab 走
  FocusTrapTab），宿主按键缝实测能走到——门：kit selftest 的 focusRovingTab
  （前提式：窗口不 key/main 的机器记未验）。

### 表格要按语义搭

上游每一层表格部件都只是"一个带 aria role 的 div"（`base/table.cpp:12-56`），**布局不管、
只管语义**：行要自己 `flexRow()`，而 `Table` 的 `row_count`/`column_count` 与
`TableRow`/`TableHead`/`TableCell` 的序号都要显式给（`materialize.cpp:352-354, 1838-1842`），
不给就等于对读屏的人说"这表没有行列、每格都在第 0 列"。

```php
El::table('t')->rowCount(2)->columnCount(2)->children([
    El::tableHeader([
        El::tableRow([
            El::tableHead([El::text('名称')])->columnIndex(0),
            El::tableHead([El::text('数量')])->columnIndex(1),
        ])->id('row-0')->rowIndex(0)->flexRow()->gap(18.0),
    ]),
    El::tableBody([
        El::tableRow([
            El::tableCell([El::text('alpha')])->columnIndex(0),
            El::tableCell([El::text('3')])->columnIndex(1),
        ])->id('row-1')->rowIndex(1)->flexRow()->gap(18.0),
    ]),
]);
```

`rowCount()/columnCount()` 发的是上游认的两个 method op；`rowIndex()/columnIndex()` 写的是
`Component::index` 槽（同一个槽，上游按 kind 决定含义，别在一颗节点上两个都调）。
自检读数：`App::specIndex('row-1')` 回读 spec 里的号，查无此 id 返回 `-1`
（与"有、且号是 0"分得开）——用它盯这件事的门在 `php bin/gpui selftest table`。

## 样式与行为（链式）

```php
->flexCol()->flexRow()->gap(8)->p(16)->px(8)->w(240)->h('100%')
->sizeFull()->wFull()->hFull()                 // 参数化 size/w/h + "100%"
->itemsCenter()->justifyCenter()->flex1()
->rounded(6)->roundedMd()->roundedLg()->roundedFull()->border()
->bg(0xRRGGBB)->textColor('#hex')->font(16)->bold()->semibold()
->overflowYScroll()->overflowXScroll()          // gpui 原生滚动（method/behavior op）
->id('name')->disabled()->selected()->checked()->open(true)->tooltip('..')
                                                   // open(true)：Select/Combobox/DatePicker
                                                   // 的"弹层现在是开着的"，部件按它解释
                                                   // Confirm/Cancel（见 events.md 1b）
->onClick()->onChange()->onHover()->onKeyDown()  // 声明事件；handler 在 App->on 注册
->slot('header', $child)                          // 命名槽（Popover/Avatar/Sidebar…）
```

**有些组件的可见部分是"装饰槽"，不给内容就什么都没有。** 上游认的公共槽名只有这些：
`content` `trigger` `input` `decrement_button` `increment_button` `image` `fallback`
`header` `footer` `panel`（`component_registry.cpp:IsRegisteredCommonSlot`）。
最典型的是 `El::numberInput()` 的 ± 两颗：`NumberStepButton` 造的是
`Button::New(cx, id, …)`——第二个参数是 **id 不是标签**，样式与子元素全从那两个槽取。
槽空着 ⇒ 按钮 0x0、不落字、`paint.hits` 里没有可点的矩形，于是"点了没反应"查的是这里而不是事件链：

```php
El::numberInput('in-num')->value('42')->onStep()
    ->slot('decrement_button', El::button('－')->id('num-dec'))
    ->slot('increment_button', El::button('＋')->id('num-inc')),
```

同一族里 `El::select()`/`El::combobox()` 的根在 shell 层只是**一个带
`role=combobox` 的容器**（`Select::New` 返回裸 `Div`，`base/select.cpp:54-73`），
trigger 与弹层要宿主自己组合——上游 `examples/js_story` 里 Select/Combobox 两条 story
至今标着 `availability: "pending"`。所以我们量这一族的事件走的是
`App::focusElement()` + `dispatchAction()`，不是点触发器。

**按钮有两张脸，选对那张**：`El::button()` 是 gpui-base 的 `ComponentKind::Button`
（只有点击与文本），上游画廊里带圆角/底色/变体的是 component_shell 的 `Button`
（`label/tooltip/loading/size/outline/primary/secondary/danger/success/warning/ghost/link/compact`）。
后者用 `El::catalogButton($id, $label)` 造，或走通用工厂 `El::catalog($导出名, $构造实参, $children)`：

```php
El::catalogButton('btn-save', '保存')->onClick()->primary();
El::catalog('Accordion', ['acc-1'])->child(El::catalog('AccordionItem', ['i1']));
```

变体糖 `primary()/secondary()/danger()/ghost()/outline()/success()/warning()/compact()/loading()`
以及 `title()/banner()/description()/label()/side()/align()/columns()/…` 只对 catalog 节点有效，
打在 kind 节点上**抛 `InvalidArgumentException`**。以前它们发的是
`method('variant', [...])` / `method('outline')` —— 上游两个名字都不认，而带参未知 op 是
静默丢弃那一格，于是这五个糖一个像素都不改变（见 `cmd/check-upstream-parity.php` 那条门）。

一个实测到的坑：**catalog Button 会把标签居中并按盒子裁字**。所以"宽度固定、文案可能很长"
的位置（侧栏导航就是这种）不要用它——`examples/chrome` 的导航行因此保留 base Button，
选中态改用 `->bold()` 表达。另外构造器里的 id 与 `->id()` 要一致：事件按元素 id 路由，
只给构造器不给 `->id()` 的话点击会落到空 id 上（`El::catalogButton()` 把这两件事一起做了）。

样式糖覆盖上游 137 个 nullary 关键字里的 **135** 个（2026-10-30 批1 把差集 63 颗补齐，
剩 `h_flex`/`v_flex` 两颗走 `El::hFlex()`/`El::vFlex()` 的 kind 名——见下节），且
`style()` / `paramStyle()` / `method()` /
`callback()` 都是 private —— 没有“发一个没写糖的名字”的公开口子。
`grid`、`self_*`、`whitespace_nowrap` 等已补齐的名字见下；其余缺口见[上游覆盖](./upstream-coverage)。


### 补齐的样式关键字（47 个）

上游 `style.cpp` 的 137 个零参关键字里，我们现在发得出 72 个。新增的这批按组记：

```php
El::div()->block()->grid()->visible()->invisible();                    // 显示模式
El::div()->flexRowReverse()->flexColReverse()->flexWrapReverse();
El::div()->flexNowrap()->flexAuto()->flexInitial();                    // 弹性方向与收缩语义
El::div()->itemsBaseline()->itemsStretch();                             // 容器交叉轴
El::div()->selfStart()->selfCenter()->selfEnd()->selfStretch();         // 子元素自己
El::div()->justifyAround()->justifyEvenly();
El::div()->contentCenter()->contentBetween()->contentAround()
    ->contentEvenly()->contentStretch();                                // 多行之间，别和 justify_* 混
El::div()->overflowHidden()->aspectSquare();
El::text('…')->whitespaceNowrap()->textEllipsis()->lineThrough();
El::div()->borderDashed()->shadowNone()->shadowLg();
El::div()->cursorText()->cursorMove()->cursorNotAllowed()->cursorGrab();
El::text('…')->fontThin()->fontLight()->fontNormal()->fontMedium()->fontBlack();
El::text('…')->textXs()->textSm()->textBase()->textLg()->textXl()->text2xl()->text3xl();
```

两条纪律：

1. **不做组合糖**。`flexBetween()` 这种看着省一次调用，实际是把两个正交概念焊死，
   上游改名时又是一条新的静默路。
2. `text_*` 那七档与 `paramStyle('text_size', [px])`（即 `El::font(16.0)`）**互斥**，
   后调用者覆盖前一个；它们也是零参样式里唯一动到被追踪字段的（`transition()`/`spring()`
   只对这一族有字号可动画），细节见[上游覆盖](./upstream-coverage)。
3. 这 47 个糖（连同批1 补的 63 颗，共 110 颗）在**编出来的产物**里逐个调一遍的门住在
   `php bin/gpui selftest style`
   （`nullaryStyles — 发出=110/110 去重后=110`）。放这里是有意为之：nano 里调用一个没编进去的
   方法是 SIGABRT 而不是异常，只有跑产物排得掉，而它一崩需要看见的是"崩在哪一个糖上"，
   不是那种只在末尾打印一次的总报告。

### 补齐的样式关键字·第二批（批1，2026-10-30，63 颗）

2026-10-13 那批之后差集实测还剩 65 颗：`h_flex`/`v_flex` 走 `El::hFlex()/vFlex()` 的
kind 名不算洞，其余 63 颗一批补齐（72→135）。零参糖各一行、只发关键字本身，
分组同 `src/Element.php`：

```php
// 网格线放置（grid 无模板列可发，形状交上游自动流）
El::text('…')->colSpanFull()->rowSpanFull()->colStartAuto()->colEndAuto()
    ->rowStartAuto()->rowEndAuto();
// 弹性定值与分布（与带参 flexGrow($v)/flexShrink($v) 互斥，后调覆盖）
El::text('…')->flexGrow0()->flexGrow1()->flexShrink0()->flexShrink1();
El::div()->contentStart()->contentEnd()->contentNormal()->justifyEnd();
// 交叉轴与字重补档
El::text('…')->selfBaseline()->selfFlexStart()->selfFlexEnd();
El::text('…')->fontExtrabold()->fontExtralight();
// 文本面
El::text('…')->textLeft()->textEllipsisMiddle()->textEllipsisStart()
    ->whitespaceNormal()->notItalic();
El::text('…')->overflowXHidden()->overflowYHidden();
// 阴影四档补档
El::div()->shadow2xs()->shadowXs()->shadowXl()->shadow2xl();
// 文字装饰：粗细五档要有装饰线才见（配 underline()/lineThrough()）
El::text('…')->underline()->textDecorationSolid()->textDecoration8();
// 光标 18 颗（悬停才换形，静态快照不显形）
El::text('…')->cursorAlias()->cursorGrabbing()->cursorWResize(); // …全表 18 颗
// 调试族 7 颗：release 产物里是上游 no-op（DEBUG/NDEBUG 条件编译），op 照发
El::text('…')->debugRed()->debugPink()->debug();
```

 kit 域树 1 折叠线下有每组一段的真调用样（`examples/_shared/features/kit.php`，
"样式糖差集"各行），滚动条三态小样与像素对拍见下节。

### 元素级浮层行为糖（批1，2026-10-30）

四颗糖走 kind 节点的行为链 method op，给 `El::popover()` / `El::hoverCard()` /
`El::dialog()` 等任意 kind 节点直接写行为：

```php
El::popover('t', $body)->defaultOpen(true)->overlayClosable(false);
El::hoverCard('t', $body)->openDelayMs(120)->closeDelayMs(80);
```

- `default_open`：只在保留态**首帧播种**时读（materialize.cpp:2503-2508）；
  不翻已开的保留态。
- `overlay_closable`：点弹层外面是否收起（base/popover.cpp:174/237）。
- `open_delay` / `close_delay`：毫秒，消费端只有 HoverCard
  （base/hover_card.cpp:58、materialize.cpp:2633-2634）；0–60000 范围校验，
  越界当场抛（上游 AsNumber 对负数/超大值静默收下，静默改值不如拒绝）。

**通道分界**：registered catalog 节点（`catalogPopover` / `catalogHoverCard`）的
同名配置走 descriptor 的 `registered_method` 表（工厂 ctor 实参已覆盖），
这四颗是给 kind 节点用的，两条通道别混。

### 高级滚动 op 糖（批1，2026-10-30）

与已有的 `overflowYScroll()` / `overflowScroll()` 同族，每颗消费端不同：

```php
El::div()->overflowScrollbar()->scrollbarMode('always');
El::div()->viewportFromLayout(true)->scrollSize(600.0, 400.0);
El::uniformList('u', 10)->withItemToMeasureIndex(2);
```

- `overflow_scrollbar`：横竖都滚且**挂原生滚动条**（behavior.scrollbar=true 时
  不再 HideScrollbar，materialize.cpp:2782-2786），是 `overflowScroll()` 的兄弟名。
- `mode`：三档白名单 `hover` / `always` / `scrolling`；未知值上游静默落 Scrolling
  （:385-388 无 else 拒绝），所以白名单当场拒。
- `viewport_from_layout`：behavior 链只解析（:386-387 记 flag）**今天无人读取**；
  真消费端是 catalog Scrollbar descriptor（构造要 PHP 造不出的 ScrollbarHandle）。
  发 `false` 不发 op——上游见名就置 true、无视实参。
- `scroll_size`：behavior 链解析（:388-390）后**全树无读取点**；descriptor 侧也
  没有这个方法名。发得出去、上游不消费——留糖不删名，边界由注释钉住。
- `with_item_to_measure_index`：只落 UniformList（:1180-1181 普通 list 明确 log
  "ignored"、:1209-1210 取值）。

kit 域 selftest 的 `scrollbarModePaintPair` 门断结构面（registeredError/componentFailure
清零），regionRgb 像素对拍降为 advisory 读数——macOS 无头渲染自动隐藏原生滚动条，
两盒右缘读数预期同色，"always 真的画出滚动条"需真人 HID 或 --shot 验收
（见[测试与门禁](./testing)）。

### 路径绘制：PathFill / PathStroke + 六命令（批5，2026-10-31）

```php
El::pathFill('tri', '#2f6fed')
    ->moveTo(10, 90)->lineTo(50, 10)->lineTo(90, 90)->closePath()
    ->w(100.0)->h(100.0);
El::pathStroke('arc', 3.0, '#e05a2b')
    ->moveTo(10, 80)->arcTo(40, 40, 0, false, true, 90, 80)
    ->w(100.0)->h(100.0);
El::pathFillGradient('g', '#ff0000', '#0000ff')->moveTo(…)->…;
```

- 命令六颗：`moveTo`（2 参）/`lineTo`（2）/`curveTo`（4，二次——上游折算成三次）/
  `cubicBezierTo`（6）/`arcTo`（7：rx,ry,rot,large,sweep,x,y）/`closePath`（0），
  与 runtime.cpp:8154-8177 的 JS 构件器 arity 逐字对齐。坐标 = bounds 原点 +
  像素（字符串百分比按 extent 折算），**元素要有 w/h**（bounds 来源）。
- background/strokeWidth 走专用 ABI `gpui_shim_push_path`（Push 前填全
  Component.background/strokeWidth——push 的五槽带不出来）；桥在 emitTree 的
  path 分支消费 `path_bg`/`path_bg_gradient`/`path_stroke_width` 三个元 op。
- 像素证据：kit selftest 的 pathPaintGate（三角质心 regionRgb 取到 #2f6fed 精确值）。

### 应用内键位 bindKeys（批5，2026-10-31）

```php
$app->bindKeys([
    ['keystroke' => 'cmd-s', 'action' => 'kit::save'],
]);
$app->on('kit::save', 'action', fn () => …);   // 动作无人接时 enqueue 成 action 事件
```

三步照上游 NativeBindKeys（runtime.cpp:9568-9641）：KeyChordsParse 逐条解析 →
ShellActionOf 塌成 ShellAction → KeymapBind 终局安装；**整表先验**（任何一条
keystroke 不可解析，整表拒装并抛——上游注释"a keymap half applied … is a worse
state"）。安装回执与表内查证（KeymapAnyBindingForAction）= bindKeysGate 硬断言；
合成 cmd-key 的送达降为 advisory（NSApp 对无菜单行的 cmd 组合键在窗口派发前吞掉，
native 键盘归真人 HID）。

### 窗口选项与动作动词（批5，TitleBar B 期切片）

```php
$app->createWindow('无边框', 360, 220,
    ['borderless' => true, 'clientTitleBar' => true]);
$app->windowAction('zoom');      // minimize | zoom | close
```

- clientTitleBar 时顶部 34px（title_bar.h kTitleBarHeight）是 caption 带：双击
  最大化走 window_common.cpp:2526 通道，macOS 系统红绿灯仍由 Cocoa 画在透明标题
  栏上（gpui.h WinOpts 注释）——PHP 自拼标题条做视觉、控制键归系统/窗口动作。
- `windowAction('close')` 后 isOpen=false 是 titlebarWindowGate 的硬证据。
- **C 期立项（未落码）**：上游 component::TitleBar 不是 descriptor 导出也不是
  ComponentKind（PHP spec 通道造不出）——需要 cmd/patch-title-bar.sh 给
  title_bar.h 出 descriptor/kind + `kDialogMethods` 具名方法表复刻
  （coverage.md:65-67 先例）+ `El::titleBar()` 工厂。卡点与路径见 coverage 页。

### 读屏与键盘：`role()` / `aria_*` / `tab_*`
这一族全是 behavior op，**不改变像素**，只改变读屏软件和键盘轮询看到的树。
给一颗原本没有语义的 `div` / `text` 打标：

```php
El::vFlex($items)->role('list')->ariaLabel('可滚动列表')
    ->tabIndex(0)->tabStop()->keyContext('GpuiDataList');
El::text('列表项 3')->role('list_item')->ariaSelected(true);
El::text('表格、列表与进度')->role('heading')->ariaLevel(2);
```

三条容易踩的：

1. `role()` 的名字**只认 snake_case**，且必须在上游那 181 个里（`Element::ROLES`）。
   写 `'Heading'` 会当场抛 `InvalidArgumentException` —— 这不是苛刻，是上游那句
   `if (role != AccessibilityRole::None) element->Role(role)` 对未知名字**完全静默**，
   既不变像素也不报错，与 `variant` 那五个糖同一形状（见[上游覆盖](./upstream-coverage)）。
2. `ariaLevel(0)` 也抛：上游把它夹成 1（`level < 1 ? 1 : level`），静默改值不如拒绝。
3. 上游的表格部件（`tableRow` / `tableHead` / `tableCell`）**自己就带 role**
   （`base/table.cpp:12-56` 全是"带 AccessibilityRole 的 div"），别再手动 `role('row')`
   叠一遍；`keyContext()` 的字符串要与你实际绑定的按键上下文一致，否则键位解析走不到那格。

`track_focus` / `content_focus_handle` 没有 PHP 糖：它们的实参是 `Bridged` 焦点句柄，
只有 bridge 那侧造得出来（retained 输入与 Select 由 bridge 自动发）。

## 虚拟列表：按可见区间同步渲染

`El::list($id, $count)` 测量每行高度；`El::uniformList($id, $count)` 只测一行，适合等高数据。
不要给列表直接附加 children；先用 `App::listRenderer()` 登记行构造器，GPUI 才能按滚动位置索取 `[start,end)`。
登记可以在创建窗口前进行，与事件接线一样。

```php
$app->listRenderer('items', static function (int $start, int $end): array {
    $rows = [];
    for ($i = $start; $i < $end; $i++) {
        $rows[] = El::text("第 {$i} 行")->font(12.0)->truncate()->id("item-{$i}");
    }
    return $rows;
});
$list = El::uniformList('items', 10000)->w(260.0)->h(90.0)
    ->onItemClick()->onItemSecondaryClick();
```

- 必须返回正好 `end-start` 个 Element；缺行、多行、错误类型和闭包异常会被拒绝。
  PHP 异常原因在 `App::lastError()`，原生缺行诊断在 `registeredError()`。
- 行闭包只描述元素。它在原生绘制途中同步运行，不能重入窗口 API（例如 render、loop、close）。
- `uniform_list` 的测量约束是 MinContent：不换行文字用 `truncate()`/`whitespaceNowrap()`，
  或为行容器显式指定高度；否则窄约束下的逐字换行会增大行高。
- 行自己的事件仍然可用；列表级 `item_click` 的 `value` 是行根 id，缺 id 时为全局行号字符串。
  右键 `item_secondary_click` 还携带 button、click_count、position、local_position、modifiers。
- 真惰性与滚动由 `examples/table` 的 `virtualListLazy`、`virtualListScroll`、`virtualVariableRows` 验证；
  行事件由 `virtualRowListeners`、`virtualItemEvents` 验证，不只看构建成功。

`cmd/patch-host-list.sh` 让上游 `RenderVirtualItems` 的宿主回调走 C ABI 函数指针到 bridge 的
`php::call`。临时描述 arena 借用帧内存，既避免改动正在读取的主树，也让字符串活到布局和绘制结束。
这不是 PHP 估算滚动偏移的近似窗口化。

## Dock：原生保留态布局

先创建窗口，再创建 Dock 和面板；`El::dockArea()` 只挂载已有 Dock，不能用 children 填充它。
面板内容由 PHP 描述，面板之间的布局、标签切换、关闭和拖拽由原生 `DockState` 与上游主题 chrome 管理。

```php
$app->create();
$app->createWindow('Dock', 820, 620);
$app->createDock('area');
$app->addDockPanel('area', 'editor', '编辑器', El::text('编辑器内容'));
$app->addDockPanel('area', 'console', '控制台', El::text('控制台内容'));
$app->addDockPanel('area', 'files', '文件', El::text('文件列表'), 'left', 180.0);
$app->render(El::dockArea('area')->sizeFull());
$app->selectDockPanel('area', 'editor');
$layout = $app->dockLayout('area');
$app->removeDockPanel('area', 'console');
$app->loadDockLayout('area', $layout);
```

- 同一面板名再次 `addDockPanel()` 更新标题/内容，不重复添加，也不覆盖用户调整的布局。
  更新描述先暂存，验证通过后才提交；失败的新建不进入注册表，失败更新保留原面板。
- placement 只认 center/left/right/bottom，size 必须是有限非负数；面板关闭受原生关闭约束控制。
- `dockLayout()` 是完整 JSON 字符串；恢复只允许已注册面板。无效 JSON 或未知面板返回 false，旧布局保留。
- `dockProbe()` 返回 registered/open/active/nodes/x/y/w/h；active 是中央组的原生面板索引。
- 布局变化以 `dock_change` 回 PHP（value 为 null），需要保存时用 `dockLayout()` 取值，不把大 JSON 塞进定长事件缓冲。
- `php bin/gpui run layout -- --dock` 进入交互演示；`selftest layout` 验原生标签点击、面板按钮、布局往返及关闭回收。
  重复布局恢复另有 C++ 门。拖拽依赖物理按住状态，真人 HID 验收仍保留为独立待办。

此接口使用上游默认 `DockSkin`，不是 PHP 自绘的近似 Dock；尚未提供 PHP 自定义 chrome 回调。

## catalog 富组件（Registered 机制）

经 `component_shell` catalog 按导出名分发。工厂设 `registered=true`、`kind=导出名`、`ctorArgs`。

```php
El::alert($id, $msg, $variant='default')   // variant: default|info|success|warning|error
El::breadcrumb(array $labels)              // Array<String> 构造实参
El::icon(string $path)                     // 资源根下相对路径
El::sidebar($id, array $menus) / El::sidebarMenu(array $items) / El::sidebarMenuItem($label, array $children=[])
El::sidebarHeader(?El $child) / El::sidebarFooter(?El $child) / El::sidebarToggleButton()
El::form(bool $horizontal=false, array $fields=[]) / El::field(array $children=[])
El::menu($label, array $items) / El::menuItem($label, $action) / El::menuSeparator() / El::menuBar($label, array $menus)
El::dropdownMenu($id, $label)->item($label, $routeId, $type='click')
```

菜单家族的**子节点契约**（上游 `component_shell/lifecycle/menu.cpp` 逐字校验，写错了不是
"看起来不对"而是每帧报错误并渲染成 `Failed to render MenuBar`）：

- `El::menuBar()` 的子节点**只能是 `El::menu()`**——`MenuItem`/分隔符不能直接挂在它下面；
- `El::menu()` 的子节点**只能是 `El::menuItem()` 或 `El::menuSeparator()`**——不支持嵌套 `menu`，
  要分组就多写几个 `menuBar` 的子 `menu`。

```php
El::menuBar('File', [
    El::menu('File', [El::menuItem('新建', 'new'), El::menuSeparator()]),
    El::menu('最近', [El::menuItem('a.txt', 'open-a')]),
])
```

`El::menuBar()` 在 PHP 宿主下能装出原生菜单条，但需要两处配合（都已在工具链里）：

1. **宿主应用身份**：菜单的安装走 component app effects，而 `MaterializeRequest::AppEffects`
   只认 QuickJS `cx.new` 给的"根应用身份"。`cmd/patch-host-application.sh` 补了
   `RegisterHostApplication` / `SetHostApplicationFallback`，shim 在建视图时登记**一个全宿主
   共用**的身份（不同实体发不同令牌会生出两条世代，各自装卸同一份菜单数据）。
2. **回调不能走 ScriptView 成员**：延迟安装原本是
   `WindowPost(..., ListenTo(owner, &ScriptView::OnComponentAppEffect, token))`，而那个函数读
   `self->runtime`——宿主实体是 `EntityNewRaw` 建的裸对象（我们是 8 字节的 `PhpView{Box2*}`），
   按 ScriptView 去读就是越界。补丁给宿主视图换了一个不看 ScriptView 的 trampoline。
   没这一条时表现为**偶发 SIGSEGV**（实测 3 次崩 1；单一身份下 6 次崩 1），修后画廊的 `--liveclick`
   连跑 20 次 0 崩（那条模式已随画廊删除，同一性质现在由 `examples/chrome` 的 `navRowsReachable` 把住）。

菜单条与 `App::setAppMenus([...])` 是两条路：后者装的是**应用级**菜单（macOS 顶部菜单条），
`El::menuBar()` 是元素树里的组件，两者都可用。**动作回 PHP 是同一个 `action` 事件**
（`$app->onAny('action', fn)`，`$e['value']` 就是声明的 id），详见
[事件与回调 · 菜单动作](/guide/events#_7b-菜单动作-action)。

`setAppMenus` 的键名要跟 bridge 对齐：菜单条用 `name`、行用 `action`、分隔符
`['separator' => true]`。写成 `label`/`id` **不报错**，只是那一行的动作永远是 0——菜单看着
装上了、点了没回音。装完拿 `App::menuRows()` 读一次表（各行携带的 action id）就能确认。

`--selftest` 里有一条对应的门：逐节渲染前后比对 `App::componentFailure()` 的计数，
任何一节让计数增加就报 `7:materialize 失败(MenuBar accepts only Menu children)` 这种带原因的错。
为什么需要它——registered 组件建不出来时 gpui **不失败整帧**，只在画面上渲染成
`Failed to render X`，而 `registeredError()` 照不到 materialize 阶段，只有这个计数会动。

catalog 方法糖（走 `registered_method`，枚举 render 前预校验）：

```php
->title('..')->banner()->description('..')          // Alert / Field
->columns(2)->labelWidth(120)                       // Form
->side('left')->collapsibleMode('icon')->collapsed(true)   // Sidebar（枚举校验）
->navIcon('home')                                   // SidebarMenuItem（枚举校验）
->sizeEnum('large')                                 // Alert/Icon/Form 语义尺寸（枚举校验）
->align('center')                                   // Field（枚举校验）
->rotate(1.57)->color('#e07b39')                    // Icon
```

底层逃生舱（一般不用，糖方法覆盖不到时）：

```php
$el->rm('methodName', [arg1, arg2])   // 任意 registered_method
$el::cb('click', 'routeId')           // 回调实参标记（DropdownMenu.item 用）
```

枚举常量（对齐 descriptor 白名单）：`El::SIZES` `El::SIDES` `El::COLLAPSE_MODES`
`El::FIELD_ALIGN` `El::NAV_ICONS` `El::ALERT_VARIANTS` `El::SEPARATOR_VARIANTS`。

## 带样式的表单族要先造一颗 state 实体（catalog Input 一族）

`El::input()` 画出来永远是个裸框——这不是主题没配好：它走的是 shell 的
`ComponentKind::Input`，而 materialize 调的是**不带样式**那个重载
（`InputBase::New(cx, id, interactive, role)`，`base/input.cpp:34-39`）。带边框/底色/焦点态的那颗
是 catalog 里的 `Input` 组件，而它的构造**只吃一个实参** `state`，schema 是
`SchemaEntity("InputState")`。同型的还有七颗：`NumberInput` / `OtpInput` / `Slider` /
`ColorPicker` / `Calendar` / `DatePicker` / `TimeField`
（`component_shell/retained_forms/mod.cpp:538-554, 624`）。

上游那颗实体的**创建**原本只存在于 JS 绑定函数里（`NativeRegisteredState` 要 live script scope
才能拿到 Window/App 身份和 JS 错误通道），PHP 宿主两样都没有。
`cmd/patch-host-state.sh` 补了宿主入口 `ShellRuntime::NewHostState(...)`，shim 经
`gpui_shim_state` 用它，PHP 侧就是这两句：

```php
El::catalogInput('in-cat', '你的名字', '初值');   // 糖：catalog Input 加它自己的 InputState
El::catalog('Input', [El::state('InputState', 'in-cat', ['占位', '初值'])]);   // 同一形状，手写
El::state($kind, $key, $args);   // 实参标记；$args 按该 state 描述符的 schema 顺序，null = 不传（optional 缺席）
```

整族八颗都有糖（`retained_forms/mod.cpp:470-518` 是它们各自的 schema）：

| 糖 | state kind | 实参 |
|---|---|---|
| `El::catalogInput($id, $ph, $init)` | `InputState` | 两个 optional 文本 |
| `El::catalogNumberInput($id, $ph, $init)` | `InputState`（与 Input 同一颗） | 同上 |
| `El::catalogOtpInput($id, $length = 6)` | `OtpState` | `length` 是**必填** Number |
| `El::catalogSlider($id, $initial = 0.0)` | `SliderState` | 一个 optional Number，上游限 0..100 |
| `El::catalogColorPicker($id)` / `catalogCalendar` / `catalogDatePicker` / `catalogTimeField` | 各自同名 kind | **不吃实参** |

`examples/_shared/features/inputs.php` 里这一族与 kind 版并排画着，两件事一次看清：
带不带框、以及**取值回读只覆盖 kind 版**——`App::text()`/`checked()` 读的是 shim 的
`inputHandles` 表，catalog 那颗不在里面。实体创建与像素验收不证明实时取值；
catalog 各组件的取值/回调载荷尚未逐一验收，不能把构造绿色当作这条链的绿色。

第二族（`examples/catalog` 的 tree 2）是同一条入口上的另一批：`catalogTextarea` /
`catalogEditor`（`language` 只认 `rust`/`json`）/ `catalogDiff`（补丁文本必须是真的 unified
diff——`DiffFile::Parse` 要 hunk 头 `@@ -1 +1 @@`，缺了就实体建不出来）/ `catalogCommand`
（子节点契约是硬的：只收 `CommandItem`/`CommandGroup`/`CommandSeparator`）/ Carousel 六件套。
Carousel 那一组把**共享 key** 的意义摆在明面上：根、Content、三张 Item、Prev/Next 一共七处写的
都是 `El::state('CarouselState', 'ca-car', …)`，缓存按 `(kind,key)` 命中同一颗实体——
各写各的 key 就是六个互不相干的轮播，而画面上看不出任何区别（只有实体颗数会红）。

三条必须知道的语义：

- **`$key` 是复用的身份**：PHP 每帧重建整棵树，宿主按 `(kind, key)` 缓存实体——用元素 id 就够。
  不缓存就是每帧往上游实体表塞一颗。仪表是 `App::stateCounts()['componentStates']`（上游表的长度），
  我方缓存条数分不了这个案：漏的时候是同一个 key 被覆盖，条数不变，而表长度每帧 +1。
  `examples/input` 的 `catalogStateReuse` 门断的是八颗表单实体在首帧及多帧之后均为 8。
- **handle `0` 是一颗合法实体**：上游 `ComponentStateStore::nextHandle` 从 **0** 起
  （`component_registry.h:522`），所以第一颗实体的 handle 就是 0。跨层传它一律"状态码 + 出参"，
  别让同一个整数既当身份又当成功位——#81 首跑正是把"刚建成第一颗"读成了失败。
- **造不出实体不会静默降级**：bridge 在下发时就把它换成 NIL 实参，而 shim 的 `Entity` 支只收
  `GPUI_ARG_ENTITY`，于是这颗组件渲染成错误节点、`registeredError()` 报出原因。把 0 当 handle
  传下去才危险——那个 handle 可能属于别的组件。`examples/input` 的 `catalogStateFailureVisible`
  用 `El::state('NoSuchState', ...)` 实测过这一条。

失败的另一半仍然是静默的：实体建出来了、组件 materialize 不了时 gpui 只画 `Failed to render X`。
所以 `El::catalogInput()` 的验收要看**两条**——`registeredError()` 为空，**且**
`App::inputProbe()` 的框数真的因为这一行变多（`input` 域实测 4→5）。

## kit 工厂批（tier-A 组件的专用工厂）

这里是 2026-10-28 落地的三批专用工厂（src/Element.php「tier-A 差距清单」「纯工厂热身」与
「带前置作业的 S 工厂 + 组合糖」三节）：tier-A 批 27 个 + 纯工厂热身批 5 个
（Spinner/TableFooter/GroupBox/VForm/InputGroupText，纯 reg() 通道、上游零改动）+
批2 六颗（Label/Toolbar/StatusBar/Clipboard/HorizontalRadioGroup）与 SplitButton
（组合糖，非独立导出、上游无 widget）。组件在 component_shell 里
**早已导出**，工厂只是把 `El::catalog('导出名', …)` 的裸通道换成
有名字、有默认值、有 PHP 侧枚举预校验的糖。片段与 `examples/_shared/features/kit.php`、
`examples/kit/src/main.php` 的真调用一致（下文标注的动画件在示例里以 `->hidden()` 渲染，
理由见 Skeleton 一节）；每颗工厂至少一条门住在 `php bin/gpui selftest kit`

### 分割线 Separator

```php
El::separator('horizontal')->label('或')->color('#e07b39');   // 可嵌字与语气色是方法表的 label/color
El::separator($v);   // $v ∈ El::SEPARATOR_VARIANTS 的键：
                     // horizontal | vertical | dashed | vertical_dashed
```

四个变体是**四个导出名**（`Separator`/`VerticalSeparator`/`DashedSeparator`/
`VerticalDashedSeparator`，separator.cpp kConstructors），不是构造实参——写错变体当场抛
`InvalidArgumentException`，而不是降级成默认横线。可嵌字（Ely 的 `label("or")`）与
语气色走方法表的 `label`/`color`，分别复用既有链式糖 `->label()` / `->color()`。

### 提示触发器 Tooltip

```php
El::catalogTooltip('kit-tt', '悬停我', '这是 catalog Tooltip 的受管提示文本');   // (id, 按钮标签, 提示文本) 三实参全非空
```

上游的 `Tooltip` 是"一颗自带受管提示的按钮"（materialize 出的是 `component::Button`），
不是任意元素的悬停修饰——后者是实例方法 `->tooltip('…')`（给别的 catalog 组件挂提示）。
因为与那个实例方法重名，工厂按 `catalogButton` 先例加了 `catalog` 前缀。

### 骨架屏 Skeleton

```php
El::skeleton()->w(120.0)->h(16.0);              // 默认主色，形状交给样式
El::skeleton()->secondary()->w(120.0)->h(16.0); // ->secondary() 是上游唯一变体
```

**Skeleton 是脉冲动画**（"An animated loading placeholder"）：放进截图视口，快照相位随
帧时序漂，像素基线每跑必抖（实测恒定 gdfrac≈0.025）——kit 树1 里这一行带 `->hidden()`：
display 级隐藏让 materialize 照常跑（`registeredError()`/`componentFailure()` 两个门
照常量它），像素恒为零、基线逐字节确定。Ely 的 `SkeletonText/SkeletonCard/…` 变体上游
没有导出：卡片/文本条等形状用 div+样式链组合（`El::vFlex([...])` 里摆几颗不同宽高的
Skeleton），真变体需要上游加导出名。

### 空状态 EmptyState

```php
El::emptyState('icons/square-terminal.svg', '还没有任务', '创建第一个任务开始使用',
    El::button('新建任务'));            // 第 4 参（动作按钮区）可省
```

组装式工厂，内部发 `Empty` 五件套：`EmptyMedia`（icon 变体 + `El::icon($icon)`）/
`EmptyTitle` / `EmptyDescription` / `EmptyContent`（给了 `$content` 才发）。icon
实参走 `El::icon()` 同一款校验：必须是 `icons/<base>.svg` 且 base 在编译表里，否则静默不画。
默认走 Empty 根的**普通子节点**（empty.cpp 的 Root 分支）。

第 5 参 `$headerSlot=true` 走上游原生 **header 槽路**：`EmptyHeader` 的
`media/title/description` 是方法实参 SchemaElement（empty.cpp 的 RecordSlot 方法表），
由 `->elementArg()` 桥（cmd/patch-element-arg.sh）解锁——见下文「批5 底层补洞」一节。
注意实参必须是**注册侧 Empty 部件元素**（`El::catalog('EmptyMedia', …)` 等），
不是裸 icon/text（上游 `TakeElementAs` 按 tag 取值，裸元素会被拒）。两条路视觉不同。

### 徽标 Badge 与标签 Tag

```php
El::hFlex([El::button('收件箱'), El::badge(9, false, 99)])->gap(4.0); // 数字徽标（超 max 变 +n）
El::hFlex([El::button('通知'), El::badge(0, true)]);                  // 圆点徽标（dot）
foreach (['primary', 'secondary', 'success', 'warning', 'danger', 'info'] as $tv) {
    $tagVariants[] = El::tag($tv, $tv);            // variant: primary|secondary|danger|success|warning|info
}
El::tag('草稿', 'secondary')->outline();          // 描边形
El::tag('圆角', 'info')->pill();                  // 药丸圆角（registered_method 'rounded_full'）
El::tag('语气改写', 'secondary')->variant('warning'); // ->variant() 是 Tag 方法表的枚举糖
```

CountBadge/DotBadge 不需要单独工厂：上游 Badge 方法表本身带 `dot`/`count`/`max`，
`El::badge()` 直接透传（count 0 时上游语义是隐藏数字徽标）。徽标"盖在什么东西上"用
`->child()`（普通子节点）挂宿主元素。`->variant()` 与 `primary()` 那五个按钮变体糖**不是**
同一族：后者是 Button 的零参方法，`variant(enum)` 属于 Tag / DropdownButton / Bubble /
Marker / GroupBox / TabBar 的方法表。

### 步骤条 Stepper

```php
El::stepper('kit-steps', ['购物车', '下单', '支付'], 1, false, 'kit-steps');
//                id       步骤标签数组        零基当前步  纵排?   on_change 路由 id
El::stepper('kit-steps-v', ['起', '承', '转', '合'], 2, true);          // 纵排、不挂回调
El::stepperItem('禁用步', true);   // 单独一颗步骤（disabled 参可选）
```

`on_change` 事件以 `($onChangeId, 'change')` 路由到 `App->on($id, 'change')`；实参是一个
`El::cb('change', $onChangeId)` 标记，送达由 `selftest kit` 的 `kitChangeRoutes` 用合成 fire
验到 PHP handler（"原生控件真的点了会发"属真人验收，与所有部件回调同一分工）。
子节点契约是硬的：Stepper 只收 `StepperItem`（typed children 逐字校验）。

### 树形 Tree

```php
El::tree('kit-tree', [
    El::treeItem('kit-tree-src', 'src/')->rm('expanded', [true])->child(
        El::treeItem('kit-tree-main', 'main.php')),      // TreeItem 可嵌 TreeItem
    El::treeItem('kit-tree-lock', 'GPUI.lock'),
]);
```

`TreeItem(id, label)` 两个构造实参都必填非空（tree.cpp 逐字校验）；初始展开态用
`->rm('expanded', [true])`——TreeItem 方法表只有 `expanded`/`disabled` 两个名字，
不值得各开一条糖。Tree 只收 TreeItem 子节点（kAllowed），挂别的会每帧报错误节点。

### 分页 Pagination

```php
El::pagination('kit-pg', 2, 10, 5, 'kit-pg');   // (id, 当前页 1 基, 总页数, 可见页钮数, on_change 路由 id)
El::pagination('kit-pg')->rm('compact', []);    // 紧凑形（只有前后钮；变体示意，kit 树2 只展示参数形）
```

受控语义：上游 `on_change` 只"报告读者要去的页号"，当前页仍由脚本写回
`current_page`（descriptor 原话："so the script can drive `currentPage`"）。

### 评分 Rating

```php
El::rating('kit-rate', 3.0, 5, false, 'kit-rate');         // 可交互，on_change → kit-rate
El::rating('kit-rate-ro', 4.0, 5, true)->color('#e07b39'); // 只读（Ely Stars 展示形）
```

只读走的是 common behavior 的 `disabled` 位：Rating 方法表带 `kDisabledMethod`，
materialize 把 Method op `'disabled'` 读进 `request->disabled`——`El::disabled(true)`
发的正是这个名字，所以 `$readOnly=true` 就是这一颗糖。

### 键帽 Kbd / 组合键 KbdCombo

```php
El::kbd('⌘');
El::kbd('K')->outline();                 // 描边键帽（零参方法 outline）
El::kbdCombo(['⌘', 'Shift', 'P']);       // 组合键糖：横排发多颗 Kbd
```

Kbd 本体是静态的；它和 ShimmerText 同一行是为了排版——kit 树1 里整行带 `->hidden()`，
理由同 Skeleton（同行的扫光是动画）。

### 微光文字 ShimmerText

```php
El::shimmerText('正在加载…');
El::shimmerText('慢速反向扫光')->rm('duration_ms', [2400.0])->rm('reverse', [true]);
```

**ShimmerText 是扫光动画**：像素基线放不下它（同 Skeleton，示例里 `->hidden()`）。
变体（`id`/`duration_ms`/`spread`/`reverse`/`once`）走 `->rm()` 透传——五个名字都在
ShimmerText 方法表在册，但没有各自开糖。

### 批7 特效三件（唯一从零写 C++ widget 的批）

```php
El::typewriter('打字机效果演示文本');
El::typewriter('快速打字')->rm('speed', [10.0]);
El::gradientText('渐变文字效果演示', '#ff6600', '#0066ff');
El::animatedNumber('kit-an', 42.5, 1)->pad(3);
```

- **Typewriter 打字机文本**：逐字 reveal，`speed` = ms/字符（默认 50）。MotionAppear
  动画，与 ShimmerText 同款内部 state 管理。方法：`speed`/`id`/`color`。
- **GradientText 渐变文字**：静态渐变，每字 `RgbaMix(from,to,t)` 插值，无动画。
  ctor 三实参 `text/from/to`（from/to 是颜色字符串）。方法：`id`。
- **AnimatedNumber 数字滚动**：count_up 动画（0→value），`decimals` 小数位、`pad`
  整数位补零。MotionAppear 管理动画 state，不需要额外 retained 实体。方法：`color`/`pad`。

三件都在 `ui/text_effects.h/.cpp`（新写，Ely spec 描述、上游无），descriptor 在
`controls/text.cpp`（`cmd/patch-text-effects.sh`）。特效族视觉验收只能逐帧快照/真人
——selftest 只断"不崩 + 账本干净"，动画本身由 MotionAppear 内部管理。

### 弹层触发器 Dialog / AlertDialog / Sheet

```php
El::dialog('kit-dlg', '打开确认框',
    El::vFlex([El::text('要继续这个操作吗？'), El::text('确认后不可回退。')])->gap(4.0),
    '确认操作', 'kit-dlg-ok', 'kit-dlg-cancel');
El::alertDialog('kit-ald', '删除数据', '此操作不可撤销，确定继续？', true, '警告', 'kit-ald-ok');
El::sheet('kit-sheet', '打开抽屉',
    El::vFlex([El::text('抽屉正文：从右侧滑入。'), El::text('关闭走 on_close（kit-sheet-close）。')])->gap(4.0),
    'right', 'kit-sheet-close');
```

这一族与 `App::openDialog()` / `openSheet()` / `notify()` 是**两条路**：这三颗是"真按钮
触发的原生窗口效果"——页面上渲染的是一颗触发按钮，点击后由原生侧开窗。三条硬约束
（window_effects/mod.cpp:467-476，钉在 `dialogFamily` 门里）：

- **Dialog 与 Sheet 必须带 `content` 具名槽**（`->slot('content', $正文)`，工厂第 3 参就是它），
  且只收这一个槽名；缺槽每帧 Fail（"require exactly one content(element) named slot"）。
- **AlertDialog 拒绝一切具名槽**，正文走 `description` 方法（工厂第 3 参）。
- 构造第三实参 `on_effect_error` 是效果失败的上报回调，工厂自动以
  `($id-error, 'click')` 挂上——通常无人接，只在效果失败时才有事件。

`on_ok`/`on_cancel`/`on_close` 的回调 id 由参数给入，事件以 `(该id, 'click')` 路由；
送达由 `kitClickRoutes` 门用合成 fire 验到 PHP。

### 描述列表 DescriptionList

```php
El::descriptionList(['名称' => 'typephp-gpui', '用途' => 'PHP 原生桌面 GUI'], false, true);
//                                  关联表项（label => value）       纵排?  横排加边框?
El::descriptionList([
    El::descriptionItem('跨列项', '占两格')->rm('span', [2.0]),   // 需要跨列时直接给 Element
    El::descriptionItem('普通项', '一格'),
], true);
```

子节点契约：DescriptionList 只收 `DescriptionItem`。Ely 的 PropertyGrid 没有对应导出，
用本工厂近似（计划口径如此）。

### 高级表格 DataTable（结构已接，数据通道未通）

```php
// 真调用都住在 kit 的 --selftest 门里（树里不放它，见下方卡点）：
El::dataTable('dt-x', ['名', '量']);           // 结构门：DataTableState + render_rows/render_cell 标记
El::dataTable('kit-dt-neg', ['名称', '数量']); // 负向门：真渲染当帧失败，探针 fails 上涨
El::tableToCsv(['名', '量'], [                 // Ely to_csv 的 PHP 侧对应物，纯数据变换（kit 树3 展示）
    ['名' => '带逗号,的值', '量' => 3],
    ['名' => '带"引号"的值', '量' => 'x'],
]);
```

构造三实参是 `DataTableState` 实体 + `rows` 回调 + `render_cell` 回调；工厂把后两个编码成
`cb` 标记，但 **shim 回调通道只发事件、不带返回值**（runtime.cpp:12922 的 shimId 分支转
enqueue 即返回），上游 `SnapshotRowsWith` 拿不到行数组，真渲染当帧失败——所以现在能建的
是结构，不是数据表。行为参数（`stripe`/`bordered`/`scrollbar_visible`/`row_selectable`/
`column_selectable`/`cell_selectable`/`row_header`/`sortable`/`column_resizable`/
`column_movable`）用 `->rm()` 透传。两个实测到的事实值得写代码的人知道：

1. **这个失败不走任何账本**：DataTable 的 `Failure()` 自己返回一颗带错误文本的 Div，
   `registeredError()` 与 `componentFailure()` 都收不到。shim 经上游预留的
   `SetDataTableProbe` 测试缝（families.h:84-85）把结果记进
   `App::stateCounts()['dataTableBuilds'/'dataTableFails']`；`selftest kit` 的
   `dataTableRowsCallbackLoud` 门断"fails 上涨、builds 不动"（不断定恰好 +1：负向渲染后的
每一帧都会重新 materialize、重新失败，跑几帧是脏驱动决定的）。
2. 数据通道要走 `App::listRenderer()` 同款宿主回路（`cmd/patch-host-list.sh` 的形状），
   属 tier B；`query`/`filters`/`sorts`/`paged`/`group_by` 上游 kMethods 里也没有。

### 设置页族 Settings

```php
El::settings('kit-set', [
    El::settingPage('通用', [
        El::settingGroup('外观', [
            El::settingItem('主题', El::switchBox('深色')),   // 字段控件经 content 槽挂入
        ]),
    ]),
])
    ->rm('sidebar_width', [200.0])
    ->rm('sidebar_size_range', [160.0, 240.0]);
```

四层 typed children 契约逐层校验：Settings 只收 `SettingPage`（title 进构造）→
`SettingGroup`（title 走方法）→ `SettingItem`（title 进构造）。**SettingItem 缺 content
槽就 Fail**（"requires content(element)"）——`settingItem()` 第 2 参就是那颗字段。
三个实测到的坑：① **Settings 与 SettingPage 拒样式**——挂 `->w()` 每帧 Fail
（"carries data rather than a box, so it does not implement Styled"，descriptor 也写明
"style is rejected"）；② **原生侧栏宽度是逐帧沉降的布局反馈**（ui/setting.cpp：从第二帧
起收缩到 size range 下缘），不钉住就是像素抖动源——示例用 `sidebar_width` +
`sidebar_size_range` 钉死；③ 搜索关键词/字段排布用 `->rm('keywords', [...])` /
`->rm('layout', ['horizontal'])` 透传，默认页走 `default_selected_page`。Ely 的
SettingsSearch 需要上游 searchable_list 开名，尚无。

### 轮播 Carousel

```php
El::carousel('kit-car', [
    El::vFlex([El::text('第一页')->font(16.0)->semibold(), El::text('轮播内容甲')])->gap(4.0),
    El::vFlex([/* 第二页 */]),
    El::vFlex([/* 第三页 */]),
])->w(480.0)->h(120.0);
```

组装式工厂一次发齐八件套（`Carousel`/`CarouselContent`/`CarouselItem`/
`CarouselPrevious`/`CarouselNext`/`CarouselPagination`/`CarouselPaginationItem`），八处
构造引用**同一颗** `CarouselState`（缓存按 `(kind,key)` 命中，key=`$id`）。这与
`examples/catalog` tree 2 里手写 `El::state('CarouselState', 'ca-car', …)` 七处共享是同一
个语义——各写各的 key 就是互不相干的多颗轮播，而画面上看不出区别（只有
`stateCounts()['componentStates']` 会红）。

### 加载指示器 Spinner

```php
El::spinner();                                                    // 默认：medium、loader、线性
El::spinner()->sizeEnum('large')->spinnerIcon('loader_circle')    // 四方法各一笔
    ->color('#e07b39')->spinnerEase('ease_in_out');
```

无构造实参、**不收子节点**（Materialize 只把旋转图标包进一颗 Div，children 从不被取用）。
`size` 走既有 `->sizeEnum()`（上游 `kSizeLiterals` 与 `El::SIZES` 同表），`color` 走
`->color()`（上游 `ParseColorArgument` 收色串）；`icon`（`loader|loader_circle`）与
`ease`（`linear|ease_in_out|ease_out_quint`）是本组件私有枚举，各有白名单糖
`->spinnerIcon()` / `->spinnerEase()`（`El::SPINNER_ICONS` / `El::SPINNER_EASES`），
拼错当场抛。**Spinner 是旋转动画**：进像素基线必须 `->hidden()`（同 Skeleton 的相位
抖动，kit 树1 就是这么摆的）。

### 表格页脚 TableFooter

```php
El::catalog('Table', [], [
    El::catalog('TableHeader', [], [ /* TableRow > TableHead… */ ]),
    El::catalog('TableBody', [], [ /* TableRow > TableCell… */ ]),
    El::tableFooter([
        El::catalog('TableRow', [], [
            El::catalog('TableCell', [], [El::text('合计')]),
        ]),
    ]),
]);
```

**只有 registered 一条路**：shell 的 `ComponentKind` 枚举里没有 `TableFooter`
（Table 段止于 `TableCaption`），shim 的 `kindFromString` 到不了它——这颗导出名
（structured/table.cpp kFooter）只能走 `reg()` 通道。children 只收 `TableRow`
（`MaterializeGroup` 的 kAllowed 逐字校验），且必须是**注册侧**的行：`TakeTypedChildren`
只给 `Registered` 子节点记 componentName，kind 版 `El::tableRow()` 记出来是空名，
挂进来每帧报错误节点。整套主题表格（`Table`/`TableHeader`/`TableBody`/`TableFooter`）
都在注册侧；基座 kind 表（`El::table()` 那批）是无样式 div 族（base/table.h），
两个家族别混。

### 分组盒 GroupBox

```php
El::groupBox('外观', [El::text('分组内容甲')])->variant('fill');
El::groupBox('边框', [El::text('outline 形')])->variant('outline');
El::groupBox('默认', [El::text('normal 形')]);   // 不发 variant 即 normal
```

构造无实参；title 走方法（复用 `->title()` 糖），children 是普通子节点。
variant 的字面量集是 **normal/fill/outline**（group_box.cpp:78）——与 Tag 的语气色
不是一组。`->variant()` 的白名单因此是各组件字面量集的**并**（Tag 六色 + GroupBox 三形）：
集外的值（如 `'card'`）PHP 当场抛；集内他组件不认的字面量（如 GroupBox 发 `'primary'`）
由上游 enum 响亮拒（`registeredError()` 报得出），PHP 侧并表不重复拦——这是共享糖的
已知代价，换来的是只记一个方法名。

### 纵排表单 VForm

```php
El::vForm([
    El::field([El::checkbox('每周摘要')])->label('订阅'),
    El::field([El::switchBox('深色模式')])->label('外观'),
]);
```

`'VForm'` 与 `'Form'` 是**同一构造器** `ConstructForm<false>` 的两个导出名
（form.cpp:276-280，descriptor 共用同一张方法表）——形状与 `El::form()` 全同：
children 只收 `Field`（kAllowed 逐字校验）。何时用它而非 `El::form()`：对着上游
示例/文档的名字逐字复刻时。

### 输入组文字 InputGroupText

```php
El::inputGroupText('https://');    // 前缀/后缀装饰件：弱化文字
```

无构造实参、无方法、普通子节点（`FinishPart` 照单全收），`$text` 包成一颗 text。
今天只能单放：整个 `InputGroup` 组合拼不齐——组的 `addon` 方法吃 SchemaElement 实参
（shim 通道给不出 SpecId，同 EmptyHeader media/title 的缺口）、`input` 槽要
`InputGroupInput`/`InputGroupTextarea`（另需 retained state 实体）。装饰件本身独立渲染。

### 文本标签 Label

```php
El::catalogLabel('src/Element.php')->secondaryLabel('辅')->masked()->labelHighlights('Element');
// catalogLabel 带 catalog 前缀：本类已有实例糖 ->label(string)（Button/Field 的标题），
// PHP 不允许同类里静态/实例同名方法并存——按 catalogTooltip 先例加前缀。
```

`El::label` 同名静态法在 PHP 里不允许与实例糖并存，所以工厂命名为 `catalogLabel`
（语义区别同 `catalogButton`：这是 Label 组件本身，那是给别的组件挂标题）。
构造一个非空字符串（上游拒空串）；次级灰字走 `->secondaryLabel()`、掩码走
`->masked()`、搜索高亮走 `->labelHighlights()`。**highlights 的实参是单个匹配串**
（controls/text.cpp:193-194 是 `SchemaString`，shell 侧 `Label::Highlights(Str)`
走全文匹配——清单侦察时猜的数组是错的，开工前已按 descriptor 核实）。

### 工具栏 Toolbar

```php
El::toolbar('kit-tb', [
    El::catalogButton('kit-tb-new', '新建')->primary(),   // 子节点不挑名字（普通子节点）
    El::button('打开'),
    El::catalogLabel('3 项已选'),
]);
```

id 非空；**无方法表**；普通子节点按源序进 Contents（display/toolbar.cpp 原话："a
transparent, sizable toolbar container whose controls render in source order"）。
按钮若要带样式变体（primary 等），用 `El::catalogButton()` 而不是 `El::button()->primary()`
——后者是 gpui-base 原语，`->primary()` 打上去会被实例糖拒（只对 catalog 组件有效）。

### 状态条 StatusBar

```php
El::statusBar([El::catalogLabel('就绪'), El::catalogLabel('UTF-8')]);   // 普通子节点填中段
```

构造无实参；**普通子节点填中段**（Materialize 逐颗 `bar->Center()`）。`left_content` /
`right_content` 两方法吃 **SchemaElement** 实参（status_bar.cpp:59-60）——shim 的
Element 分支只会把一个数字当 SpecId（gpui_shim.cpp `toComponentArg` 的 Element case），
PHP 侧给不出合法实参，与 EmptyHeader media/title 同一个 shim 缺口（tier B）。本工厂
**不发**这两个方法；硬发 `->rm('left_content', …)` 的下场是 native 物化阶段段错误
（自测里这条探针因此被注释掉、按"不可无头验证"处理，见 kit selftest 的说明）。

### 复制按钮 Clipboard

```php
El::clipboard('kit-clip', 'typephp-gpui', '点击复制仓库名');
// （value/tooltip 两个方法可省；拒 children——EnsureNoChildren）
```

id 进构造；`value`/`tooltip` 走方法。**点击复制是部件内置行为**
（ui/clipboard.cpp `ClipboardState::OnCopy` → `ClipboardSetText`）：descriptor 没有
`on_copied` 导出、没有 PHP 回调，selftest 的合成 fire 按 (id,type) 查回调表到不了——
行为门 `clipboardCopyRoute` 是**前提式**：断"没有合成路"这个事实本身，真复制要真人
点击（HID），未验不判红（focusRovingTab 先例）。

### 横排单选组 HorizontalRadioGroup

```php
El::hRadioGroup('kit-hrg', ['按名称', '按路径', '按大小'], 1, 'kit-hrg');
//              id            标签串（内部发注册侧 Radio，id 派生 $id-r<i>）
//                selected 零基走 selected_index；onChangeId 给了就挂 on_change（事件以 ($id,'change') 路由，值是选中下标）
```

与 `El::radioGroup()` 同形状（typed_compound/mod.cpp:456-465 的
`ConstructRadioGroup<Axis::Horizontal>`）：children **只收注册侧 Radio**
（kRadioName={"Radio"} 逐字校验，compound/radio.cpp:140——kind 版 `El::radio()`
没有 componentName，挂进来每帧报错误节点）。`on_change` 实参是 `El::cb('change', $id)`
标记，路由由 `kitChangeRoutes` 门用合成 fire 验到 PHP。

### 分裂按钮 SplitButton（组合糖）

```php
El::splitButton('kit-split', '导出…', ['导出为 PNG', '导出为 CSV']);
// item 回调 id 派生为 $id-item-<i>（事件以 (该id,'click') 路由）
```

**这是组合形，不是独立导出**：上游与参考仓都没有 SplitButton widget（grep src/ 零命中；
Ely 的实现 `ely-core/src/menus/hosts.rs` 本身就是"按钮+挂 Menu"的组合件），不值得为
它写新 widget。本工厂只发 DropdownMenu 导出名（触发钮 + 菜单项），与
`El::catalogButton()` + `El::dropdownMenu()` 各发一颗的组合是**两个独立组件的组合**，
本糖只是把"纯菜单触发的按钮"这最常见的一形起了名字。签名字段 `$menuItems` 是菜单标签
串，回调 id 派生，`splitButtonShape` 门钉住。

### 通知 Notification

```php
El::catalogNotification('kit-not', '标题', '一条提示消息', 'warning',
    'kit-not-ok', 'kit-not-close')
    ->notificationOnClick('kit-not-ok')
    ->notificationOnClose('kit-not-close');
```

与 `App::notify()` 是**两条路**：`App::notify()` 是宿主保留态的通知窗效果（保留态、
可点可追踪，见[事件与回调](/guide/events)）；这个 factory 是
catalog 的 `Notification` 导出名（window_effects/mod.cpp:730-738），纯组件层、没有
宿主保留态。构造三实参 id/label/on_effect_error 必填（factory 按 dialog 家族先例自动
挂 on_effect_error 回调标记）；message/type（info|success|warning|error 白名单）/
on_click/on_close/autohide 走方法糖（枚举走 NOTIFICATION_TYPES 白名单）。

### 下拉按钮 DropdownButton

```php
El::dropdownButton('kit-db-btn', '导出菜单', 'secondary', 'bottom_right',
    'kit-db-btn', ['导出 PNG', '导出 CSV']);
// 糖：->dropdownButtonVariant('danger') / ->dropdownButtonAnchor('top_left') /
//     ->dropdownButtonOnClick($id) / ->dropdownMenuItem('项X', $itemId)
```

构造 id/label 两个非空串；变体（primary/secondary/danger/ghost）、锚点
（top_right/bottom_right/bottom_left/top_left）是私有枚举白名单（`DB_DROPDOWN_*` 常量）；
`menu_item` 吃 label + 回调标记（与 dropdownMenu 的 `item()` 糖同形）。菜单弹层在
构造时按 `$id` 建在按钮名下（同名按钮各一棵菜单），行回调 id 派生 `$id-item-<i>`。

### 气泡 / 标记 Bubble / Marker

```php
El::bubble('start', 'filled')->child(El::catalogLabel('标题气泡'));   // alignment/variant
El::bubble('', 'secondary')->child(El::catalogLabel('普通气泡'));
El::marker('kit-mk', 'separator', true, 'spinner');                   // variant/loading/loading_style
```

Bubble 构造无实参（普通子节点是其可见内容），alignment ∈ {start}（chat.cpp
kAlignmentLiterals 实为 {"start", ""}，第二个是空字面量）、variant 七种语气形；
Marker 构造 id 非空，variant（plain/separator/border）、loading(bool)、
loading_style（spinner/shimmer）走私有枚举白名单（`MARKER_*` 常量）。

### 消息 / 消息滚动 / 附件 Message / MessageScroller / Attachment

```php
El::message('start', [El::catalogLabel('消息甲')]);                    // alignment + 子节点
El::attachment('kit-att', 'uploading', 'vertical', 'large');            // status/axis/size 三枚举
```

Message 构造无实参（alignment 枚举 + 普通子节点进内容槽）；Attachment 构造 id 非空，
status（pending/uploading/processing/failed/complete）、axis（horizontal/vertical）、
size 走私有枚举白名单（`ATTACHMENT_*` 常量）。**MessageScroller 只建结构**：它的
ctor 三实参 id/state/render_item，其中 render_item 是"返回元素"的回调——shim 回调
通道只发事件不带返回值（DataTable 实证，见 Element.php 的 dataTable 注释），真数据等
批6宿主回路；state 经 `El::state('MessageScrollerState', …)` 标记。它不进任何树
（会让 componentStates 计数涨，破坏 tree2CarouselState 的 ==1 门）。

### 图表七连（批6 数据回路接通）

```php
// 五图（结构已接，数据通道未通——回调通道只发事件不带返回值）
El::barChart(); El::lineChart(); El::areaChart(); El::pieChart(); El::radarChart();
// 五个导出名（chart/mod.cpp:495-499）：构造只吃 rows 回调
// (cx) => { label: string; value: number }[]——真数据等宿主数据回路。

// 批6 新接的两张图（数据回路已通）：
El::candlestickChart('kit-candle');
// CandlestickChart（chart/mod.cpp kCandlestickChart，cmd/patch-candlestick.sh）：
// rows 回调吃 {open,high,low,close} 四字段行，与五图同一条 SnapshotRowsWith 路。
// up/down 色由 widget 的 New 取 theme chartBullish/chartBearish。

El::sankeyChart('kit-sankey');
// SankeyChart（chart/mod.cpp kSankeyChart，cmd/patch-sankey.sh）：双回调
// render_nodes（[{label}…]）+ render_links（[{source,target,value}…]），
// descriptor 命令式建图（New → Node×n → Link×n）。新 Op::ShowValues（show_values）。
```


### SearchableList 可搜索列表（批6 数据回路接通）

```php
El::searchableList('kit-sl', 'kit-sl-rows')->w(320.0)->h(200.0);
// SearchableList（delegate_collections/searchable_list.cpp，cmd/patch-searchable-list.sh）：
// rows 回调吃 [{title,value?,section?,disabled?,badge?}]，descriptor 转
// SearchableListItem 数组 + SearchableListDelegate::Items 工厂（默认函数表：
// matches 是 title 的大小写不敏感子串）。query 传 null（widget 安全跳过搜索输入框）。
// 行渲染不发 render_item 回调：delegate 的 renderItem 留 null，widget 用默认行
// （title + icon + badge）。元素通道（render_cell/renderRow）未实现（批6 只做数据
// 通道），发元素回调会走 BuildComponentElement 的 shimId 分叉 → 非确定性 SIGBUS。
```

### 问卷 Questionnaire 家族

```php
El::questionnaire('kit-q', [
    El::questionnaireItem('age', '年龄', [
        El::questionnaireChoice('18', '18 岁以下', null, false, true),
    ])->rm('required', [true]),
], 'letters');
// 根 shortcuts ∈ {letters, numbers}（白名单 QUESTIONNAIRE_SHORTCUTS）
```

上游最深的嵌套家族：Questionnaire 根（ctor id）→ QuestionnaireItem（ctor name/label）
→ QuestionnaireChoice（ctor value/label）/ QuestionnaireInput（ctor state + label），
typed children 逐层校验（Questionnaire 只收 Item；Item 只收 Choice/Input）。根的
shortcuts 发枚举（字母/数字快捷键）；事件面核对过——该家族**没有**选项变化回调，
焦点/导航/提交由 retained state 内部驱动（QuestionnaireState 按 id 键保留、schema
指纹变化才重建），无需新 patch。`questionnaireShape` 门钉住嵌套形状 + shortcuts 白名单。

### Accordion catalog 版

```php
El::catalogAccordion('acc-cat', 3, false, true, 'medium', 'acc-cat-tog');
// kind 版是 El::accordionItem 三段件（header/trigger/panel + open(true)）——两版同组件。
```

kind 版（`El::accordionItem`）是三段件组合（header/trigger/panel 四个 kind），
catalog 版（registered Accordion/AccordionItem 导出）带 multiple/bordered/size/
on_toggle 方法表——同一组件的第二张脸，用法按需求选。**title 是 SchemaElement shim
缺口**（kTitleArgs = {"title", SchemaElement()}——发字符串实参在 native 物化阶段段错误，
实测）——本工厂按**项数**建 item、不发 title，标题留到 shim Element 通道（tier B）；
`accordionCatalogShape` 门钉住构造形状与 childOps 为空（无 title 的预期形状）。

**kind 版根自批3 起也有了**（2026-10-30）：`El::accordion($id, $items)`——上游本体是
Div+Id+Role(Group)（base/accordion.cpp:5-8，极薄），价值在把 accordionItem 三段件
合法挂树；shim 的 `kKindNames` 本批补了 `{"accordion", K::Accordion}` 登记（不登记
kindFromString 不认、节点直接拒建写 lastError——三段件三条先例在旁）。items 只收
`accordionItem()`（typed 校验当场抛）。至此手风琴**三张表达**齐了：kind 三段件 +
kind 根（aria 语义分组）、catalog 成品（multiple/bordered/size）。

### Scroll 视口 + Scrollbar 条（批3，2026-10-30，共享 ScrollbarHandle）

15 个上游 state kind 的最后一颗 `ScrollbarHandle` 在本批闭合（creator 无参，
`NewHostState` 直通实测可用）：

```php
// Scroll 视口：children + 泛 style 合法，默认竖滚；Scrollbar 条：拒 children/泛
// style（物化成绝对定位空 Div 覆在视口上），条本体由共享 handle 的视口侧画。
El::vFlex([
    El::catalogScroll('kit-sb', [$rows])->gap(4.0)->p(8.0)->w(220.0)->h(96.0),
    El::catalogScrollbar('kit-sb', 'vertical', 'always'),   // 同 key = 共享 handle
])->relative();
```

- **同一个 key 字符串就是"把手控制视口"的接缝**：`El::state('ScrollbarHandle', $key)`
  按 (kind,key) 缓存，Scroll 与 Scrollbar 传同一个 `$key` 才共享一颗实体。
- `scrollAxis()` 糖三值白名单 `vertical|horizontal|both`——方法名是 `scroll_axis`
  不是 `axis`（运行时元素原型先拦 axis、`both` 到不了 registered 组件，上游特意改名）。
- Scrollbar 的 `mode` 三档与 kind 面的 `scrollbarMode()` 同值不同通道
  （registered_method vs behavior 链）。
- 像素证据：kit selftest 的 `scrollbarHandleProbe` 门（实体 +探针 + 有条/无条盒
  右缘 regionRgb 对拍——upstream ApplyBar 自绘路径，不受 macOS NSScroller 自动隐藏影响）。

### transition / spring 动画糖（批3，2026-10-30）

```php
El::vFlex([El::text('')])
    ->id('kit-anim-box')                      // 必须落 id：跨帧动画身份 = id op
    ->w(240.0)
    ->transition('width', 300.0);             // 或 spring('width', 250, 1, 0.001)
```

- 上游是 materialize 途中的**采样器**（MotionValue/SpringValue 每帧回写
  element->style，存储在 WindowMotionState 跨帧持久），不是 CSS 动画。
- **实际被采样的只有五项**：opacity/width/height/left/top（width 吃 `w`/`size`
  样式 op、height 吃 `h`/`size`）——白名单 `MOTION_PROPERTIES` 当场拒集外名。
- easing 三值白名单 `linear|ease-in|ease-in-out`（空串走上游默认）；
  spring 的 response/damping/epsilon 默认 250/1/0.001。
- kit selftest 的 `transitionInterpolation` 门做三臂断言：初始帧探针=背景、
  翻宽后 2 帧仍=背景（排掉 specId 身份漂移导致的逐帧跳变）、泵到收敛=盒色——
  早期臂与收敛臂合起来才是"插值可见"。

### catalog 四颗升级糖（Select/Combobox/Popover/TabBar）

```php
El::catalogSelect('kit-sel', '请选择')->selectMenuWidth(200.0);
El::catalogCombobox('kit-cb', '下拉搜索', '搜索提示', 'kit-cb-change', 'kit-cb-confirm')
    ->comboboxSearchable()->comboboxMenuWidth(220.0);
El::catalogPopover('kit-po', '弹窗', El::vFlex([El::catalogLabel('弹窗正文')])->gap(4.0));
El::catalogTabBar('kit-tb', ['名称', '路径', '大小'])
    ->tabBarMenu()->tabBarSelectedIndex(1)->tabBarVariant('tab')->tabBarSize('large')
    ->tabBarOnToggle('kit-tb-toggle');
```

kind 层同名能力不等价（裸框 vs 带样式/方法表）——catalog 第二张脸按
catalogButton/button() 双轨先例。四颗各自的实参形状：

- **Select**：ctor 四实参（id + rows/render_row/on_select 三回调）；rows 是**返回数据**
  的回调（shim 回调通道不带返回值，DataTable 实证）——只建结构、不发数据通道，
  真数据等批6（SelectProbe 探针无 shim 读口，同图表五连卡点）；placeholder 走方法。
- **Combobox**：ctor 四实参（id + rows + on_change + on_confirm）；方法表
  placeholder/search_placeholder/searchable/menu_width/disabled。
- **Popover**：ctor 两实参（id/label），content 走具名槽（硬约束 require content(element)）；
  方法表 card_anchor（8 角 POPOVER_ANCHORS 白名单）/default_open/open/appearance/
  overlay_closable 布尔 + on_open_change 回调。**catalogHoverCard 自批2 起可进树**
  （2026-10-30 订正：原句"不进树，trigger_element 是 shim 缺口"失真——批5 的
  element_arg 桥已通用，工厂加 `?self $trigger` 第十一参，经
  `->elementArg('trigger_element', …)` 喂入；上游对缺 trigger 是每帧 loud Fail
  "HoverCard requires trigger_element(element)"（hover_card.cpp:79-82/145），是硬要求
  不是静默缺口；kit 树 3 已有真 trigger 样例）。
- **TabBar**：ctor 两实参（id + tabs）；children 只收注册侧 Tab（kind 版 `El::tab()` 不是
  注册节点，挂进去每帧报错）；方法表 menu/selected_index/variant（tab|outline|pill|
  segmented|underline 白名单）/size/on_change——菜单开关/选中下标/变体/尺寸/回调各走
  实例糖（构造签名里带 bool/int 默认值的参数一律收糖——TypePhp 翻译器在"混合默认值 +
  实参个数不完整"的签名形状上会生成默认值 re-assign 缺陷，示例直接翻译失败，实测）。

### 裸通道三组专用工厂（批2，2026-10-30）

coverage 页原"裸通道四组"里除 Accordion 根外的三组，本批从 descriptor 现读补齐形状
（纯 PHP 层零桥零补丁）：

```php
// Command 命令面板三件套（command.cpp）：Group(label) 只收 Item；Item(label)
// 方法 disabled/keyword/checked/action 走 ->rm()；Separator() 无参。
// 根 catalogCommand 的 children 白名单同步收紧到三导出名。
El::catalogCommand('kit-cmd', [
    El::commandGroup('文件', [
        El::commandItem('新建文件')->rm('action', ['kit-cmd-new'])->rm('keyword', ['new create']),
    ]),
    El::commandSeparator(),
    El::commandItem('切换主题')->rm('checked', [true]),
]);

// NativeMenu 三件套（native_menu.cpp）：trigger 的 on_effect_error 是 materialize
// **硬要求**（缺了每帧 Fail），错误回调 id 是必填第三参；children 只收
// Item(label,action)/Separator。行选中派发 ShellAction → 'action' 事件
// （事件 id = action 名），$app->on('kit-nm-copy', 'action', …) 收——真点选要真人。
El::nativeMenuTrigger('kit-nm', '原生菜单', 'kit-nm-err', [
    El::nativeMenuItem('复制路径', 'kit-nm-copy'),
    El::nativeMenuSeparator(),
]);

// Resizable catalog 第二张脸（layout/resizable.cpp）：与 kind 面 hResizable()/
// vResizable() 同组件两套孩子形态——catalog 版 ctor id、children 只收
// catalogResizablePanel、布局状态上游 keyed state 内部持有（PHP 不喂 ResizableState）。
El::catalogResizable('kit-rz', [
    El::catalogResizablePanel(El::text('甲'))->panelSize(180.0)->panelSizeRange(120.0, 320.0),
    El::catalogResizablePanel(El::text('乙'))->panelVisible(true),
])->axis('horizontal')->crossSize(96.0);
```

- `axis()` 两值白名单 `horizontal|vertical`（上游对未知值 loud Fail）；`crossSize`/
  `panelSize`/`panelSizeRange` 前置上游 FinitePositive 与 min≤max 校验，越界当场抛。
- panel 的 `size`/`visible` 与 kind 面同名糖撞名（:size 枚举糖、:visible nullary
  样式），按 catalogButton 别名惯例出 `panelSize`/`panelSizeRange`/`panelVisible`。
- Resizable 拖拽把手要真人 HID（patch-resize-handle.sh 既有探针口径）——静态基线
  只钉面板树建出与糖发名。

- **MenuBar**：已由宿主应用身份与 app-effect trampoline 接通；子节点必须遵守 Menu/Item 契约，不能用缺 Root 作为不可用理由。
- **Dock**：默认原生 chrome、面板与布局读写已接通；PHP 自定义 chrome 和真人拖拽验收尚未提供。
- **WebView / Inspector 面板 / 通知层**：已可用作元素——`El::webview($url, $id)` /
  `El::webviewHtml($html, $id)` / `El::inspector($id)` / `El::notifications($id)`，走
  `ComponentKind::ChildView` + host 侧 nested-view 注册（`cmd/patch-nested-view.sh`）。webview 是盖在窗口上
  的 OS 控件：它挡住元素树里排在它后面的东西、不随容器滚动、没有圆角，所以要给它自己的一块
  盒子。通知层反过来：它自己就是绝对定位的浮层，给它的那颗 `El::notifications()` 摆在哪、
  多大都不影响卡片位置（卡片按 `placement` 贴窗口边角），但**必须摆一颗**才画得出来——
  ShellRoot 不挂它，见[系统能力的运行约束第 4 条](/guide/system-capabilities#运行约束)。推荐摆法
  `El::notifications('notes')->sizeFull()->absolute()`（tree32 基线与交互 demo 用的就是这一形状）。
- **Alert / Icon / SidebarMenuItem**：拒 children / 无样式——工厂里别 `->child()`。
- **`El::icon($path)` 的名字错了不报错**：`AssetIconForPath` 要字面 `icons/<base>.svg`
  且 base 必须在编译进去的 111 个表里，任一条不满足就返回空，渲染侧对空是**静默什么都不画**。
  PHP 侧现在挡形状（`icons/…\.svg`），base 在不在表里挡不住——`tests/cpp/shim_test.cpp`
  的查表探针钉住这一层。另注意 `navIcon('home')` 那五个名字是上游另一套**逻辑名**
  （映射到 SquareTerminal/LayoutDashboard/Settings2/BookOpen/User），与 `icons/x.svg`
  的 base 不是一个命名空间。

### 环形进度 ProgressCircle（上游 descriptor 补洞）

```php
El::progressCircle('pc-x', 62.0)      // id + 百分比（0–100，上游自己 clamp）
    ->progressCircleLabel()          // 环心印百分比（registered_method 'label'）
    ->progressCircleSize(72.0)       // 像素 Number（widget 的 Size(float)，不是 kSizeLiterals 枚举）
    ->progressCircleColor('#e07b39') // ParseColorArgument（spinner 同款）
    ->progressCircleLoading();       // 不定态旋转（动画——进像素基线要 hidden，同 Skeleton）
```

widget 早就随 vendored 上游在树里（ui/progress.h），缺的是 catalog 面：批5 用
`cmd/patch-progress-circle.sh` 在 compound/progress.cpp 照 kProgress 的五件套样式补了
descriptor 并并入 `RegisterCompoundProgress`——**新导出名，词表计数 137→140**。
`value` 是百分比 Number（0–100）；`loading` 转不定态旋转，是动画。

### 弹层菜单 PopupMenu / ContextMenu（上游 descriptor 补洞）

```php
El::popupMenu('pm-x', ['新建任务', '导出 CSV']);                 // rows 是行标签串数组
El::contextMenu('cm-x', El::button('右键目标'), ['复制', '粘贴']); // 第 1 子节点是右键目标
```

widget 层（ui/menu.h 的 `component::PopupMenu`/`ContextMenu` + base/popup_menu.h 的行模型）
早已在 vendored 上游，缺的是 catalog 面：批5 用 `cmd/patch-popup-menu.sh` 新建
`overlays/popup_menu.cpp`（注册链并入 overlays）。**rows 走普通子节点**：子元素的 text
是行标签。行**不带点击处理器**——Func0 的 userData 打包不暴露回调 id（如实限制，
`cmd/patch-*.sh` 注释有记）。ContextMenu 的第一个子节点是右键目标（上游
`ContextMenu::Child` 接线 OnMouseDownRight；真右键要真人 HID，selftest 里是前提式门）。

### 批5 底层补洞总览：elementArg 与 EmptyHeader

`->elementArg($name, $element)` 给 registered_method 的 **SchemaElement 实参**喂一个元素：
bridge（cmd/patch-element-arg.sh）先 emitTree 子元素拿 SpecId、再以它当实参发方法
（slot 分支的同形状；emitTree 的 op 顺序在 children 之前，所以必须像 slot 一样先建子）。
`EmptyHeader` 的 `media/title/description` 由此可喂：

```php
El::emptyState('icons/square-terminal.svg', '标题', '描述', null, true)  // 第 5 参 = header 槽路
// 或直接用形：
El::catalog('EmptyHeader', [])
    ->elementArg('media', El::catalog('EmptyMedia', [], [El::icon('icons/…svg')])->rm('variant', ['icon']))
    ->elementArg('title', El::catalog('EmptyTitle', [], [El::text('标题')]))
    ->elementArg('description', El::catalog('EmptyDescription', [], [El::text('描述')]));
```

实参必须是注册侧 Empty 部件元素（`TakeElementAs` 按 TypedChildBox tag 取值——
空 elementArg 发裸 icon/text 会得 "registered EmptyMedia materialized an
incompatible element"，实测）。`ResolveElement` 是上游成熟的 SchemaElement 实参解析
（status_bar/hover_card/input_group 同款机制）。这条桥还解锁了 StatusBar 的
`left_content`/`right_content`（此前是 shim 缺口）——但那两颗的工厂化不在本批。

## 下一步


- [事件与回调](/guide/events)
