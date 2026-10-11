<?php

declare(strict_types=1);

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;
use TypePHP\Gpui\FakeBridge;

/*
 * 系统能力的无头单测（Pest），走 FakeBridge：剪贴板 / 文件对话框 / open_url 白名单
 * / inspector / 帧率 HUD / Sheet。
 *
 * 这些断言证明的是 PHP 层的包装与判据（wrap-green），不是原生实现真的动了系统
 * 粘贴板或真的弹了面板——后者由 examples/hello 的 --selftest（route-green）与
 * --shot（pixel-green）负责。
 */

function sysApp(): App
{
    $app = new App();
    $app->create();
    $app->createWindow('T', 400, 300);

    return $app;
}

test('clipboard round-trips text', function (): void {
    $app = sysApp();
    $app->setClipboardText('复制我');

    expect($app->clipboardText())->toBe('复制我');
});

test('empty clipboard reads as null, not empty string', function (): void {
    expect(sysApp()->clipboardText())->toBeNull();
});

test('promptForPath returns the chosen path and records the request', function (): void {
    FakeBridge::$nextPromptPath = '/tmp/a.txt';
    $app = sysApp();

    expect($app->promptForPath(false, '选文件'))->toBe('/tmp/a.txt');
    expect(FakeBridge::$promptRequests[0])->toBe(['directories' => false, 'title' => '选文件']);

    $app->promptForPath(true, '');
    expect(FakeBridge::$promptRequests[1])->toBe(['directories' => true, 'title' => '']);
});

test('cancelled dialog reads as null', function (): void {
    expect(sysApp()->promptForPath())->toBeNull();
});

test('openUrl hands http(s) to the browser and refuses everything else', function (): void {
    $app = sysApp();

    expect($app->openUrl('https://example.com/x?y=1'))->toBeTrue();
    expect($app->openUrl('javascript:alert(1)'))->toBeFalse();
    expect($app->openUrl('mailto:a@b.c'))->toBeFalse();
    expect($app->openUrl('not a url'))->toBeFalse();
    expect(FakeBridge::$openedUrls)->toBe(['https://example.com/x?y=1']);
    expect(FakeBridge::$rejectedUrls)->toHaveCount(3);
});

test('the url gate mirrors gpui on the parts that decide it', function (): void {
    // 这张表与 tests/cpp/shim_test.cpp 的 kCases 逐条同内容：C++ 侧量真实判据
    // （gpui::IsOpenableUrl），这里量替身（FakeBridge::isOpenableUrl），两侧钉住。
    expect(FakeBridge::isOpenableUrl('HTTP://EXAMPLE.COM'))->toBeTrue();
    expect(FakeBridge::isOpenableUrl('http://'))->toBeFalse();
    // 空的 userinfo（"@host"）在真实判据里仍算有 host：hostStart 越过 '@' 后非空。
    expect(FakeBridge::isOpenableUrl('http://@example.com'))->toBeTrue();
    expect(FakeBridge::isOpenableUrl('http://example.com@'))->toBeFalse();
    expect(FakeBridge::isOpenableUrl("http://exa\nmple.com"))->toBeFalse();
    expect(FakeBridge::isOpenableUrl("http://exa\0mple.com"))->toBeFalse();
    expect(FakeBridge::isOpenableUrl('http://ex\\ample.com'))->toBeFalse();
    expect(FakeBridge::isOpenableUrl('ftp://example.com'))->toBeFalse();
    expect(FakeBridge::isOpenableUrl('https://user:pw@example.com/p'))->toBeTrue();
    // 端口与 IPv6 字面量
    expect(FakeBridge::isOpenableUrl('http://host:8080'))->toBeTrue();
    expect(FakeBridge::isOpenableUrl('http://host:'))->toBeFalse();
    expect(FakeBridge::isOpenableUrl('http://host:abc'))->toBeFalse();
    expect(FakeBridge::isOpenableUrl('http://host:99999'))->toBeFalse();
    expect(FakeBridge::isOpenableUrl('http://[::1]'))->toBeTrue();
    expect(FakeBridge::isOpenableUrl('http://[::1]:8080/p'))->toBeTrue();
    expect(FakeBridge::isOpenableUrl('http://[]:80'))->toBeFalse();
    expect(FakeBridge::isOpenableUrl('http://:80'))->toBeFalse();
});

test('toggleInspector flips the window inspector', function (): void {
    $app = sysApp();
    $app->toggleInspector();
    expect(FakeBridge::$inspectorOpen)->toBeTrue();
    $app->toggleInspector();
    expect(FakeBridge::$inspectorOpen)->toBeFalse();
});

test('fps hud shows, reports visible, and hides exactly once', function (): void {
    $app = sysApp();
    expect($app->fpsHudVisible())->toBeFalse();
    expect($app->showFpsHud())->toBeTrue();
    expect(FakeBridge::$fpsAnchor)->toBe('top_right');
    expect($app->fpsHudVisible())->toBeTrue();
    expect($app->hideFpsHud())->toBeTrue();
    expect($app->hideFpsHud())->toBeFalse();
});

test('every documented fps anchor is accepted', function (): void {
    $app = sysApp();
    foreach (App::FPS_ANCHORS as $anchor) {
        expect($app->showFpsHud($anchor))->toBeTrue();
    }
    expect(App::FPS_ANCHORS)->toHaveCount(8);
});

test('an unknown fps anchor throws instead of silently not showing', function (): void {
    $app = sysApp();
    expect(fn () => $app->showFpsHud('middle'))->toThrow(InvalidArgumentException::class);
    expect($app->fpsHudVisible())->toBeFalse();
});

test('sheet opens once, replaces rather than stacking, and closes', function (): void {
    $app = sysApp();
    $tree = El::vFlex([El::text('侧栏')]);

    expect($app->hasSheet())->toBeFalse();
    expect($app->openSheet($tree, 'left'))->toBe(1);
    expect($app->hasSheet())->toBeTrue();
    $app->openSheet($tree, 'right');
    expect(FakeBridge::$sheetCount)->toBe(1);
    expect($app->closeSheet())->toBeTrue();
    expect($app->closeSheet())->toBeFalse();
});

test('an unknown sheet anchor throws', function (): void {
    $app = sysApp();
    expect(fn () => $app->openSheet(El::text('x'), 'diagonal'))->toThrow(InvalidArgumentException::class);
});
