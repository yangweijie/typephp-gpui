<?php

declare(strict_types=1);

/**
 * examples/showcase — 把 PHP 面的**全部**特性走一遍的示例 app。
 *
 * 与 hello 的分工：hello 是"能跑"的最小闭环 + 六道门禁的宿主（selftest/shot 树都长在那儿）；
 * showcase 是"能看全"的目录——13 节，每节把一组组件/能力真用起来。
 *
 * 完整性不靠自觉：tests/ShowcaseTest.php 反射 src/Element.php 与 src/App.php 的公开 API，
 * 逐个断言在本目录两份源码里出现过（注释剥掉后再匹配，所以"写在注释里"不算数）。
 *
 * 模式：
 *   （无参数）            交互：左侧节按钮，右侧当前节；定时器驱动计数器
 *   --selftest           逐节渲染 + 断言 registeredError() 为空 + 真调每个 App 能力
 *   --shot <path> [tree] 出一节的 PNG（tree=节号，0=第 1 节），喂 shotdiff
 *   --sections           打印节清单（脚本用来枚举基线树号）
 */

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;

// 不写 require_once：nano 模式不支持 include（tpc 直接 fatal）。sections.php 与 main.php
// 都在 project.macos.yml 的 sources 里，由 tpc 一起 compose 进同一个二进制。

function main(int $argc, array $argv): void
{
    // nano 挂了只印 "Unhandled TypePHP exception"，不带消息也不带栈（rc=1）。示例的
    // --selftest/--shot 要靠这条把异常类名/消息/位置打出来，否则每个错都得重编二分。
    try {
        showcaseDispatch($argv);
    } catch (\Throwable $e) {
        echo 'SHOWCASE EXCEPTION ' . get_class($e) . ': ' . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
        exit(3);
    }
}

function showcaseDispatch(array $argv): void
{
    if (in_array('--sections', $argv, true)) {
        foreach (showcaseSections() as $tree => [$title, $_fn]) {
            echo $tree . "\t" . $title . "\n";
        }
        return;
    }
    if (in_array('--selftest', $argv, true)) {
        // --expect-bundle 由 `bin/gpui selftest` 附上：nano 里 getenv() 直接编译不过
        // （"Function `getenv` is not supported in nano mode"），所以这个信号只能走 argv。
        runSelfTest(in_array('--expect-bundle', $argv, true),
            in_array('--startfront', $argv, true));
        return;
    }
    if (in_array('--livetyping', $argv, true)) {
        runLiveType();
        return;
    }
    if (in_array('--liveclick', $argv, true)) {
        runLiveClick();
        return;
    }
    if (in_array('--escape', $argv, true)) {
        runEscapeProbe();
        return;
    }
    if (in_array('--probe-events', $argv, true)) {
        runEventProbe();
        return;
    }
    $keyIdx = array_search('--wait-key', $argv, true);
    if ($keyIdx !== false) {
        $secs = isset($argv[$keyIdx + 1]) ? max(5, min(600, (int) $argv[$keyIdx + 1])) : 60;
        runWaitKey($secs);
        return;
    }
    if (in_array('--trace', $argv, true)) {
        // trace 必须作为 boot() 的 over 传进去：boot() 整表重置，先 boot 再置位会被冲掉
        // （第一版就是这么写的，结果 --trace 一条都没落盘，探针误以为"真输入没到"）。
        runInteractive(true);
        return;
    }
    $shotIdx = array_search('--shot', $argv, true);
    if ($shotIdx !== false) {
        $path = isset($argv[$shotIdx + 1]) ? (string) $argv[$shotIdx + 1] : '/tmp/showcase.png';
        $tree = isset($argv[$shotIdx + 2]) ? (int) $argv[$shotIdx + 2] : 1;
        runShot($path, $tree);
        return;
    }
    // 未知开关不能静默落到交互模式：实测 `showcase --debug` 会悄悄起一个阻塞的 GUI，
    // 看上去就像"命令卡住了/没反应"。只查 `--` 前缀的项——`--shot` 的路径与节号是裸参数。
    $known = ['--sections', '--selftest', '--expect-bundle', '--startfront',
        '--livetyping', '--liveclick', '--escape', '--probe-events', '--wait-key',
        '--trace', '--shot'];
    foreach ($argv as $a) {
        $s = (string) $a;
        if (\strncmp($s, '--', 2) === 0 && !in_array($s, $known, true)) {
            echo "未知开关: {$s}\n"
                . "用法: showcase [--sections | --selftest [--expect-bundle] [--startfront]"
                . " | --liveclick | --livetyping | --escape | --probe-events"
                . " | --trace | --shot <路径> <节号>]\n";
            exit(2);
        }
    }
    runInteractive();
}

/**
 * --liveclick：模拟用户真实处境——app 已经跑了一串活帧（每帧都在重建 view），然后再点。
 *
 * 为什么单独一条模式：selftest 的点击发生在"渲染一次、立刻点"的畸形时序里，而交互 app
 * 每帧重建 view。上一轮 --selftest 里出现 `afterRender=false,false / synthetic=true /
 * hits=18→18`（监听器在、命中表也在，就是点不进去），但它是在连续 25 次点击把侧栏滚起来
 * 之后测到的，不能拿来推断用户。这条模式只推进帧数、不做别的动作，量的就是
 * "跑了 N 帧之后真点击还进不进得来"。
 */
/**
 * --escape：#62 的**原始场景**单独隔离——进程里第一个 dialog、纯文本、不去点它，直接按 Escape。
 *
 * 为什么要单独一条：selftest 里那段现在测的是"另一个 dialog 周期之后"的状态，
 * 而且反向对照（把候选修法 FocusTrapApplyPending 摘掉）在 selftest 上照样绿
 * ——那条断言不覆盖这个 bug。真正的读数得靠这一格。
 */
function runEscapeProbe(): void
{
    ExampleState::boot();
    $app = new App();
    wireShowcase($app);
    $app->create();
    $app->createWindow('Showcase Escape', 1180, 760);
    $app->keepTryingForeground(12);
    // 与交互模式同一形状：每帧重建 view，Escape 面对的是用户面前的节奏。
    $app->view(static function () use ($app): El { return interactiveFrame($app); });
    $app->loop(6);
    $opened = $app->openDialog(El::text('进程里第一个框，纯文本')->p(10.0));
    $trap = $app->focusTrap();
    $app->loop(10);
    // 关键分岔：陷阱是"永远不生效"还是"要几帧后才生效"。前者说明 arm/apply 那条链
    // 在我们宿主里根本没走通（FocusTrapArm 只在 DrawFrame 的 EntityRender 里被调用，
    // 而 apply 的条件是 win->focusEls 里有 popup 那颗），后者只是时序。
    $trapAfterFrames = $app->focusTrap();
    $app->realKey(53, '');                // kVK_Escape
    $app->loop(8);
    $closed = $app->dialogCount() === 0;
    echo 'ESCAPE-FIRST opened=' . $opened . ' trapAfterOpen=' . $trap
        . ' trapAfterFrames=' . $trapAfterFrames
        . ' focus=' . $app->focusedId() . ' closed=' . var_export($closed, true) . "\n";
    exit($closed ? 0 : 1);
}

/// 部件级事件的独立送达探针（`--probe-events`）。
///
/// 要量的是这一族：open_change / confirm / dismiss / step / resize。它们的 callbackId
/// 存在部件自己的 binding 里（ShellSelectBinding 等），由部件在合适时刻调
/// runtime->Dispatch*() 发出，而打过补丁的 ShellRuntime::Dispatch 开头就有 shim 那条路
/// （entry->shimId 非零 ⇒ gpuiEnqueueShimCallback 入本窗口队列，完全不碰 QuickJS），
/// 所以**不需要新补丁**就该到 PHP——这一族此前一次都没量过。
///
/// 为什么要独立模式而不是塞进 selftest：见 runSelfTest 里那段注释——放在中途会点开
/// 弹层把 $dirtyGate 弄红，放到最后又没有投递余量。这里给它的是一次干净的前台申请 +
/// 一整段只干这件事的时间。
///
/// 已知的坑（都踩过）：按命中表逐个点中心会整轮挂死（命中表含窗口 resize 边那一类
/// 矩形，点它 AppKit 的 _mouseHysteresisCheck: 会等下一个事件而不返回），所以这里用网格。
function runEventProbe(): void
{
    ExampleState::boot();
    $app = new App();
    wireShowcase($app);
    $app->create();
    $app->createWindow('Showcase EventProbe', 1180, 760);
    $app->keepTryingForeground(12);
    ExampleState::$v['section'] = 3;
    $app->view(static function () use ($app): El { return interactiveFrame($app); });
    $app->loop(8);
    $posted = 0;
    $probed = [];
    foreach (['open_change', 'confirm', 'dismiss', 'step', 'resize'] as $t) {
        $probed[$t] = 0;
    }
    for ($y = 150; $y <= 620 && $probed['open_change'] === 0; $y += 16) {
        for ($x = 230; $x <= 760 && $probed['open_change'] === 0; $x += 40) {
            if ($app->realClick($x, $y)) {
                $posted++;
            }
            $app->loop(2);
            foreach ($probed as $t => $n) {
                $probed[$t] = max($n, evCount($t));
            }
        }
    }
    // 真点击之后再补一条不依赖输入的路：合成 ShellAction 派发。
    // Select 把 action::Confirm()/Cancel() 绑在 Listen(cx, &ScriptView::OnSelectAction, binding) 上。
    $confirmPosted = $app->dispatchAction('ui::Confirm');
    $app->loop(6);
    $confirmAfterAction = evCount('confirm');
    $cancelPosted = $app->dispatchAction('ui::Cancel');
    $app->loop(6);
    $dismissAfterAction = evCount('dismiss');
    // 顺带量一件更有用的事：内建动作能不能当"不用键盘的取消键"。
    // 上游把 escape 绑在 dialog 的焦点陷阱上（#62 读到的形状），而 OnAction(Cancel)
    // 是同一条动作路——派发命中的话框就该关，且这件事完全不依赖真按键送达。
    $app->openDialog(El::text('探针框：派发 ui::Cancel 该关掉我')->p(10.0));
    $app->loop(10);
    $dialogTrap = $app->focusTrap();
    $cancelOnDialog = $app->dispatchAction('ui::Cancel');
    $app->loop(8);
    $closedByAction = $dialogTrap !== 0 && $app->dialogCount() === 0;
    $st = $app->windowState();
    echo 'EVENT-PROBE posted=' . $posted
        . ' open=' . $probed['open_change']
        . ' confirm=' . $probed['confirm']
        . ' dismiss=' . $probed['dismiss']
        . ' step=' . $probed['step']
        . ' resize=' . $probed['resize']
        . ' | action confirmPosted=' . var_export($confirmPosted, true)
        . ' confirm=' . $confirmAfterAction
        . ' cancelPosted=' . var_export($cancelPosted, true)
        . ' dismiss=' . $dismissAfterAction
        . ' | dialog trap=' . $dialogTrap
        . ' cancelPosted=' . var_export($cancelOnDialog, true)
        . ' closedByAction=' . var_export($closedByAction, true)
        . ' | active=' . var_export($st['active'] ?? null, true)
        . ' key=' . var_export($st['key'] ?? null, true)
        . ' bundled=' . var_export($st['bundled'] ?? null, true)
        . ' evSeen=' . evSeenSummary() . "\n";
    // 退出码只表示"至少有一条到没到"，其余留给读数：这一族还没量通之前，
    // 不能让它当门（#62 的教训：门的红必须对应得上事）。
    $anyArrived = array_sum($probed) + $confirmAfterAction + $dismissAfterAction
        + ($closedByAction ? 1 : 0) > 0;
    exit($anyArrived ? 0 : 1);
}

/**
 * `--wait-key [秒]`：把"等真人手按一下"这件事做成一条有判决的命令。
 *
 * 为什么要它：#56（真 OS 按键下 key_down 到不到 PHP）与 #62 的后半（Escape 关框）
 * 卡在同一个前提上——窗口得是 key window，而前台归属是跟 IDE/终端**竞争**来的，
 * 自动化拿不到（电脑操控那轮量过：AX 点击能产生 click 事件，却不改变激活归属）。
 * 既然只有人能给它，就别让人去猜"我这一下到没到"：屏幕上写清楚要按什么，
 * 到了就把读数打出来并以退出码给结论，人不需要去翻日志。
 */
