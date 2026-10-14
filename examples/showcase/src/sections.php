<?php

declare(strict_types=1);

/**
 * examples/showcase/src/sections.php — 分节 builders：每节把一组特性走一遍。
 *
 * 这一堆代码的唯一存在理由是"可核对的完整性"：tests/ShowcaseTest.php 从 src/Element.php 与
 * src/App.php 反射出全部公开工厂、链式方法与 App 能力，逐个断言在这里（或 main.php 的动作里）
 * 出现过。少写一个特性，CI 就红——所以"展示了所有特性"不是自述，是断言。
 *
 * 页的划分对齐上游 examples/showcase/（41 个组件页），但这里按 PHP 面的能力分组，且只调
 * 我们**确实有**的签名：registered_method 一律走 Element.php 里已有的糖（title/description/
 * banner/item/size/color/rotate/collapsed/side/collapsibleMode/navIcon/columns/labelWidth/
 * align/sizeEnum），不凭印象造方法名。
 *
 * 约定：所有交互 id 以节名前缀，免得跨节撞车（id 是事件路由键，撞了会串到别人的 handler）。
 */

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;

/** 节清单：树号（--shot 用）→ [标题, builder 函数名]。 */
function showcaseSections(): array
{
    return [
        1 => ['基础原语与文本', 'sectionPrimitives'],
        2 => ['输入与保留态取值', 'sectionInputs'],
        3 => ['选择、开关与按钮变体', 'sectionChoices'],
        4 => ['容器、布局与滚动', 'sectionLayout'],
        5 => ['表格、列表与进度', 'sectionData'],
        6 => ['catalog 富组件', 'sectionCatalog'],
        7 => ['侧栏、表单与菜单', 'sectionChrome'],
        8 => ['浮层：dialog / sheet / toast / 通知', 'sectionOverlays'],
        9 => ['系统能力：剪贴板 · url · 文件系统 · 进程 · 存储', 'sectionSystem'],
        10 => ['服务：http · 目录监听 · 解压 · 系统状态', 'sectionServices'],
        11 => ['WebView 与 Inspector', 'sectionEmbedded'],
        12 => ['样式、图标与弹层原语', 'sectionStyling'],
        13 => ['mac 集成：托盘 · 全局快捷键 · Dock · OS 通知', 'sectionMac'],
    ];
}

// 状态表（exampleState() + ExampleState）在 examples/_shared/state.php：
// 按功能域拆出去的独立示例要共用它，所以它不属于 showcase。

// ── 1. 基础原语 ─────────────────────────────────────────────────────────────
function sectionPrimitives(array $s): El
{
    return El::vFlex([
        El::text('基础原语')->font(22.0)->semibold(),
        El::text('text / link / image / svg / textView(Markdown)')->font(12.0)->truncate(),
        // link 的 href 走 gpui 那条白名单（capability.cpp）：只放行 http/https。
        El::link('一个外链（点击交给 openUrl 白名单）', 'https://example.com'),
        El::textView("# Markdown 标题\n\n支持 **粗体**、[链接](https://example.com) 与列表：\n\n- 第一条\n- 第二条"),
        El::hFlex([
            // image / svg 走资源根相对路径；文件不存在时是空块，不报错。
            El::image('assets/missing.png', '占位图')->w(64.0)->h(40.0)->roundedMd(),
            El::svg('assets/missing.svg')->w(48.0)->h(48.0),
            El::div()->w(64.0)->h(40.0)->bg(0xEEEAE3)->roundedMd(),
        ])->gap(10.0)->itemsCenter(),
        El::text("计数器（定时器驱动）：{$s['count']}")->font(14.0)->bold(),
        El::hFlex([
            El::text('disabled 样式')->font(12.0)->disabled(),
            El::text('这行被 hidden() 藏了')->font(12.0)->hidden(),
            El::text('relative + cursorPointer')->font(12.0)->relative()->cursorPointer(),
        ])->gap(12.0),
        // onHover 只是"声明此元素有 hover 事件"的回调标记；handler 在 App->on(id,'hover') 注册。
        El::hFlex([
            // onHover 要求元素有 hit-testable 几何体（bg/border/明确尺寸），纯 text 节点不参与 hover 检测。
            El::text('把鼠标放上来（onHover）')->id('pr-hover')->onHover()
                // 指针事件族（onMouseMove / 按下 / 抬起）挂在同一颗有几何体的探针上：
                // selftest 的 hover 扫描正好在这列做真移动，down/up 由 navProbe 的真点击覆盖。
                ->onMouseMove()->onMouseDownLeft()->onMouseUpLeft()
                // 右/中键这两对只是把 API 摆出来：selftest 的真点击只发左键，
                // 所以它们的送达要靠人手动一下（同 #56 那格），这里不当门。
                ->onMouseDownRight()->onMouseUpRight()
                ->onMouseDownMiddle()->onMouseUpMiddle()
                ->onMouseDownOut()->onScrollWheel()
                ->font(12.0)
                ->p(6.0)->bg(0xF4F1EC)->roundedMd()->cursorPointer(),
            El::text("hover={$s['hover']}")->font(12.0),
        ])->gap(10.0)->itemsCenter(),
        // wFull/hFull = 100% 的 w/h（非 nullary style，是带参 method op）。
        El::hFlex([
            El::div()->wFull()->hFull()->bg(0xE8F0E4)->roundedMd(),
        ])->h(28.0)->p(4.0)->border()->borderColor(0xCCC6BD),
    ])->sizeFull()->gap(8.0)->p(16.0)->overflowYScroll();
}

