#!/usr/bin/env php
<?php

/**
 * cmd/check-click-survival.php — 广度门：树上**每一颗**可点控件都点一遍，
 * 断言没有一个把宿主带走（#111/#110 那一类）。
 *
 * 为什么单独立一条：具名 selftest 门（accordionRealClick / linkRealClick /
 * checkboxRealClick…）各守一颗控件，它们全绿只说明"写过关的那几颗活着"。
 * #111 的根因是"上游把 content 实体裸转成 ScriptView 后读 ->runtime"
 * （Entity<T>::Get 是无类型校验的裸转，gpui/gpui.h:6830），命中范围是
 * component_shell 整个家族——2026-11-03 实测同一棵树里 18 颗可点控件
 * **8 颗 SIGSEGV**，其中没人写过关的占多数。这条门量的是这一族。
 *
 * 三档读数（不合并，也不拿"没判"冒充"判过"）：
 *   · 判红：会话 rc != 0（139=SIGSEGV、134=SIGABRT），或超时且 CPU 在烧
 *     （"点一下把泵转死"是实现缺陷），或出现这两条致命签名——
 *       "belongs to a superseded render"（查表拿 Box2* 当 ShellRuntime*，表是假的，
 *        所以"回调过期"这个诊断本身误导）、
 *       "window state is missing"（#110：ShellRoot 窗口没有 component 层插件）。
 *   · 未判定：会话超时但 CPU≈0 —— 进程是 block 在 OS 模态会话里。实测树 3 的
 *     NativeMenu 触发器：`sample` 的栈是 NativeMenu::Show → PlatShowMenu →
 *     -[NSMenu popUpMenuPositioningItem:] → -[NSMenuTrackingSession
 *     startRunningMenuEventLoop:] → _BlockUntilNextEventMatchingListInMode，
 *     等的是"没人来关这个菜单"，不是产品缺陷。该行被点名的同时**不计入通过数**。
 *   · 计数不判红：其余 `shell:` 告警（例如 Div/HFlex/VFlex zero-ops 那条诊断）。
 *     这是本门自己的边界，不是漏判。
 *   · "没有可点行"分两种，判据不同：命中表**一行都没有**＝探测没量到东西（未判定）；
 *     有行但全 click=0＝**量出来的空集**（树 4 本就是纯文本滚动盒），按通过计——
 *     前提是本轮至少一棵树量到 >0 颗（单树跑或全树皆 0 ⇒ 未判定，别拿空转冒充判定）。
 *   · "点了没反应"不归本门：那要先有 PHP 侧处理器才有读数，由具名门负责。
 *
 * 坐标全部从当轮 hits.txt 现算，不硬编码（写死坐标=量一个已经不在那里的矩形）。
 * 只按"宽度≥8"筛、**不看高度**：h=0 的行照样吃得到点击（修前崩的正是那批 h=0 行）。
 *
 * 用法：php cmd/check-click-survival.php [--tree <1..4>] [--wait <秒>]
 *   不带参数 = 四棵全扫（每颗一个会话，数分钟）；--tree 只扫一棵（红臂复测用）；
 *   --wait 改单会话上限（默认 90s，含基线会话）。
 * 退出码：0 全部判绿 / 1 有红 / 2 未判定（有挂住行、命中表一行都没有、全树零可点、或前置缺失）。
 */

$root = dirname(__DIR__);
$bin = $root . '/examples/kit/build/kit';
$windowH = 5000;         // automation 窗高：把整棵树都铺进布局
$trees = [1, 2, 3, 4];
$deadline = 90;
const BUSY_PCT = 20.0;    // 超时采样里达到这个 %CPU 就算"在烧"，判红