function runWaitKey(int $seconds): void
{
    ExampleState::boot();
    $app = new App();
    wireShowcase($app);
    $app->create();
    $app->createWindow('Showcase WaitKey', 900, 520);
    $app->keepTryingForeground(20);
    $app->view(static function () use ($app, $seconds): El {
        $kd = evCount('key_down');
        $ku = evCount('key_up');
        $cl = evCount('click');
        $st = $app->windowState();
        return El::vFlex([
            El::text('键盘人工验收：请先点一下这扇窗口（把它带到前台），再按几个字母键，然后按 Escape')->font(18.0)->semibold()->p(16.0),
            El::text('按键 key_down=' . $kd . ' · key_up=' . $ku . ' · 点击 click=' . $cl)->font(16.0)->px(16.0),
            El::text('active=' . var_export($st['active'] ?? null, true)
                . ' key=' . var_export($st['key'] ?? null, true)
                . ' 焦点 id=' . $app->focusedId()
                . ' 陷阱 id=' . $app->focusTrap())->font(13.0)->px(16.0)->pt(8.0),
            El::text('窗口会在按到键或计时结束后自己退出；结论也写在终端里。')->font(12.0)->px(16.0)->pt(8.0),
        ])->sizeFull()->gap(6.0);
    });
    $deadline = microtime(true) + $seconds;
    $frames = 0;
    $sawKey = false;
    while (microtime(true) < $deadline) {
        $app->loop(1);
        $frames++;
        if (evCount('key_down') > 0 || evCount('key_up') > 0) {
            $sawKey = true;
            // 按到了再多收几帧，让 Escape / 连续按键都进读数，再多等一会儿给人时间。
            $deadline = min($deadline, microtime(true) + 8);
        }
    }
    $kd = evCount('key_down');
    $ku = evCount('key_up');
    echo 'WAIT-KEY seconds=' . $seconds . ' frames=' . $frames
        . ' key_down=' . $kd . ' key_up=' . $ku
        . ' click=' . evCount('click') . ' change=' . evCount('change')
        . ' focus=' . $app->focusedId()
        . ' seen=' . ($sawKey ? 'yes' : 'no')
        . ' evSeen=' . evSeenSummary() . "\n";
    exit($sawKey ? 0 : 1);
}

function runLiveClick(): void
{
    ExampleState::boot();
    $app = new App();
    wireShowcase($app);
    $app->create();
    $app->createWindow('Showcase LiveClick', 1180, 760);
    // 前台是"和当时握着焦点的一方竞争"来的，一次申请未必赢：让框架在头 12 帧里
    // 没拿到就再申请一次，12 帧之后收手——用户可能正在别的 app 里打字，
    // 一直抢焦点比"点第一下没反应"更糟。
    $app->keepTryingForeground(12);
    // 与交互模式同一颗每帧回调（每帧重建 view + paint 途中抢前台），不是只画 shell 的简化版：
    // 用户面前的节奏里就有那一步，探针必须跑在同一形状上。
    $app->view(static function () use ($app): El { return interactiveFrame($app); });

    $rows = [];
    $firstFrameSent = -1;
    foreach ([1, 2, 5, 20, 60] as $frames) {
        for ($i = 0; $i < $frames; $i++) {
            $app->loop(1);
        }
        ExampleState::$v['evSeen']['click'] = [];
        $sent = 0;
        for ($y = 60; $y <= 430; $y += 10) {
            if ($app->realClick(100, $y)) {
                $sent++;
            }
        }
        $app->loop(3);
        $ids = array_keys(ExampleState::$v['evSeen']['click'] ?? []);
        if ($firstFrameSent < 0) {
            $firstFrameSent = $sent;
        }
        $rows[] = 'frames=' . $frames . ' 事件=' . $sent . '/38'
            . ' 到达PHP=' . count($ids) . '[' . implode(',', array_slice($ids, 0, 4)) . ']';
    }
    echo 'LIVECLICK ' . implode(' | ', $rows) . "\n";
    // 逐行验收：上面的 y 扫描只能证明"有些行点得动"（18/38 这种数说不清是哪一行没点到，
    // 采样漂了也会报同样的数）。命中表驱动才给得出"13 行全点得动"，而且顺带盯住
    // "渲染过带原生子视图的那一节之后仍然点得动"——那正是修掉的 WKWebView 泄漏的现场。
    $navRows = count(showcaseSections());
    $perRow = navProbe($app);
    echo 'LIVECLICK 逐行=' . $perRow[1] . '/' . $perRow[0] . ' 到达节数=' . $perRow[2] . "\n";
    $app->close();
    // 首帧就得可点：这一条直接对应"启动后左侧点了没反应"。修之前实测是 0/38。
    exit($firstFrameSent > 0 && $perRow[1] >= $navRows && $perRow[2] >= $navRows ? 0 : 1);
}

/**
 * --livetyping：把屏上**每一颗**输入框都点一遍、打一下，逐颗报"改没改"。
 *
 * 为什么不是只测一颗：`--selftest` 的 typedIn 拿的是 paint 里最后一颗 input 的框，
 * 一颗过了就算过。用户说的是"输入框也无法输入"，那是复数——textarea / number_input /
 * otp_input 各有各的 press→focus→keyDown 路径，只验最后一颗等于没验其余的。
 * 所以这里用 `App::inputProbe($index)` 逐索引取框，点中心、打一个 '1'（kVK_ANSI_1=18，
 * 数字与文本都不该被吃掉），再看那颗有没有发出 change。
 */
function runLiveType(): void
{
    // trace 打开：这一步在本模式里不是可选项。段错误时 stdout 一行都没落（echo 全被缓冲吃掉），
    // 只有落盘的进度行能告诉我们死在哪一步——每条动作前先写一行，最后一行就是崩点。
    ExampleState::boot(['trace' => true]);
    $app = new App();
    wireShowcase($app);
    $app->create();
    $app->createWindow('Showcase LiveType', 1180, 760);
    // 与交互模式同一颗每帧回调（含 paint 途中的 activate 抢前台），不是只画 shell 的简化版：
    // 用户面前的节奏里就有那一步，探针必须跑在同一形状上。
    $app->keepTryingForeground(12);
    $app->view(static function () use ($app): El { return interactiveFrame($app); });
    $app->loop(2);

    $sawKeyDown = [];
    $keyValueSeen = '';
    $rows = [];
    $totalIn = 0;
    $totalTyped = 0;
    $allOk = true;
    foreach (array_keys(showcaseSections()) as $tree) {
        showcaseTrace('lt tree=' . $tree . ' begin');
        // 换节走真点击：这一条门从此同时盯着"导航点得到"与"点完打得进字"，
        // 而不是只测后者（改状态换节会整段绕过 hit-test）。
        if (!navClickTo($app, $tree)) {
            showcaseTrace('lt tree=' . $tree . ' nav-click-missed');
            $rows[] = 'tree' . $tree . ':导航点不到';
            $allOk = false;
            continue;
        }
        $n = (int) ($app->inputProbe()['n'] ?? 0);
        showcaseTrace('lt tree=' . $tree . ' inputs=' . $n);
        if ($n <= 0) {
            continue;
        }
        $typed = 0;
        $tried = 0;
        // 倒序是历史遗留：曾经有一颗按下去就把进程打崩，正序跑会在崩点之后什么都测不到。
        for ($i = $n - 1; $i >= 0; $i--) {
            // 第 2 节的 i=1 是 `in-key`（挂 onKeyDown 的那颗）：它一度"一按真实键就段错误"，
            // 中间还试过"干脆不给它挂监听器"。现在键事件在 view 层就交给宿主
            //（cmd/patch-key-view.sh 的 gpuiEnqueueKey，与 OnClick 同形状），所以它和其余
            // 各颗走完全同一条断言，没有任何特例。
            showcaseTrace('lt tree=' . $tree . ' probe i=' . $i);
            $b = $app->inputProbe($i);
            $cx = (int) ((int) $b['x'] + (int) $b['w'] / 2);
            $cy = (int) ((int) $b['y'] + (int) $b['h'] / 2);
            ExampleState::$v['evSeen']['change'] = [];
            if ((int) $b['w'] <= 0 || !$app->realClick($cx, $cy)) {
                continue;
            }
            showcaseTrace('lt tree=' . $tree . ' clicked i=' . $i . ' at ' . $cx . ',' . $cy);
            $tried++;
            $app->loop(2);
            showcaseTrace('lt tree=' . $tree . ' key i=' . $i);
            $app->realKey(18, '1');
            $app->loop(2);
            if (count(ExampleState::$v['evSeen']['change'] ?? []) > 0) {
                $typed++;
            }
            // 真实按键也要真的成为 key_down 事件（不只是 change）：下面按 id 收着，
            // 最后要求 in-key 在里面——那是这轮修复的正题。
            foreach (array_keys(ExampleState::$v['evSeen']['key_down'] ?? []) as $kid) {
                $sawKeyDown[(string) $kid] = true;
            }
            // value 也断言：key_down 带的是键名（KeyName(vk)），字符事件带的是码点
            //（KeyEvent 的 vk/ch 互斥，见 gpui.h 的注释）。合成这颗 keycode 18 → "1"。
            $keyValueSeen .= (string) ExampleState::$v['key'];
            ExampleState::$v['key'] = '';
        }
        $rows[] = 'tree' . $tree . ':' . $typed . '/' . $tried . '(of ' . $n . ')';
        $totalIn += $tried;
        $totalTyped += $typed;
        if ($typed !== $tried) {
            $allOk = false;
        }
    }
    // 用户手势的一圈：真点进"挂原生子视图的那一节"、再真点回第 2 节逐颗打字。
    // 它照见的是"原生视图在场过之后，导航与键盘焦点还得回来"。它**不是**那条常驻
    // WKWebView 吃掉上半区点击的回归位：反向对照实测过两次（把可见性收放掐掉、
    // 以及把裸渲染换到不同顺序），这一条都还是 导航=true 打字=4/4，而 --selftest 的
    // navProbe 同样条件下会红成 afterAll=7/13。那条才是回归位，这里只是多一层手势覆盖。
    // 节号从标题里找，不写死：以后加节/调顺序时这条门不该跟着漂。
    $embedTree = 0;
    $embedBuilder = '';
    foreach (showcaseSections() as $rtT => [$rtTitle, $rtFn]) {
        if (str_contains((string) $rtTitle, 'WebView')) {
            $embedTree = (int) $rtT;
            $embedBuilder = (string) $rtFn;
        }
    }
    $rtNav = $embedTree > 0 && navClickTo($app, $embedTree);
    if ($rtNav && $embedBuilder !== '') {
        $app->render($embedBuilder(ExampleState::get()));
        $app->loop(2);
        $rtNav = navClickTo($app, 2);
    }
    $rtTyped = 0;
    $rtTried = 0;
    if ($rtNav) {
        $rtN = (int) ($app->inputProbe()['n'] ?? 0);
        for ($i = 0; $i < $rtN; $i++) {
            $b = $app->inputProbe($i);
            if ((int) $b['w'] <= 0) {
                continue;
            }
            ExampleState::$v['evSeen']['change'] = [];
            if (!$app->realClick((int) $b['x'] + (int) $b['w'] / 2, (int) $b['y'] + (int) $b['h'] / 2)) {
                continue;
            }
            $rtTried++;
            $app->loop(2);
            $app->realKey(18, '1');
            $app->loop(2);
            if (count(ExampleState::$v['evSeen']['change'] ?? []) > 0) {
                $rtTyped++;
            }
        }
    }
    echo 'ROUNDTRIP 经节' . $embedTree . '回节2 导航=' . var_export($rtNav, true)
        . ' 打字=' . $rtTyped . '/' . $rtTried . "\n";
    ksort($sawKeyDown);
    echo 'LIVETYPE 合计=' . $totalTyped . '/' . $totalIn
        . ' key_down=[' . implode(',', array_keys($sawKeyDown)) . ']'
        . ' value=[' . $keyValueSeen . ']'
        . ' | ' . implode(' ', $rows) . "\n";
    $app->close();
    // 每一颗被点开的框都必须收下那个键（change 到 PHP 才算"打得进字"），
    // 而且至少要覆盖 4 颗——不然"全过"可能只是什么都没测。
    // 挂了 onKeyDown 的那颗必须真的收到 key_down——这才是"输入框能输入"里按键的那一半。
    // 从原生子视图那节切回来之后，第 2 节的 4 颗（实测数）还得各打得进一个字。
    exit($allOk && $totalTyped >= 4 && isset($sawKeyDown['in-key'])
        && str_contains($keyValueSeen, '1')
        && $rtNav && $rtTried >= 4 && $rtTyped === $rtTried ? 0 : 1);
}

