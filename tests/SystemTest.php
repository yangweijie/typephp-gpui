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

test('sysInfo returns the whole overview and no processes by default', function (): void {
    $info = App::sysInfo();

    expect(array_keys($info))->toBe([
        'cpu', 'memPct', 'memTotal', 'memUsed', 'diskPct', 'diskTotal', 'diskUsed',
        'ncpu', 'batteryPresent', 'batteryCharging', 'batteryPct', 'procs',
    ])->and($info['procs'])->toBe([]);
});

test('sysInfo disk numbers are self-consistent and anchored on the real volume', function (): void {
    $info = App::sysInfo();

    // 替身的 disk 走 PHP 的 disk_total_space/disk_free_space，与 gpui 的 statfs("/")
    // 同一个算法，所以这条在两侧都成立：used = total - free，pct = used/total。
    expect($info['diskTotal'])->toBeGreaterThan(0)
        ->and($info['diskUsed'])->toBeGreaterThan(0)
        ->and($info['diskUsed'])->toBeLessThan($info['diskTotal'])
        ->and(abs($info['diskPct'] - $info['diskUsed'] * 100 / $info['diskTotal']))
        ->toBeLessThan(0.001);
});

test('sysInfo honours scripted values and the topProcs cap', function (): void {    FakeBridge::$sysInfo = [
        'cpu' => 42.5,
        'ncpu' => 10,
        'batteryPresent' => true,
        'batteryCharging' => true,
        'batteryPct' => 87.0,
        'procs' => [
            ['pid' => 1, 'name' => 'launchd', 'cpu' => 1.0, 'memory' => 4096],
            ['pid' => 2, 'name' => 'kernel', 'cpu' => 2.0, 'memory' => 0],
            ['pid' => 3, 'name' => 'x', 'cpu' => 3.0, 'memory' => 0],
        ],
    ];

    $info = App::sysInfo(2);
    expect($info['cpu'])->toBe(42.5)
        ->and($info['ncpu'])->toBe(10)
        ->and($info['batteryPresent'])->toBeTrue()
        ->and($info['batteryCharging'])->toBeTrue()
        ->and($info['batteryPct'])->toBe(87.0)
        ->and($info['procs'])->toHaveCount(2)
        ->and($info['procs'][0]['name'])->toBe('launchd');
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

test('notifications stack, dismiss, and clear', function (): void {
    $app = sysApp();
    expect($app->notificationCount())->toBe(0);

    $a = $app->notify('已保存', '3 个文件');
    $b = $app->notify('磁盘将满', '', 'warning', 'bottom_left');
    expect($a)->toBeGreaterThan(0)->and($b)->toBeGreaterThan($a);
    expect($app->notificationCount())->toBe(2);

    expect($app->dismissNotification($a))->toBeTrue();
    expect($app->dismissNotification($a))->toBeFalse();
    expect($app->notificationCount())->toBe(1);

    $app->clearNotifications();
    expect($app->notificationCount())->toBe(0);
});

test('notify rejects an unknown type or placement instead of guessing', function (): void {
    $app = sysApp();
    expect(fn () => $app->notify('t', '', 'urgent'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $app->notify('t', '', 'info', 'middle'))->toThrow(InvalidArgumentException::class);
    expect($app->notificationCount())->toBe(0);
});

test('a dismissed notification reports close, not click', function (): void {
    $app = sysApp();
    $seen = [];
    $app->on('saved', 'close', function () use (&$seen): void {
        $seen[] = 'close';
    });
    $app->on('saved', 'click', function () use (&$seen): void {
        $seen[] = 'click';
    });

    $nid = $app->notify('已保存', '', 'success', 'top_right', 5000, 'saved');
    expect(FakeBridge::fireNotification($nid, 'click'))->toBeTrue();
    $app->loop(1);
    expect($seen)->toBe(['click']);

    // close 的语义是"已从列表移除"：触发它的那条通知也就没了，不能重复 fire。
    expect(FakeBridge::fireNotification($nid, 'close'))->toBeTrue();
    $app->loop(1);
    expect($seen)->toBe(['click', 'close']);
    expect(FakeBridge::fireNotification($nid, 'close'))->toBeFalse();
});

test('a notification with no routing id fires no events', function (): void {
    $app = sysApp();
    $nid = $app->notify('无 id');
    expect(FakeBridge::fireNotification($nid, 'click'))->toBeTrue();
    expect(FakeBridge::$eventQueue)->toBe([]);
});

test('the notification layer is an explicit node in the tree', function (): void {
    $n = El::notifications('notes')->toArray();

    // App::notify() 不挂这层也能推成功（状态在窗口层），但看不见——所以这颗节点
    // 是"看得见"的前提，必须能在树里出现。
    expect($n['kind'])->toBe('notifications')->and($n['id'])->toBe('notes');
});

// ── macOS 系统集成（Phase 10）──

test('tray items register with labels and can be removed', function (): void {
    $app = sysApp();
    expect($app->trayCount())->toBe(0);

    $id = $app->tray(['显示窗口', '退出'], 'tray-main', '示例应用');
    expect($id)->toBeGreaterThan(0)
        ->and($app->trayCount())->toBe(1)
        ->and(FakeBridge::$trays[0]['elem'])->toBe('tray-main')
        ->and(FakeBridge::$trays[0]['tooltip'])->toBe('示例应用')
        ->and(FakeBridge::$trays[0]['labels'])->toBe(['显示窗口', '退出']);

    $app->removeTray($id);
    expect($app->trayCount())->toBe(0);

    // 没挂菜单的托盘项：点击本身就是事件，labels 为空数组。
    $bare = $app->tray([], 'tray-bare');
    expect(FakeBridge::$trays[0]['labels'])->toBe([]);
    $app->removeTray($bare);
});

test('hotkey modifiers are named, and unknown names are refused', function (): void {
    $app = sysApp();
    // cmdKey|shiftKey = 256|512：调用方写名字，位或在这里算。
    $id = $app->onHotkey('focus', 105, ['cmd', 'shift']);
    expect($id)->toBeGreaterThan(0)
        ->and(FakeBridge::$hotkeys[0]['mods'])->toBe(768)
        ->and(FakeBridge::$hotkeys[0]['keycode'])->toBe(105)
        ->and($app->hotkeyCount())->toBe(1);

    expect(fn () => $app->onHotkey('x', 105, ['hyper']))
        ->toThrow(InvalidArgumentException::class, 'hyper');

    $app->removeHotkey($id);
    expect($app->hotkeyCount())->toBe(0);
});

test('a fired hotkey routes to its handler', function (): void {
    $app = sysApp();
    $seen = [];
    $app->on('focus', 'hotkey', function () use (&$seen): void {
        $seen[] = 'hotkey';
    });
    $app->onHotkey('focus', 105, ['cmd']);

    expect(App::fireHotkeyForTest(0))->toBeTrue();
    $app->loop(1);
    expect($seen)->toBe(['hotkey']);

    // 越界索引不动事件队列：合成接缝也不能凭空造事件。
    expect(App::fireHotkeyForTest(9))->toBeFalse();
    expect(FakeBridge::$eventQueue)->toBe([]);
});

test('dock badge and OS notification reach the system layer', function (): void {
    App::dockBadge('7');
    expect(FakeBridge::$dockBadge)->toBe('7');
    App::dockBadge('');
    expect(FakeBridge::$dockBadge)->toBe('');

    expect(App::osNotify('构建完成', '产物在 build/'))->toBeTrue();
    expect(FakeBridge::$osNotifications)->toBe([
        ['title' => '构建完成', 'body' => '产物在 build/'],
    ]);
});

// ── 授权文件系统 / 进程 / 存储（Phase 11）──

test('fs reads and writes only inside granted roots', function (): void {
    $dir = sys_get_temp_dir() . '/gpui-fs-' . uniqid('', true);
    mkdir($dir . '/sub', 0777, true);
    App::grantRoot($dir, true);

    expect(App::fsWrite($dir . '/sub/a.txt', "第一段\n第二段"))->toBeTrue()
        ->and(App::fsRead($dir . '/sub/a.txt'))->toBe("第一段\n第二段")
        ->and(App::fsError())->toBe('');

    // 反向锚：没授予过的兄弟目录必须被拒，且给出原因。
    $outside = sys_get_temp_dir() . '/gpui-outside-' . uniqid('', true);
    mkdir($outside, 0777, true);
    expect(App::fsRead($outside . '/secret'))->toBeNull()
        ->and(App::fsError())->toContain('不在授予的根里');

    // 二进制安全：\0 与高位字节要原样穿过去。
    $bin = "\x00\x89PNG\r\n\x1a\n中文";
    expect(App::fsWrite($dir . '/b.bin', $bin))->toBeTrue()
        ->and(App::fsRead($dir . '/b.bin'))->toBe($bin);

    // 收尾自己来：glob() 也会给出目录，直接 unlink 它会告警。
    @unlink($dir . '/sub/a.txt');
    @unlink($dir . '/b.bin');
    @rmdir($dir . '/sub');
    @rmdir($dir);
    @rmdir($outside);
});

test('fs list / exists / mkdir / remove share the same gate', function (): void {
    $dir = sys_get_temp_dir() . '/gpui-fs2-' . uniqid('', true);
    App::grantRoot($dir, true);

    expect(App::fsMkdir($dir . '/deep/nest', true))->toBeTrue()
        ->and(App::fsExists($dir . '/deep/nest'))->toBeTrue()
        ->and(App::fsExists($dir . '/nope'))->toBeFalse();

    file_put_contents($dir . '/deep/nest/x.txt', 'x');
    $listing = App::fsList($dir . '/deep', $truncated);
    expect($listing)->toBe([['name' => 'nest', 'dir' => true]])
        ->and($truncated)->toBeFalse();

    expect(App::fsRemove($dir . '/deep/nest/x.txt'))->toBeTrue()
        ->and(App::fsRemove($dir . '/deep', true, true))->toBeTrue()
        ->and(App::fsExists($dir . '/deep'))->toBeFalse();

    // 未授予的根里什么都不能删。
    expect(App::fsRemove('/etc/hosts'))->toBeFalse()
        ->and(App::fsError())->toContain('不在授予的根里');

    @rmdir($dir);
});

test('processRun obeys the command whitelist', function (): void {
    App::grantExecute(['/bin/echo']);
    $r = App::processRun('/bin/echo', ['hi', '两个 参数']);
    expect($r)->not->toBeNull()
        ->and($r['out'])->toContain('hi 两个 参数');

    expect(App::processRun('/bin/cat', ['/etc/passwd']))->toBeNull()
        ->and(App::fsError())->toContain('白名单');
});

test('storage needs an explicit grant and round-trips values', function (): void {
    expect(App::storageGranted())->toBeFalse()
        ->and(App::storageSet('k', 'v'))->toBeFalse()
        ->and(App::fsError())->toContain('存储授权');

    App::grantStorage(true);
    expect(App::storageGranted())->toBeTrue()
        ->and(App::storagePath(sys_get_temp_dir() . '/gpui-store.json'))->toBeTrue()
        ->and(App::storageSet('theme', 'dark'))->toBeTrue()
        ->and(App::storageGet('theme'))->toBe('dark')
        ->and(App::storageGet('missing'))->toBeNull()
        ->and(App::storageLen())->toBe(1)
        ->and(App::storageRemove('theme'))->toBeTrue()
        ->and(App::storageLen())->toBe(0);
});

test('app menu rows carry the action ids PHP declared', function (): void {
    $app = sysApp();
    $n = $app->setAppMenus([
        ['name' => 'File', 'items' => [
            ['label' => 'New', 'action' => 'file.new'],
            ['separator' => true],
            ['label' => '纯标题项'],
        ]],
        ['name' => 'Edit', 'items' => [['label' => 'Copy', 'action' => 'edit.copy']]],
    ]);
    // 菜单数按"条"算，行表只收带动作的行：分隔符与无动作行混进表里，说明键名读歪了。
    expect($n)->toBe(2)
        ->and($app->menuRows())->toBe(['file.new', 'edit.copy']);

    // 反方向锚点：键名写成 id（而不是 action）时装不出任何可路由的行——
    // showcase 上一版就是这么写的，菜单看着在、点了没回音。
    $app->setAppMenus([
        ['label' => 'File', 'items' => [['label' => 'New', 'id' => 'file.new']]],
    ]);
    expect($app->menuRows())->toBe([]);
});

test('elementValue/checked read the last value that came in on a change event', function (): void {
    $app = sysApp();
    // 从没上报过 = null，不是 false：这两件事必须分得开，否则"没读过"会被当成"没勾上"。
    expect($app->elementValue('sw'))->toBeNull()
        ->and($app->checked('sw'))->toBeFalse();

    FakeBridge::pushEvent(1, 'change', 'sw', true);
    expect($app->elementValue('sw'))->toBeTrue()
        ->and($app->checked('sw'))->toBeTrue();

    FakeBridge::pushEvent(1, 'change', 'sw', false);
    expect($app->checked('sw'))->toBeFalse()
        ->and($app->elementValue('sw'))->toBeFalse();

    // 无值事件（click/hover）不许覆盖已记录的值。
    FakeBridge::pushEvent(1, 'change', 'slider', 37.0);
    FakeBridge::pushEvent(1, 'click', 'slider');
    expect($app->elementValue('slider'))->toBe(37.0);
});

// 表格的序号槽（Component::index）：TableRow 拿它当行号、TableHead/TableCell 当列号，
// 上游再喂给 AriaRowIndex/AriaColumnIndex（materialize.cpp:1838-1842）。
// 这条测的是替身侧的语义，但 -1 与 0 的区分必须一致：否则"这行没编号"会被读成"第 0 行"。
test('specIndex reads back the aria slot and tells 0 apart from missing', function (): void {
    $app = sysApp();
    expect($app->specIndex('r0'))->toBe(-1);   // 还没画过：查无

    $app->render(El::table()->rowCount(2)->columnCount(2)->children([
        El::tableBody([
            El::tableRow([El::tableCell([El::text('a')])->columnIndex(0)])->id('r0')->rowIndex(0),
            El::tableRow([El::tableCell([El::text('b')])->columnIndex(1)])->id('r1')->rowIndex(1),
        ]),
    ]));
    expect($app->specIndex('r0'))->toBe(0)
        ->and($app->specIndex('r1'))->toBe(1)
        ->and($app->specIndex('nope'))->toBe(-1);
});

// 行列数走的是两个 method op（row_count / column_count），不是从子节点数推的。
test('rowCount/columnCount emit the two ops upstream reads', function (): void {
    $node = El::table('t')->rowCount(3)->columnCount(4)->toArray();
    $byName = [];
    foreach ($node['ops'] as $op) { $byName[$op['name']] = $op['args'] ?? null; }
    expect($byName)->toHaveKey('row_count')
        ->and($byName['row_count'])->toBe([3.0])
        ->and($byName['column_count'])->toBe([4.0]);

    expect(fn () => El::table('t')->rowCount(-1))->toThrow(InvalidArgumentException::class);
});

// #62 的替身侧语义：dialog 开着 = 焦点陷阱生效；关掉就没了。
// （真侧的读数是上游按实体算出的 id，测试只判"有没有生效"。）
test('focusTrap is active while a dialog is open', function (): void {
    $app = sysApp();
    expect($app->focusTrap())->toBe(0);
    $app->openDialog(El::text('带焦点陷阱的框'));
    expect($app->focusTrap())->not->toBe(0)
        ->and($app->dialogCount())->toBe(1);
    $app->closeDialog();
    expect($app->focusTrap())->toBe(0);
});
