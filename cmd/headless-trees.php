<?php

declare(strict_types=1);

use TypePHP\Gpui\App;
use TypePHP\Gpui\FakeBridge;

/*
 * 无头（FakeBridge）把一支示例的每一棵树建一遍，并核"注册到元素的 handler，id 要真在树里"。
 *
 * 为什么一支示例单起一个进程：nano 的入口约定是每支 main.php 都定义 main()/step()，
 * 一起 require 就是 redeclare fatal。
 *
 * 为什么要有这个脚本：这两条门过去由 tests/ShowcaseTest.php 借画廊顺手做掉。画廊按功能域拆完
 * 删掉之后它们没有主人，而"Linux 上就能发现示例被改坏"这件事不该等一次 90 秒的原生构建——
 * 原生侧的 registeredError/componentFailure 由各示例的 --selftest 负责，两边不互相替代。
 *
 * 用法：php cmd/headless-trees.php <example>
 */

$example = (string) ($argv[1] ?? '');
$repo = dirname(__DIR__);
$yml = $repo . '/examples/' . $example . '/project.macos.yml';
if ($example === '' || !is_file($yml)) {
    fwrite(STDERR, "用法：php cmd/headless-trees.php <example>（找不到 {$example}）\n");
    exit(2);
}

require_once $repo . '/vendor/autoload.php';

/** 收集一棵渲染树里出现过的所有字符串：事件路由键不止在 id 字段上。 */
function headlessTreeStrings(array $node): array
{
    $seen = [];
    $stack = [$node];
    while ($stack !== []) {
        $n = array_pop($stack);
        foreach ($n as $v) {
            if (is_array($v)) {
                $stack[] = $v;
            } elseif (is_string($v) && $v !== '') {
                $seen[$v] = true;
            }
        }
    }

    return array_keys($seen);
}

/**
 * 注册到元素上的 handler 里，哪些 id 在任何一棵树里都找不到（写了没人接的线）。
 *
 * timer/http/dirChange/tray/hotkey/close 的 id 是各自通道的键，本来就不是元素 id；
 * '*' 是 onAny 的通配槽；通知卡 id 由 notify() 的实参带过去，不在元素树里。
 * dock 面板 spec 可能被整块序列化成一个字符串，所以只对那一份放宽成"包含即算见过"——
 * 放宽不作用于普通树字符串，否则短 id 会被任意长文本顺带命中，这条门就白写了。
 *
 * @param array<string, bool>  $strings    树里出现过的字符串
 * @param list<string>         $panelBlobs 整块序列化的面板 spec
 * @return list<string>        "<id>:<type>" 清单
 */
function headlessOrphans(App $app, array $strings, array $panelBlobs): array
{
    $elementTypes = ['click', 'change', 'hover', 'key_down'];
    $offTreeIds = ['ov-note'];
    $seen = static function (string $id) use ($strings, $panelBlobs): bool {
        if (isset($strings[$id])) {
            return true;
        }
        foreach ($panelBlobs as $blob) {
            if (str_contains($blob, $id)) {
                return true;
            }
        }

        return false;
    };
    $handlers = (new ReflectionProperty(App::class, 'handlers'))->getValue($app);
    $orphans = [];
    foreach ((array) $handlers as $id => $byType) {
        if ($id === '*') {
            continue;
        }
        foreach (array_keys((array) $byType) as $type) {
            if (in_array($type, $elementTypes, true)
                && !in_array((string) $id, $offTreeIds, true)
                && !$seen((string) $id)) {
                $orphans[] = $id . ':' . $type;
            }
        }
    }

    return $orphans;
}

$dir = dirname($yml);
$sources = [];
foreach (explode("\n", (string) file_get_contents($yml)) as $line) {
    if (!preg_match('/^\s+-\s+(\S+\.php)\s*$/', $line, $m)) {
        continue;
    }
    $abs = realpath($dir . '/' . $m[1]);
    if ($abs === false) {
        continue;
    }
    // 原生声明桩不能在这里 require：composer 的 autoload-dev.files 已经把 FakeBridge 的
    // 实现装成全局 gpui_*，再要一遍就是 Cannot redeclare。
    if (str_ends_with($abs, 'gpui.stub.php')) {
        continue;
    }
    $sources[] = $abs;
}
foreach ($sources as $src) {
    require_once $src;
}
// hello 那类不共享状态表的示例：ExampleState 是这条门自己要用的，缺了就补上。
if (!class_exists('ExampleState', false)) {
    require_once $repo . '/examples/_shared/state.php';
}

