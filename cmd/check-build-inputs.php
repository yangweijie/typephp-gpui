<?php

declare(strict_types=1);

/*
 * cmd/check-build-inputs.php —— 「克隆出来能不能构建」的常驻门（不需要编译器，任何 runner 都能跑）。
 *
 * 为什么要有它：审核 P0-1 量到 bin/gpui 与 CI 按名字调用的那几支补丁脚本、若干示例源与
 * 一批像素基线**全是未跟踪状态**——从干净克隆出发这些步骤
 * 都不可能满足，而本地工作树跑起来一切正常，所以没有任何信号。
 *
 * 检查六件事：
 *   1) bin/gpui 补丁清单点名的每支 cmd/*.sh 都在版本控制里；
 *   2) cmd/ 下每支 patch-*.sh 都在清单里（新补丁忘记登记 = 永远不会被跑到）；
 *   3) 每个示例 yml 的 sources: 指向的 PHP/C++ 文件都在版本控制里；
 *   4) 全仓不变式：索引里没有任何"路径在册但内容是空 blob"的路径；
 *   5) .github/workflows/ci.yml 点名的每支 cmd/*.(php|sh) 都在版本控制里
 *      （CI 引用一支未跟踪的脚本 = runner 上 "could not open input file"，
 *      这条是当场抓到本轮自己新造的洞才加上的）；
 *   6) composer.json 的 scripts 点名的本地路径与 `bin/gpui <动词> <示例>` 示例名都真的存在
 *      （删掉一个示例/一份文件后，最容易留在入口里的就是这种"跑起来才懂"的坏引用）。
 *
 * 「在版本控制里」按**内容**判：`git add -N`（intent-to-add）只把路径记进索引、
 * 索引里是空 blob，`git ls-files -- <path>` 会说它在册，而按索引提交的产物是 0 字节文件——
 * 那是 P0-1「未跟踪的构建输入」同一类的更深一层，所以这里查 sha 而不是只查路径存在。
 *
 * 用法：php cmd/check-build-inputs.php   （非 0 = 有构建输入没进树）
 */

