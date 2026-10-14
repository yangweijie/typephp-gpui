<?php

declare(strict_types=1);

/**
 * examples/choices/src/main.php — 功能域独立示例：选择、开关与按钮变体。
 *
 * 这一支是 #73 的现场。那条问题（部件自己发的 open_change / confirm / dismiss 到底回不回 PHP）
 * 在画廊里问不出来：画廊的 selftest 末尾才打一次报告，而"弹层一开就持续重画"会先把
 * 画廊的 dirtyGate 弄红——那个探针在画廊里天生自相矛盾。这里两件事分开了：
 * "弹层开没开"是硬门（用重画增量判），"事件回没回"先只报读数。
 *
 * 开关：（无参）交互 · --sections · --shot <path> [tree] · --selftest
 */

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;

const CHOICES_W = 760;
const CHOICES_H = 520;

function main(int $argc, array $argv): void
{
    try {
        choicesDispatch($argv);
    } catch (\Throwable $e) {
        echo 'FATAL ' . get_class($e) . ': ' . $e->getMessage() . "\n";
        exit(12);
    }
}

function choicesDispatch(array $argv): void
{
    if (in_array('--sections', $argv, true)) {
        foreach (choicesTrees() as $tree => [$title, $_fn]) {
            echo $tree . "\t" . $title . "\n";
        }
        return;
    }
    if (in_array('--selftest', $argv, true)) {
        runChoicesSelfTest();
        return;
    }
    $shotIdx = array_search('--shot', $argv, true);
    if ($shotIdx !== false) {
        $path = isset($argv[$shotIdx + 1]) ? (string) $argv[$shotIdx + 1] : '/tmp/choices.png';
        $tree = isset($argv[$shotIdx + 2]) ? (int) $argv[$shotIdx + 2] : 1;
        runChoicesShot($path, $tree);
        return;
    }
    ExampleState::boot(['section' => 3]);
    $app = new App();
    wireChoices($app);
    $app->run('Choices · 选择与按钮变体', CHOICES_W, CHOICES_H, 'choicesTree');
}

function step(string $name, bool $ok, string $detail): void
{
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ' — ' . $detail . "\n";
}

function choicesTree(): El
{
    return El::vFlex([sectionChoices(ExampleState::get())])->sizeFull()->bg(0xF6F3EE);
}

function runChoicesShot(string $path, int $tree): void
{
    ExampleState::boot(['section' => 3]);
    $app = new App();
    $app->create();
    $app->createWindow('Choices Shot', CHOICES_W, CHOICES_H);
    wireChoices($app);
    $app->render(choicesTree());
    $app->loop(20);
    echo '--- debug tree (choices ' . (int) $tree . ') ---'
        . "\n" . \gpui_debug_tree($app->handle()) . "\n---\n";
    $shotOk = $app->snapshot($path, CHOICES_W, CHOICES_H);
    echo $shotOk ? "SHOT OK tree={$tree} {$path}\n" : "SHOT FAIL tree={$tree} {$path}\n";
    $app->close();
    exit($shotOk ? 0 : 1);
}

/** 某一类事件收到过几颗（按不同 id 计）。 */
function evCountOf(string $type): int
{
    return count((array) (ExampleState::$v['evSeen'][$type] ?? []));
}

/** 把 evSeen 压成一行——nano 里没有 json_encode（动态调用未编进的函数是 SIGABRT）。 */
function seenSummary(): string
{
    $parts = [];
    foreach (ExampleState::$v['evSeen'] as $type => $byId) {
        $ids = [];
        foreach ((array) $byId as $id => $value) {
            $ids[] = (string) $id . '=' . (is_bool($value) ? var_export($value, true) : (string) $value);
        }
        $parts[] = (string) $type . '{' . implode(',', $ids) . '}';
    }
    return $parts === [] ? '（空）' : implode(' ', $parts);
}

