<?php

declare(strict_types=1);

/**
 * examples/style/src/main.php — 功能域独立示例：样式、图标与弹层原语。
 *
 * 这一支接手的第一条门原来住在画廊 selftest 中段。它量的东西只有跑产物才能排掉
 * （nano 里动态调用一个没编进去的方法是 SIGABRT，不是异常），但过去它一崩，
 * 用户看见的只是"rc 非零、一个字都没打"——因为画廊的报告攒到最后才打印一次。
 * 这里每步一行，所以"哪个样式糖出问题了"是报告里的一行，不是一个谜。
 *
 * 开关：（无参）交互 · --sections · --shot <path> [tree] · --selftest
 */

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;

const STYLE_W = 900;
const STYLE_H = 640;

/** 2026-10-13 补的那批零参样式糖，逐个调一遍。 */
const STYLE_SUGAR_COUNT = 47;

function main(int $argc, array $argv): void
{
    try {
        styleDispatch($argv);
    } catch (\Throwable $e) {
        echo 'FATAL ' . get_class($e) . ': ' . $e->getMessage() . "\n";
        exit(12);
    }
}

function styleDispatch(array $argv): void
{
    if (in_array('--sections', $argv, true)) {
        foreach (stylingTrees() as $tree => [$title, $_fn]) {
            echo $tree . "\t" . $title . "\n";
        }
        return;
    }
    if (in_array('--selftest', $argv, true)) {
        runStyleSelfTest();
        return;
    }
    $shotIdx = array_search('--shot', $argv, true);
    if ($shotIdx !== false) {
        $path = isset($argv[$shotIdx + 1]) ? (string) $argv[$shotIdx + 1] : '/tmp/style.png';
        $tree = isset($argv[$shotIdx + 2]) ? (int) $argv[$shotIdx + 2] : 1;
        runStyleShot($path, $tree);
        return;
    }
    ExampleState::boot(['section' => 12]);
    $app = new App();
    wireStyling($app);
    $app->run('Style · 样式与图标原语', STYLE_W, STYLE_H, 'styleTree');
}

function step(string $name, bool $ok, string $detail): void
{
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ' — ' . $detail . "\n";
}

function styleTree(): El
{
    return El::vFlex([sectionStyling(ExampleState::get())])->sizeFull()->bg(0xF6F3EE);
}

function runStyleShot(string $path, int $tree): void
{
    ExampleState::boot(['section' => 12]);
    $app = new App();
    $app->create();
    $app->createWindow('Style Shot', STYLE_W, STYLE_H);
    wireStyling($app);
    $app->render(styleTree());
    $app->loop(20);
    echo '--- debug tree (style ' . (int) $tree . ') ---'
        . "\n" . \gpui_debug_tree($app->handle()) . "\n---\n";
    $shotOk = $app->snapshot($path, STYLE_W, STYLE_H);
    echo $shotOk ? "SHOT OK tree={$tree} {$path}\n" : "SHOT FAIL tree={$tree} {$path}\n";
    $app->close();
    exit($shotOk ? 0 : 1);
}

