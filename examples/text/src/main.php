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
    // 指针事件族的 handler 必须在 render **之前**挂：回调 id 是 materialize 那一步定下来的，
    // 树都画完了再挂，shim 那边登记的还是渲染时那份，事件永远不落地。
    $mouseSeen = [];
    $mouseVals = [];
    foreach (['mouse_down_left', 'mouse_up_left', 'mouse_move'] as $mt) {
        $app->on('pr-hover', $mt, static function (array $e) use (&$mouseSeen, &$mouseVals, $mt): void {
            $mouseSeen[$mt] = (int) ($mouseSeen[$mt] ?? 0) + 1;
            $mouseVals[$mt] = (string) ($e['value'] ?? '');
        });
    }
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

    // 6) 181 个角色名要在**编出来的产物**里逐个 role() 一遍，未知角色必须当场抛。
    //    两头都要：上游对未知角色是 `if (role != None) element->Role(role)`（静默不加），
    //    所以 PHP 侧这一抛是那条哑路唯一的哨兵；而 `in_array` 在 nano 里对 181 个常量名
    //    的行为，只有跑产物才排得掉——PHP 层绿不代表编出来还绿。
    //    （画廊时代这条只在画廊跑，删了它就是静默丢覆盖，所以搬到这里。）
    $roleAllOk = true;
    $roleBad = '';
    foreach (El::ROLES as $r) {
        try {
            El::div()->role($r);
        } catch (\Throwable $t) {
            $roleAllOk = false;
            $roleBad = $r . ': ' . $t->getMessage();
            break;
        }
    }
    $roleThrows = false;
    try {
        El::div()->role('Heading');   // 上游表里就是这么拼的，但匹配只按 snake_case
    } catch (\Throwable $t) {
        $roleThrows = true;
    }
    step('rolesInArtifact', $roleAllOk && $roleThrows,
        '角色=' . count(El::ROLES) . '/allOk=' . var_export($roleAllOk, true)
        . ' unknownThrows=' . var_export($roleThrows, true)
        . ($roleBad === '' ? '' : ' 首个失败=' . $roleBad));
    $ok = $ok && $roleAllOk && $roleThrows;

    // 7) 指针事件族的**送达**（#71 那条缝的回归位，从画廊迁来）：pr-hover 那颗探针上挂着
    //    onMouseDownLeft / onMouseUpLeft / onMouseMove / onHover，这里真点真扫一遍，
    //    要求三类都到货，而且**值**是宿主拼出来的 CSV（x,y,button,clickCount,mods）。
    //    只看"到了没有"不够——形状不对说明走错了通道，而上游对未知事件是静默丢弃。
    //    tests/AppTest 那半边只把手工 CSV 过一遍 PHP，量不到送达；这条是产物里的送达位。
    $mouseSeen = [];
    $mouseVals = [];
    foreach (['mouse_down_left', 'mouse_up_left', 'mouse_move'] as $mt) {
        $app->on('pr-hover', $mt, static function (array $e) use (&$mouseSeen, &$mouseVals, $mt): void {
            $mouseSeen[$mt] = (int) ($mouseSeen[$mt] ?? 0) + 1;
            $mouseVals[$mt] = (string) ($e['value'] ?? '');
        });
    }
    // 命中表里逐颗试：只有 pr-hover 挂了指针回调，所以"hover 落到哪"就是它自己的位置，
    // 不必猜宽度（上一版拿"最宽的可点矩形"当探针，挑到的是 31px 的正文，0 事件到货）。
    $hoverX = 0;
    $hoverY = 0;
    $boxDump = '';
    for ($i = 1; $i < $total; $i++) {
        $h = $app->hitProbe($i);
        $cx = (int) $h['x'] + intdiv((int) $h['w'], 2);
        $cy = (int) $h['y'] + intdiv((int) $h['h'], 2);
        $boxDump .= $i . ':' . (int) $h['x'] . ',' . (int) $h['y'] . 'x' . (int) $h['w']
            . 'x' . (int) $h['h'] . '/c' . (int) $h['click'] . ' ';
        if ((int) $h['w'] <= 4 || (int) $h['h'] <= 4) {
            continue;
        }
        $app->realMouseMove($cx, $cy);
        $app->loop(2);
        if ((string) (ExampleState::$v['hover'] ?? '') === 'pr-hover') {
            $hoverX = $cx;
            $hoverY = $cy;
            break;
        }
    }
    $app->realMouseMove($hoverX + 6, $hoverY);
    $app->loop(2);
    $app->realClick($hoverX, $hoverY);
    $app->loop(4);
    // 不用 preg：形状判据就是"五个字段、第三位是按下计数 0、前两位带小数"，
    // 少编一个扩展就少一条 nano 里 catch 不住的 SIGABRT 路。
    $shape = explode(',', isset($mouseVals['mouse_down_left']) ? $mouseVals['mouse_down_left'] : '');
    $mouseShape = count($shape) === 5 && $shape[2] === '0'
        && str_contains($shape[0], '.') && str_contains($shape[1], '.');
    $hoverRouted = (string) (ExampleState::$v['hover'] ?? '') === 'pr-hover';
    $mouseOk = $hoverX > 0 && (int) ($mouseSeen['mouse_down_left'] ?? 0) > 0
        && (int) ($mouseSeen['mouse_up_left'] ?? 0) > 0
        && (int) ($mouseSeen['mouse_move'] ?? 0) > 0 && $mouseShape && $hoverRouted;
    step('pointerEventsDelivered', $mouseOk,
        '探针=' . $hoverX . ',' . $hoverY . ' hover=' . (string) (ExampleState::$v['hover'] ?? '—')
        . ' down=' . (int) ($mouseSeen['mouse_down_left'] ?? 0)
        . ' up=' . (int) ($mouseSeen['mouse_up_left'] ?? 0)
        . ' move=' . (int) ($mouseSeen['mouse_move'] ?? 0)
        . ' down值=' . ($mouseVals['mouse_down_left'] ?? '—')
        . ' 表=[' . trim($boxDump) . ']');
    $ok = $ok && $mouseOk;

    // 8) ariaLevel 的边界（同族的可访问性 op，过去只在画廊的壳里被顺带调到）。
    //    上游 base 的默认是 3，并且会把 <1 的层级**夹成 1**（materialize.cpp aria_level）——
    //    夹取是静默的，画面上什么都看不出来，所以 PHP 侧这一拒是那条哑路唯一的哨兵。
    $levelThrew = false;
    $levelWhy = '';
    try {
        El::div()->ariaLevel(0);
    } catch (\Throwable $t) {
        $levelThrew = true;
        $levelWhy = $t->getMessage();
    }
    $levelOps = opNames(El::div()->role('heading')->ariaLevel(2), 'method');
    $levelOk = $levelThrew && $levelOps === ['role', 'aria_level'];
    step('ariaLevelGuarded', $levelOk, '0 层抛=' . var_export($levelThrew, true)
        . '（' . ($levelWhy === '' ? '没抛' : $levelWhy) . '） 发出=' . implode('/', $levelOps));
    $ok = $ok && $levelOk;

    $app->close();
    echo ($ok ? 'TEXT SELFTEST OK' : 'TEXT SELFTEST FAIL') . "\n";
    exit($ok ? 0 : 1);
}
