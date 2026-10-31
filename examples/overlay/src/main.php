<?php

declare(strict_types=1);

/**
 * examples/overlay/src/main.php — 功能域独立示例：浮层（dialog / sheet / toast / 通知）。
 *
 * 为什么这一域特别值得单独一支：#62/#73/#74 那几条门（焦点陷阱、ui::Cancel、Escape、
 * dialog 析构）都在浮层上，而它们在画廊里挤在 1800 行的 --selftest 中段——那边报告只在末尾
 * 打印一次，任何一处崩都是"无声退非零"（上一轮的 rc=11 就是这么没的）。这里每步一行，
 * 崩在哪行就停在哪行。
 *
 * 开关：
 *   （无参）              交互模式
 *   --sections           自报像素树清单
 *   --shot <path> [tree] 出图
 *   --selftest           本域硬门（全是确定性动作，不需要真 OS 输入）
 *   --escape             #62 的原始场景单独隔离：进程里第一个 dialog、纯文本、直接按 Escape。
 *                        这是**探针不是门**——它 rc=1 说的是"真按键没送达"（#56 那一格仍未判），
 *                        不参与 --selftest 的 rc，免得把环境前提当回归。
 */

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;

const OVERLAY_W = 900;
const OVERLAY_H = 620;

function main(int $argc, array $argv): void
{
    try {
        overlayDispatch($argv);
    } catch (\Throwable $e) {
        echo 'FATAL ' . get_class($e) . ': ' . $e->getMessage() . "\n";
        exit(12);
    }
}

function overlayDispatch(array $argv): void
{
    if (in_array('--sections', $argv, true)) {
        foreach (overlaysTrees() as $tree => [$title, $_fn]) {
            echo $tree . "\t" . $title . "\n";
        }
        return;
    }
    if (in_array('--selftest', $argv, true)) {
        runSelfTest();
        return;
    }
    if (in_array('--escape', $argv, true)) {
        runEscapeProbe();
        return;
    }
    $shotIdx = array_search('--shot', $argv, true);
    if ($shotIdx !== false) {
        $path = isset($argv[$shotIdx + 1]) ? (string) $argv[$shotIdx + 1] : '/tmp/overlay.png';
        $tree = isset($argv[$shotIdx + 2]) ? (int) $argv[$shotIdx + 2] : 1;
        runShot($path, $tree);
        return;
    }
    runInteractive();
}

/** 边跑边打：一步一行。 */
function step(string $name, bool $ok, string $detail): void
{
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ' — ' . $detail . "\n";
}

function overlayTree(): El
{
    return El::vFlex([sectionOverlays(ExampleState::get())])->sizeFull()->bg('#f6f3ee');
}

function runInteractive(): void
{
    ExampleState::boot(['section' => 8]);
    $app = new App();
    wireOverlays($app);
    $app->run('Overlay · dialog / sheet / toast / 通知', OVERLAY_W, OVERLAY_H, 'overlayTree');
}

function runShot(string $path, int $tree): void
{
    $trees = overlaysTrees();
    $tree = isset($trees[$tree]) ? $tree : 1;
    ExampleState::boot(['section' => 8]);

    $app = new App();
    $app->create();
    $app->createWindow('Overlay Shot', OVERLAY_W, OVERLAY_H);
    wireOverlays($app);
    $app->render(overlayTree());
    // 通知入场是动画（Starting 段 opacity = elapsedMs/400，由 50ms 墙钟计时器推），
    // 只跑几帧等于拍在 0 上，所以给 80 帧跑到静止。
    $app->loop(80);
    // 与画廊同一张证据：两条不自动退场（timeout 0）的通知，各占一角——同一角会叠成
    // collapsed stack，分开才拍得出两张完整的卡。
    $app->notify('已保存', '3 个文件写好了', 'success', 'top_right', 0, 'shot-note');
    $app->notify('磁盘将满', '只剩 8 GB', 'warning', 'bottom_left', 0, 'shot-note');
    $app->loop(80);
    echo '--- debug tree (overlay ' . $tree . ': ' . (string) $trees[$tree][0] . ') ---'
        . "\n" . \gpui_debug_tree($app->handle()) . "\n---\n";
    $ok = $app->snapshot($path, OVERLAY_W, OVERLAY_H);
    echo $ok ? "SHOT OK tree={$tree} {$path}\n" : "SHOT FAIL tree={$tree} {$path}\n";
    $app->close();
    exit($ok ? 0 : 1);
}