// ── 2. 输入与保留态取值 ──> 已搬到 examples/_shared/features/inputs.php
//    （sectionInputs + wireInputs + inputsTrees），独立示例 examples/input 与画廊共用那一份。

// ── 3. 选择、开关与按钮变体 ─────────────────────────────────────────────────
function sectionChoices(array $s): El
{
    return El::vFlex([
        El::text('选择、开关与按钮变体')->font(22.0)->semibold(),
        El::hFlex([
            El::radio('A', true),
            El::radio('B', false),
            El::toggle('Tgl'),
        ])->gap(10.0)->itemsCenter(),
        El::hFlex([
            El::radioGroup([El::radio('甲', true), El::radio('乙', false), El::radio('丙', false)]),
            El::toggleGroup([El::toggle('左'), El::toggle('中'), El::toggle('右')]),
        ])->gap(14.0)->itemsCenter(),
        El::hFlex([
            // 这颗是 **kind 原语**（gpui-base Button：只有点击与文本，没有圆角/底色/变体），
            // 后面五颗才是上游画廊里那个 catalog Button。两者同名不同物，
            // 在左边这颗上调 ->primary() 现在会直接抛（以前是静默丢，见 #66）。
            El::button('默认(kind)')->id('btn-plain')->onClick(),
            El::catalogButton('btn-p', 'primary')->onClick()->primary(),
            El::catalogButton('btn-s', 'secondary')->onClick()->secondary(),
            El::catalogButton('btn-d', 'danger')->onClick()->danger(),
            El::catalogButton('btn-g', 'ghost')->onClick()->ghost(),
            El::catalogButton('btn-o', 'outline')->onClick()->outline(),
        ])->gap(8.0)->itemsCenter()->flexWrap(),
        El::hFlex([
            // 部件自己发出的那批事件（open_change / confirm / dismiss）挂在这三颗上。
            // 它们的 callbackId 存在 ShellSelectBinding 里，由部件调 runtime->Dispatch*()
            // 发出，而打过补丁的 Dispatch 开头就有 shim 那条路（entry->shimId 非零 ⇒
            // 入本窗口事件队列，不碰 QuickJS），所以不需要新补丁就能到 PHP。
            El::select('ch-select', ['Apple', 'Banana', 'Cherry'])->w(180)
                ->onOpenChange()->onConfirm()->onDismiss(),
            El::combobox('ch-combo', ['Red', 'Green', 'Blue'])->w(180)
                ->onOpenChange()->onConfirm()->onDismiss(),
            El::datePicker('ch-date')->w(180)->onOpenChange(),
        ])->gap(10.0),
        El::text("最近点的按钮：{$s['lastBtn']}")->font(12.0),
    ])->sizeFull()->gap(12.0)->p(16.0)->overflowYScroll();
}

