<?php

declare(strict_types=1);

/*
 * cmd/check-bundle-signature.php —— 门 14：每个 .app 的"在屏资格"两条件常驻检查。
 *
 * 为什么要有它（#56 的教训）：窗口拿不到 WindowServer 的在屏资格时，
 * 进程内 AppKit 照样报 active/key/visible/main 全真、像素门照样全绿
 * （`--shot` 走 cacheDisplayInRect，是进程内渲染），于是**没有任何信号**——
 * 症状只出现在真人手点手敲那一格，而这一步没人天天做。
 *
 * 两条件都由我们自己保证（是**产物属性**，不是 OS 竞争态），所以能硬断：
 *   1) Contents/MacOS/<CFBundleExecutable> 必须是**正规文件**：符号链接会让
 *      `codesign` 直接拒签（"the main executable or Info.plist must be a regular file"）；
 *   2) bundle 的签名必须把 Info.plist 绑进去：`codesign -dvv` 里出现
 *      `Info.plist entries=N`。只有 `Signature=adhoc` 而 plist 没绑上 = 未签的等价物。
 * 实测四象限：symlink / 真文件未签 / 硬链接未签 ⇒ onScreen=false；
 * 真文件 + 绑定 plist 的签名 ⇒ onScreen=true 且物理键鼠开始送达。
 *
 * 用法：php cmd/check-bundle-signature.php [--dir=<examples 根>] [--verbose]
 * 找不到任何 .app 时**报错退出**（rc=2）而不是"零个全过"——静默通过正是这类门最没用的形态。
 */

$root = dirname(__DIR__);
$dir = "$root/examples";
$verbose = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--dir=')) {
        $dir = substr($arg, 6);
    } elseif ($arg === '--verbose' || $arg === '-v') {
        $verbose = true;
    } else {
        fwrite(STDERR, "未知开关：$arg（本门只认 --dir= 与 --verbose；不静默当通过）\n");
        exit(2);
    }
}

if (!is_dir($dir)) {
    fwrite(STDERR, "目录不存在：$dir\n");
    exit(2);
}

/** 只认 examples/<示例>/build/<名>.app 这一层，外加 --dir 直接指向单个 .app 的情形（红侧自证用）。 */
$apps = glob("$dir/*/build/*.app") ?: [];
if ($dir !== "$root/examples" && str_ends_with($dir, '.app')) {
    $apps = [$dir];
}
if ($apps === []) {
    fwrite(STDERR, "没找到任何 .app（扫的是 $dir/*/build/*.app）——"
        . "先跑 php bin/gpui build <示例> --no-native 造出 bundle，再跑本门。\n");
    fwrite(STDERR, "扫零个不算通过：那样这条门在'产物根本没建'的机器上是永远绿的空壳。\n");
    exit(2);
}

$problems = 0;
foreach ($apps as $app) {
    $name = basename($app);
    $plist = "$app/Contents/Info.plist";
    if (!is_file($plist)) {
        echo "✗ {$name}：没有 Info.plist\n";
        $problems++;
        continue;
    }
    $exec = trim((string) shell_exec('plutil -extract CFBundleExecutable raw ' . escapeshellarg($plist) . ' 2>/dev/null'));
    if ($exec === '') {
        echo "✗ {$name}：Info.plist 里没有 CFBundleExecutable\n";
        $problems++;
        continue;
    }
    $main = "$app/Contents/MacOS/$exec";
    if (!is_file($main)) {
        echo "✗ {$name}：主可执行文件不存在：$main\n";
        $problems++;
        continue;
    }
    // 条件 1：正规文件。is_link() 必须在 is_file() 之前判——符号链接两者都为真。
    if (is_link($main)) {
        echo "✗ {$name}：主可执行文件是符号链接（codesign 会拒签这种 bundle）：$main\n";
        $problems++;
        continue;
    }
    // 条件 2：签名把 Info.plist 绑进去。
    $desc = (string) shell_exec('codesign -dvv ' . escapeshellarg($app) . ' 2>&1');
    if (!preg_match('/Info\.plist entries=(\d+)/', $desc, $m)) {
        $why = trim(explode("\n", $desc)[0] ?? '');
        echo "✗ {$name}：签名没绑定 Info.plist（窗口拿不到在屏资格）"
            . ($verbose && $why !== '' ? "：codesign 说「$why」" : '') . "\n";
        $problems++;
        continue;
    }
    $verify = (int) shell_exec('codesign --verify --strict ' . escapeshellarg($app) . ' 2>/dev/null; echo $?');
    if ($verify !== 0) {
        echo "✗ {$name}：codesign --verify 回非零（$verify）——签名与内容不一致\n";
        $problems++;
        continue;
    }
    if ($verbose) {
        echo "✓ {$name}：正规文件 + Info.plist entries={$m[1]} + verify 通过\n";
    }
}

printf("扫了 %d 个 bundle，%d 个有问题。\n", count($apps), $problems);
if ($problems > 0) {
    echo "BUNDLE SIGNATURE GATE FAILED —— 这些 bundle 起的应用窗口不会上屏，"
        . "而进程内自报与像素门都看不出来（见文件头）。\n";
    exit(1);
}
echo "BUNDLE SIGNATURE GATE OK\n";
exit(0);
