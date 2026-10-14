<?php

declare(strict_types=1);

/**
 * examples/chrome/src/main.php — 功能域独立示例：侧栏、表单与菜单。
 *
 * 这一域两条最容易被"看着能用"骗到的地方：命名槽（header/footer 走 slot，塞进 children
 * 会静默丢掉）与 registered_method 的通道（collapsible/navIcon/align 名字对但打在
 * kind 节点上就等于没打，见 #66）。第三条是目录菜单那条动作回 PHP 的路（#33）。
 *
 * 开关：（无参）交互 · --sections · --shot <path> [tree] · --selftest
 */

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;

const CHROME_W = 900;
const CHROME_H = 620;

function main(int $argc, array $argv): void
{
    try {
        chromeDispatch($argv);
    } catch (\Throwable $e) {
        echo 'FATAL ' . get_class($e) . ': ' . $e->getMessage() . "\n";
        exit(12);
    }
}

function chromeDispatch(array $argv): void
{
    if (in_array('--sections', $argv, true)) {
        foreach (chromeTrees() as $tree => [$title, $_fn]) {
            echo $tree . "\t" . $title . "\n";
        }
        return;
    }
    if (in_array('--selftest', $argv, true)) {
        runChromeSelfTest();
        return;
    }
    $shotIdx = array_search('--shot', $argv, true);
    if ($shotIdx !== false) {
        $path = isset($argv[$shotIdx + 1]) ? (string) $argv[$shotIdx + 1] : '/tmp/chrome.png';
        runChromeShot($path);
        return;
    }
    ExampleState::boot(['section' => 7]);
    $app = new App();
    wireChrome($app);
    $app->run('Chrome · 侧栏 / 表单 / 菜单', CHROME_W, CHROME_H, 'chromeTree');
}

function step(string $name, bool $ok, string $detail): void
{
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ' — ' . $detail . "\n";
}

function chromeTree(): El
{
    return El::vFlex([sectionChrome(ExampleState::get())])->sizeFull()->bg(0xF6F3EE);
}

function runChromeShot(string $path): void
{
    ExampleState::boot(['section' => 7]);
    $app = new App();
    $app->create();
    $app->createWindow('Chrome Shot', CHROME_W, CHROME_H);
    wireChrome($app);
    $app->render(chromeTree());
    $app->loop(20);
    echo '--- debug tree (chrome 1) ---' . "\n" . \gpui_debug_tree($app->handle()) . "\n---\n";
    $shotOk = $app->snapshot($path, CHROME_W, CHROME_H);
    echo $shotOk ? "SHOT OK tree=1 {$path}\n" : "SHOT FAIL tree=1 {$path}\n";
    $app->close();
    exit($shotOk ? 0 : 1);
}

