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

/** 每节的初始状态（main.php 建状态表时合并）。 */
function showcaseState(): array
{
    return [
        'count' => 0, 'name' => '', 'vol' => 30, 'agree' => false, 'lastBtn' => '—',
        'lastDd' => '—', 'sidebarCollapsed' => false, 'dialogCount' => 0,
        'hasSheet' => false, 'sheetAnchor' => '—', 'notifyCount' => 0, 'clip' => '',
        'picked' => '', 'sysLog' => [], 'http' => '', 'watchId' => 0, 'watchHits' => 0,
        'inflate' => '', 'sysInfo' => ['（还没采样）'], 'hud' => false, 'theme' => 'light',
        'probe' => '', 'note' => '', 'hover' => '—', 'key' => '—',
        'size' => '—', 'collapse' => 'icon', 'align' => 'start', 'placement' => '—',
        'fpsAnchor' => '—', 'booted' => false,
        'trayId' => 0, 'hotkeyId' => 0, 'hotkeyHits' => 0, 'hotkeyMods' => '—',
        'dock' => '', 'osNotify' => '—', 'menuCount' => 0, 'trace' => false,
        'front' => true, 'frontTries' => 0, 'evSeen' => [],
    ];
}

/**
 * 全部可变状态的唯一住所。
 *
 * 为什么不是"main() 里的一个数组 + 到处 use (&$state)"：nano 编译下，把固定类型变量
 * （php::Array）按引用交给一个动态调用会得到 RefWrap<php::Array>，它在 commit() 时
 * 直接 abort（typephp_helper.h:124）。事件处理器都是动态调用，所以引用式状态在
 * 这条路上走不通；静态表既能被处理器写，又能被每帧的 view 读到，不需要任何引用。
 */
final class ShowcaseState
{
    /** @var array<string, mixed> */
    public static array $v = [];

    public static function boot(array $over = []): void
    {
        self::$v = array_merge(showcaseState(), ['section' => 1], $over);
    }

    /** 当前状态快照，给 builder 当参数用。 */
    public static function get(): array
    {
        return self::$v;
    }

}

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
            El::text('把鼠标放上来（onHover）')->id('pr-hover')->onHover()->font(12.0)
                ->p(6.0)->bg(0xF4F1EC)->roundedMd()->cursorPointer(),
            El::text("hover={$s['hover']}")->font(12.0),
        ])->gap(10.0)->itemsCenter(),
        // wFull/hFull = 100% 的 w/h（非 nullary style，是带参 method op）。
        El::hFlex([
            El::div()->wFull()->hFull()->bg(0xE8F0E4)->roundedMd(),
        ])->h(28.0)->p(4.0)->border()->borderColor(0xCCC6BD),
    ])->sizeFull()->gap(8.0)->p(16.0)->overflowYScroll();
}

