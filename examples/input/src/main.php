<?php

declare(strict_types=1);

/**
 * examples/input/src/main.php — 功能域独立示例：输入与保留态取值。
 *
 * 存在理由（与原画廊的分工）：画廊是**一次跑 13 节**、报告只在末尾打印一次，
 * 所以任何一处崩都表现为"无声退非零"，量一颗输入框要把整个画廊跑完。这一支只装输入域，
 * 于是①编译只花时间在本域源上，②自检**边跑边打**（每步一行，见 step()），
 * 崩在哪一行就停在哪一行——这直接对应上一轮 rc=11 那种"退出码非零但一个字都没印"的现场。
 *
 * 开关：
 *   （无参）              交互模式，能真点真敲
 *   --sections           打印本示例的像素树清单（shotdiff 按它枚举基线）
 *   --shot <path> [tree] 出一棵树的 PNG
 *   --selftest           跑本域的硬门，rc=0 即通过
 *
 * 硬门只收"不需要真 OS 输入也能定论"的那些；真打字那一条（需要 bundle + key window，
 * 见 #56）在这里只当读数打印，不参与 rc——把它当门会变成随机红灯。
 */

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;

const INPUT_W = 560;
const INPUT_H = 620;

/**
 * 页面上 catalog 表单族的颗数 = 宿主造的 state 实体颗数（#81/#82）。
 *
 * 这个数字是 catalogStateReuse 那条门的判据，改 examples/_shared/features/inputs.php
 * 那一族时必须同步改这里——不同步就红，正是要的红。清单：
 *   in-cat(Input) in-cnum(NumberInput) in-cotp(OtpInput) in-cslider(Slider)
 *   in-ccolor(ColorPicker) in-ctime(TimeField) in-cdate(DatePicker) in-ccal(Calendar)
 */
const INPUT_CATALOG_STATES = 8;

function main(int $argc, array $argv): void
{
    // nano 下未捕获异常只印一行类名，位置与消息都没有：先包一层，把消息原样打出来。
    try {
        inputDispatch($argv);
    } catch (\Throwable $e) {
        echo 'FATAL ' . get_class($e) . ': ' . $e->getMessage() . "\n";
        exit(12);
    }
}

function inputDispatch(array $argv): void
{
    if (in_array('--sections', $argv, true)) {
        foreach (inputsTrees() as $tree => [$title, $_fn]) {
            echo $tree . "\t" . $title . "\n";
        }
        return;
    }
    if (in_array('--selftest', $argv, true)) {
        runSelfTest();
        return;
    }
    if (in_array('--bare-window', $argv, true)) {
        // #56 二分用：只建窗 + 跑帧，不接任何 handler / 不画 HUD / 不装 wiring。
        runBareWindow();
        return;
    }
    $waitIdx = array_search('--wait-key', $argv, true);
    if ($waitIdx !== false) {
        // 真人键盘验收：秒数钳在 5..600，给一个不至于手慢就判失败的窗口。
        $secs = isset($argv[$waitIdx + 1]) ? (int) $argv[$waitIdx + 1] : 30;
        runWaitKey(max(5, min(600, $secs)));
        return;
    }
    // --automation 的取值必须排在下面那道未知开关扫描**之前**：它曾经只在 runInteractive()
    // 里读 global $argv，于是 `input --automation <dir>` 单独用会被扫描拒掉（rc=2），
    // 只有 `--trace --automation` 才进得去——真二进制实测过这条分歧。
    $autoIdx = array_search('--automation', $argv, true);
    $autoDir = $autoIdx === false ? '' : (string) ($argv[$autoIdx + 1] ?? '');
    if ($autoIdx !== false && ($autoDir === '' || str_starts_with($autoDir, '--'))) {
        echo "用法: --automation <dir>\n";
        exit(2);
    }
    if (in_array('--trace', $argv, true) || $autoDir !== '') {
        runInteractive(in_array('--trace', $argv, true), $autoDir);
        return;
    }
    $shotIdx = array_search('--shot', $argv, true);
    if ($shotIdx !== false) {
        $path = isset($argv[$shotIdx + 1]) ? (string) $argv[$shotIdx + 1] : '/tmp/input.png';
        $tree = isset($argv[$shotIdx + 2]) ? (int) $argv[$shotIdx + 2] : 1;
        runShot($path, $tree);
        return;
    }
    // 未知开关必须拒绝。静默忽略会一路落到交互 run() 去开一扇窗口，于是"跑一下某个模式"
    // 变成永久挂住（实测踩过），拿不到任何结论；退出码 2 与"门红"的 1 分开。
    foreach ($argv as $arg) {
        if (is_string($arg) && str_starts_with($arg, '--')) {
            echo '未知开关: ' . $arg . "\n"
                . "可用：--sections / --selftest / --shot <path> [tree] / --wait-key <秒> / --trace\n"
                . "     / --automation <dir>（dropbox 动词，单独用即可，不必搭 --trace）\n"
                . "诊断：--bare-window（只建窗跑帧，#56 用）配合 --no-app-create（跳过 App::create）\n"
                . "     --wait-key 期间可加 --synthetic-key 让合成键也计数\n";
            exit(2);
        }
    }
    runInteractive();
}

/** 边跑边打：一步一行。崩溃时最后打出来的那行就是案发现场。 */
function step(string $name, bool $ok, string $detail): void
{
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ' — ' . $detail . "\n";
}

/** 本域树：builder 直接当窗口的整棵树（画廊里它外面还套着导航壳）。 */
function inputTree(): El
{
    return El::vFlex([sectionInputs(ExampleState::get())])->sizeFull()->bg(0xF6F3EE);
}


function runInteractive(bool $trace = false, string $autoDir = ''): void
{
    ExampleState::boot(['section' => 2, 'trace' => $trace]);
    $app = new App();
    wireInputs($app);
    if ($trace) {
        wireEventTrace($app);
    }
    // --automation <dir>：与 hello 同一张 dropbox（动词见 docs/guide/testing.md）。
    // input 这一支最需要它：它要验的正是"键/点击有没有到 PHP"。合成键走
    // gpui_real_key 的进程内路径，可以把"派发缝"与"OS 投递"分开验。
    if ($autoDir !== '') {
        $app->automation($autoDir);
    }
    $app->run('Input · 保留态取值', INPUT_W, INPUT_H, 'inputTree');
}

/** 收到过某类事件的不同 id 数。 */
function inEvCount(string $type): int
{
    return count((array) (ExampleState::$v['evSeen'][$type] ?? []));
}

function inEvSeenSummary(): string
{
    $parts = [];
    foreach ((array) ExampleState::$v['evSeen'] as $type => $ids) {
        $parts[] = (string) $type . '{' . implode(',', array_keys((array) $ids)) . '}';
    }
    return implode(' ', $parts);
}

/** --trace 的落盘：cmd/real-input-probe.py 读的就是这份，一行一个事件。 */
function inTrace(string $line): void
{
    if (!(bool) (ExampleState::$v['trace'] ?? false)) {
        return;
    }
    file_put_contents('/tmp/input-events.log', $line . "\n", FILE_APPEND);
}

/**
 * 通配记录器。onAny 按**类型**收所有 id 的事件，handler 拿的是整个 event 数组，
 * 所以这里一条就能覆盖五类；注册必须在 render 之前（回调 id 在 materialize 时定死）。
 */
function wireEventTrace(App $app): void
{
    foreach (['key_down', 'key_up', 'click', 'change', 'hover'] as $type) {
        $app->onAny($type, static function (array $e) use ($type): void {
            $id = (string) ($e['id'] ?? '-');
            ExampleState::$v['evSeen'][$type][$id] = true;
            inTrace($type . ' ' . $id . ' ' . (string) ($e['value'] ?? ''));
        });
    }
}

/** #56 二分用：只建窗跑帧的诊断开关（见 inputDispatch 的 --bare-window 分支）。 */
function runBareWindow(): void
{
    global $argv;
    $app = new App();
    // --no-app-create：跳过 App::create()（即 bridge 的 gpui_app_create → shim app_init），
    // 只留建窗与跑帧。#56 二分用：加上它读数不变 ⇒ 触发点不在 PHP 侧的调用序列上。
    // 注意这条开关**只能排除"调用序列"**，不能用来指认 nano/PHP 运行时：
    // 23:05 的 2×3 矩阵（同秒、每颗程序两种启动方式）显示零 PHP 的那支从 .app 里跑也会不在屏，
    // 所以"跳过之后仍不在屏 ⇒ nano 在场本身就够"这句当初的推论配错了对照（见 task_plan 17-E）。
    if (! in_array('--no-app-create', $argv, true)) {
        $app->create();
    }
    $app->createWindow('Bare Window Probe', 420, 300);
    $deadline = microtime(true) + 6.0;
    $frames = 0;
    while (microtime(true) < $deadline) {
        $app->loop(1);
        // 每帧读一次状态：#56 的"不在屏就重新排队"试验挂在 windowState 的账本那格里，
        // 不读就永远不触发（这条开关只服务诊断，不是产品路径）。
        $app->windowState();
        $frames++;
    }
    $st = $app->windowState();
    echo 'BARE frames=' . $frames
        . ' active=' . var_export($st['active'] ?? null, true)
        . ' key=' . var_export($st['key'] ?? null, true)
        . ' onScreen=' . var_export($st['onScreen'] ?? null, true)
        . ' peers=' . (int) ($st['onScreenPeers'] ?? -1)
        . ' bundled=' . var_export($st['bundled'] ?? null, true) . "\n";
    $app->close();
}

/**
 * --wait-key：真人键盘验收（从画廊迁来，是 #56 唯一的人机接口）。
 * 只有人能给的输入，就别让人去猜"我这一下到没到"：屏幕上写清要按什么，
 * 到了就把读数打出来并以退出码给结论（0=按到了，1=没按到），人不必翻日志。
 */
