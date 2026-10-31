<?php

declare(strict_types=1);

/**
 * examples/_shared/features/system.php — 功能域「系统能力」+「服务」（两节一域）。
 *
 * 从原画廊（已删除）的 §9（剪贴板 · url · 文件系统 · 进程 · 存储）与 §10（http · 目录监听 · 解压 ·
 * 系统状态 · 主题）搬来。它们合成一支独立示例的理由：这两节的能力全部受同一张
 * Capabilities 授权表约束（grantRoot 之后才允许遍历，逐级 openat + O_NOFOLLOW），
 * 一起测才能把"授了根才读得到、没授的根读不到"这个双向门钉在同一个进程里。
 *
 * fmtBytes() 也跟着搬过来：它只被这两节的读数格式化用。
 */

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;

/** 批3 A8：data URI 用 4x4 蓝块（#2f6fed）的 PNG，base64 内联——解码路径与
 * 网络无关，selftest 与树里共用同一字面量（image.cpp DataUriBytes 本地解码）。 */
const SY_DATA_PNG_B64 =
    'iVBORw0KGgoAAAANSUhEUgAAAAQAAAAECAIAAAAmkwkpAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFElEQVQImWPUz3/LAANMDEgANwcATWwBkzi21GoAAAAASUVORK5CYII=';

/** 本域的像素树清单：树号 → [标题, builder]。两节两棵树。 */
function systemTrees(): array
{
    return [
        1 => ['系统能力：剪贴板 · url · 文件系统 · 进程 · 存储', 'sectionSystem'],
        2 => ['服务：http · 目录监听 · 解压 · 系统状态', 'sectionServices'],
    ];
}

function sectionSystem(array $s): El
{
    return El::vFlex([
        El::text('系统能力：剪贴板 · url · 文件系统 · 进程 · 存储')->font(22.0)->semibold(),
        El::hFlex([
            El::catalogButton('sy-clip-set', '写剪贴板')->onClick()->ghost(),
            El::catalogButton('sy-clip-get', '读文本')->onClick()->ghost(),
            El::catalogButton('sy-clip-item', '读完整一项')->onClick()->ghost(),
            El::catalogButton('sy-url', '打开 URL')->onClick()->ghost(),
            El::catalogButton('sy-pick', '选文件')->onClick()->ghost(),
        ])->gap(8.0),
        El::text($s['clip'] !== '' ? "剪贴板：{$s['clip']}" : '剪贴板：还没读（三通道：文本/图像/文件）')->font(12.0),
        El::text($s['picked'] !== '' ? "选中：{$s['picked']}" : 'promptForPath 自带嵌套消息循环 ⇒ 只能在事件回调里调')->font(11.0)->truncate(),
        El::text('授权文件系统（先 grantRoot，之后每个调用内部自己选根）：')->font(12.0),
        El::hFlex([
            El::catalogButton('sy-fs', 'fs 写·读·列·删')->onClick()->secondary(),
            El::catalogButton('sy-proc', '跑 /bin/echo')->onClick()->ghost(),
            El::catalogButton('sy-store', '存储 set/get')->onClick()->ghost(),
        ])->gap(8.0),
        // ── 批3 A8：image 三路 src。远程面核心 image.cpp:223-234 已在 vendored
        // 上游（本地资产走 AssetsExists、data: URI 本地解码、http(s) 走
        // HttpFetch 状态机，Pending/None 每帧重试即全部重试策略）——PHP 侧
        // src 直传 text 字段即达。本地两颗可见（像素确定、进基线，探针见
        // selftest 的 imageAssetRenders 门）；远程颗 hidden：无头会话的 fetch
        // 结果随网络态走，像素不确定，进树是为门 12 的工厂覆盖与 imageUrlPolicy
        // 门的结构读数，hidden 保基线逐字节确定。
        El::text('image 三路 src：本地资产 / data: URI / 远程 URL（hidden，像素随网络态）')
            ->font(13.0)->semibold(),
        El::hFlex([
            El::image('tile.png', '本地资产 8x8 橙块')->w(64.0)->h(64.0),
            El::image('data:image/png;base64,' . SY_DATA_PNG_B64, 'data URI 4x4 蓝块')
                ->w(64.0)->h(64.0),
            El::image('https://example.com/gpui-probe.png', '远程 URL（hidden）')
                ->w(64.0)->h(64.0)->hidden(),
        ])->gap(16.0)->itemsStart(),
        El::vFlex(array_map(
            static fn (string $line): El => El::text($line)->font(11.0)->truncate(),
            $s['sysLog'] === [] ? ['（还没点上面三个按钮）'] : $s['sysLog']
        ))->gap(2.0)->p(8.0)->bg('#faf8f5')->roundedMd()->h(130.0)->overflowYScroll(),
    ])->sizeFull()->gap(10.0)->p(16.0)->overflowYScroll();
}

