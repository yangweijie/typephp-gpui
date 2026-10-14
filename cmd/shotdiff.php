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
 * 基线树集：hello 用下面的固定表（跳过 24：MenuBar 现报 app-effects 错误节点，
 * 不纳入基线以免固化坏态）；其他示例向二进制要 `--sections`，由示例自报节号。
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
    fwrite(STDERR, "找不到二进制：{$bin}（先 bin/gpui build {$example}）\n");
    exit(2);
}

// 二进制比它的构建输入旧 ⇒ 这次量的是上一次构建的产物。这条门是 2026-10-14 真踩出来的：
// 改了 examples/_shared/features/inputs.php 之后直接 `shotdiff record showcase`，
// 13 棵树全报"字节数与基线相同"，看起来正好像"这次改动不影响像素"，
// 而真实原因是那支二进制没重编——record 模式还会把旧图重新写一遍，把假绿坐实。
$binAge = filemtime($bin);
$yml = "$root/examples/$example/project.macos.yml";
$newest = 0;
$newestSrc = '';
if (is_file($yml)) {
    foreach (explode("\n", (string) file_get_contents($yml)) as $line) {
        if (!preg_match('/^\s+-\s+(\S+\.(?:php|cc|cpp))\s*$/', $line, $m)) {
            continue;
        }
        $src = realpath(dirname($yml) . '/' . $m[1]);
        if ($src === false || !is_file($src)) {
            continue;
        }
        if (filemtime($src) > $newest) {
            $newest = filemtime($src);
            $newestSrc = $src;
        }
    }
}
if ($newest > $binAge) {
    fwrite(STDERR, "二进制过期：{$bin}（" . date('Y-m-d H:i:s', $binAge)
        . "）旧于构建输入 {$newestSrc}（" . date('Y-m-d H:i:s', $newest) . "）\n"
        . "先 php bin/gpui build {$example} --no-native 再比对，否则量的是上一次构建的产物。\n");
    exit(2);
}

// 24（catalog MenuBar）不在窗口内，抓不到，故不入基线。
// 27/28/29 = 帧率 HUD / Sheet / inspector，Wave1 系统能力的像素验收。
// 30 = WebView：它那块抓不到（跨进程表面），所以这张基线量的是"webview 没把布局搞塌"，
//     控件本身的存在与盒子由 hello --selftest 第 12 步量。
// 31 = Inspector 面板（El::inspector），gpui 自己画的，像素可验。
// 32 = 通知层（El::notifications + 两条不自动退场的通知）。
$trees = [0, 10, 11, 12, 13, 14, 20, 21, 22, 23, 25, 26, 27, 28, 29, 30, 31, 32];
if ($example !== 'hello') {
    // 其他示例（如 showcase：一节一棵树）不自报树号集就不比对——宁缺毋滥，
    // 免得把"没截图"当成"截了但一致"。
    $trees = [];
    $list = (string) shell_exec($bin . ' --sections 2>/dev/null');
    foreach (explode("\n", $list) as $line) {
        $parts = explode("\t", trim($line));
        if (count($parts) >= 1 && ctype_digit($parts[0])) {
            $trees[] = (int) $parts[0];
        }
    }
    if ($trees === []) {
        fwrite(STDERR, "$example 既没有 --sections 也没有内置树表，无法比对\n");
        exit(2);
    }
}
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
    // 先删旧图：$tmpDir 从不清理，--shot 崩在半路时上一轮的 PNG 还躺在那儿，
    // 于是"今天的产物"其实是昨天的像素——这条门自己踩进项目明文禁止的陈旧产物坑。
    if (is_file($shot)) {
        unlink($shot);
    }
    $cmd = escapeshellarg($bin) . ' --shot ' . escapeshellarg($shot) . ' ' . (int) $tree;
    // 收退出码：过去只判 is_file，非 0 退出但留下文件的情况会被当成成功。
    exec($cmd . ' >/dev/null 2>&1', $cmdOut, $rc);
    if ($rc !== 0) {
        fwrite(STDERR, "  ✗ tree {$tree}：--shot 退出码 {$rc}（非 0 就不算出图）\n");
        $failures++;
        continue;
    }
    if (!is_file($shot)) {
        fwrite(STDERR, "  ✗ tree {$tree}：出图失败（退出码 0 但没有文件）\n");
        $failures++;
        continue;
    }
    // 帧必须是"读得开且有内容"的图：空白/截断的帧一旦被 record 洗成基线，
    // 之后每一轮都会拿它当标准答案比对，全绿。
    $info = @getimagesize($shot);
    if ($info === false || $info[0] < 8 || $info[1] < 8 || filesize($shot) < 4096) {
        fwrite(STDERR, "  ✗ tree {$tree}：产物不像一帧图（"
            . ($info === false ? '解不开' : $info[0] . 'x' . $info[1])
            . ', ' . (int) filesize($shot) . " 字节）\n");
        $failures++;
        continue;
    }
    $ref = "$baselineDir/tree$tree.png";
    if ($mode === 'record') {
        $before = is_file($ref) ? filesize($ref) : 0;
        copy($shot, $ref);
        printf("  ✓ record tree %d → %s（%d 字节，基线原为 %d 字节）\n",
               $tree, basename($ref), (int) filesize($ref), (int) $before);
        continue;
    }
    if (!is_file($ref)) {
        fwrite(STDERR, "  ✗ tree {$tree}：无基线（先跑 record）\n");
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
