<?php

declare(strict_types=1);

/**
 * examples/table/src/main.php — 功能域独立示例：表格、列表与进度。
 *
 * 接手的第一条门是 #69 的回归位：表格的 aria 行/列序号必须真的过到原生侧。
 * specIndex() 读的是 bridge→shim→arena 那条路，不是 PHP 自己那份数组，
 * 所以它证明的是"号真的交出去了"；查无此 id 必须是 -1，否则"0"与"没找到"分不开。
 *
 * 开关：（无参）交互 · --sections · --shot <path> [tree] · --selftest
 */

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;

const TABLE_W = 760;
const TABLE_H = 560;

function main(int $argc, array $argv): void
{
    try {
        tableDispatch($argv);
    } catch (\Throwable $e) {
        echo 'FATAL ' . get_class($e) . ': ' . $e->getMessage() . "\n";
        exit(12);
    }
}

function tableDispatch(array $argv): void
{
    if (in_array('--sections', $argv, true)) {
        foreach (dataTrees() as $tree => [$title, $_fn]) {
            echo $tree . "\t" . $title . "\n";
        }
        return;
    }
    if (in_array('--selftest', $argv, true)) {
        runTableSelfTest();
        return;
    }
    $shotIdx = array_search('--shot', $argv, true);
    if ($shotIdx !== false) {
        $path = isset($argv[$shotIdx + 1]) ? (string) $argv[$shotIdx + 1] : '/tmp/table.png';
        $tree = isset($argv[$shotIdx + 2]) ? (int) $argv[$shotIdx + 2] : 1;
        runTableShot($path, $tree);
        return;
    }
    ExampleState::boot(['section' => 5]);
    $app = new App();
    wireData($app);
    $app->run('Table · 表格、列表与进度', TABLE_W, TABLE_H, 'tableTree');
}

function step(string $name, bool $ok, string $detail): void
{
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ' — ' . $detail . "\n";
}

function tableTree(): El
{
    return El::vFlex([sectionData(ExampleState::get())])->sizeFull()->bg(0xF6F3EE);
}

function runTableShot(string $path, int $tree): void
{
    ExampleState::boot(['section' => 5]);
    $app = new App();
    $app->create();
    $app->createWindow('Table Shot', TABLE_W, TABLE_H);
    wireData($app);
    $app->render(tableTree());
    $app->loop(20);
    echo '--- debug tree (table ' . (int) $tree . ') ---'
        . "\n" . \gpui_debug_tree($app->handle()) . "\n---\n";
    $shotOk = $app->snapshot($path, TABLE_W, TABLE_H);
    echo $shotOk ? "SHOT OK tree={$tree} {$path}\n" : "SHOT FAIL tree={$tree} {$path}\n";
    $app->close();
    exit($shotOk ? 0 : 1);
}