function sectionServices(array $s): El
{
    return El::vFlex([
        El::text('服务：http · 目录监听 · 解压 · 系统状态 · HUD · Inspector · 主题')->font(22.0)->semibold(),
        El::hFlex([
            El::catalogButton('sv-http', 'httpGet（loopback，必然失败）')->onClick()->secondary(),
            El::catalogButton('sv-watch', 'watchDir 临时目录')->onClick()->ghost(),
            El::catalogButton('sv-unwatch', '停监听')->onClick()->ghost(),
            El::catalogButton('sv-inflate', 'inflate 一段 gzip')->onClick()->ghost(),
            El::catalogButton('sv-sysinfo', 'sysInfo 采样')->onClick()->ghost(),
        ])->gap(8.0),
        El::text($s['http'] !== '' ? "http：{$s['http']}" : 'http：HttpSend 阻塞 ⇒ 只接 HttpSendAsync，结果走事件队列')->font(11.0)->truncate(),
        El::text("dirChange：watch id={$s['watchId']}，命中 {$s['watchHits']} 次（合并上报，不带文件名）")->font(11.0),
        El::text($s['inflate'] !== '' ? "inflate：{$s['inflate']}" : 'inflate：三方锚定的解压（gpui 容器与真 deflate 都读）')->font(11.0)->truncate(),
        El::vFlex(array_map(
            static fn (string $line): El => El::text($line)->font(11.0)->truncate(),
            $s['sysInfo']
        ))->gap(2.0)->p(8.0)->bg('#faf8f5')->roundedMd(),
        El::hFlex([
            El::catalogButton('sv-insp', 'Inspector 拾取')->onClick()->ghost(),
            El::catalogButton('sv-theme', '切主题 dark/light')->onClick()->ghost(),
        ])->gap(8.0),
        // FPS_ANCHORS 全枚举：8 个锚点各一颗，HUD 真钉在那一角。
        El::hFlex(array_map(
            static fn (string $a): El => El::catalogButton('sv-fps-' . $a, 'HUD·' . $a)->onClick()->ghost()->sizeEnum('xsmall'),
            App::FPS_ANCHORS
        ))->gap(4.0)->flexWrap(),
        El::hFlex([
            El::catalogButton('sv-fps-off', '藏 HUD')->onClick()->ghost(),
            El::text("hud=" . ($s['hud'] ? 'on' : 'off') . " anchor={$s['fpsAnchor']} · 主题={$s['theme']}")->font(12.0),
        ])->gap(8.0),
    ])->sizeFull()->gap(10.0)->p(16.0)->overflowYScroll();
}

function fmtBytes(int $n): string
{
    $units = ['B', 'K', 'M', 'G', 'T'];
    $i = 0;
    $v = (float) $n;
    while ($v >= 1024.0 && $i < 4) {
        $v /= 1024.0;
        $i++;
    }
    return sprintf('%.1f%s', $v, $units[$i]);
}

