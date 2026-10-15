<?php

declare(strict_types=1);

/**
 * examples/_shared/features/catalog.php — 功能域「catalog 富组件（Registered 机制）」。
 *
 * 从原画廊（已删除）的 §6 搬来：builder + 那两条下拉项的接线 + 自报的像素树号。
 * 这一域是 registered 分发那条路最密的一页（alert / breadcrumb / icon / avatar / collapsible /
 * popover / hoverCard / accordionItem / dropdownMenu 各吃一份 catalog descriptor），
 * 所以"哪一颗组件建不出来"值得有一支只渲染它自己的二进制来回答。
 */

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;

/** 本域的像素树清单：树号 → [标题, builder]。 */
function catalogTrees(): array
{
    return [
        1 => ['catalog 富组件', 'sectionCatalog'],
        2 => ['catalog 带 state 实体的组件', 'sectionCatalogStates'],
    ];
}

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

/** 本域元素的接线。 */
function wireCatalog(App $app): void
{
    $app->on('ca-dd-1', 'click', function (): void {
        ExampleState::$v['lastDd'] = '第一项';
    });
    $app->on('ca-dd-2', 'click', function (): void {
        ExampleState::$v['lastDd'] = '第二项';
    });
}


/**
 * 第二棵：构造里要**一颗宿主造的 state 实体**的那一族（#84；入口是 #81 的 El::state）。
 *
 * 为什么单独一棵：第一棵的基线是"catalog 不需要实体的那批"，混在一起之后任何一颗实体
 * 出问题都会连带改动那张图。颗数由 examples/catalog 的 catalogStateFamily 门钉着。
 *
 * Carousel 六件套**共用同一颗** CarouselState：El::state 按 (kind,key) 缓存，所以下面
 * 五处写的是同一个 key。各写各的 key 就是五颗实体、五个互不相干的轮播——那正是这一族
 * 唯一"看着画得出来但行为不对"的错法。
 */
function sectionCatalogStates(array $s): El
{
    $car = El::state('CarouselState', 'ca-car', [3, 0, 'horizontal', true]);
    return El::vFlex([
        El::text('catalog 带 state 实体的组件')->font(22.0)->semibold(),
        El::text('每颗的构造只吃一个 state；实体由宿主经 El::state 造（#81 的入口）')->font(11.0)->truncate(),
        El::catalogTextarea('ca-ta', 'catalog Textarea：多行、带初值'),
        El::catalogEditor('ca-ed', "fn main() {\n    println!(\"hi\");\n}", 'rust')->h(120.0),
        // 补丁文本得是**真** unified diff：DiffFile::Parse 要 hunk 头（@@ -1 +1 @@），
        // 少了它就报解析错（第一次这里写的是单引号串，\n 还是字面量——两个错叠在一起）。
        El::catalogDiff('ca-diff',
                        "--- a/x.txt\n+++ b/x.txt\n@@ -1 +1 @@\n-old line\n+new line",
                        'unified')->h(120.0),
        El::catalogCommand('ca-cmd', [
            El::catalog('CommandItem', ['新建文件'])->rm('action', ['cmd-new']),
            El::catalog('CommandSeparator', []),
            El::catalog('CommandGroup', ['最近'])->child(
                El::catalog('CommandItem', ['x.txt'])->rm('action', ['cmd-x'])
            ),
        ])->h(160.0),
        // Carousel 的根构造是 (id, state)（carousel.cpp:501 kIdStateArgs），Content/Previous/
        // Next 只要 (state)，Item 是 (id, index, state)——少写一颗就是 "missing argument 'state'"。
        El::catalog('Carousel', ['ca-carousel', $car], [
            El::catalog('CarouselContent', [$car], [
                El::catalog('CarouselItem', ['ca-slide-0', 0, $car], [El::text('第一张')->font(14.0)])->w(180.0),
                El::catalog('CarouselItem', ['ca-slide-1', 1, $car], [El::text('第二张')->font(14.0)])->w(180.0),
                El::catalog('CarouselItem', ['ca-slide-2', 2, $car], [El::text('第三张')->font(14.0)])->w(180.0),
            ]),
            El::catalog('CarouselPrevious', [$car]),
            El::catalog('CarouselNext', [$car]),
        ])->h(100.0),
    ])->sizeFull()->gap(10.0)->p(16.0)->overflowYScroll();
}
