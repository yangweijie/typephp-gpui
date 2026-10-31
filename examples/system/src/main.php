<?php

declare(strict_types=1);

/**
 * examples/system/src/main.php — 功能域独立示例：系统能力 + 服务（§9 与 §10 合成一域）。
 *
 * 这一支全部是"双向门"：授权之前必须被拒、授权之后必须放行。
 * 单向断言抓不住的东西不少——`ResolvePath` 只查对应那张表、`grantExecute` 的白名单、
 * `grantStorage` 的开关，任何一格变成"永远放行"或"永远拒绝"，单向门都是绿的。
 * 而授权表是**进程内累计**的，所以顺序也是证据的一部分：同一支里先测拒再测放才有意义。
 *
 * 开关：（无参）交互 · --sections · --shot <path> [tree] · --selftest
 */

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;

const SYSTEM_W = 980;
const SYSTEM_H = 680;

function main(int $argc, array $argv): void
{
    try {
        systemDispatch($argv);
    } catch (\Throwable $e) {
        echo 'FATAL ' . get_class($e) . ': ' . $e->getMessage() . "\n";
        exit(12);
    }
}

function systemDispatch(array $argv): void
{
    if (in_array('--sections', $argv, true)) {
        foreach (systemTrees() as $tree => [$title, $_fn]) {
            echo $tree . "\t" . $title . "\n";
        }
        return;
    }
    if (in_array('--selftest', $argv, true)) {
        runSystemSelfTest();
        return;
    }
    $shotIdx = array_search('--shot', $argv, true);
    if ($shotIdx !== false) {
        $path = isset($argv[$shotIdx + 1]) ? (string) $argv[$shotIdx + 1] : '/tmp/system.png';
        $tree = isset($argv[$shotIdx + 2]) ? (int) $argv[$shotIdx + 2] : 1;
        runSystemShot($path, $tree);
        return;
    }
    ExampleState::boot(['section' => 9]);
    $app = new App();
    wireSystem($app);
    $app->run('System · 系统能力与服务', SYSTEM_W, SYSTEM_H, 'systemTree');
}

function step(string $name, bool $ok, string $detail): void
{
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ' — ' . $detail . "\n";
}

function systemTree(): El
{
    // 注意键的是**节号**（9 / 10），不是树号（1 / 2）——first run 就把两者混了，
    // 结果两棵"不同的树"拍出同一张图（基线字节数完全相同才暴露出来）。
    $builder = (int) (ExampleState::$v['section'] ?? 9) === 10
        ? 'sectionServices' : 'sectionSystem';
    return El::vFlex([$builder(ExampleState::get())])->sizeFull()->bg('#f6f3ee');
}

function runSystemShot(string $path, int $tree): void
{
    $tree = isset(systemTrees()[$tree]) ? $tree : 1;
    ExampleState::boot(['section' => $tree === 2 ? 10 : 9]);
    $app = new App();
    $app->create();
    $app->createWindow('System Shot', SYSTEM_W, SYSTEM_H);
    wireSystem($app);
    $app->render(systemTree());
    $app->loop(20);
    echo '--- debug tree (system ' . $tree . ') ---'
        . "\n" . \gpui_debug_tree($app->handle()) . "\n---\n";
    $shotOk = $app->snapshot($path, SYSTEM_W, SYSTEM_H);
    echo $shotOk ? "SHOT OK tree={$tree} {$path}\n" : "SHOT FAIL tree={$tree} {$path}\n";
    $app->close();
    exit($shotOk ? 0 : 1);
}