/** 节导航条 + 当前节内容（当前节号从 ExampleState::$v 读）。 */
function showcaseShell(): El
{
    // 节表每帧只建一次：原来下面 `$sections = showcaseSections()` 又建一遍，
    // 而 view 回调是每帧跑的。
    $sections = showcaseSections();
    $nav = [];
    foreach ($sections as $tree => [$title, $fn]) {
        // w(194) 不是装饰：侧栏是 itemsStart()，按钮默认只占文字宽度，实测命中带只有
        // x=12..132，而侧栏宽 210——点在行右半截（用户以为是"同一行"）什么都不会发生。
        // 给满宽之后整行可点，selftest 的 navBand 断言盯着这件事。
        // 导航行必须是 base Button，不能用 catalog Button：后者会把标签居中并按盒子裁掉，
        // 而这里的标题最长有「系统能力：剪贴板 · url · 文件系统 · 进程」，194px 装不下
        // （实测新图左右各被切掉几个字）。选中态改用 bold 表达——它不依赖组件库的盒子。
        // 上游的 primary/ghost 变体属于 catalog Button，打在 kind 节点上现在会抛（见 #66）。
        $btn = El::button($title)->id('nav-' . $tree)->onClick()->w(194.0)
            ->onMouseDownLeft()->onMouseUpLeft();
        if (ExampleState::$v['section'] === $tree) {
            $btn->bold();
        }
        $nav[] = $btn;
    }
    [$title, $builder] = $sections[ExampleState::$v['section']] ?? $sections[1];

    // "点了没反应"最常见的成因不在我们的代码里，而在系统：进程没有 bundle 身份时
    // NSApp.isActive 永远是 false，第一次点击被当成"激活这扇窗口"的点击吃掉，keyDown
    // 也没有 firstResponder 可去。这个判断只有 app 自己看得见，所以直接画在画面上——
    // 比让人猜"是框架坏了还是我没被激活"省事。默认 front=true，基线截图不受影响。
    $head = [El::text($title)->font(16.0)->semibold()->px(16.0)->pt(12.0)
        ->role('heading')->ariaLevel(2)->ariaLabel('当前示例：' . $title)];
    // 事件计数常驻标题下方：手点的人一眼就能看到"我这一下到没到"。日志在 /tmp 里，
    // 不是每个人都愿意为了一次点击去开终端——数字不动就是硬证据，比事后翻日志快得多。
    // 取值全部来自 evSeen（与 selftest 报告同一份数据），没事件时是稳定的 0，
    // 所以 --shot 的像素基线是确定的。
    $head[] = El::text('已收到事件  点击 ' . evCount('click')
        . ' · 输入 ' . evCount('change')
        . ' · 按键 ' . evCount('key_down')
        . ' · 悬停 ' . evCount('hover')
        . ' · 焦点 ' . (ExampleState::$v['front'] ? '前台' : '非前台'))
        ->font(12.0)->px(16.0)->pb(6.0);
    if (!ExampleState::$v['front']) {
        $head[] = El::text('!! 当前不是前台应用：真实点击会被系统当激活点击吃掉，键盘也没有 firstResponder。请用 php bin/gpui run showcase（它会把二进制包进 .app bundle），或先点一下窗口标题栏。')
            ->font(13.0)->px(16.0)->py(6.0);
    }

    return El::hFlex([
        El::vFlex(array_merge(
            [El::text('TypePHP GPUI showcase')->font(14.0)->semibold()->px(8.0)->py(4.0)
                ->role('heading')->ariaLevel(1)],
            $nav
        ))->w(210.0)->gap(2.0)->p(8.0)->bg(0xF4F1EC)->itemsStart()->overflowYScroll()
            ->role('navigation')->ariaLabel('示例节导航'),
        El::vFlex(array_merge($head, [$builder(ExampleState::get())]))
            ->flex1()->gap(4.0)->role('main'),
    ])->sizeFull();
}

/**
 * 手工验收的观测缝：`showcase --trace` 时把每条到达 PHP 的事件追加进
 * /tmp/showcase-events.log。
 *
 * 为什么需要它：`--selftest` 的事件走 gpui_shim_test_event 那条合成缝，它按 callbackId
 * 直接入队，**绕过 materialize 的 behavior 解析**——于是"监听器压根没挂到元素上"这类错
 * 在自检里永远是绿的。只有真鼠标/真键盘 + 这条日志能证明 OS→gpui→PHP 整条路通。
 */
function showcaseTrace(string $line): void
{
    if (!ExampleState::$v['trace']) {
        return;
    }
    file_put_contents('/tmp/showcase-events.log', $line . "\n", FILE_APPEND);
}

