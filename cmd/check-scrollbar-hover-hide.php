#!/usr/bin/env php
<?php

/**
 * cmd/check-scrollbar-hover-hide.php — #112 的回归门：hover 模式滚动条的 thumb
 * 必须能隐藏。
 *
 * 根因（2026-11-04）：IdCollect 给未命名元素零路径贡献，kit 树 4 的 hover/always
 * 两盒（结构相同的未命名兄弟）坍缩到同一 scrollId → ScrollFadeFor 共用一条可见性
 * 状态 → always 盒每帧 settle 到 1、hover 盒每帧启动退出——互踢永不完结，两盒
 * thumb 永远 α≈1（真人轮"hover 盒滚动条常驻"的根因；cmd/patch-scroll-id-ordinal.sh
 * 把兄弟序数折进未命名路径使用者的身份）。
 *
 * 序列（automation 树 4）：滚 always 盒（它自己的正当滚动）→ 垫帧 ≈3s（idle 2s +
 * exit 0.5s 之后）→ screenshot → GD 判读：
 *   · hover 盒右缘带区必须**无 thumb**（带区最暗 ≥ 白底阈值）——常驻即回归红；
 *   · always 盒右缘带区必须**有 thumb**（最暗 ≤ thumb 阈值）——它没亮说明序列
 *     没跑起来（滚动了不存在的内容等），判未判定而不是通过。
 * 两判据各说各的话，不合并。
 *
 * 坐标从当帧 screenshot 现算吗？不——盒子几何由 kit.php 的
 * sectionKitScrollbarPair 决定（p40 + 两只 w220/h120/gap40 盒），PNG 900x680，
 * 带区坐标由此固定；几何若变，always 判据先红（setup 崩了可见），不会假绿。
 *
 * 用法：php cmd/check-scrollbar-hover-hide.php
 * 退出码：0 全判绿 / 1 回归红（hover 带区有 thumb）/ 2 未判定（产物缺、尺寸不对、
 *         always 盒没亮——序列没跑起来）。
 */

$root = dirname(__DIR__);
$bin = $root . '/examples/kit/build/kit';
$tmp = sys_get_temp_dir() . '/gpui-hover-hide-' . getmypid() . '-' . uniqid('', true);
mkdir($tmp, 0700, true);

$fail = static function (string $msg) use ($tmp): void {
    echo "HOVER-HIDE {$msg} ⇒ 未判定\n";
    exec('rm -rf ' . escapeshellarg($tmp));
    exit(2);
};

if (!is_executable($bin)) {
    echo "HOVER-HIDE 前置产物缺：{$bin}（先 php bin/gpui build kit）⇒ 未判定\n";
    exit(2);
}

// 1) 滚 always 盒（内容坐标 ~(430,100) 在盒内）→ 2) 垫帧 ≈3s（idle 2s + exit 0.5s
// 之后 hover 盒若还有 thumb 就是回归）→ 3) 出图。
$cmds = ['scroll 430 100 60'];
for ($i = 0; $i < 120; $i++) {
    $cmds[] = 'poll';
}
$cmds[] = 'screenshot t4hide';
$cmds[] = 'quit';
foreach ($cmds as $i => $line) {
    file_put_contents(sprintf('%s/command-%d.txt', $tmp, $i + 1), $line . "\n");
}

exec(sprintf('exec %s --automation %s 4 680 >%s/run.log 2>&1',
    escapeshellarg($bin), escapeshellarg($tmp), escapeshellarg($tmp)));

$png = sprintf('%s/screenshot-t4hide.png', $tmp);
$snap = sprintf('%s/snapshot.txt', $tmp);
if (!is_file($png) || !is_file($snap)) {
    $fail('screenshot/snapshot 不在（见 ' . $tmp . '/run.log）');
}
$snapText = (string) file_get_contents($snap);
if (!str_contains($snapText, 'commands=' . count($cmds))) {
    $fail('命令没吃满（' . $snapText . ' 尾行）');
}

$im = imagecreatefrompng($png);
if (!$im) {
    $fail('PNG 解不开');
}
$w = imagesx($im);
$h = imagesy($im);
if ($w !== 900 || $h !== 680) {
    $fail("尺寸 {$w}x{$h} ≠ 900x680");
}

/**
 * 带区最暗亮度：右缘竖带从 y1 到 y2 逐列扫，返回最小亮度（0-255）。
 * thumb 灰（~167）远暗于白底（255）——最暗值即"有没有 thumb"的判读。
 */
$bandMinLum = static function (int $x1, int $x2, int $y1, int $y2) use ($im): int {
    $min = 255;
    for ($x = $x1; $x <= $x2; $x++) {
        for ($y = $y1; $y <= $y2; $y++) {
            $c = imagecolorat($im, $x, $y);
            $lum = ((($c >> 16) & 0xFF) + (($c >> 8) & 0xFF) + ($c & 0xFF)) / 3;
            if ($lum < $min) {
                $min = (int) round($lum);
            }
        }
    }
    return $min;
};

// 盒几何（kit.php sectionKitScrollbarPair：p40，两盒 w220 h120，gap40）：
// hover 盒内容 (40,40)-(260,160)，always 盒 (300,40)-(520,160)；thumb 贴右缘，
// 出图坐标即内容坐标（screenshot 从窗口左上起）。
$hoverBandMin = $bandMinLum(238, 256, 55, 145);
$alwaysBandMin = $bandMinLum(498, 516, 55, 145);
exec('rm -rf ' . escapeshellarg($tmp));

$hoverHidden = $hoverBandMin >= 240;
$alwaysShown = $alwaysBandMin <= 200;

echo "HOVER-HIDE hover带最暗={$hoverBandMin}（≥240=无 thumb） always带最暗={$alwaysBandMin}（≤200=有 thumb）\n";

if (!$alwaysShown) {
    echo "HOVER-HIDE always 盒没亮——序列没跑起来（滚动没生效？）";
    exit(2);
}
if (!$hoverHidden) {
    echo "HOVER-HIDE FAIL ⇒ hover 盒 thumb 常驻（#112 回归：两盒 scrollId 又碰撞了？"
        . "查 cmd/patch-scroll-id-ordinal.sh 是否在册且已落）\n";
    exit(1);
}
echo "HOVER-HIDE OK ⇒ always 盒亮、hover 盒净（滚动 3 秒后）——hover 模式 thumb 可隐藏\n";
exit(0);
