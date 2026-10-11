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

test('clipboardItem reads all representations from one snapshot', function (): void {
    $app = sysApp();
    expect($app->clipboardItem())->toBe(['text' => null, 'image' => null, 'paths' => []]);

    $app->setClipboardText('一段文本');
    expect($app->clipboardItem())->toBe(['text' => '一段文本', 'image' => null, 'paths' => []]);

    // 图像流是二进制的：开头就有 \0，必须原样穿过边界。
    $png = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR";
    FakeBridge::$clipboardImage = $png;
    FakeBridge::$clipboardPaths = ['/tmp/a.png', '/tmp/中文.txt'];
    $all = $app->clipboardItem();
    expect($all['text'])->toBe('一段文本')
        ->and($all['image'])->toBe($png)
        ->and($all['paths'])->toBe(['/tmp/a.png', '/tmp/中文.txt']);
});

test('an image-only clipboard yields no text and no paths', function (): void {
    FakeBridge::$clipboardImage = "\x00\x01\x02";
    $item = sysApp()->clipboardItem();

    expect($item['text'])->toBeNull()->and($item['paths'])->toBe([])
        ->and($item['image'])->toBe("\x00\x01\x02");
});

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

test('webview factories carry url/html without an id method op', function (): void {
    $byUrl = El::webview('https://example.com', 'wv1')->toArray();
    $byHtml = El::webviewHtml('<b>hi</b>', 'wv2')->toArray();

    expect($byUrl['kind'])->toBe('webview')
        ->and($byUrl['value'])->toBe('https://example.com')
        ->and($byUrl['text'])->toBe('')
        ->and($byUrl['id'])->toBe('wv1');
    expect($byHtml['kind'])->toBe('webview')
        ->and($byHtml['text'])->toBe('<b>hi</b>')
        ->and($byHtml['value'])->toBe('')
        ->and($byHtml['id'])->toBe('wv2');

    // ChildView 节点不接方法：走 ->id() 会多记一个 method('id') op。
    foreach ([$byUrl, $byHtml] as $node) {
        $names = array_map(static fn (array $op): string => (string) ($op['name'] ?? ''), $node['ops']);
        expect($names)->not->toContain('id');
    }
});

test('probe answers for a rendered webview id only', function (): void {
    $app = sysApp();
    $app->render(El::vFlex([El::webview('https://example.com', 'wv1')]));

    $known = $app->webviewProbe('wv1');
    expect($known)->toBe(['ok' => true, 'url' => 'https://example.com', 'w' => 0.0, 'h' => 0.0]);

    // w/h 恒 0 是替身的边界：应用盒子只有真控件知道（wry::WebViewBounds）。
    // 那两个数的验收在 hello --selftest 第 12 步，不在这里。
    $unknown = $app->webviewProbe('nope');
    expect($unknown['ok'])->toBeFalse()->and($unknown['url'])->toBe('');
});

test('history nav answers per id and records the direction', function (): void {
    $app = sysApp();
    $app->render(El::webviewHtml('<b>hi</b>', 'wv1'));

    expect($app->webviewBack('wv1'))->toBeTrue();
    expect($app->webviewForward('wv1'))->toBeTrue();
    expect($app->webviewBack('other'))->toBeFalse();
    expect(FakeBridge::$webviewNavs)->toBe([
        ['id' => 'wv1', 'dir' => -1],
        ['id' => 'wv1', 'dir' => 1],
        ['id' => 'other', 'dir' => -1],
    ]);
});

test('inspector is a mountable node on its own', function (): void {
    $n = El::inspector('devtools')->toArray();

    // 它和 webview 走同一道 ChildView 口子，所以也是一颗普通节点：可样式、可挂父。
    expect($n['kind'])->toBe('inspector')
        ->and($n['id'])->toBe('devtools')
        ->and($n['text'])->toBe('')
        ->and($n['value'])->toBe('')
        ->and($n['ops'])->toBe([]);
});

test('httpGet gates on the scheme and records the request', function (): void {
    $app = sysApp();

    expect($app->httpGet('https://example.com/api', 'r1'))->toBeTrue();
    expect($app->httpGet('data:text/plain,hi', 'r2'))->toBeFalse();
    expect($app->httpGet('ftp://host/f', 'r3'))->toBeFalse();
    expect($app->httpGet('https://example.com', ''))->toBeFalse();
    expect(FakeBridge::$httpRequests)->toBe([['id' => 'r1', 'url' => 'https://example.com/api']]);
});

test('an http response is handed over once', function (): void {
    FakeBridge::$httpBodies['r1'] = '{"n":1}';
    $app = sysApp();

    expect($app->httpResponse('r1'))->toBe([
        'ok' => true, 'status' => 200, 'body' => '{"n":1}', 'contentType' => 'text/plain',
    ]);
    expect($app->httpResponse('r1'))->toBeNull();
    expect($app->httpResponse('never'))->toBeNull();
});

test('watchDir hands out ids and unwatch takes them back', function (): void {
    $app = sysApp();

    $w1 = $app->watchDir('/tmp', 'themes');
    $w2 = $app->watchDir('/var', 'logs', true);
    expect($w1)->toBeGreaterThan(0)->and($w2)->toBeGreaterThan($w1);
    expect(FakeBridge::$watches)->toBe([$w1 => 'themes', $w2 => 'logs']);

    $app->unwatchDir($w1);
    expect(FakeBridge::$watches)->toBe([$w2 => 'logs']);
    expect($app->watchDir('', 'bad'))->toBe(0);
});

test('a watch signal reaches the PHP handler as a dirChange event', function (): void {
    $app = sysApp();
    $hits = 0;
    $app->on('themes', 'dirChange', function () use (&$hits): void {
        $hits++;
    });
    $watchId = \gpui_watch_fake($app->handle(), 'themes');
    expect($watchId)->toBeGreaterThan(0);

    $app->loop(1);
    expect($hits)->toBe(1);
});

test('inflate reads the containers PHP writes', function (): void {
    // 两侧锚：替身用 PHP 自己的 zlib 扩展解，原生侧用 gpui 的 ZlibInflate，
    // 吃的是同一批字节。gzip=false 对应 zlib 容器（gzcompress），true 对应 gzip 容器
    // （gzencode）——不是 raw deflate，这点语义必须一致。
    $payload = str_repeat('typephp-gpui ', 200);

    expect(App::inflate(gzcompress($payload)))->toBe($payload);
    expect(App::inflate(gzencode($payload), true))->toBe($payload);
    expect(App::inflate('not a zlib stream'))->toBeNull();
    expect(App::inflate(gzcompress($payload), true))->toBeNull();
});
