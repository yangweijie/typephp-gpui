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
        runSelfTest();
        return;
    }
    if (in_array('--caps', $argv, true)) {
        runCaps();
        return;
    }
    $shotIdx = array_search('--shot', $argv, true);
    if ($shotIdx !== false) {
        $path = isset($argv[$shotIdx + 1]) ? (string) $argv[$shotIdx + 1] : '/tmp/hello.png';
        $tree = isset($argv[$shotIdx + 2]) ? (int) $argv[$shotIdx + 2] : 0;
        runShot($path, $tree);
        return;
    }

    $state = ['count' => 0, 'name' => '', 'vol' => 30, 'path' => ''];

    $app = new App();
    $app->create();
    $app->createWindow('Hello GPUI', 800, 600);

    // 每帧按 $state 重新描述界面
    $app->view(function () use (&$state): El {
        $greet = $state['name'] !== '' ? "Hello, {$state['name']}!" : 'Hello, World!';
        return El::vFlex([
            El::text($greet)->font(20.0)->semibold(),
            El::text("Clicked: {$state['count']} times — vol: {$state['vol']}")->font(14.0),
            El::input()->id('name')->placeholder('Your name')->onChange(),
            El::slider()->id('vol')->onChange(),
            El::hFlex([
                El::button('+1')->id('inc')->onClick()->primary(),
                El::button('Reset')->id('reset')->onClick()->ghost(),
                El::button('Open…')->id('pick')->onClick()->ghost(),
            ])->gap(8.0)->itemsCenter(),
            El::text($state['path'] !== '' ? "Picked: {$state['path']}" : 'Open… 走原生对话框（无人值守自检里不测，见 runSelfTest 注释）')->font(12.0),
        ])
            ->sizeFull()
            ->gap(8.0)
            ->itemsCenter()
            ->justifyCenter();
    });

    // 事件处理器只改状态；change 事件带 value（输入文本 / 滑块数值）
    $app->on('inc', 'click', function () use (&$state): void {
        $state['count']++;
    });
    $app->on('reset', 'click', function () use (&$state): void {
        $state['count'] = 0;
    });
    $app->on('name', 'change', function (array $e) use (&$state): void {
        $state['name'] = (string) ($e['value'] ?? '');
    });
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

    $app->loop();
}

/**
 * --selftest：无需用户交互，验证 retained 取值 + 点击事件两条链路。
 * 退出码 0=通过。
 */
function runSelfTest(): void
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
        El::button('hit')->id('inc')->onClick(),
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
    $opened = $app->openDialog(El::vFlex([El::text('dlg'), El::button('x')->id('dx')])->p(16.0));
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

    echo $ok
        ? sprintf(
            "SELFTEST OK (input + click + timer hits=%d + dialog + registered-cb + err-channel"
            . " + appmenu + clipboard + urlgate + fps + sheet + webview %.0fx%.0f"
            . " + inflate + dirChange x%d + http ok=%s/%d)\n",
            $timerHits,
            $probe['w'],
            $probe['h'],
            $watchHits,
            $resp === null ? 'null' : var_export($resp['ok'], true),
            $resp === null ? -1 : $resp['status']
        )
        : "SELFTEST FAIL (input='{$got}' fired=" . ($fired ? '1' : '0') . " clicks={$clicks} timer={$timerHits} dlg=$before/$opened/$during/$after ddcb=" . ($ddFired ? '1' : '0') . "/{$ddFires} err='$err' menu=$menuN/$menuCount clip='$clip' want='$payload' fps=$fps1/$fpsVis/$fps2/$fpsOff/$fpsAgain sheet=$sheetOpened/" . ($sheetDuring ? '1' : '0') . "/" . ($sheetClosed ? '1' : '0') . " wv=" . json_encode($probe) . " inf=" . var_export([$infZ, $infG, $infBad], true) . " watch=$watchId/$watchHits http=" . ($schemeRejected ? '1' : '0') . "/$queued/$httpHits/" . json_encode($resp) . ")\n";
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
                    El::button('+1')->id('inc')->primary(),
                    El::button('Reset')->id('reset')->ghost(),
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
                El::table()->w(300)->children([
                    El::tableHeader([El::text('H1'), El::text('H2')]),
                    El::tableBody([
                        El::button('cell-btn')->id('cb'),
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
                    El::icon('icons/home.svg')->sizeEnum('large')->color('#e07b39'),
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
            default => El::vFlex([
                El::text('Hello, World!')->font(20.0)->semibold(),
                El::text('Clicked: 0 times — vol: 30')->font(14.0),
                El::input()->id('name')->placeholder('Your name')->onChange(),
                El::slider()->id('vol')->w(240)->onChange(),
                El::hFlex([
                    El::button('+1')->id('inc')->onClick()->primary(),
                    El::button('Reset')->id('reset')->onClick()->ghost(),
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
                El::button('OK')->id('ok')->primary(),
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
        $app->loop(3);
    }
    if ($tree === 28) {
        $n = $app->openSheet(
            El::vFlex([
                El::text('Sheet Title')->font(20.0)->semibold(),
                El::text('Slides in from the edge its anchor names.')->font(14.0),
                El::button('Done')->id('done')->primary(),
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
    echo "--- debug tree (tree={$tree}) ---\n" . \gpui_debug_tree($app->handle()) . "\n---\n";
    $ok = $app->snapshot($path, 800, 600);
    echo $ok ? "SHOT OK tree={$tree} {$path}\n" : "SHOT FAIL tree={$tree} {$path}\n";
    $app->close();
    exit($ok ? 0 : 1);
}
