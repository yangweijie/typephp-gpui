<?php

declare(strict_types=1);

/**
 * examples/_shared/features/data.php — 功能域「表格、列表与进度」。
 *
 * 从 showcase 的 §5 搬来。这一域没有点击接线（行/格的 id 只是 specIndex 回读的键），
 * 所以这里只有 builder 与自报的像素树号，没有 wire 函数。
 */

use TypePHP\Gpui\Element as El;

/** 本域的像素树清单：树号 → [标题, builder]。 */
function dataTrees(): array
{
    return [1 => ['表格、列表与进度', 'sectionData']];
}

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
