<?php

declare(strict_types=1);

/**
 * examples/input/src/main.php — 功能域独立示例：输入与保留态取值。
 *
 * 存在理由（与 showcase 的分工）：showcase 是**画廊**，一次跑 13 节、报告只在末尾打印一次，
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
    $shotIdx = array_search('--shot', $argv, true);
    if ($shotIdx !== false) {
        $path = isset($argv[$shotIdx + 1]) ? (string) $argv[$shotIdx + 1] : '/tmp/input.png';
        $tree = isset($argv[$shotIdx + 2]) ? (int) $argv[$shotIdx + 2] : 1;
        runShot($path, $tree);
        return;
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

function runInteractive(): void
{
    ExampleState::boot(['section' => 2]);
    $app = new App();
    wireInputs($app);
    $app->run('Input · 保留态取值', INPUT_W, INPUT_H, 'inputTree');
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
    //    顺带把"每一颗都打得进字"量全（showcase 的 --livetyping 对整个画廊做同一件事）。
    //    key_down 也在这里量：合成缝不认 key_down（见 4)），真键是它唯一的路（#53 的回归位）。
    //    判据用打字的结果本身，**并且**焦点也当门：这里以前写着"focusedId() 对 retained
    //    input 实测恒 0，拿它当门会假红"，2026-10-14 复量推翻了三句里的两句——见下面
    //    focusFollowsClick 的读数。
    //    这条在 CI 上是**裸二进制**跑的：showcase 的 --liveclick/--livetyping 同样如此
    //    并实测通过，所以它够格当硬门；不成立就是真回归，不是环境抖动。
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

    // 焦点跟着点击走：每颗 retained input 各自一个非零实体 id，且互不相同。
    // 这条 2026-10-14 才敢立——旧注释写着"focusedId() 对 retained input 实测恒 0，
    // 拿它当门会假红"，复量的读数是 0→-1001 1→-1002 2→-1003 3→-1004，那句已经不成立。
    // 断的是**关系**（非零 + 逐颗互不相同），不断具体数字：实体 id 由 gpui 按创建顺序发，
    // 加一颗输入框就会整体挪位。
    $focusOk = $focTried >= 2 && count($focIds) === $focTried && !isset($focIds[0]);
    step('focusFollowsClick', $focusOk, '点过 ' . $focTried . ' 颗，不同焦点 id '
        . count($focIds) . ' 个 [' . $focDump . ']');
    $ok = $ok && $focusOk;

    // 值回读门（审核 P0-4 的回归位）：elementValue() 读的是 shim 随 change 事件留下的
    // "最近一次上报值"。上面真打字进 in-name 的那个 'g' 必须能从读口拿回来——
    // enqueue → rememberValue → bridge → PHP，与用户手打之后想读值走的是同一条链。
    $pullBack = $typedIn && str_contains((string) $app->elementValue('in-name'), 'g');
    step('valuePull', $pullBack, 'elementValue(in-name)='
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
    //    事件而不返回（showcase 的探针栽过一次，实测 rc=124 被看门狗掐掉）。
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

    $app->close();
    echo ($ok ? 'INPUT SELFTEST OK' : 'INPUT SELFTEST FAIL') . "\n";
    exit($ok ? 0 : 1);
}