/**
 * --escape：#62 的原始场景。进程里第一个 dialog、纯文本、不去点它，直接按 Escape。
 * 单独一条模式的原因（沿用画廊那边的结论）：selftest 里那段测的是"另一个 dialog 周期之后"
 * 的状态，反向对照在 selftest 上照样绿——真正的读数得靠这一格。
 */
function runEscapeProbe(): void
{
    ExampleState::boot(['section' => 8]);
    $app = new App();
    wireOverlays($app);
    $app->create();
    $app->createWindow('Overlay Escape', OVERLAY_W, OVERLAY_H);
    $app->keepTryingForeground(12);
    // 与交互模式同一形状：每帧重建 view，Escape 面对的是用户面前的节奏。
    $app->view(static function (): El { return overlayTree(); });
    $app->loop(6);
    $opened = $app->openDialog(El::text('进程里第一个框，纯文本')->p(10.0));
    $trap = $app->focusTrap();
    $app->loop(10);
    // 关键分岔：陷阱是"永远不生效"还是"要几帧后才生效"。前者说明 arm/apply 那条链
    // 在我们宿主里根本没走通，后者只是时序。
    $trapAfterFrames = $app->focusTrap();
    $app->realKey(53, '');                // kVK_Escape
    $app->loop(8);
    $closed = $app->dialogCount() === 0;
    echo 'ESCAPE-FIRST opened=' . $opened . ' trapAfterOpen=' . $trap
        . ' trapAfterFrames=' . $trapAfterFrames
        . ' focus=' . $app->focusedId() . ' closed=' . var_export($closed, true) . "\n";
    exit($closed ? 0 : 1);
}

