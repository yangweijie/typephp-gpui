<?php

declare(strict_types=1);

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;

/**
 * Hello GPUI — 点击计数 + 实时输入 + 滑块。
 *
 * PHP 主循环驱动，状态改一处、整树每帧重建（gpui frame-rebuilt 模型）。
 */
function main(int $argc, array $argv): void
{
    if (in_array('--selftest', $argv, true)) {
        runSelfTest(in_array('--expect-bundle', $argv, true));
        return;
    }
    if (in_array('--caps', $argv, true)) {
        runCaps();
        return;
    }
    if (in_array('--clip', $argv, true)) {
        runClip();
        return;
    }
    $shotIdx = array_search('--shot', $argv, true);
    if ($shotIdx !== false) {
        $path = isset($argv[$shotIdx + 1]) ? (string) $argv[$shotIdx + 1] : '/tmp/hello.png';
        $tree = isset($argv[$shotIdx + 2]) ? (int) $argv[$shotIdx + 2] : 0;
        runShot($path, $tree);
        return;
    }

    $state = ['count' => 0, 'name' => '', 'vol' => 30, 'path' => '', 'clip' => '', 'note' => '',
              'notes' => 0, 'mac' => '', 'trayId' => 0];

    $app = new App();
    $app->create();
    $app->createWindow('Hello GPUI', 800, 600);

    // --automation <dir>：外部脚本把命令写成 <dir>/command-<n>.txt，本帧吃完在
    // <dir>/snapshot.txt 里回执（动词见 App::automationExec）。无人值守真输入验收走这条，
    // 不再需要人坐在机器前按——本地探针与 CI 都能驱动。见 task_plan.md Phase 18。
    $autoIdx = array_search('--automation', $argv, true);
    if ($autoIdx !== false && isset($argv[$autoIdx + 1]) && $argv[$autoIdx + 1] !== '') {
        $app->automation((string) $argv[$autoIdx + 1]);
    }

    // 每帧按 $state 重新描述界面
    $app->view(function () use (&$state): El {
        $greet = $state['name'] !== '' ? "Hello, {$state['name']}!" : 'Hello, World!';
        return El::vFlex([
            El::text($greet)->font(20.0)->semibold(),
            El::text("Clicked: {$state['count']} times — vol: {$state['vol']}")->font(14.0),
            El::input()->id('name')->placeholder('Your name')->onChange(),
            El::slider()->id('vol')->onChange(),
            El::hFlex([
                El::catalogButton('inc', '+1')->onClick()->primary(),
                El::catalogButton('reset', 'Reset')->onClick()->ghost(),
                El::catalogButton('pick', 'Open…')->onClick()->ghost(),
                El::catalogButton('clip', 'Clipboard')->onClick()->ghost(),
                El::catalogButton('note', 'Notify')->onClick()->ghost(),
                El::catalogButton('tray', 'Tray')->onClick()->ghost(),
                El::catalogButton('dock', 'Dock 3')->onClick()->ghost(),
            ])->gap(8.0)->itemsCenter(),
            El::text($state['path'] !== '' ? "Picked: {$state['path']}" : 'Open… 走原生对话框（无人值守自检里不测，见 runSelfTest 注释）')->font(12.0),
            // 剪贴板图像/文件通道的**人工**验收：截一张图（⌘⇧⌃3）或从 Finder 复制文件，
            // 再点 Clipboard——这里会报三种表示各有多少。自检只验到文本通道。
            El::text($state['clip'] !== '' ? "Clipboard: {$state['clip']}" : 'Clipboard 按钮读粘贴板的文本/图像/文件三种表示')->font(12.0),
            // 通知的 click/close 只能人点：合成指针打不到浮层，自检里只打得通 close。
            El::text($state['note'] !== '' ? "Notify: {$state['note']}" : 'Notify 按钮推一条应用内通知，点它看 click 事件')->font(12.0),
            // 窗口外能力的人工验收入口：机器看不见菜单栏与 Dock，只能人看。
            El::text($state['mac'] !== '' ? "Mac: {$state['mac']}" : 'Tray 挂菜单栏图标（⌃⌥⌘F13 是全局快捷键）、Dock 按钮写角标')->font(12.0),
            // 通知层本体。绝对定位盖住窗口，不参与上面的居中排布；卡片贴的是窗口边角，
            // 不是这颗节点的盒子。ShellRoot 不替我们挂它，所以必须自己摆。
            El::notifications('notes')->sizeFull()->absolute(),
        ])
            ->sizeFull()
            ->gap(8.0)
            ->itemsCenter()
            ->justifyCenter();
    });

    // 事件处理器只改状态；问候读 **state 当前文本**（参考 examples/input.cpp 的 OnChange），
    // 不信事件载荷：合成 change 带过来的是 true，信载荷会把问候画成 "Hello, 1!"
    // （2026-10-22 在 input 那一支用 automation + screenshot 实测到同一种形状）。
    $app->on('inc', 'click', function () use (&$state): void {
        $state['count']++;
    });
    $app->on('reset', 'click', function () use (&$state): void {
        $state['count'] = 0;
    });
    $app->on('name', 'change', function () use ($app, &$state): void {
        $state['name'] = $app->text('name');
    });
    // vol 没有 state 取值口（App 只有 text()/checked()，slider 不在 inputHandles 表里），
    // 只能信载荷：合成 change 下它会读到 1。这是已知边界，input 那一支的 in-vol 同形状。
    $app->on('vol', 'change', function (array $e) use (&$state): void {
        $state['vol'] = (int) ($e['value'] ?? 0);
    });
    // 对话框自带嵌套消息循环，跑到用户选完为止：只能在事件回调里调，
    // 不能在一次 render 里调。
    $app->on('pick', 'click', function () use ($app, &$state): void {
        $picked = $app->promptForPath(false, '选一个文件');
        if ($picked !== null) {
            $state['path'] = $picked;
        }
    });
    $app->on('clip', 'click', function () use ($app, &$state): void {
        $item = $app->clipboardItem();
        $parts = [];
        $parts[] = $item['text'] !== null ? 'text=' . strlen($item['text']) : 'text=-';
        $parts[] = $item['image'] !== null ? 'image=' . strlen($item['image']) . 'B' : 'image=-';
        $parts[] = 'paths=' . count($item['paths']);
        $state['clip'] = implode(' ', $parts);
    });
    // 通知：带 id 才接线。click 这一侧机器验不了（合成指针打不到浮层），所以留在这里给人点。
    $app->on('note', 'click', function () use ($app, &$state): void {
        $state['notes']++;
        $n = $app->notify(
            '第 ' . $state['notes'] . ' 条',
            '5 秒后自动退场；点卡片正文会回到这里',
            'info',
            'top_right',
            5000,
            'demo-note'
        );
        $state['note'] = 'pushed #' . $n . '，在场 ' . $app->notificationCount() . ' 条';
    });
    $app->on('demo-note', 'click', function () use ($app, &$state): void {
        $state['note'] = '卡片被点了（click 事件回来了）';
        $app->toast('你点了通知卡', 'App::notify 传了 id 才接得到');
    });
    $app->on('demo-note', 'close', function () use (&$state): void {
        $state['note'] = '一张卡片退场了（close 事件回来了）';
    });
    // 托盘与 Dock 角标：这两条没有机器可见的证据，按下去自己看菜单栏 / Dock。
    $app->on('tray', 'click', function () use ($app, &$state): void {
        if ($state['trayId'] > 0) {
            $app->removeTray($state['trayId']);
            $state['trayId'] = 0;
            $state['mac'] = '托盘已撤，count=' . $app->trayCount();

            return;
        }
        $state['trayId'] = $app->tray(['来自 demo 的菜单项', '再点一下试试'], 'demo-tray', 'GPUI demo');
        // ⌃⌥⌘F13：F13 的 kVK 是 105，control|option|cmd = 4096|2048|256。
        $app->onHotkey('demo-hk', 105, ['cmd', 'option', 'control']);
        $state['mac'] = '托盘 id=' . $state['trayId'] . '，快捷键数=' . $app->hotkeyCount()
            . '；去别的 app 里按 ⌃⌥⌘F13';
    });
    $app->on('demo-tray', 'tray', function (array $e) use (&$state): void {
        $state['mac'] = '托盘事件：' . (string) ($e['value'] ?? '(点击本体)');
    });
    $app->on('demo-hk', 'hotkey', function () use (&$state): void {
        $state['mac'] = '全局快捷键响了（在别的 app 里按也算）';
    });
    $app->on('dock', 'click', function () use (&$state): void {
        App::dockBadge($state['mac'] === 'dock:3' ? '' : '3');
        $state['mac'] = $state['mac'] === 'dock:3' ? 'dock:cleared' : 'dock:3';
    });

    $app->loop();
}

