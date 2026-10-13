<?php

declare(strict_types=1);

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;
use TypePHP\Gpui\FakeBridge;

// 只 require 示例源码的**声明**部分：它们顶层只有函数定义，不调用任何 gpui_* 原生函数，
// 所以在无头 PHP 里加载是安全的，能直接反射节表与状态表。
//
// 为什么不 require 全部示例的 main.php：nano 的入口约定是每支示例都定义一个
// `main(int $argc, array $argv)`，一起加载就是 redeclare fatal。它们只参与下面的
// 文本/AST 扫描（覆盖率与 arity 两条门），不需要被执行。
require_once __DIR__ . '/../examples/_shared/state.php';
foreach (glob(__DIR__ . '/../examples/_shared/features/*.php') ?: [] as $feature) {
    require_once $feature;
}
require_once __DIR__ . '/../examples/showcase/src/sections.php';
require_once __DIR__ . '/../examples/showcase/src/main.php';

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
 * 扫描范围从"showcase 两份文件"改成"examples 下全部 PHP"，是因为 showcase 正在按功能域拆成
 * 独立示例（task_plan.md Phase 15）：域源搬到 examples/_shared/features/ 之后，
 * 若还只扫 showcase 目录，搬家本身就会把这条门弄红——它认的是"整个示例集"，不是某一个目录。
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
const SHOWCASE_EXEMPT = [
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
function showcaseCode(): string
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
        if (isset(SHOWCASE_EXEMPT[$name])) {
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
    // showcaseCode() 在每个 token 后补了空格，所以操作符与名字之间必须容许空白。
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
    $code = showcaseCode();
    $missing = [];
    foreach (publicMembers(El::class)['static'] as $name) {
        if (!isCalled($code, $name)) {
            $missing[] = 'El::' . $name . '()';
        }
    }
    expect($missing)->toBe([], '这些 Element 工厂没在任何示例源码里被调用过');
});

test('示例集覆盖 Element 的全部链式方法', function (): void {
    $code = showcaseCode();
    $missing = [];
    foreach (publicMembers(El::class)['instance'] as $name) {
        if (!isCalled($code, $name)) {
            $missing[] = '->' . $name . '()';
        }
    }
    expect($missing)->toBe([], '这些 Element 链式方法没在任何示例源码里被调用过');
});