// ── 4. 容器、布局与滚动 ─────────────────────────────────────────────────────
function sectionLayout(array $s): El
{
    return El::vFlex([
        El::text('容器、布局与滚动')->font(22.0)->semibold(),
        El::text('flex / flexRow / flexCol / flexWrap / flexNone / flexGrow / flexShrink / gap·gapX·gapY / m·mt·mb·ml·mr / p·px·py·pt·pb·pl·pr')->font(11.0)->truncate(),
        El::hFlex([
            El::text('m + mt')->font(12.0)->m(4.0)->mt(8.0)->bg(0xE4EAF0)->p(6.0)->roundedMd(),
            El::text('mb + ml')->font(12.0)->mb(8.0)->ml(6.0)->bg(0xF0E8E4)->p(6.0)->roundedMd(),
            El::text('mr + pb/pr')->font(12.0)->mr(10.0)->pb(6.0)->pr(6.0)->bg(0xE8F0E4)->pt(4.0)->pl(6.0)->roundedMd(),
        ])->gap(0.0)->itemsStart()->bg(0xFAF8F5)->p(2.0)->roundedMd(),
        El::hFlex([
            El::text('flexGrow 1')->font(12.0)->flexGrow(1.0)->bg(0xE8F0E4)->p(8.0)->roundedMd(),
            El::text('flexGrow 2')->font(12.0)->flexGrow(2.0)->bg(0xF0E8E4)->p(8.0)->roundedMd(),
            El::text('flexShrink 0 + flexNone')->font(12.0)->flexShrink(0.0)->flexNone()->bg(0xE4EAF0)->p(8.0)->roundedMd(),
        ])->gapY(8.0)->gapX(6.0)->h(64.0),
        El::vFlex([
            El::text('itemsEnd + justifyBetween')->font(12.0),
            El::text('第二行')->font(12.0),
        ])->gap(4.0)->p(8.0)->border()->borderColor(0xCCC6BD)->itemsStart()->itemsEnd()->justifyStart()->justifyCenter()->justifyBetween()->roundedMd()->flex()->flexCol()->flexRow()->flex1(),
        // 原生滚动：overflowYScroll 是 method/behavior op（materialize.cpp:364），不是 nullary
        // style——这是 bug #18 那轮量出来的。
        El::vFlex(array_map(
            static fn (int $i): El => El::text("可滚动第 {$i} 行")->font(12.0)->px(8.0)->py(2.0),
            range(1, 40)
        ))->h(120.0)->overflowYScroll()->overflowXScroll()->overflowScroll()->bg(0xFAF8F5)->roundedMd(),
        El::hResizable([
            El::resizablePanel(El::text('左栏（可拖）')->font(13.0)->p(8.0))->size('30%')->onResize(),
            El::resizablePanel(El::text('右栏')->font(13.0)->p(8.0))->size('70%'),
        ])->h(80.0)->gap(4.0),
        El::vResizable([
            El::resizablePanel(El::text('上')->font(12.0)->p(6.0))->size('50%'),
            El::resizablePanel(El::text('下')->font(12.0)->p(6.0))->size('50%'),
        ])->h(90.0),
        El::tabs('lay-tabs')->children([
            El::tab('总览')->child(El::text('tab 内容一')->font(12.0)->p(8.0)),
            El::tab('明细')->child(El::text('tab 内容二')->font(12.0)->p(8.0)),
        ])->h(90.0),
    ])->sizeFull()->gap(10.0)->p(16.0)->overflowYScroll();
}

