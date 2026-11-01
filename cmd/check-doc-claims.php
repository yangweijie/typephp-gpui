<?php

declare(strict_types=1);

/**
 * cmd/check-doc-claims.php — 文档的"接了/没接"声明必须能被代码对一遍。
 *
 * 为什么要这条门：docs/{,en/}guide/upstream-coverage.md 的"上游概念对照"表与
 * docs/{,en/}guide/system-capabilities.md 的"已接"表是两处独立手写的。2026-11-02 实测到
 * 一处自相矛盾：第 3 节写着动画糖已接（`El::transition()`/`El::spring()` + kit 的
 * transitionInterpolation 门），对照表里却还留着 `animation | ❌ | transition/spring 整族未接`。
 * 文档内部打架不会让任何一层门禁红——它只是让下一位读者信了错的那半句。
 *
 * 两个方向（同一判据的正反两面）：
 *  A 少报：说某面"没接"，代码里却已有这个方法 ⇒ 红。
 *  B 多报：「已接」表点名了一个 PHP 方法，代码里根本没有 ⇒ 红。
 *
 * 判据（刻意窄，避免误红）：
 *  - A 只看"概念对照"那一张表（zh/en 的 `## …概念…` 标题之后、下一个 `## ` 之前）。
 *    该表一行一个概念，"缺口标记 + 反引号点名"才成立。别处的"没接"句子（如
 *    「端口里有、还没接」里 `**不做**` 那些）不扫：那里的行会把**已接**的方法当对照上下文
 *    写进同一格（例：deflate 那行的"解压侧已经接了（`App::inflate`）"），扫它就是必假红。
 *  - B 只看两张"已接"表（zh `## 已接` / en `## Connected APIs`）的**第一列**（PHP API 列）。
 *    第一列是签名，第二列才是 C++ 符号——不扫后面几列，就不会把 `shell::FsRun`
 *    这类上游名字当成 PHP 方法。
 *  - B 认两种写法：`Class::method()` / `$app->method()`（按那个类判）与裸 `method()`
 *    （按 El/Element/App 三张表并集判，en 页习惯省掉 `$app->` 前缀）。斜杠分组清单
 *    （`App::fsRead/fsWrite/…`）逐个成员判。
 *    代价：裸名跨类误配（把 App 的方法写成裸名并期望它是 El 的）B 判不出，交给 A 与测试。
 *  - A 认反引号里的裸名（`transition`）与带前缀的点名（`El::x()`、`$app->x()`），按最后一段
 *    对小写方法名表；上游 op 名（move_to、gpui::PathFill）三张表里都没有 ⇒ 不匹配。
 *
 * 自检臂（--self-check）：A、B 各内置一对样本（矛盾样本必须被判红、合法样本必须不判红）。
 * 判不出红就说明探测器坏了。这条门自己的"能红"也在仓外临时改文档实测过（见 testing.md 门表）。
 *
 * 用法：php cmd/check-doc-claims.php [--self-check]
 * 退出码 0 = 无矛盾；1 = 有（逐条点名）；2 = 前提不成立（文档结构变了，读不到那些表）。
 */

$root = dirname(__DIR__);
$selfCheck = in_array('--self-check', $argv, true);

/** 反射拿公开方法名表，按类分组（小写键，避开大小写差异带来的漏检） */
function claimedSurface(string $root): array
{
    require_once $root . '/vendor/autoload.php';
    $byClass = [];
    foreach (['El', 'Element', 'App'] as $cls) {
        $class = 'TypePHP\\Gpui\\' . $cls;
        if (!class_exists($class)) {
            continue;
        }
        $ref = new ReflectionClass($class);
        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            $byClass[$cls][strtolower($m->getName())] = $cls . '::' . $m->getName() . '()';
        }
    }
    return $byClass;
}

$GAP_MARKERS = ['❌', '未接', '未实现', 'not exposed', 'not supported', 'no application locale'];

