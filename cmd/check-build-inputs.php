<?php

declare(strict_types=1);

/*
 * cmd/check-build-inputs.php —— 「克隆出来能不能构建」的常驻门（不需要编译器，任何 runner 都能跑）。
 *
 * 为什么要有它：审核 P0-1 量到 bin/gpui 与 CI 按名字调用的三支补丁脚本、整个 examples/showcase、
 * tests/ShowcaseTest.php 与 13 张像素基线**全是未跟踪状态**——从干净克隆出发这些步骤
 * 都不可能满足，而本地工作树跑起来一切正常，所以没有任何信号。
 *
 * 检查三件事：
 *   1) bin/gpui 补丁清单点名的每支 cmd/*.sh 都在版本控制里；
 *   2) cmd/ 下每支 patch-*.sh 都在清单里（新补丁忘记登记 = 永远不会被跑到）；
 *   3) 每个示例 yml 的 sources: 指向的 PHP/C++ 文件都在版本控制里。
 *
 * 用法：php cmd/check-build-inputs.php   （非 0 = 有构建输入没进树）
 */

$root = dirname(__DIR__);
$fail = 0;
$ok = static function (string $s): void { echo "  \033[32m✓\033[0m {$s}\n"; };
$bad = static function (string $s) use (&$fail): void { $fail++; echo "  \033[31m✗\033[0m {$s}\n"; };
$tracked = static function (string $rel) use ($root): bool {
    $out = shell_exec('git -C ' . escapeshellarg($root) . ' ls-files -- ' . escapeshellarg($rel));
    return trim((string) $out) !== '';
};

$cli = (string) file_get_contents("$root/bin/gpui");

// 1)+2) 清单与磁盘互相校验（清单只有一份：patchManifest 派生 patchScripts）
preg_match_all("/\['(patch-[a-z0-9-]+\.sh)'/", $cli, $mm);
$listed = array_values(array_unique($mm[1] ?? []));
if ($listed === []) {
    $bad('没能从 bin/gpui 里读到补丁清单——这条门自己失效了');
} else {
    foreach ($listed as $s) {
        $tracked("cmd/$s") ? $ok("清单在册且已跟踪：$s") : $bad("清单点名但未跟踪：cmd/$s");
    }
    foreach (glob("$root/cmd/patch-*.sh") ?: [] as $path) {
        $name = basename($path);
        in_array($name, $listed, true) or $bad("磁盘上有 $name 但清单没登记（永远不会被跑到）");
    }
}

// 3) 每个示例的 sources 都得在树里
foreach (glob("$root/examples/*/project.macos.yml") ?: [] as $yml) {
    $dir = dirname($yml);
    $relDir = ltrim(str_replace($root, '', $dir), '/');
    foreach (explode("\n", (string) file_get_contents($yml)) as $line) {
        if (!preg_match('/^\s+-\s+(\S+\.(?:php|cc|cpp))\s*$/', $line, $m)) {
            continue;
        }
        $abs = realpath($dir . '/' . $m[1]);
        $rel = $abs === false ? $relDir . '/' . $m[1] : ltrim(str_replace($root, '', $abs), '/');
        $tracked($rel) ? $ok("sources 已跟踪：{$rel}") : $bad("sources 未跟踪：{$rel}（{$relDir} 的构建在干净克隆上会缺文件）");
    }
}

echo $fail === 0 ? "BUILD INPUTS OK\n" : "BUILD INPUTS FAIL ($fail)\n";
exit($fail === 0 ? 0 : 1);