// ── 5. 表格、列表与进度 ─────────────────────────────────────────────────────
function sectionData(array $s): El
{
    return El::vFlex([
        El::text('表格、列表与进度')->font(22.0)->semibold(),
        // 语义表格：Table > TableHeader/TableBody > TableRow > TableHead/TableCell。
        // 每一层在上游都只是"一个带 aria role 的 div"（base/table.cpp:12-56），布局仍归
        // 样式管（所以行要自己 flexRow）；行列数与序号是 aria 读数——不写就等于对读屏的人
        // 说"这表没有行列、每格都在第 0 列"。
        El::table('da-table')->rowCount(3)->columnCount(3)->children([
            El::tableCaption('table / header / body / row / head / cell（aria 行列号已给）'),
            El::tableHeader([
                El::tableRow([
                    El::tableHead([El::text('名称')->font(12.0)->semibold()])->columnIndex(0),
                    El::tableHead([El::text('数量')->font(12.0)->semibold()])->columnIndex(1),
                    El::tableHead([El::text('状态')->font(12.0)->semibold()])->columnIndex(2),
                ])->id('da-row-0')->rowIndex(0)->flexRow()->gap(18.0),
            ]),
            El::tableBody([
                El::tableRow([
                    El::tableCell([El::text('alpha')])->columnIndex(0),
                    El::tableCell([El::text('3')])->columnIndex(1),
                    El::tableCell([El::text('ok')])->columnIndex(2),
                ])->id('da-row-1')->rowIndex(1)->flexRow()->gap(18.0),
                El::tableRow([
                    El::tableCell([El::text('beta')])->columnIndex(0),
                    El::tableCell([El::text('7')])->columnIndex(1),
                    El::tableCell([El::text('warn')])->columnIndex(2),
                ])->id('da-row-2')->rowIndex(2)->flexRow()->gap(18.0),
            ]),
        ])->w(340.0),
        // El::list 是 catalog 的 List 组件，内容由 item renderer 决定，直接塞 children 会被
        // 丢掉（gpui 自己在控制台说 "children are dropped on a list"）。桥这边还没接 renderer
        // （task #29/#35 VirtualList），所以这里留一颗 List 证明 API 在，
        // 真正能滚的列表用 vFlex + overflowYScroll 画。
        El::list('da-list')->w(240.0)->h(16.0),
        El::text('List 的 children 被上游丢弃（renderer 未接）；下面这排是 vFlex 近似的可滚动列表：')->font(11.0)->truncate(),
        // 读屏侧的语义：容器是 list、每项是 list_item，第 3 项标 aria-selected。
        // 这一族全是 behavior op（materialize 认 role / aria_* / tab_* / key_context / selectable），
        // 不画像素——所以 shotdiff 逐字节不变本身就是"只改读屏树"的证据。
        // role 名写错会当场抛（Element::ROLES 白名单），因为上游那句
        // `if (role != None) element->Role(role)` 对未知名字是完全静默的。
        El::vFlex(array_map(
            static fn (int $i): El => El::text("列表项 {$i}")->font(12.0)->px(8.0)->py(2.0)
                ->role('list_item')->ariaSelected($i === 3),
            range(1, 12)
        ))->h(110.0)->overflowYScroll()
            ->role('list')->ariaLabel('可滚动列表')
            ->tabIndex(0)->tabStop()->keyContext('ShowcaseDataList')
            ->ariaActiveDescendant()->selectable(false),
        El::text('progress / progressTrack + progressIndicator')->font(12.0),
        El::progress(0.65)->w(240.0),
        El::progressTrack([El::progressIndicator()])->w(240.0)->h(8.0)->bg(0xE6E1D9)->roundedFull(),
    ])->sizeFull()->gap(10.0)->p(16.0)->overflowYScroll();
}

