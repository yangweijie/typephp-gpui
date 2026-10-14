<?php
declare(strict_types=1);

/**
 * 漂移警报：我们发出的每一个"上游按名字查表"的符号，都必须真的在上游表里在册。
 *
 * 为什么需要这条门：上游对不认的名字分两种下场，而**其中一种是彻底静默**——
 *   runtime.cpp:3700-3703 记账时决定 op 种类：未知零参名 → NullaryStyle →
 *   materialize.cpp:2576 会写错误（响）；未知**带参**名 → Method →
 *   materialize.cpp:451-454 的行为链链尾**没有 else**（静默丢，没有任何通道）。
 * 所以"发了但没生效"和"没发"现象一样，编译期、phpstan、pest、selftest、shotdiff
 * 五层都不报（像素基线还是自录的）。这条门是这第三条哑路上唯一的哨兵。
 *
 * 样式名不在本门范围内：它们走的是"响"的那条路（invalid style call 会落错误），
 * 把它混进来只会稀释红信号。
 *
 * 用法：php cmd/check-upstream-parity.php [--tree=<upstream 树>] [--info]
 * 退出码 0 = 全在册；1 = 有名字上游不认（逐个点名）。
 */

require_once __DIR__ . '/upstream-vocab.php';

/**
 * @return list<string> 问题清单（空 = 通过）
 * @return array{0: list<string>, 1: list<string>} [problems, info]
 */