test('示例集覆盖 App 的全部公开 API', function (): void {
    $code = showcaseCode();
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
    $code = showcaseCode();
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

test('showcase 的节表自洽：树号连续、构造器可调用', function (): void {
    $sections = showcaseSections();
    $trees = array_keys($sections);
    expect($trees)->toBe(range(1, count($sections)), '节号必须是 1..N 且连续——shotdiff 按它编号基线');
    foreach ($sections as $tree => [$title, $builder]) {
        expect($title)->not->toBeEmpty("第 {$tree} 节缺标题");
        expect($builder)->toBeCallable();
    }
});

test('showcase 状态表自洽：boot 出的表 = 默认值 + section', function (): void {
    $defaults = exampleState();
    ExampleState::boot();
    $booted = ExampleState::get();
    // boot() 走的是无引用路径：处理器写它、每帧 view 读它（nano 下引用式状态会 abort）。
    expect(array_key_exists('section', $booted))->toBeTrue('状态表必须带当前节号')
        ->and(array_diff(array_keys($defaults), array_keys($booted)))->toBe([], 'boot() 必须涵盖默认值的每个键');
    ExampleState::boot(['section' => 7]);
    expect(ExampleState::get()['section'])->toBe(7);
    foreach (showcaseSections() as $tree => [$title, $fn]) {
        expect($fn)->toBeCallable();
    }
});

/**
 * 收集一棵渲染树里出现过的**所有字符串**。
 * 事件路由键不止在节点的 id 上：retained input 的 id 在 ctorArgs、菜单项/下拉项的 id 是
 * registered_method 的实参、sidebar 的 id 也在参数里。只收 id 字段会漏，收全部字符串才对齐
 * bridge 实际会注册的键集。
 */
function treeStrings(array $node): array
{
    $ids = [];
    $stack = [$node];
    while ($stack !== []) {
        $n = array_pop($stack);
        foreach ($n as $v) {
            if (is_array($v)) {
                $stack[] = $v;
            } elseif (is_string($v) && $v !== '') {
                $seen[$v] = true;
            }
        }
    }
    return array_keys($seen);
}

test('showcase 的每一节在无头 FakeBridge 下都建得出非空树', function (): void {
    // 这一层证明的是 PHP 侧建树与接线不炸（wrap-green）；原生侧的"没有错误节点"由
    // showcase --selftest 的 registeredError() 断言负责。它跑在 Linux CI 上，
    // 所以示例改坏了不用等一次 90 秒的原生构建才知道。
    ExampleState::boot();
    $app = new App();
    $app->create();
    $window = $app->createWindow('T', 800, 600);
    wireShowcase($app);

    $empty = [];
    foreach (showcaseSections() as $tree => [$title, $fn]) {
        $node = $fn(ExampleState::get())->toArray();
        $app->render($fn(ExampleState::get()));
        if ($node['children'] === [] && treeIds($node) === []) {
            $empty[] = $tree;
        }
    }
    expect($empty)->toBe([], '这些节渲染出来是空树');
    expect(FakeBridge::$renderLog[(int) $window])->toHaveCount(count(showcaseSections()));
});

test('showcase 注册到元素的 handler，id 必须真出现在某一节的树里', function (): void {
    ExampleState::boot();
    $app = new App();
    $app->create();
    $app->createWindow('T', 800, 600);
    wireShowcase($app);

    $seen = [];
    foreach (showcaseSections() as $tree => [, $fn]) {
        ExampleState::$v['section'] = $tree;
        foreach (treeStrings($fn(ExampleState::get())->toArray()) as $id) {
            $seen[$id] = true;
        }
        foreach (treeStrings(showcaseShell()->toArray()) as $id) {
            $seen[$id] = true;
        }
    }

    $handlers = (new ReflectionProperty(App::class, 'handlers'))->getValue($app);
    // 只有这几种事件绑在元素上；timer/http/dirChange/tray/hotkey/close 的 id 是
    // 各自通道的键，本来就不是元素 id，按元素查会假红。
    $elementTypes = ['click', 'change', 'hover', 'key_down'];
    // 通知卡的 id 由 notify() 的 $id 实参带过去，不在元素树里（ov-note 就是这种）。
    $offTreeIds = ['ov-note'];
    $orphans = [];
    foreach ((array) $handlers as $id => $byType) {
        if ($id === '*') {
            continue;
        }
        foreach (array_keys((array) $byType) as $type) {
            if (in_array($type, $elementTypes, true)
                && !in_array((string) $id, $offTreeIds, true)
                && !isset($seen[(string) $id])) {
                $orphans[] = $id . ':' . $type;
            }
        }
    }
    expect($orphans)->toBe([], '这些 handler 注册的 id 在任何一节里都渲染不出来（写了没人接的线）');
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
function showcaseCallArities(): array
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
    $observed = showcaseCallArities();
    $ranges = [];
    foreach ([El::class, App::class] as $class) {
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            if (isset(SHOWCASE_EXEMPT[$m->getName()])) {
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

/**
 * 拆分（task_plan.md Phase 15）自己引入的那条漂移门。
 *
 * 把一域的 builder 从 sections.php 搬进 examples/_shared/features/ 之后，只要忘记在画廊的
 * yml 里补这一行，`showcaseSections()` 仍然点得到那个函数名——**无头 PHP 是绿的**（测试
 * require 了 features 目录），只有跑 `bin/gpui build showcase` 才在产物里炸。
 * 所以这里静态核三件事：每支示例都有 yml、画廊每一节的 builder 定义在画廊 yml 列出的某份源里、
 * _shared/features 下每份源都被画廊收录（域可以拆出去单独测，但不许从画廊里悄悄消失）。
 */
test('拆出去的域不会从画廊的编译清单里悄悄消失', function (): void {
    $examplesDir = (string) realpath(__DIR__ . '/../examples');
    $missingYml = [];
    foreach (array_filter(array_merge(glob($examplesDir . '/*', GLOB_ONLYDIR) ?: []),
        static fn (string $d): bool => !str_ends_with($d, '/_shared')) as $dir) {
        if (!is_file($dir . '/project.macos.yml')) {
            $missingYml[] = basename($dir);
        }
    }
    expect($missingYml)->toBe([], '这些示例目录没有 project.macos.yml，bin/gpui build 找不到它');

    $gallery = $examplesDir . '/showcase/project.macos.yml';
    $sources = array_values(array_filter(ymlSources($gallery), 'is_file'));
    expect($sources)->not->toBeEmpty('画廊 yml 的 sources 一个都读不到，这条门自己失效了');

    // 每节 builder 的定义在哪份源里
    $definedIn = [];
    foreach ($sources as $src) {
        if (preg_match_all('/^function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m',
            (string) file_get_contents($src), $mm)) {
            foreach ($mm[1] as $fn) {
                $definedIn[$fn] = true;
            }
        }
    }
    $dangling = [];
    foreach (showcaseSections() as $tree => [, $builder]) {
        if (!isset($definedIn[$builder])) {
            $dangling[] = $tree . ':' . $builder;
        }
    }
    expect($dangling)->toBe([], '这些节的 builder 没在画廊 yml 列出的任何一份源里定义（产物会缺函数）');

    $notListed = [];
    foreach (glob($examplesDir . '/_shared/features/*.php') ?: [] as $feature) {
        if (!in_array($feature, $sources, true)) {
            $notListed[] = basename($feature);
        }
    }
    expect($notListed)->toBe([], '这些功能域源没被画廊的 sources 收录，画廊里就看不到这一域');
});
