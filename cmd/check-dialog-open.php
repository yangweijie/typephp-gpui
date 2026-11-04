#!/usr/bin/env php
<?php

/**
 * cmd/check-dialog-open.php — #110 的回归门：弹层触发器必须真开层。
 *
 * 背景（2026-11-04）：ShellRoot 窗口此前没有 base Root，Root::Read 永远空，
 * WindowLayersOf 返回 null——Dialog/AlertDialog/Sheet/PopupMenu/Notification
 * 触发器全部空转（LayersOf 的 fail-soft 告警）。修法（批24）：ShellRoot 继承
 * gpui::Root，shim 开窗后 RootCapturePlugins + RootMount，把 app 级根插件
 * （含 component::kWindowStatePlugin，component::Init 已登记）挂到 ShellRoot 上。
 *
 * 判据（stderr 地面真值）：触发器点击 → 弹层逻辑 → LayersOf——插件在册则
 * 永不告警；不在册则首点必告警（patch-window-layers.sh 的 fail-soft 分支，
 * warn once）。告警走 stderr（run.log），不进显示缓冲，不受 automation
 * 截图/event 后缓冲不同步的影响——那是本门早期用截图差分当判据时踩的坑
 * （点击其实一直投递成功：回执的 0 是 queue.size() 判据的假阴，弹层触发器
 * 不入 PHP 队列；截图的 diff=0 是显示缓冲陈旧）。
 *
 * 用法：php cmd/check-dialog-open.php
 * 退出码：0 插件在册（无告警）/ 1 不在册（告警，#110 回归）/ 2 未判定（产物缺）。
 */

$root = dirname(__DIR__);
$bin = $root . '/examples/kit/build/kit';
$tmp = sys_get_temp_dir() . '/gpui-dlg-open-' . getmypid() . '-' . uniqid('', true);
mkdir($tmp, 0700, true);

if (!is_executable($bin)) {
    echo "DIALOG-OPEN 前置产物缺：{$bin}（先 php bin/gpui build kit）⇒ 未判定\n";
    exit(2);
}

// 树 3 高窗 1200：触发器行在内容 (16,102) 起，(61,118) 是「打开确认框」按钮中心。
file_put_contents($tmp . '/command-1.txt', "clickat 61 118\n");
file_put_contents($tmp . '/command-2.txt', "screenshot record\n");
file_put_contents($tmp . '/command-3.txt', "quit\n");

exec(sprintf('exec %s --automation %s 3 1200 >%s/run.log 2>&1',
    escapeshellarg($bin), escapeshellarg($tmp), escapeshellarg($tmp)));

$runLog = $tmp . '/run.log';
$logText = is_file($runLog) ? (string) file_get_contents($runLog) : '';
$snap = $tmp . '/snapshot.txt';
if (!is_file($snap)) {
    echo "DIALOG-OPEN snapshot 缺（见 {$tmp}/run.log）⇒ 未判定\n";
    exec('rm -rf ' . escapeshellarg($tmp));
    exit(2);
}
$snapText = (string) file_get_contents($snap);
if (!str_contains($snapText, 'commands=3')) {
    echo "DIALOG-OPEN 命令没吃满（{$snapText} 尾行）⇒ 未判定\n";
    exec('rm -rf ' . escapeshellarg($tmp));
    exit(2);
}

$warned = str_contains($logText, 'component window state unavailable');
exec('rm -rf ' . escapeshellarg($tmp));

echo 'DIALOG-OPEN LayersOf告警=' . ($warned ? '有' : '无') . "（触发器点击后）\n";

if (!$warned) {
    echo "DIALOG-OPEN OK ⇒ 点触发器后无 component window state 告警——WindowState 插件在册（#110 回归位）\n";
    exit(0);
}
echo "DIALOG-OPEN FAIL ⇒ 点触发器触发了 'component window state unavailable' 告警——"
    . "WindowState 插件不在册（#110 回归：查 ShellRoot 继承 Root + RootCapturePlugins/RootMount 是否落）\n";
exit(1);
