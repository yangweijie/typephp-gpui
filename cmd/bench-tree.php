<?php

declare(strict_types=1);

/**
 * cmd/bench-tree.php — 每帧 PHP 侧成本测量 + 预算棘轮（Phase 19）。
 *
 * 测的是什么：gpui 是 frame-rebuilt 模型，**每个脏帧**都要重跑一次 view builder 再把树
 * `toArray()` 交给 C 侧。这个脚本量的就是那一跳的 PHP 半边成本，规模取"表格行数"，
 * 每行 3 个元素 + 根节点（nodes = 3 × rows + 1）。
 *
 * 为什么不连 C 侧一起量：emitTree/taffy/渲染要真实窗口与 GPU，本地与 CI 不可能给出
 * 同一个数，那样的"门"只会变成两边互相不信任的噪音。PHP 半边是纯函数、可复现，
 * 而它正是本项目**独有**的那一段（C 侧是上游的，上游自己看）。
 *
 * 用法：
 *   php cmd/bench-tree.php                        # 只测并打印
 *   php cmd/bench-tree.php --check <预算文件>      # 与预算比对，超了 rc=1
 *
 * 预算文件每行 `节点数 毫秒数`（# 开头是注释）；`us-per-node` 是单独一条全规模上限。
 * 标定规则与预算的"守什么"写在 cmd/bench-tree-budgets.txt 的注释里。
 */

use TypePHP\Gpui\Element as El;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// 20k 行那一档峰值 168MB：不提 memory_limit 的话量到的是 fatal error 而不是时间。
// 这是测量装置的必要条件，不是对被测量对象的宽容——被测代码本身没被改。
if (ini_get('memory_limit') !== false) {
    @ini_set('memory_limit', '1G');
}

/** @var list<int> 行数档位；nodes = 3 × rows + 1 */
const SIZES = [1000, 2000, 5000, 10000, 20000];

/**
 * 每档重复次数。门看的是 **min**（微基准的标准口径：被噪声污染的是别的样本，不是最小值），
 * 中位数与 max 只打印作诊断。实测依据（2026-10-20 同机 A/B）：同一档 med 48ms 的序列里
 * 会出现一次 max 94ms 的停顿——那種样本会把中位数门在"机器恰好忙"的日子里绊倒，
 * 而 min 在同一台机器的快/慢状态间只差 10~20%。
 */
const REPS = 5;

function benchTree(int $n): El
{
    $rows = [];
    for ($i = 0; $i < $n; $i++) {
        $rows[] = El::hFlex([
            El::text("row {$i}")->font(13.0)->semibold(),
            El::text('secondary')->font(12.0)->bg('#333'),
        ])->gap(8.0)->p(4.0)->rounded(6.0);
    }
    return El::vFlex($rows)->gap(2.0)->sizeFull();
}

/** @return array{rows:int,nodes:int,ms:float,min:float,max:float,us_per_node:float,peak_mb:float} ms/min 均为毫秒 */
function benchOne(int $rows): array
{
    $samples = [];
    $peakBefore = memory_get_peak_usage(true);
    for ($i = 0; $i < REPS; $i++) {
        $t0 = hrtime(true);
        $arr = benchTree($rows)->toArray();
        $t1 = hrtime(true);
        $samples[] = ($t1 - $t0) / 1e6;
        // 别让上一轮的树留在堆里影响下一档的内存读数。
        unset($arr);
        gc_collect_cycles();
    }
    sort($samples);
    $nodes = $rows * 3 + 1;

    return [
        'rows' => $rows,
        'nodes' => $nodes,
        'ms' => $samples[0],
        'min' => $samples[0],
        'max' => $samples[REPS - 1],
        'us_per_node' => $samples[0] * 1000 / $nodes,
        'peak_mb' => (memory_get_peak_usage(true) - $peakBefore) / 1048576,
    ];
}

/**
 * @return array<int, float> nodes => ms；外加 'us-per-node' => float
 */
function benchLoadBudgets(string $path): array
{
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $parts = preg_split('/\s+/', $line) ?: [];
        if (count($parts) >= 2 && preg_match('/^\d+(\.\d+)?$/', $parts[1]) === 1) {
            $out[$parts[0]] = (float) $parts[1];
        }
    }
    return $out;
}