function upstreamParityProblems(string $repo, string $tree): array
{
    $v = UpstreamVocab::load($tree);
    if ($v['kinds'] === [] || $v['ops'] === [] || $v['catalog'] === [] || $v['roles'] === []) {
        return [['抽表失败：上游树里没有 spec.h/materialize.cpp/a11y.cpp/component_shell（路径：' . $tree . '）'], []];
    }

    $problems = [];
    $info = [];

    /** camelCase/PascalCase → snake_case（kind 名就是枚举值的 snake 形）。 */
    $snake = static fn (string $s): string => strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $s));

    // ── 1. kind 表：shim 登记的名字必须是上游枚举值的 snake 形 ──────────────
    $shim = (string) file_get_contents($repo . '/cpp-src/gpui_shim.cpp');
    $head = strpos($shim, 'kKindNames[] = {');
    if ($head === false) {
        return [['cpp-src/gpui_shim.cpp 里找不到 kKindNames 表'], []];
    }
    $tail = strpos($shim, '};', $head);
    $table = substr($shim, $head, (int) $tail - $head);
    preg_match_all('/\{\s*"([a-z0-9_]+)"\s*,\s*K::([A-Za-z0-9]+)\s*\}/', $table, $km, PREG_SET_ORDER);
    $kindNames = [];
    $badKind = [];
    foreach ($km as $row) {
        $kindNames[] = $row[1];
        if ($row[1] !== $snake($row[2])) { $badKind[] = $row[1] . '(K::' . $row[2] . ')'; }
        if (!in_array($row[2], $v['kinds'], true)) { $badKind[] = $row[1] . '→K::' . $row[2] . '(枚举里没有)'; }
    }
    if ($badKind !== []) {
        $problems[] = 'kKindNames 里有名字对不上上游 ComponentKind：' . implode(', ', $badKind);
    }
    $info[] = sprintf('kind：%d 个登记名全部对应上游 %d 个枚举值', count($kindNames), count($v['kinds']));

    // 未被任何 kind 名覆盖的枚举值（上游加了新值时这里会涨）
    $covered = array_map(static fn (string $k): string => $k, array_map(
        static fn (array $row): string => $row[2], $km));
    $unnamed = array_values(array_diff($v['kinds'], $covered));
    $info[] = '上游枚举里 PHP 表达不了的值（' . count($unnamed) . '）：' . implode(', ', $unnamed);

    // ── 2. op 名：PHP 糖 + bridge 里硬编码的名字，都必须在上游行为链里 ────────
    // 我们自己截走、从不交给上游的名字在这里白名单，并写明截走的行。
    $internalOps = [
        // gpui_bridge.cc 的 retained 输入建状态时读这两个 method op 的实参，
        // 它们从不下游到 materialize（placeholder 不是上游 op 名）。
        'placeholder',
    ];
    $src = (string) file_get_contents($repo . '/src/Element.php');
    $bridge = (string) file_get_contents($repo . '/cpp-src/gpui_bridge.cc');

    $methodOps = [];
    if (preg_match_all("/\\\$this->method\(\s*'([a-z0-9_]+)'/", $src, $m)) { $methodOps = $m[1]; }
    if (preg_match_all('/gpui_shim_method\(\s*\w+\s*,\s*\w+\s*,\s*"([a-z_0-9]+)"/', $bridge, $m2)) {
        $methodOps = array_merge($methodOps, $m2[1]);
    }
    $badOps = array_values(array_unique(array_diff($methodOps, $v['ops'], $internalOps)));
    if ($badOps !== []) {
        $problems[] = '这些 method op 名上游 materialize 不认（带参未知名会被**静默丢弃**）：'
            . implode(', ', $badOps) . ' —— 改名字，或改成 catalog 的 registered_method';
    }
    $info[] = 'method op：' . count(array_unique($methodOps)) . ' 个名字在册（含 bridge 硬编码的）';

    $cbOps = [];
    if (preg_match_all("/\\\$this->callback\(\s*'([a-z0-9_]+)'/", $src, $m)) { $cbOps = $m[1]; }
    $badCb = array_values(array_unique(array_diff($cbOps, $v['ops'])));
    if ($badCb !== []) {
        $problems[] = '这些回调 op 名上游不认：' . implode(', ', $badCb);
    }

    $slotOps = [];
    if (preg_match_all("/\\\$this->slot\(\s*'([a-z0-9_]+)'/", $src, $m)) { $slotOps = $m[1]; }
    if (preg_match_all("/self::reg\([^;]*\)\s*->\s*slot\(\s*'([a-z0-9_]+)'/", $src, $m3)) { $slotOps = array_merge($slotOps, $m3[1]); }
    $badSlot = array_values(array_unique(array_diff($slotOps, $v['slots'])));
    if ($badSlot !== []) {
        $problems[] = '这些槽名不在上游 prelude 的槽表里：' . implode(', ', $badSlot)
            . '（可用槽名：' . implode(', ', $v['slots']) . '）';
    }

    // ── 2b. 角色名：Element::ROLES 必须与上游 kRoleNames **双向**相等 ─────────
    // 单向只查"我们多写的"会留一个洞：谁把这张表删瘦了（少 40 个名字）只表现为
    // "上游有而我们没有"，那是能力缺口而不是错，门就永远绿着让糖覆盖不到新角色。
    if (!preg_match('/public const ROLES = \[(.*?)\];/s', $src, $rm2)) {
        $problems[] = 'src/Element.php 里找不到 public const ROLES 表';
    } else {
        preg_match_all("/'([a-z0-9_]+)'/", $rm2[1], $rn);
        $phpRoles = $rn[1];
        $extraRoles = array_values(array_diff($phpRoles, $v['roles']));
        $missRoles = array_values(array_diff($v['roles'], $phpRoles));
        if (count(array_unique($phpRoles)) !== count($phpRoles)) {
            $problems[] = 'Element::ROLES 里有重复名';
        }
        if ($extraRoles !== []) {
            $problems[] = '这些角色名上游 kRoleNames 里没有，role() 会放行但 materialize 静默不加：'
                . implode(', ', $extraRoles);
        }
        if ($missRoles !== []) {
            $problems[] = '上游有 ' . count($missRoles) . ' 个角色名没进 Element::ROLES（白名单落后于上游）：'
                . implode(', ', $missRoles) . ' —— 跑 `php cmd/upstream-vocab.php --dump-roles` 重新对齐';
        }
        if ($extraRoles === [] && $missRoles === []) {
            $info[] = '角色名：Element::ROLES 与上游 kRoleNames 双向相等（' . count($phpRoles) . ' 个）';
        }
    }

    // ── 2c. 样式名：style()/paramStyle() 发的名字要在 style.cpp 的表里在册 ──────
    // 这一族原本被排除在门外，理由是"未知零参名会响"（materialize.cpp:2576 写错误）。
    // 但响只在真跑起来时响：拼错的名字要等某块像素不对才被看见，而带参名走的是
    // ApplyParamStyle 的 false 分支。静态核一遍不花钱，顺带把"哪 7 个关键字动到被
    // 追踪字段"这类事实固定到取数端（animated 表是 transition() 的唯一可用面）。
    $sk = $v['styleKeywords'];
    if ($sk['all'] === [] || $sk['families'] === []) {
        $problems[] = '抽样式表失败：style.cpp 里没有 kKeywords/kFamilies（路径：' . $tree . '）';
    } elseif ($sk['missing'] !== []) {
        $problems[] = '取数端漏了 ' . count($sk['missing']) . ' 条样式关键字（fields 没解析出来）：'
            . implode(', ', $sk['missing']);
    } else {
        $nullaryNames = [];
        $paramNames = [];
        if (preg_match_all("/\\\$this->style\(\s*'([a-z0-9_]+)'/", $src, $ms)) {
            $nullaryNames = $ms[1];
        }
        if (preg_match_all("/\\\$this->paramStyle\(\s*'([a-z0-9_]+)'/", $src, $mp)) {
            $paramNames = $mp[1];
        }
        // 零参样式：关键字表，或族表（ramp 名如 p_4 由 ParseFamily 现拼，也算认得）。
        $badNullary = array_values(array_unique(array_diff(
            $nullaryNames, $sk['all'], $sk['families'], $sk['otherParams'])));
        sort($badNullary);
        if ($badNullary !== []) {
            $problems[] = '这些零参样式名 style.cpp 不认（会在 materialize 写错误）：'
                . implode(', ', $badNullary);
        }
        // 带参样式：族表 + kOtherParamNames。
        $badParam = array_values(array_unique(array_diff(
            $paramNames, $sk['families'], $sk['otherParams'], $sk['all'])));
        sort($badParam);
        if ($badParam !== []) {
            $problems[] = '这些带参样式名上游既不在族表也不在 other-param 表里（ApplyParamStyle 直接 return false）：'
                . implode(', ', $badParam);
        }
        $info[] = sprintf('样式：%d 个零参名 + %d 个带参名在册（上游 %d 关键字 / %d 族 / %d other-param，其中 %d 个关键字动到被追踪字段）',
            count(array_unique($nullaryNames)), count(array_unique($paramNames)),
            count($sk['all']), count($sk['families']), count($sk['otherParams']),
            count($sk['animated']));
        $info[] = '动到被追踪字段的关键字（transition/spring 才有得动画）：'
            . implode(', ', $sk['animated']);
    }

    // ── 3. catalog：导出名与方法名都要在 descriptor 表里 ────────────────────
    $exports = [];
    $methods = [];
    $compOfExport = [];
    foreach ($v['catalog'] as $comp => $rec) {
        foreach ($rec['ctors'] as $e => $a) { $exports[] = $e; $compOfExport[$e] = $comp; }
        foreach ($rec['methods'] as $mn => $a) { $methods[$mn] = true; }
    }
    $usedExports = [];
    if (preg_match_all('/self::reg\(/', $src, $rm, PREG_OFFSET_CAPTURE)) {
        foreach ($rm[0] as $hit) {
            $seg = substr($src, $hit[1] + strlen($hit[0]), 140);
            $semi = strpos($seg, ';');
            if ($semi !== false) { $seg = substr($seg, 0, $semi); }
            if (preg_match_all("/'([A-Z][A-Za-z0-9]*)'/", $seg, $mm)) {
                foreach ($mm[1] as $n) { $usedExports[$n] = true; }
            }
        }
    }
    if (preg_match_all("/=>\s*'([A-Z][A-Za-z0-9]*)'/", $src, $m4)) {
        foreach ($m4[1] as $n) { $usedExports[$n] = true; }
    }
    // 通用工厂 El::catalog('Name', …) 与示例里的直接用法也算"我们发出的名字"。
    foreach (glob($repo . '/examples/*/src/*.php') ?: [] as $f) {
        if (preg_match_all("/El::catalog\(\s*'([A-Z][A-Za-z0-9]*)'/", (string) file_get_contents($f), $m5)) {
            foreach ($m5[1] as $n) { $usedExports[$n] = true; }
        }
    }
    // state 实参标记（#81）：`El::state('InputState', …)` 里的名字来自**另一张表**
    // （StateDescriptor 的 kind，不是组件导出名）。两张表混着判会两头错：合法名字被
    // 报成"上游查不到"，而真正拼错的 kind 因为长得像导出名放过去。所以各查各的。
    // 只扫 src——`examples/input` 的反向对照故意写了一个不存在的 kind。
    $usedStateKinds = [];
    if (preg_match_all("/state\(\s*'([A-Z][A-Za-z0-9]*)'/", $src, $mState)) {
        foreach ($mState[1] as $n) { $usedStateKinds[$n] = true; }
    }
    $validStates = $v['stateKinds'] ?? [];
    // 从导出名里豁免的，只有"确实写在 state( 位置上、且上游真有这个 kind"的那几个——
    // 否则一个拼错的导出名撞上合法 kind 名就悄悄过了。
    $excused = array_intersect(array_keys($usedStateKinds), $validStates);
    $badExports = array_values(array_diff(array_keys($usedExports), $exports, $excused));
    sort($badExports);
    if ($badExports !== []) {
        $problems[] = '这些 catalog 导出名上游 descriptor 表里没有（会 build 不出来 → 降级成 Div）：'
            . implode(', ', $badExports);
    }
    $badStates = array_values(array_diff(array_keys($usedStateKinds), $validStates));
    sort($badStates);
    if ($badStates !== []) {
        $problems[] = '这些 state kind 上游没有对应的 SchemaEntity（gpui_shim_state 查不到描述符，'
            . 'bridge 会下发 NIL 实参 → 那颗组件渲染成错误节点）：' . implode(', ', $badStates);
    }
    $info[] = sprintf('state kind：%d 个上游在册（SchemaEntity 声明），PHP 侧用到 %d 个：%s',
        count($validStates), count($usedStateKinds),
        implode(', ', array_keys($usedStateKinds)) ?: '（无）');
    $rmNames = [];
    // 名字来源有两处：直接 ->rm('x')，以及 catalog 糖里的 catalogMethod('x', …)。
    // 只扫前者会在"糖改成统一分发器"之后静默失去覆盖——那正是这条门要防的那类事。
    $rmPatterns = ["/->rm\(\s*'([a-z0-9_]+)'/", "/catalogMethod\(\s*'([a-z0-9_]+)'/"];
    foreach ([$repo . '/src/Element.php', $repo . '/src/App.php'] as $f) {
        $t = (string) file_get_contents($f);
        foreach ($rmPatterns as $re) {
            if (preg_match_all($re, $t, $m6)) { $rmNames = array_merge($rmNames, $m6[1]); }
        }
    }
    foreach (glob($repo . '/examples/*/src/*.php') ?: [] as $f) {
        $t = (string) file_get_contents($f);
        foreach ($rmPatterns as $re) {
            if (preg_match_all($re, $t, $m7)) { $rmNames = array_merge($rmNames, $m7[1]); }
        }
    }
    $badMethods = array_values(array_unique(array_diff($rmNames, array_keys($methods))));
    sort($badMethods);
    if ($badMethods !== []) {
        $problems[] = '这些 registered_method 名没有任何 descriptor 声明过：' . implode(', ', $badMethods);
    }
    $info[] = sprintf('catalog：我们用到 %d 个导出名 / %d 个方法名，上游共 %d / %d',
        count($usedExports), count(array_unique($rmNames)), count($exports), count($methods));

    // 归属矩阵只报信息：糖打在"没有这个方法"的组件上会静默丢，那是用法问题，
    // 名字本身在册，不该让门红。
    $orphan = [];
    foreach (array_unique($rmNames) as $mn) {
        $owners = [];
        foreach ($v['catalog'] as $comp => $rec) {
            if (isset($rec['methods'][$mn])) { $owners[] = $comp; }
        }
        $usable = array_intersect(array_keys($compOfExport), array_keys($usedExports));
        $hitOn = 0;
        foreach ($usable as $e) {
            $c = $compOfExport[$e] ?? null;
            if ($c !== null && isset($v['catalog'][$c]['methods'][$mn])) { $hitOn++; }
        }
        if ($hitOn === 0) { $orphan[] = $mn . '(' . implode('|', $owners) . ')'; }
    }
    if ($orphan !== []) {
        $info[] = '这些 registered_method 只存在于我们没用到的组件上（打错组件即静默丢）：' . implode(', ', $orphan);
    }

    return [$problems, $info];
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $repo = dirname(__DIR__);
    $tree = getenv('GPUI_DIR') ?: $repo . '/upstream/gpui-kit-cpp';
    $showInfo = in_array('--info', $argv, true);
    foreach ($argv as $a) {
        if (str_starts_with($a, '--tree=')) { $tree = rtrim(substr($a, 7), '/'); }
    }
    [$problems, $info] = upstreamParityProblems($repo, rtrim($tree, '/'));
    if ($showInfo) {
        foreach ($info as $line) { echo '  · ' . $line . "\n"; }
    }
    if ($problems === []) {
        printf("UPSTREAM PARITY OK（%s）\n", $tree);
        exit(0);
    }
    foreach ($problems as $p) { fwrite(STDERR, "  ✗ " . $p . "\n"); }
    fwrite(STDERR, "共 " . count($problems) . " 条：这些名字上游查不到，运行期不会有任何提示。\n");
    exit(1);
}
