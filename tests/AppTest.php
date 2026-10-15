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
// 值是一段 CSV —— 这条门量的是"到了 PHP 之后怎么发"，投递本身由 examples/text 的
// pointerEventsDelivered（真点真扫 + 值形状）量。
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

test('focusElement 设得进去也读得回来，空 id 回 0', function (): void {
    // 钉的是 PHP 层的语义契约："把焦点放进部件"之后 focusedId() 必须报同一颗——
    // 原生那一侧给的是部件 retained focus handle 的 id，替身给的是 crc32 伪 id，
    // 两边只需要"同进同出"，不要求数值相等（所以这里断言的是关系，不是具体数字）。
    // 有这层契约，examples/choices 才能把"焦点没进部件"与"动作没派发"分成两种失败。
    $app = new App();
    $app->create();
    $app->createWindow('T', 400, 300);

    expect($app->focusElement(''))->toBe(0, '空 id 不该有焦点身份');
    $sel = $app->focusElement('ch-select');
    expect($sel)->toBe($app->focusedId(), '设完必须读得回同一颗');
    $date = $app->focusElement('ch-date');
    expect($date)->not->toBe($sel, '两颗部件的焦点身份不能撞车');
    expect($date)->toBe($app->focusedId());
});

test('list renderers serialize only the requested range and replace per-window registrations', function (): void {
    $app = new App();
    $requested = [];
    $app->listRenderer('items', function (int $start, int $end) use (&$requested): array {
        $requested[] = [$start, $end];
        $rows = [];
        for ($i = $start; $i < $end; $i++) $rows[] = El::text((string) $i)->id('item-' . $i);
        return $rows;
    });
    expect(FakeBridge::$listRenderers)->toBe([]);
    $app->create();
    $win = $app->createWindow('List', 400, 300);
    $key = ((int) $win) . "\x1fitems";
    $rows = FakeBridge::$listRenderers[$key](30, 33);
    expect($requested)->toBe([[30, 33]])
        ->and(array_column($rows, 'id'))->toBe(['item-30', 'item-31', 'item-32']);
    $app->listRenderer('items', static fn (int $start, int $end): array => [El::text('replacement')]);
    expect(FakeBridge::$listRenderers)->toHaveCount(1)
        ->and(FakeBridge::$listRenderers[$key](10, 11)[0]['text'])->toBe('replacement');
    $app->close();
    expect(FakeBridge::$listRenderers)->toBe([]);
});

test('run installs list renderers registered before the window is created', function (): void {
    $app = new App();
    $app->listRenderer('items', static fn (int $start, int $end): array => [El::text('ready')]);
    $value = '';
    $app->run('List', 400, 300, function () use ($app, &$value): El {
        $key = ((int) $app->handle()) . "\x1fitems";
        $value = FakeBridge::$listRenderers[$key](0, 1)[0]['text'];
        $app->close();
        return El::div();
    });
    expect($value)->toBe('ready')->and(FakeBridge::$listRenderers)->toBe([]);
});

test('list renderer failures remain visible and release the window API guard', function (): void {
    $app = new App();
    $app->create();
    $win = $app->createWindow('List', 400, 300);
    $key = ((int) $win) . "\x1fitems";
    foreach ([
        static fn (int $start, int $end): array => [],
        static fn (int $start, int $end): array => [El::text('extra'), El::text('extra')],
        static fn (int $start, int $end): array => [null],
        static fn (int $start, int $end) => null,
        static fn (int $start, int $end): array => throw new RuntimeException('renderer failed'),
        function (int $start, int $end) use ($app): array { $app->render(El::div()); return []; },
        function (int $start, int $end) use ($app): array { $app->close(); return []; },
        function (int $start, int $end) use ($app): array { $app->loop(1); return []; },
        function (int $start, int $end) use ($app): array { $app->createWindow('bad'); return []; },
    ] as $render) {
        $app->listRenderer('items', $render);
        expect(FakeBridge::$listRenderers[$key](0, 1))->toBe([])
            ->and($app->lastError())->not->toBe('')
            ->and($app->handle())->toBe($win)
            ->and($app->isOpen())->toBeTrue();
    }
    $app->listRenderer('items', static fn (int $start, int $end): array => [El::text('ok')]);
    expect(FakeBridge::$listRenderers[$key](0, 1)[0]['text'])->toBe('ok');
    $app->close();
});

test('Dock retains named panels and restores a saved layout without duplication', function (): void {
    $app = new App();
    $app->create();
    $app->createWindow('Dock', 600, 400);
    expect($app->createDock('area'))->toBeTrue()->and($app->createDock('area'))->toBeTrue();
    expect($app->addDockPanel('area', 'editor', '编辑器', El::text('one')))->toBeTrue();
    expect($app->addDockPanel('area', 'console', '控制台', El::text('two')))->toBeTrue();
    expect($app->addDockPanel('area', 'files', '文件', El::text('three'), 'left'))->toBeTrue();
    $app->addDockPanel('area', 'console', '更新控制台', El::text('updated'));
    expect($app->dockProbe('area')['registered'])->toBe(3)
        ->and($app->stateCounts()['dockStates'])->toBe(1)
        ->and($app->stateCounts()['dockPanels'])->toBe(3);
    expect($app->selectDockPanel('area', 'editor'))->toBeTrue()
        ->and($app->dockProbe('area')['active'])->toBe(0);
    $layout = $app->dockLayout('area');
    expect($layout)->not->toBe('');
    expect($app->removeDockPanel('area', 'console'))->toBeTrue()
        ->and($app->dockProbe('area')['open'])->toBe(2);
    expect($app->loadDockLayout('area', $layout))->toBeTrue()
        ->and($app->dockProbe('area')['open'])->toBe(3);
    expect($app->loadDockLayout('area', 'not-json'))->toBeFalse()
        ->and($app->loadDockLayout('area', str_replace('editor', 'unknown', $layout)))->toBeFalse()
        ->and($app->dockLayout('area'))->toBe($layout);
    $app->close();
    expect($app->stateCounts()['dockStates'])->toBe(0)
        ->and($app->stateCounts()['dockPanels'])->toBe(0);
});

test('Dock validates ids and geometry and isolates window registrations', function (): void {
    $app = new App();
    $app->create();
    $app->createWindow('First', 400, 300);
    expect(fn () => $app->createDock(''))->toThrow(InvalidArgumentException::class);
    $app->createDock('same');
    expect(fn () => $app->addDockPanel('same', '', 'x', El::text('x')))->toThrow(InvalidArgumentException::class);
    expect(fn () => $app->addDockPanel('same', 'x', 'x', El::text('x'), 'top'))->toThrow(InvalidArgumentException::class);
    foreach ([-1.0, INF, NAN] as $size) {
        expect(fn () => $app->addDockPanel('same', 'x', 'x', El::text('x'), 'left', $size))
            ->toThrow(InvalidArgumentException::class);
    }
    expect($app->dockProbe('missing'))->toBe([])
        ->and($app->selectDockPanel('same', 'missing'))->toBeFalse()
        ->and($app->removeDockPanel('same', 'missing'))->toBeFalse();
    $other = new App();
    $other->createWindow('Second', 400, 300);
    $other->createDock('same');
    $other->addDockPanel('same', 'other', 'other', El::text('other'));
    expect($app->dockProbe('same')['registered'])->toBe(0)
        ->and($other->dockProbe('same')['registered'])->toBe(1);
    $app->close();
    expect($other->stateCounts()['dockStates'])->toBe(1);
    $other->close();
});