/**
 * --selftest：无需用户交互，验证 retained 取值 + 点击事件两条链路。
 * 退出码 0=通过。
 */
/** 读 PNG 的 IHDR 宽度（第 16..19 字节，大端）。文件不存在或过短返回 0。 */
function armPngWidth(string $path): int
{
    if (!is_file($path)) {
        return 0;
    }
    $raw = file_get_contents($path);
    if (!is_string($raw) || strlen($raw) < 20) {
        return 0;
    }
    $hdr = substr($raw, 16, 4);
    return (ord($hdr[0]) << 24) + (ord($hdr[1]) << 16) + (ord($hdr[2]) << 8) + ord($hdr[3]);
}

function runSelfTest(bool $expectBundle = false): void
{
    $app = new App();
    $app->create();
    $win = $app->createWindow('SelfTest', 400, 300);

    // render 一棵含 input + 可点击 button + catalog DropdownMenu 的树：
    // bridge 见到 kind=input+id 即建 retained；见到 onClick 即注册 callbackId；
    // DropdownMenu.item 的回调标记 ['__cb__',...] 触发 gpui_shim_new_callback →
    // runtime 登记带 shimId 的合成回调。
    $app->render(El::vFlex([
        El::input()->id('name')->placeholder('type'),
        El::slider()->id('vol'),
        El::catalogButton('inc', 'hit')->onClick(),
        El::dropdownMenu('dd', 'Actions')->item('New', 'dd-new'),
    ]));

    $ok = true;

    // 1) 输入取值回环：setInput → InputSetValue → InputValue → text
    $ok = $ok && $app->setInput('name', 'SelfTest-OK');
    $got = $app->text('name');
    $ok = $ok && $got === 'SelfTest-OK';

    // 2) 点击事件回环：合成 fire → 真实 gpuiEnqueueClick → boxForView 反查 →
    //    队列 → gpui_poll_event → GpuiEvent → PHP dispatch → handler 改状态
    $clicks = 0;
    $app->on('inc', 'click', function () use (&$clicks): void {
        $clicks++;
    });
    $fired = \gpui_selftest_fire($win, 'inc', 'click');
    $event = \gpui_poll_event($win);
    if (!empty($event)) {
        $app->dispatch($event);
    }
    $ok = $ok && $fired && $clicks === 1;

    // 3) 定时器回环：setTimer → WindowSetInterval → tick 监听 → gpuiEnqueueTimer
    //    (strong) → boxForView 反查 → 队列 → loop 的 pump+drain → dispatch → handler
    $timerHits = 0;
    $app->on('tick', 'timer', function () use (&$timerHits): void {
        $timerHits++;
    });
    $app->setTimer('tick', 10);
    $app->loop(20);
    $ok = $ok && $timerHits > 0;

    // 4) Dialog 回环：openDialog → ShellRootOpenDialogView → dialogCount>0 →
    //    closeDialog → dialogCount 归零。验证窗口根 ShellRoot overlay 链路。
    $before = $app->dialogCount();
    $opened = $app->openDialog(El::vFlex([El::text('dlg'), El::catalogButton('dx', 'x')])->p(16.0));
    $during = $app->dialogCount();
    $closed = $app->closeDialog();
    $after = $app->dialogCount();
    $ok = $ok && $opened >= 1 && $during === $before + 1 && $closed && $after === $before;

    // 5) catalog 组件回调回环：DropdownMenu.item 的回调经 gpui_shim_new_callback
    //    登记为带 shimId 的 runtime 回调。直接 InvokeWith → shimId 旁路 →
    //    gpuiEnqueueShimCallback → box->enqueue → 队列 → poll → dispatch → handler。
    $ddFires = 0;
    $app->on('dd-new', 'click', function () use (&$ddFires): void {
        $ddFires++;
    });
    $ddFired = \gpui_selftest_fire_registered($win, 'dd-new', 'click');
    $ddEvent = \gpui_poll_event($win);
    if (!empty($ddEvent)) {
        $app->dispatch($ddEvent);
    }
    $ok = $ok && $ddFired && $ddFires === 1;

    // 6) registered 错误回传：空 id 的 Alert 构造被 factory 拒（NonEmptyId）→
    //    shim 记 lastError，PHP 经 gpui_last_error 取回（不再只藏在 debug_tree）。
    $app->render(El::alert('', 'no id'));  // render_begin 清空 → emitTree 落错
    $err = $app->registeredError();
    $ok = $ok && $err !== '';

    // 7) 原生 app 菜单条安装：setAppMenus → BaseSetAppMenus（深拷）→ appMenuCount 读回。
    //    macOS 系统菜单栏在屏幕顶、--shot 抓不到，故用数量断言验证安装链路。
    $menuN = $app->setAppMenus([
        ['name' => 'File', 'items' => [
            ['label' => 'New', 'action' => 'file.new'],
            ['separator' => true],
            ['label' => 'Quit', 'action' => 'app.quit'],
        ]],
        ['name' => 'Edit', 'items' => [
            ['label' => 'Copy', 'action' => 'edit.copy'],
            ['label' => 'Paste', 'action' => 'edit.paste'],
        ]],
    ]);
    $menuCount = $app->appMenuCount();
    $ok = $ok && $menuN === 2 && $menuCount === 2;

    // 8) 剪贴板回环：写系统粘贴板再读回。走的是 NSPasteboard，不是我们的缓存，
    //    所以这条绿的是"gpui 的粘贴板实现真被接到了 PHP"。
    $payload = "SELFTEST-{$timerHits}-Ω";
    $app->setClipboardText($payload);
    $clip = $app->clipboardText();
    $ok = $ok && $clip === $payload;

    // 9) openUrl 白名单：只验被拒的一侧。放行的一侧会真的拉起浏览器，
    //    无人值守的自检不该有那种副作用；接受路径由 tests/cpp/shim_test.cpp
    //    直接量 gpui::IsOpenableUrl 钉住。
    $ok = $ok && $app->openUrl('javascript:alert(1)') === false;

    // 10) 帧率 HUD 生命周期：show → visible → 重复 show（只是移动，仍 true）→
    //     hide → 再 hide 返回 false。
    $fps1 = $app->showFpsHud('top_right');
    $fpsVis = $app->fpsHudVisible();
    $fps2 = $app->showFpsHud('bottom_left');
    $fpsOff = $app->hideFpsHud();
    $fpsAgain = $app->hideFpsHud();
    $ok = $ok && $fps1 && $fpsVis && $fps2 && $fpsOff && $fpsAgain === false;

    // 11) Sheet 生命周期：openSheet → hasSheet → closeSheet → hasSheet 假。
    //     内容 Box2 复用 dialog_begin 那条路径，所以这条同时验 overlay 内容盒子。
    $sheetOpened = $app->openSheet(El::vFlex([El::text('sheet')])->p(16.0), 'right');
    $sheetDuring = $app->hasSheet();
    $sheetClosed = $app->closeSheet();
    $ok = $ok && $sheetOpened === 1 && $sheetDuring && $sheetClosed && $app->hasSheet() === false;

    // 12) WebView：ChildView + host 注册的 nested view 真建出了 OS 控件。
    //     探针读的是 wry 报的当前地址与应用盒子（不是 shim 自己记的数）。--shot 里
    //     webview 那块是空白——离屏 cacheDisplayInRect 合成不了跨进程表面——所以这条
    //     才是它的机器验收口；"在屏真看得见"仍属人看项。
    $app->render(El::vFlex([
        El::webviewHtml('<html><body style="margin:0;background:#1e6fd9">wv</body></html>', 'wv'),
    ])->sizeFull());
    $app->loop(3);
    $probe = $app->webviewProbe('wv');
    $navBack = $app->webviewBack('wv');
    $navFwd = $app->webviewForward('wv');
    $ok = $ok && $probe['ok'] && $probe['w'] > 0 && $probe['h'] > 0 && $navBack && $navFwd;

    // 13) 解压：喂**外部真数据**——这两串是开发机上 PHP 的 zlib 生成的 "hello gpui"
    //     的 zlib / gzip 容器（不是 gpui 自己写的 stored 壳），所以它量的是
    //     "gpui 的解压器读得懂标准 deflate 流"。字节用转义写死，不依赖 hex2bin。
    $plain = 'hello gpui';
    $zlib = "\x78\x9C\xCB\x48\xCD\xC9\xC9\x57\x48\x2F\x28\xCD\x04\x00\x15\x74\x03\xEA";
    $gzip = "\x1F\x8B\x08\x00\x00\x00\x00\x00\x00\x13\xCB\x48\xCD\xC9\xC9\x57\x48"
        . "\x2F\x28\xCD\x04\x00\x52\x1A\x59\x50\x0A\x00\x00\x00";
    $infZ = App::inflate($zlib);
    $infG = App::inflate($gzip, true);
    $infBad = App::inflate('not a zlib stream');
    $ok = $ok && $infZ === $plain && $infG === $plain && $infBad === null;

    // 14) 目录监听：走 gpui 自己的测试接缝（DirWatchAdd + DirWatchSignal），完全不碰
    //     文件系统，验的是"信号 → 主线程 post → 入队 → poll → dispatch"整条路。
    //     OS 那一半（FSEvents/inotify/ReadDirectoryChangesW）是 gpui 自己的代码。
    $watchHits = 0;
    $app->on('themes', 'dirChange', function () use (&$watchHits): void {
        $watchHits++;
    });
    $watchId = \gpui_watch_fake($win, 'themes');
    $app->loop(5);
    $ok = $ok && $watchId > 0 && $watchHits > 0;

    // 15) HTTP 路由：打一个 loopback 上必然没人监听的端口（127.0.0.1:1），于是整条
    //     异步路（executor → 主线程 done → 入队 → poll → 取应答）都走通而不碰外网。
    //     断言的是"应答事件到了 + 取到的内容是 ok=false/status=0"，不是"能上网"。
    $httpHits = 0;
    $app->on('probe', 'http', function () use (&$httpHits): void {
        $httpHits++;
    });
    $schemeRejected = $app->httpGet('data:text/plain,hi', 'probe') === false;
    $queued = $app->httpGet('http://127.0.0.1:1/nope', 'probe');
    $app->loop(60);
    $resp = $app->httpResponse('probe');
    $again = $app->httpResponse('probe');
    $ok = $ok && $schemeRejected && $queued && $httpHits > 0
        && $resp !== null && $resp['ok'] === false && $resp['status'] === 0
        && $again === null;

    // 16) 剪贴板完整一项：刚写过文本，所以同一快照里应当只有文本——图像与文件必须报
    //     "没有"，这验的是 flags 的判别而不是粘贴板内容（写文本会替换掉整个粘贴板）。
    //     图像/文件两条通道的真实读取属人工项：截一张图或从 Finder 复制文件，
    //     再看 demo 的 Clipboard 按钮报的字节数。
    $app->setClipboardText("ITEM-{$timerHits}");
    $item = $app->clipboardItem();
    $itemText = $item['text'] ?? '';
    $itemImage = $item['image'] ?? '';
    $ok = $ok && $itemText === "ITEM-{$timerHits}"
        && $item['image'] === null && $item['paths'] === [];

    // 17) 系统状态。mem/disk/ncpu 不是差值量，第一次采样就能断言非零；cpu 是两次采样
    //     之间的差值，所以先断言"第一次报 0"（这是文档承诺的行为），再烧 ~150ms 单核
    //     把全机占用推起来，第二次采样必须 > 0。烧单核在 8 核机器上约 12%，离 0 有
    //     足够余量——不是靠噪声过关。
    $sys1 = App::sysInfo();
    $firstCpuZero = ($sys1['cpu'] ?? -1.0) === 0.0;
    $noProcList = ($sys1['procs'] ?? null) === [];
    $sink = 0;
    $burnUntil = microtime(true) + 0.15;
    while (microtime(true) < $burnUntil) {
        $sink++;
    }
    $app->loop(2);
    $sys2 = App::sysInfo(5);
    $procs = is_array($sys2['procs'] ?? null) ? $sys2['procs'] : [];
    $topNamed = $procs !== [] && (string) ($procs[0]['name'] ?? '') !== ''
        && (int) ($procs[0]['pid'] ?? 0) > 0;
    $ok = $ok && (int) $sys1['ncpu'] > 0
        && (int) $sys1['memTotal'] > 0 && (int) $sys1['memUsed'] > 0
        && (float) $sys1['memPct'] > 0.0
        && (int) $sys1['diskTotal'] > 0 && (float) $sys1['diskPct'] > 0.0
        && $firstCpuZero && $noProcList
        && (float) $sys2['cpu'] > 0.0
        && count($procs) > 0 && count($procs) <= 5 && $topNamed;

    // 18) 应用内通知中心：推一条 → count 起来 → dismiss → 退场走完后 count 归零，
    //     并且 onClose 作为 close 事件回到 PHP。退场由 gpui 自己的 50ms 时钟推进，
    //     所以这一段要泵足够多帧。注意这里**没有**挂 El::notifications()：push/dismiss
    //     与画不画无关（状态住在窗口层），"看得见"那一半归 tree32 的像素验收。
    $notifEvents = [];
    $app->on('saved', 'close', function () use (&$notifEvents): void {
        $notifEvents[] = 'close';
    });
    $app->on('saved', 'click', function () use (&$notifEvents): void {
        $notifEvents[] = 'click';
    });
    $nid = $app->notify('已保存', '3 个文件', 'success', 'top_right', 5000, 'saved');
    $countPush = $app->notificationCount();
    $notifDismissed = $nid > 0 && $app->dismissNotification($nid);
    $app->loop(60);
    $countAfter = $app->notificationCount();
    $ok = $ok && $nid > 0 && $countPush === 1 && $notifDismissed
        && $countAfter === 0 && $notifEvents === ['close'];

    // 19) mac 系统集成：托盘与快捷键的注册/撤销计数，加上一条**合成** hotkey 事件走完整条
    //     Carbon 派发链（队列 → 派发 → handler → 事件出口 → 窗口队列 → poll）。
    //     它证明的是派发链通，不证明"系统真的全局捕获了这组键"——那一条只能人按。
    $tray0 = $app->trayCount();
    $trayId = $app->tray(['显示窗口', '退出'], 'tray-test', 'selftest');
    $tray1 = $app->trayCount();
    $app->removeTray($trayId);
    $tray2 = $app->trayCount();
    $hkEvents = [];
    $app->on('hk', 'hotkey', function () use (&$hkEvents): void {
        $hkEvents[] = 'hotkey';
    });
    $hkId = $app->onHotkey('hk', 105, ['cmd', 'shift']);   // F13 + cmd+shift
    $hkCount = $app->hotkeyCount();
    $fired = App::fireHotkeyForTest(0);
    $app->loop(3);
    $badFire = App::fireHotkeyForTest(99);
    App::dockBadge('3');
    $osn = App::osNotify('selftest', '这条横幅不保证出现（裸可执行文件没有 bundle id）');
    $macOk = $tray0 === 0 && $trayId > 0 && $tray1 === 1 && $tray2 === 0
        && $hkId > 0 && $hkCount === 1 && $fired && $hkEvents === ['hotkey']
        && !$badFire;
    $ok = $ok && $macOk;

    // 20) 授权文件系统：先 grantRoot，再让 gpui 自己建目录/写/读，并与 PHP 自己的文件系统
    //     调用**双向**对照——gpui 写的 PHP 要读得到，PHP 写的 gpui 也要读得到。
    //     反向锚：没授予过的路径必须失败，且原因说得出"根"。
    App::grantRoot('/tmp', true);
    $mk = App::fsMkdir('/tmp/gpui-selftest', true);
    $w1 = App::fsWrite('/tmp/gpui-selftest/a.txt', "gpui 写的\n第二段");
    $phpRead = @file_get_contents('/tmp/gpui-selftest/a.txt');
    $phpWrite = @file_put_contents('/tmp/gpui-selftest/b.bin', "\x00\x89PNG中文");
    $gpuiRead = App::fsRead('/tmp/gpui-selftest/b.bin');
    $listed = App::fsList('/tmp/gpui-selftest', $truncated);
    $names = [];
    foreach ((array) $listed as $e) {
        $names[] = (string) $e['name'];
    }
    sort($names);
    $denied = App::fsRead('/etc/hosts');
    $deniedWhy = App::fsError();
    $rm = App::fsRemove('/tmp/gpui-selftest', true, true);
    $fsOk = $mk && $w1 && $phpRead === "gpui 写的\n第二段" && $phpWrite !== false
        && $gpuiRead === "\x00\x89PNG中文" && $names === ['a.txt', 'b.bin']
        && !$truncated && $denied === null && str_contains($deniedWhy, '根') && $rm;
    $ok = $ok && $fsOk;

    // 21) 进程白名单与存储授权：白名单外的命令必须被拒（拿 /bin/cat 当反例，它不去联网
    //     也不写盘）；存储没授权时 Set 直接失败。
    App::grantExecute(['/bin/echo']);
    $proc = App::processRun('/bin/echo', ['gpui-selftest']);
    $procDenied = App::processRun('/bin/cat', ['/etc/hosts']);
    $procWhy = App::fsError();
    $storeDenied = App::storageSet('k', 'v');
    App::grantStorage(true);
    $storeOkPath = App::storagePath('/tmp/gpui-selftest-store.json');
    $set = App::storageSet('theme', 'dark');
    $get = App::storageGet('theme');
    $len = App::storageLen();
    App::storageRemove('theme');
    @unlink('/tmp/gpui-selftest-store.json');
    $svcOk = $proc !== null && str_contains($proc['out'], 'gpui-selftest')
        && $proc['code'] === 0 && $procDenied === null && str_contains($procWhy, '白名单')
        && $storeDenied === false && $storeOkPath && $set && $get === 'dark' && $len === 1;
    $ok = $ok && $svcOk;

    // 22) 定时器按 id 幂等重挂：同一个 id 挂 50 次，键表只许多一条。
    //     修之前每挂一次净发一个新号、旧号没人收（0→51），长会话就是只增不减的表。
    //     取数点在本组之前，所以基线是当时的实际条数，不假设 hello 自己没挂过定时器。
    $tk0 = (int) ($app->stateCounts()['timerKeys'] ?? -1);
    for ($grow = 0; $grow < 50; $grow++) {
        $app->setTimer('grow-check', 40);
    }
    $tk1 = (int) ($app->stateCounts()['timerKeys'] ?? -2);
    $app->setTimer('grow-check', 0);
    $timerNoGrow = $tk1 === $tk0 + 1;
    $ok = $ok && $timerNoGrow;

    // 23) onAny 通配缝：按类型收所有 id 的事件，handler 拿的是**整个 event 数组**
    //     （不是 value——那是 id-specific 的另一套读法）。画廊里它是 `any=71`，
    //     量的是"通配 handler 真被喂到、且认得出来源 id"，注册不报错不算。
    $anyHits = 0;
    $anyId = '';
    $app->onAny('click', static function (array $e) use (&$anyHits, &$anyId): void {
        $anyHits++;
        $anyId = (string) ($e['id'] ?? '');
    });
    if (\gpui_selftest_fire($win, 'inc', 'click')) {
        $anyEvent = \gpui_poll_event($win);
        if (!empty($anyEvent)) {
            $app->dispatch($anyEvent);
        }
    }
    $anyOk = $anyHits >= 1 && $anyId === 'inc';
    $ok = $ok && $anyOk;

    // 24) 生命周期、脏门与每帧重描述（从画廊迁来）。这几颗 API 在全仓只有画廊调过
    //     （invalidate / runFrames / frameCount / stop / isOpen / lastError / activate /
    //     foregroundTries），删掉画廊又不搬过来就是**静默**丢覆盖。
    //     顺序有讲究：帧数只在有 view 回调时才计，所以先注册计数 view 再 runFrames；
    //     会自己叫停的那颗必须最后注册——早装就把后面所有读数截断成一帧。
    $openBefore = $app->isOpen();
    $lifeFrames = 0;
    $lifeText = 'lifecycle-A';
    $app->view(static function () use (&$lifeFrames, &$lifeText): El {
        $lifeFrames++;
        return El::text($lifeText)->font(12.0);
    });
    $app->runFrames(3);
    $frames3 = $app->frameCount();
    $lifeOk = $openBefore && $frames3 === 3;

    // 脏门两半都要（只测一半能靠"永远不画"或"永远都画"蒙过去）：
    // 反半——把所有定时器撤掉再空转 40 帧，只许画 1 次（重进循环必补一帧是留着语义的，
    //        画廊第一版漏撤一个 tick 计时器就红在 idle=1，红得对）；
    // 正半——真塞一条事件进去，渲染计数必须增加。
    $app->setTimer('tick', 0);
    $rendersBefore = $app->renderCount();
    $app->runFrames(40);
    $idleRenders = $app->renderCount() - $rendersBefore;
    if (\gpui_selftest_fire($win, 'inc', 'click')) {
        $wakeEvent = \gpui_poll_event($win);
        if (!empty($wakeEvent)) {
            $app->dispatch($wakeEvent);
        }
    }
    $app->loop(2);
    $wakesOnEvent = $app->renderCount() > $rendersBefore + $idleRenders;
    $dirtyGate = $idleRenders === 1 && $wakesOnEvent;

    // view() 每帧真的重新描述：改状态 + 显式标脏之后，debug_tree 要出现新文案。
    // 不标脏的话空帧被跳过，读到的还是旧节——那这条门量的就成了"没画"，不是"画错了"。
    $lifeText = 'lifecycle-B';
    $app->invalidate();
    $app->loop(2);
    $treeAfter = \gpui_debug_tree($app->handle());
    $rerenderOk = str_contains($treeAfter, 'lifecycle-B');
    $stopFrames = 0;
    $app->view(static function () use ($app, &$stopFrames): El {
        $stopFrames++;
        $app->stop();
        return El::text('stopped')->font(12.0);
    });
    // view() 注册时预热一帧（首帧没有命中表），那一帧不算进"循环里的帧"。
    $primed = $stopFrames;
    $app->loop(50);
    $stoppedAfterOneFrame = ($stopFrames - $primed) === 1;
    $lastErr = $app->lastError();
    // 取一个不存在的 id：必须是空串，不能是垃圾，也不能抛。
    $missingText = $app->text('no-such-input');
    $lifeOk = $lifeOk && $dirtyGate && $rerenderOk && $stoppedAfterOneFrame
        && $lastErr === '' && $missingText === '';
    $ok = $ok && $lifeOk;

    // 25) bundle 身份与事件路由（两半都要说清自己是哪条路给的绿）：
    //     - bundleGreen：从 .app 起进程（`bin/gpui selftest` 会带 --expect-bundle）时
    //       bundled 必须为真，这是产物属性。裸二进制记为"未验"，不装成验过。
    //     - routeGreen：已经拿到前台时，事件缝不许退回直投 -[NSWindow sendEvent:]，
    //       走真 NSApp 路由（postEvent → nextEventMatchingMask → -[NSApp sendEvent:]）
    //       的计数必须 > 0。
    //     上面 1)~24) 全走 \gpui_selftest_fire 那条直投缝，它不经过 NSApp 路由，
    //     所以这里必须真投一次鼠标事件才量得到这条门——不补这一跳 routeGreen 是恒假，
    //     不是"路不通"。别拿窗口边角当落点：resize 边上 AppKit 的迟滞判定会不返回。
    //     frontmost **不当硬门**：前台是和当时握着焦点的一方竞争出来的状态，
    //     macOS 会正当拒绝后台进程抢焦点，拿它当门就变成随机红灯；所以只报读数。
    $app->activate();
    $app->realMouseMove(200, 150);
    $app->realClick(200, 150);
    $app->loop(3);
    $winEnd = $app->windowState();
    $bundled = (bool) $winEnd['bundled'];
    $frontmost = (bool) $winEnd['active'] && (bool) $winEnd['key'];
    $nsappDeliveries = (int) $winEnd['nsappDeliveries'];
    $fgTries = $app->foregroundTries();
    $bundleGreen = !$expectBundle || $bundled;
    $routeGreen = !($bundled && $frontmost) || $nsappDeliveries > 0;
    $ok = $ok && $bundleGreen && $routeGreen;

    // #90 的判别臂：取帧必须绑到**传进去的那一扇**，不是 OS 当前偏爱的那一扇。
    // 做法：同进程再开一扇尺寸不同的窗口（新窗口会接走 key），然后各按自己的句柄取帧。
    // 绑对了 ⇒ 两张 PNG 的 IHDR 宽度必然不同；绑错（旧实现先 [NSApp keyWindow]、拿不到
    // 再退化成 [NSApp windows] 的第一个，传进来的窗口根本没参与选窗）⇒ 两次拍到同一扇
    // ⇒ 宽度相等 ⇒ 红。断宽度不断内容：自检里两扇画的是同一棵树，能区分它们的只有尺寸。
    $armWinA = $app->handle();
    $armWinB = $app->createWindow('typephp-gpui snapshot binding arm', 420, 320);
    $app->runFrames(6);
    $armPathA = '/tmp/gpui-arm-a.png';
    $armPathB = '/tmp/gpui-arm-b.png';
    $armShotA = \gpui_snapshot($armWinA, $armPathA, 800, 600);
    $armShotB = \gpui_snapshot($armWinB, $armPathB, 420, 320);
    $armWA = armPngWidth($armPathA);
    $armWB = armPngWidth($armPathB);
    $armBound = $armShotA && $armShotB && $armWA > 0 && $armWB > 0 && $armWA !== $armWB;
    $ok = $ok && $armBound;

    echo 'EXTRA'
        . " snaparm=" . var_export($armBound, true)
        . "/A{$armWA}px/B{$armWB}px shot=" . var_export((bool) $armShotA, true)
        . ',' . var_export((bool) $armShotB, true)
        . " timerKeys={$tk0}->{$tk1} noGrow=" . var_export($timerNoGrow, true)
        . " any={$anyHits}/{$anyId}"
        . " life open=" . var_export($openBefore, true) . "/frames={$frames3}"
            . "/dirty idle={$idleRenders}/40 wake=" . var_export($wakesOnEvent, true)
            . "/rerender=" . var_export($rerenderOk, true)
            . "/stopped=" . var_export($stoppedAfterOneFrame, true)
            . "/err='{$lastErr}' missing='" . $missingText . "'"
        . " win bundled=" . var_export($bundled, true) . "/frontmost=" . var_export($frontmost, true)
            . "/nsapp={$nsappDeliveries}/fgTries={$fgTries}"
            . "/bundleGreen=" . var_export($bundleGreen, true)
            . "/routeGreen=" . var_export($routeGreen, true) . "\n";

    echo $ok
        ? sprintf("SELFTEST OK (input + click + timer hits=%d + dialog + registered-cb + err-channel"
            . " + appmenu + clipboard + urlgate + fps + sheet + webview %.0fx%.0f"
            . " + inflate + dirChange x%d + http拒连回环 ok=%s/status=%d + clipboard-item"
            . " + sys cpu=%.1f%% mem=%.0f%% procs=%d"
            . " + notify x%d→%d %s + mac=%d fs=%d svc=%d)\n",
            $timerHits,
            $probe['w'],
            $probe['h'],
            $watchHits,
            $resp === null ? 'null' : var_export($resp['ok'], true),
            $resp === null ? -1 : $resp['status'],
            (float) $sys2['cpu'],
            (float) $sys2['memPct'],
            count($procs),
            $countPush,
            $countAfter,
            implode(',', $notifEvents),
            $macOk ? 1 : 0,
            $fsOk ? 1 : 0,
            $svcOk ? 1 : 0
        )
        : "SELFTEST FAIL (input='{$got}' fired=" . ($fired ? '1' : '0') . " clicks={$clicks} timer={$timerHits} dlg=$before/$opened/$during/$after ddcb=" . ($ddFired ? '1' : '0') . "/{$ddFires} err='$err' menu=$menuN/$menuCount clip='$clip' want='$payload' fps=$fps1/$fpsVis/$fps2/$fpsOff/$fpsAgain sheet=$sheetOpened/" . ($sheetDuring ? '1' : '0') . "/" . ($sheetClosed ? '1' : '0') . " wv=" . json_encode($probe) . " inf=" . var_export([$infZ, $infG, $infBad], true) . " watch=$watchId/$watchHits http=" . ($schemeRejected ? '1' : '0') . "/$queued/$httpHits/" . json_encode($resp) . " item=" . strlen($itemText) . "/" . strlen($itemImage) . "/" . count($item['paths']) . " sys=" . json_encode([$firstCpuZero, $noProcList, $sys1['ncpu'] ?? null, $sys1['memPct'] ?? null, $sys1['diskPct'] ?? null, $sys2['cpu'] ?? null, count($procs)]) . " notify=" . $nid . "/" . $countPush . "/" . $countAfter . "/" . implode(',', $notifEvents)
            . " mac=" . var_export($macOk, true)
            . " fs=" . var_export($fsOk, true)
            . " svc=" . var_export($svcOk, true) . "/" . $deniedWhy
            . " fsParts=" . json_encode([$mk, $w1, $phpRead !== false, $phpWrite !== false,
                $gpuiRead !== null, $names, $truncated, $denied === null, $rm])
            . " svcParts=" . json_encode([$proc !== null, $proc['code'] ?? null,
                $proc['out'] ?? null, $procDenied === null, $storeDenied, $storeOkPath,
                $set, $get, $len])
            . ")\n";
    $app->close();
    exit($ok ? 0 : 1);
}