// ── 6. catalog 富组件（Registered 机制）────────────────────────────────────
function sectionCatalog(array $s): El
{
    return El::vFlex([
        El::text('catalog 富组件')->font(22.0)->semibold(),
        El::text('经 component_shell catalog 按导出名分发；枚举在 render 前预校验')->font(11.0)->truncate(),
        // Alert 只暴露 title / banner / visible / size（alert.cpp:140 的 kMethods），
        // description 是 Field 的方法——硬挂上去就是 "no such registered method"。
        El::alert('ca-alert', 'default 变体', 'default')->title('Alert 标题')->sizeEnum('small'),
        El::alert('ca-alert-ok', 'success 变体', 'success')->banner(),
        El::alert('ca-alert-info', 'info 变体', 'info'),
        El::alert('ca-alert-warn', 'warning 变体', 'warning'),
        El::alert('ca-alert-err', 'error 变体', 'error'),
        El::breadcrumb(['Home', 'Library', 'Data']),
        El::hFlex([
            El::icon('icons/square-terminal.svg')->sizeEnum('large')->color('#e07b39')->rotate(0.0),
            El::icon('icons/settings.svg')->rm('rotate', [1.57]),
            El::avatar('assets/missing.png'),
        ])->gap(12.0)->itemsCenter(),
        El::hFlex([
            // collapsed() 只在 Sidebar 上有注册（sidebar.cpp:473），Collapsible 没有这个方法，
            // 硬塞会产出 "no such registered method 'collapsed'" 的错误节点。
            El::collapsible('折叠区', El::text('折叠里的内容')->font(12.0)->p(8.0)),
            El::popover('popover', El::text('popover 内容')->font(12.0)),
            El::hoverCard('hoverCard', El::text('悬停出现的卡片')->font(12.0)),
        ])->gap(12.0)->itemsCenter(),
        El::accordionItem('手风琴一节', El::text('手风琴内容')->font(12.0)->p(8.0)),
        El::dropdownMenu('ca-dd', 'DropdownMenu')
            ->item('第一项', 'ca-dd-1')
            ->item('第二项', 'ca-dd-2', 'click'),
        El::text("最近点的下拉项：{$s['lastDd']}")->font(12.0),
    ])->sizeFull()->gap(10.0)->p(16.0)->overflowYScroll();
}

// ── 7. 侧栏、表单与菜单 ─────────────────────────────────────────────────────
function sectionChrome(array $s): El
{
    return El::vFlex([
        El::text('侧栏、表单与菜单')->font(22.0)->semibold(),
        El::hFlex([
            El::sidebar('cr-sidebar', [
                El::sidebarMenu([
                    El::sidebarMenuItem('Home')->navIcon('home')->selected(true),
                    El::sidebarMenuItem('Components')->navIcon('components'),
                    El::sidebarMenuItem('Settings')->navIcon('settings'),
                    El::sidebarMenuItem('Archive')->navIcon('archive'),
                    El::sidebarMenuItem('Account')->navIcon('account'),
                ]),
            ])->side('left')->collapsibleMode($s['collapse'])->collapsed($s['sidebarCollapsed'])
              // slot 是命名槽：header/footer 走它，不是 children。
              ->slot('header', El::sidebarHeader(El::text('头部')->font(12.0)->semibold()))
              ->slot('footer', El::sidebarFooter(El::text('底部')->font(12.0))),
            El::sidebarToggleButton(),
            El::vFlex([
                El::form(true, [
                    // align 属于 Field（"Aligns the label and control within the field"），
                    // 不是 Form 的方法；Form 那边可设的是 columns / label_width。
                    El::field([El::text('字段 A')->font(12.0), El::input('cr-f1')->value('a')])
                        ->align($s['align'])->description('description 挂在 Field 上'),
                    El::field([El::text('字段 B')->font(12.0), El::input('cr-f2')->value('b')])->align('end'),
                ])->columns(2)->labelWidth(90),
                // 菜单家族的契约（component_shell/lifecycle/menu.cpp）：
                //   MenuBar 只收 Menu 子节点（:281），Menu 只收 MenuItem/MenuSeparator（:98）
                //   ——嵌套 Menu 也不行。上一版把 MenuItem/分隔符直接塞给 MenuBar、又把
                //   「最近」当嵌套 Menu，于是每帧报 `MenuBar accepts only Menu children`
                //   并在画面上渲染成 "Failed to render MenuBar"。
                El::menuBar('File', [
                    El::menu('File', [
                        El::menuItem('新建', 'cr-m-new'),
                        El::menuSeparator(),
                    ]),
                    El::menu('最近', [
                        El::menuItem('a.txt', 'cr-m-a'),
                        El::menuItem('b.txt', 'cr-m-b'),
                    ]),
                ]),
                El::menu('内联菜单', [El::menuItem('只读项', 'cr-m-ro')]),
            ])->flex1()->gap(8.0)->pl(12.0),
        ])->sizeFull(),
        El::hFlex(array_map(
            static fn (string $mode): El => El::catalogButton('cr-collapse-' . $mode, '折叠·' . $mode)->onClick()->ghost(),
            El::COLLAPSE_MODES
        ))->gap(6.0),
        El::text("左边大侧栏当前 collapsibleMode={$s['collapse']}")->font(11.0),
        // COLLAPSE_MODES 全枚举：三颗并排的迷你侧栏，各自钉死一种折叠模式。
        El::hFlex(array_map(
            static fn (string $mode): El => El::sidebar('cr-mode-' . $mode, [
                El::sidebarMenu([
                    El::sidebarMenuItem('Home')->navIcon('home'),
                    El::sidebarMenuItem('Settings')->navIcon('settings'),
                ]),
            ])->w(180.0)->h(96.0)->side('right')->collapsibleMode($mode)->collapsed(false),
            El::COLLAPSE_MODES
        ))->gap(10.0)->itemsStart(),
        El::hFlex(array_map(
            static fn (string $a): El => El::catalogButton('cr-align-' . $a, 'align·' . $a)->onClick()->ghost(),
            El::FIELD_ALIGN
        ))->gap(6.0),
        // FIELD_ALIGN 全枚举：form 的 label 对齐三档并排画出来。
        El::hFlex(array_map(
            static fn (string $a): El => El::form(true, [
                El::field([El::text('L')->font(11.0), El::input('cr-al-' . $a)->value($a)])->align($a),
            ])->w(200.0)->columns(1)->labelWidth(40),
            El::FIELD_ALIGN
        ))->gap(10.0)->itemsStart(),
        El::text("上面大表单当前 align={$s['align']}")->font(11.0),
    ])->sizeFull()->gap(10.0)->p(16.0)->overflowYScroll();
}

