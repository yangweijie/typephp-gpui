<?php

declare(strict_types=1);

/**
 * examples/mac/src/main.php — 功能域独立示例：mac 系统集成（托盘 / 全局快捷键 / Dock / OS 通知）。
 *
 * 这一域的证据大多在系统界面之外：原生菜单条在屏幕顶（--shot 抓不到）、Dock 角标在程序坞、
 * OS 通知走通知中心。所以这里的门全是"读数与状态"，而拆小之后新多出来的一条是
 * **HOTKEY_MODS 四个修饰键逐个注册、再逐个撤销**——画廊里只注册过一条 ⌘⇧ 的，
 * 剩下三个组合从来没被钉住过。
 *
 * 开关：（无参）交互 · --sections · --shot <path> [tree] · --selftest
 */

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;

const MAC_W = 820;
const MAC_H = 460;

function main(int $argc, array $argv): void
{
    try {
        macDispatch($argv);
    } catch (\Throwable $e) {
        echo 'FATAL ' . get_class($e) . ': ' . $e->getMessage() . "\n";
        exit(12);
    }
}

function macDispatch(array $argv): void
{
    if (in_array('--sections', $argv, true)) {
        foreach (macTrees() as $tree => [$title, $_fn]) {
            echo $tree . "\t" . $title . "\n";
        }
        return;
    }
    if (in_array('--selftest', $argv, true)) {
        runMacSelfTest();
        return;
    }
    $shotIdx = array_search('--shot', $argv, true);
    if ($shotIdx !== false) {
        $path = isset($argv[$shotIdx + 1]) ? (string) $argv[$shotIdx + 1] : '/tmp/mac.png';
        runMacShot($path);
        return;
    }
    ExampleState::boot(['section' => 13]);
    $app = new App();
    wireMac($app);
    $app->run('Mac · 托盘 / 快捷键 / Dock / 通知', MAC_W, MAC_H, 'macTree');
}

function step(string $name, bool $ok, string $detail): void
{
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ' — ' . $detail . "\n";
}

function macTree(): El
{
    return El::vFlex([sectionMac(ExampleState::get())])->sizeFull()->bg('#f6f3ee');
}

function runMacShot(string $path): void
{
    ExampleState::boot(['section' => 13]);
    $app = new App();
    $app->create();
    $app->createWindow('Mac Shot', MAC_W, MAC_H);
    wireMac($app);
    $app->render(macTree());
    $app->loop(20);
    echo '--- debug tree (mac 1) ---' . "\n" . \gpui_debug_tree($app->handle()) . "\n---\n";
    $shotOk = $app->snapshot($path, MAC_W, MAC_H);
    echo $shotOk ? "SHOT OK tree=1 {$path}\n" : "SHOT FAIL tree=1 {$path}\n";
    $app->close();
    exit($shotOk ? 0 : 1);
}

