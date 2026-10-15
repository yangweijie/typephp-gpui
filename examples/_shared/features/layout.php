<?php

declare(strict_types=1);

/**
 * examples/_shared/features/layout.php — 功能域「容器、布局与滚动」。
 *
 * 从原画廊（已删除）的 §4 搬来。这一域目前没有 PHP 侧 handler：`on_resize` 的 op 挂在组上
 * （见下面那段的注释），但送达仍未量到——宿主钩子与"按住再拖"的自检缝都还缺。
 * 所以只有 builder 与自报的像素树号。
 */

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;

/** 本域的像素树清单：树号 → [标题, builder]。 */
function layoutTrees(): array
{
    return [
        1 => ['容器、布局与滚动', 'sectionLayout'],
        2 => ['Dock 保留态布局', 'sectionDock'],
    ];
}

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
        // on_resize 挂在**组**上，不挂在 panel 上：materialize 只从 h_resizable/v_resizable
        // 自己的 behavior 读它（materialize.cpp:2349-2352），panel 分支只取 size/range/visible
        // （:2361-2371）——挂在 resizablePanel 上是颗静默失效的糖（2026-10-14 查清，
        // 见 docs/guide/events.md 1b）。仍然**没量到送达**，还差两条：宿主侧没有带 float 数组的
        // 钩子（上游发的是 DispatchNumbers），自检缝里也没有"按住再拖"（real_mouse 只有
        // 同点按下+抬起与移动），所以拖不动分隔条。
        El::hResizable([
            El::resizablePanel(El::text('左栏（可拖）')->font(13.0)->p(8.0))->size('30%'),
            El::resizablePanel(El::text('右栏')->font(13.0)->p(8.0))->size('70%'),
        ])->h(80.0)->gap(4.0)->id('lay-split')->onResize(),
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

function sectionDock(array $s): El
{
    return El::vFlex([
        El::text('Dock 保留态布局')->font(22.0)->semibold(),
        El::text('原生标签切换、关闭与拖拽；面板内容由 PHP 描述，布局由 DockState 持有')->font(12.0)->truncate(),
        El::dockArea('ld-area')->flex1(),
    ])->sizeFull()->gap(10.0)->p(16.0);
}

function wireDock(App $app): void
{
    $app->createDock('ld-area');
    $app->addDockPanel('ld-area', 'ld-editor', '编辑器', El::vFlex([
        El::text('编辑器面板')->font(18.0),
        El::text('点击原生标签切换面板')->font(12.0),
    ])->p(12.0));
    $app->addDockPanel('ld-area', 'ld-console', '控制台', El::vFlex([
        El::text('控制台面板')->font(18.0),
        El::catalogButton('ld-panel-button', '面板内按钮')->onClick(),
    ])->p(12.0));
    $app->addDockPanel('ld-area', 'ld-files', '文件', El::text('文件树面板')->font(14.0)->p(12.0), 'left', 180.0);
    $app->addDockPanel('ld-area', 'ld-log', '日志', El::text('日志面板')->font(14.0)->p(12.0), 'bottom', 150.0);
    $app->on('ld-area', 'dock_change', static function (): void {
        ExampleState::$v['dockChanges'] = (int) ExampleState::$v['dockChanges'] + 1;
    });
    $app->on('ld-panel-button', 'click', static function (): void {
        ExampleState::$v['dockButton'] = true;
    });
}

/** 本域元素的接线。 */
function wireLayout(App $app): void
{
    // 可伸缩面板的尺寸变化。载荷是宿主拼出来的 CSV（"30,70" 这样的两列，
    // cmd/patch-select-view.sh 的 OnResize 那处），所以这里读 $e['value'] 就够。
    $app->on('lay-split', 'resize', function (array $e): void {
        ExampleState::$v['split'] = (string) ($e['value'] ?? '');
    });
}
