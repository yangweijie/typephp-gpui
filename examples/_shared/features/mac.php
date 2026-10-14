<?php

declare(strict_types=1);

/**
 * examples/_shared/features/mac.php — 功能域「mac 系统集成：托盘 · 全局快捷键 · Dock · OS 通知」。
 *
 * 从 showcase 的 §13 搬来（builder + 全部 mc-* 接线 + 自报的像素树号）。
 * 这一域的"对不对"大部分在系统界面之外（菜单栏在屏幕顶、Dock 角标在程序坞、
 * OS 通知通知中心），--shot 拍不到，所以它的证据是读数与状态：
 * trayCount / hotkeyCount / fireHotkeyForTest / appMenuCount / menuRows。
 */

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;

/** 本域的像素树清单：树号 → [标题, builder]。 */
function macTrees(): array
{
    return [1 => ['mac 集成：托盘 · 全局快捷键 · Dock · OS 通知', 'sectionMac']];
}

function sectionMac(array $s): El
{
    // HOTKEY_MODS 全部走一遍：每个修饰键组合一颗按钮，注册成真全局快捷键。
    $hkBtns = [];
    foreach (array_keys(App::HOTKEY_MODS) as $mod) {
        $hkBtns[] = El::catalogButton('mc-hk-' . $mod, 'HK·' . $mod)->onClick()->ghost();
    }

    return El::vFlex([
        El::text('mac 集成：托盘 · 全局快捷键 · Dock · OS 通知')->font(22.0)->semibold(),
        El::text('托盘/快捷键/Dock/OS 通知都经 gpui_mac.mm 的 Cocoa+Carbon 直路，不碰 gpui.h。')->font(11.0)->truncate(),
        El::hFlex([
            El::catalogButton('mc-tray', '装托盘（A/B 两项）')->onClick()->primary(),
            El::catalogButton('mc-tray-off', '撤掉托盘')->onClick()->ghost(),
            El::text("trayId={$s['trayId']} 在场=" . App::trayCount())->font(12.0),
        ])->gap(8.0)->itemsCenter(),
        El::text('托盘项被点 ⇒ 事件 type=tray，值=菜单项文本（无人值守下用 fireHotkeyForTest 那条缝验派发链）。')->font(11.0)->truncate(),
        El::hFlex([
            El::catalogButton('mc-hk-1', '注册 ⌘⇧F7')->onClick()->secondary(),
            El::catalogButton('mc-hk-2', '注册 ⌥F8')->onClick()->ghost(),
            El::catalogButton('mc-hk-3', '注册 ⌃F9')->onClick()->ghost(),
            El::catalogButton('mc-hk-off', '撤最近一条')->onClick()->ghost(),
            El::text("hkId={$s['hotkeyId']} 在场=" . App::hotkeyCount() . " 命中={$s['hotkeyHits']} mods={$s['hotkeyMods']}")->font(12.0),
        ])->gap(8.0)->itemsCenter(),
        El::hFlex($hkBtns)->gap(6.0)->flexWrap(),
        El::text('上面一排按 HOTKEY_MODS 的每个键各注册一条（cmd/shift/option/control 全覆盖）。')->font(11.0),
        El::hFlex([
            El::catalogButton('mc-dock-7', 'Dock 角标 7')->onClick()->ghost(),
            El::catalogButton('mc-dock-off', 'Dock 角标清掉')->onClick()->ghost(),
            El::catalogButton('mc-osn', 'OS 通知')->onClick()->outline(),
            El::text("badge={$s['dock']} osNotify={$s['osNotify']}")->font(12.0),
        ])->gap(8.0)->itemsCenter(),
        El::hFlex([
            El::catalogButton('mc-menus', '装 app 菜单（含分隔线与子菜单）')->onClick()->primary(),
            El::text("menuCount=" . $s['menuCount'])->font(12.0),
        ])->gap(8.0)->itemsCenter(),
    ])->sizeFull()->gap(12.0)->p(16.0)->overflowYScroll();
}

/** 本域元素的接线。 */
function wireMac(App $app): void
{
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
}
