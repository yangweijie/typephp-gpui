<?php

declare(strict_types=1);

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;

/*
 * 示例集的覆盖率门禁（wrap-green，纯 PHP + 反射，CI 的 php job 跑）。
 *
 * "展示了所有特性"里的"所有"不能靠形容词。这里从 src/Element.php 与 src/App.php 反射出
 * 每一个公开成员，逐个断言它在**任一示例**源码里被**调用过**（不是被提到过——注释先剥掉），
 * 再断言每个枚举常量的每个取值都真被传过（字面量或 foreach 整表遍历）。缺一个就红。
 *
 * 于是示例每加一个组件、框架每长一个 API，这个测试都会立刻知道谁掉队了；
 * 想跳过必须在下面的白名单里写理由，白名单本身就是债务的账本。
 *
 * 这一支原来叫 tests/ShowcaseTest.php：它借画廊的壳做四件额外的事（节表自洽、状态表自洽、
 * 每节建得出非空树、handler 不许是没接线的孤儿）。画廊按功能域拆完删掉后，那四件事没有主人了，
 * 而它们量的都是**全域**性质，不该跟着一个目录一起消失——于是：
 *   · 三条静态门（工厂 / 链式方法 / 枚举取值 / arity）改成扫整个 examples/，与画廊无关；
 *   · "每节建得出非空树 + 孤儿 handler"搬进 cmd/headless-trees.php，逐支示例各起一个进程跑
 *     （nano 的入口约定让每支 main.php 都定义 main()/step()，一起 require 就是 redeclare）；
 *   · 节表自洽改成"每支示例都有 project.macos.yml，且它列的源都在、每份共享功能源都被收录"。
 */

/** 参与静态扫描的示例源：examples/ 下所有 PHP，构建产物目录除外。 */
function exampleSources(): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__ . '/../examples', FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        $path = (string) $f;
        if (!str_ends_with($path, '.php') || str_contains($path, '/build/')) {
            continue;
        }
        $out[] = $path;
    }
    sort($out);
    return $out;
}

/**
 * 不要求示例出现的公开成员 → 理由。
 * 空数组是默认期望：新增 API 时先想清楚它是不是"用户能用的特性"，是就写进展示。
 */
const EXAMPLE_EXEMPT = [
    // Element：PHP→bridge 的序列化出口与原始 op 逃生舱，不是给用户用的组件特性。
    'toArray' => 'Element→数组的序列化出口，App::render 内部消费',
    'hasCallbacks' => 'bridge 侧取回调表的内部查询',
    'rm' => '原始 op 删除器（P2-4 抽象泄漏收口），示例应只走链式方法',
    'cb' => '原始回调 op 出口，用户 API 是 onClick/onChange/onHover/onKeyDown',
    // App：事件投交与测试注入点，产品代码不会手写。
    'dispatch' => '原生事件回灌入口，只有 FakeBridge/原生泵会调',
    'fireHotkeyForTest' => '快捷键的测试注入点，不是产品能力',
];

/** 全部示例源码去掉注释与空白后的纯代码文本。 */
function exampleCode(): string
{
    $out = '';
    foreach (exampleSources() as $file) {
        $code = (string) file_get_contents($file);
        foreach (token_get_all($code) as $tok) {
            if (is_array($tok)) {
                if (in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $tok[1];
            } else {
                $out .= $tok;
            }
            $out .= ' ';
        }
    }

    return $out;
}

/** 反射出某个类的公开成员名，按静态/实例分开。 */
function publicMembers(string $class): array
{
    $static = [];
    $instance = [];
    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
        $name = $m->getName();
        if (isset(EXAMPLE_EXEMPT[$name])) {
            continue;
        }
        if ($m->isStatic()) {
            $static[] = $name;
        } else {
            $instance[] = $name;
        }
    }

    return ['static' => $static, 'instance' => $instance];
}