// ── 8. 浮层 ────────────────────────────────────────────────────────────────
// ── 8. 浮层：dialog / sheet / toast / 通知 ──> 已搬到 examples/_shared/features/overlays.php
//    （sectionOverlays + wireOverlays + overlaysTrees），独立示例 examples/overlay 与画廊共用那一份。

// ── 9. 系统能力 ─────────────────────────────────────────────────────────────
function sectionSystem(array $s): El
{
    return El::vFlex([
        El::text('系统能力：剪贴板 · url · 文件系统 · 进程 · 存储')->font(22.0)->semibold(),
        El::hFlex([
            El::catalogButton('sy-clip-set', '写剪贴板')->onClick()->ghost(),
            El::catalogButton('sy-clip-get', '读文本')->onClick()->ghost(),
            El::catalogButton('sy-clip-item', '读完整一项')->onClick()->ghost(),
            El::catalogButton('sy-url', '打开 URL')->onClick()->ghost(),
            El::catalogButton('sy-pick', '选文件')->onClick()->ghost(),
        ])->gap(8.0),
        El::text($s['clip'] !== '' ? "剪贴板：{$s['clip']}" : '剪贴板：还没读（三通道：文本/图像/文件）')->font(12.0),
        El::text($s['picked'] !== '' ? "选中：{$s['picked']}" : 'promptForPath 自带嵌套消息循环 ⇒ 只能在事件回调里调')->font(11.0)->truncate(),
        El::text('授权文件系统（先 grantRoot，之后每个调用内部自己选根）：')->font(12.0),
        El::hFlex([
            El::catalogButton('sy-fs', 'fs 写·读·列·删')->onClick()->secondary(),
            El::catalogButton('sy-proc', '跑 /bin/echo')->onClick()->ghost(),
            El::catalogButton('sy-store', '存储 set/get')->onClick()->ghost(),
        ])->gap(8.0),
        El::vFlex(array_map(
            static fn (string $line): El => El::text($line)->font(11.0)->truncate(),
            $s['sysLog'] === [] ? ['（还没点上面三个按钮）'] : $s['sysLog']
        ))->gap(2.0)->p(8.0)->bg(0xFAF8F5)->roundedMd()->h(130.0)->overflowYScroll(),
    ])->sizeFull()->gap(10.0)->p(16.0)->overflowYScroll();
}

