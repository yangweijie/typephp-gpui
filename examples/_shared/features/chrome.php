<?php

declare(strict_types=1);

/**
 * examples/_shared/features/chrome.php — 功能域「侧栏、表单与菜单」
 *
 * 从原画廊（已删除）的 §7 原样搬来（builder + 接线 + 自报的像素树号），各示例共用这一份。
 */

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;

/** 本域的像素树清单：树号 → [标题, builder]。 */
function chromeTrees(): array
{
    return [1 => ['侧栏、表单与菜单', 'sectionChrome']];
}

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

/** 本域元素的接线。 */
function wireChrome(App $app): void
{
    foreach (['new' => '新建', 'a' => 'a.txt', 'b' => 'b.txt', 'ro' => '只读项'] as $k => $label) {
        $app->on('cr-m-' . $k, 'click', function () use ($label): void {
            ExampleState::$v['lastBtn'] = 'menu:' . $label;
        });
    }
    $app->on('cr-sidebar', 'click', function (): void {
        ExampleState::$v['sidebarCollapsed'] = !ExampleState::$v['sidebarCollapsed'];
    });
    foreach (El::COLLAPSE_MODES as $mode) {
        $app->on('cr-collapse-' . $mode, 'click', function () use ($mode): void {
            ExampleState::$v['collapse'] = $mode;
        });
    }
    foreach (El::FIELD_ALIGN as $a) {
        $app->on('cr-align-' . $a, 'click', function () use ($a): void {
            ExampleState::$v['align'] = $a;
        });
    }
}
