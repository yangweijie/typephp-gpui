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
        // 同一页并排画两颗输入框，是为了让"裸框"这件事可看可比：上面那颗是 shell 的
        // `ComponentKind::Input`（materialize 走不带样式的 InputBase::New，天生无框），
        // 下面这颗是 catalog 的 `Input`（El::catalogInput，#81）——带边框/底色/焦点态，
        // 代价是构造要先有一颗 InputState 保留实体。验收看两点：componentFailure 仍为 0
        // （建不出来会渲染成 "Failed to render X" 并计数）+ inputProbe 的颗数多一颗。
        El::catalogInput('in-cat', 'catalog Input（带样式）', '初值来自 state'),
        El::text($s['name'] !== '' ? "Hello, {$s['name']}!" : '还没输入')->font(13.0)->italic(),
        // onKeyDown：回调 op 名是 on_key_down，bridge 剥掉 "on_" 前缀后事件类型是 key_down
        // （cpp-src/gpui_bridge.cc:49），所以 handler 注册用 $app->on(id, 'key_down')。
        El::input('in-key')->placeholder('在这里按键（onKeyDown）')->onKeyDown()->onKeyUp(),
        El::text("keyDown={$s['key']}")->font(11.0),
        El::textarea('in-note')->value('多行文本：textarea 也是保留态的')->onChange(),
        El::text($s['note'] !== '' ? "备注 {$s['note']} 个字符" : '备注还没改')->font(11.0),
        El::hFlex([
            // ± 那颗组件自己不做标签：`NumberStepButton`（materialize.cpp:960-983）造的
            // 是 `Button::New(cx, id, …)`——第二个参数是 **id 不是文字**，且它只从
            // `SlotId(node,"decrement_button"/"increment_button")` 那个 spec 节点取样式与子元素。
            // 上游把这两颗做成【装饰槽】（`IsRegisteredCommonSlot` 里就列着这两个名字），
            // 宿主不给内容就是一颗空按钮：0x0、不落字、点不到。2026-10-14 之前这里没填，
            // 于是 `step` 的送达无从量起（见 #79）。
            El::numberInput('in-num')->value('42')->placeholder('数字')->onChange()->onStep()
                ->slot('decrement_button', El::button('－')->id('num-dec'))
                ->slot('increment_button', El::button('＋')->id('num-inc')),
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
        // catalog 的带样式表单族（入口是 #81，这一族是 #82）：八颗的构造都**只吃一个
        // `state` 实参**，而那颗实体原本只有 JS 绑定函数能造（要 live script scope），
        // 现在由宿主经 El::state() 造。跟上面 kind 版那几颗并排，是为了"带不带框"
        // 在同一页可比。
        //
        // 取值回读**只覆盖 kind 版**：App::text()/checked() 读的是 shim 的 inputHandles
        // 表，catalog 那颗不在里面。所以这一族的验收是"建成 + 有矩形 + 实体数不涨"
        // （examples/input 的 catalogFamily* 三条门），不是"值回得来"。
        El::text('catalog 表单族（每颗都要一颗宿主造的 state 实体）')->font(12.0),
        El::hFlex([
            El::catalogNumberInput('in-cnum', 'catalog NumberInput', '7'),
            El::catalogOtpInput('in-cotp', 4),
        ])->gap(12.0),
        El::hFlex([
            El::catalogSlider('in-cslider', 35.0),
            El::text('catalog Slider(35)')->font(12.0),
        ])->gap(12.0)->itemsCenter(),
        El::hFlex([
            El::catalogColorPicker('in-ccolor'),
            El::catalogTimeField('in-ctime'),
        ])->gap(12.0),
        El::catalogDatePicker('in-cdate'),
        El::catalogCalendar('in-ccal'),
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
