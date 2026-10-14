<?php

declare(strict_types=1);

/**
 * examples/catalog/src/main.php — 功能域独立示例：catalog 富组件（Registered 机制）。
 *
 * registered 分发那条路在这一页最密：alert / breadcrumb / icon / avatar / collapsible /
 * popover / hoverCard / accordionItem / dropdownMenu 各吃一份 catalog descriptor。
 * 上游对"未知方法名"是产出 "no such registered method" 的错误节点而不是失败整帧，
 * 所以"哪一颗组件建不出来"需要一支只渲染它自己的二进制来回答。
 *
 * 开关：（无参）交互 · --sections · --shot <path> [tree] · --selftest
 */

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;

const CATALOG_W = 900;
const CATALOG_H = 620;

/**
 * 第二棵树（sectionCatalogStates）该有的宿主 state 实体颗数。
 * ca-ta(TextareaState) / ca-ed(EditorState) / ca-diff(DiffState) / ca-cmd(CommandState)
 * / ca-car(CarouselState，Carousel 那五处共用同一颗)。
 * 改那一棵树时同步改这里——不同步就红，正是要的红。
 */
const CATALOG_STATE_ENTITIES = 5;

function main(int $argc, array $argv): void
{
    try {
        catalogDispatch($argv);
    } catch (\Throwable $e) {
        echo 'FATAL ' . get_class($e) . ': ' . $e->getMessage() . "\n";
        exit(12);
    }
}

function catalogDispatch(array $argv): void
{
    if (in_array('--sections', $argv, true)) {
        foreach (catalogTrees() as $tree => [$title, $_fn]) {
            echo $tree . "\t" . $title . "\n";
        }
        return;
    }
    if (in_array('--selftest', $argv, true)) {
        runCatalogSelfTest();
        return;
    }
    $shotIdx = array_search('--shot', $argv, true);
    if ($shotIdx !== false) {
        $path = isset($argv[$shotIdx + 1]) ? (string) $argv[$shotIdx + 1] : '/tmp/catalog.png';
        $tree = isset($argv[$shotIdx + 2]) ? (int) $argv[$shotIdx + 2] : 1;
        runCatalogShot($path, $tree);
        return;
    }
    ExampleState::boot(['section' => 6]);
    $app = new App();
    wireCatalog($app);
    $app->run('Catalog · registered 组件', CATALOG_W, CATALOG_H, 'catalogTree');
}

function step(string $name, bool $ok, string $detail): void
{
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ' — ' . $detail . "\n";
}

function catalogTree(int $tree = 1): El
{
    // 按**树号**选 builder。写死一棵的话，两棵"不同的树"会拍出逐字节相同的帧——
    // 这一族栽过两次（system 第一次、catalog 加第二棵时又一次），现在 cmd/shotdiff.php
    // 有一条门直接拦重复帧，所以这里漏选会被当场报出来而不是悄悄进基线。
    $builder = $tree === 2 ? 'sectionCatalogStates' : 'sectionCatalog';
    return El::vFlex([$builder(ExampleState::get())])->sizeFull()->bg(0xF6F3EE);
}

function runCatalogShot(string $path, int $tree): void
{
    $tree = isset(catalogTrees()[$tree]) ? $tree : 1;
    ExampleState::boot(['section' => 6]);
    $app = new App();
    $app->create();
    $app->createWindow('Catalog Shot', CATALOG_W, CATALOG_H);
    wireCatalog($app);
    $app->render(catalogTree($tree));
    $app->loop(20);
    echo '--- debug tree (catalog ' . (int) $tree . ') ---'
        . "\n" . \gpui_debug_tree($app->handle()) . "\n---\n";
    $shotOk = $app->snapshot($path, CATALOG_W, CATALOG_H);
    echo $shotOk ? "SHOT OK tree={$tree} {$path}\n" : "SHOT FAIL tree={$tree} {$path}\n";
    $app->close();
    exit($shotOk ? 0 : 1);
}