function runChoicesSelfTest(): void
{
    ExampleState::boot(['section' => 3]);
    $app = new App();
    $app->create();
    $app->createWindow('Choices Selftest', CHOICES_W, CHOICES_H);
    wireChoices($app);
    $app->render(choicesTree());
    $app->loop(4);

    $ok = true;
    echo "CHOICES SELFTEST\n";

    // 1) radio / toggle / 两组 group / 六颗按钮 / select / combobox / datePicker 全建得出来。
    $err = $app->registeredError();
    step('registeredError', $err === '', $err === '' ? '空' : $err);
    $ok = $ok && $err === '';
    $cf = App::componentFailure();
    step('componentFailure', (int) $cf['count'] === 0, 'count=' . (int) $cf['count']
        . ' last=' . (string) $cf['last']);
    $ok = $ok && (int) $cf['count'] === 0;

    // 2) 五个变体糖各发一条 registered_method（#66 的回归位）：
    //    以前它们发的是会被 ResolveBehavior 直接跳过的形状（materialize.cpp:314
    //    `if (op.kind != Method) continue;`），于是"按上去一个像素都不动"。
    $variants = [];
    foreach (['p' => 'primary', 's' => 'secondary', 'd' => 'danger',
              'g' => 'ghost', 'o' => 'outline'] as $k => $name) {
        $btn = El::catalogButton('btn-' . $k, $name)->onClick();
        $btn->{$name}();
        $found = false;
        foreach ($btn->ops as $op) {
            if ($op['kind'] === 'registered_method' && $op['name'] === $name) {
                $found = true;
            }
        }
        if (!$found) {
            $variants[] = $name;
        }
    }
    step('variantOps', $variants === [],
        $variants === [] ? '五颗各一条 registered_method' : '没发出去的=' . implode('/', $variants));
    $ok = $ok && $variants === [];

    // 3) kind 原语上调变体必须当场抛（#66 的另一半：静默丢比抛更坏）。
    $threw = false;
    $thrownMsg = '';
    try {
        El::button('默认(kind)')->id('btn-x')->primary();
    } catch (\InvalidArgumentException $e) {
        $threw = true;
        $thrownMsg = $e->getMessage();
    }
    $guardOk = $threw && El::catalogButton('btn-y', 'ok')->onClick()->primary() !== null;
    step('kindButtonGuard', $guardOk, 'kind 上调抛=' . var_export($threw, true)
        . ' 原因提到 catalog=' . var_export(str_contains($thrownMsg, 'catalog'), true));
    $ok = $ok && $guardOk;

    // 4)+5) 不猜坐标：把命中表里每一条矩形各点一次，用"点完之后 20 帧画了几次"找出是哪一条
    //    把弹层开的。为什么不按 ->w(180) 认触发器：首轮实测 paint.hits 有 17 条，
    //    但**没有一条宽在 170–185**——catalog Select 交出去的可点矩形不是那个 180 的盒子，
    //    按声明宽度筛就是 0 条，而拿这个 0 去取第 0 个元素就是那次 rc=6。
    //    按"点了之后有没有持续重画"反查则不需要任何几何假设：gpui 的弹层一开就持续重画
    //    （这个过去把画廊 dirtyGate 弄红的现象，反过来就是"popup 真开了"的读数）。
    $rendersBefore = $app->renderCount();
    $app->loop(20);
    $idle = $app->renderCount() - $rendersBefore;
    $total = (int) ($app->hitProbe()['n'] ?? 0);
    $deltas = [];
    $dump = [];
    $best = 0;
    $bestIdx = -1;
    for ($i = 0; $i < $total; $i++) {
        $h = $app->hitProbe($i);
        if ((int) $h['w'] <= 0 || (int) $h['h'] <= 0) {
            continue;
        }
        $dump[] = $i . ':' . (int) $h['x'] . ',' . (int) $h['y'] . 'x'
            . (int) $h['w'] . 'x' . (int) $h['h'];
        $before = $app->renderCount();
        $app->realClick((int) $h['x'] + (int) $h['w'] / 2, (int) $h['y'] + (int) $h['h'] / 2);
        $app->loop(20);
        $d = $app->renderCount() - $before;
        $deltas[$i] = $d;
        if ($d > $best) {
            $best = $d;
            $bestIdx = $i;
        }
        if ($d > $idle) {
            // 开过弹层就把它关掉再继续，否则后面每一条都落在"一直重画"的噪声上。
            $app->realKey(53, '');
            $app->loop(6);
        }
    }
    step('hitSweep(读数)', true, '空转 20 帧画=' . $idle . ' 命中表=' . $total . ' 条 ['
        . implode(' ', $dump) . ']');
    step('popupRepaints(读数)', true, '重画增量最大的是第 ' . $bestIdx . ' 条=' . $best
        . ' 每条=' . implode('/', array_values($deltas)));

    // 6) 部件级事件送达：#73 那一格。handler 已经在 wireChoices 里挂上（三颗各三条），
    //    所以这里能真的读出"回没回 PHP"。上面那一轮点+Escape 的循环就是它的全部机会。
    //    仍**只报数**：它是这条链自己的结论，量到了下一轮再升级成硬门。
    $app->dispatchAction('ui::Confirm');
    $app->loop(6);
    $app->dispatchAction('ui::Cancel');
    $app->loop(6);
    step('componentEventDelivery(读数)', true,
        'open_change=' . evCountOf('open_change') . ' confirm=' . evCountOf('confirm')
        . ' dismiss=' . evCountOf('dismiss') . ' step=' . evCountOf('step')
        . ' resize=' . evCountOf('resize') . ' evSeen=' . seenSummary());

    // 标着 (读数) 的三条不参与 $ok：popupRepaints 要真点击与前台窗口，
    // componentEventDelivery 现在就是 #73 的已知未定状态——拿"没量到"当红灯只会变成噪声。
    // 弹层可能还开着——关掉再收尾，免得 close() 撞上 P0-mem 那支析构顺序。
    $app->realKey(53, '');
    $app->loop(4);
    $app->close();
    echo ($ok ? 'CHOICES SELFTEST OK' : 'CHOICES SELFTEST FAIL') . "\n";
    exit($ok ? 0 : 1);
}