function runSelfTest(): void
{
    ExampleState::boot(['section' => 8]);
    $app = new App();
    $app->create();
    $app->createWindow('Overlay Selftest', OVERLAY_W, OVERLAY_H);
    wireOverlays($app);
    $app->render(overlayTree());
    $app->loop(3);

    $ok = true;
    echo "OVERLAY SELFTEST\n";

    // 1) 原生侧没有报错节点、组件失败计数没动。通知层与 catalog 按钮都是 registered 组件，
    //    建不出来时 gpui 不失败整帧，只画 "Failed to render X"，所以两条都要看。
    $err = $app->registeredError();
    step('registeredError', $err === '', $err === '' ? '空' : $err);
    $ok = $ok && $err === '';
    $cf = App::componentFailure();
    step('componentFailure', (int) $cf['count'] === 0, 'count=' . (int) $cf['count']
        . ' last=' . (string) $cf['last']);
    $ok = $ok && (int) $cf['count'] === 0;

    // 2) dialog 的焦点陷阱：开框 → 推进几帧 → 陷阱非零；**窗口真持有焦点时**再断焦点就是那颗陷阱。
    //    （#62：机制级实测通。反控做过——把 loop(10) 挪到读数之后会 trap=0/focus 负数。）
    //    "$focus === $trap" 这一半依赖窗口能当上 key/main：托管 runner 两次同 commit 都是
    //    active=false/key=false（task_plan 17-D），在那里它量的是"系统给不给焦点"，属 17-E 那一格，
    //    不是回归。所以跟 hello 的 bundleGreen 同一个形状：前提不成立记"未验"，trap 注册仍硬断。
    $opened = $app->openDialog(El::text('浮层 selftest 框')->p(12.0));
    $app->loop(10);
    $trap = $app->focusTrap();
    $focus = $app->focusedId();
    $winOv = $app->windowState();
    $focusHeld = (bool) ($winOv['key'] ?? false) || (bool) ($winOv['main'] ?? false);
    $trapOk = $opened >= 1 && $trap !== 0 && (! $focusHeld || $focus === $trap);
    step('focusTrap', $trapOk, 'opened=' . $opened . ' dialogCount=' . $app->dialogCount()
        . ' trap=' . $trap . ' focusedId=' . $focus
        . ' focusHeld=' . var_export($focusHeld, true));
    $ok = $ok && $trapOk;

    // 3) ui::Cancel 关框（#74 的回归位）：动作名有**两张表**——菜单/脚本声明的动作被 intern 成
    //    "shell::<id>"，而 gpui 内建的 Confirm/Cancel 是 base/actions.cpp 用不带前缀的名字 intern 的。
    //    shim 派发时两张都试（cpp-src/gpui_shim_services.inc），所以这条完全不依赖真按键。
    $dispatched = $app->dispatchAction('ui::Cancel');
    $closedByCancel = $dispatched && $app->dialogCount() === 0;
    step('cancelClosesDialog', $closedByCancel, 'dispatched=' . var_export($dispatched, true)
        . ' dialogCount=' . $app->dialogCount());
    $ok = $ok && $closedByCancel;

    // 4) closeDialog 那条路也要能把框收干净（开→关→计数归零），并且陷阱随之不再有。
    $again = $app->openDialog(El::text('第二条：closeDialog')->p(12.0));
    $threw = $app->closeDialog();
    $closedClean = $again >= 1 && $threw && $app->dialogCount() === 0;
    step('closeDialog', $closedClean, 'opened=' . $again . ' closed=' . var_export($threw, true)
        . ' dialogCount=' . $app->dialogCount());
    $ok = $ok && $closedClean;

    // 5) 每个 anchor 各开一次 sheet 并关掉：openSheet 计数、hasSheet 读数、closeSheet 回执。
    //    串着开不开新的那种错，只有逐个 anchor 各自开→各自关才量得出来。
    $bad = [];
    $seen = [];
    foreach (App::SHEET_ANCHORS as $a) {
        $n = $app->openSheet(El::text('Sheet·' . $a)->p(12.0), $a);
        $has = $app->hasSheet();
        $shut = $app->closeSheet();
        $seen[] = $a . '=' . $n . '/' . var_export($has, true);
        if ($n < 1 || !$has || !$shut) {
            $bad[] = $a . '(n=' . $n . ' has=' . var_export($has, true)
                . ' close=' . var_export($shut, true) . ')';
        }
    }
    step('sheetPerAnchor', $bad === [], '仍在场的 sheet=' . ($app->hasSheet() ? '1' : '0')
        . ' ' . implode(' ', $seen)
        . ($bad === [] ? '' : ' 坏点=' . implode('/', $bad)));
    $ok = $ok && $bad === [];

    // 6) 通知层：每种 type 推一条（带 timeout，会自己退场），计数必须随推随长。
    //    这条量的是"通知卡真进了窗口自持的那份状态"，不是"函数没炸"。
    $growBad = [];
    $lastCount = 0;
    foreach (App::NOTICE_TYPES as $t) {
        $app->notify('通知·' . $t, 'type 决定图标与配色', $t, 'top_right', 5000, 'ov-note');
        $now = $app->notificationCount();
        if ($now <= $lastCount) {
            $growBad[] = $t . '=' . $now;
        }
        $lastCount = $now;
    }
    step('notifyPerType', $growBad === [], '在场=' . $lastCount
        . ' 类型数=' . count(App::NOTICE_TYPES)
        . ($growBad === [] ? '' : ' 没长的=' . implode('/', $growBad)));
    $ok = $ok && $growBad === [];

    // 7) 清场：`clearNotifications()` 之后**立刻**读与**泵过帧**再读各记一次。
    //    为什么两条都要：卡片退场是动画（不透明度由 50ms 墙钟计时器推），
    //    而 notificationCount() 读的是那份自持状态里还有几张。首轮实测"清完立刻读=12"，
    //    当时分不清"清场不动"与"退场还没走完"，所以这里把两个读数都留下来，
    //    断言只钉在泵过帧之后那个（它是用户真正会看到的数）。
    $app->clearNotifications();
    $instant = $app->notificationCount();
    $app->loop(40);
    $afterPump = $app->notificationCount();
    step('clearNotifications', $afterPump === 0, '立刻读=' . $instant
        . ' 泵 40 帧后=' . $afterPump);
    $ok = $ok && $afterPump === 0;

    // 8) 八个锚点各钉一张（timeout 0 = 不自动退场），条数必须正好等于锚点数。
    //    这条要的是"从干净状态开始数"，所以放在清场之后——首轮那条 FAIL
    //    （在场=12/8）就是没先清场：12 = 上面 4 条 5 秒超时的还没退 + 这 8 条。
    foreach (App::NOTICE_PLACEMENTS as $p) {
        $app->notify('换角', 'placement=' . $p, 'warning', $p, 0, 'ov-note');
    }
    $placed = $app->notificationCount();
    $want = count(App::NOTICE_PLACEMENTS);
    step('placements', $placed === $want, '在场=' . $placed . '/' . $want);
    $ok = $ok && $placed === $want;
    $app->clearNotifications();
    $app->loop(40);

    // 9) 关掉框再渲染一帧，确认窗口没被 dialog 的拆除流程弄塌
    //    （P0-mem 那轮踩过 double-free：close 之后自己 EntityDrop，ShellRoot 又 drop 一次）。
    $app->render(overlayTree());
    $app->loop(3);
    $tailErr = $app->registeredError();
    $tailOk = $tailErr === '' && (int) App::componentFailure()['count'] === 0;
    step('teardownClean', $tailOk, 'err=' . ($tailErr === '' ? '空' : $tailErr));
    $ok = $ok && $tailOk;

    // 10) overlay 回收（审核第 5 步③的回归位，从画廊迁来）：反复开/关 dialog、反复换 sheet，
    //     包装条数必须回到基线。修之前每次开关都净增一条 Box2 包装与一条 g_views 键，
    //     而那条键指向的 PhpView 早已被上游的层析构释放——长会话里就是只增不减的悬垂表。
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
    $churnAfter = $app->stateCounts();
    $churnOk = $churnOpened >= 25 && (int) ($churnAfter['dialogs'] ?? -1) === 0
        && (int) ($churnAfter['boxesAlive'] ?? -1) === $boxesBase
        && (int) ($churnAfter['viewsAlive'] ?? -1) === $viewsBase;
    step('overlayChurnReclaim', $churnOk, '开过=' . $churnOpened
        . ' boxes=' . $boxesBase . '→' . (int) ($churnAfter['boxesAlive'] ?? -1)
        . ' views=' . $viewsBase . '→' . (int) ($churnAfter['viewsAlive'] ?? -1)
        . ' dialogs=' . (int) ($churnAfter['dialogs'] ?? -1));
    $ok = $ok && $churnOk;

    // 11) 原生关掉的那一条路（点背板 → ShellDialogLayer::OnBackdrop → Close）不经过本 shim 的
    //     closeDialog，所以包装栈会与上游脱钩：多出来的那份包装对应的 PhpView 已被上游层
    //     析构释放（root.cpp:209），留着就是"下次同地址复用时 boxForView 交出已死 Box2"。
    //     这条量的是下一次 dialogCount() 轮询必须把它对齐收掉。
    //     落点取内容区偏上（surface 在正中，那里只能是背板）；别拿窗口边角当背板：
    //     窗口 resize 边上 AppKit 的 _mouseHysteresisCheck: 会等下一个事件而不返回。
    $app->openDialog(El::text('bd-dismiss me')->p(10.0), false, true);
    $app->loop(2);
    $app->realClick(intdiv(OVERLAY_W, 2) + 120, 120);
    $app->loop(2);
    $bdClosed = $app->dialogCount() === 0;
    $bdCounts = $app->stateCounts();
    $bdOk = $bdClosed && (int) ($bdCounts['boxesAlive'] ?? -1) === $boxesBase
        && (int) ($bdCounts['viewsAlive'] ?? -1) === $viewsBase;
    step('backdropNativeClose', $bdOk, 'nativeClosed=' . var_export($bdClosed, true)
        . ' boxes=' . (int) ($bdCounts['boxesAlive'] ?? -1) . '/' . $boxesBase
        . ' views=' . (int) ($bdCounts['viewsAlive'] ?? -1) . '/' . $viewsBase);
    $ok = $ok && $bdOk;

    $app->close();
    echo ($ok ? 'OVERLAY SELFTEST OK' : 'OVERLAY SELFTEST FAIL') . "\n";
    exit($ok ? 0 : 1);
}
