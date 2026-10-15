<?php

declare(strict_types=1);

/**
 * examples/_shared/features/data.php — 功能域「表格、列表与进度」。
 *
 * 从原画廊（已删除）的 §5 搬来。表格行/格的 id 只是 specIndex 回读的键；唯一的接线是
 * 虚拟列表的行渲染闭包（wireData）。
 */

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;

/** da-vlist 的总条数：大到"全描述一遍"与"只描述可见几行"在耗时上天差地别。 */
const DATA_LIST_ROWS = 10000;

/**
 * 虚拟列表的行渲染闭包。gpui 在画帧途中按可见区间 [start, end) 同步来要；
 * 每要一批就在 ExampleState 里记一笔，自检拿它与 shim 自己的计数对账。
 */
function wireData(App $app): void
{
    $app->listRenderer('da-vlist', static function (int $start, int $end): array {
        ExampleState::$v['listBatches'] = (int) ExampleState::$v['listBatches'] + 1;
        ExampleState::$v['listRows'] = (int) ExampleState::$v['listRows'] + ($end - $start);
        if ($start === 0) {
            ExampleState::$v['listSawFirst'] = true;
        }
        ExampleState::$v['listRanges'] = "[{$start},{$end})";
        ExampleState::$v['listMaxStart'] = max((int) ExampleState::$v['listMaxStart'], $start);
        $rows = [];
        for ($i = $start; $i < $end; $i++) {
            // uniform_list 以 MinContent 测高；不换行才能避免文本按一像素宽度逐字换行。
            $row = El::text("第 {$i} 行")->font(12.0)->truncate()->px(8.0)->py(3.0)
                ->id('da-item-' . $i);
            if ((bool) ExampleState::$v['listRowHandlers']) {
                $row->onClick()->onMouseDownRight();
            }
            $rows[] = $row;
        }
        return $rows;
    });
    $app->onAny('click', static function (array $e): void {
        $id = (string) ($e['id'] ?? '');
        if (str_starts_with($id, 'da-item-')) ExampleState::$v['listClicked'] = $id;
    });
    $app->onAny('mouse_down_right', static function (array $e): void {
        $id = (string) ($e['id'] ?? '');
        if (str_starts_with($id, 'da-item-')) ExampleState::$v['listRight'] = $id;
    });
    $app->on('da-vlist', 'item_click', static function (array $e): void {
        ExampleState::$v['listItem'] = (string) ($e['value'] ?? '');
    });
    $app->on('da-vlist', 'item_secondary_click', static function (array $e): void {
        ExampleState::$v['listSecondary'] = $e;
    });
}

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
        // 真虚拟列表（#29/#35）：一万行，只给条数；行由 wireData() 登记的闭包按需给，
        // gpui 只为可见那一段要行。门在 examples/data 的 virtualListLazy：要过的行数
        // 必须远小于总数——"一万行全描述一遍"与"只描述可见的几行"在画面上看不出区别。
        El::text('uniformList：' . DATA_LIST_ROWS . ' 行，行由 PHP 闭包按可见区间给')->font(11.0)->truncate(),
        El::uniformList('da-vlist', DATA_LIST_ROWS)->w(260.0)->h(90.0)->border()->borderColor(0xCCC6BD),
        El::text('下面这排是 vFlex 近似的可滚动列表（读屏语义演示）：')->font(11.0)->truncate(),
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
            ->tabIndex(0)->tabStop()->keyContext('GpuiDataList')
            ->ariaActiveDescendant()->selectable(false),
        El::text('progress / progressTrack + progressIndicator')->font(12.0),
        El::progress(0.65)->w(240.0),
        El::progressTrack([El::progressIndicator()])->w(240.0)->h(8.0)->bg(0xE6E1D9)->roundedFull(),
    ])->sizeFull()->gap(10.0)->p(16.0)->overflowYScroll();
}