// ── 事件接线：每节用到的 id 都在这里，一处不漏 ──────────────────────────────
//
// 状态一律走 ExampleState::$v：形参与闭包捕获都不取引用。nano 下把固定类型变量按引用
// 传进函数会得到 RefWrap<php::Array>，它在动态调用里 commit() 直接 abort
// （"A temporary typed reference cannot escape a dynamic call"，
// vendor/swoole/phpx/include/typephp_helper.h:124）；把形参声明成 array 引参则更早，
// 编译期就 fatal（TypeConversionTrait.php:325 只对 Type::VAR/REF 放行取引用）。
function wireShowcase(App $app): void
{
    // 导航
    foreach (array_keys(showcaseSections()) as $tree) {
        $app->on('nav-' . $tree, 'click', function () use ($tree): void {
            ExampleState::$v['section'] = $tree;
        });
    }
    // 每条通道都记一行：真输入的可观测证明（见 showcaseTrace）
    foreach (['click', 'change', 'hover', 'key_down', 'timer', 'tray', 'hotkey', 'close',
        'mouse_down_left', 'mouse_up_left', 'mouse_move',
        'mouse_down_out', 'scroll_wheel', 'key_up',
        'open_change', 'confirm', 'dismiss', 'step', 'resize'] as $t) {
        $app->onAny($t, function (array $e) use ($t): void {
            $id = (string) ($e['id'] ?? '');
            ExampleState::$v['evSeen'][$t][$id] = $e['value'] ?? true;
            showcaseTrace($t . ' id=' . $id . ' value=' . var_export($e['value'] ?? null, true));
        });
    }
    // 交互模式第一帧挂上的 1s 计时器（见 runInteractive）
    $app->on('tick', 'timer', function () use ($app): void {
        ExampleState::$v['count']++;
        if (ExampleState::$v['trace']) {
            $st = $app->windowState();
            showcaseTrace('tick#' . ExampleState::$v['count']
                . ' active=' . var_export($st['active'], true)
                . ' key=' . var_export($st['key'], true));
        }
    });

    // 3 节：按钮变体——线在 examples/_shared/features/choices.php 里，与 examples/choices 共用。
    wireChoices($app);

    // 2 节：保留态取值——线在 examples/_shared/features/inputs.php 里，
    // 与独立示例 examples/input 共用同一份。
    wireInputs($app);

    // 6 节：catalog 回调（registered 旁路）——线在 examples/_shared/features/catalog.php 里
    wireCatalog($app);

    // 7 节：菜单项（catalog 菜单的动作也走同一条 id/type 路由）
    foreach (['new' => '新建', 'a' => 'a.txt', 'b' => 'b.txt', 'ro' => '只读项'] as $k => $label) {
        $app->on('cr-m-' . $k, 'click', function () use ($label): void {
            ExampleState::$v['lastBtn'] = 'menu:' . $label;
        });
    }
    $app->on('cr-sidebar', 'click', function (): void {
        ExampleState::$v['sidebarCollapsed'] = !ExampleState::$v['sidebarCollapsed'];
    });

    // 8 节：浮层——线在 examples/_shared/features/overlays.php 里，与独立示例 examples/overlay 共用。
    wireOverlays($app);

    // 9 节：系统能力
    $app->on('sy-clip-set', 'click', function () use ($app): void {
        $app->setClipboardText('showcase 写入的文本 ' . $app->notificationCount());
    });
    $app->on('sy-clip-get', 'click', function () use ($app): void {
        $t = $app->clipboardText();
        ExampleState::$v['clip'] = $t === null ? '空' : 'text=' . strlen($t);
    });
    $app->on('sy-clip-item', 'click', function () use ($app): void {
        $item = $app->clipboardItem();
        ExampleState::$v['clip'] = sprintf(
            'text=%s image=%s paths=%d',
            $item['text'] === null ? '-' : strlen($item['text']),
            $item['image'] === null ? '-' : strlen($item['image']),
            count($item['paths'])
        );
    });
    $app->on('sy-url', 'click', function () use ($app): void {
        $ok = $app->openUrl('https://example.com');
        $rej = $app->openUrl('javascript:alert(1)');
        ExampleState::$v['clip'] = 'openUrl 放行=' . var_export($ok, true) . ' 拒绝=' . var_export($rej, true);
    });
    $app->on('sy-pick', 'click', function () use ($app): void {
        $p = $app->promptForPath(false, '选一个文件');
        if ($p !== null) {
            ExampleState::$v['picked'] = $p;
        }
    });
    $app->on('sy-fs', 'click', function (): void {
        // 授权根必须是**已存在**的目录：Capabilities::ResolvePath 只做前缀判断，
        // 不会替你把根建出来，授一个还不存在的目录会让后面每一步都吃"不在根里"。
        $root = '/tmp';
        $work = '/tmp/gpui-showcase/nest';
        App::grantRoot($root, true);
        $log = [];
        $log[] = 'mkdir=' . var_export(App::fsMkdir($work, true), true);
        $log[] = 'write=' . var_export(App::fsWrite($root . '/nest/a.txt', "第一段\n第二段"), true);
        $log[] = 'read=' . var_export(App::fsRead($root . '/nest/a.txt'), true);
        $log[] = 'exists=' . var_export(App::fsExists($root . '/nest/a.txt'), true);
        $truncated = false;
        $entries = App::fsList($work, $truncated) ?? [];
        $names = [];
        foreach ($entries as $e) {
            $names[] = (string) $e['name'];
        }
        sort($names);
        $log[] = 'list=' . implode(',', $names) . ' truncated=' . var_export($truncated, true);
        $log[] = 'outside=' . var_export(App::fsRead('/etc/hosts'), true) . '（' . App::fsError() . '）';
        $log[] = 'remove=' . var_export(App::fsRemove($work, true, true), true);
        ExampleState::$v['sysLog'] = $log;
    });
    $app->on('sy-proc', 'click', function (): void {
        App::grantExecute(['/bin/echo']);
        $r = App::processRun('/bin/echo', ['showcase 的输出']);
        $denied = App::processRun('/bin/cat', ['/etc/passwd']);
        ExampleState::$v['sysLog'] = array_merge(ExampleState::$v['sysLog'], [
            'echo=' . ($r === null ? 'null' : 'code=' . $r['code'] . ' out=' . trim($r['out'])),
            'cat(未授权)=' . var_export($denied, true) . '（' . App::fsError() . '）',
        ]);
    });
    $app->on('sy-store', 'click', function (): void {
        $before = App::storageSet('k', 'v');
        App::grantStorage(true);
        App::storagePath('/tmp/gpui-showcase-store.json');
        $set = App::storageSet('theme', 'dark');
        ExampleState::$v['sysLog'] = array_merge(ExampleState::$v['sysLog'], [
            '未授权 set=' . var_export($before, true),
            '授权后 set=' . var_export($set, true) . ' get=' . var_export(App::storageGet('theme'), true)
                . ' len=' . App::storageLen(),
            'remove=' . var_export(App::storageRemove('theme'), true),
        ]);
    });

    // 10 节：服务
    $app->on('sv-http', 'click', function () use ($app): void {
        // 127.0.0.1 上一个必然没人听的端口：验整条异步路而不碰外网
        ExampleState::$v['http'] = 'queued=' . var_export($app->httpGet('http://127.0.0.1:1/probe', 'sv-api'), true);
    });
    $app->on('sv-api', 'http', function () use ($app): void {
        $r = $app->httpResponse('sv-api');
        ExampleState::$v['http'] = $r === null ? '应答已被读过' : 'ok=' . var_export($r['ok'], true) . ' status=' . $r['status'];
    });
    $app->on('sv-watch', 'click', function () use ($app): void {
        ExampleState::$v['watchId'] = $app->watchDir('/tmp', 'sv-themes', false);
    });
    $app->on('sv-unwatch', 'click', function () use ($app): void {
        if (ExampleState::$v['watchId'] > 0) {
            $app->unwatchDir(ExampleState::$v['watchId']);
            ExampleState::$v['watchId'] = 0;
        }
    });
    $app->on('sv-themes', 'dirChange', function () use ($app): void {
        ExampleState::$v['watchHits']++;
        $app->toast('目录变了', '/tmp 有写动作为真');
    });
    $app->on('sv-inflate', 'click', function (): void {
        // 外部真数据（开发机 PHP 的 zlib 生成的 "hello gpui"），字节写死。
        // 之前这里用一串手写的 base64，结果 inflate 返回 NULL——本机
        // gzdecode(base64_decode(...)) 同样返回 false，所以错的是**我编的向量**，
        // 不是 nano 的 base64_decode，也不是 gpui 的解压器。锚点要在两边都成立。
        $gzip = "\x1F\x8B\x08\x00\x00\x00\x00\x00\x00\x13\xCB\x48\xCD\xC9\xC9\x57\x48"
            . "\x2F\x28\xCD\x04\x00\x52\x1A\x59\x50\x0A\x00\x00\x00";
        $out = App::inflate($gzip, true);
        ExampleState::$v['inflate'] = $out === null ? '失败' : '解出 ' . strlen($out) . ' 字节：' . $out;
    });
    $app->on('sv-sysinfo', 'click', function (): void {
        $info = App::sysInfo(3);
        $rows = [
            sprintf('cpu=%.1f%% mem=%.0f%% disk=%.0f%% ncpu=%d',
                $info['cpu'], $info['memPct'], $info['diskPct'], $info['ncpu']),
            sprintf('mem %s / %s', fmtBytes((int) $info['memUsedBytes']), fmtBytes((int) $info['memTotalBytes'])),
            sprintf('battery present=%s charging=%s pct=%.0f%%',
                var_export($info['batteryPresent'], true), var_export($info['batteryCharging'], true), $info['batteryPct']),
        ];
        foreach ($info['procs'] as $p) {
            $rows[] = sprintf('  pid=%d %s cpu=%.1f%% mem=%s', $p['pid'], $p['name'], $p['cpu'], fmtBytes((int) $p['memoryBytes']));
        }
        ExampleState::$v['sysInfo'] = $rows;
    });
    foreach (App::FPS_ANCHORS as $a) {
        $app->on('sv-fps-' . $a, 'click', function () use ($app, $a): void {
            ExampleState::$v['hud'] = $app->showFpsHud($a);
            ExampleState::$v['fpsAnchor'] = $a;
        });
    }
    $app->on('sv-fps-off', 'click', function () use ($app): void {
        $app->hideFpsHud();
        ExampleState::$v['hud'] = $app->fpsHudVisible();
    });
    $app->on('sv-insp', 'click', function () use ($app): void {
        $app->toggleInspector();
    });
    $app->on('sv-theme', 'click', function () use ($app): void {
        ExampleState::$v['theme'] = ExampleState::$v['theme'] === 'dark' ? 'light' : 'dark';
        $app->setTheme(ExampleState::$v['theme']);
    });

    // 11 节：内嵌
    $app->on('em-back', 'click', function () use ($app): void {
        $app->webviewBack('em-url');
    });
    $app->on('em-fwd', 'click', function () use ($app): void {
        $app->webviewForward('em-url');
    });
    $app->on('em-probe', 'click', function () use ($app): void {
        $p = $app->webviewProbe('em-url');
        ExampleState::$v['probe'] = sprintf('ok=%s url=%s box=%.0fx%.0f',
            var_export($p['ok'] ?? false, true), (string) ($p['url'] ?? ''),
            (float) ($p['w'] ?? 0), (float) ($p['h'] ?? 0));
    });

    // 13 节：mac 系统集成
    $app->on('mc-tray', 'click', function () use ($app): void {
        ExampleState::$v['trayId'] = $app->tray(['新建', '打开…', '退出'], 'mc-tray-item', 'Showcase 托盘');
    });
    $app->on('mc-tray-off', 'click', function () use ($app): void {
        if (ExampleState::$v['trayId'] > 0) {
            $app->removeTray(ExampleState::$v['trayId']);
            ExampleState::$v['trayId'] = 0;
        }
    });
    $app->on('mc-tray-item', 'tray', function (array $e): void {
        ExampleState::$v['lastBtn'] = 'tray:' . (string) ($e['value'] ?? '');
    });
    $app->on('mc-hk-1', 'click', function () use ($app): void {
        // keycode 105 = F7（Carbon kVK_F7）， mods 走 App::HOTKEY_MODS 的位掩码表。
        ExampleState::$v['hotkeyId'] = $app->onHotkey('mc-hk-cmdshift', 105, ['cmd', 'shift']);
        ExampleState::$v['hotkeyMods'] = 'cmd+shift';
    });
    $app->on('mc-hk-2', 'click', function () use ($app): void {
        ExampleState::$v['hotkeyId'] = $app->onHotkey('mc-hk-opt', 100, ['option']);
        ExampleState::$v['hotkeyMods'] = 'option';
    });
    $app->on('mc-hk-3', 'click', function () use ($app): void {
        ExampleState::$v['hotkeyId'] = $app->onHotkey('mc-hk-ctrl', 101, ['control']);
        ExampleState::$v['hotkeyMods'] = 'control';
    });
    foreach (array_keys(App::HOTKEY_MODS) as $mod) {
        $app->on('mc-hk-' . $mod, 'click', function () use ($app, $mod): void {
            ExampleState::$v['hotkeyId'] = $app->onHotkey('mc-hk-' . $mod, 110, [$mod]);
            ExampleState::$v['hotkeyMods'] = $mod;
        });
    }
    $app->on('mc-hk-cmdshift', 'hotkey', function (): void {
        ExampleState::$v['hotkeyHits']++;
    });
    foreach (array_keys(App::HOTKEY_MODS) as $mod) {
        $app->on('mc-hk-' . $mod, 'hotkey', function () use ($mod): void {
            ExampleState::$v['hotkeyHits']++;
            ExampleState::$v['hotkeyMods'] = '命中:' . $mod;
        });
    }
    $app->on('mc-hk-off', 'click', function () use ($app): void {
        if (ExampleState::$v['hotkeyId'] > 0) {
            $app->removeHotkey(ExampleState::$v['hotkeyId']);
            ExampleState::$v['hotkeyId'] = 0;
        }
    });
    $app->on('mc-dock-7', 'click', function (): void {
        App::dockBadge('7');
        ExampleState::$v['dock'] = '7';
    });
    $app->on('mc-dock-off', 'click', function (): void {
        App::dockBadge('');
        ExampleState::$v['dock'] = '（清空）';
    });
    $app->on('mc-osn', 'click', function (): void {
        ExampleState::$v['osNotify'] = var_export(App::osNotify('Showcase', '这是操作系统通知中心的一条'), true);
    });
    $app->on('mc-menus', 'click', function () use ($app): void {
        // 键名要跟 bridge 对齐：菜单条用 name（不是 label），行用 action（不是 id）。
        // 写错不报错，只是那一行的动作永远是 0——点了没有任何回音。
        ExampleState::$v['menuCount'] = $app->setAppMenus([
            ['name' => 'Showcase', 'items' => [
                ['label' => '关于', 'action' => 'menu-about'],
                ['separator' => true],
                ['label' => '退出', 'action' => 'menu-quit'],
            ]],
            ['name' => 'Help', 'items' => [['label' => '文档', 'action' => 'menu-doc']]],
        ]);
    });

    // 12 节：样式域的接线在 examples/_shared/features/styling.php 里
    wireStyling($app);
    // 1/2 节：hover 与 key_down 两条独立事件通道
    foreach (El::COLLAPSE_MODES as $mode) {
        $app->on('cr-collapse-' . $mode, 'click', function () use ($mode): void {
            ExampleState::$v['collapse'] = $mode;
        });
    }
    foreach (El::FIELD_ALIGN as $a) {
        $app->on('cr-align-' . $a, 'click', function () use ($a): void {
            ExampleState::$v['align'] = $a;
        });
    }
    // 1 节：pr-hover 那颗探针的精确 hover handler 在 examples/_shared/features/primitives.php 里
    wirePrimitives($app);
    // 具体 id 的 hover handler 与 onAny('hover') 两条都注册：前者证明精确路由，后者证明通配。
    $app->onAny('hover', function (array $e): void {
        ExampleState::$v['note'] = 'hover:' . (string) ($e['id'] ?? '');
    });
    // 菜单行的动作以 `action` 事件回来，id 就是 El::menuItem($label, $action) 里那个
    // action 名（shim 用 ShellActionScriptId 反查回来的），所以这里与按钮点击同一套写法。
    $app->onAny('action', function (array $e): void {
        $id = (string) ($e['id'] ?? '');
        ExampleState::$v['evSeen']['action'][$id] = true;
        showcaseTrace('action id=' . $id);
    });
}

/** 把"哪些事件的哪个 id 真到了 PHP"压成一行——诊断真输入路径用，避开 json_encode。 */
/** 某类事件收到过几颗（按不同 id 计）——窗口内 HUD 用，和报告同源，不另开计数。 */
function evCount(string $type): int
{
    return count(ExampleState::$v['evSeen'][$type] ?? []);
}

function evSeenSummary(): string
{
    $parts = [];
    foreach (ExampleState::$v['evSeen'] as $type => $ids) {
        $parts[] = $type . '{' . implode(',', array_keys($ids)) . '}';
    }
    return implode(' ', $parts);
}

function fmtBytes(int $n): string
{
    $units = ['B', 'K', 'M', 'G', 'T'];
    $i = 0;
    $v = (float) $n;
    while ($v >= 1024.0 && $i < 4) {
        $v /= 1024.0;
        $i++;
    }
    return sprintf('%.1f%s', $v, $units[$i]);
}

// ── 模式 ────────────────────────────────────────────────────────────────────
/**
 * 交互模式的每一帧：第一帧装上菜单/定时器，之后每帧量窗口状态、必要时抢一次前台。
 *
 * 单独成函数是为了让 `--liveclick` 能注册**同一颗**每帧回调：`activate()` 内部会泵 AppKit
 * 的 run loop，而它发生在 paint 途中——用户那一下点击正是从这条队列里上来的。这条路径
 * 之前任何门都没覆盖（liveclick 原来注册的是只画 shell 的回调），于是"手点没反应"在
 * 全绿的情况下完全可能真实存在。
 */
function interactiveFrame(App $app): El
{
    if (!ExampleState::$v['booted']) {
        ExampleState::$v['booted'] = true;
        ExampleState::$v['menuCount'] = $app->setAppMenus([
            ['name' => 'Showcase', 'items' => [
                ['label' => '关于', 'action' => 'menu-about'],
                ['label' => '退出', 'action' => 'menu-quit'],
            ]],
        ]);
        $app->setTimer('tick', 1000);
        // 把窗口状态写进 trace：用户手点一次之后 active/key 有没有变 1，
        // 分的正是"激活被拒（点击被当成激活吃掉）"与"事件到了但没路由"两种病。
        $st = $app->windowState();
        showcaseTrace('startup active=' . var_export($st['active'], true)
            . ' key=' . var_export($st['key'], true)
            . ' visible=' . var_export($st['visible'], true)
            . ' main=' . var_export($st['main'], true));
    }
    // 每帧量一次：banner 的显示条件只有 app 自己测得到，而"什么时候被系统降级成
    // 非前台"是运行时才发生的（比如用户点了别的 app），不能只在启动时判。
    // 抢前台本身已经交给框架（keepTryingForeground）：帧可能被脏门跳过，
    // 放在这里就会变成"没画→没尝试→永远不是前台"的死结。
    $live = $app->windowState();
    ExampleState::$v['front'] = (bool) $live['active'] && (bool) $live['key'];
    return showcaseShell();
}