function runWaitKey(int $seconds): void
{
    ExampleState::boot(['section' => 2]);
    $app = new App();
    $app->create();
    $app->createWindow('Input WaitKey', INPUT_W, INPUT_H);
    $app->keepTryingForeground(20);
    wireInputs($app);
    wireEventTrace($app);
    $app->view(static function () use ($app): El {
        $st = $app->windowState();
        return El::vFlex([
            El::text('键盘人工验收：焦点已自动放到"你的名字"那颗框，直接按几个字母键，最后按 Escape')
                ->font(17.0)->semibold()->p(14.0),
            El::text('key_down=' . inEvCount('key_down') . ' · key_up=' . inEvCount('key_up')
                . ' · click=' . inEvCount('click') . ' · change=' . inEvCount('change'))
                ->font(15.0)->px(14.0),
            El::text('active=' . var_export($st['active'] ?? null, true)
                    . ' key=' . var_export($st['key'] ?? null, true)
                    . ' 在屏=' . var_export($st['onScreen'] ?? null, true)
                    . ' 焦点 id=' . $app->focusedId() . ' 陷阱 id=' . $app->focusTrap())
                ->font(12.0)->px(14.0)->pt(8.0),
            El::text('按到键或计时结束就自己退出；结论也写在终端里。')
                ->font(12.0)->px(14.0)->pt(8.0),
            // 这一格要验"物理 keyDown 能不能进 PHP"，就必须真有一颗能吃键的输入框：
            // 2026-10-17 前这棵树里**一颗输入都没有**（只有四行说明文字），
            // 于是 focusElement('in-name') 恒 0、屏上那句"焦点已自动放好"是空头支票，
            // 真人按多少键都不会有 key_down。补上这一颗，并让预放焦点真的落在它上面。
            El::input()->id('in-name')->placeholder('你的名字')->w(320.0),
        ])->sizeFull()->gap(6.0);
    });
    $deadline = microtime(true) + $seconds;
    $frames = 0;
    $sawKey = false;
    // 窗口状态打进终端，但**必须在泵够帧之后**：NSWindow 是 OrderFrontOnce 到首次 Redraw 才建的，
    // 第一帧之前读到的 found:0/visible:false 是时序而非缺陷（2026-10-15 就这么误判过一次）。
    // 也不在这期间用 System Events / lsappinfo 查窗口——那会把前台抢走，探针毁掉被测量。
    $stateDumped = false;
    $baseAppkit = 0;
    // 输入框初值（第 1 帧后读）。成功判据要含"字真的进了框"：
    // 2026-10-17 实测进程内真派发缝按一次 'a' ⇒ value='a'、change=1、nsappDeliveries=2，
    // 而 key_down 仍是 **0**（字符被输入框吃掉、只发 change）——只认 key_down 会把
    // 一次成功的真人输入误判成"派发丢了"。
    $baseText = '';
    while (microtime(true) < $deadline) {
        $app->loop(1);
        $frames++;
        if ($frames === 1) {
            $baseText = $app->text('in-name');
        }
        if (! $stateDumped && $frames >= 12) {
            $stateDumped = true;
            $baseAppkit = (int) ($app->windowState()['appkitDeliveries'] ?? 0);
            echo 'WAIT-KEY-STATE@f' . $frames . ' ' . json_encode($app->windowState())
                . ' fgTries=' . $app->foregroundTries() . ' trap=' . $app->focusTrap()
                . ' focus=' . $app->focusedId() . "\n";
            // 自己把焦点放上那颗框。真人这一格要验的是"物理 keyDown 能不能进 PHP"，
            // 而"点击命中输入框"已由 selftest 的 focusFollowsClick 覆盖（合成路）——
            // 不预放焦点就要人同时点准 + 打字，任一没中都读成 0，分不出断在哪一段。
            if ($app->focusedId() === 0) {
                // 两次读数：设完立刻读（是否被当场丢弃）与走一帧后读（是否只是延迟落地）。
                $armSet = $app->focusElement('in-name');
                $armNow = $app->focusedId();
                $app->loop(1);
                echo 'WAIT-KEY-ARM focusElement(in-name)=' . $armSet
                    . ' now=' . $armNow . ' after1=' . $app->focusedId() . "\n";
            }
            // `--synthetic-key`：用进程内真派发缝按一次 'a'（macOS 键码 0），只做诊断——
            // 它证明"焦点→键→PHP→值"这条链是通的；**不**替代 17-E 要的真人 HID 记录。
            if (in_array('--synthetic-key', $GLOBALS['argv'], true) && $app->focusedId() !== 0) {
                $okKey = $app->realKey(0, 'a');
                $app->loop(2);
                echo 'WAIT-KEY-ARM synthetic realKey(a)=' . var_export($okKey, true)
                    . ' key_down=' . inEvCount('key_down')
                    . ' change=' . inEvCount('change')
                    . ' value=' . var_export($app->text('in-name'), true) . "\n";
            }
        }
        if (inEvCount('key_down') > 0 || inEvCount('key_up') > 0) {
            $sawKey = true;
            // 按到了再多收几帧，让 Escape / 连续按键都进读数。
            $deadline = min($deadline, microtime(true) + 8);
        }
    }
    $stEnd = $app->windowState();
    $finalText = $app->text('in-name');
    $typed = ($finalText !== '' && $finalText !== $baseText);
    echo 'WAIT-KEY seconds=' . $seconds . ' frames=' . $frames
        . ' key_down=' . inEvCount('key_down') . ' key_up=' . inEvCount('key_up')
        . ' click=' . inEvCount('click') . ' change=' . inEvCount('change')
        . ' focus=' . $app->focusedId() . ' typed=' . ($typed ? 'yes' : 'no')
        . ' text=' . var_export($finalText, true) . ' seen=' . ($sawKey ? 'yes' : 'no')
        // 结束时再报一次窗口态与两个计数：f12 那次只说明"开局窗口是 key"，
        // 人要分辨的是"我按的那几下有没有进到本进程"（appkitDeliveries）与
        // "进来了有没有派发到 PHP"（上面的 key_down/click）。
        . ' endState=' . json_encode($stEnd)
        . ' evSeen=' . inEvSeenSummary() . "\n";
    // 结论自己说，别让人去比对三段 JSON 谁涨谁没涨：断在哪一段，处置完全不同。
    $endAppkit = (int) ($stEnd['appkitDeliveries'] ?? 0);
    // 判"到没过本进程"要用**增量**：实测没人按键时这个计数也会自己涨（8 秒那次 1→7），
    // 绝对值为 0 不是有效信号——开局基线之后的差值才是。
    $deltaAppkit = $endAppkit - $baseAppkit;
    $verdict = 'OK';
    if (! ($stEnd['onScreen'] ?? false)) {
        // 这一条排在最前，因为它让后面几条失去意义：物理键鼠按 WindowServer 的**在屏**
        // 账本派发，不在屏就一条都到不了，与输入法、与"人有没有按"都无关。
        // 实测（2026-10-15 本机）：本 app 恒 onScreen=false 而 active/key/visible/main
        // 全为 true；同一台机器、同一个会话里一个 60 行的普通 AppKit 程序 onScreen=true。
        $verdict = 'WindowServer 没把这扇窗放上屏（onScreen=false）⇒ 物理按键不可能到位，'
            . '不必再试输入法；断点在宿主侧（#56），合成路径不受影响';
    } elseif ($deltaAppkit === 0) {
        $verdict = 'OS 没送到本进程（激活/输入法管线）——先切 ABC 再试，仍 0 就是宿主事件环';
    } elseif ($app->focusedId() === 0) {
        $verdict = '送到了本进程，但没有任何元素拿到焦点——点击没命中输入框（查命中/布局）';
    } elseif ($typed) {
        // 字符被输入框消费时只会发 change、不发 key_down（2026-10-17 实测），
        // 所以"字进来了"是一条有效且更贴近真人意图的成功判据。
        $verdict = 'OK：字已经进了输入框（value=' . $finalText . '）——键→输入框→PHP 这条链通了';
    } elseif (inEvCount('key_down') === 0) {
        $verdict = '有焦点却没收到 key_down——派发那一层丢了（查 keyDown→宿主入队）';
    }
    echo 'WAIT-KEY-VERDICT ' . $verdict . ' 事件增量=' . $deltaAppkit
        . '（开局 ' . $baseAppkit . ' → 结束 ' . $endAppkit . "）\n";
    $app->close();
    exit(($sawKey || $typed) ? 0 : 1);
}

function runShot(string $path, int $tree): void
{
    $trees = inputsTrees();
    $tree = isset($trees[$tree]) ? $tree : 1;
    ExampleState::boot(['section' => 2]);

    $app = new App();
    $app->create();
    $app->createWindow('Input Shot', INPUT_W, INPUT_H);
    wireInputs($app);
    $app->render(inputTree());
    $app->loop(20);
    echo '--- debug tree (input ' . $tree . ': ' . (string) $trees[$tree][0] . ') ---'
        . "\n" . \gpui_debug_tree($app->handle()) . "\n---\n";
    $ok = $app->snapshot($path, INPUT_W, INPUT_H);
    echo $ok ? "SHOT OK tree={$tree} {$path}\n" : "SHOT FAIL tree={$tree} {$path}\n";
    $app->close();
    exit($ok ? 0 : 1);
}