// fail-closed 的参数面：旗、值、缺值、未知旗都要判。
$args = array_slice($argv, 1);
for ($i = 0; $i < count($args); $i++) {
    $flag = $args[$i];
    if ($flag !== '--tree' && $flag !== '--wait') {
        echo "CLICK-SURVIVAL 未知开关：{$flag}（本门只收 --tree <1..4> 与 --wait <秒>）⇒ 未判定\n";
        exit(2);
    }
    $val = $args[$i + 1] ?? '';
    if ($val === '' || !ctype_digit($val)) {
        echo "CLICK-SURVIVAL {$flag} 缺值或不是非负整数：'" . $val . "' ⇒ 未判定\n";
        exit(2);
    }
    $i++;
    if ($flag === '--tree') {
        if ((int) $val < 1 || (int) $val > 4) {
            echo "CLICK-SURVIVAL --tree 要 1..4 的树号，收到 '" . $val . "' ⇒ 未判定\n";
            exit(2);
        }
        $trees = [(int) $val];
    } else {
        if ((int) $val < 5) {
            echo "CLICK-SURVIVAL --wait 太短（<5s）会自己制造挂住读数，收到 '" . $val . "' ⇒ 未判定\n";
            exit(2);
        }
        $deadline = (int) $val;
    }
}

if (!is_executable($bin)) {
    echo "CLICK-SURVIVAL 前置产物缺：{$bin}（先 php bin/gpui build kit）⇒ 未判定\n";
    exit(2);
}

/** 取一次进程 %CPU（ps 的瞬时值）。读不到记 -1，让"读不到"与"读到底数"分得开。 */
function psCpu(int $pid): float
{
    $out = @shell_exec('ps -o %cpu= -p ' . escapeshellarg((string) $pid));
    if ($out === null) {
        return -1.0;
    }
    $v = trim($out);
    return is_numeric($v) ? (float) $v : -1.0;
}

/**
 * 跑一个 automation 会话（命令依次写成 command-<n>.txt）。
 *
 * macOS 没有 timeout，所以自己管：proc_open + 非阻塞管道 + deadline，超时 SIGKILL。
 * 超时那档先按 CPU 分箱，见文件头三档读数——烧（判红）与 block 在 OS 模态会话（未判定）
 * 是两种不同的事实，不许合成一个。
 */
