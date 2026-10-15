<?php

declare(strict_types=1);

/**
 * examples/embedded/src/main.php — 功能域独立示例：WebView 与 Inspector。
 *
 * 两者都是"盖在窗口上的 OS 控件"：经 ComponentKind::ChildView + host 侧 nested-view 注册
 * 挂进元素树（#42/#43）。这一域最难判的假绿是"节点建出来了但表面没挂上"——树上看着对，
 * 屏上什么也没有。所以这里读的是 wry 报回来的**盒子**：尺寸为零就是没挂上。
 *
 * 只探 em-html 那颗（内容由 webviewHtml 直接给）：门禁里不新增对外站的请求与它的超时抖动，
 * §11 树里那颗加载 example.com 的仍然照画廊原样渲染。
 *
 * 开关：（无参）交互 · --sections · --shot <path> [tree] · --selftest
 */

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;

const EMBEDDED_W = 760;
const EMBEDDED_H = 520;

function main(int $argc, array $argv): void
{
    try {
        embeddedDispatch($argv);
    } catch (\Throwable $e) {
        echo 'FATAL ' . get_class($e) . ': ' . $e->getMessage() . "\n";
        exit(12);
    }
}

function embeddedDispatch(array $argv): void
{
    if (in_array('--sections', $argv, true)) {
        foreach (embeddedTrees() as $tree => [$title, $_fn]) {
            echo $tree . "\t" . $title . "\n";
        }
        return;
    }
    if (in_array('--selftest', $argv, true)) {
        runEmbeddedSelfTest();
        return;
    }
    $shotIdx = array_search('--shot', $argv, true);
    if ($shotIdx !== false) {
        $path = isset($argv[$shotIdx + 1]) ? (string) $argv[$shotIdx + 1] : '/tmp/embedded.png';
        runEmbeddedShot($path);
        return;
    }
    ExampleState::boot(['section' => 11]);
    $app = new App();
    wireEmbedded($app);
    $app->run('Embedded · WebView 与 Inspector', EMBEDDED_W, EMBEDDED_H, 'embeddedTree');
}

function step(string $name, bool $ok, string $detail): void
{
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ' — ' . $detail . "\n";
}

function embeddedTree(): El
{
    return El::vFlex([sectionEmbedded(ExampleState::get())])->sizeFull()->bg(0xF6F3EE);
}

function runEmbeddedShot(string $path): void
{
    // offline：像素路径不加载外网（见 examples/_shared/features/embedded.php 那段注释）——
    // 那颗 webview 的内容会进离屏截图，"页面这次加载进来没有"就成了基线像素的差别。
    ExampleState::boot(['section' => 11, 'offline' => true]);
    $app = new App();
    $app->create();
    $app->createWindow('Embedded Shot', EMBEDDED_W, EMBEDDED_H);
    wireEmbedded($app);
    $app->render(embeddedTree());
    // Inspector 面板只在拾取模式开着的时候画（component::Inspector::IntoEl() 关着返回 null），
    // 所以出图前先开它——与 hello 的 tree31 同一处理。
    $app->toggleInspector();
    $app->loop(80);
    echo '--- debug tree (embedded 1) ---' . "\n" . \gpui_debug_tree($app->handle()) . "\n---\n";
    $shotOk = $app->snapshot($path, EMBEDDED_W, EMBEDDED_H);
    echo $shotOk ? "SHOT OK tree=1 {$path}\n" : "SHOT FAIL tree=1 {$path}\n";
    $app->close();
    exit($shotOk ? 0 : 1);
}

