<?php

declare(strict_types=1);

/**
 * examples/_shared/features/embedded.php — 功能域「WebView 与 Inspector」
 *
 * 从 showcase 的 §11 原样搬来（builder + 接线 + 自报的像素树号），画廊与独立示例共用这一份。
 */

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;

/** 本域的像素树清单：树号 → [标题, builder]。 */
function embeddedTrees(): array
{
    return [1 => ['WebView 与 Inspector', 'sectionEmbedded']];
}

function sectionEmbedded(array $s): El
{
    return El::vFlex([
        El::text('WebView 与 Inspector 面板')->font(22.0)->semibold(),
        El::text('两者都经 ComponentKind::ChildView + host 侧 nested-view 注册挂进元素树。')->font(11.0)->truncate(),
        El::text('WebView 是盖在窗口上的 OS 控件：挡住后面的元素、不随容器滚动、没有圆角。')->font(11.0)->truncate(),
        El::hFlex([
            El::catalogButton('em-back', '后退')->onClick()->ghost(),
            El::catalogButton('em-fwd', '前进')->onClick()->ghost(),
            El::catalogButton('em-probe', '探针读 url/盒子')->onClick()->ghost(),
            El::text($s['probe'] !== '' ? "probe：{$s['probe']}" : 'probe：读 wry 报的当前地址与应用盒子')->font(11.0)->truncate(),
        ])->gap(8.0),
        El::hFlex([
            El::webviewHtml('<h3 style="font-family:sans-serif">webviewHtml</h3><p>由 wry 加载</p>', 'em-html')->w(300.0)->h(180.0)->border()->borderColor(0xCCC6BD),
            El::webview('https://example.com', 'em-url')->w(300.0)->h(180.0)->border()->borderColor(0xCCC6BD),
        ])->gap(12.0),
        El::text('下面是 Inspector 面板（要先开拾取模式才画得出东西）：')->font(12.0),
        El::inspector('em-inspector')->w(300.0)->h(150.0),
    ])->sizeFull()->gap(10.0)->p(16.0)->overflowYScroll();
}

/** 本域元素的接线。 */
function wireEmbedded(App $app): void
{
    $app->on('em-back', 'click', function () use ($app): void {
        $app->webviewBack('em-url');
    });
    $app->on('em-fwd', 'click', function () use ($app): void {
        $app->webviewForward('em-url');
    });
    $app->on('em-probe', 'click', function () use ($app): void {
        $p = $app->webviewProbe('em-url');
        ExampleState::$v['probe'] = sprintf('ok=%s url=%s box=%.0fx%.0f',
            var_export($p['ok'] ?? false, true), (string) ($p['url'] ?? ''),
            (float) ($p['w'] ?? 0), (float) ($p['h'] ?? 0));
    });
}
