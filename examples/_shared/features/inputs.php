<?php

declare(strict_types=1);

/**
 * examples/_shared/features/inputs.php — 功能域「输入与保留态取值」。
 *
 * 从 showcase 的 §2 原样搬来：一棵树（builder）+ 接这棵树的线（wiring）+ 自报的像素树号表。
 * showcase 的 yml 与本域独立示例（examples/input）的 yml 都列这一份文件，所以
 * "展示了 input/textarea/numberInput/otpInput/slider/checkbox/switch 的保留态" 只有一份真源。
 *
 * id 前缀 `in-` 是事件路由键，全域唯一，别在别的域里复用。
 */

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;

/** 本域的像素树清单：树号 → [标题, builder]。独立示例用它自报 --sections。 */
function inputsTrees(): array
{
    return [1 => ['输入与保留态取值', 'sectionInputs']];
}

function sectionInputs(array $s): El
{
    return El::vFlex([
        El::text('输入与保留态取值')->font(22.0)->semibold(),
        El::text('input / textarea / numberInput / otpInput：值由 gpui 持有，PHP 用 text(id) 取回')->font(12.0),
        El::input('in-name')->placeholder('你的名字')->onChange(),
        El::text($s['name'] !== '' ? "Hello, {$s['name']}!" : '还没输入')->font(13.0)->italic(),
        // onKeyDown：回调 op 名是 on_key_down，bridge 剥掉 "on_" 前缀后事件类型是 key_down
        // （cpp-src/gpui_bridge.cc:49），所以 handler 注册用 $app->on(id, 'key_down')。
        El::input('in-key')->placeholder('在这里按键（onKeyDown）')->onKeyDown()->onKeyUp(),
        El::text("keyDown={$s['key']}")->font(11.0),
        El::textarea('in-note')->value('多行文本：textarea 也是保留态的')->onChange(),
        El::text($s['note'] !== '' ? "备注 {$s['note']} 个字符" : '备注还没改')->font(11.0),
        El::hFlex([
            El::numberInput('in-num')->value('42')->placeholder('数字')->onChange()->onStep(),
            El::otpInput('in-otp')->placeholder('4 位')->onChange(),
        ])->gap(12.0),
        El::hFlex([
            El::slider(0.0, 100.0, (float) $s['vol'])->id('in-vol')->onChange(),
            El::text("vol={$s['vol']}")->font(12.0)->textRight(),
        ])->gap(10.0)->itemsCenter(),
        El::hFlex([
            El::checkbox('同意', false)->id('in-agree')->onChange(),
            El::switchBox('深色开关', false)->id('in-dark')->onChange(),
            El::text($s['agree'] ? 'checkbox 已勾选' : 'checkbox 未勾选')->font(12.0),
        ])->gap(12.0),
    ])->sizeFull()->gap(10.0)->p(16.0)->overflowYScroll();
}

/** 把本域元素的事件接进 App。handler 写 ExampleState，下一帧的 builder 读到。 */
function wireInputs(App $app): void
{
    $app->on('in-name', 'change', function (array $e) use ($app): void {
        ExampleState::$v['name'] = (string) ($e['value'] ?? '');
    });
    $app->on('in-note', 'change', function (array $e): void {
        ExampleState::$v['note'] = (string) strlen((string) ($e['value'] ?? ''));
    });
    $app->on('in-vol', 'change', function (array $e): void {
        ExampleState::$v['vol'] = (int) ($e['value'] ?? 0);
    });
    $app->on('in-agree', 'change', function () use ($app): void {
        ExampleState::$v['agree'] = $app->checked('in-agree');
    });
    $app->on('in-dark', 'change', function () use ($app): void {
        ExampleState::$v['theme'] = $app->checked('in-dark') ? 'dark' : 'light';
    });
    $app->on('in-key', 'key_down', function (array $e): void {
        ExampleState::$v['key'] = (string) ($e['value'] ?? '?');
    });
    // NumberInput 的 ± 步进。上游把它做成"点按钮"而不是键位，所以这条只能经真点击量；
    // 载荷是字符串 increment/decrement（cmd/patch-select-view.sh 起才带着值到 PHP）。
    $app->on('in-num', 'step', function (array $e): void {
        ExampleState::$v['lastStep'] = (string) ($e['value'] ?? '');
    });
}