// get_defined_functions 给的是小写名。
$user = get_defined_functions()['user'];
$tables = array_values(array_filter(
    $user,
    static fn (string $f): bool => (bool) preg_match('/\w+trees$/', $f)
));
$wires = array_values(array_filter(
    $user,
    static fn (string $f): bool => (bool) preg_match('/^wire\w+$/', $f) && $f !== 'wireeventtrace'
));

FakeBridge::reset();
ExampleState::boot();
$app = new App();
$app->create();
$window = $app->createWindow('headless', 900, 640);
foreach ($wires as $wire) {
    $wire($app);
}

$problems = [];
$strings = [];
$built = 0;foreach ($tables as $table) {
    foreach ($table() as $tree => [$title, $builder]) {
        if (!is_callable($builder)) {
            $problems[] = $table . ':' . $tree . '（builder 不可调用）';
            continue;
        }
        ExampleState::$v['section'] = (int) $tree;
        // 各域的 builder 签名不一致：接状态的传 state，不接的裸调（传多了在 nano 里是
        // ArgumentCountError，这里按反射决定，不给它骗人的机会）。
        $params = (new ReflectionFunction($builder))->getNumberOfParameters();
        $el = $params >= 1 ? $builder(ExampleState::get()) : $builder();
        $node = $el->toArray();
        $app->render($el);
        $built++;
        $found = headlessTreeStrings($node);
        if ($found === []) {
            $problems[] = $table . ':' . $tree . '（' . (string) $title . '）渲染出来是空树';
        }
        foreach ($found as $s) {
            $strings[$s] = true;
        }
    }
}

$renders = count(FakeBridge::$renderLog[(int) $window] ?? []);
if ($built > 0 && $renders !== $built) {
    $problems[] = 'render 次数 ' . $renders . ' 与树数 ' . $built . ' 对不上';
}

// Dock 面板的 spec 存在 DockState 里，不在窗口渲染树里（原生侧由 layout --selftest 的
// dockPanelCallback 真点它来证明送达）。这里把 FakeBridge 记下的面板描述也折进"见过的字符串"，
// 否则那颗面板按钮会被当成写了没人接的线——是这条门自己的盲区，不是示例的错。
// 面板 spec 可能被整块序列化成一个字符串，所以这一份单独留着，只对它放宽成"包含即算见过"；
// 放宽绝不作用于普通树字符串——那会让短 id 被任意长文本顺带命中，孤儿门就白写了。
$panelBlobs = [];
foreach (FakeBridge::$docks as $dock) {
    foreach ((array) ($dock['panels'] ?? []) as $panel) {
        if (is_array($panel)) {
            foreach (headlessTreeStrings($panel) as $s) {
                $strings[$s] = true;
            }
        } elseif (is_string($panel) && $panel !== '') {
            $panelBlobs[] = $panel;
        }
    }
}

// 接线孤儿：注册到元素上的 handler，其 id 必须真出现在某一节的树里。
// timer/http/dirChange/tray/hotkey/close 的 id 是各自通道的键，本来就不是元素 id；
// '*' 是 onAny 的通配槽；通知卡 id 由 notify() 的实参带过去，不在元素树里。
$orphans = headlessOrphans($app, $strings, $panelBlobs);

if ($orphans !== []) {
    $problems[] = '写了没人接的线=' . implode('/', $orphans);
}

// --self-check：这条门自己的对照臂。凭空挂一颗"任何树里都没有"的 handler，
// 它必须被上面那个函数点名——不然"0 孤儿"可能只是因为门根本没在看。
if (in_array('--self-check', $argv, true)) {
    $app->on('zz-not-in-any-tree', 'click', static function (): void {});
    $probe = headlessOrphans($app, $strings, $panelBlobs);
    $caught = in_array('zz-not-in-any-tree:click', $probe, true);
    echo 'SELF-CHECK ' . ($caught ? 'OK' : 'FAIL')
        . ' 凭空挂的孤儿被点名=' . var_export($caught, true)
        . ' 检出=' . implode('/', $probe) . "\n";
    if (!$caught) {
        exit(1);
    }
}

if ($problems !== []) {
    echo 'HEADLESS TREES FAIL ' . $example . ' trees=' . $built . "\n";
    foreach ($problems as $p) {
        echo '  - ' . $p . "\n";
    }
    exit(1);
}

echo 'HEADLESS TREES OK ' . $example . ' trees=' . $built
    . ' renders=' . $renders . ' 接线=' . count($wires) . " 源=" . count($sources) . "\n";
exit(0);
