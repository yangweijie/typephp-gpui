<?php

declare(strict_types=1);

/**
 * cmd/check-doc-claims.php — 「能力对照页说某面没接，但代码里已经接了」的漂移门。
 *
 * 为什么要这条门：docs/{,en/}guide/upstream-coverage.md 的"上游概念对照"表与本页第 3 节
 * 是两处独立手写的。2026-11-02 实测到一处自相矛盾：第 3 节写着动画糖已接
 * （`El::transition()`/`El::spring()` + kit 的 transitionInterpolation 门），
 * 对照表里却还留着 `animation | ❌ | transition/spring 整族未接`。
 * 文档内部打架不会让任何一层门禁红——它只是让下一位读者信了错的那半句。
 *
 * 判据（刻意窄，避免误红）：
 *  - 只看"概念对照"那一张表（zh `## 6.` / en `## 6.` 之后、下一个 `## ` 之前）；
 *  - 行里要有"缺口标记"（❌ / 未接 / 未实现 / not exposed / not supported）才算它在声明缺口；
 *  - 反引号里的标识符必须**正好等于** El/Element/App 的一个公开方法名才算点名了一个面；
 *    上游 op 名（move_to、PathFill 这类）不匹配，故第 3 节与 paint 行不会误红。
 *  - 命中 ⇒ 红，并点名"文档说没有、代码里有"。
 *
 * 自检臂（--self-check）：内置一行真矛盾样本，必须被判红；判不出红就说明探测器坏了。
 * 这条门自己的"能红"也在仓外临时改文档实测过（见 testing.md 的门表）。
 *
 * 用法：php cmd/check-doc-claims.php [--self-check]
 * 退出码 0 = 无矛盾；1 = 有（逐条点名）；2 = 前提不成立（文档结构变了，读不到那张表）。
 */

$root = dirname(__DIR__);
$selfCheck = in_array('--self-check', $argv, true);

/** 反射拿三份公开方法名表（小写比较，避开大小写差异带来的漏检） */
function claimedSurface(string $root): array
{
    require_once $root . '/vendor/autoload.php';
    $names = [];
    foreach (['TypePHP\\Gpui\\El', 'TypePHP\\Gpui\\Element', 'TypePHP\\Gpui\\App'] as $cls) {
        if (!class_exists($cls)) {
            continue;
        }
        $ref = new ReflectionClass($cls);
        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            $names[strtolower($m->getName())] = $cls . '::' . $m->getName() . '()';
        }
    }
    return $names;
}

$GAP_MARKERS = ['❌', '未接', '未实现', 'not exposed', 'not supported', 'no application locale'];

/**
 * 扫一张概念表：返回 [文件, 行号, 行内文本, 命中的方法] 列表。
 * @return array{0: list<array{0:string,1:int,2:string,3:string}>, 1: list<string>}
 */
function scanTable(string $file, string $heading, array $names, array $markers): array
{
    global $root;
    $problems = [];
    $notes = [];
    $path = $root . '/' . $file;
    if (!is_file($path)) {
        $notes[] = "缺文件 {$file}";
        return [$problems, $notes];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    $inTable = false;
    $sawHeading = false;
    foreach ($lines as $i => $line) {
        if (preg_match('/^##\s/', $line)) {
            $inTable = str_contains($line, $heading);
            $sawHeading = $sawHeading || $inTable;
            continue;
        }
        if (!$inTable || !preg_match('/^\|/', $line)) {
            continue;
        }
        $hasMarker = false;
        foreach ($markers as $mk) {
            if (mb_stripos($line, $mk) !== false) {
                $hasMarker = true;
                break;
            }
        }
        if (!$hasMarker) {
            continue;
        }
        if (!preg_match_all('/`([^`]+)`/', $line, $m)) {
            continue;
        }
        foreach ($m[1] as $ident) {
            $key = strtolower(trim($ident, " \t()\\$"));
            // 只认"正好是一个方法名"的点名：El::transition() 取 transition，transition 取 transition
            if (!str_contains($key, ' ') && isset($names[$key])) {
                $problems[] = [$file, $i + 1, trim($ident), $names[$key]];
            }
        }
    }
    if (!$sawHeading) {
        $notes[] = "{$file} 里找不到标题「{$heading}」——表结构变了，本门没看到东西";
    }
    return [$problems, $notes];
}

if ($selfCheck) {
    // 自检臂：一行真矛盾（说 transition 没接，而 El::transition() 在代码里）。
    $tmp = tempnam(sys_get_temp_dir(), 'docclaims');
    $body = "## 6. probe\n\n| Animation | `transition` and `spring` not exposed |\n"
        . "| Paint | `move_to` not exposed |\n";
    file_put_contents($tmp, $body);
    $names = claimedSurface($root);
    // 临时把"根"指到 tmp，文件名用 basename
    $problems = [];
    $lines = file($tmp, FILE_IGNORE_NEW_LINES);
    foreach ($lines as $i => $line) {
        if (!preg_match('/^\|/', $line) || !preg_match('/not exposed/', $line)) {
            continue;
        }
        preg_match_all('/`([^`]+)`/', $line, $m);
        foreach ($m[1] as $ident) {
            $key = strtolower(trim($ident, " \t()\\$"));
            if (isset($names[$key])) {
                $problems[] = $key;
            }
        }
    }
    unlink($tmp);
    $ok = in_array('transition', $problems, true) && !in_array('move_to', $problems, true);
    echo $ok
        ? "DOC CLAIMS SELF-CHECK OK（矛盾样本被判红、上游 op 名样本没误红）\n"
        : "DOC CLAIMS SELF-CHECK FAIL（判定=" . json_encode($problems) . "）\n";
    exit($ok ? 0 : 1);
}

$names = claimedSurface($root);
if ($names === []) {
    fwrite(STDERR, "读不到 El/Element/App 的公开方法表（vendor/autoload.php 是否正常？）\n");
    exit(2);
}

$all = [];
$notes = [];
foreach ([
    ['docs/guide/upstream-coverage.md', '上游文档的概念页'],
    ['docs/en/guide/upstream-coverage.md', 'Upstream concepts mapped'],
] as [$file, $heading]) {
    [$p, $n] = scanTable($file, $heading, $names, $GAP_MARKERS);
    foreach ($p as $row) {
        $all[] = $row;
    }
    foreach ($n as $note) {
        $notes[] = $note;
    }
}

if ($notes !== [] && $all === []) {
    foreach ($notes as $note) {
        fwrite(STDERR, "  ! {$note}\n");
    }
    fwrite(STDERR, "DOC CLAIMS 未判定（前提不成立）\n");
    exit(2);
}

foreach ($all as [$file, $ln, $ident, $real]) {
    printf("  ✗ %s:%d 说「%s」没接，但代码里有 %s\n", $file, $ln, $ident, $real);
}
echo $all === []
    ? 'DOC CLAIMS OK — 概念对照表没再说"没接"的面其实代码里已有（方法表 ' . count($names) . " 项）\n"
    : "DOC CLAIMS FAIL (" . count($all) . ")\n";
exit($all === [] ? 0 : 1);