/** 本域元素的接线（§9 + §10）。 */
function wireSystem(App $app): void
{
    $app->on('sy-clip-set', 'click', function () use ($app): void {
        $app->setClipboardText('gpui 写入的文本 ' . $app->notificationCount());
    });
    $app->on('sy-clip-get', 'click', function () use ($app): void {
        $t = $app->clipboardText();
        ExampleState::$v['clip'] = $t === null ? '空' : 'text=' . strlen($t);
    });
    $app->on('sy-clip-item', 'click', function () use ($app): void {
        $item = $app->clipboardItem();
        ExampleState::$v['clip'] = sprintf(
            'text=%s image=%s paths=%d',
            $item['text'] === null ? '-' : strlen($item['text']),
            $item['image'] === null ? '-' : strlen($item['image']),
            count($item['paths'])
        );
    });
    $app->on('sy-url', 'click', function () use ($app): void {
        $ok = $app->openUrl('https://example.com');
        $rej = $app->openUrl('javascript:alert(1)');
        ExampleState::$v['clip'] = 'openUrl 放行=' . var_export($ok, true) . ' 拒绝=' . var_export($rej, true);
    });
    $app->on('sy-pick', 'click', function () use ($app): void {
        $p = $app->promptForPath(false, '选一个文件');
        if ($p !== null) {
            ExampleState::$v['picked'] = $p;
        }
    });
    $app->on('sy-fs', 'click', function (): void {
        // 授权根必须是**已存在**的目录：Capabilities::ResolvePath 只做前缀判断，
        // 不会替你把根建出来，授一个还不存在的目录会让后面每一步都吃"不在根里"。
        $root = '/tmp';
        $work = '/tmp/gpui-system/nest';
        App::grantRoot($root, true);
        $log = [];
        $log[] = 'mkdir=' . var_export(App::fsMkdir($work, true), true);
        $log[] = 'write=' . var_export(App::fsWrite($root . '/nest/a.txt', "第一段\n第二段"), true);
        $log[] = 'read=' . var_export(App::fsRead($root . '/nest/a.txt'), true);
        $log[] = 'exists=' . var_export(App::fsExists($root . '/nest/a.txt'), true);
        $truncated = false;
        $entries = App::fsList($work, $truncated) ?? [];
        $names = [];
        foreach ($entries as $e) {
            $names[] = (string) $e['name'];
        }
        sort($names);
        $log[] = 'list=' . implode(',', $names) . ' truncated=' . var_export($truncated, true);
        $log[] = 'outside=' . var_export(App::fsRead('/etc/hosts'), true) . '（' . App::fsError() . '）';
        $log[] = 'remove=' . var_export(App::fsRemove($work, true, true), true);
        ExampleState::$v['sysLog'] = $log;
    });
    $app->on('sy-proc', 'click', function (): void {
        App::grantExecute(['/bin/echo']);
        $r = App::processRun('/bin/echo', ['gpui 的输出']);
        $denied = App::processRun('/bin/cat', ['/etc/passwd']);
        ExampleState::$v['sysLog'] = array_merge(ExampleState::$v['sysLog'], [
            'echo=' . ($r === null ? 'null' : 'code=' . $r['code'] . ' out=' . trim($r['out'])),
            'cat(未授权)=' . var_export($denied, true) . '（' . App::fsError() . '）',
        ]);
    });
    $app->on('sy-store', 'click', function (): void {
        $before = App::storageSet('k', 'v');
        App::grantStorage(true);
        App::storagePath('/tmp/gpui-system-store.json');
        $set = App::storageSet('theme', 'dark');
        ExampleState::$v['sysLog'] = array_merge(ExampleState::$v['sysLog'], [
            '未授权 set=' . var_export($before, true),
            '授权后 set=' . var_export($set, true) . ' get=' . var_export(App::storageGet('theme'), true)
                . ' len=' . App::storageLen(),
            'remove=' . var_export(App::storageRemove('theme'), true),
        ]);
    });

    $app->on('sv-http', 'click', function () use ($app): void {
        // 127.0.0.1 上一个必然没人听的端口：验整条异步路而不碰外网
        ExampleState::$v['http'] = 'queued=' . var_export($app->httpGet('http://127.0.0.1:1/probe', 'sv-api'), true);
    });
    $app->on('sv-api', 'http', function () use ($app): void {
        $r = $app->httpResponse('sv-api');
        ExampleState::$v['http'] = $r === null ? '应答已被读过' : 'ok=' . var_export($r['ok'], true) . ' status=' . $r['status'];
    });
    $app->on('sv-watch', 'click', function () use ($app): void {
        ExampleState::$v['watchId'] = $app->watchDir('/tmp', 'sv-themes', false);
    });
    $app->on('sv-unwatch', 'click', function () use ($app): void {
        if (ExampleState::$v['watchId'] > 0) {
            $app->unwatchDir(ExampleState::$v['watchId']);
            ExampleState::$v['watchId'] = 0;
        }
    });
    $app->on('sv-themes', 'dirChange', function () use ($app): void {
        ExampleState::$v['watchHits']++;
        $app->toast('目录变了', '/tmp 有写动作为真');
    });
    $app->on('sv-inflate', 'click', function (): void {
        // 外部真数据（开发机 PHP 的 zlib 生成的 "hello gpui"），字节写死。
        // 之前这里用一串手写的 base64，结果 inflate 返回 NULL——本机
        // gzdecode(base64_decode(...)) 同样返回 false，所以错的是**我编的向量**，
        // 不是 nano 的 base64_decode，也不是 gpui 的解压器。锚点要在两边都成立。
        $gzip = "\x1F\x8B\x08\x00\x00\x00\x00\x00\x00\x13\xCB\x48\xCD\xC9\xC9\x57\x48"
            . "\x2F\x28\xCD\x04\x00\x52\x1A\x59\x50\x0A\x00\x00\x00";
        $out = App::inflate($gzip, true);
        ExampleState::$v['inflate'] = $out === null ? '失败' : '解出 ' . strlen($out) . ' 字节：' . $out;
    });
    $app->on('sv-sysinfo', 'click', function (): void {
        $info = App::sysInfo(3);
        $rows = [
            sprintf('cpu=%.1f%% mem=%.0f%% disk=%.0f%% ncpu=%d',
                $info['cpu'], $info['memPct'], $info['diskPct'], $info['ncpu']),
            // 键名是 bridge 给的 'memUsed'/'memTotal'（cpp-src/gpui_bridge.cc:800-801），
            // 不是 C 结构体那侧的 memUsedBytes——写错不报错，(int) null 就是 0，
            // 所以这一行在画廊里一直显示 "mem 0 / 0"，直到 examples/system 的 sysInfo 门把它照出来。
            sprintf('mem %s / %s', fmtBytes((int) $info['memUsed']), fmtBytes((int) $info['memTotal'])),
            sprintf('battery present=%s charging=%s pct=%.0f%%',
                var_export($info['batteryPresent'], true), var_export($info['batteryCharging'], true), $info['batteryPct']),
        ];
        foreach ($info['procs'] as $p) {
            $rows[] = sprintf('  pid=%d %s cpu=%.1f%% mem=%s', $p['pid'], $p['name'], $p['cpu'], fmtBytes((int) $p['memoryBytes']));
        }
        ExampleState::$v['sysInfo'] = $rows;
    });
    foreach (App::FPS_ANCHORS as $a) {
        $app->on('sv-fps-' . $a, 'click', function () use ($app, $a): void {
            ExampleState::$v['hud'] = $app->showFpsHud($a);
            ExampleState::$v['fpsAnchor'] = $a;
        });
    }
    $app->on('sv-fps-off', 'click', function () use ($app): void {
        $app->hideFpsHud();
        ExampleState::$v['hud'] = $app->fpsHudVisible();
    });
    $app->on('sv-insp', 'click', function () use ($app): void {
        $app->toggleInspector();
    });
    $app->on('sv-theme', 'click', function () use ($app): void {
        ExampleState::$v['theme'] = ExampleState::$v['theme'] === 'dark' ? 'light' : 'dark';
        $app->setTheme(ExampleState::$v['theme']);
    });
}