/** 在纯代码文本里找一次真调用：`X::name(` 或 `->name(`。 */
function isCalled(string $code, string $name): bool
{
    // exampleCode() 在每个 token 后补了空格，所以操作符与名字之间必须容许空白。
    return (bool) preg_match('/(::|->)\s*' . preg_quote($name, '/') . '\s*\(/', $code);
}

/** 某个枚举取值是否作为字符串字面量出现在代码里（即真被当参数传过）。 */
function isUsedAsLiteral(string $code, string $value): bool
{
    return (bool) preg_match('/[\'"]' . preg_quote($value, '/') . '[\'"]/', $code);
}

/**
 * 枚举常量是否被整体遍历：`foreach (X::CONST as …)` 让每个取值都被传一遍，
 * 这比手写 8 个字符串字面量更能算"用过"——所以按常量名豁免它的取值清单。
 * 只认 foreach：`X::FPS_ANCHORS[$i]` 那种下标取样不算覆盖全部取值。
 */
function isIteratedConst(string $code, string $constName): bool
{
    return (bool) preg_match(
        '/foreach\s*\(\s*(?:El|Element|App)\s*::\s*' . preg_quote($constName, '/') . '\s+(?:as\b|\[)/',
        $code
    );
}

test('示例集覆盖 Element 的全部公开工厂', function (): void {
    $code = exampleCode();
    $missing = [];
    foreach (publicMembers(El::class)['static'] as $name) {
        if (!isCalled($code, $name)) {
            $missing[] = 'El::' . $name . '()';
        }
    }
    expect($missing)->toBe([], '这些 Element 工厂没在任何示例源码里被调用过');
});

test('示例集覆盖 Element 的全部链式方法', function (): void {
    $code = exampleCode();
    $missing = [];
    foreach (publicMembers(El::class)['instance'] as $name) {
        if (!isCalled($code, $name)) {
            $missing[] = '->' . $name . '()';
        }
    }
    expect($missing)->toBe([], '这些 Element 链式方法没在任何示例源码里被调用过');
});

test('示例集覆盖 App 的全部公开 API', function (): void {
    $code = exampleCode();
    $missing = [];
    $members = publicMembers(App::class);
    foreach (array_merge($members['static'], $members['instance']) as $name) {
        if (!isCalled($code, $name)) {
            $missing[] = $name . '()';
        }
    }
    expect($missing)->toBe([], '这些 App 能力没在任何示例源码里被调用过');
});

test('示例集用过每个枚举常量的每个取值', function (): void {
    $code = exampleCode();
    $missing = [];
    foreach ([El::class, App::class] as $class) {
        $ref = new ReflectionClass($class);
        foreach ($ref->getConstants() as $key => $value) {
            $label = $ref->getShortName() . '::' . $key;
            if (isIteratedConst($code, $key)) {
                continue;
            }
            // 关联表（ALERT_VARIANTS、HOTKEY_MODS）的取值是原生注册名/位掩码，
            // 示例该传的是**键**，所以断言键出现即可。
            $needles = array_is_list($value) ? $value : array_keys($value);
            foreach ($needles as $needle) {
                if (is_string($needle) && !isUsedAsLiteral($code, $needle)) {
                    $missing[] = $label . ' → ' . $needle;
                }
            }
        }
    }
    expect($missing)->toBe([], '这些枚举取值既没作为实参字面量出现，也没被 foreach 整体遍历');
});

/**
 * 用 php-parser（TypePHP 自带的依赖）扫示例源码，收集每个方法名被调用时的实参个数。
 *
 * 这里原来是一把手写的 token 扫描器：它把链式调用吃漏，159 个方法名只认出 12 个，
 * 于是 arity 门禁**静默不报**——正好是最坏的那种假绿。既然解析器就在 vendor 里，
 * 就用真 AST，不给手搓词法第二次骗人的机会。
 *
 * @return array<string, array<int, bool>> name => [arity => true]
 */