// ── 10. 服务 ────────────────────────────────────────────────────────────────
function sectionServices(array $s): El
{
    return El::vFlex([
        El::text('服务：http · 目录监听 · 解压 · 系统状态 · HUD · Inspector · 主题')->font(22.0)->semibold(),
        El::hFlex([
            El::catalogButton('sv-http', 'httpGet（loopback，必然失败）')->onClick()->secondary(),
            El::catalogButton('sv-watch', 'watchDir 临时目录')->onClick()->ghost(),
            El::catalogButton('sv-unwatch', '停监听')->onClick()->ghost(),
            El::catalogButton('sv-inflate', 'inflate 一段 gzip')->onClick()->ghost(),
            El::catalogButton('sv-sysinfo', 'sysInfo 采样')->onClick()->ghost(),
        ])->gap(8.0),
        El::text($s['http'] !== '' ? "http：{$s['http']}" : 'http：HttpSend 阻塞 ⇒ 只接 HttpSendAsync，结果走事件队列')->font(11.0)->truncate(),
        El::text("dirChange：watch id={$s['watchId']}，命中 {$s['watchHits']} 次（合并上报，不带文件名）")->font(11.0),
        El::text($s['inflate'] !== '' ? "inflate：{$s['inflate']}" : 'inflate：三方锚定的解压（gpui 容器与真 deflate 都读）')->font(11.0)->truncate(),
        El::vFlex(array_map(
            static fn (string $line): El => El::text($line)->font(11.0)->truncate(),
            $s['sysInfo']
        ))->gap(2.0)->p(8.0)->bg(0xFAF8F5)->roundedMd(),
        El::hFlex([
            El::catalogButton('sv-insp', 'Inspector 拾取')->onClick()->ghost(),
            El::catalogButton('sv-theme', '切主题 dark/light')->onClick()->ghost(),
        ])->gap(8.0),
        // FPS_ANCHORS 全枚举：8 个锚点各一颗，HUD 真钉在那一角。
        El::hFlex(array_map(
            static fn (string $a): El => El::catalogButton('sv-fps-' . $a, 'HUD·' . $a)->onClick()->ghost()->sizeEnum('xsmall'),
            App::FPS_ANCHORS
        ))->gap(4.0)->flexWrap(),
        El::hFlex([
            El::catalogButton('sv-fps-off', '藏 HUD')->onClick()->ghost(),
            El::text("hud=" . ($s['hud'] ? 'on' : 'off') . " anchor={$s['fpsAnchor']} · 主题={$s['theme']}")->font(12.0),
        ])->gap(8.0),
    ])->sizeFull()->gap(10.0)->p(16.0)->overflowYScroll();
}

// ── 11. WebView 与 Inspector ────────────────────────────────────────────────
function sectionEmbedded(array $s): El
{
    return El::vFlex([
        El::text('WebView 与 Inspector 面板')->font(22.0)->semibold(),
        El::text('两者都经 ComponentKind::ChildView + host 侧 nested-view 注册挂进元素树。')->font(11.0)->truncate(),
        El::text('WebView 是盖在窗口上的 OS 控件：挡住后面的元素、不随容器滚动、没有圆角。')->font(11.0)->truncate(),
        El::hFlex([
            El::catalogButton('em-back', '后退')->onClick()->ghost(),
            El::catalogButton('em-fwd', '前进')->onClick()->ghost(),
            El::catalogButton('em-probe', '探针读 url/盒子')->onClick()->ghost(),
            El::text($s['probe'] !== '' ? "probe：{$s['probe']}" : 'probe：读 wry 报的当前地址与应用盒子')->font(11.0)->truncate(),
        ])->gap(8.0),
        El::hFlex([
            El::webviewHtml('<h3 style="font-family:sans-serif">webviewHtml</h3><p>由 wry 加载</p>', 'em-html')->w(300.0)->h(180.0)->border()->borderColor(0xCCC6BD),
            El::webview('https://example.com', 'em-url')->w(300.0)->h(180.0)->border()->borderColor(0xCCC6BD),
        ])->gap(12.0),
        El::text('下面是 Inspector 面板（要先开拾取模式才画得出东西）：')->font(12.0),
        El::inspector('em-inspector')->w(300.0)->h(150.0),
    ])->sizeFull()->gap(10.0)->p(16.0)->overflowYScroll();
}