function runTableSelfTest(): void
{
    ExampleState::boot(['section' => 5]);
    $app = new App();
    $app->create();
    $app->createWindow('Table Selftest', TABLE_W, TABLE_H);
    wireData($app);
    $app->render(tableTree());
    $app->loop(3);

    $ok = true;
    echo "TABLE SELFTEST\n";

    // 1) 整棵 §5 树在原生侧没有报错节点（虚拟列表的行若少给，也会在这里报出来）。
    $err = $app->registeredError();
    step('registeredError', $err === '', $err === '' ? '空' : $err);
    $ok = $ok && $err === '';
    $cf = App::componentFailure();
    step('componentFailure', (int) $cf['count'] === 0, 'count=' . (int) $cf['count']
        . ' last=' . (string) $cf['last']);
    $ok = $ok && (int) $cf['count'] === 0;

    // 2) #69 的回归位：三行的行号必须过到原生侧，而查无此 id 必须回 -1。
    $rowIdx = [$app->specIndex('da-row-0'), $app->specIndex('da-row-1'), $app->specIndex('da-row-2')];
    $cellIdx = $app->specIndex('no-such-cell');
    $indexOk = $rowIdx === [0, 1, 2] && $cellIdx === -1;
    step('specIndex', $indexOk, 'rowIndex=' . implode(',', $rowIdx) . ' 查无=' . $cellIdx);
    $ok = $ok && $indexOk;

    // 3) 行列数发的是 method op（row_count / column_count）——上游那两个 aria 读数只有
    //    收到这两个名字才算"这表有行列"。detached 元素不喂 render，所以像素一位不动。
    $ariaEl = El::table('t-counts')->rowCount(3)->columnCount(4);
    $ariaOps = [];
    foreach ($ariaEl->ops as $op) {
        if ($op['kind'] === 'method') {
            $ariaOps[] = (string) $op['name'];
        }
    }
    $ariaOk = $ariaOps === ['row_count', 'column_count'];
    step('tableCountOps', $ariaOk, '发出=' . implode('/', $ariaOps));
    $ok = $ok && $ariaOk;

    // 4) 负数序号当场抛（静默收下就会画出"第 -1 行"）。三个入口各试一次负值、一次合法值。
    $guards = [];
    foreach ([
        'rowCount' => static fn (): El => El::table('g')->rowCount(-1),
        'columnCount' => static fn (): El => El::table('g')->columnCount(-1),
        'rowIndex' => static fn (): El => El::tableRow([])->rowIndex(-1),
        'columnIndex' => static fn (): El => El::tableCell([])->columnIndex(-1),
    ] as $name => $make) {
        $threw = false;
        try {
            $make();
        } catch (\InvalidArgumentException $e) {
            $threw = true;
        }
        if (!$threw) {
            $guards[] = $name;
        }
    }
    $legalOk = El::table('g2')->rowCount(0)->columnCount(0) !== null;
    $guardOk = $guards === [] && $legalOk;
    step('indexGuard', $guardOk, '该抛没抛=' . ($guards === [] ? '无' : implode('/', $guards))
        . ' 合法(0)建得出=' . var_export($legalOk, true));
    $ok = $ok && $guardOk;

    // 虚拟列表真惰性（#29/#35）：一万行的 uniformList，PHP 闭包只该被要可见那一段。
    // 两个独立仪表对账：PHP 侧自己记的（ExampleState）与 shim 记的（stateCounts 的
    // listCalls/listItems）——只看一边，"闭包被调了"与"行真的进了 materialize"分不开。
    // 断"远小于总数"而不断具体数：可见几行取决于行高与盒高，改个字号就会挪。
    $app->loop(3);
    $sc = $app->stateCounts();
    $nCalls = (int) ($sc['listCalls'] ?? -1);
    $nItems = (int) ($sc['listItems'] ?? -1);
    $phpRows = (int) ExampleState::$v['listRows'];
    $lazyOk = $nCalls > 0 && $nItems > 0 && $nItems <= DATA_LIST_ROWS / 100
        && $phpRows >= $nItems && (bool) ExampleState::$v['listSawFirst'];
    step('virtualListLazy', $lazyOk, 'shim 批次=' . $nCalls . ' 行=' . $nItems
        . ' PHP 批次=' . (int) ExampleState::$v['listBatches'] . ' 行=' . $phpRows
        . ' 总数=' . DATA_LIST_ROWS . ' 区间=' . (string) ExampleState::$v['listRanges'] . ' 见过第 0 行=' . var_export((bool) ExampleState::$v['listSawFirst'], true));
    $ok = $ok && $lazyOk;

    $app->render(El::vFlex([
        El::uniformList('da-vlist', DATA_LIST_ROWS)->w(260.0)->h(90.0),
    ])->sizeFull());
    $app->loop(3);
    $startBefore = (int) ExampleState::$v['listMaxStart'];
    $delivered = $app->realScroll(100, 40, -440);
    $app->loop(4);
    $startAfter = (int) ExampleState::$v['listMaxStart'];
    $scScroll = $app->stateCounts();
    $scrollOk = $delivered && $startAfter > $startBefore
        && (int) $scScroll['listItems'] < DATA_LIST_ROWS / 10;
    step('virtualListScroll', $scrollOk, '投递=' . var_export($delivered, true)
        . ' 最大 start=' . $startBefore . '→' . $startAfter
        . ' 最后区间=' . (string) ExampleState::$v['listRanges']);
    $ok = $ok && $scrollOk;

    $app->realClick(100, 20);
    $app->realRightClick(100, 20);
    $app->loop(3);
    $rowClick = (string) ExampleState::$v['listClicked'];
    $rowRight = (string) ExampleState::$v['listRight'];
    $rowsWired = str_starts_with($rowClick, 'da-item-') && $rowRight === $rowClick;
    step('virtualRowListeners', $rowsWired, 'click=' . $rowClick . ' right=' . $rowRight);
    $ok = $ok && $rowsWired;

    ExampleState::$v['listRowHandlers'] = false;
    $app->render(El::vFlex([
        El::uniformList('da-vlist', DATA_LIST_ROWS)->w(260.0)->h(90.0)
            ->onItemClick()->onItemSecondaryClick(),
    ])->sizeFull());
    $app->loop(3);
    $app->realClick(100, 20);
    $app->loop(2);
    $leftExcluded = ExampleState::$v['listSecondary'] === [];
    $app->realRightClick(100, 20);
    $app->loop(2);
    $secondary = (array) ExampleState::$v['listSecondary'];
    $key = (string) ExampleState::$v['listItem'];
    $itemsWired = str_starts_with($key, 'da-item-') && $leftExcluded
        && ($secondary['value'] ?? '') === $key && ($secondary['button'] ?? '') === 'right'
        && (int) ($secondary['click_count'] ?? 0) === 1
        && (float) ($secondary['position']['x'] ?? -1) === 100.0
        && (float) ($secondary['position']['y'] ?? -1) === 20.0
        && (float) ($secondary['local_position']['x'] ?? -1) >= 0
        && (float) ($secondary['local_position']['y'] ?? -1) >= 0;
    step('virtualItemEvents', $itemsWired, 'key=' . $key . ' secondary=' . var_export($secondary, true)
        . ' 左键排除=' . var_export($leftExcluded, true));
    $ok = $ok && $itemsWired;

    ExampleState::$v['varyingRows'] = 0;
    $app->listRenderer('da-variable', static function (int $start, int $end): array {
        ExampleState::$v['varyingRows'] = (int) ExampleState::$v['varyingRows'] + ($end - $start);
        $rows = [];
        for ($i = $start; $i < $end; $i++) {
            $rows[] = El::div()->h($i % 2 === 0 ? 20.0 : 30.0)
                ->child(El::text('不等高行 ' . $i)->font(12.0));
        }
        return $rows;
    });
    $app->render(El::vFlex([El::list('da-variable', DATA_LIST_ROWS)->w(260.0)->h(90.0)])->sizeFull());
    $app->loop(3);
    $varying = (int) ExampleState::$v['varyingRows'];
    $variableOk = $varying > 1 && $varying < DATA_LIST_ROWS / 10 && $app->registeredError() === '';
    step('virtualVariableRows', $variableOk, '不等高列表请求行数=' . $varying . ' 总数=' . DATA_LIST_ROWS);
    $ok = $ok && $variableOk;

    $app->close();
    echo ($ok ? 'TABLE SELFTEST OK' : 'TABLE SELFTEST FAIL') . "\n";
    exit($ok ? 0 : 1);
}