/**
 * --caps：让编译产物自己报哪些原生能力已经在 PHP 侧可用。
 *
 * 存在的理由：桥一个 PHP 本来就有的函数是净负价值（多一条 C ABI、多一个会漂移的
 * 替身实现）。"纯计算/压缩/网络"这类服务要不要接，看这张表的结果定，不看上游文档
 * 或 nano 文档的措辞。退出码恒 0，输出供人读。
 *
 * 判据是 function_exists，不是"真调用一次"：这里试过用调用当判据，结果撞到一个
 * nano 的行为差异——把不存在的函数名放进变量再 `$fn()` 走的是回调路径，报
 * `Invalid callback ... not found or invalid function name` 并 **abort（SIGABRT）**，
 * 不抛可 catch 的 Error。nano 的扩展集是 Composer 在编译前定的，不是按源码用没用裁的，
 * 所以 function_exists 就是忠实探针。
 */
function runCaps(): void
{
    $groups = [
        'hash' => ['hash', 'hash_hmac'],
        'random' => ['random_bytes', 'random_int'],
        'zlib' => ['gzcompress', 'gzuncompress', 'gzencode', 'gzdecode'],
        'curl' => ['curl_init', 'curl_exec'],
        'socket' => ['socket_create', 'stream_socket_client'],
        'proc' => ['proc_open', 'exec'],
        'fs' => ['file_get_contents', 'scandir', 'touch'],
        'dirwatch' => ['inotify_add_watch'],
    ];
    foreach ($groups as $name => $fns) {
        $have = [];
        foreach ($fns as $fn) {
            if (function_exists($fn)) {
                $have[] = $fn;
            }
        }
        printf("%-8s %s\n", $name, $have === [] ? '-' : implode(', ', $have));
    }
    exit(0);
}

