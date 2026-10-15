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
    $waitIdx = array_search('--wait-key', $argv, true);
    if ($waitIdx !== false) {
        // 真人键盘验收：秒数钳在 5..600，给一个不至于手慢就判失败的窗口。
        $secs = isset($argv[$waitIdx + 1]) ? (int) $argv[$waitIdx + 1] : 30;
        runWaitKey(max(5, min(600, $secs)));
        return;
    }
    if (in_array('--trace', $argv, true)) {
        runInteractive(true);
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
                . "可用：--sections / --selftest / --shot <path> [tree] / --wait-key <秒> / --trace\n";
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

function runInteractive(bool $trace = false): void
{
    ExampleState::boot(['section' => 2, 'trace' => $trace]);
    $app = new App();
    wireInputs($app);
    if ($trace) {
        wireEventTrace($app);
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
 * 所以这里一条就能覆盖四类；注册必须在 render 之前（回调 id 在 materialize 时定死）。
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
    while (microtime(true) < $deadline) {
        $app->loop(1);
        $frames++;
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
                echo 'WAIT-KEY-ARM focusElement(in-name)=' . $app->focusElement('in-name')
                    . ' focus=' . $app->focusedId() . "\n";
            }
        }
        if (inEvCount('key_down') > 0 || inEvCount('key_up') > 0) {
            $sawKey = true;
            // 按到了再多收几帧，让 Escape / 连续按键都进读数。
            $deadline = min($deadline, microtime(true) + 8);
        }
    }
    $stEnd = $app->windowState();
    echo 'WAIT-KEY seconds=' . $seconds . ' frames=' . $frames
        . ' key_down=' . inEvCount('key_down') . ' key_up=' . inEvCount('key_up')
        . ' click=' . inEvCount('click') . ' change=' . inEvCount('change')
        . ' focus=' . $app->focusedId() . ' seen=' . ($sawKey ? 'yes' : 'no')
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
    } elseif (inEvCount('key_down') === 0) {
        $verdict = '有焦点却没收到 key_down——派发那一层丢了（查 keyDown→宿主入队）';
    }
    echo 'WAIT-KEY-VERDICT ' . $verdict . ' 事件增量=' . $deltaAppkit
        . '（开局 ' . $baseAppkit . ' → 结束 ' . $endAppkit . "）\n";
    $app->close();
    exit($sawKey ? 0 : 1);
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
    $sweep = [];
    $focIds = [];
    $focTried = 0;
    $focDump = '';
    for ($i = 0; $i < $n; $i++) {
        $b = $app->inputProbe($i);
        if ((int) $b['w'] <= 0) {
            continue;
        }
        $app->realClick((int) $b['x'] + (int) $b['w'] / 2, (int) $b['y'] + (int) $b['h'] / 2);
        $app->loop(2);
        // 点完之后 gpui 认为谁在焦点上。以前这里写着"focusedId() 对 retained input 恒 0，
        // 拿它当门会假红"——2026-10-14 复量已经不成立（每颗都回一个稳定的负数实体 id），
        // 所以先把逐颗读数打出来，再判它够不够格当门。
        $foc = $app->focusedId();
        $focIds[$foc] = true;
        $focTried++;
        $focDump .= ($focDump === '' ? '' : ' ') . $i . '→' . $foc;
        $pressed = $app->realKey(9, 'g');        // kVK_ANSI_G
        $app->loop(2);
        $sweep[] = $i . ':' . var_export($pressed, true) . '/f' . $foc;
        if ($typedIdx < 0 && (string) ExampleState::$v['name'] !== '') {
            $typedIdx = $i;
        }
        if ($keyIdx < 0 && (string) ExampleState::$v['key'] !== '—') {
            $keyIdx = $i;
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
    // 判据带的是值本身，不只是"来过"：increment/decrement 是这颗事件唯一的载荷。
    $stepOk = $stepIdx >= 0
        && ($stepVal === 'increment' || $stepVal === 'decrement')
        && (string) $app->elementValue('in-num') === $stepVal;
    step('stepDelivered', $stepOk, '命中表=' . $hits . ' 条 [' . implode(' ', $stepTried)
        . '] 落点索引=' . $stepIdx . ' lastStep=' . var_export($stepVal, true)
        . ' elementValue(in-num)=' . var_export($app->elementValue('in-num'), true));
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

    $app->close();
    echo ($ok ? 'INPUT SELFTEST OK' : 'INPUT SELFTEST FAIL') . "\n";
    exit($ok ? 0 : 1);
}
