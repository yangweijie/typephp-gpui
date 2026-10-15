<?php

declare(strict_types=1);

/**
 * examples/_shared/features/primitives.php — 功能域「基础原语」（文本 / 链接 / 图 / Markdown / hover 探针）。
 *
 * 从原画廊（已删除）的 §1 搬来。`pr-hover` 那颗是整条指针事件族的探针（#71 的现场），
 * 它的 hover handler 也一起搬过来——画廊与 examples/text 共用同一颗、同一条线。
 */

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;

/** 本域的像素树清单：树号 → [标题, builder]。 */
function primitivesTrees(): array
{
    return [1 => ['基础原语', 'sectionPrimitives']];
}

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

/** 本域元素的接线。 */
function wirePrimitives(App $app): void
{
    // 具体 id 的 hover handler 与画廊里的 onAny('hover') 两条都注册：
    // 前者证明精确路由，后者证明通配。
    $app->on('pr-hover', 'hover', function (): void {
        ExampleState::$v['hover'] = 'pr-hover';
    });
}