function runInteractive(bool $trace = false): void
{
    ExampleState::boot($trace ? ['trace' => true] : []);
    $app = new App();
    // 处理器注册不碰原生，先接线再进 run()。
    wireShowcase($app);
    // 前台是"和当时握着焦点的一方竞争"来的，一次申请未必赢：让框架在头 12 帧里
    // 没拿到就再申请一次，12 帧之后收手——用户可能正在别的 app 里打字，
    // 一直抢焦点比"点第一下没反应"更糟。
    $app->keepTryingForeground(12);
    // run() 是 create + createWindow + view + loop 的糖，也是 PHP 面的主入口；
    // 窗口要到 run() 里面才存在，所以菜单/定时器这类"要有窗口"的调用挂到第一帧。
    $app->run('TypePHP GPUI — Showcase', 1180, 760, static function () use ($app): El {
        return interactiveFrame($app);
    });
}

/**
 * 命中表驱动的导航探针：先量出每颗导航按钮的矩形，再逐颗点自己的中心，报"这一下有没有到 PHP"。
 * 不按 y 猜行位置——猜会把"命中表没登记 / 坐标换算错 / 监听没挂 / 被原生子视图挡住"四种错
 * 统一伪装成"那一行点不动"，而这四种修法完全不同（最后那种实测就是 WebView 那节留下的
 * 那颗 WKWebView：gpui 的命中表里 13 条矩形、监听器全在，真实鼠标却只到得了下面 7 行）。
 * 返回 [导航矩形数, 点动数, 到达的不同节数]。
 */
function navProbe(App $app): array
{
    ExampleState::$v['section'] = 1;
    $app->render(showcaseShell());
    $app->loop(2);
    $total = (int) ($app->hitProbe()['n'] ?? 0);
    $navRects = [];
    for ($i = 0; $i < $total; $i++) {
        $h = $app->hitProbe($i);
        if ((int) $h['x'] < 210 && (int) $h['w'] >= 190 && (int) $h['w'] <= 200) {
            $navRects[] = $h;
        }
    }
    $clicked = 0;
    $sections = [];
    foreach ($navRects as $r) {
        // 每次点击前退回第 1 节重画：几何钉住，量到的矩形才仍然指向同一行。
        ExampleState::$v['section'] = 1;
        $app->render(showcaseShell());
        $app->loop(2);
        $cx = (int) $r['x'] + (int) $r['w'] / 2;
        $cy = (int) $r['y'] + (int) $r['h'] / 2;
        $got = $app->realClick($cx, $cy);
        $sec = -1;
        if ($got) {
            $clicked++;
            $app->loop(2);
            $sec = (int) ExampleState::$v['section'];
            $sections[$sec] = true;
        }
    }
    return [count($navRects), $clicked, count($sections)];
}

/**
 * --selftest：逐节渲染并断言"这一节的树在原生侧没有报错节点"，再把每个 App 能力真调一遍。
 *
 * 为什么值得单独一条：注册失败只在 registeredError() 里，平时画面上只是一个空块。
 */
/**
 * 真点某一节的导航行：从命中表里取第 $tree 条导航矩形（paint 顺序就是节顺序），点它的中心，
 * 再看状态有没有真的换成这一节。用它而不是 `ExampleState::$v['section'] = N`：直接改状态
 * 等于绕过 hit-test 假装"点过了"，而那正是用户报"左侧点不了"时唯一没被照到的那段路。
 */
function navClickTo(App $app, int $tree): bool
{
    $total = (int) ($app->hitProbe()['n'] ?? 0);
    $seen = 0;
    for ($i = 0; $i < $total; $i++) {
        $h = $app->hitProbe($i);
        if ((int) $h['x'] >= 210 || (int) $h['w'] < 190 || (int) $h['w'] > 200) {
            continue;
        }
        $seen++;
        if ($seen !== $tree) {
            continue;
        }
        if (!$app->realClick((int) $h['x'] + (int) $h['w'] / 2, (int) $h['y'] + (int) $h['h'] / 2)) {
            return false;
        }
        $app->loop(2);
        return (int) ExampleState::$v['section'] === $tree;
    }
    return false;
}

