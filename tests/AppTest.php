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

test('loop runs the view once per frame and stops', function (): void {
    $app = new App();
    $app->create();
    $win = $app->createWindow('T', 400, 300);
    $frames = 0;
    $app->view(function () use (&$frames): El {
        $frames++;
        return El::text("frame $frames");
    });
    FakeBridge::$windowOpen = true;
    $app->loop(3);

    expect($frames)->toBe(3);
    expect(FakeBridge::$renderLog[(int) $win])->toHaveCount(3);
});
