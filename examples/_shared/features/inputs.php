<?php

declare(strict_types=1);

/**
 * examples/_shared/features/inputs.php — 功能域「输入与保留态取值」。
 *
 * 从原画廊（已删除）的 §2 原样搬来：一棵树（builder）+ 接这棵树的线（wiring）+ 自报的像素树号表。
 * 本域独立示例（examples/input）的 yml 列这一份文件——画廊删除后它是唯一收录方，
 * "展示了 input/textarea/numberInput/otpInput/slider/checkbox/switch 的保留态" 以这里为唯一真源
 * （checkbox 由 checked() 回读、selftest 的 checkboxRoute 把住；switch 的可见回路是切主题
 * in-dark → setTheme，selftest 的 darkRoute 把住）。
 *
 * id 前缀 `in-` 是事件路由键，全域唯一，别在别的域里复用。
 */

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;

/** 本域的像素树清单：树号 → [标题, builder]。--sections 只读标题；cmd/headless-trees.php
 *  按 builder 列无头建树量孤儿，所以第二元素不是死数据（inputTree() 的硬调之外它有第二个消费者）。 */
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
            // in-num 只挂 onStep、in-otp 连 change 都不声明：wireInputs 没挂这两颗的
            // change handler，树上的 on_change 声明会让合成 fire 入队却在 dispatch 时
            // 静默丢弃（App::handleEvent 只查挂了的表），是纯噪音；值回读由 selftest
            // 的 retainedRoundtrip（setInput→text 回环）与 stepDelivered 各自把住。
            El::numberInput('in-num')->value('42')->placeholder('数字')->onStep()
                ->slot('decrement_button', El::button('－')->id('num-dec'))
                ->slot('increment_button', El::button('＋')->id('num-inc')),
            El::otpInput('in-otp')->placeholder('4 位'),
            // 批3：格数可配——El::otpInput 第二参经桥内 otp_length op 传进
            // gpui_shim_retained_otp（上游 CreateRetainedOtp 本就按任意 length 建）。
            El::otpInput('in-otp6', 6)->placeholder('6 位'),
        ])->gap(12.0),
        El::hFlex([
            El::slider(0.0, 100.0, (float) $s['vol'])->id('in-vol')->onChange(),
            El::text("vol={$s['vol']}")->font(12.0)->textRight(),
        ])->gap(10.0)->itemsCenter(),
        El::hFlex([
            // 批3：量程与初值不再静默丢弃——slider_min/slider_max 由桥在建 retained
            // 时读走传给 gpui_shim_retained_slider，初值用公开的 SliderSetValue 补
            // （CreateRetainedSlider 恒从 min 起）。回读门见 input selftest 的
            // sliderRangeInitial（App::text('in-sld2') 走 shim 新开的 slider 分支）。
            El::slider(10.0, 90.0, 40.0)->id('in-sld2'),
            El::text('量程 10..90 · 初值 40')->font(12.0)->textRight(),
        ])->gap(10.0)->itemsCenter(),
        El::hFlex([
            // 勾选态必须从 **state** 读，不能写死 false：App::run 的 view 闭包每帧重建，
            // 点选后 handler 写回 ExampleState::$v['agree']，下一帧 builder 读到 true →
            // bridge 下发 checked op → materialize 建 CheckboxState::Checked → 画勾。
            // 写死 false 等于「点了有事件、屏上永远不勾」（bug C 的根因）。
            El::checkbox('同意', (bool) $s['agree'])->id('in-agree')->onChange(),
            El::switchBox('深色开关', $s['theme'] === 'dark')->id('in-dark')->onChange(),
            El::text($s['agree'] ? 'checkbox 已勾选' : 'checkbox 未勾选')->font(12.0),
        ])->gap(12.0),
        // catalog 的带样式表单族（入口是 #81，这一族是 #82）：八颗的构造都**只吃一个
        // `state` 实参**，而那颗实体原本只有 JS 绑定函数能造（要 live script scope），
        // 现在由宿主经 El::state() 造。跟上面 kind 版那几颗并排，是为了"带不带框"
        // 在同一页可比。
        //
        // 取值回读**只覆盖 kind 版**：App::text()/checked() 读的是 shim 的 inputHandles
        // 表，catalog 那颗不在里面。所以这一族的验收是"建成 + 有矩形 + 实体数不涨"
        // （examples/input 的 catalogInputRect / catalogStateReuse / catalogStateFailureVisible
        // 三条门），不是"值回得来"。
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
        // 真人复验 2026-10-27 的误读根因：这颗裸日历贴着 DatePicker 字段正下方，
        // 页面一滚（字段本来就在窗底缘，真人要看得下去必滚）它就进视口——观感与
        // 「弹层已展开」无法区分：弹层开时同屏两本日历，点图标把上面那本当帧收掉，
        // 下面这本纹丝不动，读起来就是「点日历图标收不起来」（上一轮 bug E 的
        // 「下方弹层高亮 6 与字段对不上」同源）。合成 selftest 从不滚动，所以三条
        // DatePicker 门全部量不到——给日历一行自己的名分，把「常开组件」和「弹层」
        // 在屏上分开，这行必须插在字段与日历之间（在它后面就不隔开了）。
        El::text('catalog Calendar（常开裸日历，独立组件——不是上面 DatePicker 的弹层）')
            ->font(12.0),
        El::catalogCalendar('in-ccal'),
    ])->sizeFull()->gap(10.0)->p(16.0)->overflowYScroll();
}

