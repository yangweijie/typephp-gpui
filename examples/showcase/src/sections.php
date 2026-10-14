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

// ── 1. 基础原语 ──> 已搬到 examples/_shared/features/primitives.php
//    （sectionPrimitives + wirePrimitives + primitivesTrees），独立示例 examples/text 与画廊共用那一份。

// ── 2. 输入与保留态取值 ──> 已搬到 examples/_shared/features/inputs.php
//    （sectionInputs + wireInputs + inputsTrees），独立示例 examples/input 与画廊共用那一份。

// ── 3. 选择、开关与按钮变体 ──> 已搬到 examples/_shared/features/choices.php
//    （sectionChoices + wireChoices + choicesTrees），独立示例 examples/choices 与画廊共用那一份。

// ── 4. 容器、布局与滚动 ──> 已搬到 examples/_shared/features/layout.php
//    （sectionLayout + layoutTrees），独立示例 examples/layout 与画廊共用那一份。

// ── 5. 表格、列表与进度 ──> 已搬到 examples/_shared/features/data.php
//    （sectionData + dataTrees；这一域没有点击接线），独立示例 examples/table 与画廊共用那一份。

// ── 6. catalog 富组件（Registered 机制）──> 已搬到 examples/_shared/features/catalog.php
//    （sectionCatalog + wireCatalog + catalogTrees），独立示例 examples/catalog 与画廊共用那一份。

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

// ── 8. 浮层：dialog / sheet / toast / 通知 ──> 已搬到 examples/_shared/features/overlays.php
//    （sectionOverlays + wireOverlays + overlaysTrees），独立示例 examples/overlay 与画廊共用那一份。

// ── 9. 系统能力 ──> 已搬到 examples/_shared/features/system.php
//    （sectionSystem + sectionServices + wireSystem + systemTrees），
//    独立示例 examples/system 与画廊共用那一份。

// ── 10. 服务 ──> 已搬到 examples/_shared/features/system.php（与 §9 同一份）

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

// ── 12. 样式、图标与弹层原语 ──> 已搬到 examples/_shared/features/styling.php
//    （sectionStyling + wireStyling + stylingTrees），独立示例 examples/style 与画廊共用那一份。

// ── 13. mac 系统集成 ──> 已搬到 examples/_shared/features/mac.php
//    （sectionMac + wireMac + macTrees），独立示例 examples/mac 与画廊共用那一份。