/**
 * 取某标题之下、下一个 `## ` 之前的表格行（`### ` 子标题不打断）。
 * @return list<array{0:int,1:string}> [行号, 原始行]
 */
function sectionRows(array $lines, string $heading): array
{
    $rows = [];
    $inTable = false;
    foreach ($lines as $i => $line) {
        if (preg_match('/^##\s/', $line)) {
            $inTable = str_contains($line, $heading);
            continue;
        }
        if ($inTable && preg_match('/^\|/', $line)) {
            $rows[] = [$i + 1, $line];
        }
    }
    return $rows;
}

/** A 少报：缺口声明行里点名了一个代码里已有的方法。 */
function scanUnderClaim(array $rows, array $byClass, array $markers): array
{
    $union = [];
    foreach ($byClass as $tbl) {
        $union += $tbl;
    }
    $problems = [];
    foreach ($rows as [$ln, $line]) {
        $hasMarker = false;
        foreach ($markers as $mk) {
            if (mb_stripos($line, $mk) !== false) {
                $hasMarker = true;
                break;
            }
        }
        if (!$hasMarker || !preg_match_all('/`([^`]+)`/', $line, $m)) {
            continue;
        }
        foreach ($m[1] as $ident) {
            $key = strtolower(trim($ident, " \t()\\$"));
            // 允许 `El::transition()` 这种带类前缀的点名：取最后一段
            foreach (['::', '->'] as $sep) {
                $at = strrpos($key, $sep);
                if ($at !== false) {
                    $key = substr($key, $at + strlen($sep));
                }
            }
            if ($key !== '' && !str_contains($key, ' ') && isset($union[$key])) {
                $problems[] = [$ln, trim($ident), $union[$key], '少报'];
            }
        }
    }
    return $problems;
}