/** 把本域元素的事件接进 App。handler 写 ExampleState，下一帧的 builder 读到。 */
function wireInputs(App $app): void
{
    // 参考 examples/input.cpp 的 OnChange：问候从 **state 的当前文本**重发，不信事件载荷。
    // 合成 change（automation 的 change 动词）不带文本，信载荷会把问候画成 "Hello, 1!"
    // （2026-10-22 用 automation + screenshot 实测到）；真键入的 change 带文本，两条路都该对。
    $app->on('in-name', 'change', function () use ($app): void {
        ExampleState::$v['name'] = $app->text('in-name');
    });
    $app->on('in-note', 'change', function () use ($app): void {
        ExampleState::$v['note'] = (string) strlen($app->text('in-note'));
    });
    // in-vol 没有 state 取值口（App 只有 text()/checked()，slider 不在 inputHandles 表里），
    // 只能信载荷：合成 change 下它会读到 1。已知边界，与 in-name/in-note 的读 state 不同源。
    $app->on('in-vol', 'change', function (array $e): void {
        ExampleState::$v['vol'] = (int) ($e['value'] ?? 0);
    });
    $app->on('in-agree', 'change', function () use ($app): void {
        ExampleState::$v['agree'] = $app->checked('in-agree');
    });
    $app->on('in-dark', 'change', function () use ($app): void {
        ExampleState::$v['theme'] = $app->checked('in-dark') ? 'dark' : 'light';
        // 控件名叫「深色开关」，回路就得落到切主题上：只写 state 而域内无人读它，
        // 等于接了线却没有可见效果（system 域 sv-theme 是同款先例）。selftest 的
        // darkRoute 断 state 翻转；setTheme 是被动方法、没有回读口，不在此处假装断得住。
        $app->setTheme(ExampleState::$v['theme']);
    });
    $app->on('in-key', 'key_down', function (array $e): void {
        ExampleState::$v['key'] = (string) ($e['value'] ?? '?');
    });
    // NumberInput 的 ± 步进。上游把它做成"点按钮"而不是键位，所以这条只能经真点击量；
    // 载荷是字符串 increment/decrement（cmd/patch-select-view.sh 起才带着值到 PHP）。
    //
    // 值归脚本管：on_step 一旦声明，materialize 就把上游内建的步进挂成 nullptr
    // （materialize.cpp 的 `!behavior.onStep && retained->number.hasStep` 那三行）——
    // 点 ± 只发事件、不改数。所以 handler 必须自己把新值回写进 retained 实体
    // （setInput→InputSetValue，App.php 两口都在），否则事件来了、屏上数字纹丝不动，
    // 「点了没反应」。回环由 selftest 的 retainedRoundtrip 与 stepDelivered（含
    // text('in-num')±1 判据）各自把住。
    $app->on('in-num', 'step', function (array $e) use ($app): void {
        $dir = (string) ($e['value'] ?? '');
        ExampleState::$v['lastStep'] = $dir;
        $cur = (int) $app->text('in-num');
        $next = $dir === 'increment' ? $cur + 1
            : ($dir === 'decrement' ? $cur - 1 : $cur);
        $app->setInput('in-num', (string) $next);
    });
}