function exampleCallArities(): array
{
    $parser = (new PhpParser\ParserFactory())->createForNewestSupportedVersion();
    $visitor = new class () extends PhpParser\NodeVisitorAbstract {
        /** @var array<string, array<int, bool>> */
        public array $seen = [];

        public function enterNode(PhpParser\Node $node): ?int
        {
            if ($node instanceof PhpParser\Node\Expr\MethodCall
                || $node instanceof PhpParser\Node\Expr\NullsafeMethodCall) {
                if ($node->name instanceof PhpParser\Node\Identifier) {
                    $this->seen[$node->name->toString()][count($node->getArgs())] = true;
                }
            } elseif ($node instanceof PhpParser\Node\Expr\StaticCall
                && $node->name instanceof PhpParser\Node\Identifier) {
                $this->seen[$node->name->toString()][count($node->getArgs())] = true;
            }
            return null;
        }
    };

    $out = [];
    foreach (exampleSources() as $src) {
        $traverser = new PhpParser\NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($parser->parse((string) file_get_contents($src)));
    }
    foreach ($visitor->seen as $name => $arities) {
        foreach (array_keys($arities) as $a) {
            $out[$name][(int) $a] = true;
        }
    }
    return $out;
}

test('示例集里每个 Element/App 调用的实参个数都在签名容许范围内', function (): void {
    // PHP 容忍"传多了"的实参（只在 nano 里抛 ArgumentCountError），所以无头 php -l
    // 与 FakeBridge 都是绿的、原生构建才炸。arity 只能静态核。
    // 名字可能同时存在于 Element 与 App（如 text/checked），所以只有当**所有**同名
    // 方法都不接受这个 arity 时才算错——否则是扫描器按名字匹配的假红。
    $observed = exampleCallArities();
    $ranges = [];
    foreach ([El::class, App::class] as $class) {
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            if (isset(EXAMPLE_EXEMPT[$m->getName()])) {
                continue;
            }
            $max = $m->isVariadic() ? PHP_INT_MAX : $m->getNumberOfParameters();
            $ranges[$m->getName()][] = [$m->getNumberOfRequiredParameters(), $max];
        }
    }

    $bad = [];
    foreach ($observed as $name => $arities) {
        if (!isset($ranges[$name])) {
            continue;
        }
        foreach (array_keys($arities) as $arity) {
            $ok = false;
            foreach ($ranges[$name] as [$min, $max]) {
                if ($arity >= $min && $arity <= $max) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                $span = array_map(static fn (array $r): string => $r[0] . '..' . $r[1], $ranges[$name]);
                $bad[] = $name . '() 传了 ' . $arity . ' 个实参，签名容许 ' . implode(' 或 ', $span);
            }
        }
    }
    expect($bad)->toBe([], '这些调用的实参个数在原生产物里会抛 ArgumentCountError');
});

/** 读一份示例 yml 的 sources: 列表，解析成绝对路径（realpath 归一，`../_shared` 才比得上）。 */
function ymlSources(string $yml): array
{
    $dir = dirname($yml);
    $out = [];
    foreach (explode("\n", (string) file_get_contents($yml)) as $line) {
        if (!preg_match('/^\s+-\s+(\S+\.php)\s*$/', $line, $m)) {
            continue;
        }
        $abs = realpath($dir . '/' . $m[1]);
        $out[] = $abs === false ? $dir . '/' . $m[1] : $abs;
    }
    return $out;
}

/** 有 project.macos.yml 的示例目录（_shared 是共享源，不是示例）。 */
function exampleDirs(): array
{
    $root = (string) realpath(__DIR__ . '/../examples');
    return array_values(array_filter(
        glob($root . '/*', GLOB_ONLYDIR) ?: [],
        static fn (string $d): bool => basename($d) !== '_shared' && is_file($d . '/project.macos.yml')
    ));
}