function runSelfTest(bool $expectBundle, bool $startFront = false): void
{
    ExampleState::boot();
    $app = new App();
    $app->create();
    $app->createWindow('Showcase Selftest', 1000, 700);
    // 先取一份"还没碰过任何缝"的窗口状态：缝里的 Deliver 在窗口不是 key 时会替它 MakeKey，
    // 于是后面所有读数都看不见"启动时本来不是前台"这件事——而真实用户没有那一步，
    // 他那第一下会被 macOS 当成"激活这扇窗口"的点击直接吃掉。这条读数决定要不要
    // 在启动时就自己去抢前台。
    $startState = $app->windowState();
    // A/B（--startfront）：启动时就自己去抢前台，到底赢不赢？实测裸跑与 bundle 两条
    // 在抢之前都是 active=false/key=false——那种状态下用户的第一下点击必然被 macOS
    // 当成"激活这扇窗口"吃掉，键盘也没有 firstResponder。赢不了就不该在启动时抢
    // （只是噪声），赢得了才值得把它接到 run() 的第一帧之前。
    if ($startFront) {
        $app->activate();
        $app->loop(2);
    }
    $startState2 = $app->windowState();
    wireShowcase($app);
    // 基线：窗口刚建好、什么都还没调用过。下面 C 那次用的是同一套动作，两次读数一起看
    // 才能分清"从来点不动"与"跑过某一步之后才点不动"（后者实测踩过一次：见 C 的注释）。
    $navA = navProbe($app);

    // 组件失败计数是**累计**的，逐节判之前先取基线（前面若有动作已经动过它，
    // 不该算到某一节头上）。
    $failuresBefore = (int) App::componentFailure()['count'];
    $badSections = [];
    foreach (showcaseSections() as $tree => [$title, $builder]) {
        $app->render($builder(ExampleState::get()));
        $app->loop(2);
        $err = $app->registeredError();
        if ($err !== '') {
            $badSections[] = $tree . ':' . $err;
        }
        // registered 组件建不出来时 gpui **不失败整帧**，只在画面上渲染成
        // "Failed to render X"，而 registeredError() 照不到 materialize 阶段——
        // 唯一会动的是 runtime 的组件失败计数。所以按"这一节渲染前后计数有没有增加"判，
        // 并把原因带进报告。（反例：扫 debug_tree 找那句占位文本是无效的，
        // debug_tree 打的是 spec 树，占位节点是 materialize 阶段才生成的。）
        $cf = App::componentFailure();
        if ((int) $cf['count'] > $failuresBefore) {
            $badSections[] = $tree . ':materialize 失败(' . (string) $cf['last'] . ')';
        }
        $failuresBefore = (int) $cf['count'];
    }

    // 表格 aria 序号那条门（#69 的回归位）2026-10-14 起住在 examples/table：
    // 它要的是"把 §5 重画一遍再回读 specIndex"，不需要画廊的壳，独立一支崩了能停在自己那一行。
    // 角色白名单两头都要在**编出来的产物**里验：上游对未知角色是
    // `if (role != None) element->Role(role)`（静默不加），所以 role() 的当场抛
    // 是这条哑路唯一的哨兵；而 181 个常量名 in_array 在 nano 里的行为，只有跑产物才排得掉。
    $roleAllOk = true;
    foreach (El::ROLES as $r) {
        try {
            El::div()->role($r);
        } catch (\Throwable $t) {
            $roleAllOk = false;
            break;
        }
    }
    $roleThrows = false;
    try {
        El::div()->role('Heading');   // 上游表里就是这么拼的，但匹配只按 snake_case
    } catch (\Throwable $t) {
        $roleThrows = true;
    }
    // 47 个零参样式糖在产物里逐个调一遍那条门，2026-10-14 起搬到 examples/style
    // （同一份源也在画廊 yml 里，画廊仍会把整棵 §12 树渲染一遍，只是"数 op"这件事
    // 不需要画廊的壳，独立一支崩了能停在自己那一行）。
    // 五个部件级回调名那条门（#73 的"op 发得出"那一半）2026-10-14 起住在 examples/catalog。
    // 送达那一半仍未量到：link_click 要 markdown 里的链接真被点，item_click /
    // item_secondary_click 要 list 的 renderer（#29/#35 那格活），token_* 要 TokenInput。
    // 文档里标的仍是"名字在册、送达未量"。

    // 能力真调用（不只看形状）：每一项都断在"结果对"而不是"没崩"。
    $app->setClipboardText('SHOWCASE-Ω');
    $clipBack = $app->clipboardText();
    $item = $app->clipboardItem();
    $urlRejected = $app->openUrl('javascript:alert(1)');
    $hudOn = $app->showFpsHud('top_left');
    $hudVisible = $app->fpsHudVisible();
    $app->hideFpsHud();
    $sheetN = $app->openSheet(El::text('st sheet')->p(10.0), 'right');
    $hasSheet = $app->hasSheet();
    $app->closeSheet();
    // overlay 回收门（审核第 5 步③的回归位）：反复开/关 dialog、反复换 sheet，
    // 包装条数必须回到 0。修之前每次开关都净增一条 Box2 包装与一条 g_views 键，
    // 而那条键指向的 PhpView 早已被上游的层析构释放——长会话里就是只增不减的悬垂表。
    $baseCounts = $app->stateCounts();
    $boxesBase = (int) ($baseCounts['boxesAlive'] ?? 0);
    $viewsBase = (int) ($baseCounts['viewsAlive'] ?? 0);
    $churnOpened = 0;
    for ($i = 0; $i < 30; $i++) {
        if ($app->openDialog(El::text('churn ' . $i)->p(10.0)) <= 0) {
            break;
        }
        $churnOpened++;
        $app->closeDialog();
    }
    for ($i = 0; $i < 10; $i++) {
        $app->openSheet(El::text('churn sheet ' . $i)->p(10.0), 'right');
    }
    $app->closeSheet();
    // 量"回收"必须看进程级的存活数，不能看 dialogs 那条 vector 长度——pop 在调用方做，
    // 回收与否它都是 0（第一版门就是这么假绿的，反向对照才暴露）。
    $after = $app->stateCounts();
    $overlayLeft = (int) ($after['dialogs'] ?? -1);
    $boxesAlive = (int) ($after['boxesAlive'] ?? -1);
    $viewsAlive = (int) ($after['viewsAlive'] ?? -1);
    $overlayReclaimed = $churnOpened >= 25 && $overlayLeft === 0
        && $boxesAlive === $boxesBase && $viewsAlive === $viewsBase;
    // 原生关掉的那一条路（点背板 → ShellDialogLayer::OnBackdrop → Close）不经过本 shim
    // 的 closeDialog，所以包装栈会与上游脱钩：多出来的那份包装对应的 PhpView 已被上游
    // 层析构释放（root.cpp:209），留着就是"下次同地址复用时 boxForView 交出已死 Box2"。
    // 这条门量的是：下一次轮询 dialogCount() 时必须把它对齐收掉。
    // （不用 Escape 试：DialogBindKeys 把按键绑在 focus trap 上，焦点没进 trap 就不响，
    //   那是另一件事，见任务 #62。）
    $app->openDialog(El::text('bd-dismiss me')->p(10.0), false, true);
    $app->loop(2);
    $app->realClick(600, 150);         // 内容区内、居上：surface 在正中，这里只能是背板
    // （别拿边角当背板：窗口 resize 边上 AppKit 的 _mouseHysteresisCheck: 会等下一个事件而不返回）
    $app->loop(2);
    $bdNativeClosed = $app->dialogCount() === 0;   // 这一次轮询顺带把包装对齐
    $bdCounts = $app->stateCounts();
    $bdReclaimed = (int) ($bdCounts['boxesAlive'] ?? -1) === $boxesBase
        && (int) ($bdCounts['viewsAlive'] ?? -1) === $viewsBase;
    // #62 的读数。两条断言各管各的，别混：
    //   $escProbeAfterTab —— 焦点在框内（Tab 进去了）时 Escape 必须关掉它。这条真锁行为。
    //   $escClosed        —— 前一个 dialog 周期之后，纯文本框的 Escape 也关得掉。
    // 但**#62 本体**（进程里第一个框、没点过任何东西）不在这里判——反向对照显示摘掉
    // 候选修法后这两条照样绿，说明它们不覆盖那个场景。那一格由 `--escape` 单独量。
    // 已排除：注入路不通（同一个派发口上 Tab 会换焦点）、escape/backdrop 四层参数顺序错、
    // topmost 没置（root.cpp:840-847 每次渲染重算，背板能关就是证据）、
    // 帧数不够、脏驱动没重画、开框时清焦点（focusWas=0 时 Escape 依然不关）。
    // 第一格：框里放一颗**可聚焦**的 catalog 按钮，Tab 把焦点带进去再按 Escape。
    //   关掉 ⇒ 绑定与派发都是好的，缺的只是"开框那一下把焦点带进去"；
    //   不关 ⇒ 上下文栈这一侧没拿到 DialogContext，得往 win->dispatch/KeyContext 查。
    $app->openDialog(El::catalogButton('esc-target', '按 Escape 该关掉我')->onClick());
    $app->loop(6);
    $escProbeOpen = $app->dialogCount();
    $escProbeTrap = $app->focusTrap();     // 机制级读数：陷阱生效了没有
    $app->realKey(48, '');                // kVK_Tab
    $app->loop(4);
    $escProbeFocus = $app->focusedId();
    $app->realKey(53, '');                // kVK_Escape
    $app->loop(6);
    $escProbeAfterTab = $app->dialogCount();
    for ($i = 0; $i < 4 && $app->dialogCount() > 0; $i++) {
        $app->closeDialog();
        $app->loop(2);
    }

    $dialogN = $app->openDialog(El::text('st dialog')->p(10.0));
    $dialogCount = $app->dialogCount();
    $plainTrap = $app->focusTrap();
    // 这一格是**不依赖输入**的机制读数，因此可以当硬门：给几帧之后，
    // 焦点陷阱必须生效、且焦点必须就落在陷阱的宿主（popup）上。
    // 实测：trapOnOpen=0（open 当场还没 arm，Arm 发生在 DrawFrame 的渲染里）、
    // 十帧后 trap==focus==popup 的 id —— 上游那条"开框把焦点带进陷阱"的链是通的。
    // 反向对照：把 loop(10) 挪到读数之后 ⇒ trapAfterFrames=0 / focus=-1062 / trapOk=false，
    // selftest rc=1。所以这门量的是"帧把陷阱带起来"这件事本身，不是恒真。
    // Escape 关不关则依赖真键送达（前台是竞争态），所以只报不判，见 --escape。
    $app->loop(10);
    $trapAfterFrames = $app->focusTrap();
    $focusOnTrap = $app->focusedId();
    $trapOk = $trapAfterFrames !== 0 && $focusOnTrap === $trapAfterFrames;
    // 第二格：纯文本框（没有任何可聚焦东西）开着，直接按 Escape。
    $escClosed = false;
    $escAfterInvalidate = false;
    $countAfterPress = -1;
    $focusBeforeEsc = $app->focusedId();
    if ($dialogCount >= 1) {
        $app->loop(10);
        $app->realKey(53, '');            // kVK_Escape
        $app->loop(6);
        $escClosed = $app->dialogCount() === 0;
        if (!$escClosed) {
            $app->invalidate();
            $app->loop(6);
            $app->realKey(53, '');
            $app->loop(6);
            $escAfterInvalidate = $app->dialogCount() === 0;
        }
        if (!$escAfterInvalidate && $app->dialogCount() >= 1) {
            $app->realClick(600, 380);
            $app->loop(3);
            $countAfterPress = $app->dialogCount();
            $app->realKey(53, '');
            $app->loop(6);
        }
    }
    // 内建动作派发是**不依赖输入**的一条关框路（#62 的另一半）：dispatchAction('ui::Cancel')
    // 命中上游给 popup 挂的那条 OnAction(Cancel)，框就该关。
    // 放在这里而不是上面那格中间：上面那格要读 trap/focus 的时序，中途把框关掉会把它弄红
    // ——上一版就犯过这个错（门的红必须对应得上事，不能对应到探针自己）。
    // 这条门还只在上次修的那条回退路上成立：gpui_shim_dispatch_action 若只查 shell:: 那张表，
    // 'ui::Cancel' 会被 intern 成"shell::ui::Cancel"这个谁都没在听的新 id，派发返回 false。
    $app->openDialog(El::text('cancel-gate 框')->p(10.0));
    $app->loop(10);
    $cancelTrap = $app->focusTrap();
    $cancelDispatched = $cancelTrap !== 0 && $app->dispatchAction('ui::Cancel');
    $app->loop(8);
    $cancelClosed = $cancelDispatched && $app->dialogCount() === 0;
    $app->closeDialog();
    $app->toast('st toast', 'desc', 'warning', 100);
    $nid = $app->notify('st 通知', 'body', 'success', 'bottom_left', 0, 'st-note');
    $notifyCount = $app->notificationCount();
    $app->dismissNotification($nid);
    $app->loop(60);
    $notifyAfter = $app->notificationCount();
    $menuCount = $app->setAppMenus([
        ['name' => 'File', 'items' => [
            ['label' => 'New', 'action' => 'st-new'],
            ['separator' => true],
            ['label' => '无动作项'],
        ]],
    ]);
    // 脏门证据（审核第 5 步①）：两半都要，只测一半可以靠"永远不画"或"永远都画"蒙过去。
    // 正半：有事件进来必须真的重画一次；反半：没事件、状态不变的帧一律不画。
    // 先把**所有**定时器撤掉——它们一响就是真事件，留着这条门就变成随机的。
    // 第一版只撤了 st-timer，漏了 interactiveFrame 第一帧装的 tick(1000ms)：
    // 40 帧的墙钟够它响一次，门于是红在 idle=1——红得对，撤漏的那个才是 bug。
    $app->setTimer('st-timer', 0);
    $app->setTimer('tick', 0);
    $rendersBefore = $app->renderCount();
    $app->runFrames(40);
    // 期望是 1 不是 0：$first 是 loop() 的局部，重进一次循环必补一帧（这个语义要留着
    // ——两次 loop() 之间 app 状态可能已经变了）。要证明的是"40 帧只画 1 次"，
    // 而不是"一帧不画"；不做脏门的话这里是 40。
    $idleRenders = $app->renderCount() - $rendersBefore;
    $framesRan = $app->frameCount();
    $app->dispatchAction('cr-m-new');
    $app->loop(2);
    $wakesOnEvent = $app->renderCount() > $rendersBefore;
    $dirtyGate = $idleRenders === 1 && $wakesOnEvent && $framesRan >= 40;

    // 内存增长门（审核第 5 步③的回归位）：同一个 id 反复重挂定时器，
    // timerKeys 必须还是 1——修之前每挂一次净增一条，永不回收。
    $tk0 = (int) ($app->stateCounts()['timerKeys'] ?? -1);
    for ($i = 0; $i < 50; $i++) {
        $app->setTimer('st-timer', 40);
    }
    $tk1 = (int) ($app->stateCounts()['timerKeys'] ?? -2);
    // 取数点在第一次 setTimer 之前，所以基线是 0；重挂 50 次之后必须只剩 1 条
    // （一个 id 一个键）。修之前是 0 → 51：每次重挂发一个新号，旧号没人收。
    $timerNoGrow = $tk0 === 0 && $tk1 === 1;
    $app->setInput('st-input', '值');
    $app->setTimer('st-timer', 40);
    $app->loop(6);
    $trayId = $app->tray(['A', 'B'], 'st-tray', 'showcase');
    $trayCount = $app->trayCount();
    $app->removeTray($trayId);
    $hk = $app->onHotkey('st-hk', 105, ['cmd', 'shift']);
    $hkFired = App::fireHotkeyForTest(0);
    $app->removeHotkey($hk);
    App::dockBadge('9');
    App::dockBadge('');
    $osn = App::osNotify('st', 'body');
    $theme = $app->text('st-missing');

    // 文件系统 / 进程 / 存储：走真授权门，双向都要对。
    // 授予的根必须已存在（ResolvePath 只做前缀判断），所以授 /tmp、在里面建子目录。
    $fsDir = '/tmp/gpui-showcase-st';
    App::grantRoot('/tmp', true);
    $mk = App::fsMkdir($fsDir, true);
    $w = App::fsWrite($fsDir . '/a.txt', "gpui 写的\n第二段");
    $phpSees = @file_get_contents($fsDir . '/a.txt');
    @file_put_contents($fsDir . '/b.bin', "\x00\x89PNG中文");
    $gpuiRead = App::fsRead($fsDir . '/b.bin');
    $truncated = false;
    $listing = App::fsList($fsDir, $truncated) ?? [];
    $outside = App::fsRead('/etc/hosts');
    $outsideErr = App::fsError();
    $rm = App::fsRemove($fsDir, true, true);

    App::grantExecute(['/bin/echo']);
    $echo = App::processRun('/bin/echo', ['showcase-st']);
    $denied = App::processRun('/bin/cat', ['/etc/passwd']);
    $deniedErr = App::fsError();
    $storeDenied = App::storageSet('k', 'v');
    App::grantStorage(true);
    $storePath = '/tmp/gpui-showcase-store.json';
    $sp = App::storagePath($storePath);
    $ss = App::storageSet('theme', 'dark');
    $sg = App::storageGet('theme');
    $sLen = App::storageLen();
    App::storageRemove('theme');
    @unlink($storePath);

    $plainVec = 'hello gpui';
    $zlibVec = "\x78\x9C\xCB\x48\xCD\xC9\xC9\x57\x48\x2F\x28\xCD\x04\x00\x15\x74\x03\xEA";
    $gzipVec = "\x1F\x8B\x08\x00\x00\x00\x00\x00\x00\x13\xCB\x48\xCD\xC9\xC9\x57\x48"
        . "\x2F\x28\xCD\x04\x00\x52\x1A\x59\x50\x0A\x00\x00\x00";
    $inf = App::inflate($zlibVec);
    $infGzip = App::inflate($gzipVec, true);
    $infBad = App::inflate('not a zlib stream');
    $sys = App::sysInfo(2);
    $watch = $app->watchDir('/tmp', 'st-watch', false);
    $app->unwatchDir($watch);
    $httpQueued = $app->httpGet('http://127.0.0.1:1/x', 'st-api');
    $app->loop(4);
    $resp = $app->httpResponse('st-api');
    $probe = $app->webviewProbe('st-none');
    $errText = $app->registeredError();
    $anyFired = 0;
    $app->onAny('timer', function () use (&$anyFired): void {
        $anyFired++;
    });
    $app->setTimer('st-timer2', 30);
    $app->loop(6);

    // 先量窗口状态，**在任何 realClick 之前**：缝里的 MakeKey 会把"窗口本来不是 key"
    // 这件事盖掉，而真实用户没有那一步。visible/active/key 三个都得成立，手点手敲才有反应。
    $app->loop(3);
    $winState = $app->windowState();
    // 只断言"窗口在屏"。active/key 不当断言用：从终端起的裸可执行文件在用户点它之前本来
    // 就不是前台应用（macOS 不允许后台进程抢焦点），这时 realClick 会退回直投，
    // 由下面的 nsappDeliveries 计数器把这件事照出来。
    $windowUsable = (bool) $winState['visible'];
    // 前台要靠竞争，一次申请可能落空（实测同一个 bundle 二进制两次启动分别量到
    // active=true 与 false/false）。与其让 bundleGreen 看运气，不如在这里有界地把它
    // 争回来：最多 24 轮 activate + loop(2)。争不回来就是真问题（例如这台机器根本没有
    // 窗口会话），那时 FAIL 是对的，不该放宽。
    $activateTries = 0;
    while ($activateTries < 24) {
        $s = $app->windowState();
        if ((bool) $s['active'] && (bool) $s['key']) {
            break;
        }
        $activateTries++;
        $app->activate();
        $app->loop(2);
    }

    // 真输入路径：点击/移动/按键走 AppKit 的正常派发（hit-test + behavior + focus），
    // 不是合成缝。这条验的是所有交互能力的地基——on_ 前缀失配那次，21 条合成链路全绿
    // 而真实鼠标一个事件都进不来，所以只有这条路能照见"监听器根本没挂上"。
    // 门的下限用**实测值**定，不凭空写：有几节就该量到几条导航矩形、每一次都进 PHP、
    // 并且到达那么多个不同节号。这一遍跑在"13 节都渲染过"之后，正是那条原生视图泄漏的
    // 现场——修之前实测只有 7/13：上面 6 行被渲染过 WebView 那节之后留在窗口上的
    // WKWebView 吃掉了，而 gpui 的命中表里 13 条矩形和监听器全都在。
    $navC = navProbe($app);
    $navClicked = $navC[1];
    $sectionsReachable = $navC[2];
    $navRows = count(showcaseSections());
    $navRouted = $navC[0] >= $navRows && $navClicked >= $navRows
        && $sectionsReachable >= $navRows && $navA[1] >= $navRows;

    // 导航行的**横向可点范围**："点了没反应"有可能不是路由坏了，而是落点在按钮命中框之外
    // （侧栏 p(8)、按钮还有内边距，"看上去一整行"不等于命中框那么宽）。
    // 前两版这里恒 0，真因是探针自己：选中行从 ghost 换成 primary 时行高会变，复用旧的 y
    // 就等于每次都点在行缝里（不是浮层挡的，也不是 render 吞点击——那两条解释都没验证过）。
    // 所以这里全程点**当前已经选中的那一行**：section 不变 → 主/副态不变 → 几何全程稳定，
    // 命中判据只用 realClick 的返回值（它本身就是"这一下有没有让 gpui 队列增长"）。
    $bandHits = [];
    $hitsBefore = (int) ($app->inputProbe()['hits'] ?? 0);
    ExampleState::$v['section'] = 1;
    $app->render(showcaseShell());
    $app->loop(2);
    $hitsAfter = (int) ($app->inputProbe()['hits'] ?? 0);
    $bandY = 0;
    for ($y = 40; $y <= 430 && $bandY === 0; $y += 4) {
        if ($app->realClick(100, $y)) {
            $bandY = $y;
        }
    }
    for ($x = 4; $bandY > 0 && $x <= 208; $x += 8) {
        if ($app->realClick($x, $bandY)) {
            $bandHits[] = $x;
        }
    }
    $bandMin = $bandHits === [] ? 0 : min($bandHits);
    $bandMax = $bandHits === [] ? 0 : max($bandHits);
    // 整行都得可点：侧栏 w(210) 减去左右 p(8)，按钮可用宽 194，从 x≈8 起。x 每 8px 采一次，
    // 所以容差留到一档。没做满宽之前这里量到的是 12..132（只有文字那段能点）。
    $navBandWide = count($bandHits) >= 22 && $bandMin <= 12 && $bandMax >= 196;
    // 上面那些**真点击**同时该产出按下与抬起：这是"鼠标事件真的进了宿主队列"的
    // 可观测证明（cmd/patch-mouse-view.sh 把 ScriptView::OnScriptMouse* 从 QuickJS
    // 那条路改接到 gpuiEnqueueMouse）。注意这里必须在 1328 那行清空 evSeen 之前读，
    // 否则读到的是下一次扫描的数据。
    $mouseDownBand = evCount('mouse_down_left');
    $mouseUpBand = evCount('mouse_up_left');
    // 值的形状是"谁 produced 这条事件"的指纹：只有 cmd/patch-mouse-view.sh 那条
    // gpuiEnqueueMouse 会把坐标编成 "x,y,button,clicks,mods"（两个 %.2f + 三个整数）。
    // 反向对照（把补丁从树里摘掉、amalgam 钩子数=0 重编）里进程在真按下那一刻 SIGSEGV
    // （rc=139，没有 .ips 可解析，具体帧未能命名）—— 与 #53 的 key_down 现场同形：
    // ScriptView::OnScriptMouseDown → DispatchMouseButton → QuickJS。
    $mouseSample = '';
    foreach ((array) (ExampleState::$v['evSeen']['mouse_down_left'] ?? []) as $v) {
        $mouseSample = (string) $v;
        break;
    }
    $mouseShape = (bool) preg_match('/^-?\d+\.\d\d,-?\d+\.\d\d,0,\d,\d+$/', $mouseSample);

    $hoverRouted = false;
    $hoverMoves = 0;
    $hoverAtY = 0;
    $hoverAtX = 0;
    ExampleState::$v['section'] = 1;
    ExampleState::$v['hover'] = '—';
    ExampleState::$v['evSeen'] = [];
    $app->render(showcaseShell());
    // 两列 x：pr-hover 那颗胶囊在内容区左侧，600 可能已经越过它
    foreach ([300, 600] as $hx) {
        for ($y = 200; $y <= 690 && !$hoverRouted; $y += 14) {
            if ($app->realMouseMove($hx, $y)) {
                $hoverMoves++;
                $app->loop(2);
                $hoverAtX = $hx;
                $hoverAtY = $y;
            }
            $hoverRouted = ExampleState::$v['hover'] === 'pr-hover';
        }
    }

    // pr-hover 那颗胶囊同时挂了 onMouseMove：上面这串真移动应当产出 mouse_move，
    // 值形如 "x,y,pressed,button,mods"（trace 里能看到具体数字）。
    $moveHits = evCount('mouse_move');
    $mouseOk = $mouseDownBand > 0 && $mouseUpBand > 0 && $moveHits > 0 && $mouseShape;

    $typedIn = false;
    $keyHits = 0;
    $focus = 0;
    ExampleState::$v['section'] = 2;
    ExampleState::$v['name'] = '';
    ExampleState::$v['evSeen'] = [];
    $app->render(showcaseShell());
    // 关键断言：inputProbe()['n'] > 0。gpui 在 paint 里给每颗真 Input 登记
    // inputBounds（gpui.cpp:7815），点击靠它找可聚焦的框。n==0 说明这颗节点根本没被
    // 建成 Input——materialize 对建不出的 retained 是 `return Div(cx->a)`，静默降级成
    // 一颗 Div：不聚焦、不能打字、点它没事件，而 registeredError() 还是空的。
    // 这正是 El::input('x') 把 id 写进 text 而不是 id 字段时的现场。
    $app->loop(2);
    $probe = $app->inputProbe();
    $inputsReal = (int) $probe['n'] > 0;
    $px = (int) $probe['x'] + (int) $probe['w'] / 2;
    $py = (int) $probe['y'] + (int) $probe['h'] / 2;
    if ($inputsReal) {
        $app->realClick($px, $py);
        $app->loop(2);
        if ($app->realKey(9, 'g')) {
            $keyHits++;
        }
        $app->loop(2);
        $typedIn = ExampleState::$v['name'] !== '';
    }
    // 兜底：inputProbe 只报最后一颗的框，多输入框时按列扫。
    // 这里**不拿 focusedId() 当门**：它读的是 win->focusId（WindowFocusedId），而 shell 里
    // 输入框的焦点记在 win->input 上，实测对 retained input 恒 0。判"能不能打字"只能
    // 看打字的结果本身——所以下面只把它当诊断读一下。
    for ($y = 120; $y <= 660 && !$typedIn; $y += 10) {
        $app->realClick(500, $y);
        $app->loop(2);
        $focus = max($focus, $app->focusedId());
        if ($app->realKey(9, 'g')) {   // kVK_ANSI_G
            $keyHits++;
        }
        $app->loop(2);
        $typedIn = ExampleState::$v['name'] !== '';
    }

    // 值回读门（审核 P0-4 的回归位）：App::checked()/elementValue() 过去是恒 false 的空壳
    // （php_gpui_element_value 里一句 `// Phase 4` 直接 return nil）。现在它读的是 shim 随
    // change 事件留下的"最近一次上报值"，所以这里用**真打字**那条路验：上面往 in-name 里
    // 打进去的 'g'，必须能从读口拿回来。走的是 enqueue → rememberValue → bridge → PHP，
    // 与用户手打之后想读值的同一条链。
    $valuePull = $typedIn && str_contains((string) $app->elementValue('in-name'), 'g');

    // 交互模式"点了要看得见"靠的是每帧重算 view：注册一个 view，改状态，下一帧的树
    // 必须换成新节。上面 navRouted 只证明 handler 改到了状态，不证明屏幕会换——
    // 用户看到的"点了没反应"是这两件事里的后者。
    // 顺手给 HUD 一条断言：窗口顶部那行事件计数不能只是"存在"，它得反映真收到的事件，
    // 否则它对用户就是个装饰。先塞一条 click，再要求树上出现"点击 1"。
    ExampleState::$v['evSeen']['click'] = ['nav-5' => true];
    // 与交互模式同一颗每帧回调（含 paint 途中的 activate 抢前台），不是只画 shell 的简化版：
    // 用户面前的节奏里就有那一步，探针必须跑在同一形状上。
    $app->view(static function () use ($app): El { return interactiveFrame($app); });
    ExampleState::$v['section'] = 5;
    // 直接改状态、没有事件：脏门会跳过空帧，所以必须显式标脏，否则下一帧还是旧节。
    $app->invalidate();
    $app->loop(2);
    $treeAfter = \gpui_debug_tree($app->handle());
    $viewRerenders = str_contains($treeAfter, '表格、列表与进度')
        && str_contains($treeAfter, '点击 1');

    // 菜单动作路由：两条来源（元素树里的 El::menuItem、应用级的 App::setAppMenus）声明的
    // action 名，都要能以 `action` 事件回到 PHP。真点原生菜单行那一跳够不到（NSMenu 属于
    // 系统菜单条那个窗口，进程内注入的 NSEvent 只 Deliver 给自己的窗口），所以拆成两段取证：
    // ① 菜单条那张表里装的确实是这些 id（menuRows）；② 该 id 的动作会回到 PHP（dispatchAction）。
    ExampleState::$v['menuCount'] = $app->setAppMenus([
        ['name' => 'Selftest', 'items' => [
            ['label' => '带动作', 'action' => 'st-new'],
            ['separator' => true],
            ['label' => '无动作项'],
        ]],
    ]);
    $menuRows = $app->menuRows();
    // 表里只该有带动作的那一行：分隔符/无动作行混进来说明读表读歪了。
    $menuRowsOk = in_array('st-new', $menuRows, true) && count($menuRows) === 1;
    ExampleState::$v['evSeen']['action'] = [];
    $actDispatched = $app->dispatchAction('cr-m-new') && $app->dispatchAction('st-new');
    $app->loop(2);
    $actionRouted = $actDispatched
        && isset(ExampleState::$v['evSeen']['action']['cr-m-new'])
        && isset(ExampleState::$v['evSeen']['action']['st-new']);

    // 生命周期与只读计数器：这几个没有副作用可验，验的是"读数自洽"。
    $granted = App::storageGranted();
    $hkCount = App::hotkeyCount();
    $menuCnt = $app->appMenuCount();
    $openBefore = $app->isOpen();
    $app->runFrames(3);
    $frames3 = $app->frameCount();
    // stop() 的可观测证明：view 第一帧把自己叫停，loop() 就必须只跑一帧返回。
    $stopFrames = 0;
    $app->view(static function () use ($app, &$stopFrames): El {
        $stopFrames++;
        $app->stop();
        return El::text('stopped');
    });
    // view() 注册时预热一次（首帧没有命中表，见 App::view），那次调用不该算进"循环里的帧数"，
    // 所以先把预热的次数扣掉，再断 loop 之后只多了一帧。
    $primed = $stopFrames;
    $app->loop(50);
    $stoppedAfterOneFrame = ($stopFrames - $primed) === 1;
    // run() 是 create+view+loop 的糖；这里不真调（它会阻塞到窗口关闭），
    // 交互模式用的就是它，见 runInteractive()。
    $lastErr = $app->lastError();

    // 绿得说出自己是哪条路给的，但只硬断言我们能保证的那件事：
    // - bundleGreen：`bin/gpui selftest` 从 bundle 里起进程并置 GPUI_EXPECT_BUNDLE=1，此时
    //   bundled（[NSBundle mainBundle].bundleIdentifier 非空）必须为真——这是产物属性。
    //   frontmost **不**当硬门：前台是和当时握着焦点的一方竞争出来的状态，macOS 会正当拒绝
    //   后台进程抢焦点（实测同一个 bundle 二进制一会儿 active=true、一会儿 false，
    //   activateTries 打满 24 也争不到）。拿它当门就变成随机红灯，所以照实报读数。
    // - routeGreen：已经拿到前台时，事件缝就不许退回直投 -[NSWindow sendEvent:]，走真
    //   NSApp 路由（postEvent → nextEventMatchingMask → -[NSApp sendEvent:]，与手点同源）的
    //   计数必须 > 0。焦点可得时它真会红（负控：钉死该分支 → rc=1、计数 0）。
    // 裸二进制（没有 bundle 身份，比如直接跑 build/$example）两道门都记为"未验"，不装成验过。
    $winEnd = $app->windowState();
    $bundled = (bool) $winEnd['bundled'];
    $frontmost = (bool) $winEnd['active'] && (bool) $winEnd['key'];
    $nsappDeliveries = (int) $winEnd['nsappDeliveries'];
    $bundleGreen = !$expectBundle || $bundled;
    $routeGreen = !($bundled && $frontmost) || $nsappDeliveries > 0;

    $ok = $badSections === []
        && $roleAllOk && $roleThrows
        && $clipBack === 'SHOWCASE-Ω' && $item['text'] === 'SHOWCASE-Ω'
        && $urlRejected === false && $hudOn && $hudVisible
        && $overlayReclaimed && $bdNativeClosed && $bdReclaimed
        && $sheetN > 0 && $hasSheet && $dialogN >= 0 && $dialogCount >= 1 && $trapOk && $cancelClosed
                && $nid > 0 && $notifyCount === 1 && $notifyAfter === 0
        && $menuCount > 0 && $trayId > 0 && $trayCount === 1 && $hk > 0 && $hkFired
        && $theme === ''
        && $mk && $w && $phpSees === "gpui 写的\n第二段"
        && $gpuiRead === "\x00\x89PNG中文"
        && $listing !== [] && !$truncated
        && $outside === null && str_contains($outsideErr, '根') && $rm
        && $echo !== null && str_contains($echo['out'], 'showcase-st')
        && $denied === null && str_contains($deniedErr, '白名单')
        && $storeDenied === false && $sp && $ss && $sg === 'dark' && $sLen === 1
        && $inf === $plainVec && $infGzip === $plainVec && $infBad === null
        && (int) $sys['ncpu'] > 0 && (float) $sys['memPct'] > 0.0
        && $httpQueued && $resp !== null && $resp['ok'] === false
        && $navRouted && $hoverRouted && $typedIn && $inputsReal && $windowUsable && $valuePull
        && $actionRouted && $menuRowsOk && $timerNoGrow && $dirtyGate
        && $bundleGreen && $routeGreen && $navBandWide && $mouseOk
        && $viewRerenders
        && $granted && $menuCnt > 0 && $openBefore && $frames3 === 3
        && $stoppedAfterOneFrame && $lastErr === ''
        && is_array($probe) && $anyFired > 0
        && $errText === '';

    // 一次巨型 sprintf 报 45 个字段：nano 把格式串也算进 arity，占位符与实参差一个就
    // ArgumentCountError，而 selftest 恰恰在最需要输出时炸。改成拼字符串数组，加字段不再有成本。
    // 部件级事件（open_change / confirm / dismiss / step / resize…）的送达探针不在这里跑，
    // 已挪成独立模式 `--probe-events`（见 runEventProbe）：放在 selftest 中途会点开 Select
    // 的弹层，而弹层一开 gpui 就持续重画，把"空转 40 帧只画 1 次"那条 $dirtyGate 弄红
    // ——红的原因与被量的事无关；挪到所有门之后，进程这会儿已不在前台，整段只投递成功 1 次，
    // 变成一个**没有余量的读数**，报出来的 0 会被误读成"路不通"。两个位置都不成立。
    $report = [
        'badSections=' . implode('|', $badSections),
        'roles=' . count(El::ROLES) . '/allOk=' . var_export($roleAllOk, true)
            . ' unknownThrows=' . var_export($roleThrows, true),
        'clip=' . var_export($clipBack, true),
        'notify=' . $nid . '/' . $notifyCount . '/' . $notifyAfter,
        'sheet=' . $sheetN . '/' . var_export($hasSheet, true),
        'overlay churn=' . $churnOpened . ' left=' . $overlayLeft
            . ' boxes=' . $boxesBase . '->' . $boxesAlive
            . ' views=' . $viewsBase . '->' . $viewsAlive
            . ' reclaimed=' . var_export($overlayReclaimed, true),
        'overlay backdrop nativeClosed=' . var_export($bdNativeClosed, true)
            . ' reclaimed=' . var_export($bdReclaimed, true),
        'dialog=' . $dialogN . '/' . $dialogCount
            . ' cancelGate trap=' . $cancelTrap . ' dispatched=' . var_export($cancelDispatched, true)
            . ' closed=' . var_export($cancelClosed, true),
        'escape withControl: open=' . $escProbeOpen . ' trap=' . $escProbeTrap . ' focus=' . $escProbeFocus . ' afterTab=' . $escProbeAfterTab,
        'escape plain: trapOnOpen=' . $plainTrap . ' trapAfterFrames=' . $trapAfterFrames
            . ' focus=' . $focusOnTrap . ' trapOk=' . var_export($trapOk, true)
            . ' esc=' . var_export($escClosed, true) . ' invalidate=' . var_export($escAfterInvalidate, true)
            . ' countAfterPress=' . $countAfterPress . ' focusWas=' . $focusBeforeEsc,
        'tray=' . $trayId . '/' . $trayCount,
        'hk=' . $hk . '/' . var_export($hkFired, true),
        'osn=' . var_export($osn, true) . '(参考：裸二进制无 bundle id)',
        'fs=' . var_export($mk && $w && $rm, true) . ' phpSees=' . var_export($phpSees, true),
        'outside=' . var_export($outside, true) . '(' . $outsideErr . ')',
        'proc=' . var_export($echo !== null && $denied === null, true) . '(' . $deniedErr . ')',
        'store=' . var_export($ss && $sg === 'dark', true) . '/' . var_export($storeDenied, true),
        'granted=' . var_export($granted, true) . ' menus=' . $menuCnt,
        'open=' . var_export($openBefore, true) . ' frames=' . $frames3,
        'stopped=' . var_export($stoppedAfterOneFrame, true) . " lastErr='" . $lastErr . "'",
        'inf=' . var_export($inf === $plainVec && $infGzip === $plainVec && $infBad === null, true),
        'ncpu=' . var_export($sys['ncpu'] ?? null, true),
        'http=' . var_export($httpQueued, true) . '/' . var_export($resp, true),
        'probe=' . var_export($probe, true),
        'any=' . $anyFired . " err='" . $errText . "'",
        'theme=' . var_export($theme, true),
        'hud=' . var_export($hudOn, true) . '/' . var_export($hudVisible, true),
        'win=' . var_export($windowUsable, true)
            . '/atStart=' . var_export((bool) $startState['active'], true)
            . '/' . var_export((bool) $startState['key'], true)
            . '/tryFront=' . var_export($startFront, true)
            . '/' . var_export((bool) $startState2['active'], true)
            . '/' . var_export((bool) $startState2['key'], true)
            . '/active=' . var_export($winState['active'], true)
            . '/key=' . var_export($winState['key'], true)
            . '/visible=' . var_export($winState['visible'], true)
            . '/main=' . var_export($winState['main'], true)
            . ' activateTries=' . $activateTries . ' fgTries=' . $app->foregroundTries()
            . ' bundled=' . var_export($bundled, true) . ' frontmost=' . var_export($frontmost, true)
            . ' nsappDeliveries=' . $nsappDeliveries
            . '/bundleGreen=' . var_export($bundleGreen, true)
            . '/routeGreen=' . var_export($routeGreen, true),
        'action=' . var_export($actionRouted, true) . '/dispatched=' . var_export($actDispatched, true)
            . '/rows=' . implode(',', $menuRows) . '/rowsOk=' . var_export($menuRowsOk, true),
        'valuePull=' . var_export($valuePull, true),
        "timerKeys {$tk0}->{$tk1} noGrow=" . var_export($timerNoGrow, true),
        "dirty idle={$idleRenders}/40frames wake=" . var_export($wakesOnEvent, true)
            . " gate=" . var_export($dirtyGate, true),
        'rerender=' . var_export($viewRerenders, true),
        'realInput=' . var_export($navRouted, true) . '/' . var_export($hoverRouted, true)
            . '/' . var_export($typedIn, true) . ' navHits=' . $navClicked
            . ' hoverMoves=' . $hoverMoves
            . ' evSeen=' . evSeenSummary() . ' keyHits=' . $keyHits . ' focus=' . $focus
            . ' navProbe fresh=' . $navA[1] . '/' . $navA[0]
            . ' afterAll=' . $navC[1] . '/' . $navC[0]
            . ' sectionsReachable=' . $sectionsReachable . '/' . $navRows
            . ' navBand=y' . $bandY . ':' . $bandMin . '..' . $bandMax . '/' . count($bandHits)
            . '/hits=' . $hitsBefore . '→' . $hitsAfter
            . ' mouseProbe down=' . $mouseDownBand . ' up=' . $mouseUpBand
            . ' move=' . $moveHits . '/ok=' . var_export($mouseOk, true)
            . ' downVal=' . $mouseSample
            . ' hoverAt=' . $hoverAtX . ',' . $hoverAtY . ' probe=' . $probe['n'] . '@' . $probe['x'] . ',' . $probe['y'] . ' ' . $probe['w'] . 'x' . $probe['h'] . ' hit=' . $px . ',' . $py,
    ];
    echo ($ok ? 'SHOWCASE SELFTEST OK (' : 'SHOWCASE SELFTEST FAIL (') . implode(' · ', $report) . ")\n";
    $app->close();
    exit($ok ? 0 : 1);
}

