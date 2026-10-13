<?php

declare(strict_types=1);

use TypePHP\Gpui\FakeBridge;

/*
 * 跨层契约的门：php-src/gpui.stub.php（PHP 声明）↔ src/FakeBridge.php（无头替身）
 * ↔ cpp-src/gpui_bridge.cc（真桥）三处必须说同一件事。
 *
 * 为什么需要这条门：这个项目的每个能力都活在四层里，而**没有任何一处编译期会检查它们
 * 对齐**。已经为此付过的学费：
 *  - App::setAppMenus 用 label/id 传参，bridge 读的是 name/action ⇒ 菜单装得上去、
 *    点了永远没回音（不报错，四层各自"看着对"）。
 *  - 假桥把入参原样收下 ⇒ 上面那条在 Pest 里完全隐形。
 *  - stub 里两条声明挤在同一行（`{}function gpui_hotkey_count()`）⇒ 行向的机器读取
 *    会静默漏掉一个函数，而 php -l 不报。
 * 所以这里做的是"名字与元数必须一一对上"，不是"行为像不像"（行为由 selftest 管）。
 */

/** 解析 PHP 风格的函数参数表，返回元数（顶层逗号计数，忽略嵌套括号/字符串里的逗号）。 */
function contractArity(string $params): int
{
    $params = trim($params);
    if ($params === '') {
        return 0;
    }
    $depth = 0;
    $n = 1;
    $len = strlen($params);
    for ($i = 0; $i < $len; $i++) {
        $c = $params[$i];
        if ($c === '(' || $c === '[' || $c === '{') {
            $depth++;
        } elseif ($c === ')' || $c === ']' || $c === '}') {
            $depth--;
        } elseif ($c === ',' && $depth === 0) {
            $n++;
        }
    }
    return $n;
}

/** @return array<string,int> 函数名 → 元数 */
function contractParsePhp(string $code): array
{
    $out = [];
    if (!preg_match_all('/^[ \t]*function[ \t]+(gpui_\w+)[ \t]*\(([^)]*)\)/m', $code, $m, PREG_SET_ORDER)) {
        return $out;
    }
    foreach ($m as $one) {
        $out[$one[1]] = contractArity($one[2]);
    }
    return $out;
}

test('the stub is well formed: one declaration per line, every line parseable', function (): void {
    $path = __DIR__ . '/../php-src/gpui.stub.php';
    $lines = file($path) ?: [];
    $ugly = [];
    foreach ($lines as $i => $line) {
        // 两条声明挤一行（`}function ...`）时 php -l 照样过，但任何行向读取都会漏掉第二条。
        if (preg_match('/\}\s*function\s+gpui_/', $line)) {
            $ugly[] = ($i + 1) . ': ' . trim($line);
        }
    }
    expect($ugly)->toBe([], 'gpui.stub.php 里有函数声明挤在同一行：' . implode(' | ', $ugly));

    $stub = contractParsePhp(implode('', $lines));
    // 数量本身也是断言：解析器退化到"一个都没读到"时，下面的差集检查会全部空过。
    expect(count($stub))->toBeGreaterThan(70);
});

test('stub, FakeBridge and the C++ bridge declare the same gpui_* surface', function (): void {
    $stub = contractParsePhp((string) file_get_contents(__DIR__ . '/../php-src/gpui.stub.php'));
    $fake = contractParsePhp((string) file_get_contents(__DIR__ . '/../src/FakeBridge.php'));
    $bridgeSrc = (string) file_get_contents(__DIR__ . '/../cpp-src/gpui_bridge.cc');

    $bridge = [];
    if (preg_match_all('/\b(?:Variant|Int|Bool|Float|String|Array|void)[ \t\n]+php_(gpui_\w+)[ \t]*\(/', $bridgeSrc, $bm, PREG_SET_ORDER)) {
        foreach ($bm as $one) {
            $bridge[$one[1]] = true;
        }
    }

    // ① 声明了却没有替身 / 声明了却没有真实现 = 要么测试跑不到，要么 AOT 链接期才炸。
    $noFake = array_diff(array_keys($stub), array_keys($fake));
    expect(array_values($noFake))->toBe([], 'stub 里有函数没被 FakeBridge 实现：' . implode(',', $noFake));

    $noBridge = array_diff(array_keys($stub), array_keys($bridge));
    expect(array_values($noBridge))->toBe([], 'stub 声明了但 gpui_bridge.cc 里没有 php_gpui_* 实现：' . implode(',', $noBridge));

    // ② 反向：假桥不能自己长出没声明的函数（否则测试在测一个产品里不存在的接口）。
    // gpui_fake_* 是替身自己的内部辅助（路径归一化、递归删目录），不是 API 面，豁免。
    $orphanFake = array_filter(
        array_diff(array_keys($fake), array_keys($stub)),
        static fn (string $n): bool => !str_starts_with($n, 'gpui_fake_')
    );
    expect(array_values($orphanFake))->toBe([], 'FakeBridge 里有 stub 没声明的函数：' . implode(',', $orphanFake));

    // ③ 元数必须一致：参数顺序/个数错位在 nano 侧是 ArgumentCountError 或静默传错。
    $arity = [];
    foreach ($stub as $name => $count) {
        if (($fake[$name] ?? null) !== $count) {
            $arity[] = $name . ' stub=' . $count . ' fake=' . ($fake[$name] ?? '?');
        }
    }
    expect($arity)->toBe([], 'stub 与 FakeBridge 元数不一致：' . implode(' | ', $arity));
});