function runChromeSelfTest(): void
{
    ExampleState::boot(['section' => 7]);
    $app = new App();
    $app->create();
    $app->createWindow('Chrome Selftest', CHROME_W, CHROME_H);
    wireChrome($app);
    $app->render(chromeTree());
    $app->loop(4);

    $ok = true;
    echo "CHROME SELFTEST\n";

    // 1) sidebar / form / field / dropdownMenu / menuItem 全是 registered 组件，
    //    建不出来只画占位、不失败整帧，所以两条读数都要看。
    $err = $app->registeredError();
    step('registeredError', $err === '', $err === '' ? '空' : $err);
    $ok = $ok && $err === '';
    $cf = App::componentFailure();
    step('componentFailure', (int) $cf['count'] === 0, 'count=' . (int) $cf['count']
        . ' last=' . (string) $cf['last']);
    $ok = $ok && (int) $cf['count'] === 0;

    // 2) 命名槽就是命名槽：header/footer 走 slot。
    //    这里传**空** menus，children 就必须真的是 0——塞成 children 的话头部根本不出现。
    //    （first run 我拿 §7 那颗带 menus 的 sidebar 来断言 children==0，那是断言过头：
    //    El::sidebar($id, $menus) 的第二个实参本来就是往 children 里放的。）
    $sb = El::sidebar('ch-slot', [])
        ->slot('header', El::sidebarHeader(El::text('头部')))
        ->slot('footer', El::sidebarFooter(El::text('底部')));
    $slots = [];
    foreach ($sb->ops as $op) {
        if ($op['kind'] === 'slot') {
            $slots[] = (string) $op['name'];
        }
    }
    $slotOk = $slots === ['header', 'footer'] && $sb->children === [];
    step('namedSlots', $slotOk, '槽=' . implode('/', $slots) . ' children=' . count($sb->children));
    $ok = $ok && $slotOk;

    // 3) 三个 registered_method 的名字：collapsible / icon / align。
    //    它们必须在编出来的产物里发得出去——上游对 registered 节点之外的节点是
    //    `if (op.kind != Method) continue;`（materialize.cpp:314），一个像素都不动。
    $rmOf = static function (El $el): array {
        $out = [];
        foreach ($el->ops as $op) {
            if ($op['kind'] === 'registered_method') {
                $out[] = (string) $op['name'];
            }
        }
        return $out;
    };
    $rmSidebar = $rmOf(El::sidebar('ch-a', [])->collapsibleMode('icon')->collapsed(false));
    $rmItem = $rmOf(El::sidebarMenuItem('Home')->navIcon('home')->selected(true));
    $rmField = $rmOf(El::field([El::text('字段')])->align('end')->description('说明'));
    $rmOk = in_array('collapsible', $rmSidebar, true) && in_array('icon', $rmItem, true)
        && in_array('align', $rmField, true);
    step('registeredMethodNames', $rmOk, 'sidebar=' . implode('/', $rmSidebar)
        . ' item=' . implode('/', $rmItem) . ' field=' . implode('/', $rmField));
    $ok = $ok && $rmOk;

    // 4) 枚举全走一遍：COLLAPSE_MODES 与 FIELD_ALIGN 的每个取值都真发一条同名实参的方法。
    $modeBad = [];
    foreach (El::COLLAPSE_MODES as $modeName) {
        if (!in_array('collapsible', $rmOf(El::sidebar('ch-m', [])->collapsibleMode($modeName)), true)) {
            $modeBad[] = $modeName;
        }
    }
    $alignBad = [];
    foreach (El::FIELD_ALIGN as $alignName) {
        if (!in_array('align', $rmOf(El::field([El::text('x')])->align($alignName)), true)) {
            $alignBad[] = $alignName;
        }
    }
    $enumOk = $modeBad === [] && $alignBad === [];
    step('enumSweeps', $enumOk, 'collapse 没发出去的=' . implode('/', $modeBad)
        . ' align 没发出去的=' . implode('/', $alignBad)
        . '（' . count(El::COLLAPSE_MODES) . '+' . count(El::FIELD_ALIGN) . ' 个取值）');
    $ok = $ok && $enumOk;

    // 5) 目录菜单行的动作回到 PHP。**回来的事件类型是 action，不是 click**——
    //    搬过来的 §7 接线只注册了 click（那是 DropdownMenu.item 那条路），画廊里这条
    //    是靠 onAny('action') 通配记录器才被看见的；第一轮我就拿 click handler 去等它，
    //    于是 dispatched=true 而 lastBtn 一动不动。所以这里在示例内自己挂一颗 action handler，
    //    量的才是"动作名 → shim 钩子 → 队列 → dispatch → handler"整条路（#33）。
    $seenType = '';
    $app->on('cr-m-new', 'action', function (array $e): void {
        ExampleState::$v['lastBtn'] = 'menu:' . (string) ($e['id'] ?? '');
    });
    $before = (string) ExampleState::$v['lastBtn'];
    $sent = $app->dispatchAction('cr-m-new');
    $event = \gpui_poll_event($app->handle());
    if (!empty($event)) {
        $seenType = (string) ($event['type'] ?? '');
        $app->dispatch($event);
    }
    $after = (string) ExampleState::$v['lastBtn'];
    $menuOk = $sent && $seenType === 'action' && $after !== $before;
    step('menuActionToPhp', $menuOk, 'dispatched=' . var_export($sent, true)
        . ' 回来的 type=' . ($seenType === '' ? '（队列里没有事件）' : $seenType)
        . ' lastBtn:' . $before . '→' . $after);
    $ok = $ok && $menuOk;

    $app->close();
    echo ($ok ? 'CHROME SELFTEST OK' : 'CHROME SELFTEST FAIL') . "\n";
    exit($ok ? 0 : 1);
}
