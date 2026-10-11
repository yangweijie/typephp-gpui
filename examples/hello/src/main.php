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
    $shotIdx = array_search('--shot', $argv, true);
    if ($shotIdx !== false) {
        $path = isset($argv[$shotIdx + 1]) ? (string) $argv[$shotIdx + 1] : '/tmp/hello.png';
        $tree = isset($argv[$shotIdx + 2]) ? (int) $argv[$shotIdx + 2] : 0;
        runShot($path, $tree);
        return;
    }

    $state = ['count' => 0, 'name' => '', 'vol' => 30];

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
            ])->gap(8.0)->itemsCenter(),
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

    echo $ok
        ? "SELFTEST OK (input + click + timer hits={$timerHits} + dialog + registered-cb + err-channel + appmenu)\n"
        : "SELFTEST FAIL (input='{$got}' fired=" . ($fired ? '1' : '0') . " clicks={$clicks} timer={$timerHits} dlg=$before/$opened/$during/$after ddcb=" . ($ddFired ? '1' : '0') . "/{$ddFires} err='$err' menu=$menuN/$menuCount)\n";
    $app->close();
    exit($ok ? 0 : 1);
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
    echo "--- debug tree (tree={$tree}) ---\n" . \gpui_debug_tree($app->handle()) . "\n---\n";
    $ok = $app->snapshot($path, 800, 600);
    echo $ok ? "SHOT OK tree={$tree} {$path}\n" : "SHOT FAIL tree={$tree} {$path}\n";
    $app->close();
    exit($ok ? 0 : 1);
}