// ── 12. 样式与图标 ──────────────────────────────────────────────────────────
// ── 12. 样式、图标与弹层原语 ──> 已搬到 examples/_shared/features/styling.php
//    （sectionStyling + wireStyling + stylingTrees），独立示例 examples/style 与画廊共用那一份。

// ── 13. mac 系统集成 ────────────────────────────────────────────────────────
function sectionMac(array $s): El
{
    // HOTKEY_MODS 全部走一遍：每个修饰键组合一颗按钮，注册成真全局快捷键。
    $hkBtns = [];
    foreach (array_keys(App::HOTKEY_MODS) as $mod) {
        $hkBtns[] = El::catalogButton('mc-hk-' . $mod, 'HK·' . $mod)->onClick()->ghost();
    }

    return El::vFlex([
        El::text('mac 集成：托盘 · 全局快捷键 · Dock · OS 通知')->font(22.0)->semibold(),
        El::text('托盘/快捷键/Dock/OS 通知都经 gpui_mac.mm 的 Cocoa+Carbon 直路，不碰 gpui.h。')->font(11.0)->truncate(),
        El::hFlex([
            El::catalogButton('mc-tray', '装托盘（A/B 两项）')->onClick()->primary(),
            El::catalogButton('mc-tray-off', '撤掉托盘')->onClick()->ghost(),
            El::text("trayId={$s['trayId']} 在场=" . App::trayCount())->font(12.0),
        ])->gap(8.0)->itemsCenter(),
        El::text('托盘项被点 ⇒ 事件 type=tray，值=菜单项文本（无人值守下用 fireHotkeyForTest 那条缝验派发链）。')->font(11.0)->truncate(),
        El::hFlex([
            El::catalogButton('mc-hk-1', '注册 ⌘⇧F7')->onClick()->secondary(),
            El::catalogButton('mc-hk-2', '注册 ⌥F8')->onClick()->ghost(),
            El::catalogButton('mc-hk-3', '注册 ⌃F9')->onClick()->ghost(),
            El::catalogButton('mc-hk-off', '撤最近一条')->onClick()->ghost(),
            El::text("hkId={$s['hotkeyId']} 在场=" . App::hotkeyCount() . " 命中={$s['hotkeyHits']} mods={$s['hotkeyMods']}")->font(12.0),
        ])->gap(8.0)->itemsCenter(),
        El::hFlex($hkBtns)->gap(6.0)->flexWrap(),
        El::text('上面一排按 HOTKEY_MODS 的每个键各注册一条（cmd/shift/option/control 全覆盖）。')->font(11.0),
        El::hFlex([
            El::catalogButton('mc-dock-7', 'Dock 角标 7')->onClick()->ghost(),
            El::catalogButton('mc-dock-off', 'Dock 角标清掉')->onClick()->ghost(),
            El::catalogButton('mc-osn', 'OS 通知')->onClick()->outline(),
            El::text("badge={$s['dock']} osNotify={$s['osNotify']}")->font(12.0),
        ])->gap(8.0)->itemsCenter(),
        El::hFlex([
            El::catalogButton('mc-menus', '装 app 菜单（含分隔线与子菜单）')->onClick()->primary(),
            El::text("menuCount=" . $s['menuCount'])->font(12.0),
        ])->gap(8.0)->itemsCenter(),
    ])->sizeFull()->gap(12.0)->p(16.0)->overflowYScroll();
}