/** B 多报：已接表第一列点名的方法在代码里没有。 */
function scanOverClaim(array $rows, array $byClass): array
{
    $union = [];
    foreach ($byClass as $tbl) {
        $union += $tbl;
    }
    $problems = [];
    foreach ($rows as [$ln, $line]) {
        $cells = explode('|', trim($line, '|'));
        $col1 = $cells[0] ?? '';
        if (!preg_match_all('/`([^`]+)`/', $col1, $spans)) {
            continue;
        }
        foreach ($spans[1] as $code) {
            if (!preg_match_all('/([A-Za-z_]\w*)((?:\s*\/\s*[A-Za-z_]\w*)*)\s*[(\/]/', $code, $g, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($g as $one) {
                $before = substr($code, 0, $one[1][1]);
                $cls = null;
                if (preg_match('/(?:\\\\|\$)?([A-Za-z_]\w*)(::|->)$/s', $before, $p)) {
                    $label = $p[1];
                    if (strtolower($label) === 'app') {
                        $cls = 'App';
                    } elseif (isset($byClass[$label])) {
                        $cls = $label;
                    }
                }
                foreach (preg_split('/\s*\/\s*/', $one[1][0] . ($one[2][0] ?? '')) as $nm) {
                    if ($nm === '') {
                        continue;
                    }
                    $low = strtolower($nm);
                    $found = $cls === null ? ($union[$low] ?? null) : ($byClass[$cls][$low] ?? null);
                    if ($found === null) {
                        $problems[] = [$ln, $nm, $cls === null ? 'El/Element/App 三张表都没有' : $cls . ' 类没有', '多报'];
                    }
                }
            }
        }
    }
    return $problems;
}

if ($selfCheck) {
    $names = claimedSurface($root);
    $aRows = sectionRows([
        '## 6. probe',
        '',
        '| Animation | ❌ | `transition` and `spring` not exposed |',
        '| Paint | ❌ | `move_to` not exposed |',
    ], '6. probe');
    $a = scanUnderClaim($aRows, $names, $GAP_MARKERS);
    $bRows = sectionRows([
        '## Connected probe',
        '',
        '| `El::inspector($id)` | native | ok |',
        '| `App::notARealMethod($x)` | native | bad |',
        '| `El::clipboardText()` | native | bad（真方法在 App 上，跨类误配要能判出）|',
        '| `El::webview($url, $id)` | native | ok |',
    ], 'Connected probe');
    $b = scanOverClaim($bRows, $names);
    $aCaught = array_column($a, 1) === ['transition', 'spring'];
    $bCaught = array_column($b, 1) === ['notARealMethod', 'clipboardText'];
    $ok = $aCaught && $bCaught;
    printf(
        "A 臂（少报）: %s 判出 %s\nB 臂（多报）: %s 判出 %s\n",
        $aCaught ? 'OK' : 'FAIL',
        json_encode(array_column($a, 1), JSON_UNESCAPED_UNICODE),
        $bCaught ? 'OK' : 'FAIL',
        json_encode(array_column($b, 1), JSON_UNESCAPED_UNICODE)
    );
    echo $ok
        ? "DOC CLAIMS SELF-CHECK OK（两向的矛盾样本都判红、合法样本都没误红）\n"
        : "DOC CLAIMS SELF-CHECK FAIL\n";
    exit($ok ? 0 : 1);
}

$names = claimedSurface($root);
if ($names === []) {
    fwrite(STDERR, "读不到 El/Element/App 的公开方法表（vendor/autoload.php 是否正常？）\n");
    exit(2);
}

$under = [];
$over = [];
$notes = [];
$census = [];
foreach ([
    ['docs/guide/upstream-coverage.md', '上游文档的概念页', 'A'],
    ['docs/en/guide/upstream-coverage.md', 'Upstream concepts mapped', 'A'],
    ['docs/guide/system-capabilities.md', '已接', 'B'],
    ['docs/en/guide/system-capabilities.md', 'Connected APIs', 'B'],
] as [$file, $heading, $dir]) {
    $path = $root . '/' . $file;
    if (!is_file($path)) {
        $notes[] = "缺文件 {$file}";
        continue;
    }
    $rows = sectionRows(file($path, FILE_IGNORE_NEW_LINES), $heading);
    if ($rows === []) {
        $notes[] = "{$file} 的「{$heading}」表里没读到任何行——表结构变了，这一张本门没看到东西";
        continue;
    }
    $census[] = $dir . ':' . count($rows);
    $hits = $dir === 'A'
        ? scanUnderClaim($rows, $names, $GAP_MARKERS)
        : scanOverClaim($rows, $names);
    foreach ($hits as [$ln, $ident, $real, $kind]) {
        $line = sprintf('  ✗ %s:%d 「%s」%s：%s', $file, $ln, $ident, $kind === '少报' ? '被说成没接，但代码里有' : '被说成已接，但代码里没有', $real);
        if ($dir === 'A') {
            $under[] = $line;
        } else {
            $over[] = $line;
        }
    }
}

$union = count(array_reduce($names, static fn ($c, $tbl) => $c + $tbl, []));
$scan = '扫 ' . count($census) . '/4 张表（' . implode(' ', $census) . ' 行），方法并集 ' . $union . ' 名';

if ($notes !== [] && $under === [] && $over === []) {
    foreach ($notes as $note) {
        fwrite(STDERR, "  ! {$note}\n");
    }
    fwrite(STDERR, "DOC CLAIMS 未判定（前提不成立；{$scan}）\n");
    exit(2);
}

foreach ([...$under, ...$over] as $line) {
    echo $line . "\n";
}
foreach ($notes as $note) {
    echo "  ! {$note}\n";
}
$n = count($under) + count($over);
echo $n === 0
    ? "DOC CLAIMS OK — {$scan}；「没接」的声明代码里确没有、「已接」的声明代码里确也有\n"
    : "DOC CLAIMS FAIL ({$n} = 少报 " . count($under) . ' / 多报 ' . count($over) . ")\n";
exit($n === 0 ? 0 : 1);