// 逃生阀：这台机器正忙着（跑构建、跑别的测试）时，bench 的红不说明代码坏了。
// 参考项目同此设计（NATIVE_SDK_SKIP_BENCH_CHECK）——只在"有人记得时才跑的棘轮"才会
// 变成阻碍，所以跳过必须是显式动作而不是默认。
if (getenv('GPUI_SKIP_BENCH_TREE') !== false && getenv('GPUI_SKIP_BENCH_TREE') !== ''
    && getenv('GPUI_SKIP_BENCH_TREE') !== '0') {
    echo "GPUI_SKIP_BENCH_TREE 已设置，跳过帧预算棘轮\n";
    exit(0);
}

$check = false;
$budgetFile = null;
if (in_array('--check', $argv, true)) {
    $check = true;
    $i = array_search('--check', $argv, true);
    $budgetFile = $argv[$i + 1] ?? null;
    if ($budgetFile === null || !is_file($budgetFile)) {
        fwrite(STDERR, "用法：php cmd/bench-tree.php --check <预算文件>\n");
        exit(2);
    }
}

$budgets = $check ? benchLoadBudgets((string) $budgetFile) : [];
$results = [];
foreach (SIZES as $rows) {
    $results[] = benchOne($rows);
}

printf("%-8s %-8s %-10s %-9s %-9s %-12s %-10s\n",
    'rows', 'nodes', 'min_ms(门)', 'med_ms', 'max_ms', 'us/node', 'peak_mb');
$worstUsPerNode = 0.0;
foreach ($results as $r) {
    printf("%-8d %-8d %-10.2f %-9.2f %-9.2f %-12.2f %-10.1f\n",
        $r['rows'], $r['nodes'], $r['ms'], $r['min'], $r['max'],
        $r['us_per_node'], $r['peak_mb']);
    $worstUsPerNode = max($worstUsPerNode, $r['us_per_node']);
}

if (!$check) {
    // 无头时把 60Hz 一帧的账算给用户看：16.67ms 只是 PHP 半边，C 侧与渲染还没算。
    foreach ($results as $r) {
        if ($r['ms'] > 16.67) {
            printf("\n注意：%d 行（%d 节点）PHP 半边就 %.2fms，已超 16.67ms/帧的一半以上\n",
                $r['rows'], $r['nodes'], $r['ms']);
            break;
        }
    }
    exit(0);
}

$fails = [];
printf("\n预算比对（%s）：\n", basename((string) $budgetFile));
foreach ($results as $r) {
    $budget = $budgets[(string) $r['nodes']] ?? null;
    if ($budget === null) {
        printf("  %-8d 节点  无预算（跳过）\n", $r['nodes']);
        continue;
    }
    $ok = $r['ms'] <= $budget;
    printf("  %-8d 节点  %8.2fms / %6.0fms  %s\n",
        $r['nodes'], $r['ms'], $budget, $ok ? 'OK' : 'OVER');
    if (!$ok) {
        $fails[] = "nodes={$r['nodes']} measured={$r['ms']}ms budget={$budget}ms";
    }
}
$usBudget = $budgets['us-per-node'] ?? null;
if ($usBudget !== null) {
    $ok = $worstUsPerNode <= $usBudget;
    printf("  us/node 最差 %8.2fus / %6.2fus  %s\n", $worstUsPerNode, $usBudget, $ok ? 'OK' : 'OVER');
    if (!$ok) {
        $fails[] = "us-per-node measured={$worstUsPerNode} budget={$usBudget}";
    }
}

if ($fails !== []) {
    fwrite(STDERR, "\nBENCH-TREE OVER BUDGET:\n  " . implode("\n  ", $fails) . "\n");
    fwrite(STDERR, "若这是特性带来的真实成本：连测 5 次后按同样规则重标定预算，"
        . "并在同一次提交里写清为什么（见 cmd/bench-tree-budgets.txt 头部）。\n");
    exit(1);
}
echo "\nBENCH-TREE OK\n";
