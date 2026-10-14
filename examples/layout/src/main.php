<?php

declare(strict_types=1);

/**
 * examples/layout/src/main.php — 功能域独立示例：容器、布局与滚动。
 *
 * 这一支把"三条通道各是什么"钉成门：nullary_style（flex/relative 这类零参关键字）、
 * param_style（flex_grow/gap_x/p 这类带值的族）、method/behavior（overflow_*_scroll、size）。
 * 分派错的形状是 #66 那种：名字对、通道错，上游直接 continue，屏幕上一个像素都不动，
 * 而 registeredError() 照样是空的。
 *
 * 开关：（无参）交互 · --sections · --shot <path> [tree] · --selftest
 */

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;

const LAYOUT_W = 820;
const LAYOUT_H = 620;

function main(int $argc, array $argv): void
{
    try {
        layoutDispatch($argv);
    } catch (\Throwable $e) {
        echo 'FATAL ' . get_class($e) . ': ' . $e->getMessage() . "\n";
        exit(12);
    }
}

function layoutDispatch(array $argv): void
{
    if (in_array('--sections', $argv, true)) {
        foreach (layoutTrees() as $tree => [$title, $_fn]) {
            echo $tree . "\t" . $title . "\n";
        }
        return;
    }
    if (in_array('--selftest', $argv, true)) {
        runLayoutSelfTest();
        return;
    }
    $shotIdx = array_search('--shot', $argv, true);
    if ($shotIdx !== false) {
        $path = isset($argv[$shotIdx + 1]) ? (string) $argv[$shotIdx + 1] : '/tmp/layout.png';
        runLayoutShot($path);
        return;
    }
    ExampleState::boot(['section' => 4]);
    $app = new App();
    // 这一域没有事件接线（tabs/resizable 的回调另有归属），所以只有 render。
    $app->run('Layout · 容器、布局与滚动', LAYOUT_W, LAYOUT_H, 'layoutTree');
}

function step(string $name, bool $ok, string $detail): void
{
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ' — ' . $detail . "\n";
}

function layoutTree(): El
{
    return El::vFlex([sectionLayout(ExampleState::get())])->sizeFull()->bg(0xF6F3EE);
}

function runLayoutShot(string $path): void
{
    ExampleState::boot(['section' => 4]);
    $app = new App();
    $app->create();
    $app->createWindow('Layout Shot', LAYOUT_W, LAYOUT_H);
    $app->render(layoutTree());
    $app->loop(20);
    echo '--- debug tree (layout 1) ---' . "\n" . \gpui_debug_tree($app->handle()) . "\n---\n";
    $shotOk = $app->snapshot($path, LAYOUT_W, LAYOUT_H);
    echo $shotOk ? "SHOT OK tree=1 {$path}\n" : "SHOT FAIL tree=1 {$path}\n";
    $app->close();
    exit($shotOk ? 0 : 1);
}

/** 按 kind 收集 op 名，顺序就是链式调用顺序。 */
function layoutOpNames(El $el, string $kind): array
{
    $out = [];
    foreach ($el->ops as $op) {
        if ($op['kind'] === $kind) {
            $out[] = (string) $op['name'];
        }
    }
    return $out;
}

function runLayoutSelfTest(): void
{
    ExampleState::boot(['section' => 4]);
    $app = new App();
    $app->create();
    $app->createWindow('Layout Selftest', LAYOUT_W, LAYOUT_H);
    $app->render(layoutTree());
    $app->loop(4);

    $ok = true;
    echo "LAYOUT SELFTEST\n";

    // 1) resizable（横竖两组）、tabs、40 行的滚动容器都建得出来。
    //    tabs/resizable 是 registered 组件，建不出来时只画 "Failed to render X"，
    //    所以 registeredError 与累计失败计数两条都要看。
    $err = $app->registeredError();
    step('registeredError', $err === '', $err === '' ? '空' : $err);
    $ok = $ok && $err === '';
    $cf = App::componentFailure();
    step('componentFailure', (int) $cf['count'] === 0, 'count=' . (int) $cf['count']
        . ' last=' . (string) $cf['last']);
    $ok = $ok && (int) $cf['count'] === 0;

    // 2) nullary_style：这五个是零参关键字，名字逐个等于上游 kKeywords。
    $nullary = layoutOpNames(
        El::div()->flex()->flexCol()->flexRow()->flex1()->flexNone(),
        'nullary_style'
    );
    $nullaryOk = $nullary === ['flex', 'flex_col', 'flex_row', 'flex_1', 'flex_none'];
    step('nullaryChannel', $nullaryOk, '发出=' . implode('/', $nullary));
    $ok = $ok && $nullaryOk;

    // 3) param_style：带值的族名。flex_grow 与 flex_1 是两回事（一个是族带参、一个是关键字），
    //    把后者当前者发出去就是"名字对、通道错"，上游静默 continue。
    $param = layoutOpNames(
        El::div()->flexGrow(1.0)->flexShrink(0.0)->gapX(6.0)->gapY(8.0)->p(8.0)->px(6.0),
        'param_style'
    );
    $paramOk = $param === ['flex_grow', 'flex_shrink', 'gap_x', 'gap_y', 'p', 'px'];
    step('paramChannel', $paramOk, '发出=' . implode('/', $param));
    $ok = $ok && $paramOk;

    // 4) 滚动是 behavior（materialize.cpp:364 认这三个名字），不是样式关键字——
    //    #18 那轮量出来的结论，现在由这条门钉住。
    $behavior = layoutOpNames(
        El::div()->overflowYScroll()->overflowXScroll()->overflowScroll(),
        'method'
    );
    $behaviorOk = $behavior === ['overflow_y_scroll', 'overflow_x_scroll', 'overflow_scroll'];
    step('behaviorChannel', $behaviorOk, '发出=' . implode('/', $behavior));
    $ok = $ok && $behaviorOk;

    // 5) size 在 kind 节点上是 method op（带一个字符串值），不是 registered_method——
    //    §12 那条注释说"rm('size') 打在 kind 节点上是没用的"，这条就是那句的可执行形式。
    $panel = El::resizablePanel(El::text('p'))->size('30%');
    $sizeOps = layoutOpNames($panel, 'method');
    $sizeOk = $sizeOps === ['size'];
    step('sizeIsMethodOp', $sizeOk, '发出=' . implode('/', $sizeOps)
        . ' 值=' . (string) ($panel->ops[0]['args'][0] ?? ''));
    $ok = $ok && $sizeOk;

    $app->close();
    echo ($ok ? 'LAYOUT SELFTEST OK' : 'LAYOUT SELFTEST FAIL') . "\n";
    exit($ok ? 0 : 1);
}