function runSystemSelfTest(): void
{
    ExampleState::boot(['section' => 9]);
    $app = new App();
    $app->create();
    $app->createWindow('System Selftest', SYSTEM_W, SYSTEM_H);
    wireSystem($app);
    $app->render(systemTree());
    $app->loop(3);

    $ok = true;
    echo "SYSTEM SELFTEST\n";

    // 1) 两节都渲染得过、都不留错误节点（§10 那节有 http/watch/sysInfo 的按钮行）。
    $err = $app->registeredError();
    step('registeredError', $err === '', $err === '' ? '空' : $err);
    $ok = $ok && $err === '';
    ExampleState::$v['section'] = 10;
    $app->render(systemTree());
    $app->loop(3);
    $err2 = $app->registeredError();
    $cf = App::componentFailure();
    $renderOk = $err2 === '' && (int) $cf['count'] === 0;
    step('bothSectionsRender', $renderOk, '§10 err=' . ($err2 === '' ? '空' : $err2)
        . ' componentFailure=' . (int) $cf['count'] . '/' . (string) $cf['last']);
    $ok = $ok && $renderOk;

    // 2) 剪贴板三通道：写进去的中文与大写字母要逐字节回得来。
    $app->setClipboardText('SYSTEM-Ω-中文');
    $clip = (string) $app->clipboardText();
    $item = $app->clipboardItem();
    $clipOk = $clip === 'SYSTEM-Ω-中文' && (string) ($item['text'] ?? '') === 'SYSTEM-Ω-中文';
    step('clipboard', $clipOk, 'clipboardText=' . $clip . ' item.text=' . (string) ($item['text'] ?? ''));
    $ok = $ok && $clipOk;

    // 3) 文件系统的双向门。顺序要紧：先授 /tmp（ResolvePath 只做前缀判断，根必须已存在），
    //    gpui 写的文件 PHP 要读得到、PHP 写的二进制 gpui 要原样读回（0x00 与多字节不能截断），
    //    而 /etc/hosts 在授权之外必须读不到且**留下原因**——曾经 fsError() 回空串，
    //    把"拒绝"伪装成"读到一个空文件"。
    $dir = '/tmp/gpui-system-st';
    App::grantRoot('/tmp', true);
    $mk = App::fsMkdir($dir, true);
    $w = App::fsWrite($dir . '/a.txt', "gpui 写的\n第二段");
    $phpSaw = @file_get_contents($dir . '/a.txt');
    @file_put_contents($dir . '/b.bin', "\x00\x89PNG中文");
    $gpuiSaw = App::fsRead($dir . '/b.bin');
    $truncated = false;
    $listing = App::fsList($dir, $truncated) ?? [];
    $outside = App::fsRead('/etc/hosts');
    $outsideErr = App::fsError();
    $rm = App::fsRemove($dir, true, true);
    $gone = App::fsExists($dir);
    $fsOk = $mk && $w && $phpSaw === "gpui 写的\n第二段" && $gpuiSaw === "\x00\x89PNG中文"
        && count($listing) === 2 && !$truncated && $outside === null && $outsideErr !== ''
        && $rm && !$gone;
    step('fsTwoWay', $fsOk, 'mkdir=' . var_export($mk, true) . ' write=' . var_export($w, true)
        . ' PHP读到=' . var_export($phpSaw === "gpui 写的\n第二段", true)
        . ' gpui读回二进制=' . var_export($gpuiSaw === "\x00\x89PNG中文", true)
        . ' 列目录=' . count($listing) . ' 截断=' . var_export($truncated, true)
        . ' 越界读=' . var_export($outside, true) . ' 原因长度=' . strlen($outsideErr)
        . ' 递归删=' . var_export($rm, true) . ' 还在=' . var_export($gone, true));
    $ok = $ok && $fsOk;

    // 4) 进程的双向门：授过的 /bin/echo 能跑，没授的 /bin/cat 必须拿不到结果。
    App::grantExecute(['/bin/echo']);
    $echo = App::processRun('/bin/echo', ['system-st']);
    $denied = App::processRun('/bin/cat', ['/etc/passwd']);
    $procOk = is_array($echo) && $denied === null;
    step('processTwoWay', $procOk, 'echo=' . var_export($echo, true)
        . ' 未授权的 cat=' . var_export($denied, true));
    $ok = $ok && $procOk;

    // 5) 存储的双向门：grantStorage 之前 set 必须失败，之后 set/get/len/remove 成环。
    //    这条顺序不能换——它就是"开关真在管"的证据。
    $storeDenied = App::storageSet('k', 'v');
    App::grantStorage(true);
    $storeFile = '/tmp/gpui-system-store.json';
    $pathOk = App::storagePath($storeFile);
    $set = App::storageSet('theme', 'dark');
    $get = App::storageGet('theme');
    $len = App::storageLen();
    App::storageRemove('theme');
    @unlink($storeFile);
    $storeOk = !$storeDenied && $pathOk && $set && $get === 'dark' && $len >= 1
        && App::storageGranted();
    step('storageTwoWay', $storeOk, '未授权时 set=' . var_export($storeDenied, true)
        . ' storagePath=' . var_export($pathOk, true) . ' get=' . var_export($get, true)
        . ' len=' . $len . ' granted=' . var_export(App::storageGranted(), true));
    $ok = $ok && $storeOk;

    // 6) 解压：同一句话的 zlib 与 gzip 两种容器都要解得出来，坏流必须回 null 而不是半截数据。
    $plain = 'hello gpui';
    $zlib = "\x78\x9C\xCB\x48\xCD\xC9\xC9\x57\x48\x2F\x28\xCD\x04\x00\x15\x74\x03\xEA";
    $gzip = "\x1F\x8B\x08\x00\x00\x00\x00\x00\x00\x13\xCB\x48\xCD\xC9\xC9\x57\x48"
        . "\x2F\x28\xCD\x04\x00\x52\x1A\x59\x50\x0A\x00\x00\x00";
    $inf = App::inflate($zlib);
    $infGz = App::inflate($gzip, true);
    $infBad = App::inflate('not a zlib stream');
    $infOk = $inf === $plain && $infGz === $plain && $infBad === null;
    step('inflate', $infOk, 'zlib=' . var_export($inf, true) . ' gzip=' . var_export($infGz, true)
        . ' 坏流=' . var_export($infBad, true));
    $ok = $ok && $infOk;

    // 7) http 的异步路：127.0.0.1 上一个必然没人听的端口（**不碰外网**，门禁里不许有
    //    网络副作用与超时抖动）。排队要成功，回来必须是"失败"而不是永远没下文。
    $queued = $app->httpGet('http://127.0.0.1:1/probe', 'sys-api');
    $app->loop(60);
    $resp = $app->httpResponse('sys-api');
    $httpOk = $queued && is_array($resp) && ($resp['ok'] ?? true) === false;
    step('httpFailurePath', $httpOk, 'queued=' . var_export($queued, true)
        . ' resp=' . var_export($resp, true));
    $ok = $ok && $httpOk;

    // 8) 目录监听：注册要拿到非零 id，撤销之后不该再有事件。真改动要不要等到，
    //    取决于墙钟与后台线程，所以这里只钉"注册/撤销"这条确定路，命中数当读数。
    $watchId = $app->watchDir('/tmp', 'sys-watch', false);
    $hits = (int) (ExampleState::$v['watchHits'] ?? 0);
    $app->unwatchDir($watchId);
    step('watchDir', $watchId > 0, 'watchId=' . $watchId . ' 已收到变更=' . $hits);
    $ok = $ok && $watchId > 0;

    // 9) 系统状态：键名用 bridge 给的那一套（'memUsed'/'memTotal'，不是 C 结构体字段名）。
    //    这条门第一次跑就照出 §10 那个 handler 一直在读不存在的键、把 (int) null 画成 "mem 0 / 0"。
    $info = App::sysInfo(3);
    $infoOk = isset($info['memUsed'], $info['memTotal'])
        && (int) $info['memTotal'] > 0 && (int) $info['memUsed'] > 0
        && is_array($info['procs'] ?? null);
    step('sysInfo', $infoOk, 'mem=' . (string) ($info['memUsed'] ?? '?') . '/'
        . (string) ($info['memTotal'] ?? '?') . ' cpu=' . (string) ($info['cpu'] ?? '?')
        . ' procs=' . count((array) ($info['procs'] ?? [])));
    $ok = $ok && $infoOk;

    // 10) image 三路 src（批3 A8）。独立探针窗：三颗 64x64 白底盒各挂一路 src。
    //     ① imageAssetRenders（硬门）：本地资产 tile.png（examples/system/assets/，
    //     exe 父级 assets 走查命中）与 data URI 4x4 蓝块两路——debug_tree 的 image
    //     节点计数（3 颗全在，hidden 只是不画不改树）、registeredError/componentFailure
    //     清零、两颗可见盒区域均色=图色（图没画出来就是白底 bg）。
    //     ② imageUrlPolicy（策略门）：远程 URL 不破坏渲染管线——loop 跑完、
    //     错误节点清零；fetch 状态没有 PHP 读口（HttpFetch 表在 image.cpp 内部），
    //     区域色随网络态走，只读数不断言（advisory）——"无头会话不硬断"就是
    //     这条门本身。
    $imgApp = new App();
    $imgApp->create();
    $imgApp->createWindow('System Image Probe', 320, 160);
    $imgApp->render(El::hFlex([
        El::image('tile.png', '本地资产')->w(64.0)->h(64.0)->bg('#ffffff'),
        El::image('data:image/png;base64,' . SY_DATA_PNG_B64, 'data URI')
            ->w(64.0)->h(64.0)->bg('#ffffff'),
        El::image('https://example.com/gpui-probe.png', '远程 URL')
            ->w(64.0)->h(64.0)->bg('#ffffff')->hidden(),
    ])->sizeFull()->p(20.0)->bg('#f6f3ee')->gap(20.0)->itemsStart());
    $imgApp->loop(8);
    $imgDebug = \gpui_debug_tree($imgApp->handle());
    $imgNodes = substr_count($imgDebug, 'image');
    $assetRgb = $imgApp->regionRgb(40, 40, 24, 24);
    $dataRgb = $imgApp->regionRgb(124, 40, 24, 24);
    $remoteRgb = $imgApp->regionRgb(208, 40, 24, 24);
    $fmtRgb = static function (array $c): string {
        return isset($c['r']) ? sprintf('rgb(%d,%d,%d)', $c['r'], $c['g'], $c['b'])
            : json_encode($c);
    };
    $isOrange = isset($assetRgb['r']) && $assetRgb['r'] > 180 && $assetRgb['g'] > 80
        && $assetRgb['g'] < 170 && $assetRgb['b'] < 110;
    $isBlue = isset($dataRgb['b']) && $dataRgb['b'] > 180 && $dataRgb['r'] < 110;
    $imgCf = (int) (App::componentFailure()['count'] ?? 1);
    $assetOk = $imgNodes >= 3 && $isOrange && $isBlue
        && $imgApp->registeredError() === '' && $imgCf === 0;
    step('imageAssetRenders', $assetOk,
        'image节点数=' . $imgNodes
        . ' 资产盒=' . $fmtRgb($assetRgb) . ' dataURI盒=' . $fmtRgb($dataRgb)
        . ' registeredError=' . ($imgApp->registeredError() === '' ? '空' : $imgApp->registeredError())
        . ' componentFailure=' . $imgCf
        . ($assetOk ? '' : '（资产盒不是橙/dataURI盒不是蓝：图没画出来——查 assets 根'
            . '（examples/system/assets 走 exe 父级）与 data URI 解码）'));
    $ok = $ok && $assetOk;
    $policyOk = $imgApp->registeredError() === '' && $imgCf === 0;
    step('imageUrlPolicy', $policyOk,
        '远程盒(右侧,hidden)=' . $fmtRgb($remoteRgb)
        . ' → fetch 只读数不断言（HttpFetch 状态无 PHP 读口，网络态决定画/不画）；'
        . '渲染管线不因远程 URL 崩溃或挂起 = 本门硬断言部分'
        . ($policyOk ? '' : '（错误节点没清零：远程 URL 把管线搞坏了，真卡点）'));
    $ok = $ok && $policyOk;
    $imgApp->close();

    // 11) 语义 token 表暴露（批3 A6）：App::themeTokens() 的读口面。硬断言：
    //     colors 键集=17 个语义名（value.cpp:137-142 名单，与 runtime.cpp 的
    //     SetThemeColor 同表）、primary/border 非 #ffffff（语义名真的解析到
    //     palette，不是空串兜底）、spacing 7 档/radius 6 档全在册、typography
    //     的 sans/mono 非空（A7 设计段引用的族名读口）。appearance 切换联动
    //     另测（outOfScope）。
    $tokens = $app->themeTokens();
    $expectColors = ['background', 'foreground', 'surface', 'surface_foreground',
        'primary', 'primary_foreground', 'secondary', 'secondary_foreground',
        'muted', 'muted_foreground', 'accent', 'accent_foreground',
        'destructive', 'destructive_foreground', 'border', 'input', 'ring'];
    $gotColors = array_keys((array) ($tokens['colors'] ?? []));
    $colorsOk = $gotColors === $expectColors;
    $primaryOk = ($tokens['colors']['primary'] ?? '') !== ''
        && ($tokens['colors']['primary'] ?? '') !== '#ffffff';
    $borderOk = ($tokens['colors']['border'] ?? '') !== ''
        && ($tokens['colors']['border'] ?? '') !== '#ffffff';
    $scaleKeys = array_keys((array) ($tokens['spacing'] ?? []));
    $spacingOk = $scaleKeys === ['xxs', 'xs', 'sm', 'md', 'lg', 'xl', 'xxl'];
    $radiusKeys = array_keys((array) ($tokens['radius'] ?? []));
    $radiusOk = $radiusKeys === ['none', 'sm', 'md', 'lg', 'xl', 'full'];
    $typoOk = ($tokens['typography']['sans'] ?? '') !== ''
        && ($tokens['typography']['mono'] ?? '') !== '';
    $tokOk = $colorsOk && $primaryOk && $borderOk && $spacingOk && $radiusOk && $typoOk;
    step('tokenTableExpose', $tokOk,
        'colors=' . count($gotColors) . ' 键集全对=' . var_export($colorsOk, true)
        . ' primary=' . (string) ($tokens['colors']['primary'] ?? '?')
        . ' border=' . (string) ($tokens['colors']['border'] ?? '?')
        . ' spacing=' . count($scaleKeys) . ' 档 radius=' . count($radiusKeys) . ' 档'
        . ' typography=' . json_encode($tokens['typography'] ?? [])
        . ($tokOk ? '' : '（colors 键集/档位不全或值空：查 gpui_shim_theme_tokens'
            . ' 的六读口与 ThemeTokensSync）'));
    $ok = $ok && $tokOk;

    $app->close();
    echo ($ok ? 'SYSTEM SELFTEST OK' : 'SYSTEM SELFTEST FAIL') . "\n";
    exit($ok ? 0 : 1);
}