test('每支示例的编译清单自洽：yml 在、列出的源都在', function (): void {
    $root = (string) realpath(__DIR__ . '/../examples');
    $missingYml = [];
    foreach (array_filter(glob($root . '/*', GLOB_ONLYDIR) ?: [],
        static fn (string $d): bool => basename($d) !== '_shared') as $dir) {
        if (!is_file($dir . '/project.macos.yml')) {
            $missingYml[] = basename($dir);
        }
    }
    expect($missingYml)->toBe([], '这些示例目录没有 project.macos.yml，bin/gpui build 找不到它');

    $dangling = [];
    foreach (exampleDirs() as $dir) {
        foreach (ymlSources($dir . '/project.macos.yml') as $src) {
            if (!is_file($src)) {
                $dangling[] = basename($dir) . ' → ' . $src;
            }
        }
    }
    expect($dangling)->toBe([], '这些 yml 列出的源文件不存在（产物会缺函数）');
});

test('共享功能源不许从任何示例的编译清单里悄悄消失', function (): void {
    // 拆分自己引入的那条漂移门：一域的 builder 搬进 examples/_shared/features/ 之后，
    // 只要忘记在示例的 yml 里补这一行，**无头 PHP 是绿的**（测试 require 了 features 目录），
    // 只有跑原生构建才在产物里炸。所以静态核"每份共享源至少被一支示例收录"。
    $root = (string) realpath(__DIR__ . '/../examples');
    $listed = [];
    foreach (exampleDirs() as $dir) {
        foreach (ymlSources($dir . '/project.macos.yml') as $src) {
            $listed[$src] = true;
        }
    }
    $notListed = [];
    foreach (glob($root . '/_shared/features/*.php') ?: [] as $feature) {
        if (!isset($listed[(string) realpath($feature)])) {
            $notListed[] = basename($feature);
        }
    }
    expect($notListed)->toBe([], '这些功能域源没被任何示例的 sources 收录，写了却进不了产物');
});

test('每支示例的每一棵树在无头 FakeBridge 下都建得出非空树，且没有孤儿 handler', function (): void {
    // 这一层证明的是 PHP 侧建树与接线不炸（wrap-green）；原生侧的"没有错误节点"由每支示例
    // --selftest 的 registeredError()/componentFailure 断言负责。它跑在 Linux CI 上，
    // 所以示例改坏了不用等一次 90 秒的原生构建才知道。
    // 逐支单起进程：nano 的入口约定让每支 main.php 都定义 main()/step()，一起 require 是 redeclare。
    $script = (string) realpath(__DIR__ . '/../cmd/headless-trees.php');
    expect(is_file($script))->toBeTrue('cmd/headless-trees.php 不在，这条门自己失效了');
    $bad = [];
    $counts = [];
    foreach (exampleDirs() as $dir) {
        $ex = basename($dir);
        $out = [];
        $rc = 0;
        exec('php ' . escapeshellarg($script) . ' ' . escapeshellarg($ex) . ' 2>&1', $out, $rc);
        $line = implode("\n", $out);
        $counts[$ex] = $rc === 0 ? (string) ($out[0] ?? '') : $line;
        if ($rc !== 0) {
            $bad[] = $ex . "\n" . $line;
        }
    }
    expect($bad)->toBe([], "这些示例的无头建树或接线有问题：\n" . implode("\n", $bad));
    // 树数不是判据，但"全都 0 棵"说明这条门在空转——hello 一支没有 *Trees() 表，
    // 所以门槛是"至少有一支示例交出非零树数"，而不是逐支都非零。
    $withTrees = array_filter(
        $counts,
        static fn (string $l): bool => (bool) preg_match('/trees=[1-9]/', $l)
    );
    expect(count($withTrees))->toBeGreaterThan(0, '没有一支示例建出过任何树，这条门在空转');

    // 对照臂：凭空挂一颗"任何树里都没有"的 handler，孤儿检测必须点名它。
    // 没有这一条，"0 孤儿"这个绿可能只是因为门根本没在看。
    $probe = [];
    $probeRc = 0;
    exec('php ' . escapeshellarg($script) . ' text --self-check 2>&1', $probe, $probeRc);
    expect($probeRc)->toBe(0, "孤儿检测的对照臂没通过：\n" . implode("\n", $probe));
    expect(implode("\n", $probe))->toContain('凭空挂的孤儿被点名=true');
});