function runEmbeddedSelfTest(): void
{
    // 同上：自检也不该为了一条像素无关的门打外网（CI 可能根本没网）。
    ExampleState::boot(['section' => 11, 'offline' => true]);
    $app = new App();
    $app->create();
    $app->createWindow('Embedded Selftest', EMBEDDED_W, EMBEDDED_H);
    wireEmbedded($app);
    $app->render(embeddedTree());
    $app->loop(6);

    $ok = true;
    echo "EMBEDDED SELFTEST\n";

    // 1) 两颗 webview + inspector + 三颗按钮都建得出来（registered 建不出来只画占位）。
    $err = $app->registeredError();
    step('registeredError', $err === '', $err === '' ? '空' : $err);
    $ok = $ok && $err === '';
    $cf = App::componentFailure();
    step('componentFailure', (int) $cf['count'] === 0, 'count=' . (int) $cf['count']
        . ' last=' . (string) $cf['last']);
    $ok = $ok && (int) $cf['count'] === 0;

    // 2) WebView 真挂上了：探针读回 wry 报的盒子，**宽高都非零**才算 nested view 注册成功。
    //    这是"节点在树上但表面没挂"那类假绿的唯一反证——debug_tree 里它永远是对的。
    $box = $app->webviewProbe('em-html');
    $boxOk = ($box['ok'] ?? false) === true
        && (float) ($box['w'] ?? 0) > 0 && (float) ($box['h'] ?? 0) > 0;
    step('webViewAttached', $boxOk, 'ok=' . var_export($box['ok'] ?? null, true)
        . ' box=' . (string) ($box['w'] ?? 0) . 'x' . (string) ($box['h'] ?? 0)
        . ' url=' . (string) ($box['url'] ?? ''));
    $ok = $ok && $boxOk;

    // 3) 查无此 id 必须回"没有"，而不是回一个零盒子——否则 2) 里的零分不清是哪种。
    $none = $app->webviewProbe('em-does-not-exist');
    $noneOk = ($none['ok'] ?? true) === false;
    step('probeUnknownId', $noneOk, '未知 id=' . var_export($none, true));
    $ok = $ok && $noneOk;

    // 4) 前进/后退这两条导航缝对一颗活着的 webview 要返回布尔而不是崩
    //    （没历史时 false 是正确答案，这里不当门，只钉"调用本身成立"）。
    $back = $app->webviewBack('em-html');
    $fwd = $app->webviewForward('em-html');
    step('navSeamsCallable', is_bool($back) && is_bool($fwd),
        'back=' . var_export($back, true) . ' forward=' . var_export($fwd, true));
    $ok = $ok && is_bool($back) && is_bool($fwd);

    // 5) Inspector 面板：开拾取模式前后都不许留错误节点（它关着时 IntoEl() 返回 null，
    //    即"这一帧没有这个节点"是设计行为，不是失败）。
    $app->toggleInspector();
    $app->render(embeddedTree());
    $app->loop(4);
    $insErr = $app->registeredError();
    $insCf = App::componentFailure();
    $insOk = $insErr === '' && (int) $insCf['count'] === 0;
    step('inspectorOpen', $insOk, 'err=' . ($insErr === '' ? '空' : $insErr)
        . ' componentFailure=' . (int) $insCf['count'] . '/' . (string) $insCf['last']);
    $ok = $ok && $insOk;
    $app->toggleInspector();

    // 8) WebView 切走之后，原来那块区域必须重新点得动（从画廊迁来的回归位）。
    //    常驻的 WKWebView 是盖在窗口上的 OS 控件，会吃掉排在它后面那一区的点击——
    //    画廊当年"上半区点了没反应"就是这个原因，而它只在"先显示 webview、再换走、
    //    再点原位"这个顺序下才现形，所以把这三步钉成一条门。
    $awayBox = $app->webviewProbe('em-html');
    $awayW = (int) ($awayBox['w'] ?? 0);
    $awayH = (int) ($awayBox['h'] ?? 0);
    $app->on('em-after-webview', 'click', static function (): void {
        ExampleState::$v['afterWebview'] = true;
    });
    $app->render(El::vFlex([
        El::catalogButton('em-after-webview', 'webview 已切走')->onClick()->sizeFull(),
    ])->sizeFull()->p(12.0));
    $app->loop(4);
    $hitW = 0;
    $hitX = 0;
    $hitY = 0;
    $emHits = (int) ($app->hitProbe()['n'] ?? 0);
    for ($emI = 1; $emI < $emHits; $emI++) {
        $emHit = $app->hitProbe($emI);
        if ((int) $emHit['click'] === 1 && (int) $emHit['w'] > $hitW) {
            $hitW = (int) $emHit['w'];
            $hitX = (int) $emHit['x'] + intdiv((int) $emHit['w'], 2);
            $hitY = (int) $emHit['y'] + intdiv((int) $emHit['h'], 2);
        }
    }
    $app->realClick($hitX, $hitY);
    $app->loop(3);
    $awayReached = (bool) (ExampleState::$v['afterWebview'] ?? false);
    $awayOk = $awayW > 0 && $awayH > 0 && $hitW > 0 && $awayReached;
    step('clickAfterWebView', $awayOk, 'webview 盒子=' . $awayW . 'x' . $awayH
        . ' 原位按钮=' . $hitW . 'px@' . $hitX . ',' . $hitY
        . ' 点击到达=' . var_export($awayReached, true));
    $ok = $ok && $awayOk;

    $app->close();
    echo ($ok ? 'EMBEDDED SELFTEST OK' : 'EMBEDDED SELFTEST FAIL') . "\n";
    exit($ok ? 0 : 1);
}
