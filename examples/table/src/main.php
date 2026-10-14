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
    // 这一域没有点击接线（行/格 id 只是 specIndex 回读的键），所以只有 render。
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
    $app->render(tableTree());
    $app->loop(3);

    $ok = true;
    echo "TABLE SELFTEST\n";

    // 1) 整棵 §5 树在原生侧没有报错节点。List 那颗是**故意**留的：上游会丢它的 children
    //    （renderer 未接，task #29/#35），丢 children 不是建不出来，所以这里仍应全空。
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

    $app->close();
    echo ($ok ? 'TABLE SELFTEST OK' : 'TABLE SELFTEST FAIL') . "\n";
    exit($ok ? 0 : 1);
}
