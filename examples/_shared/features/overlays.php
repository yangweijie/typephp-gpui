<?php

declare(strict_types=1);

/**
 * examples/_shared/features/overlays.php — 功能域「浮层：dialog / sheet / toast / 通知」。
 *
 * 从原画廊（已删除）的 §8 原样搬来（builder + 接线 + 自报的像素树号），各示例
 * examples/overlay 共用这一份。搬动只改了一处字面量：dialog 标题里的 "Showcase" 换成中性的
 * "浮层"——那棵树只在点开之后才存在，画廊的基线树 8 里根本没有它，所以像素不受影响
 * （搬完跑了 shotdiff check <域> 核对）。
 *
 * 约定同原画廊：id 前缀 `ov-` 是事件路由键，全域唯一。
 */

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;

/** 本域的像素树清单：树号 → [标题, builder]。 */
function overlaysTrees(): array
{
    return [1 => ['浮层：dialog / sheet / toast / 通知', 'sectionOverlays']];
}

function sectionOverlays(array $s): El
{
    $sheetBtns = [];
    foreach (App::SHEET_ANCHORS as $a) {
        $sheetBtns[] = El::catalogButton("ov-sheet-{$a}", "Sheet·{$a}")->onClick()->ghost();
    }
    $noticeBtns = [];
    foreach (App::NOTICE_TYPES as $t) {
        $noticeBtns[] = El::catalogButton("ov-notify-{$t}", "通知·{$t}")->onClick()->outline();
    }
    // NOTICE_PLACEMENTS 全枚举：8 个锚点各一颗按钮，钉在哪一角是看得见的（像素证据）。
    $placeBtns = [];
    foreach (App::NOTICE_PLACEMENTS as $p) {
        $placeBtns[] = El::catalogButton('ov-place-' . $p, $p)->onClick()->ghost()->sizeEnum('xsmall');
    }

    return El::vFlex([
        El::text('浮层：dialog / sheet / toast / 通知')->font(22.0)->semibold(),
        El::text('dialog 与 toast 由 ShellRoot 持有；通知层必须自己摆一颗 El::notifications()。')->font(11.0)->truncate(),
        El::hFlex([
            El::catalogButton('ov-dialog', '打开 dialog')->onClick()->primary(),
            El::catalogButton('ov-dialog-close', '关 dialog')->onClick()->ghost(),
            El::catalogButton('ov-toast', 'toast')->onClick()->ghost(),
            El::text("dialog=" . $s['dialogCount'])->font(12.0),
        ])->gap(8.0)->itemsCenter(),
        El::hFlex($sheetBtns)->gap(8.0),
        El::text("sheet: has=" . ($s['hasSheet'] ? '1' : '0') . " anchor=" . $s['sheetAnchor'])->font(12.0),
        El::hFlex($noticeBtns)->gap(8.0),
        El::hFlex([
            El::catalogButton('ov-notify-click', '带 id 的通知（可点）')->onClick()->secondary(),
            El::catalogButton('ov-notify-clear', '关掉全部通知')->onClick()->ghost(),
            El::text("在场 " . $s['notifyCount'] . ' 条')->font(12.0),
        ])->gap(8.0),
        El::hFlex($placeBtns)->gap(4.0)->flexWrap(),
        El::text("placement={$s['placement']}（8 个锚点各一颗，点哪张卡就钉在那一角）")->font(11.0),
        El::text('通知层本体（绝对定位盖住窗口，卡片贴窗口边角）：')->font(11.0),
        El::notifications('ov-notes')->sizeFull()->absolute(),
    ])->sizeFull()->gap(10.0)->p(16.0)->relative()->overflowYScroll();
}

/** 把本域元素的事件接进 App。 */
function wireOverlays(App $app): void
{
    $app->on('ov-dialog', 'click', function () use ($app): void {
        ExampleState::$v['dialogCount'] = $app->openDialog(
            El::vFlex([
                El::text('浮层 dialog')->font(18.0)->semibold(),
                El::text('dialog 内容是一棵独立的 Element 树（自己的 Box2）。')->font(13.0),
                El::catalogButton('ov-dialog-ok', '好')->primary(),
            ])->p(20.0)->gap(10.0)
        );
    });
    $app->on('ov-dialog-close', 'click', function () use ($app): void {
        $app->closeDialog();
        ExampleState::$v['dialogCount'] = $app->dialogCount();
    });
    $app->on('ov-toast', 'click', function () use ($app): void {
        $app->toast('Toast', 'title + description + level', 'success');
    });
    foreach (App::SHEET_ANCHORS as $a) {
        $app->on('ov-sheet-' . $a, 'click', function () use ($app, $a): void {
            $app->openSheet(
                El::vFlex([
                    El::text('Sheet·' . $a)->font(16.0)->semibold(),
                    El::text('从 anchor 命名的那条边滑进来。')->font(12.0),
                ])->p(20.0)->gap(8.0),
                $a
            );
            ExampleState::$v['hasSheet'] = $app->hasSheet();
            ExampleState::$v['sheetAnchor'] = $a;
        });
    }
    foreach (App::NOTICE_TYPES as $t) {
        $app->on('ov-notify-' . $t, 'click', function () use ($app, $t): void {
            $app->notify('通知·' . $t, 'type 决定图标与配色', $t, 'top_right', 5000, 'ov-note');
            ExampleState::$v['notifyCount'] = $app->notificationCount();
        });
    }
    $app->on('ov-notify-click', 'click', function () use ($app): void {
        $app->notify('带 id 的通知', '点它或等它退场，都会打回 PHP', 'info', 'top_right', 8000, 'ov-note');
        ExampleState::$v['notifyCount'] = $app->notificationCount();
    });
    $app->on('ov-notify-clear', 'click', function () use ($app): void {
        $app->clearNotifications();
        ExampleState::$v['notifyCount'] = $app->notificationCount();
    });
    foreach (App::NOTICE_PLACEMENTS as $p) {
        // placement 是这几种绿里唯一能钉在像素上的：8 个锚点各一条真通知。
        $app->on('ov-place-' . $p, 'click', function () use ($app, $p): void {
            $app->notify('换角', 'placement=' . $p, 'warning', $p, 0, 'ov-note');
            ExampleState::$v['placement'] = $p;
            ExampleState::$v['notifyCount'] = $app->notificationCount();
        });
    }
    $app->on('ov-note', 'click', function (): void {
        ExampleState::$v['clip'] = '通知卡被点了';
    });
    $app->on('ov-note', 'close', function () use ($app): void {
        ExampleState::$v['notifyCount'] = $app->notificationCount();
    });
}