test('every App method that wraps a gpui_* call points at a declared function', function (): void {
    // PHP 侧写错函数名（\gpui_elment_value）在 php -l 与 phpstan 下都可能过（动态调用），
    // 到 nano 运行时才是 SIGABRT。这里把 App.php 里所有 \gpui_* 调用与 stub 对一遍。
    $stub = contractParsePhp((string) file_get_contents(__DIR__ . '/../php-src/gpui.stub.php'));
    $appSrc = (string) file_get_contents(__DIR__ . '/../src/App.php');
    preg_match_all('/\\\\?(gpui_[a-z0-9_]+)\s*\(/', $appSrc, $m);
    $bad = [];
    foreach (array_unique($m[1]) as $call) {
        if (!isset($stub[$call])) {
            $bad[] = $call;
        }
    }
    expect($bad)->toBe([], 'src/App.php 调用了 stub 里不存在的函数：' . implode(',', $bad));
});

/**
 * Element 能发出的每个 kind 都必须在 shim 的 kindFromString 表里在册。
 *
 * 这条门是"静默兜底"的直接解药：兜底分支 `return K::Div` 让「最常见的 kind」和
 * 「拼错的名字 / 上游新增没登记的名字」长得一模一样，四层都不报错。
 * 把兜底改成拒收后，这条静态对比第一次跑就抓出 **`div` 根本不在表里**
 * （实测像素回归 tree1 gdfrac=0.056、selftest 直接卡死），补进表后全绿。
 *
 * 三个伪 kind 豁免：webview / inspector / notifications 由 bridge 自己处理，
 * 不走 gpui_shim_push（走的话 shotdiff 会立刻花屏，不是静默）。
 */
test('every kind Element.php can emit is registered in the shim kindFromString table', function (): void {
    $shim = (string) file_get_contents(__DIR__ . '/../cpp-src/gpui_shim.cpp');
    // 用字节位（strpos）而不是字符位（mb_strpos）：下面 substr 按字节切，
    // 混用会让窗口偏移到表中间，一半的 kind 被当成"没登记"。
    // 词法表现在在 kindFromString 外面（shim_test 要用它做 ComponentKind 漂移警报），
    // 所以窗口从表头截到表的右花括号——框到 12000 字节会把它后面的代码也扫进来，
    // 那边随便一个 `{"名字", ...}` 都能被当成"这个 kind 在册"，门就虚了。
    $head = 'kKindNames[] = {';
    $table = strpos($shim, $head);
    if ($table !== false) {
        $from = $table + (int) strlen($head);
        $to = strpos($shim, '};', $from);
        expect($to)->not->toBeFalse('kKindNames 表没有右花括号：解析器无从截断');
        $body = substr($shim, $from, $to - $from);
    } else {
        // 老形状：表内联在函数体里，写成 `if (s == "x") return K::Y;`。
        $start = strpos($shim, 'kindFromString(const char* k');
        expect($start)->not->toBeFalse('找不到 kKindNames/kindFromString，对比无从做起');
        $body = substr($shim, (int) $start, 12000);
    }
    // 两种写法都认：表项 `{"x", K::Y},` 与老的 `if (s == "x") return K::Y;`。
    // 解析不放宽的话，改了表形状这条门会直接报"读不到任何 kind 名"——那正是它该做的。
    preg_match_all('/\{\s*"([a-z0-9_]+)"\s*,\s*K::/', $body, $m2);
    $known = $m2[1];
    if ($known === []) {
        preg_match_all('/if \(s == "([a-z0-9_]+)"\)/', $body, $m);
        $known = $m[1];
    }
    expect($known)->not->toBe([], '没从 shim 里读到任何 kind 名：解析器退化了');

    $php = (string) file_get_contents(__DIR__ . '/../src/Element.php');
    preg_match_all("/\\\$e->kind = '([a-z0-9_]+)'/", $php, $m2);
    $emitted = array_values(array_unique($m2[1]));
    expect($emitted)->not->toBe([]);

    $pseudo = ['webview', 'inspector', 'notifications'];
    $unknown = array_values(array_diff($emitted, $known, $pseudo));
    expect($unknown)->toBe([], '这些 kind 会被 gpui_shim_push 拒收（表里没有）：' . implode(',', $unknown));

    // div 必须在册——它过去只靠兜底存在，是整条链最脆的一环。
    expect(in_array('div', $known, true))->toBeTrue('div 不在 kindFromString 表里');
});

/**
 * 我们发出的每个"上游按名字查表"的符号，都必须真的在上游表里在册。
 *
 * 这条门盯的是三层都不看的那一格：带参且非行为名的 op 会被
 * `materialize.cpp:451-454` 那条链尾没有 else 的行为链**静默丢掉** ——
 * 编译过、phpstan 过、pest 过、selftest 过、shotdiff 也过（基线是自录的）。
 * 取数与判定都在 cmd/check-upstream-parity.php 里（同一份实现供 CI 用）。
 */
test('every name we emit exists in the upstream vocabulary', function (): void {
    require_once __DIR__ . '/../cmd/check-upstream-parity.php';
    $root = dirname(__DIR__);
    $tree = rtrim((getenv('GPUI_DIR') !== false && getenv('GPUI_DIR') !== '')
        ? (string) getenv('GPUI_DIR') : $root . '/upstream/gpui-kit-cpp', '/');
    [$problems] = upstreamParityProblems($root, $tree);
    expect($problems)->toBe([], "这些名字上游查不到，运行期不会有任何提示：\n  "
        . implode("\n  ", $problems));
});
