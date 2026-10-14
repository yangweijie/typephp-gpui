<?php

declare(strict_types=1);

/**
 * examples/text/src/main.php — 功能域独立示例：基础原语（文本 / 链接 / 图 / Markdown / hover 探针）。
 *
 * 这一支量的不是"有没有这个方法"，而是"这个方法走的是哪条通道"：
 * 上游对带参的未知 op 名是静默丢弃、对 kind 节点上的 registered_method 是直接 continue，
 * 所以名字对而通道错时，五层门禁全绿而屏幕上一个像素都不动（#66 就是这么一案）。
 *
 * 开关：（无参）交互 · --sections · --shot <path> [tree] · --selftest
 */

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;

const TEXT_W = 720;
const TEXT_H = 520;

function main(int $argc, array $argv): void
{
    try {
        textDispatch($argv);
    } catch (\Throwable $e) {
        echo 'FATAL ' . get_class($e) . ': ' . $e->getMessage() . "\n";
        exit(12);
    }
}

function textDispatch(array $argv): void
{
    if (in_array('--sections', $argv, true)) {
        foreach (primitivesTrees() as $tree => [$title, $_fn]) {
            echo $tree . "\t" . $title . "\n";
        }
        return;
    }
    if (in_array('--selftest', $argv, true)) {
        runTextSelfTest();
        return;
    }
    $shotIdx = array_search('--shot', $argv, true);
    if ($shotIdx !== false) {
        $path = isset($argv[$shotIdx + 1]) ? (string) $argv[$shotIdx + 1] : '/tmp/text.png';
        runTextShot($path);
        return;
    }
    ExampleState::boot(['section' => 1]);
    $app = new App();
    wirePrimitives($app);
    $app->run('Text · 基础原语', TEXT_W, TEXT_H, 'textTree');
}

function step(string $name, bool $ok, string $detail): void
{
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ' — ' . $detail . "\n";
}

function textTree(): El
{
    return El::vFlex([sectionPrimitives(ExampleState::get())])->sizeFull()->bg(0xF6F3EE);
}

function runTextShot(string $path): void
{
    ExampleState::boot(['section' => 1]);
    $app = new App();
    $app->create();
    $app->createWindow('Text Shot', TEXT_W, TEXT_H);
    wirePrimitives($app);
    $app->render(textTree());
    $app->loop(20);
    echo '--- debug tree (text 1) ---' . "\n" . \gpui_debug_tree($app->handle()) . "\n---\n";
    $shotOk = $app->snapshot($path, TEXT_W, TEXT_H);
    echo $shotOk ? "SHOT OK tree=1 {$path}\n" : "SHOT FAIL tree=1 {$path}\n";
    $app->close();
    exit($shotOk ? 0 : 1);
}

/** 按 kind 收集 op 名，顺序就是链式调用顺序。 */
function opNames(El $el, string $kind): array
{
    $out = [];
    foreach ($el->ops as $op) {
        if ($op['kind'] === $kind) {
            $out[] = (string) $op['name'];
        }
    }
    return $out;
}

function runTextSelfTest(): void
{
    ExampleState::boot(['section' => 1]);
    $app = new App();
    $app->create();
    $app->createWindow('Text Selftest', TEXT_W, TEXT_H);
    wirePrimitives($app);
    $app->render(textTree());
    $app->loop(4);

    $ok = true;
    echo "TEXT SELFTEST\n";

    // 1) link / textView(Markdown) / image / svg / 那颗带全事件族的 pr-hover 探针，
    //    在原生侧都不许留下错误节点。image 与 svg 指的是不存在的文件——
    //    那应当是"空块"而不是报错节点，所以这一条同时钉住这一点。
    $err = $app->registeredError();
    step('registeredError', $err === '', $err === '' ? '空' : $err);
    $ok = $ok && $err === '';
    $cf = App::componentFailure();
    step('componentFailure', (int) $cf['count'] === 0, 'count=' . (int) $cf['count']
        . ' last=' . (string) $cf['last']);
    $ok = $ok && (int) $cf['count'] === 0;

    // 2) 四个"看起来像样式"的糖走 nullary_style，名字逐个等于上游词表。
    //    bold() 发的是 font_bold 而不是 bold——这一条钉的就是这类"我以为的名字"。
    $nullary = opNames(
        El::text('x')->hidden()->truncate()->bold()->cursorPointer(),
        'nullary_style'
    );
    $nullaryOk = $nullary === ['hidden', 'truncate', 'font_bold', 'cursor_pointer'];
    step('nullaryChannel', $nullaryOk, '发出=' . implode('/', $nullary));
    $ok = $ok && $nullaryOk;

    // 3) 滚动与 disabled 走 method(behavior)，不是 nullary_style（#18 那一轮的结论）。
    //    打在错通道上时上游直接 continue，画面上没有任何东西会告诉你它没了。
    $behavior = opNames(El::div()->overflowYScroll()->disabled(), 'method');
    $behaviorOk = $behavior === ['overflow_y_scroll', 'disabled'];
    step('behaviorChannel', $behaviorOk, '发出=' . implode('/', $behavior));
    $ok = $ok && $behaviorOk;

    // 4) link 的 href 白名单：非 http/https 必须被拒（这条在原生侧，不在 PHP 数组上）。
    //    只测拒绝那一支——放行那一支会真的开浏览器，门禁里不该有那种副作用。
    $rejected = $app->openUrl('javascript:alert(1)');
    $rejectOk = $rejected === false;
    step('urlWhitelistReject', $rejectOk, 'openUrl(javascript:…)=' . var_export($rejected, true));
    $ok = $ok && $rejectOk;

    // 5) hover 探针必须在命中表里有自己的矩形——onHover 要求元素有可命中几何体
    //    （bg/明确尺寸），纯 text 节点不参与 hover 检测。这是 choices 那轮学到的顺序：
    //    先证明"点得到"，再谈事件到没到。
    $total = (int) ($app->hitProbe()['n'] ?? 0);
    $boxes = 0;
    for ($i = 1; $i < $total; $i++) {
        $h = $app->hitProbe($i);
        if ((int) $h['w'] > 20 && (int) $h['h'] > 10) {
            $boxes++;
        }
    }
    step('hoverProbeHasRect', $boxes > 0, '命中表=' . $total . ' 条，其中像样矩形=' . $boxes);
    $ok = $ok && $boxes > 0;

    $app->close();
    echo ($ok ? 'TEXT SELFTEST OK' : 'TEXT SELFTEST FAIL') . "\n";
    exit($ok ? 0 : 1);
}