function runMacSelfTest(): void
{
    ExampleState::boot(['section' => 13]);
    $app = new App();
    $app->create();
    $app->createWindow('Mac Selftest', MAC_W, MAC_H);
    wireMac($app);
    $app->render(macTree());
    $app->loop(3);

    $ok = true;
    echo "MAC SELFTEST\n";

    // 1) 这一页的 catalog 按钮（含 HOTKEY_MODS 那一排）都建得出来。
    $err = $app->registeredError();
    step('registeredError', $err === '', $err === '' ? '空' : $err);
    $ok = $ok && $err === '';
    $cf = App::componentFailure();
    step('componentFailure', (int) $cf['count'] === 0, 'count=' . (int) $cf['count']
        . ' last=' . (string) $cf['last']);
    $ok = $ok && (int) $cf['count'] === 0;

    // 2) 托盘一个来回：装上 ⇒ 在场数增加；撤掉 ⇒ 回到原值。
    //    trayId 必须非零——0 是"没装成"，而 Cocoa 那边装失败是不抛的。
    $trayBase = App::trayCount();
    $trayId = $app->tray(['甲', '乙', '退出'], 'mac-tray-item', 'mac 示例托盘');
    $during = App::trayCount();
    $app->removeTray($trayId);
    $after = App::trayCount();
    $trayOk = $trayId > 0 && $during === $trayBase + 1 && $after === $trayBase;
    step('trayLifecycle', $trayOk, 'id=' . $trayId . ' 在场 ' . $trayBase . '→' . $during
        . '→' . $after);
    $ok = $ok && $trayOk;

    // 3) HOTKEY_MODS 的**每个**修饰键组合各注册一条再各撤一条。
    //    画廊的按钮会把每个组合都注册一遍，但没有任何断言逐个数过它们——
    //    这里不点按钮、直接注册，所以 id>0 与"撤完在场数回落"是逐条钉住的。
    //    变量名各用各的：nano 在同一作用域里只允许一个名字有一种类型，而
    //    `foreach ($ids as $k => $v)` 把值推成 php::Var 之后再赋 int 就是编译期 fatal
    //    ——这一支第一轮就是这么死在编译上的，`php -l` 完全不报。
    $hkBase = App::hotkeyCount();
    $hkIds = [];
    $modTotal = 0;
    $badMod = [];
    foreach (array_keys(App::HOTKEY_MODS) as $modName) {
        $modTotal++;
        $newId = $app->onHotkey('mac-hk-' . $modName, 110, [$modName]);
        if ($newId <= 0) {
            $badMod[] = $modName;
            continue;
        }
        $hkIds[] = $newId;
    }
    $grown = App::hotkeyCount();
    foreach ($hkIds as $dropId) {
        $app->removeHotkey($dropId);
    }
    $hkOk = $badMod === [] && $modTotal > 0 && $grown >= $hkBase + $modTotal
        && App::hotkeyCount() <= $hkBase;
    step('hotkeyEveryMod', $hkOk, '组合数=' . $modTotal . ' 在场 ' . $hkBase . '→' . $grown
        . '→' . App::hotkeyCount()
        . ($badMod === [] ? '' : ' 注册失败的=' . implode('/', $badMod)));
    $ok = $ok && $hkOk;

    // 4) 快捷键的派发链：注册一条 + 挂 handler，然后用 fireHotkeyForTest 那条缝打进去。
    //    原生热键在无人值守下点不到（它是系统级事件），所以这条门量的是
    //    "动作 → shim 钩子 → 队列 → dispatch → handler" 整条路，不量 Carbon 本身。
    //    它必须排在 3) 之后：那时表里已经空了，index 0 才是我这条。
    $hits = 0;
    $app->on('mac-hk-fired', 'hotkey', function () use (&$hits): void {
        $hits++;
    });
    $firedId = $app->onHotkey('mac-hk-fired', 105, ['cmd', 'shift']);
    $fired = App::fireHotkeyForTest(0);
    $app->loop(3);
    $dispatchOk = $firedId > 0 && $fired && $hits >= 1;
    step('hotkeyDispatch', $dispatchOk, 'id=' . $firedId . ' fired=' . var_export($fired, true)
        . ' handler 命中=' . $hits);
    $ok = $ok && $dispatchOk;
    $app->removeHotkey($firedId);

    // 5) app 菜单条：装上去要能读回条数，行里那个 action 名要能在**注册表**里查到
    //    （真点击进不了门禁——NSMenu 属于另一个窗口，所以这里读的是"行与 id 对得上"）。
    $menuN = $app->setAppMenus([
        ['name' => 'Mac', 'items' => [
            ['label' => '关于', 'action' => 'mac-about'],
            ['separator' => true],
            ['label' => '退出', 'action' => 'mac-quit'],
        ]],
        ['name' => 'Help', 'items' => [['label' => '文档', 'action' => 'mac-doc']]],
    ]);
    $rows = $app->menuRows();
    $menuOk = $menuN >= 1 && $app->appMenuCount() >= 1 && in_array('mac-about', $rows, true);
    step('appMenus', $menuOk, 'setAppMenus=' . $menuN . ' appMenuCount=' . $app->appMenuCount()
        . ' rows=' . implode('/', $rows));
    $ok = $ok && $menuOk;

    // 6) Dock 角标与 OS 通知只报读数：dockBadge 是 void、osNotify 的返回值取决于
    //    系统通知权限（本机可能拒），都不构成可断言的框架行为。
    App::dockBadge('7');
    $withBadge = ExampleState::$v['dock'];
    App::dockBadge('');
    step('dockAndOsNotify(读数)', true, '角标设过 7 再清空；osNotify='
        . var_export(App::osNotify('mac 示例', '这条走操作系统通知中心'), true));

    $app->close();
    echo ($ok ? 'MAC SELFTEST OK' : 'MAC SELFTEST FAIL') . "\n";
    exit($ok ? 0 : 1);
}