/**
 * --clip：把剪贴板一项的三种表示打成一行（text=/image=/paths=，-1 表示没有）。
 *
 * 存在的理由：自检里"写文本再读回"只能证明文本通道通 + flags 判别对，图像与文件两条
 * 通道要有**真数据**才验得到。开发机上用 osascript 把一张 PNG 放上粘贴板、再跑这条，
 * 就看到 image=<字节数> ——不联网、不用人眼看窗口。见 docs/guide/system-capabilities.md
 * 的验收清单。
 */
function runClip(): void
{
    $app = new App();
    $app->create();
    $app->createWindow('Clip', 200, 120);
    $app->render(El::text('clip'));
    $app->loop(1);
    $item = $app->clipboardItem();
    printf(
        "text=%d image=%d paths=%d\n",
        $item['text'] === null ? -1 : strlen($item['text']),
        $item['image'] === null ? -1 : strlen($item['image']),
        count($item['paths'])
    );
    $app->close();
    exit(0);
}

/**
 * --shot <path> [tree]：渲染指定测试树若干帧后存 PNG，用于像素级视觉验收。
 * tree 0=完整 demo（默认），1..N=二分排查用最小树。退出码 0=成功出图。
 */
function runShot(string $path, int $tree = 0): void
{
    $app = new App();
    $app->create();
    $app->createWindow('Hello GPUI', 800, 600);

    $app->view(function () use ($tree): El {
        $col = match ($tree) {
            1 => El::vFlex([
                El::text('AAA')->font(20.0),
                El::text('BBB em — dash test')->font(20.0),
            ]),
            2 => El::vFlex([
                El::text('text1')->font(20.0),
                El::input()->id('name')->placeholder('Your name'),
            ]),
            3 => El::vFlex([
                El::text('text1')->font(20.0),
                El::hFlex([
                    El::catalogButton('inc', '+1')->primary(),
                    El::catalogButton('reset', 'Reset')->ghost(),
                ])->gap(8.0),
            ]),
            4 => El::vFlex([
                El::text('text1')->font(20.0),
                El::slider()->id('vol'),
            ]),
            14 => El::vFlex(
                array_map(
                    fn ($i) => El::text("Item " . (string) $i)->font(14.0)->px(8.0),
                    range(1, 50)
                )
            )->sizeFull()->overflowYScroll()->bg(0xF5F5F5),
            12 => El::vFlex([
                El::text('Select + DatePicker')->font(18.0)->semibold(),
                El::select('pick', ['Apple', 'Banana', 'Cherry'])->w(200),
                El::datePicker('date')->w(200),
            ])->p(16.0)->gap(12.0),
            11 => El::vFlex([
                El::textView("# Markdown\n\nSome **bold** and a [link](https://example.com).\n\n- item one\n- item two"),
            ])->p(16.0),
            10 => El::vFlex([
                El::text('Gallery')->font(22.0)->semibold(),
                El::hFlex([
                    El::radio('A', true),
                    El::radio('B'),
                    El::toggle('Tgl'),
                ])->gap(12.0),
                El::popover('open me', El::text('popover content')),
                El::collapsible('section', El::text('hidden panel')),
                El::table()->w(300)->rowCount(2)->columnCount(2)->children([
                    El::tableHeader([
                        El::tableRow([
                            El::tableHead([El::text('H1')])->columnIndex(0),
                            El::tableHead([El::text('H2')])->columnIndex(1),
                        ])->rowIndex(0)->flexRow()->gap(12.0),
                    ]),
                    El::tableBody([
                        El::tableRow([
                            El::tableCell([El::catalogButton('cb', 'cell-btn')])->columnIndex(0),
                            El::tableCell([El::text('—')])->columnIndex(1),
                        ])->rowIndex(1)->flexRow()->gap(12.0),
                    ]),
                ]),
                El::progress(0.6)->w(200),
            ])->p(16.0),
            20 => El::vFlex([
                El::text('Alerts (catalog)')->font(18.0)->semibold(),
                El::alert('a1', 'This is a default alert.'),
                El::alert('a2', 'Something worth knowing.', 'info')->title('Heads up'),
                El::alert('a3', 'Operation completed.', 'success'),
                El::alert('a4', 'Check your inputs.', 'warning')->sizeEnum('large'),
                El::alert('a5', 'Something broke.', 'error')->banner(),
            ])->p(16.0)->gap(8.0)->wFull(),
            21 => El::vFlex([
                El::text('Breadcrumb + Icon (catalog)')->font(18.0)->semibold(),
                El::breadcrumb(['Home', 'Library', 'Data']),
                El::hFlex([
                    // 名字必须在编译进去的 111 个表里：'home' 不在（那是 sidebar 的 navIcon
                    // 逻辑名，另一套命名空间），画出来是一片空白且没有任何报错。
                    El::icon('icons/square-terminal.svg')->sizeEnum('large')->color('#e07b39'),
                    El::icon('icons/settings.svg')->rm('rotate', [1.57]),
                ])->gap(12.0)->itemsCenter(),
            ])->p(16.0)->gap(12.0),
            22 => El::hFlex([
                El::sidebar('sb', [
                    El::sidebarMenu([
                        El::sidebarMenuItem('Home')->navIcon('home')->selected(true),
                        El::sidebarMenuItem('Components')->navIcon('components'),
                        El::sidebarMenuItem('Settings')->navIcon('settings'),
                        El::sidebarMenuItem('Archive')->navIcon('archive'),
                    ]),
                ])
                    ->slot('header', El::sidebarHeader(El::text('Workspace')->font(14.0)->semibold()))
                    ->slot('footer', El::sidebarFooter(El::text('v1.0')->font(12.0)))
                    ->collapsibleMode('icon')
                    ->w(220.0),
                El::vFlex([El::text('Main pane')->font(16.0)])->flex1()->p(16.0),
            ])->sizeFull()->gap(0.0),
            23 => El::vFlex([
                El::text('Form (catalog)')->font(18.0)->semibold(),
                El::form(false, [
                    El::field([El::input()->id('fn')->placeholder('Jane Doe')])->rm('label', ['Full name']),
                    El::field([El::input()->id('fe')->placeholder('jane@example.com')])
                        ->rm('label', ['Email'])->rm('description', ['We never share it']),
                    El::field([El::checkbox('Subscribe', true)])->rm('label', ['Preferences'])->rm('required', [true]),
                ])->columns(1.0)->w(360.0),
            ])->p(16.0)->gap(12.0)->itemsStart(),
            24 => El::vFlex([
                El::text('MenuBar (catalog)')->font(18.0)->semibold(),
                El::menuBar('File', [
                    El::menu('File', [
                        El::menuItem('New', 'file.new'),
                        El::menuItem('Open', 'file.open'),
                        El::menuSeparator(),
                        El::menuItem('Quit', 'app.quit'),
                    ]),
                    El::menu('Edit', [
                        El::menuItem('Copy', 'edit.copy'),
                        El::menuItem('Paste', 'edit.paste'),
                    ]),
                ]),
            ])->p(16.0)->gap(12.0)->wFull(),
            25 => El::vFlex([
                El::text('C-class conveniences')->font(18.0)->semibold(),
                El::progressTrack([El::progressIndicator()->value(0.7)])->w(220.0),
                El::hResizable([
                    El::resizablePanel(El::text('Left panel')->p(12.0)),
                    El::resizablePanel(El::text('Right panel')->p(12.0)),
                ])->w(320.0)->h(120.0),
                El::radioGroup([El::radio('A', true), El::radio('B')])->gap(8.0),
            ])->p(16.0)->gap(12.0)->itemsStart(),
            26 => El::vFlex([
                El::text('DropdownMenu (catalog)')->font(18.0)->semibold(),
                El::dropdownMenu('dd', 'Actions')
                    ->item('New file', 'dd-new')
                    ->item('Open…', 'dd-open')
                    ->item('Quit', 'dd-quit'),
                El::text('Click the button to open the popup menu.')->font(13.0),
            ])->p(16.0)->gap(12.0)->itemsStart(),
            27 => El::vFlex([
                El::text('FPS HUD host')->font(18.0)->semibold(),
                El::text('HUD 由窗口根持有，不在这棵树里。')->font(13.0),
            ])->p(16.0)->gap(8.0)->itemsStart(),
            28 => El::vFlex([
                El::text('Sheet host')->font(18.0)->semibold(),
            ])->p(16.0)->gap(8.0)->itemsStart(),
            29 => El::vFlex([
                El::text('Inspector host')->font(18.0)->semibold(),
            ])->p(16.0)->gap(8.0)->itemsStart(),
            30 => El::vFlex([
                El::text('WebView host')->font(18.0)->semibold(),
                // 本地 HTML：不联网、内容确定，才配当回归基线。
                El::webviewHtml(
                    '<html><head><style>html,body{margin:0;background:#1e6fd9;'
                    . 'color:#fff;font:700 42px/1.6 -apple-system,sans-serif;'
                    . 'text-align:center}</style></head><body>GPUI WEBVIEW</body></html>',
                    'wv1'
                )->w(520.0)->h(360.0),
            ])->p(16.0)->gap(12.0)->itemsStart(),
            31 => El::hFlex([
                El::vFlex([
                    El::text('被检查的内容')->font(18.0)->semibold(),
                    El::input()->id('probe')->placeholder('type here')->onChange(),
                ])->flex1()->p(16.0)->gap(8.0)->itemsStart(),
                // DevTools 面板停靠在右边：⌘I 只开拾取模式，面板本体得这样摆。
                El::inspector('devtools')->w(320.0),
            ])->sizeFull(),
            32 => El::vFlex([
                El::text('Notification host')->font(18.0)->semibold(),
                // 通知层：gpui 的基座 Root 自己挂它，ShellRoot 不挂，所以要显式放一颗。
                // 绝对定位是推荐摆法（不参与外层排布），这张图与 demo 用同一形状。
                El::notifications('notes')->sizeFull()->absolute(),
            ])->sizeFull(),
            default => El::vFlex([
                El::text('Hello, World!')->font(20.0)->semibold(),
                El::text('Clicked: 0 times — vol: 30')->font(14.0),
                El::input()->id('name')->placeholder('Your name')->onChange(),
                El::slider()->id('vol')->w(240)->onChange(),
                El::hFlex([
                    El::catalogButton('inc', '+1')->onClick()->primary(),
                    El::catalogButton('reset', 'Reset')->onClick()->ghost(),
                ])->gap(8.0)->itemsCenter(),
            ]),
        };
        return $col->sizeFull()->gap(8.0)->itemsCenter()->justifyCenter();
    });

    // 跑几帧让 spec materialize + 布局完成，再抓真实窗口。
    $app->loop(3);
    if ($tree === 13) {
        // 打开一个 dialog（含按钮）+ 弹一条 toast，再跑几帧让 overlay 渲染。
        $n = $app->openDialog(
            El::vFlex([
                El::text('Dialog Title')->font(20.0)->semibold(),
                El::text('This is a modal dialog over the window root.')->font(14.0),
                El::catalogButton('ok', 'OK')->primary(),
            ])->p(24.0)->gap(12.0)
        );
        $app->toast('Saved', 'Your changes are stored.', 'success', 8000);
        echo "dialog_open_returned=$n dialog_count=" . $app->dialogCount() . "\n";
        $app->loop(3);
        echo "after_loop dialog_count=" . $app->dialogCount() . "\n";
    }
    if ($tree === 27) {
        // 帧率 HUD：窗口根持有的浮层，重复调用只是移动它。
        $shown = $app->showFpsHud('top_right');
        echo 'fps_shown=' . ($shown ? '1' : '0')
            . ' visible=' . ($app->fpsHudVisible() ? '1' : '0') . "\n";
        // 必须泵到"资源行已经出现"那个稳定态再拍：HUD 的 CPU/MEM 行要等采样器凑齐
        // 第二次样本才画，行数一变框就变高——只跑 3 帧时"拍到了没"取决于墙钟，
        // 同一条命令两次跑出两张图（#86 就是这个）。所以这里给足帧数，让基线总是
        // 落在含资源行的那一态，而不是靠容差或重录把差异咽下去。
        $app->loop(120);
    }
    if ($tree === 30) {
        // WebView 的 HTML 异步渲染进图层：首帧没进 cacheDisplay 背板时整块是页面底色
        // （gdfrac=0.56586 的离散空白态）。帧数预算猜不准——120 与 600 帧都曾 5/5 全空白
        // （2026-10-29 读数），而同一分钟 embedded 能画 ⇒ 不是会话整体问题，是这颗
        // WebContent 的首画时机。改用**内容判据**：泵到 webview 区域平均色变成 HTML 的
        // 蓝（#1e6fd9）为止再拍；泵不出来就照拍并打印 painted=0，让门红得可诊断。
        // 采样矩形必须落在平整蓝区（260,220）：webview 圆角边缘是白的，第一版取
        // (24,70) 恒白 ⇒ painted 恒 0（负向对照实测 tries=30）。竖向扫描确认蓝区
        // y≈120..600、x 中段，坐标系与 hits 同（y 向下）。
        $app->loop(20);
        $painted = false;
        $rgb = [];
        for ($try = 0; $try < 30; $try++) {
            $rgb = $app->regionRgb(260, 220, 40, 40);
            if ($rgb !== [] && $rgb['b'] > 150 && $rgb['b'] > $rgb['r'] + 40) {
                $painted = true;
                break;
            }
            $app->loop(20);
        }
        echo 'tree30 painted=' . ($painted ? '1' : '0') . ' tries=' . $try
            . ' rgb=' . json_encode($rgb) . "\n";
    }
    if ($tree === 28) {
        $n = $app->openSheet(
            El::vFlex([
                El::text('Sheet Title')->font(20.0)->semibold(),
                El::text('Slides in from the edge its anchor names.')->font(14.0),
                El::catalogButton('done', 'Done')->primary(),
            ])->p(24.0)->gap(12.0),
            'right'
        );
        echo 'sheet_opened=' . $n . ' has=' . ($app->hasSheet() ? '1' : '0') . "\n";
        $app->loop(3);
    }
    if ($tree === 29) {
        // inspector 没有可读的状态查询，所以它的验收只能是像素：开→出图。
        $app->toggleInspector();
        echo "inspector_toggled=1\n";
        $app->loop(3);
    }
    if ($tree === 31) {
        // 面板只在 inspector 开着的时候画：component::Inspector::IntoEl() 关着返回
        // null（inspector.h:63 的契约），所以这张图得先开拾取模式。
        $app->toggleInspector();
        echo "inspector_panel_toggled=1\n";
        $app->loop(3);
    }
    if ($tree === 32) {
        // 通知层挂上之后画不画得出来。timeout 用 0 = 不自动退场，免得拍到一半没了。
        // 两条各占一角：同一角会叠成 collapsed stack（gpui 的设计——新的在前、旧的只
        // 露一条边），分开才拍得出两张完整的卡，顺带验 placement 的 8 个锚点映射。
        $app->notify('已保存', '3 个文件写好了', 'success', 'top_right', 0);
        $app->notify('磁盘将满', '只剩 8 GB', 'warning', 'bottom_left', 0);
        echo 'notify_count=' . $app->notificationCount() . "\n";
        // 入场是动画：Starting 段的不透明度 = elapsedMs / 400（notification.cpp:948），
        // 推它的是 50ms 墙钟计时器。只跑 3 帧等于拍在 0 上，所以要跑到动画走完。
        $app->loop(80);
    }
    echo "--- debug tree (tree={$tree}) ---\n" . \gpui_debug_tree($app->handle()) . "\n---\n";
    $ok = $app->snapshot($path, 800, 600);
    echo $ok ? "SHOT OK tree={$tree} {$path}\n" : "SHOT FAIL tree={$tree} {$path}\n";
    $app->close();
    exit($ok ? 0 : 1);
}
