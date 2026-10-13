<?php

declare(strict_types=1);

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;
use TypePHP\Gpui\FakeBridge;

/*
 * App 层的无头单测（Pest），走 FakeBridge：渲染 / 事件分派 / 取值 / 主循环。
 * 每个测试前 FakeBridge::reset() 由 tests/Pest.php 的 beforeEach 统一执行。
 */

test('render logs the tree', function (): void {
    $app = new App();
    $app->create();
    $win = $app->createWindow('T', 400, 300);
    $app->render(El::text('hi'));

    expect(FakeBridge::$renderLog[(int) $win])->toHaveCount(1);
});

test('input set then read round-trips', function (): void {
    $app = new App();
    $app->create();
    $app->createWindow('T', 400, 300);
    $app->render(El::input()->id('name'));

    expect($app->setInput('name', 'hello'))->toBeTrue();
    expect($app->text('name'))->toBe('hello');
});

test('click handler fires for matching id', function (): void {
    $app = new App();
    $app->create();
    $win = $app->createWindow('T', 400, 300);
    $hit = 0;
    $app->on('btn', 'click', function () use (&$hit): void {
        $hit++;
    });
    FakeBridge::pushEvent((int) $win, 'click', 'btn');
    $app->dispatch(['type' => 'click', 'id' => 'btn', 'value' => null]);

    expect($hit)->toBe(1);
});

test('zero-arity handler works', function (): void {
    $app = new App();
    $app->create();
    $app->createWindow('T', 400, 300);
    $hit = 0;
    $app->on('b', 'click', function () use (&$hit): void {
        $hit++;
    });
    $app->dispatch(['type' => 'click', 'id' => 'b']);

    expect($hit)->toBe(1);
});

test('onAny also fires even when on() matched', function (): void {
    $app = new App();
    $app->create();
    $app->createWindow('T', 400, 300);
    $specific = 0;
    $any = 0;
    $app->on('x', 'click', function () use (&$specific): void {
        $specific++;
    });
    $app->onAny('click', function () use (&$any): void {
        $any++;
    });
    $app->dispatch(['type' => 'click', 'id' => 'x']);

    expect($specific)->toBe(1);
    expect($any)->toBe(1, 'onAny must also fire even when on() matched');
});

test('渲染是脏驱动的：预热 1 帧 + 每轮 loop 的首帧 + 事件/invalidate 各一次', function (): void {
    $app = new App();
    $app->create();
    $win = $app->createWindow('T', 400, 300);
    $frames = 0;
    $app->view(function () use (&$frames): El {
        $frames++;
        return El::text("frame $frames");
    });
    FakeBridge::$windowOpen = true;

    // 预热那一次不是多余渲染：实测只跑一帧时真点击产生 0 个事件，第二帧之后才稳定
    // （首帧的 materialize 建不出命中表），见 App::view 的注释与 --liveclick。
    expect($frames)->toBe(1);

    // 空转 3 帧：没有事件、窗口状态没变 → 只补 loop 的首帧一次，不是 3 次。
    // 这条就是审核第 5 步①的新契约（改之前是"每帧必画"，空闲窗口 60 次/秒重建整棵 PHP 树）。
    $app->loop(3);
    expect($frames)->toBe(2);
    // renderCount 只算 loop 驱动的渲染：view() 那次预热走的是直接 render，不计入。
    expect($app->renderCount())->toBe(1);
    expect($app->frameCount())->toBe(3);

    // 正半：事件必须把下一帧叫醒，否则"跳过渲染"就变成了"永远不画"的假绿。
    FakeBridge::pushEvent((int) $win, 'click', 'btn');
    $app->on('btn', 'click', static function (): void {});
    $app->loop(2);
    expect($frames)->toBe(3);
    expect($app->renderCount())->toBe(2);

    // app 自己改了状态但没有任何事件时，invalidate() 是唯一的叫醒方式。
    $app->invalidate();
    $app->loop(2);
    expect($frames)->toBe(4);
    expect($app->renderCount())->toBe(3);

    expect(FakeBridge::$renderLog[(int) $win])->toHaveCount(4);
});

// #71：指针事件在 PHP 侧的路由。类型是**短名**（bridge 剥掉 on_ 前缀，gpui_bridge.cc:49-51），
// 值是一段 CSV —— 这条门量的是"到了 PHP 之后怎么发"，投递本身由 showcase 的真输入门量。
test('pointer events route by the short type and carry the CSV value', function (): void {
    $app = new App();
    $app->create();
    $win = $app->createWindow('T', 400, 300);
    $seen = null;
    $app->on('row', 'mouse_down_left', function (array $e) use (&$seen): void {
        $seen = $e['value'] ?? null;
    });
    FakeBridge::pushEvent((int) $win, 'mouse_down_left', 'row', '105.00,226.00,0,1,0');
    $app->loop(2);
    expect($seen)->toBe('105.00,226.00,0,1,0');
    // 值槽是共享的：真侧 enqueue 也走 rememberValue，所以鼠标值会覆盖同元素的"最近上报值"。
    // 这不是 bug 而是既定语义，钉在这里免得哪天被当成回归。
    expect($app->elementValue('row'))->toBe('105.00,226.00,0,1,0');
});
