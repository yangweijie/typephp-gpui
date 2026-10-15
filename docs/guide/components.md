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

样式糖覆盖上游 137 个 nullary 关键字里的 72 个，且 `style()` / `paramStyle()` / `method()` /
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
3. 这 47 个糖在**编出来的产物**里逐个调一遍的门住在 `php bin/gpui selftest style`
   （`nullaryStyles — 发出=47/47 去重后=47`）。放这里是有意为之：nano 里调用一个没编进去的
   方法是 SIGABRT 而不是异常，只有跑产物排得掉，而它一崩需要看见的是"崩在哪一个糖上"，
   不是那种只在末尾打印一次的总报告。

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
`El::FIELD_ALIGN` `El::NAV_ICONS` `El::ALERT_VARIANTS`。

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

## 已知限制

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

## 下一步

- [事件与回调](/guide/events)