/** --shot：出一节的 PNG。tree 0 与 1 同样指第一节（与 shotdiff 的编号习惯一致）。 */
function runShot(string $path, int $tree): void
{
    $sections = showcaseSections();
    $tree = $tree <= 0 ? 1 : (isset($sections[$tree]) ? $tree : 1);
    [$title, $builder] = $sections[$tree];
    ExampleState::boot(['section' => $tree]);

    $app = new App();
    $app->create();
    $app->createWindow('Showcase Shot', 1180, 760);
    wireShowcase($app);
    $app->render(showcaseShell());
    // 通知/浮层类要跑够墙钟才拍得到（入场动画由 50ms 计时器推），这里统一给 80 帧。
    $app->loop(80);

    if ($tree === 8) {
        // 第 8 节的像素证据是"卡片真在窗口里"，所以出图前先推两条不自动退场的通知。
        $app->notify('已保存', '3 个文件写好了', 'success', 'top_right', 0, 'shot-note');
        $app->notify('磁盘将满', '只剩 8 GB', 'warning', 'bottom_left', 0, 'shot-note');
        $app->loop(80);
    }
    echo "--- debug tree (showcase {$tree}: {$title}) ---\n" . \gpui_debug_tree($app->handle()) . "\n---\n";
    $ok = $app->snapshot($path, 1180, 760);
    echo $ok ? "SHOT OK showcase tree={$tree} {$path}\n" : "SHOT FAIL showcase tree={$tree} {$path}\n";
    $app->close();
    exit($ok ? 0 : 1);
}