/*
 * 示例 CLI 的开关清单自洽（2026-10-22，来自一条真缺陷）。
 *
 * `--automation` 曾经只在 input 的 runInteractive() 里读 global $argv，而那道"未知开关必须
 * 拒绝"的扫描排在它前面——于是 `input --automation <dir>` 单独用被自己的扫描拒掉（rc=2），
 * 只有搭 `--trace` 才进得去（真二进制实测）。修法是把取值提到扫描之前。
 *
 * 这条门锁的是**踩坑的形状**而不是那颗开关：提示文本是这份 CLI 唯一对外的说明书，
 * 说明书和实际读取的集合一分叉，"读了但没声明"的开关就成了隐形开关——要么被自己的扫描吞掉，
 * 要么只有作者知道怎么用。所以双向锁：
 *   ① 每个被读取的 `--开关` 必须在未知开关提示里在册；
 *   ② 提示里在册的每颗开关必须真被读取（防说明书上画不存在的按钮）。
 * 它量的是声明一致性，**不**量可达性：某颗开关读在扫描之后但仍可达（--synthetic-key 就是）
 * 这类判断只有跑真二进制才量得到，两边别互相替代。
 */
test('带未知开关扫描的示例：读取的开关与提示里声明的开关必须双向相等', function (): void {
    $readRe = '/\b(?:in_array|array_search)\s*\(\s*\'(--[a-z][a-z0-9-]*)\'/';
    $namedRe = '/(--[a-z][a-z0-9-]*)/';
    $guardedFiles = [];
    $undeclared = [];
    $phantom = [];
    $emptyScan = [];
    foreach (exampleSources() as $file) {
        $code = '';
        foreach (token_get_all((string) file_get_contents($file)) as $tok) {
            if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= (is_array($tok) ? (string) $tok[1] : (string) $tok) . ' ';
        }
        if (!str_contains($code, '未知开关')) {
            continue;
        }
        $guardedFiles[] = basename($file);
        $read = [];
        if (preg_match_all($readRe, $code, $m)) {
            $read = array_unique($m[1]);
        }
        // 提示区 = '未知开关' 那句到紧随其后的 exit(...) 之间的文本。
        // 注意下面两条正则都留了 \s*：token 流里每个 token 之间被塞了一个空格，
        // 按源码原样写 `in_array(` / `exit(` 会一颗都匹配不上（第一次就这么红的）。
        $named = [];
        if (preg_match('/未知开关(.*?)exit\s*\(/s', $code, $g) && preg_match_all($namedRe, $g[1], $n)) {
            $named = array_unique($n[1]);
        }
        if ($read === [] || $named === []) {
            $emptyScan[] = basename($file) . ' 读取=' . count($read) . ' 声明=' . count($named);
            continue;
        }
        foreach ($read as $flag) {
            if (!in_array($flag, $named, true)) {
                $undeclared[] = basename($file) . ' 读了但没在提示里声明：' . $flag;
            }
        }
        foreach ($named as $flag) {
            if (!in_array($flag, $read, true)) {
                $phantom[] = basename($file) . ' 提示里声明了但没人读：' . $flag;
            }
        }
    }
    // 防空转：这道门至少要有"一支带扫描的示例"和"两边都非空"才有判定力。
    expect(count($guardedFiles))->toBeGreaterThanOrEqual(
        1,
        '没有任何示例带未知开关扫描——这条门在空转，而扫描本身是防"未知开关落回交互 run() 挂死"的'
    );
    expect($emptyScan)->toBe([], '这些带扫描的示例没读到或没声明任何开关：' . implode(' / ', $emptyScan));
    expect($undeclared)->toBe([], implode("\n", $undeclared));
    expect($phantom)->toBe([], implode("\n", $phantom));
});