function autoSession(string $bin, int $tree, int $windowH, array $cmds, int $deadlineSec): array
{
    $dir = sys_get_temp_dir() . '/gpui-click-' . getmypid() . '-' . uniqid('', true);
    mkdir($dir, 0700, true);
    foreach ($cmds as $i => $line) {
        file_put_contents($dir . '/command-' . ($i + 1) . '.txt', $line . "\n");
    }
    $cmd = 'exec ' . escapeshellarg($bin) . ' --automation ' . escapeshellarg($dir)
        . ' ' . $tree . ' ' . $windowH . ' 2>&1';
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        @rmdir($dir);
        return ['rc' => 'spawn-failed', 'out' => '', 'rows' => [], 'cpu' => [], 'hung' => false];
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $buf = '';
    $cpu = [];
    $deadlineAt = microtime(true) + $deadlineSec;
    $nextSample = microtime(true) + 30.0;
    $hung = false;
    $rc = 'unknown';
    // pid 只在这里取一次。循环体里**不能再调 proc_get_status()**：PHP 自己会 waitpid(WNOHANG)
    // 把尸收取，之后 pcntl_waitpid 只剩 ECHILD，活进程会被读成"永远不退出"⇒ 假挂住
    // （2026-11-03 首跑就是把树 1 的 5 颗全读成超时，CPU 采样-1%）。
    $pid = (int) proc_get_status($proc)['pid'];
    $canWait = function_exists('pcntl_waitpid');
    $reapedByMe = false;
    while (true) {
        if ($canWait) {
            $st = 0;
            $w = pcntl_waitpid($pid, $st, WNOHANG);
            if ($w === $pid) {
                // 自己收尸才分得清"退出码 1"和"被信号打死"：本机 proc_close() 对
                // 信号死亡一律回 -1（实测 SIGSEGV/SIGABRT/正常退出全是 -1）。
                $rc = pcntl_wifsignaled($st) ? 128 + pcntl_wtermsig($st) : pcntl_wexitstatus($st);
                $reapedByMe = true;
                break;
            }
            if ($w === -1) {
                // ECHILD：尸已被别人（PHP 内部）收走，信号信息丢了。退到"只分 0/非 0"，
                // 并在读数里说明这条退路，不假装还能判出 139。
                $rc = 'reaped-elsewhere';
                break;
            }
        }
        $buf .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        if (microtime(true) >= $deadlineAt) {
            $hung = true;
            $rc = 'hung';
            proc_terminate($proc, SIGKILL);
            break;
        }
        if (microtime(true) >= $nextSample) {
            $cpu[] = psCpu($pid);
            $nextSample += 15.0;
        }
        if (!$canWait && !proc_get_status($proc)['running']) {
            break;
        }
        usleep(50000);
    }
    $buf .= (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closeRc = proc_close($proc);
    if ($rc === 'unknown') {
        // 无 pcntl 的退路：本机 proc_get_status() **没有 exitCode 键**（实测
        // "Undefined array key \"exitCode\"" ⇒ 读出来是 NULL），只认 proc_close()。
        $rc = $closeRc;
    }
    $hitsFile = $dir . '/hits.txt';
    $rows = is_file($hitsFile) ? file($hitsFile, FILE_IGNORE_NEW_LINES) : [];
    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
    return ['rc' => $rc, 'out' => $buf, 'rows' => $rows, 'cpu' => $cpu, 'hung' => $hung];
}

/** hits.txt → 可点行的中心坐标（i id x y w h click down drag，tab 分隔）。 */
function clickableRows(array $hits): array
{
    $rows = [];
    foreach ($hits as $line) {
        $f = explode("\t", $line);
        if (count($f) < 9 || $f[6] !== '1') {
            continue;
        }
        $x = (float) $f[2];
        $y = (float) $f[3];
        $w = (float) $f[4];
        $h = (float) $f[5];
        if ($w < 8) {
            continue;
        }
        $rows[] = ['id' => $f[1], 'x' => (int) round($x + $w / 2),
            'y' => (int) round($y + $h / 2), 'w' => (int) $w, 'h' => (int) $h];
    }
    return $rows;
}

$HARD = ['belongs to a superseded render', 'window state is missing'];

$dead = [];
$blocked = [];
$signatures = [];
$advisory = 0;
$scanned = 0;
$emptyTrees = [];

$vacuousTrees = [];
foreach ($trees as $tree) {
    $base = autoSession($bin, $tree, $windowH, ['hits', 'quit'], $deadline);
    $rows = clickableRows($base['rows']);
    if ($rows === []) {
        // 两件事共用"没有可点行"这个读数，但判据不同，不许合成一档：
        // ① 命中表**一行都没有**＝探测这轮根本没量到东西（hits 没落盘/物化全坏）⇒ 未判定；
        // ② 有行但全 click=0＝**量出来的空集**——该树内容本无可点控件（树 4 是两个
        //    纯文本滚动盒）。它按通过计的前提是本轮**别的树量到了 >0 颗**（同一次运行
        //    已证明 click 标记与坐标换算链路是通的），单树跑给不了这个前提 ⇒ 汇总处另判。
        if ($base['rows'] === []) {
            $emptyTrees[] = $tree;
            echo "  tree{$tree} 命中表一行都没有（会话 rc=" . var_export($base['rc'], true) . '）⇒ 该树未判定' . "\n";
            echo '  输出尾部：' . substr($base['out'], -200) . "\n";
        } else {
            $vacuousTrees[] = $tree;
            echo '  tree' . $tree . ' 可点 0 颗（命中表 ' . count($base['rows'])
                . ' 行全 click=0——该树内容本无可点控件，空集按通过计，前提见汇总行）' . "\n";
        }
        continue;
    }
    echo '  tree' . $tree . ' 基线可点 ' . count($rows) . " 颗\n";
    foreach ($rows as $r) {
        $scanned++;
        $s = autoSession($bin, $tree, $windowH,
            ['clickat ' . $r['x'] . ' ' . $r['y'], 'quit'], $deadline);
        foreach ($HARD as $sig) {
            if (str_contains($s['out'], $sig)) {
                $signatures[] = "tree{$tree} id={$r['id']} @({$r['x']},{$r['y']}) 签名『{$sig}』";
            }
        }
        $where = "tree{$tree} id={$r['id']} @({$r['x']},{$r['y']}) {$r['w']}x{$r['h']}";
        if ($s['hung']) {
            $burning = false;
            foreach ($s['cpu'] as $c) {
                if ($c >= BUSY_PCT) {
                    $burning = true;
                }
            }
            if ($burning) {
                $dead[] = $where . ' 挂住且在烧 CPU（采样 ' . implode('/', $s['cpu'])
                    . '%）⇒ 点一下把泵转死';
            } else {
                $blocked[] = $where . ' 超时未返回，采样 CPU=' . implode('/', $s['cpu'])
                    . '%（≈0 ⇒ block 在 OS 模态会话，例如 AppKit 菜单跟踪）';
            }
        } elseif ($s['rc'] !== 0) {
            $dead[] = $where . ' rc=' . var_export($s['rc'], true) . ' 尾部: '
                . str_replace("\n", ' / ', substr($s['out'], -180));
        }
        $advisory += substr_count($s['out'], 'shell: ');
    }
}

foreach ($dead as $d) {
    echo "  ✗ 宿主没活下来：" . $d . "\n";
}
foreach ($signatures as $s) {
    echo "  ✗ 致命签名：" . $s . "\n";
}
foreach ($blocked as $b) {
    echo "  ? 未判定（模态等待）：" . $b . "\n";
}

$verdictCount = $scanned - count($blocked);
if ($dead !== [] || $signatures !== []) {
    echo 'CLICK-SURVIVAL FAILED（判 ' . $verdictCount . '/' . $scanned . ' 颗：'
        . count($dead) . ' 颗没活下来、' . count($signatures) . ' 条致命签名；'
        . '模态等待 ' . count($blocked) . ' 颗另计；advisory shell: 告警 '
        . $advisory . " 条不判红）\n";
    exit(1);
}
if ($blocked !== [] || $emptyTrees !== []) {
    echo 'CLICK-SURVIVAL 未判定（' . count($blocked) . ' 颗模态等待没拿到判定'
        . ($emptyTrees !== [] ? '，树 ' . implode(',', $emptyTrees) . ' 命中表一行都没有' : '')
        . '，共扫 ' . $scanned . " 颗——别当通过）\n";
    exit(2);
}
if ($scanned === 0) {
    // 全树零可点：空集通过的前提（本轮至少一棵树量到 >0 颗）没成立——
    // 可能是内容真没可点控件，也可能是 click 标记整条断了，两种分不开 ⇒ 未判定。
    echo 'CLICK-SURVIVAL 未判定（扫到的树全部可点 0 颗，本轮没有一棵树证明提取链路通——'
        . '别当通过；树 ' . implode(',', $vacuousTrees) . " 是量出来的空集）\n";
    exit(2);
}
echo 'CLICK-SURVIVAL OK（判 ' . $verdictCount . '/' . $scanned . ' 颗可点控件全活着、'
    . '无致命签名；advisory shell: 告警 ' . $advisory . ' 条不判红'
    . ($vacuousTrees !== [] ? '；树 ' . implode(',', $vacuousTrees) . ' 可点 0 颗（量出的空集，同轮他树已证链路通）' : '') . "）\n";
exit(0);
