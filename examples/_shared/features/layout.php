<?php

declare(strict_types=1);

/**
 * examples/_shared/features/layout.php — 功能域「容器、布局与滚动」。
 *
 * 从 showcase 的 §4 搬来。这一域没有事件接线（tabs/resizable 的回调另有归属），
 * 所以只有 builder 与自报的像素树号。
 */

use TypePHP\Gpui\Element as El;

/** 本域的像素树清单：树号 → [标题, builder]。 */
function layoutTrees(): array
{
    return [1 => ['容器、布局与滚动', 'sectionLayout']];
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