$root = dirname(__DIR__);
$fail = 0;
$ok = static function (string $s): void { echo "  \033[32m✓\033[0m {$s}\n"; };
$bad = static function (string $s) use (&$fail): void { $fail++; echo "  \033[31m✗\033[0m {$s}\n"; };
$intentHint = static function (string $rel): void {
    echo "      ↳ 实情：{$rel} 只是 intent-to-add——索引里是空 blob("
        . "e69de29…)、内容只在磁盘上。按索引原样提交会得到 0 字节的文件，"
        . "克隆出来照样构建不起来。提交前必须 `git add {$rel}`。\n";
};
$tracked = static function (string $rel) use ($root, $intentHint): bool {
    // 只问 `git ls-files -- <path>` 的话，intent-to-add 的路径也算"在册"——
    // 那正是 P0-1 那一类失效的更深一层：文件在索引里是空的，克隆下来是 0 字节。
    $out = trim((string) shell_exec(
        'git -C ' . escapeshellarg($root) . ' ls-files -s -- ' . escapeshellarg($rel)
    ));
    if ($out === '') {
        return false;
    }
    $intent = false;
    foreach (explode("\n", $out) as $line) {
        $head = explode("\t", $line);
        $cols = preg_split('/\s+/', trim($head[0]));
        $sha = (string) ($cols[1] ?? '');
        $path = (string) ($head[1] ?? $rel);
        if ($sha === 'e69de29bb2d1d6434b8b29ae775ad8c2e48c5391'
            && is_file($root . '/' . $path) && (int) filesize($root . '/' . $path) > 0) {
            $intent = true;
        }
    }
    if ($intent) {
        $intentHint($rel);
        return false;
    }
    return true;
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

// 4) 全仓不变式：索引里不该有"路径在册、内容是空 blob"的路径。
//    上面三条只覆盖补丁清单与示例 sources；英文文档、像素基线、tests/*.php 这些同样会随
//    intent-to-add 提交成 0 字节——而 0 字节的基线会让 shotdiff 拿空图当标准答案。
$emptyBlob = [];
foreach (explode("\n", trim((string) shell_exec(
    'git -C ' . escapeshellarg($root) . ' ls-files -s'
))) as $line) {
    $head = explode("\t", $line);
    $cols = preg_split('/\s+/', trim($head[0]));
    $sha = (string) ($cols[1] ?? '');
    $path = (string) ($head[1] ?? '');
    if ($sha === 'e69de29bb2d1d6434b8b29ae775ad8c2e48c5391'
        && $path !== '' && is_file($root . '/' . $path)
        && (int) filesize($root . '/' . $path) > 0) {
        $emptyBlob[] = $path;
    }
}
if ($emptyBlob === []) {
    $ok('索引内没有 intent-to-add 空内容路径');
} else {
    $bad('这些路径在索引里是空 blob（提交后克隆下来是 0 字节），提交前必须 git add：'
        . implode(' ', $emptyBlob));
}

// 5) CI 点名的 cmd/ 脚本必须在册。ci.yml 里写的每一条 `cmd/xxx.(php|sh)` 都是
//    干净克隆里要真的能打开的文件；漏一条就是 runner 上 "could not open input file"。
$ci = (string) file_get_contents("$root/.github/workflows/ci.yml");
$ciRefs = [];
if (preg_match_all('#\bcmd/([A-Za-z0-9_.-]+\.(?:php|sh))#', $ci, $cm)) {
    $ciRefs = array_values(array_unique($cm[1] ?? []));
}
if ($ciRefs === []) {
    $bad('没能从 .github/workflows/ci.yml 里读到任何 cmd/ 脚本——这条检查空跑了');
} else {
    foreach ($ciRefs as $b) {
        $tracked("cmd/$b")
            ? $ok("CI 点名的脚本已在册：cmd/{$b}")
            : $bad("CI 点名但不在版本控制里：cmd/{$b}（干净克隆的 runner 会打不开这个文件）");
    }
}

// 6) composer.json 的 scripts 点名的本地目标必须存在。
//    这个仓库被"陈旧入口"咬过不止一次（composer 写死 8 支补丁、bin/gpui 跑 11 支；
//    删掉画廊后若还留着 `bin/gpui selftest showcase` 这类脚本，跑的人只会撞上一句
//    看不懂的"示例不存在"）。所以脚本串里出现的 cmd|bin|tests|docs|examples|php-src|cpp-src
//    路径，以及 `bin/gpui <动词> <示例>` 的示例名，都拿来对着磁盘核一次。
$composer = json_decode((string) file_get_contents("$root/composer.json"), true);
$scripts = is_array($composer) && is_array($composer['scripts'] ?? null) ? $composer['scripts'] : [];
if ($scripts === []) {
    $bad('读不到 composer.json 的 scripts——这条检查空跑了');
} else {
    $badTargets = [];
    $checkedTargets = 0;
    foreach ($scripts as $name => $cmd) {
        foreach ((array) $cmd as $line) {
            foreach (preg_split('#\s+&&\s+|\s+;\s+#', (string) $line) as $part) {
                if (preg_match('#(^|\s)((?:cmd|bin|tests|docs|examples|php-src|cpp-src)/[A-Za-z0-9_./*-]+)#', $part, $m)) {
                    $checkedTargets++;
                    $rel = $m[2];
                    // 通配与字面路径要分开判：对 "cmd/patch-*.sh" 这种串做 file_exists()
                    // 永远返回 false，那样会把好脚本误报成坏目标。
                    if (str_contains($rel, '*')) {
                        if ((glob("$root/$rel") ?: []) === []) {
                            $badTargets[] = "$name → $rel（glob 为空）";
                        }
                    } elseif (!file_exists("$root/$rel")) {
                        $badTargets[] = "$name → $rel";
                    }
                }
                if (preg_match('#bin/gpui[\'"]?\s+(?:selftest|build|run|shotdiff\s+(?:check|record))\s+([a-z][a-z0-9-]*)#', $part, $me)) {
                    $checkedTargets++;
                    if (!is_dir("$root/examples/{$me[1]}")) {
                        $badTargets[] = "$name → 示例不存在: {$me[1]}";
                    }
                }
            }
        }
    }
    if ($badTargets !== []) {
        foreach ($badTargets as $b) {
            $bad("composer scripts 点到不存在的目标：{$b}");
        }
    } else {
        $ok("composer scripts 的 {$checkedTargets} 个本地目标都存在（" . count($scripts) . ' 支脚本）');
    }
}

echo $fail === 0 ? "BUILD INPUTS OK\n" : "BUILD INPUTS FAIL ($fail)\n";
exit($fail === 0 ? 0 : 1);