function runCatalogSelfTest(): void
{
    ExampleState::boot(['section' => 6]);
    $app = new App();
    $app->create();
    $app->createWindow('Catalog Selftest', CATALOG_W, CATALOG_H);
    wireCatalog($app);
    $app->render(catalogTree());
    $app->loop(4);

    $ok = true;
    echo "CATALOG SELFTEST\n";

    // 1) 整棵 §6 树在原生侧没有报错节点，且累计的组件失败计数没动。
    //    这一页全是 registered 组件，建不出来时 gpui 只把那一颗画成 "Failed to render X"
    //    并在 runtime 的失败计数上加一，registeredError() 又照不到 materialize 阶段，
    //    所以两条都要看（只查一条会漏）。
    $err = $app->registeredError();
    step('registeredError', $err === '', $err === '' ? '空' : $err);
    $ok = $ok && $err === '';
    $cf = App::componentFailure();
    step('componentFailure', (int) $cf['count'] === 0, 'count=' . (int) $cf['count']
        . ' last=' . (string) $cf['last']);
    $ok = $ok && (int) $cf['count'] === 0;

    // 2) 五个部件级回调名发得出去（#73 的"op 发得出"那一半）。
    //    送达仍未量到：link_click 要 markdown 里的链接真被点，item_click /
    //    item_secondary_click 要 list 的 renderer（#29/#35 那格活），token_* 要 TokenInput。
    //    所以文档里标的是"名字在册、送达未量"，这条门也只数名字——
    //    "5 个不重复"防的是链上少写一个或同一个调两遍。
    $cbEl = El::div()->onLinkClick()->onItemClick()->onItemSecondaryClick()
        ->onTokenClick()->onTokenHover();
    $cbNames = [];
    foreach ($cbEl->ops as $op) {
        if ($op['kind'] === 'callback') {
            $cbNames[] = (string) $op['name'];
        }
    }
    $cbOk = count($cbNames) === 5 && count(array_unique($cbNames)) === 5;
    step('componentCallbackOps', $cbOk, '发出=' . count($cbNames)
        . ' 去重后=' . count(array_unique($cbNames)) . ' [' . implode('/', $cbNames) . ']');
    $ok = $ok && $cbOk;

    // 3) DropdownMenu 的每一项发的是 registered_method('item')，实参两颗（label 与回调）。
    //    item() 的第三个参数是事件类型（click/hover），它经 self::cb 编进第二颗实参里，
    //    所以条数是"每项一条"而不是"每项两条"——数错就说明 item() 的发法改过了。
    $dd = El::dropdownMenu('ca-dd-x', 'DropdownMenu')
        ->item('第一项', 'ca-ddx-1')
        ->item('第二项', 'ca-ddx-2', 'click');
    $itemOps = 0;
    foreach ($dd->ops as $op) {
        if ($op['kind'] === 'registered_method' && $op['name'] === 'item') {
            $itemOps++;
        }
    }
    $ddOk = $itemOps === 2;
    step('dropdownItemOps', $ddOk, 'item 条数=' . $itemOps . '/2');
    $ok = $ok && $ddOk;

    // 4) 要 state 实体的那一族（#84）：渲染第二棵树，量"建得出来 + 实体数对 + 跨帧不涨"。
    //    为什么三条一起看：registered 组件建不出来时 gpui 只把那一颗画成
    //    "Failed to render X"，而实体建不出来时 bridge 下发 NIL 实参（#81 的修法）——
    //    两种都会同时动 registeredError 与 componentFailure，但**只有实体颗数**能抓到
    //    "共享 key 写成了各自 key"（Carousel 五处该是一颗，写错就变五颗）。
    $app->render(catalogTree(2));
    $app->loop(3);
    $sc = $app->stateCounts();
    $states = (int) ($sc['componentStates'] ?? -1);
    $famErr = $app->registeredError();
    $famCf = (int) (App::componentFailure()['count'] ?? -1);
    $app->loop(6);
    $sc2 = $app->stateCounts();
    $states2 = (int) ($sc2['componentStates'] ?? -1);
    $famOk = $states === CATALOG_STATE_ENTITIES && $states2 === CATALOG_STATE_ENTITIES
        && $famErr === '' && $famCf === 0;
    step('catalogStateFamily', $famOk, '实体 首帧后=' . $states . ' 多帧后=' . $states2
        . '（应为 ' . CATALOG_STATE_ENTITIES . '） registeredError='
        . ($famErr === '' ? '空' : $famErr) . ' componentFailure=' . $famCf);
    $ok = $ok && $famOk;

    // 5) 最后一条是**故意的失败路径**（所以要放在所有读数之后——它会把 registeredError 弄脏）：
    //    空 id 的 registered 组件被 factory 拒（NonEmptyId），shim 记 lastError，
    //    PHP 经 registeredError() 取回。曾经这类错只藏在 debug_tree 里，三层门禁都不报。
    $app->render(El::alert('', '空 id 应当被拒', 'info'));
    $negErr = $app->registeredError();
    $negOk = $negErr !== '';
    step('emptyIdIsLoud', $negOk, $negOk ? '原因=' . $negErr : 'registeredError 仍是空——这条错又变哑了');
    $ok = $ok && $negOk;

    $app->close();
    echo ($ok ? 'CATALOG SELFTEST OK' : 'CATALOG SELFTEST FAIL') . "\n";
    exit($ok ? 0 : 1);
}
