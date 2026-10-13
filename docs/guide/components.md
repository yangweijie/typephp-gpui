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
（与"有、且号是 0"分得开）——showcase 的 `--selftest` 用它盯这件事。

## 样式与行为（链式）

```php
->flexCol()->flexRow()->gap(8)->p(16)->px(8)->w(240)->h('100%')
->sizeFull()->wFull()->hFull()                 // 参数化 size/w/h + "100%"
->itemsCenter()->justifyCenter()->flex1()
->rounded(6)->roundedMd()->roundedLg()->roundedFull()->border()
->bg(0xRRGGBB)->textColor('#hex')->font(16)->bold()->semibold()
->overflowYScroll()->overflowXScroll()          // gpui 原生滚动（method/behavior op）
->id('name')->disabled()->selected()->checked()->tooltip('..')
->onClick()->onChange()->onHover()->onKeyDown()  // 声明事件；handler 在 App->on 注册
->slot('header', $child)                          // 命名槽（Popover/Avatar/Sidebar…）
```

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
的位置（侧栏导航就是这种）不要用它——`examples/showcase` 的导航行因此保留 base Button，
选中态改用 `->bold()` 表达。另外构造器里的 id 与 `->id()` 要一致：事件按元素 id 路由，
只给构造器不给 `->id()` 的话点击会落到空 id 上（`El::catalogButton()` 把这两件事一起做了）。

样式糖只覆盖上游 137 个 nullary 关键字里的 25 个，且 `style()` / `paramStyle()` / `method()` /
`callback()` 都是 private —— 没有"发一个没写糖的名字"的公开口子。缺的名字（`grid`、`self_*`、
`whitespace_nowrap`、`text_ellipsis`、`line_through`、`aspect_square`、`cursor_*` 等）目前只能用
已有糖拼出来，拼不出来就是没有。


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

### 读屏与键盘：`role()` / `aria_*` / `tab_*`
这一族全是 behavior op，**不改变像素**，只改变读屏软件和键盘轮询看到的树。
给一颗原本没有语义的 `div` / `text` 打标：

```php
El::vFlex($items)->role('list')->ariaLabel('可滚动列表')
    ->tabIndex(0)->tabStop()->keyContext('ShowcaseDataList');
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
   没这一条时表现为**偶发 SIGSEGV**（实测 3 次崩 1；单一身份下 6 次崩 1），修后 `--liveclick` 连跑 20 次 0 崩。

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

## 已知限制

- **MenuBar**：`AppEffects` 需 gpui 的 root-application（基座 `Root`），与 ShellRoot 根模型
  互斥 → 暂不能成盒。`Menu/MenuItem/MenuSeparator` 作为数据节点可 materialize。见
  [架构](/guide/architecture#渲染根模型-为什么是-shellroot)。
- **VirtualList / Dock**：依赖"回调进脚本渲染"，属同一 root-app/ScriptView 类，暂缓。
- **WebView / Inspector 面板 / 通知层**：已可用作元素——`El::webview($url, $id)` /
  `El::webviewHtml($html, $id)` / `El::inspector($id)` / `El::notifications($id)`，走
  `ComponentKind::ChildView` + host 侧 nested-view 注册（第 7 个补丁）。webview 是盖在窗口上
  的 OS 控件：它挡住元素树里排在它后面的东西、不随容器滚动、没有圆角，所以要给它自己的一块
  盒子。通知层反过来：它自己就是绝对定位的浮层，给它的那颗 `El::notifications()` 摆在哪、
  多大都不影响卡片位置（卡片按 `placement` 贴窗口边角），但**必须摆一颗**才画得出来——
  ShellRoot 不挂它，见[系统能力](/guide/system-capabilities#六条硬约束)。推荐摆法
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
