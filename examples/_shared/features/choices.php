<?php

declare(strict_types=1);

/**
 * examples/_shared/features/choices.php — 功能域「选择、开关与按钮变体」。
 *
 * 从原画廊（已删除）的 §3 搬来：builder + 那六颗按钮的接线 + 自报的像素树号。
 * 这一域是 #66（variant 那批糖一个像素都不改）的现场，也是 #73（部件自己发的那批事件）
 * 唯一挂着 onOpenChange/onConfirm/onDismiss 三颗糖的一页——所以"弹层到底开没开、
 * 事件到底回没回 PHP"这两件事，值得有一支只渲染它自己的二进制来回答。
 */

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;

/** 本域的像素树清单：树号 → [标题, builder]。 */
function choicesTrees(): array
{
    return [1 => ['选择、开关与按钮变体', 'sectionChoices']];
}

function sectionChoices(array $s): El
{
    return El::vFlex([
        El::text('选择、开关与按钮变体')->font(22.0)->semibold(),
        El::hFlex([
            El::radio('A', true),
            El::radio('B', false),
            El::toggle('Tgl'),
        ])->gap(10.0)->itemsCenter(),
        El::hFlex([
            El::radioGroup([El::radio('甲', true), El::radio('乙', false), El::radio('丙', false)]),
            El::toggleGroup([El::toggle('左'), El::toggle('中'), El::toggle('右')]),
        ])->gap(14.0)->itemsCenter(),
        El::hFlex([
            // 这颗是 **kind 原语**（gpui-base Button：只有点击与文本，没有圆角/底色/变体），
            // 后面五颗才是上游画廊里那个 catalog Button。两者同名不同物，
            // 在左边这颗上调 ->primary() 现在会直接抛（以前是静默丢，见 #66）。
            El::button('默认(kind)')->id('btn-plain')->onClick(),
            El::catalogButton('btn-p', 'primary')->onClick()->primary(),
            El::catalogButton('btn-s', 'secondary')->onClick()->secondary(),
            El::catalogButton('btn-d', 'danger')->onClick()->danger(),
            El::catalogButton('btn-g', 'ghost')->onClick()->ghost(),
            El::catalogButton('btn-o', 'outline')->onClick()->outline(),
        ])->gap(8.0)->itemsCenter()->flexWrap(),
        El::hFlex([
            // 部件自己发出的那批事件（open_change / confirm / dismiss）挂在这三颗上。
            // callbackId 存在 ShellSelectBinding 里，而它是 Box2 自己的 id、不在 runtime
            // 回调表里，所以 2026-10-14 之前这一族从来没到过 PHP（并由那次扫表崩过一次）——
            // 现在由 cmd/patch-select-view.sh 在 view 层进宿主队列，见 docs/guide/events.md 1b。
            // `selectOpen` 把 ch-select 声明成"开着"：binding 里的 open 位就是它，
            // 而 SelectActionOf 按它解释动作（关着时 Confirm 读成"再开一次"、Cancel 读成
            // "不归我管"），所以 confirm/dismiss 只有这一颗测得到。默认 false ⇒ 画廊像素不变。
            El::select('ch-select', ['Apple', 'Banana', 'Cherry'])->w(180)
                ->open((bool) ($s['selectOpen'] ?? false))
                ->onOpenChange()->onConfirm()->onDismiss(),
            El::combobox('ch-combo', ['Red', 'Green', 'Blue'])->w(180)
                ->onOpenChange()->onConfirm()->onDismiss(),
            El::datePicker('ch-date')->w(180)->onOpenChange(),
        ])->gap(10.0),
        El::text("最近点的按钮：{$s['lastBtn']}")->font(12.0),
    ])->sizeFull()->gap(12.0)->p(16.0)->overflowYScroll();
}

/** 本域元素的接线。 */
function wireChoices(App $app): void
{
    foreach (['plain', 'p', 's', 'd', 'g', 'o'] as $k) {
        $app->on('btn-' . $k, 'click', function () use ($k): void {
            ExampleState::$v['lastBtn'] = $k;
        });
    }
    // 部件自己发的那三条（open_change / confirm / dismiss）在 PHP 侧要有 handler 才谈得上"收到"。
    // 之前只有 op 发出去、没人接，于是 #73 那一格连"到底回没回 PHP"都无从判起。
    // 这里按 (id, type) 各记一颗到 evSeen，读数与画廊的通配记录器同源。
    foreach (['ch-select', 'ch-combo', 'ch-date'] as $cid) {
        foreach (['open_change', 'confirm', 'dismiss'] as $type) {
            $app->on($cid, $type, function (array $e) use ($cid, $type): void {
                ExampleState::$v['evSeen'][$type][$cid] = $e['value'] ?? true;
            });
        }
    }
}