function runStyleSelfTest(): void
{
    ExampleState::boot(['section' => 12]);
    $app = new App();
    $app->create();
    $app->createWindow('Style Selftest', STYLE_W, STYLE_H);
    wireStyling($app);
    $app->render(styleTree());
    $app->loop(4);

    $ok = true;
    echo "STYLE SELFTEST\n";

    // 1) 整棵 §12 树在原生侧没有报错节点。这一域全是原语与 catalog 组件混排
    //    （progress / popup / tooltip / icon / catalog Button），
    //    建不出来时 gpui 不失败整帧，只画 "Failed to render X"，所以两条都要看。
    $err = $app->registeredError();
    step('registeredError', $err === '', $err === '' ? '空' : $err);
    $ok = $ok && $err === '';
    $cf = App::componentFailure();
    step('componentFailure', (int) $cf['count'] === 0, 'count=' . (int) $cf['count']
        . ' last=' . (string) $cf['last']);
    $ok = $ok && (int) $cf['count'] === 0;

    // 2) 47 个零参样式糖在**编出来的产物**里逐个调用一遍。
    //    这一格量两件事：nano 那边每个方法都真编进去了（未编进去的动态调用是 SIGABRT 而不是
    //    异常，只有跑产物能排掉），以及每个方法确实发出一条 style:<上游名字>。
    //    元素是 detached 的——不喂给 render，所以像素一位不动；shotdiff 仍全绿就是这条的证据。
    //    47 = 这批糖的数量；名字逐个等于上游 kKeywords（拼错由 parity 门 2c 静态拦），
    //    而"47 个不重复"防的是链上少写一个 / 同一个调两遍这种手滑。
    $styleEl = El::div()
        ->block()->grid()->visible()->invisible()
        ->flexRowReverse()->flexColReverse()->flexWrapReverse()->flexNowrap()->flexAuto()->flexInitial()
        ->itemsBaseline()->itemsStretch()->selfStart()->selfCenter()->selfEnd()->selfStretch()
        ->justifyAround()->justifyEvenly()
        ->contentCenter()->contentBetween()->contentAround()->contentEvenly()->contentStretch()
        ->overflowHidden()->aspectSquare()->whitespaceNowrap()->textEllipsis()
        ->lineThrough()->borderDashed()->shadowNone()->shadowLg()
        ->cursorText()->cursorMove()->cursorNotAllowed()->cursorGrab()
        ->fontThin()->fontLight()->fontNormal()->fontMedium()->fontBlack()
        ->textXs()->textSm()->textBase()->textLg()->textXl()->text2xl()->text3xl();
    $styleNames = [];
    foreach ($styleEl->ops as $op) {
        if ($op['kind'] === 'nullary_style') {
            $styleNames[] = (string) $op['name'];
        }
    }
    $sugarOk = count($styleNames) === STYLE_SUGAR_COUNT
        && count(array_unique($styleNames)) === STYLE_SUGAR_COUNT;
    step('nullaryStyles', $sugarOk, '发出=' . count($styleNames) . '/' . STYLE_SUGAR_COUNT
        . ' 去重后=' . count(array_unique($styleNames)));
    $ok = $ok && $sugarOk;

    // 3) icon() 的形状守卫：写错前缀/后缀必须抛（静默不画是它唯一的失败模式，
    //    Phase 9-d 把它改成编译期就炸）。正例也要过，否则这条门只是"总在抛"。
    $threw = false;
    try {
        El::icon('star.svg');            // 少了 icons/ 前缀
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }
    $goodIcon = El::icon('icons/star.svg');
    $iconOk = $threw && $goodIcon !== null;
    step('iconPathGuard', $iconOk, '坏路径抛=' . var_export($threw, true)
        . ' 正例建成=' . var_export($goodIcon !== null, true));
    $ok = $ok && $iconOk;

    // 4) SIZES 全枚举：每个取值都真发出一条 registered_method('size')。
    //    画廊那边是 foreach 整表遍历（不是手写四个字符串），这里数的是它遍历出来的 op 条数。
    $sizeOps = 0;
    foreach (El::SIZES as $sz) {
        $btn = El::catalogButton('st-size-' . $sz, $sz)->onClick()->sizeEnum($sz);
        foreach ($btn->ops as $op) {
            if ($op['kind'] === 'registered_method' && $op['name'] === 'size') {
                $sizeOps++;
            }
        }
    }
    $sizesOk = $sizeOps === count(El::SIZES);
    step('sizesEnum', $sizesOk, 'size op 条数=' . $sizeOps . '/' . count(El::SIZES));
    $ok = $ok && $sizesOk;

    $app->close();
    echo ($ok ? 'STYLE SELFTEST OK' : 'STYLE SELFTEST FAIL') . "\n";
    exit($ok ? 0 : 1);
}
