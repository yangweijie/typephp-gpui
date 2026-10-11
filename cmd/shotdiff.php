<?php

declare(strict_types=1);

/**
 * cmd/shotdiff.php — --shot 视觉回归基线比对（自足，用已装的 imagick/gd）。
 *
 *   php cmd/shotdiff.php record [example]   生成/刷新基线 PNG（tests/baseline/<example>/）
 *   php cmd/shotdiff.php check  [example]   逐树出图并与基线像素比对，超容差则 rc=1
 *
 * 前提：examples/<example>/build/<example> 已构建（bin/gpui build）。--shot 抓真实
 * 在屏 NSView，故仅 macOS 本地/有 GUI 的 runner 可跑（CI 的托管 runner 拉不到
 * gpui-kit-cpp，跑不了原生链——见 .github/workflows/ci.yml 顶部）。
 *
 * 基线树集：跳过 24（MenuBar 现报 app-effects 错误节点，不纳入基线以免固化坏态）。
 */

$root = dirname(__DIR__);
$mode = $argv[1] ?? 'check';
$example = $argv[2] ?? 'hello';

if (!in_array($mode, ['record', 'check'], true)) {
    fwrite(STDERR, "用法：php cmd/shotdiff.php record|check [example]\n");
    exit(2);
}

$bin = "$root/examples/$example/build/$example";
if (!is_file($bin)) {
    fwrite(STDERR, "找不到二进制：$bin（先 bin/gpui build $example）\n");
    exit(2);
}

$trees = [0, 10, 11, 12, 13, 14, 20, 21, 22, 23, 25, 26];
$baselineDir = "$root/tests/baseline/$example";
$tmpDir = sys_get_temp_dir() . "/gpui-shotdiff-$example";
@mkdir($baselineDir, 0777, true);
@mkdir($tmpDir, 0777, true);

// 像素差异度量：优先 imagick（RMSE 0..1），回落 gd 逐像素，再回落字节比对。
function pixelDiff(string $a, string $b): array {
    if (class_exists('Imagick')) {
        try {
            $ia = new Imagick($a);
            $ib = new Imagick($b);
            if ($ia->getImageWidth() !== $ib->getImageWidth()
                || $ia->getImageHeight() !== $ib->getImageHeight()) {
                $ib->destroy(); $ia->destroy();
                return ['metric' => 'size', 'value' => 1.0, 'worst' => 1.0];
            }
            $img = $ia->compareImages($ib, Imagick::METRIC_ROOT_MEAN_SQUARED_ERROR);
            $rmse = (float) $img[1];
            $ia->destroy(); $ib->destroy();
            return ['metric' => 'rmse', 'value' => $rmse, 'worst' => $rmse];
        } catch (Throwable $e) {
            // 落回 gd
        }
    }
    return gdDiff($a, $b);
}

function gdDiff(string $a, string $b): array {
    $ia = @imagecreatefrompng($a);
    $ib = @imagecreatefrompng($b);
    if ($ia === false || $ib === false) {
        return ['metric' => 'byte', 'value' => (md5_file($a) === md5_file($b)) ? 0.0 : 1.0, 'worst' => 1.0];
    }
    $w = imagesx($ia); $h = imagesy($ia);
    if ($w !== imagesx($ib) || $h !== imagesy($ib)) {
        return ['metric' => 'size', 'value' => 1.0, 'worst' => 1.0];
    }
    $maxDelta = 0.0; $diffPx = 0; $total = $w * $h;
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $ca = imagecolorat($ia, $x, $y);
            $cb = imagecolorat($ib, $x, $y);
            if ($ca !== $cb) {
                $diffPx++;
                $d = max(abs((($ca >> 16) & 0xFF) - (($cb >> 16) & 0xFF)),
                         abs((($ca >> 8) & 0xFF) - (($cb >> 8) & 0xFF)),
                         abs(($ca & 0xFF) - ($cb & 0xFF))) / 255.0;
                if ($d > $maxDelta) { $maxDelta = $d; }
            }
        }
    }
    $frac = $total > 0 ? $diffPx / $total : 0.0;
    return ['metric' => 'gdfrac', 'value' => $frac, 'worst' => $maxDelta];
}

// 容差：同机 --shot 逐字节确定；留极小容差吸收潜在编码/AA 抖动。
$THRESHOLD = 0.001;
$failures = 0;
foreach ($trees as $tree) {
    $shot = "$tmpDir/tree$tree.png";
    $cmd = escapeshellarg($bin) . ' --shot ' . escapeshellarg($shot) . ' ' . (int) $tree;
    exec($cmd . ' >/dev/null 2>&1');
    if (!is_file($shot)) {
        fwrite(STDERR, "  ✗ tree $tree：出图失败\n");
        $failures++;
        continue;
    }
    $ref = "$baselineDir/tree$tree.png";
    if ($mode === 'record') {
        copy($shot, $ref);
        echo "  ✓ record tree $tree → " . basename($ref) . "\n";
        continue;
    }
    if (!is_file($ref)) {
        fwrite(STDERR, "  ✗ tree $tree：无基线（先跑 record）\n");
        $failures++;
        continue;
    }
    $d = pixelDiff($ref, $shot);
    if ($d['value'] > $THRESHOLD) {
        printf("  ✗ tree %d：%s=%.5f 超容差（worst=%.3f）\n", $tree, $d['metric'], $d['value'], $d['worst']);
        $failures++;
    } else {
        printf("  ✓ tree %d：%s=%.5f\n", $tree, $d['metric'], $d['value']);
    }
}

if ($mode === 'record') {
    echo "基线已写入 $baselineDir\n";
    exit(0);
}
echo $failures === 0 ? "SHOTDIFF OK\n" : "SHOTDIFF FAIL ($failures)\n";
exit($failures === 0 ? 0 : 1);