// ── 2. 输入与保留态取值 ─────────────────────────────────────────────────────
function sectionInputs(array $s): El
{
    return El::vFlex([
        El::text('输入与保留态取值')->font(22.0)->semibold(),
        El::text('input / textarea / numberInput / otpInput：值由 gpui 持有，PHP 用 text(id) 取回')->font(12.0),
        El::input('in-name')->placeholder('你的名字')->onChange(),
        El::text($s['name'] !== '' ? "Hello, {$s['name']}!" : '还没输入')->font(13.0)->italic(),
        // onKeyDown：回调 op 名是 on_key_down，bridge 剥掉 "on_" 前缀后事件类型是 key_down
        // （cpp-src/gpui_bridge.cc:49），所以 handler 注册用 $app->on(id, 'key_down')。
        El::input('in-key')->placeholder('在这里按键（onKeyDown）')->onKeyDown(),
        El::text("keyDown={$s['key']}")->font(11.0),
        El::textarea('in-note')->value('多行文本：textarea 也是保留态的')->onChange(),
        El::text($s['note'] !== '' ? "备注 {$s['note']} 个字符" : '备注还没改')->font(11.0),
        El::hFlex([
            El::numberInput('in-num')->value('42')->placeholder('数字')->onChange(),
            El::otpInput('in-otp')->placeholder('4 位')->onChange(),
        ])->gap(12.0),
        El::hFlex([
            El::slider(0.0, 100.0, (float) $s['vol'])->id('in-vol')->onChange(),
            El::text("vol={$s['vol']}")->font(12.0)->textRight(),
        ])->gap(10.0)->itemsCenter(),
        El::hFlex([
            El::checkbox('同意', false)->id('in-agree')->onChange(),
            El::switchBox('深色开关', false)->id('in-dark')->onChange(),
            El::text($s['agree'] ? 'checkbox 已勾选' : 'checkbox 未勾选')->font(12.0),
        ])->gap(12.0),
    ])->sizeFull()->gap(10.0)->p(16.0)->overflowYScroll();
}

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
            El::button('默认')->id('btn-plain')->onClick(),
            El::button('primary')->id('btn-p')->onClick()->primary(),
            El::button('secondary')->id('btn-s')->onClick()->secondary(),
            El::button('danger')->id('btn-d')->onClick()->danger(),
            El::button('ghost')->id('btn-g')->onClick()->ghost(),
            El::button('outline')->id('btn-o')->onClick()->outline(),
        ])->gap(8.0)->itemsCenter()->flexWrap(),
        El::hFlex([
            El::select('ch-select', ['Apple', 'Banana', 'Cherry'])->w(180),
            El::combobox('ch-combo', ['Red', 'Green', 'Blue'])->w(180),
            El::datePicker('ch-date')->w(180),
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
            El::resizablePanel(El::text('左栏（可拖）')->font(13.0)->p(8.0))->size('30%'),
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
        El::table('da-table')->children([
            El::tableCaption('table / tableHead / tableHeader / tableBody'),
            El::tableHead([El::text('表头行')->font(12.0)->semibold()]),
            El::tableHeader([El::text('名称')->font(12.0), El::text('数量')->font(12.0), El::text('状态')->font(12.0)]),
            El::tableBody([El::text('alpha'), El::text('3'), El::text('ok')]),
            El::tableBody([El::text('beta'), El::text('7'), El::text('warn')]),
        ])->w(340.0),
        // El::list 是 catalog 的 List 组件，内容由 item renderer 决定，直接塞 children 会被
        // 丢掉（gpui 自己在控制台说 "children are dropped on a list"）。桥这边还没接 renderer
        // （task #29/#35 VirtualList），所以这里留一颗 List 证明 API 在，
        // 真正能滚的列表用 vFlex + overflowYScroll 画。
        El::list('da-list')->w(240.0)->h(16.0),
        El::text('List 的 children 被上游丢弃（renderer 未接）；下面这排是 vFlex 近似的可滚动列表：')->font(11.0)->truncate(),
        El::vFlex(array_map(
            static fn (int $i): El => El::text("列表项 {$i}")->font(12.0)->px(8.0)->py(2.0),
            range(1, 12)
        ))->h(110.0)->overflowYScroll(),
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
        El::alert('ca-alert', 'default 变体', 'default')->title('Alert 标题')->size('small'),
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
            static fn (string $mode): El => El::button('折叠·' . $mode)->id('cr-collapse-' . $mode)->onClick()->ghost(),
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
            static fn (string $a): El => El::button('align·' . $a)->id('cr-align-' . $a)->onClick()->ghost(),
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
function sectionOverlays(array $s): El
{
    $sheetBtns = [];
    foreach (App::SHEET_ANCHORS as $a) {
        $sheetBtns[] = El::button("Sheet·{$a}")->id("ov-sheet-{$a}")->onClick()->ghost();
    }
    $noticeBtns = [];
    foreach (App::NOTICE_TYPES as $t) {
        $noticeBtns[] = El::button("通知·{$t}")->id("ov-notify-{$t}")->onClick()->outline();
    }
    // NOTICE_PLACEMENTS 全枚举：8 个锚点各一颗按钮，钉在哪一角是看得见的（像素证据）。
    $placeBtns = [];
    foreach (App::NOTICE_PLACEMENTS as $p) {
        $placeBtns[] = El::button($p)->id('ov-place-' . $p)->onClick()->ghost()->size('xsmall');
    }

    return El::vFlex([
        El::text('浮层：dialog / sheet / toast / 通知')->font(22.0)->semibold(),
        El::text('dialog 与 toast 由 ShellRoot 持有；通知层必须自己摆一颗 El::notifications()。')->font(11.0)->truncate(),
        El::hFlex([
            El::button('打开 dialog')->id('ov-dialog')->onClick()->primary(),
            El::button('关 dialog')->id('ov-dialog-close')->onClick()->ghost(),
            El::button('toast')->id('ov-toast')->onClick()->ghost(),
            El::text("dialog=" . $s['dialogCount'])->font(12.0),
        ])->gap(8.0)->itemsCenter(),
        El::hFlex($sheetBtns)->gap(8.0),
        El::text("sheet: has=" . ($s['hasSheet'] ? '1' : '0') . " anchor=" . $s['sheetAnchor'])->font(12.0),
        El::hFlex($noticeBtns)->gap(8.0),
        El::hFlex([
            El::button('带 id 的通知（可点）')->id('ov-notify-click')->onClick()->secondary(),
            El::button('关掉全部通知')->id('ov-notify-clear')->onClick()->ghost(),
            El::text("在场 " . $s['notifyCount'] . ' 条')->font(12.0),
        ])->gap(8.0),
        El::hFlex($placeBtns)->gap(4.0)->flexWrap(),
        El::text("placement={$s['placement']}（8 个锚点各一颗，点哪张卡就钉在那一角）")->font(11.0),
        El::text('通知层本体（绝对定位盖住窗口，卡片贴窗口边角）：')->font(11.0),
        El::notifications('ov-notes')->sizeFull()->absolute(),
    ])->sizeFull()->gap(10.0)->p(16.0)->relative()->overflowYScroll();
}

// ── 9. 系统能力 ─────────────────────────────────────────────────────────────
function sectionSystem(array $s): El
{
    return El::vFlex([
        El::text('系统能力：剪贴板 · url · 文件系统 · 进程 · 存储')->font(22.0)->semibold(),
        El::hFlex([
            El::button('写剪贴板')->id('sy-clip-set')->onClick()->ghost(),
            El::button('读文本')->id('sy-clip-get')->onClick()->ghost(),
            El::button('读完整一项')->id('sy-clip-item')->onClick()->ghost(),
            El::button('打开 URL')->id('sy-url')->onClick()->ghost(),
            El::button('选文件')->id('sy-pick')->onClick()->ghost(),
        ])->gap(8.0),
        El::text($s['clip'] !== '' ? "剪贴板：{$s['clip']}" : '剪贴板：还没读（三通道：文本/图像/文件）')->font(12.0),
        El::text($s['picked'] !== '' ? "选中：{$s['picked']}" : 'promptForPath 自带嵌套消息循环 ⇒ 只能在事件回调里调')->font(11.0)->truncate(),
        El::text('授权文件系统（先 grantRoot，之后每个调用内部自己选根）：')->font(12.0),
        El::hFlex([
            El::button('fs 写·读·列·删')->id('sy-fs')->onClick()->secondary(),
            El::button('跑 /bin/echo')->id('sy-proc')->onClick()->ghost(),
            El::button('存储 set/get')->id('sy-store')->onClick()->ghost(),
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
            El::button('httpGet（loopback，必然失败）')->id('sv-http')->onClick()->secondary(),
            El::button('watchDir 临时目录')->id('sv-watch')->onClick()->ghost(),
            El::button('停监听')->id('sv-unwatch')->onClick()->ghost(),
            El::button('inflate 一段 gzip')->id('sv-inflate')->onClick()->ghost(),
            El::button('sysInfo 采样')->id('sv-sysinfo')->onClick()->ghost(),
        ])->gap(8.0),
        El::text($s['http'] !== '' ? "http：{$s['http']}" : 'http：HttpSend 阻塞 ⇒ 只接 HttpSendAsync，结果走事件队列')->font(11.0)->truncate(),
        El::text("dirChange：watch id={$s['watchId']}，命中 {$s['watchHits']} 次（合并上报，不带文件名）")->font(11.0),
        El::text($s['inflate'] !== '' ? "inflate：{$s['inflate']}" : 'inflate：三方锚定的解压（gpui 容器与真 deflate 都读）')->font(11.0)->truncate(),
        El::vFlex(array_map(
            static fn (string $line): El => El::text($line)->font(11.0)->truncate(),
            $s['sysInfo']
        ))->gap(2.0)->p(8.0)->bg(0xFAF8F5)->roundedMd(),
        El::hFlex([
            El::button('Inspector 拾取')->id('sv-insp')->onClick()->ghost(),
            El::button('切主题 dark/light')->id('sv-theme')->onClick()->ghost(),
        ])->gap(8.0),
        // FPS_ANCHORS 全枚举：8 个锚点各一颗，HUD 真钉在那一角。
        El::hFlex(array_map(
            static fn (string $a): El => El::button('HUD·' . $a)->id('sv-fps-' . $a)->onClick()->ghost()->size('xsmall'),
            App::FPS_ANCHORS
        ))->gap(4.0)->flexWrap(),
        El::hFlex([
            El::button('藏 HUD')->id('sv-fps-off')->onClick()->ghost(),
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
            El::button('后退')->id('em-back')->onClick()->ghost(),
            El::button('前进')->id('em-fwd')->onClick()->ghost(),
            El::button('探针读 url/盒子')->id('em-probe')->onClick()->ghost(),
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
function sectionStyling(array $s): El
{
    return El::vFlex([
        El::text('样式、图标与弹层原语')->font(22.0)->semibold(),
        El::text('rounded / roundedMd / roundedLg / roundedFull / border / borderWidth / shadowSm / shadowMd / opacity / bg / textColor / borderColor')->font(11.0)->truncate(),
        El::hFlex([
            El::text('roundedFull + shadowMd')->font(12.0)->p(12.0)->bg(0xFFFFFF)->textColor(0x2F2F2F)->borderWidth(1)->borderColor(0xE0DAD1)->roundedFull()->shadowMd(),
            El::text('roundedLg + shadowSm')->font(12.0)->p(12.0)->bg(0xF3EFE8)->roundedLg()->shadowSm()->opacity(0.9),
            El::text('rounded(8) + roundedMd')->font(12.0)->p(12.0)->bg(0xE8F0E4)->rounded(8.0)->roundedMd(),
        ])->gap(14.0)->itemsCenter(),
        El::hFlex([
            El::text('underline')->font(13.0)->underline(),
            El::text('textCenter')->font(13.0)->textCenter()->w(120.0)->bg(0xF7F4EF),
            El::text('textRight')->font(13.0)->textRight()->w(120.0)->bg(0xF7F4EF),
            El::text('这是一段会被 truncate 截断的很长很长的文本，用来验证单行省略')->font(13.0)->truncate()->w(180.0),
        ])->gap(8.0)->itemsCenter(),
        El::hFlex([
            El::button('悬停有 tooltip')->id('st-tooltip')->onClick()->tooltip('这是 tooltip')->ghost(),
            // value/size 是 method op；rm 是 registered_method 的逃生舱；slot 是命名槽。
            El::progress(0.5)->w(120.0)->value('0.5')->size('small')->rm('size', ['large']),
            // popup 是无 catalog 名的浮层原语，内容走 child。
            El::popup()->child(El::text('popup 内容')->font(12.0)->p(6.0)),
            El::vFlex([El::text('children 数组形式')->font(12.0)])->children([
                El::text('child 单个形式')->font(12.0),
            ])->gap(2.0),
        ])->gap(10.0)->itemsCenter(),
        El::text('图标名必须在编译进去的 111 个表里，错了静默什么都不画：')->font(11.0),
        El::hFlex(array_map(
            static fn (string $n): El => El::icon('icons/' . $n . '.svg')->sizeEnum('medium'),
            ['circle-check', 'triangle-alert', 'circle-x', 'info', 'star', 'heart', 'save', 'search']
        ))->gap(10.0)->itemsCenter(),
        // SIZES 全枚举：整条列表 foreach 一遍，每个取值都真传进 size()/sizeEnum()。
        // size() 是 method op（原语的 100%/枚举字符串），sizeEnum() 是 registered 组件的糖。
        El::hFlex(array_map(
            static fn (string $sz): El => El::button($sz)->id('st-size-' . $sz)->onClick()->size($sz),
            El::SIZES
        ))->gap(8.0)->itemsCenter(),
        El::hFlex(array_map(
            static fn (string $sz): El => El::icon('icons/star.svg')->sizeEnum($sz),
            El::SIZES
        ))->gap(10.0)->itemsCenter(),
        El::text("size={$s['size']}")->font(11.0),
    ])->sizeFull()->gap(12.0)->p(16.0)->overflowYScroll();
}

// ── 13. mac 系统集成 ────────────────────────────────────────────────────────
function sectionMac(array $s): El
{
    // HOTKEY_MODS 全部走一遍：每个修饰键组合一颗按钮，注册成真全局快捷键。
    $hkBtns = [];
    foreach (array_keys(App::HOTKEY_MODS) as $mod) {
        $hkBtns[] = El::button('HK·' . $mod)->id('mc-hk-' . $mod)->onClick()->ghost();
    }

    return El::vFlex([
        El::text('mac 集成：托盘 · 全局快捷键 · Dock · OS 通知')->font(22.0)->semibold(),
        El::text('托盘/快捷键/Dock/OS 通知都经 gpui_mac.mm 的 Cocoa+Carbon 直路，不碰 gpui.h。')->font(11.0)->truncate(),
        El::hFlex([
            El::button('装托盘（A/B 两项）')->id('mc-tray')->onClick()->primary(),
            El::button('撤掉托盘')->id('mc-tray-off')->onClick()->ghost(),
            El::text("trayId={$s['trayId']} 在场=" . App::trayCount())->font(12.0),
        ])->gap(8.0)->itemsCenter(),
        El::text('托盘项被点 ⇒ 事件 type=tray，值=菜单项文本（无人值守下用 fireHotkeyForTest 那条缝验派发链）。')->font(11.0)->truncate(),
        El::hFlex([
            El::button('注册 ⌘⇧F7')->id('mc-hk-1')->onClick()->secondary(),
            El::button('注册 ⌥F8')->id('mc-hk-2')->onClick()->ghost(),
            El::button('注册 ⌃F9')->id('mc-hk-3')->onClick()->ghost(),
            El::button('撤最近一条')->id('mc-hk-off')->onClick()->ghost(),
            El::text("hkId={$s['hotkeyId']} 在场=" . App::hotkeyCount() . " 命中={$s['hotkeyHits']} mods={$s['hotkeyMods']}")->font(12.0),
        ])->gap(8.0)->itemsCenter(),
        El::hFlex($hkBtns)->gap(6.0)->flexWrap(),
        El::text('上面一排按 HOTKEY_MODS 的每个键各注册一条（cmd/shift/option/control 全覆盖）。')->font(11.0),
        El::hFlex([
            El::button('Dock 角标 7')->id('mc-dock-7')->onClick()->ghost(),
            El::button('Dock 角标清掉')->id('mc-dock-off')->onClick()->ghost(),
            El::button('OS 通知')->id('mc-osn')->onClick()->outline(),
            El::text("badge={$s['dock']} osNotify={$s['osNotify']}")->font(12.0),
        ])->gap(8.0)->itemsCenter(),
        El::hFlex([
            El::button('装 app 菜单（含分隔线与子菜单）')->id('mc-menus')->onClick()->primary(),
            El::text("menuCount=" . $s['menuCount'])->font(12.0),
        ])->gap(8.0)->itemsCenter(),
    ])->sizeFull()->gap(12.0)->p(16.0)->overflowYScroll();
}