function runSelfTest(): void
{
    ExampleState::boot(['section' => 2]);
    $app = new App();
    $app->create();
    $app->createWindow('Input Selftest', INPUT_W, INPUT_H);
    // 前台自举（runWaitKey 同款先例）：enterResolves / timeFieldPressTakesFocus
    // 这两条真输入门的前提是窗口 key/main，后台 shell 里 macOS 正当拒绝抢焦点时
    // 它们只能记"未验"——框架自己先试 20 帧，能抢到就在本机拿到真读数；抢不到
    // （托管 runner）照旧走前提式分支，不判红。
    $app->keepTryingForeground(20);
    wireInputs($app);
    $app->render(inputTree());
    $app->loop(3);

    $ok = true;
    echo "INPUT SELFTEST\n";

    // 1) 原生侧没有报错节点。registered 组件建不出来时 gpui 不失败整帧，只画
    //    "Failed to render X"，所以两条都要看：registeredError() 与累计失败计数。
    $err = $app->registeredError();
    step('registeredError', $err === '', $err === '' ? '空' : $err);
    $ok = $ok && $err === '';
    $cf = App::componentFailure();
    step('componentFailure', (int) $cf['count'] === 0, 'count=' . (int) $cf['count']
        . ' last=' . (string) $cf['last']);
    $ok = $ok && (int) $cf['count'] === 0;

    // 2) 输入框 bounds 表里登记的每一颗都**真**建成了 Input，且矩形互不相同。
    //    materialize 对建不出来的 retained 是 `return Div(cx->a)`：静默降级成一颗 Div，
    //    不聚焦、不能打字、点它没事件，而 registeredError() 还是空的。看得见它的
    //    地方只有 gpui 在 paint 里登记的 inputBounds（gpui.cpp:7815）。
    //    注意这条不声明"覆盖全部 retained"：实测 n=4 而本域挂 retained 的有 6 颗
    //    （input×2 / textarea / number_input / otp / slider），那张表少的是哪两颗
    //    探针答不出（只给矩形不给 id）。画廊只能验"最后一颗"（inputProbe(-1)），
    //    这一支窗口小、逐索引把每一颗都取出来——这是拆分的直接收益。
    $probe = $app->inputProbe();
    $n = (int) ($probe['n'] ?? 0);
    $rects = [];
    $badIdx = [];
    for ($i = 0; $i < $n; $i++) {
        $b = $app->inputProbe($i);
        if ((int) $b['w'] <= 0 || (int) $b['h'] <= 0) {
            $badIdx[] = $i;
        } else {
            $rects[(int) $b['x'] . ',' . (int) $b['y']] = true;
        }
    }
    $out = $app->inputProbe($n);
    $outOk = (int) $out['w'] === 0 && (int) $out['h'] === 0;
    $inputsReal = $n > 0 && $badIdx === [] && count($rects) === $n && $outOk;
    step('inputBounds', $inputsReal, 'n=' . $n . ' 有效框=' . count($rects)
        . ' 越界读数零=' . var_export($outOk, true)
        . ($badIdx === [] ? '' : ' 塌框索引=' . implode('/', $badIdx)));
    $ok = $ok && $inputsReal;

    // 2b) catalog 表单族（#81）：这一页比拆分前多一行 `El::catalogInput('in-cat', …)`。
    //     它与 `El::input()` 是**两个组件**，不是糖度差别：后者是 shell 的
    //     ComponentKind::Input，materialize 走不带样式那个重载（base/input.cpp:34-39），
    //     天生裸框；带边框/焦点态那颗是 catalog 的 `Input`，构造只吃**一颗宿主造的
    //     `InputState` 实体**（retained_forms/mod.cpp:538-554）。这条链建不出来时上游
    //     照旧静默降级成 Div（registeredError() 也是空的），所以断的是外显读数：
    //     框数确实因为这一行变多——改前实测 n=4，加了 in-cat 是 5，铺满一族之后 6。
    //     探针只给矩形不给 id，所以这里断**下界**不断定值：哪一颗进那张表由上游布局决定，
    //     定值会让任何一次布局改动都红，而下界照样抓着"in-cat 那颗没建成"。
    $catOk = $n >= 5;
    step('catalogInputRect', $catOk, 'n=' . $n . '（这一族都不在时实测 4，全铺上是 6）');
    $ok = $ok && $catOk;

    // 2c) 实体是按帧复用、不是每帧新建。读数取**上游实体表的长度**而不是我方缓存条数：
    //     缓存失效时是同一个 (kind,key) 被覆盖，我方条数看不出来，而表长度每帧 +1。
    //     期望值就是页面上 catalog 那一族的颗数（INPUT_CATALOG_STATES）。
    $sc = $app->stateCounts();
    $statesEarly = (int) ($sc['componentStates'] ?? -1);

    // 3) 取值回环：setInput → shim 的 inputHandles → InputSetValue/OtpSetValue → text。
    //    四颗带值的 retained 各来一遍——串到别的字段上（同一次改动踩过一次误报）
    //    只有逐个验才看得出来。in-otp 走的是 OtpState 不是 InputState：
    //    CreateRetainedOtp 填的是 entry->otp，所以旧那两句 `if (!e->input) return 0`
    //    把它当成"没有值可读"，setInput 直接回 false 而 registeredError 还是空的
    //    ——这一条是这一支示例第一次跑出来才发现的，画廊那 13 节里它一直挂着。
    $pull = [];
    foreach (['in-name' => '甲', 'in-note' => '乙丙', 'in-num' => '7', 'in-otp' => '1234'] as $id => $val) {
        $set = $app->setInput($id, $val);
        $got = $app->text($id);
        if (!$set || $got !== $val) {
            $pull[] = $id . '=' . var_export($got, true) . '/set=' . var_export($set, true);
        }
    }
    step('retainedRoundtrip', $pull === [], $pull === [] ? '四颗各自回环一致' : implode(' ', $pull));
    $ok = $ok && $pull === [];

    // 4) change 路由：合成 fire → gpuiEnqueueChange → 队列 → poll → dispatch → handler。
    //    那条缝只认三类（cpp-src/gpui_shim.cpp 的 gpui_shim_selftest_fire 只有
    //    click/change/hover 三个分支，其余 return 0），fire 的是 change，
    //    而它带过来的值是 **true**（gpuiEnqueueChange(self, nullptr, cbid, true)），
    //    不是控件自己的值——所以 in-vol 的 handler 把默认 30 写成了 1。
    //    这条判"线通不通 + 值槽有没有东西"；"值对不对"由 3) 的 setInput 回环
    //    与 5) 的真打字各自负责。
    $before = (int) ExampleState::$v['vol'];
    $fired = \gpui_selftest_fire($app->handle(), 'in-vol', 'change');
    $event = \gpui_poll_event($app->handle());
    if (!empty($event)) {
        $app->dispatch($event);
    }
    $after = (int) ExampleState::$v['vol'];
    $routed = $fired && $before === 30 && $after === 1;
    step('changeRoute', $routed, 'fired=' . var_export($fired, true) . ' vol:' . $before
        . '→' . $after);
    $ok = $ok && $routed;

    // 4b) 问候跟着 **state** 走，不跟着事件载荷走——参考 examples/input.cpp 的 OnChange
    //     读的是 `InputCStr(&self->inputState)`，不是事件里的值。合成 change 带过来的值
    //     是 true（见 4)），信载荷的 handler 会把问候画成 "Hello, 1!"
    //     （2026-10-22 用 automation + screenshot 在真窗口上实测到）；读 state 的两条路
    //     （合成 change / 真键入的 change）都对。3) 已把 in-name 设成 '甲'，这里只 fire。
    $firedName = \gpui_selftest_fire($app->handle(), 'in-name', 'change');
    $nameEvent = \gpui_poll_event($app->handle());
    if (!empty($nameEvent)) {
        $app->dispatch($nameEvent);
    }
    $greet = (string) ExampleState::$v['name'];
    $follows = $firedName && $greet === '甲' && $app->text('in-name') === '甲';
    step('greetingFollowsState', $follows, 'state=' . var_export($greet, true)
        . ' text=' . var_export($app->text('in-name'), true));
    $ok = $ok && $follows;

    // 4c) checkbox 的合成 change。fire 带来的载荷是 true（见 4)），对这颗恰好就是
    //     "勾上"那半：handler 里的 checked() 读的是 element_value（最近一次随 change
    //     上报的值，shim 的 rememberValue），所以一次 fire 同时锁两个回归位——
    //     线通不通，与 element_value→checked() 的 bool 语义（真值才翻得了 state）。
    //     FakeBridge 那半（tests/SystemTest.php）只量假实现，原生链此前无人断。
    $firedAgree = \gpui_selftest_fire($app->handle(), 'in-agree', 'change');
    $agreeEvent = \gpui_poll_event($app->handle());
    if (!empty($agreeEvent)) {
        $app->dispatch($agreeEvent);
    }
    $agreeOk = $firedAgree && $app->checked('in-agree') === true
        && ExampleState::$v['agree'] === true;
    // bug C 的两条追加腿。handler 写 state 只是「事件到了」；可见的半截是
    // 「下一帧 builder 从 state 把勾选态画出来」——以前 builder 写死 false，
    // 点了有事件、屏上永远不勾，这条链上原来的门一条都抓不住。
    //   腿 1（描述层）：重渲染一帧后 debug_tree 的 in-agree 行必须出现 checked=true op
    //   （El::checkbox 第二参读 state ⇒ bridge 下发 checked ⇒ materialize 建
    //   CheckboxState::Checked）。
    //   腿 2（像素层）：gpui_shim_snapshot 是**在屏像素截图**，debug_tree 只是 spec
    //   描述、不配当像素证据——勾选重渲染前后各截一帧，两帧必须逐字节不同
    //   （boot 态下两帧之间唯一的差异来源就是 agree 翻转：勾选框打勾 + 旁边那行
    //   「已勾选/未勾选」换字）；boot 帧本身由重录后的 tree1.png 基线在 shotdiff 那头锚住。
    $agreeShotBefore = sys_get_temp_dir() . '/input-agree-before.png';
    $agreeShotAfter = sys_get_temp_dir() . '/input-agree-after.png';
    $snapBefore = $app->snapshot($agreeShotBefore, INPUT_W, INPUT_H);
    $app->render(inputTree());
    $app->loop(2);
    $snapAfter = $app->snapshot($agreeShotAfter, INPUT_W, INPUT_H);
    $agreeLine = '';
    foreach (explode("\n", (string) \gpui_debug_tree($app->handle())) as $line) {
        if (str_contains($line, ':id("in-agree")')) {
            $agreeLine = trim($line);
        }
    }
    $agreeTreeOk = str_contains($agreeLine, ':checked(true)');
    $agreePixelOk = $snapBefore && $snapAfter && is_file($agreeShotBefore) && is_file($agreeShotAfter)
        && md5_file($agreeShotBefore) !== md5_file($agreeShotAfter);
    $agreeOk = $agreeOk && $agreeTreeOk && $agreePixelOk;
    step('checkboxRoute', $agreeOk, 'checked=' . var_export($app->checked('in-agree'), true)
        . ' state.agree=' . var_export(ExampleState::$v['agree'], true)
        . ' debug_tree=' . var_export($agreeLine, true)
        . ' snapshot前后异=' . var_export($agreePixelOk, true)
        . '（snap ' . var_export($snapBefore, true) . '/' . var_export($snapAfter, true) . '）');
    $ok = $ok && $agreeOk;

    // 4c-2) checkboxRealClick：真点击门（合成缝盖不住的半截）。合成 fire 只验"事件
    //      链路通"，不验"真人点鼠标能不能点到那颗控件"。修前 hitProbe 全表里 checkbox
    //      行是 0×0 死面（listener 挂上但矩形是空的），realClick 打在空矩形上不触发
    //      change。修后组件层 Checkbox 的 FlexRow 有正常矩形。
    //      判据：① 扫 hitProbe 全表，收 x<=64（左侧 checkbox 行）且 14<=h<=26 且
    //      40<=w<=180（行宽含 label）且 click==1 的候选，恰好 1 条；② realClick 中心
    //      断返回 true（realClick 语义=box->queue 增长，见 gpui_shim_services.inc:374-396）；
    //      ③ 点击后 state.agree 翻 true（handler 写 state），再点同一颗翻回 false（toggle 双向）。
    $cbCandidates = [];
    $cbN = (int) ($app->hitProbe()['n'] ?? 0);
    for ($i = 0; $i < $cbN; $i++) {
        $h = $app->hitProbe($i);
        if ((int) $h['click'] === 1 && (int) $h['h'] >= 14 && (int) $h['h'] <= 26
            && (int) $h['w'] >= 40 && (int) $h['w'] <= 180 && (int) $h['x'] <= 64) {
            $cbCandidates[] = ['i' => $i, 'x' => (int) $h['x'], 'y' => (int) $h['y'],
                'w' => (int) $h['w'], 'h' => (int) $h['h']];
        }
    }
    $cbOne = count($cbCandidates) === 1;
    $cbRealOk = false;
    $cbDetail = '';
    if ($cbOne) {
        $cb = $cbCandidates[0];
        $cbDetail = 'rect=' . $cb['x'] . ',' . $cb['y'] . 'x' . $cb['w'] . 'x' . $cb['h'];
        // toggle 双向：不预设初始态（前面的 checkboxRoute 已把 checked 设成 true）。
        // realClick 语义=事件送进窗口（gpui_shim_services.inc:374-396）；change 事件在
        // loop() 里泵出，判据看 checked() 翻转，不看 realClick 返回值（与 stepDelivered 同型）。
        $cbBefore = $app->checked('in-agree');
        $app->realClick($cb['x'] + (int) ($cb['w'] / 2), $cb['y'] + (int) ($cb['h'] / 2));
        $app->loop(3);
        $cbAfter = $app->checked('in-agree');
        // 再点一次翻回
        $app->realClick($cb['x'] + (int) ($cb['w'] / 2), $cb['y'] + (int) ($cb['h'] / 2));
        $app->loop(3);
        $cbBack = $app->checked('in-agree');
        $cbRealOk = $cbAfter !== $cbBefore && $cbBack === $cbBefore;
        $cbDetail .= ' checked:' . var_export($cbBefore, true) . '→' . var_export($cbAfter, true)
            . '→' . var_export($cbBack, true);
    } else {
        $cbDetail = '候选=' . count($cbCandidates) . ' 条（期望 1）；修前 0×0 死面会在此红';
    }
    step('checkboxRealClick', $cbOne && $cbRealOk, $cbDetail);
    $ok = $ok && $cbOne && $cbRealOk;

    // 4d) in-note 的 handler 从 **state** 重算字数（不信载荷）。3) 已把 in-note 设成
    //     '乙丙'，strlen 期望 '6'——strlen 是**字节**口径，两颗 UTF-8 汉字各 3 字节
    //     （初版判据写 '2' 是把它当字符数了，2026-10-25 首跑即红）；信载荷的写法会
    //     得到 '1'（载荷是 true），这里一并分掉。
    $firedNote = \gpui_selftest_fire($app->handle(), 'in-note', 'change');
    $noteEvent = \gpui_poll_event($app->handle());
    if (!empty($noteEvent)) {
        $app->dispatch($noteEvent);
    }
    $noteWant = (string) strlen($app->text('in-note'));
    $noteOk = $firedNote && ExampleState::$v['note'] === $noteWant && $noteWant === '6';
    step('noteRoute', $noteOk, 'note=' . var_export(ExampleState::$v['note'], true)
        . ' strlen(text(in-note))=' . $noteWant . '（字节口径）');
    $ok = $ok && $noteOk;

    // 4e) 深色开关的可见回路：handler 除了写 state 还要 setTheme（控件名承诺"深色"）。
    //     合成载荷 true 走 checked() 分支 ⇒ theme 翻成 'dark'。setTheme 是被动方法、
    //     没有回读口，门断 state 翻转；主题真画没画深色属像素验收，无头门不假装断得住。
    $firedDark = \gpui_selftest_fire($app->handle(), 'in-dark', 'change');
    $darkEvent = \gpui_poll_event($app->handle());
    if (!empty($darkEvent)) {
        $app->dispatch($darkEvent);
    }
    $darkOk = $firedDark && ExampleState::$v['theme'] === 'dark';
    step('darkRoute', $darkOk, 'theme=' . var_export(ExampleState::$v['theme'], true));
    $ok = $ok && $darkOk;

    // 4e-2) switchRealClick：与 checkboxRealClick 同型——真点击 in-dark 行，
    //      断 state.theme 翻 'dark' → 再点翻回 'light'（toggle 双向）。
    //      修前开关行是 0×0 死面（同 checkbox），realClick 打空矩形不触发 change。
    //      候选几何：x 在 checkbox 右侧（约 90-260）、h 14-26、w 40-180、click==1。
    $swCandidates = [];
    $swN = (int) ($app->hitProbe()['n'] ?? 0);
    for ($i = 0; $i < $swN; $i++) {
        $h = $app->hitProbe($i);
        // switch 与 checkbox 同行（同一个 hFlex），x 区分：checkbox x<64 已被前段收走，
        // switch 在 checkbox 右侧。
        if ((int) $h['click'] === 1 && (int) $h['h'] >= 14 && (int) $h['h'] <= 26
            && (int) $h['w'] >= 40 && (int) $h['w'] <= 180
            && (int) $h['x'] >= 64 && (int) $h['x'] <= 300) {
            $swCandidates[] = ['i' => $i, 'x' => (int) $h['x'], 'y' => (int) $h['y'],
                'w' => (int) $h['w'], 'h' => (int) $h['h']];
        }
    }
    $swOne = count($swCandidates) === 1;
    $swRealOk = false;
    $swDetail = '';
    if ($swOne) {
        $sw = $swCandidates[0];
        $swDetail = 'rect=' . $sw['x'] . ',' . $sw['y'] . 'x' . $sw['w'] . 'x' . $sw['h'];
        // toggle 双向：不预设初始态（darkRoute 已把 theme 设成 'dark' 且 native 持久）。
        // realClick 送事件进窗口，loop() 泵出 change，判据看 theme 翻转。
        ExampleState::$v['theme'] = 'light';
        $app->render(inputTree());
        $app->loop(2);
        $swBefore = ExampleState::$v['theme'];
        $app->realClick($sw['x'] + (int) ($sw['w'] / 2), $sw['y'] + (int) ($sw['h'] / 2));
        $app->loop(3);
        $swAfter = ExampleState::$v['theme'];
        $app->realClick($sw['x'] + (int) ($sw['w'] / 2), $sw['y'] + (int) ($sw['h'] / 2));
        $app->loop(3);
        $swBack = ExampleState::$v['theme'];
        $swRealOk = $swAfter !== $swBefore && $swBack === $swBefore;
        $swDetail .= ' theme:' . var_export($swBefore, true) . '→' . var_export($swAfter, true)
            . '→' . var_export($swBack, true);
    } else {
        $swDetail = '候选=' . count($swCandidates) . ' 条（期望 1）；修前 0×0 死面会在此红';
    }
    step('switchRealClick', $swOne && $swRealOk, $swDetail);
    $ok = $ok && $swOne && $swRealOk;

    // 5) 真点击 + 真按键：逐索引把 paint 登记过的每一颗框点一遍、按一个真实键，
    //    看它改掉了哪一格状态。为什么不按 id 点：inputProbe 只给矩形不给 id，
    //    而"第 i 索引对应哪一颗"随布局变——按状态改动反查就不依赖顺序，
    //    顺带把"每一颗都打得进字"量全（原画廊的 --livetyping 对整个 13 节做同一件事）。
    //    key_down 也在这里量：合成缝不认 key_down（见 4)），真键是它唯一的路（#53 的回归位）。
    //    判据用打字的结果本身，**并且**焦点也当门：这里以前写着"focusedId() 对 retained
    //    input 实测恒 0，拿它当门会假红"，2026-10-14 复量推翻了三句里的两句——见下面
    //    focusFollowsClick 的读数。
    //    这条在 CI 上是**裸二进制**跑的。原来这里写着"原画廊的 --liveclick/--livetyping 同样如此
    //    并实测通过，所以它够格当硬门；不成立就是真回归，不是环境抖动"——那句被 2026-10-15 的
    //    run 38682636928 直接推翻：CI 上画廊就红在这条链上（focus=0、字没进 in-name），
    //    而本机同一批产物是绿的。差别是前台/焦点归谁，不是代码。所以下面两条门都只断
    //    **链与关系**，把"哪一颗拿到焦点"这半交出去；能断住的回归仍然写在各自行里。
    ExampleState::$v['name'] = '';
    ExampleState::$v['key'] = '—';
    $typedIdx = -1;
    $keyIdx = -1;
    // in-note 的索引同样按行为定位：哪一颗被点中被敲字后 text('in-note') 变长，
    // 哪一颗就是它。A 门的回车换行臂要用这个索引（inputProbe 只给矩形不给 id）。
    $noteIdx = -1;
    $sweep = [];
    $focIds = [];
    $focTried = 0;
    $focDump = '';
    for ($i = 0; $i < $n; $i++) {
        $b = $app->inputProbe($i);
        if ((int) $b['w'] <= 0) {
            continue;
        }
        $noteLenBefore = strlen((string) $app->text('in-note'));
        $app->realClick((int) $b['x'] + (int) $b['w'] / 2, (int) $b['y'] + (int) $b['h'] / 2);
        $app->loop(2);
        // 点完之后 gpui 认为谁在焦点上。以前这里写着"focusedId() 对 retained input 恒 0，
        // 拿它当门会假红"——2026-10-14 复量已经不成立（每颗都回一个稳定的负数实体 id），
        // 所以先把逐颗读数打出来，再判它够不够格当门。
        $foc = $app->focusedId();
        $focIds[$foc] = true;
        $focTried++;
        $focDump .= ($focDump === '' ? '' : ' ') . $i . '→' . $foc;
        // 9 是 kVK_ANSI_V（kVK_ANSI_G 是 5）：合成事件的字符 'g' 与键码不对应同一颗
        // 物理键，这是有意的——门判的是「字符进了焦点元素」，别按 keyCode 分位读数。
        $pressed = $app->realKey(9, 'g');
        $app->loop(2);
        $sweep[] = $i . ':' . var_export($pressed, true) . '/f' . $foc;
        if ($typedIdx < 0 && (string) ExampleState::$v['name'] !== '') {
            $typedIdx = $i;
        }
        if ($keyIdx < 0 && (string) ExampleState::$v['key'] !== '—') {
            $keyIdx = $i;
        }
        if ($noteIdx < 0
            && strlen((string) $app->text('in-note')) > $noteLenBefore) {
            $noteIdx = $i;
        }
    }
    $typedIn = $typedIdx >= 0;
    step('realTyping', $typedIn, 'index→keyPressed=' . implode(' ', $sweep)
        . ' 打进字的索引=' . $typedIdx
        . ' name=' . var_export(ExampleState::$v['name'], true));
    $ok = $ok && $typedIn;

    // 焦点跟着点击走：吃到焦点的那些颗各自一个非零实体 id，且互不相同。
    // 这条 2026-10-14 才敢立——旧注释写着"focusedId() 对 retained input 实测恒 0，
    // 拿它当门会假红"，复量的读数是 0→-1001 1→-1002 2→-1003 3→-1004，那句已经不成立。
    // 断的是**关系**，不断具体数字：实体 id 由 gpui 按创建顺序发，加一颗输入框就会整体挪位。
    // 2026-10-15 把"每一颗都必须拿到焦点"这半摘掉：CI 上 showcase 的同一套扫描在裸二进制下
    // 实测出现过 focus=0（前台是竞争态），逐颗全覆盖会把环境抖动读成回归。
    // 仍然断得住两种真回归：焦点一次都不跟着走（0 颗非零 ⇒ 红）、点谁都是同一颗（1 个 ⇒ 红）。
    $focReal = [];
    foreach ($focIds as $fkey => $fseen) {
        if ((int) $fkey !== 0) {
            $focReal[(int) $fkey] = true;
        }
    }
    // 前提不成立就记"未验"：托管 runner 上两次同 commit 的读数都是 active=false/key=false
    // （见 task_plan 17-D），那时 focusedId() 恒 0——这条门在那台机器上测的其实是
    // "窗口能不能拿到系统焦点"，那是 17-E 真人那一格，不是代码回归。
    // 形状跟 hello 的 bundleGreen/routeGreen 一致：前提成立才断言，读数照原样打出来，
    // 未验的时候一眼能看出是环境不给，而不是门没跑。
    $winFocus = $app->windowState();
    $focusApplicable = (bool) ($winFocus['key'] ?? false) || (bool) ($winFocus['main'] ?? false);
    $focusOk = ! $focusApplicable || count($focReal) >= 2;
    step('focusFollowsClick', $focusOk, '点过 ' . $focTried . ' 颗，拿到焦点的不同实体 id '
        . count($focReal) . ' 个 [' . $focDump . '] applicable=' . var_export($focusApplicable, true)
        // 仪表自证臂：上面那圈真点击/真按键是经 AppKit 派发到本进程的，
        // appkitDeliveries 若仍为 0，说明这条计数是空转的（不能拿它去判真人那一按）。
        . ' appkitDeliveries=' . (int) ($app->windowState()['appkitDeliveries'] ?? -1));
    $ok = $ok && $focusOk;

    // 值回读门（审核 P0-4 的回归位）：elementValue() 读的是 shim 随 change 事件留下的
    // "最近一次上报值"。真打字那个 'g' 必须能从读口拿回来——
    // enqueue → rememberValue → bridge → PHP，与用户手打之后想读值走的是同一条链。
    // 断的是**链**，不是"字一定打进哪一颗"：CI 实测同一个表达式钉死 in-name 时，runner 上
    // 字进了别的颗（focus 竞争），门红；本机绿不代表 CI 绿。
    $pullSeen = [];
    foreach (['in-name', 'in-key', 'in-note', 'in-otp'] as $pid) {
        if (str_contains((string) $app->elementValue($pid), 'g')) {
            $pullSeen[] = $pid;
        }
    }
    // 反向控制：in-vol 是滑块，真键不可能把 'g' 打进它。若 elementValue() 退化成一个全局槽
    // （审核 P0-4 / #55 那种"串字段"），这里就会看见 g ⇒ 门红。
    $pullLeak = str_contains((string) $app->elementValue('in-vol'), 'g');
    $pullBack = $typedIn && $pullSeen !== [] && !$pullLeak;
    step('valuePull', $pullBack, '带 g 的颗=[' . implode(',', $pullSeen) . '] 串槽='
        . var_export($pullLeak, true) . ' elementValue(in-name)='
        . var_export($app->elementValue('in-name'), true));
    $ok = $ok && $pullBack;

    step('keyDownRoute', $keyIdx >= 0, 'key_down 落点索引=' . $keyIdx
        . ' key=' . var_export(ExampleState::$v['key'], true)
        . ' focus=' . $app->focusedId());
    $ok = $ok && $keyIdx >= 0;

    // 5b) D 门：catalog 时间字段的「按下取焦点」。时间字段的段（hour/minute…）有
    //     部件自己的 OnMouseDown→OnSegmentDown 自聚焦，但段间（冒号死区）没有任何
    //     监听器；root 有 PathId（clickFromPath ⇒ 有命中矩形、focusId 挂 st->focus），
    //     窗口通用路径只认 focusOnPress 这一个旗标——修前按死区焦点不动（负对照），
    //     修后迁移到字段实体。探针几何：hitProbe 的 id/path 是数字（clickId/路径哈希），
    //     没有字符串可按 "time-field-" 前缀筛——改用行为定位：段 = OnMouseDown 的矩形
    //     （见下面 down 旗标的说明），同一字段的段共享同一 parent（root 的命中条目）
    //     且同一行；死区 = 相邻两段的水平中点（必须仍落在 parent 矩形内）。实体身份用
    //     「同一死区两次点击得到同一个新 id」+「点段后 id 不变（OnSegmentDown 聚的是
    //     同一颗 handle）」锁，不按位置猜。窗口不 applicable 时同 5c 记未验不判红。
    //     两个实测过的前提，都写下来免得再猜：
    //     · 修前的真实读数不是"焦点不动"而是**清到 0**：点空白会让此前聚焦的输入框
    //       Blur，InputBlur 里 `FocusHandleIsFocused ⇒ WindowSetFocusId(win, 0)`
    //       （base/input.cpp 的 InputBlur）——判据用 focusDead !== 0 排它。
    //     · 段矩形贴着窗底是**确定性**的：retainedRoundtrip 的 `input in-otp 1234`
    //       让 OTP 行变高（实测 523→606、段从 22px 裁到 13px），时间字段整行被推到
    //       窗口下缘。死区 y=612 距底 8px，但命中链取的是**包含该点的最后一条**
    //       矩形（HitTestRect 从表尾扫），root（PathId，有命中矩形）排在整窗
    //       resize 那条之后，点击仍落在 root 的链上——修前清焦、修后聚字段，读数可辨。
    $tfOk = ! $focusApplicable;
    $tfDetail = 'applicable=' . var_export($focusApplicable, true);
    // 段的判据只看 down=1：hitProbe 的 click 旗标量的是 h.listener（shim 回调那路），
    // PathClick 造的 clickId 不进这个旗标；OnMouseDown 才是段独有的（日历格只有
    // OnClick、± 按钮只有 onClick，都不会混进来）。本树里 down=1 的另一类是窗口
    // resize 边那一条——它们不成对、不同 parent，靠「同 parent 同行相邻成对」排除。
    $tfCollectSegs = function () use ($app): array {
        $segs = [];
        $hitsAll = (int) ($app->hitProbe()['n'] ?? 0);
        for ($i = 0; $i < $hitsAll; $i++) {
            $h = $app->hitProbe($i);
            if ((int) $h['down'] === 1 && (int) $h['w'] >= 8 && (int) $h['h'] >= 8) {
                $segs[] = ['i' => $i, 'x' => (int) $h['x'], 'y' => (int) $h['y'],
                    'w' => (int) $h['w'], 'h' => (int) $h['h'], 'p' => (int) $h['parent']];
            }
        }
        return $segs;
    };
    $tfDump = function (array $segs): string {
        $out = '';
        foreach ($segs as $sg) {
            $out .= ($out === '' ? '' : ' ') . $sg['i'] . ':'
                . $sg['x'] . ',' . $sg['y'] . 'x' . $sg['w'] . 'x' . $sg['h']
                . '@p' . $sg['p'];
        }
        return $out;
    };
    $segs = $tfCollectSegs();
    $tfSegDump = $tfDump($segs);
    if ($focusApplicable) {
        // 滚动进视口（2026-10-27 补）：in-note 主题框（+38px）与 in-otp='1234' 的
        // 行加高把时间字段整行推到 620 窗口下缘之外——段矩形被视口裁成 h=0，段表
        // 只剩窗口 resize 那条，门在「未找到相邻段对」上假红（当轮实测）。真人本来
        // 就得滚才看得到这颗字段——这里把页面滚下去（80px 不够再补 60px）把字段带
        // 回视口再探，量完滚回去，后续门的几何原样。
        $tfScroll = 0;
        $tfPair = function (array $segs): bool {
            $byParent = [];
            foreach ($segs as $sg) {
                $byParent[$sg['p'] . '@' . $sg['y']][] = $sg;
            }
            foreach ($byParent as $group) {
                usort($group, static fn (array $a, array $b): int => $a['x'] <=> $b['x']);
                for ($k = 1; $k < count($group); $k++) {
                    $gap = $group[$k]['x']
                        - ($group[$k - 1]['x'] + $group[$k - 1]['w']);
                    if ($gap > 0 && $gap < 24) {
                        return true;
                    }
                }
            }
            return false;
        };
        if (! $tfPair($segs)) {
            foreach ([80, 60] as $dy) {
                $app->realScroll(280, 300, -$dy);
                $app->loop(3);
                $app->loop(5);
                $tfScroll += $dy;
                $segs = $tfCollectSegs();
                $tfSegDump = $tfDump($segs);
                if ($tfPair($segs)) {
                    break;
                }
            }
        }
        // 找同 parent 同行的相邻两段（x 升序，间隙 < 24px）。
        $deadX = -1;
        $deadY = -1;
        $tfRootIdx = -1;
        $byParent = [];
        foreach ($segs as $sg) {
            $byParent[$sg['p'] . '@' . $sg['y']][] = $sg;
        }
        foreach ($byParent as $group) {
            usort($group, static fn (array $a, array $b): int => $a['x'] <=> $b['x']);
            for ($k = 1; $k < count($group); $k++) {
                $left = $group[$k - 1];
                $right = $group[$k];
                $gap = $right['x'] - ($left['x'] + $left['w']);
                if ($gap > 0 && $gap < 24) {
                    $deadX = $left['x'] + $left['w'] + (int) ($gap / 2);
                    $deadY = $left['y'] + (int) ($left['h'] / 2);
                    $tfRootIdx = $left['p'];
                    break 2;
                }
            }
        }
        if ($deadX < 0 || $tfRootIdx < 0) {
            $tfOk = false;
            $tfDetail .= ' 未找到相邻段对（滚' . $tfScroll . 'px 后 down 矩形：'
                . $tfSegDump . '）';
        } else {
            $rootRect = $app->hitProbe($tfRootIdx);
            $inside = $deadX >= (int) $rootRect['x']
                && $deadX <= (int) $rootRect['x'] + (int) $rootRect['w']
                && $deadY >= (int) $rootRect['y']
                && $deadY <= (int) $rootRect['y'] + (int) $rootRect['h'];
            // 基线：先把焦点放到已知实体（in-name）上，死区点击前后的读数才有可比性。
            $ab = $app->inputProbe($typedIdx);
            $app->realClick((int) $ab['x'] + (int) $ab['w'] / 2, (int) $ab['y'] + (int) $ab['h'] / 2);
            $app->loop(2);
            $focusBase = $app->focusedId();
            $app->realClick($deadX, $deadY);
            $app->loop(2);
            $focusDead = $app->focusedId();
            // 身份锁 1：再按一次同一死区，id 必须幂等。
            $app->realClick($deadX, $deadY);
            $app->loop(2);
            $focusDead2 = $app->focusedId();
            // 身份锁 2：点同字段的一段，OnSegmentDown 聚的是同一颗 handle。
            $firstSeg = null;
            foreach ($byParent as $group) {
                usort($group, static fn (array $a, array $b): int => $a['x'] <=> $b['x']);
                foreach ($group as $sg) {
                    if ($sg['p'] === $tfRootIdx) {
                        $firstSeg = $sg;
                        break 2;
                    }
                }
            }
            $focusSeg = -2;
            if ($firstSeg !== null) {
                $app->realClick($firstSeg['x'] + (int) ($firstSeg['w'] / 2),
                    $firstSeg['y'] + (int) ($firstSeg['h'] / 2));
                $app->loop(2);
                $focusSeg = $app->focusedId();
            }
            $tfOk = $inside && $focusDead !== 0 && $focusDead !== $focusBase
                && $focusDead2 === $focusDead && $focusSeg === $focusDead;
            $tfDetail = '死区=' . $deadX . ',' . $deadY . ' 在root内=' . var_export($inside, true)
                . ' 焦点 ' . $focusBase . '→' . $focusDead . '（再按=' . $focusDead2
                . ' 点段=' . $focusSeg . '） 段表[' . $tfSegDump . ']'
                . ($tfScroll > 0 ? '（滚' . $tfScroll . 'px 进视口后探，段表为滚后坐标）' : '');
        }
        if ($tfScroll > 0) {
            // 滚回去：enterResolves / DatePicker 两节的探针按未滚几何取矩形。
            $app->realScroll(280, 300, $tfScroll);
            $app->loop(3);
            $app->loop(5);
        }
    }
    step('timeFieldPressTakesFocus', $tfOk, $tfDetail);
    $ok = $ok && $tfOk;

    // 5c) A 门：回车的动作解析（"enter"→input::Enter）。retained 输入框的 frame
    //     自带 KeyContext("Input")（BindInput 挂的）之后，DispatchAnchor 落 frame
    //     marker、WindowResolveKeyAction 的 [0,ix-1] 扫描才收得到这个 context，
    //     回车才从"裸字符"恢复成动作。两条臂：
    //       主臂 in-note（textarea，多行）：聚焦后 realKey(36,"\r")（36=kVK_Return），
    //         retained 回读口 text('in-note') 必须长 1 字节（换行）；
    //       对照臂 in-name（单行，同一 materialize 分支）：回车不插字，但
    //         PressEnter→change，handler 从 state 读到 setInput 预埋的新值。
    //     窗口 key/active 不 applicable 的机器按 focusFollowsClick 先例记未验不判红。
    $enterOk = ! $focusApplicable;
    $enterDetail = 'applicable=' . var_export($focusApplicable, true)
        . ' noteIdx=' . $noteIdx . ' nameIdx=' . $typedIdx;
    if ($focusApplicable && $noteIdx >= 0 && $typedIdx >= 0) {
        $nb = $app->inputProbe($noteIdx);
        $noteLenBefore = strlen((string) $app->text('in-note'));
        $app->realClick((int) $nb['x'] + (int) $nb['w'] / 2, (int) $nb['y'] + (int) $nb['h'] / 2);
        $app->loop(2);
        $pressedEnter = $app->realKey(36, "\r");
        $app->loop(2);
        $noteLenAfter = strlen((string) $app->text('in-note'));
        // 对照臂：in-name 预埋一个值再回车，change 事件让 handler 从 state 读到它。
        $app->setInput('in-name', 'ent');
        $ab2 = $app->inputProbe($typedIdx);
        $app->realClick((int) $ab2['x'] + (int) $ab2['w'] / 2, (int) $ab2['y'] + (int) $ab2['h'] / 2);
        $app->loop(2);
        $app->realKey(36, "\r");
        $app->loop(2);
        $nameAfter = (string) ExampleState::$v['name'];
        $enterOk = $pressedEnter && $noteLenAfter === $noteLenBefore + 1
            && $nameAfter === 'ent';
        $enterDetail = 'enter=' . var_export($pressedEnter, true)
            . ' note ' . $noteLenBefore . '→' . $noteLenAfter . '（期望 +1）'
            . ' name→' . var_export($nameAfter, true) . "（期望 'ent'）";
    }
    step('enterResolves', $enterOk, $enterDetail);
    $ok = $ok && $enterOk;

    // 6) NumberInput 的 ± 步进（事件 type=step，载荷是字符串）。上游把它做成"点按钮"
    //    而不是键位：`NumberStepButton`（materialize.cpp:960-983）造的是
    //    `Button::New(cx, id, …)`——第二个参数是 **id 不是标签**，样式与内容一律从
    //    `decrement_button`/`increment_button` 那两个装饰槽取（`IsRegisteredCommonSlot`
    //    里就列着这两个名字）。所以送达只能经真点击量。
    //    今天在这条上栽的两个读数，都记在这里：
    //      槽没填 ⇒ 那两条命中是 0x0、像素上也不落字 ⇒ 点不到，`lastStep='—'`。
    //        那不是"没接通"，是"没有可点的东西"——所以当时它只报数不判红。
    //      槽填了 ⇒ 落点索引 4、`lastStep='decrement'`，gpuiEnqueueString 第一次量到带值的送达。
    //    细长/零尺寸矩形一律跳过：命中表里含窗口 resize 边那一类，点它 AppKit 会等下一个
    //    事件而不返回（原画廊的探针栽过一次，实测 rc=124 被看门狗掐掉）。
    ExampleState::$v['lastStep'] = '—';
    $stepIdx = -1;
    $stepTried = [];
    // bug B 的回归位：on_step 一挂，上游内建步进就关了（materialize 里
    // `!behavior.onStep && hasStep ? &amount : nullptr`）——点 ± 只发事件不改数，
    // 值归脚本管。所以 handler 必须自己回写，光量 lastStep/elementValue（那只是
    // lastValues 回执）抓不住「事件到了、屏上数字纹丝不动」这个回归：
    // 这里在点击圈前后各读一次 retained 回读口，断数值真的 ±1 了。
    $numBefore = (int) $app->text('in-num');
    $hits = (int) ($app->hitProbe()['n'] ?? 0);
    for ($i = 0; $i < $hits; $i++) {
        $h = $app->hitProbe($i);
        $hw = (int) $h['w'];
        $hh = (int) $h['h'];
        $stepTried[] = $i . ':' . (int) $h['x'] . ',' . (int) $h['y'] . 'x' . $hw . 'x' . $hh
            . ((int) $h['click'] === 1 ? 'c' : '');
        if ($hw < 12 || $hh < 12) {
            continue;
        }
        $app->realClick((int) $h['x'] + $hw / 2, (int) $h['y'] + $hh / 2);
        $app->loop(3);
        if ((string) ExampleState::$v['lastStep'] !== '—') {
            $stepIdx = $i;
            break;
        }
    }
    $stepVal = (string) ExampleState::$v['lastStep'];
    $numAfter = (int) $app->text('in-num');
    // 判据带的是值本身，不只是"来过"：increment/decrement 是这颗事件唯一的载荷；
    // 数值判据带方向（increment ⇒ +1、decrement ⇒ −1），不是笼统的"变了"。
    $numWant = $stepVal === 'increment' ? $numBefore + 1
        : ($stepVal === 'decrement' ? $numBefore - 1 : $numBefore);
    $stepOk = $stepIdx >= 0
        && ($stepVal === 'increment' || $stepVal === 'decrement')
        && (string) $app->elementValue('in-num') === $stepVal
        && $numAfter === $numWant;
    step('stepDelivered', $stepOk, '命中表=' . $hits . ' 条 [' . implode(' ', $stepTried)
        . '] 落点索引=' . $stepIdx . ' lastStep=' . var_export($stepVal, true)
        . ' elementValue(in-num)=' . var_export($app->elementValue('in-num'), true)
        . ' text(in-num)=' . $numBefore . '→' . $numAfter . '（期望 ' . $numWant . '）');
    $ok = $ok && $stepOk;

    // 到这里页面已经重渲染过多帧（上面逐颗框都点过、每步都 loop 过）：实体表还必须是
    // 同一个数，而且必须正好是那一族的颗数。见 2c) 的说明——这条是 #81/#82 的
    // "每帧漏一颗"防线，颗数不对就说明某一颗根本没建成（而它是静默降级成 Div 的）。
    $scLate = $app->stateCounts();
    $statesLate = (int) ($scLate['componentStates'] ?? -1);
    $want = INPUT_CATALOG_STATES;
    $reuse = $statesEarly === $want && $statesLate === $want;
    step('catalogStateReuse', $reuse, 'componentStates 首帧后=' . $statesEarly
        . ' 多帧后=' . $statesLate . '（页面上 catalog 表单族 ' . $want . ' 颗）');
    $ok = $ok && $reuse;

    // 反向对照（这条门自己会不会是假绿）：把 state 的 kind 换成不存在的名字重渲染一次，
    // 失败必须**看得见**——bridge 下发 NIL 而不是 handle 0（0 在上游是一颗合法实体，
    // 可能属于别的组件），shim 的 Entity 支于是拒绝这颗实参，组件渲染成错误节点，
    // registeredError() 报出原因。放在最后跑：它会污染 registeredError() 与
    // componentFailure()，而那两条在前面已经判过了。
    $app->render(El::vFlex([
        El::catalog('Input', [El::state('NoSuchState', 'in-bad', [])]),
    ])->sizeFull());
    $app->loop(2);
    $neg = $app->registeredError();
    step('catalogStateFailureVisible', $neg !== '', '错 kind 时读回='
        . var_export($neg, true) . '（非空=看得见）');
    $ok = $ok && $neg !== '';

    // 8) E 日期弹层：合成门 + 读数。结论史（读数与证据都在本轮 2026-10-27 复量过）：
    //      · **模型链通**：OnCalendar 选中必然 open=false（ui/time.cpp:1090-1092），
    //        下一帧 if(state->open) 不再挂弹层，命中表键差精确退回关态——修前修后
    //        都如此，本门把它钉成 rc 级回归位（下面 datePickerPopupLifecycle）。
    //      · **真人路径 2026-10-25 报的「选中后弹层不收、复开新旧两颗叠加」**：本轮
    //        用 realClick（背靠背 down/up）、分半 down→泵 6 帧→up（按住带帧）、
    //        hover 先行、1px 手抖、滚动后再点五种合成变体各量一遍，模型/命中/像素
    //        三层全部当帧收干净（残留键差=0、弹层区像素与关态一致），无一种能复现
    //        「不收」或「叠加」；realClick 与真人虽同为 NSEvent，但真人的事件带
    //        WindowServer 投递时序与本会话不可造（agent 进程无 GUI session，#96）。
    //        真正修掉的可复现缺陷是 **contentReady 死帧**（cmd/patch-popup-same-frame.sh）：
    //        开弹层那一帧命中表里没有任何弹层矩形，真人先于第二帧的一击会落到底下的
    //        页面——input 示例弹层正下方就是 in-ccal 裸日历，它吃下误点后选中态停在
    //        被点的日子上，与「下方弹层高亮 6、字段 2026/10/23 对不上」的截图吻合；
    //        「弹层不收起」的主体若是它，观感上就是弹层区总有个日历面板。死帧砍掉后
    //        弹层可见的那一帧命中面就在（下面 mount1 门钉住）。
    //      · **命中错位（点 A 选 B）**依旧存在（本轮未动，见旧注释与报告）：错位让
    //        绝对坐标不可信，所以这里的判据全部取「键差/条数」这类错位不敏感读数。
    //      · **真人路径 2026-10-27 报的「一进来弹层就展开、点日历图标收不起来」**：
    //        五条合成变体（含滚屏后、慢按、图标落点）全部当帧开合、初始帧命中表里
    //        没有任何弹层矩形——contentReady 同帧直通改不出「初始就挂载」（弹层挂载
    //        由 state->open 驱动、初始 false，Content(nullptr) 在 Popup::Content
    //        首行短路），StopMouseDown 也不挡图标（弹层 AnchorBelow 放不下翻转到
    //        字段上方，不盖触发器）。可复现的本体是 **in-ccal 常开裸日历**：字段
    //        贴着 620 窗底缘，真人滚一屏它就贴在字段正下方进视口，观感=「弹层已
    //        展开」，且它是独立组件、点图标永远收不掉——与上一轮「下方弹层高亮 6」
    //        同源。摘除办法在 inputs.php（字段与裸日历之间加名分行）；可合成的部分
    //        由下面 datePickerInitialClosed 钉死（初始收起 + 图标开合）。
    //    前置恢复（实测必要）：4e 的 setTheme('dark') 在原生侧持久，boot 重置 PHP
    //    state 不还原它；retainedRoundtrip 给 in-otp 塞的 '1234' 让 OTP 行竖排变高
    //    ——两者叠加会把触发器推出窗外，realClick 落到窗外、弹层根本开不了。这里先
    //    还原 light、清空 OTP 实体值。
    ExampleState::boot(['section' => 2]);
    $app->setTheme('light');
    $app->setInput('in-otp', '');
    $app->render(inputTree());
    $app->loop(3);
    $dpDetail = '';
    $dpOk = true;
    $triggerIdx = -1;
    $n0 = (int) ($app->hitProbe()['n'] ?? 0);
    for ($i = 0; $i < $n0; $i++) {
        $h = $app->hitProbe($i);
        if ((int) $h['click'] === 1 && (int) $h['w'] >= 400) {
            $triggerIdx = $i;
        }
    }
    if ($triggerIdx < 0) {
        $dpOk = false;
        $dpDetail = '未找到全宽触发器（click 矩形 ' . $n0 . ' 条）';
        step('datePickerSyntheticReadout', false, $dpDetail);
        $ok = $ok && false;
    } else {
        $trig = $app->hitProbe($triggerIdx);
        $trigX = (int) $trig['x'] + (int) ((int) $trig['w'] / 2);
        $trigY = (int) $trig['y'] + (int) ((int) $trig['h'] / 2);

        // ── rc 门：初始收起 + 点【图标】开、再点收（2026-10-27 复验回归位）────
        // 复验报「一进来弹层就展开、点日历图标收不起来」。合成五变体（realClick
        // 背靠背 / 按下泵 5 帧再抬 / 悬停先行 / 滚屏后 / 触发器中点）全部当帧开合
        // ——弹层挂载由 state->open 驱动（ui/time.cpp RetainedDatePickerIntoEl 的
        // if (state->open)），初始 false，Content(nullptr) 在 Popup::Content 首行
        // 短路，contentReady 同帧直通改不出「初始就挂载」；点图标也不被弹层的
        // StopMouseDown 挡（弹层 AnchorBelow 翻转到字段上方，不盖触发器）。真人
        // 看到的「字段下方那本收不起来的日历」是 in-ccal——常开裸日历，已给它加
        // 名分行（inputs.php），合成从不滚动所以此前三条门全部量不到。本门钉三件
        // 可合成的事：① 初始帧弹层不挂载；② 点【图标】（触发器右端，合成惯用的
        // 中点之外的真人落点）开；③ 再点图标收。面板识别：命中表里 ≥200x100、
        // y>0 的矩形——关态全表唯一的大矩形是整窗（y=0），开态新增弹层面板
        // （实测 16,323x250x252）。
        $panelRects = function () use ($app): array {
            $n = (int) ($app->hitProbe()['n'] ?? 0);
            $found = [];
            for ($i = 0; $i < $n; $i++) {
                $h = $app->hitProbe($i);
                if ((int) $h['w'] >= 200 && (int) $h['h'] >= 100 && (int) $h['y'] > 0) {
                    $found[] = (int) $h['x'] . ',' . (int) $h['y']
                        . 'x' . (int) $h['w'] . 'x' . (int) $h['h'];
                }
            }
            return $found;
        };
        $iconX = (int) $trig['x'] + (int) $trig['w'] - 14; // padX=10 + 图标半宽
        $iconY = (int) $trig['y'] + (int) ((int) $trig['h'] / 2);
        $icPanels0 = $panelRects();
        $app->realMouseMove($iconX, $iconY);
        $app->loop(2);
        $app->realClick($iconX, $iconY);
        $app->loop(3);
        $app->loop(5);
        $icPanels1 = $panelRects();
        $app->realMouseMove($iconX, $iconY);
        $app->loop(2);
        $app->realClick($iconX, $iconY);
        $app->loop(3);
        $app->loop(5);
        $icPanels2 = $panelRects();
        $icOk = $icPanels0 === [] && $icPanels1 !== [] && $icPanels2 === [];
        step('datePickerInitialClosed', $icOk,
            '初始面板矩形=' . ($icPanels0 === [] ? '空（收起）' : implode(' ', $icPanels0))
            . ' 图标(' . $iconX . ',' . $iconY . ')点后='
            . ($icPanels1 === [] ? '无（没开）' : implode(' ', $icPanels1))
            . ' 再点后=' . ($icPanels2 === [] ? '空（收起）' : implode(' ', $icPanels2))
            . '（「字段下方的日历」是 in-ccal 裸日历，见 inputs.php 名分行；'
            . '弹层开在本字段上方，AnchorBelow 放不下会翻转）');
        $ok = $ok && $icOk;

        $rcBase = $app->renderCount();
        $shotBefore = sys_get_temp_dir() . '/input-dp-before.png';
        $shotOpen = sys_get_temp_dir() . '/input-dp-open.png';
        $shotPicked = sys_get_temp_dir() . '/input-dp-picked.png';
        $shotReopen = sys_get_temp_dir() . '/input-dp-reopen.png';
        $app->snapshot($shotBefore, INPUT_W, INPUT_H);
        // 关态命中键集：后面每一段都拿「相对这张表的键差」当弹层在不在的判据——
        // 错位缺陷让绝对坐标不可信，但"开弹层新增哪些键 / 收弹层退掉哪些键"是
        // 结构读数，不随错位变。
        $baseKeys = [];
        for ($i = 0; $i < $n0; $i++) {
            $h = $app->hitProbe($i);
            $baseKeys[(int) $h['x'] . ',' . (int) $h['y'] . ',' . (int) $h['w']
                . ',' . (int) $h['h'] . ',' . (int) $h['click']] = true;
        }
        /** 相对关态键集的键差：返回 [新增键数, 其中日格数, 新键集]。 */
        $dpDiff = function () use ($app, $baseKeys): array {
            $n = (int) ($app->hitProbe()['n'] ?? 0);
            $cnt = 0;
            $cells = 0;
            $keys = [];
            for ($i = 0; $i < $n; $i++) {
                $h = $app->hitProbe($i);
                $key = (int) $h['x'] . ',' . (int) $h['y'] . ',' . (int) $h['w']
                    . ',' . (int) $h['h'] . ',' . (int) $h['click'];
                if (isset($baseKeys[$key])) {
                    continue;
                }
                $cnt++;
                $keys[$key] = true;
                // 日格 ≈ 24..40 的方形；月份/年切换钮 71x32 会被滤掉（第一版探针
                // 点到过它，月份翻页而不是选中），日格数里混进的 32x32 是 prev/next。
                if ((int) $h['click'] === 1 && (int) $h['w'] >= 24 && (int) $h['w'] <= 40
                    && (int) $h['h'] >= 20 && (int) $h['h'] <= 40) {
                    $cells++;
                }
            }
            return [$cnt, $cells, $keys];
        };
        $clickCell = function () use ($app, $dpDiff, $baseKeys): void {
            [, , $keys] = $dpDiff();
            $n = (int) ($app->hitProbe()['n'] ?? 0);
            $cells = [];
            for ($i = 0; $i < $n; $i++) {
                $h = $app->hitProbe($i);
                $key = (int) $h['x'] . ',' . (int) $h['y'] . ',' . (int) $h['w']
                    . ',' . (int) $h['h'] . ',' . (int) $h['click'];
                if (isset($baseKeys[$key]) || !isset($keys[$key])) {
                    continue;
                }
                if ((int) $h['click'] === 1 && (int) $h['w'] >= 24 && (int) $h['w'] <= 40
                    && (int) $h['h'] >= 20 && (int) $h['h'] <= 40) {
                    $cells[] = [(int) $h['x'] + (int) ((int) $h['w'] / 2),
                        (int) $h['y'] + (int) ((int) $h['h'] / 2)];
                }
            }
            if ($cells !== []) {
                // 点网格中段：首格是上个月尾的禁用日（点了没反应），中段必是本月
                // 可点日。错位已知存在（点哪选哪差一格），门只断「选中→收起」。
                $c = $cells[intdiv(count($cells), 2)];
                $app->realClick($c[0], $c[1]);
            }
        };

        // ── 读数步：开→选→复开一遍，像素差 + renderCount 账目（不判 rc）──────
        $app->realClick($trigX, $trigY);
        $app->loop(3);
        $app->loop(5);
        $rcOpen = $app->renderCount();
        $n1 = (int) ($app->hitProbe()['n'] ?? 0);
        [$openCnt, $openCells, $openKeys] = $dpDiff();
        $cellXs = [];
        $cellYs = [];
        $surface = null;
        $nn = (int) ($app->hitProbe()['n'] ?? 0);
        for ($i = 0; $i < $nn; $i++) {
            $h = $app->hitProbe($i);
            $key = (int) $h['x'] . ',' . (int) $h['y'] . ',' . (int) $h['w']
                . ',' . (int) $h['h'] . ',' . (int) $h['click'];
            if (isset($baseKeys[$key]) || !isset($openKeys[$key])) {
                continue;
            }
            if ((int) $h['click'] === 1 && (int) $h['w'] >= 24 && (int) $h['w'] <= 40
                && (int) $h['h'] >= 20 && (int) $h['h'] <= 40) {
                $cellXs[(int) $h['x']] = true;
                $cellYs[(int) $h['y']] = true;
            }
            if ((int) $h['click'] === 0 && (int) $h['down'] === 0
                && (int) $h['w'] >= 200 && (int) $h['h'] >= 200) {
                $surface = [(int) $h['x'], (int) $h['y'], (int) $h['w'], (int) $h['h']];
            }
        }
        $app->snapshot($shotOpen, INPUT_W, INPUT_H);
        $openDiffers = is_file($shotBefore) && is_file($shotOpen)
            && md5_file($shotBefore) !== md5_file($shotOpen);
        $rcPicked = -1;
        $pickedDiffers = false;
        $rcReopen = -1;
        $reopenDiffers = false;
        $readN = [0, 0, 0, 0];
        if ($openDiffers && $openCells > 0) {
            $readN = [$n0, $n1, 0, 0];
            $clickCell();
            $app->loop(5);
            $rcPicked = $app->renderCount();
            [$pickedCnt, $pickedCells] = $dpDiff();
            $readN[2] = (int) ($app->hitProbe()['n'] ?? 0);
            $app->snapshot($shotPicked, INPUT_W, INPUT_H);
            $pickedDiffers = md5_file($shotOpen) !== md5_file($shotPicked);
            // 「选中落账」不走 debug_tree：标题在物化树里（spec 树无值可读），shim
            // 也没有 DatePicker 的值回读口。像素证据由 pickedDiffers（弹层关 +
            // 触发行换字）承担；键差退零由 lifecycle 门判 rc。
            $app->realClick($trigX, $trigY);
            $app->loop(3);
            $app->loop(5);
            $rcReopen = $app->renderCount();
            [$reopenCnt, $reopenCells] = $dpDiff();
            $readN[3] = (int) ($app->hitProbe()['n'] ?? 0);
            $app->snapshot($shotReopen, INPUT_W, INPUT_H);
            $reopenDiffers = md5_file($shotPicked) !== md5_file($shotReopen);
            $dpDetail = 'surface=' . ($surface === null ? '无' : implode(',', $surface))
                . ' 开弹层像素变了=' . var_export($openDiffers, true)
                . ' 选中后像素变了=' . var_export($pickedDiffers, true)
                . ' 复开后像素变了=' . var_export($reopenDiffers, true)
                . ' renderCount 基线/开/选/复开=' . $rcBase . '/' . $rcOpen . '/'
                . $rcPicked . '/' . $rcReopen
                . ' 命中条数 关/开/选后/复开=' . implode('/', $readN)
                . ' 开新增键=' . $openCnt . '（日格=' . $openCells . ' 颗 '
                . count($cellXs) . 'x' . count($cellYs) . '）'
                . ' 选后残留键=' . $pickedCnt . '（日格=' . $pickedCells . ' 颗）'
                . ' 复开新增键=' . $reopenCnt . '（日格=' . $reopenCells . ' 颗）'
                . '（错位缺陷未修，见注释；真人差异留验收：真机点选后弹层当帧收起、'
                . '复开高亮=字段值，连续三次）';
            step('datePickerSyntheticReadout', true, $dpDetail . '（读数步，不判 rc）');
        } else {
            step('datePickerSyntheticReadout', false,
                '开弹层失败：openDiffers=' . var_export($openDiffers, true)
                . ' 开新增键=' . $openCnt . '（像素/键差两路都量不到弹层）');
            $ok = $ok && false;
        }

        // ── rc 门：三轮 开→选(当帧收)→复开(不叠加)→点外收，轮轮一致 ──────────
        // 前置：读数步停在「复开」态，先把弹层收掉（点窗外 OnDismiss），门从关态起跑。
        \gpui_real_mouse($app->handle(), 8, 8, 2);
        $app->loop(2);
        \gpui_real_mouse($app->handle(), 8, 8, 3);
        $app->loop(5);
        $lcDetail = '';
        $lcOk = true;
        $cycles = [];
        for ($cycle = 1; $cycle <= 3 && $lcOk; $cycle++) {
            $app->realClick($trigX, $trigY);
            $app->loop(2);
            [$mountCnt, $mountCells, $mountKeys] = $dpDiff();
            if ($mountCnt <= 0 || $mountCells <= 0) {
                $lcOk = false;
                $lcDetail .= " 第{$cycle}轮开弹层没挂上（键差={$mountCnt}）";
                break;
            }
            $clickCell();
            $app->loop(5);
            [$closeCnt, $closeCells] = $dpDiff();
            if ($closeCnt !== 0 || $closeCells !== 0) {
                $lcOk = false;
                $lcDetail .= " 第{$cycle}轮选中后残留键差={$closeCnt}/日格={$closeCells}";
                break;
            }
            $app->realClick($trigX, $trigY);
            $app->loop(2);
            [$reopenCnt, $reopenCells, $reopenKeys] = $dpDiff();
            if ($reopenCnt !== $mountCnt || $reopenKeys != $mountKeys) {
                $lcOk = false;
                $lcDetail .= " 第{$cycle}轮复开键差={$reopenCnt}/首开={$mountCnt}"
                    . '（键集异=' . var_export($reopenKeys != $mountKeys, true) . '）';
                break;
            }
            $cycles[] = $mountCnt . '/' . $mountCells;
            // 点窗外收起（OnMouseUpOut→OnDismiss），回到关态进下一轮。
            \gpui_real_mouse($app->handle(), 8, 8, 2);
            $app->loop(2);
            \gpui_real_mouse($app->handle(), 8, 8, 3);
            $app->loop(5);
            [, $afterCnt] = $dpDiff();
            if ($afterCnt !== 0) {
                $lcOk = false;
                $lcDetail .= " 第{$cycle}轮点外未收（键差={$afterCnt}）";
                break;
            }
        }
        $lcOk = $lcOk && count($cycles) === 3;
        step('datePickerPopupLifecycle', $lcOk,
            '三轮 开键差/日格=' . implode(' ', $cycles)
            . ' 全部当帧收起、复开键集与首开相同、点外即收'
            // 真人路径差异读数（2026-10-27 复验的「点图标收不起来」）：本门与
            // datePickerSyntheticReadout 的合成会话从不滚动，而真人在 560x620 的窗里
            // 要看清贴着窗底缘的字段必先滚——滚一屏后 in-ccal 常开裸日历就贴在
            // 字段正下方进视口，观感=「弹层已展开」，且它不是弹层、点图标永远收不掉
            // （弹层本体开在字段上方，图标开合由 datePickerInitialClosed 钉死）。
            // 误读的屏上根因已在 inputs.php 摘除（名分行隔开字段与裸日历）。
            . '（真人差异：合成不滚屏，量不到 in-ccal 裸日历贴字段下方那本；见 inputs.php 名分行）'
            . ($lcOk ? '' : '；' . $lcDetail));

        // ── T 门：kind 版 textarea 的主题边框（cmd/patch-shell-textarea-frame.sh）
        //    判据：inputProbe 记的是**最外层 BindInput**的框（gpui.cpp:7811 起的
        //    "outermost binding wins"），补丁给 textarea frame 加了 PadY(8)x2 +
        //    Border(1)x2 ⇒ in-note 的框高 = 行高 + 18；补丁前是 20（裸列）。单行
        //    in-name 不在本补丁范围（裸框契约），只作同页参照打出。聚焦态 ring 翻色
        //    是像素层，无头门断不住，留真人验收（点进 in-note 看边框变 ring 色）。
        $tfOk = false;
        $tfDetail = 'noteIdx=' . $noteIdx . ' nameIdx=' . $typedIdx;
        if ($noteIdx >= 0) {
            $nb = $app->inputProbe($noteIdx);
            $nh = (int) $nb['h'];
            $nw = (int) $nb['w'];
            $nhName = $typedIdx >= 0 ? (int) $app->inputProbe($typedIdx)['h'] : -1;
            // 行高 20（1.25rem）+ padY 16 + border 2 = 38；留主题行高漂移的余量。
            $tfOk = $nh >= 30 && $nw >= 400;
            $tfDetail .= ' in-note框=' . (int) $nb['x'] . ',' . (int) $nb['y']
                . 'x' . $nw . 'x' . $nh . '（补丁前实测 20 高）'
                . ' in-name框高=' . $nhName . '（裸框参照）';
        }
        step('textareaThemedFrame', $tfOk, $tfDetail);
        $ok = $ok && $tfOk;
    }
    $app->close();
    echo ($ok ? 'INPUT SELFTEST OK' : 'INPUT SELFTEST FAIL') . "\n";
    exit($ok ? 0 : 1);
}
