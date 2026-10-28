<?php
declare(strict_types=1);

/**
 * 从 vendored 的上游树里抽出三张权威词法表：
 *   kinds   —— ComponentKind 枚举值名（spec.h）
 *   ops     —— materialize.cpp 行为链认的 op 名（StrEq(op.name, StrL("x"))）
 *   catalog —— component_shell descriptor：导出名 → 构造实参，组件 → 方法名 → 实参
 *
 * 这是 `tests/ContractTest.php` 那条"我们发出的名字必须在上游在册"的门的取数端，
 * 也是 cmd/check-upstream-parity.php 的唯一数据源。只读上游树。
 *
 * 抽表器本身要防的坑（都踩过）：
 *  - descriptor 有两种形状：`ComponentDescriptor kX = {…}` 与家族表
 *    `ComponentDescriptor kX[] = { {…}, {…} }`。只认前者会静默少算 28 个组件
 *    （Carousel / Calendar / Questionnaire / TimeField / ColorPicker / Empty 整批）。
 *  - 枚举值行首有缩进、行尾有逗号，还夹着注释行。
 */

final class UpstreamVocab
{
    /** @return array{kinds: list<string>, ops: list<string>, slots: list<string>, stateStyles: list<string>, stateKinds: list<string>, roles: list<string>, styleKeywords: array{all: list<string>, animated: list<string>, missing: list<string>, families: list<string>, otherParams: list<string>}, catalog: array<string,array{ctors:array<string,list<array{0:?string,1:string,2:list<string>>}>,methods:array<string,list<array{0:?string,1:string,2:list<string>>}>,file:string}>} */
    public static function load(string $treeDir): array
    {
        [$slots, $states] = self::slotTables($treeDir . '/src/shell/runtime.cpp');
        return [
            'kinds' => self::kinds($treeDir . '/src/shell/spec.h'),
            'ops' => self::ops($treeDir . '/src/shell/materialize.cpp'),
            'slots' => $slots,
            'stateStyles' => $states,
            'stateKinds' => self::stateKinds($treeDir . '/src/component_shell'),
            'roles' => self::roles($treeDir . '/src/shell/a11y.cpp'),
            'styleKeywords' => self::styleKeywords($treeDir . '/src/shell/style.cpp'),
            'catalog' => self::catalog($treeDir . '/src/component_shell'),
        ];
    }

    /**
     * 保留态实体的 kind 名（#81 的 `El::state(...)` 吃的那个名字）。
     *
     * 权威源取 `SchemaEntity("X")`：那是**组件构造真正会去要的** kind，注册表在 build
     * 时保证它与某条 StateDescriptor 的 kind 对得上。不去解析 `StateDescriptor` 聚合
     * 初始化（`{"InputState", "InputState", kInputStateArgs, …}`）——那个形状里第一个
     * 是导出名、第二个才是 kind，靠位置解析容易被注释里的逗号带偏，而我们要挡的恰恰是
     * "PHP 侧写了一个上游没人要的名字"。
     *
     * @return list<string>
     */
    public static function stateKinds(string $dir): array
    {
        $out = [];
        if (!is_dir($dir)) { return $out; }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || substr($f->getFilename(), -4) !== '.cpp') { continue; }
            if (preg_match_all('/SchemaEntity\(\s*"([^"]+)"/', (string) file_get_contents($f->getPathname()), $m)) {
                foreach ($m[1] as $name) { $out[$name] = true; }
            }
        }
        $names = array_keys($out);
        sort($names);
        return $names;
    }

    /** 从 $text 的 $open（'{' 的下标）取出配对花括号内的内容。 */
    private static function braceBody(string $text, int $open): ?string
    {
        $depth = 0;
        $len = strlen($text);
        for ($i = $open; $i < $len; $i++) {
            $c = $text[$i];
            // 字符串字面量先吞（字符串里的 `//`、`'` 都不算注释/引号）。
            if ($c === '"' || $c === "'") {
                $q = $c;
                $i++;
                while ($i < $len && $text[$i] !== $q) {
                    if ($text[$i] === '\\') { $i++; }
                    $i++;
                }
                continue;
            }
            // C++ 注释里的引号（如 popover.cpp 的 `runtime's`）不是字符串字面量——
            // 注释先跳过，否则注释里的 `'` 会被当成字符串起点，把后面的花括号
            // 全吞掉（popover 的整张 kMethods 表因此取不到，overlay_closable 判成
            // "上游没有声明"，parity 门假红）。
            if ($c === '/' && $i + 1 < $len && $text[$i + 1] === '/') {
                $i = strpos($text, "\n", $i);
                if ($i === false) { break; }
                continue;
            }
            if ($c === '/' && $i + 1 < $len && $text[$i + 1] === '*') {
                $end = strpos($text, '*/', $i + 2);
                if ($end === false) { break; }
                $i = $end + 1;
                continue;
            }
            if ($c === '{') { $depth++; } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) { return substr($text, $open + 1, $i - $open - 1); }
            }
        }
        return null;
    }

    /** 按顶层逗号切分（嵌套花括号与字符串里的逗号不算）。 */
    private static function splitEntries(string $body): array
    {
        $out = [];
        $cur = '';
        $depth = 0;
        $len = strlen($body);
        for ($i = 0; $i < $len; $i++) {
            $c = $body[$i];
            if ($c === '"' || $c === "'") {
                $q = $c;
                $cur .= $c;
                $i++;
                while ($i < $len && $body[$i] !== $q) {
                    if ($body[$i] === '\\') { $cur .= $body[$i]; }
                    $cur .= $body[$i];
                    $i++;
                }
                $cur .= $c;
                continue;
            }
            // 注释同样跳过（注释里的引号/逗号都不算数据——同 braceBody 的注释处理）。
            if ($c === '/' && $i + 1 < $len && $body[$i + 1] === '/') {
                $nl = strpos($body, "\n", $i);
                if ($nl === false) { break; }
                $i = $nl;
                continue;
            }
            if ($c === '/' && $i + 1 < $len && $body[$i + 1] === '*') {
                $end = strpos($body, '*/', $i + 2);
                if ($end === false) { break; }
                $i = $end + 1;
                continue;
            }
            if ($c === '{') { $depth++; } elseif ($c === '}') { $depth--; }
            if ($c === ',' && $depth === 0) { $out[] = $cur; $cur = ''; continue; }
            $cur .= $c;
        }
        if (trim($cur) !== '') { $out[] = $cur; }
        return $out;
    }

    private static function firstName(string $s): ?string
    {
        return preg_match('/"([^"]*)"/', $s, $m) ? $m[1] : null;
    }

    /** 某个数组标识符（`kX[] = {…}` 或 `kX = {…}`）里的条目原文。 */
    private static function arrayEntries(string $text, string $ident): array
    {
        if ($ident === '' || !preg_match('/\b' . preg_quote($ident, '/') . '\s*(?:\[\s*\])?\s*=\s*\{/', $text, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $body = self::braceBody($text, $m[0][1] + strlen($m[0][0]) - 1);
        if ($body === null) { return []; }
        $rows = [];
        foreach (self::splitEntries($body) as $e) {
            if (strpos($e, '{') !== false) {
                $rows[] = $e;
                continue;
            }
            // 命名常量引用（`kDialogMethods[] = {kTitle, kOnOk, …}`）：window_effects/mod.cpp
            // 的 Dialog/AlertDialog/Sheet/Notification 家族把共享的 MethodDescriptor 定义成
            // 具名 constexpr 再按名收进数组——直接过滤无 `{` 的条目会把 on_ok/on_close/
            // on_cancel/title 整族漏成"上游没声明"（parity 门因此假红过一次）。同一文件里
            // 按 `constexpr <类型> <名> = {…}` 解一层引用；共享描述符（kTitle 被四颗复用）
            // 每次展开都得到同一条目，跟重复内联的效果一致。
            $ref = trim($e);
            if (preg_match('/^[A-Za-z_]\w*$/', $ref)
                && preg_match('/constexpr\s+\w+\s+' . preg_quote($ref, '/') . '\s*=\s*\{/', $text, $rm, PREG_OFFSET_CAPTURE)) {
                $refBody = self::braceBody($text, $rm[0][1] + strlen($rm[0][0]) - 1);
                // 注意：单条 descriptor 的本体没有内层大括号
                // （`MethodDescriptor kOnOk = {"on_ok", …}`），不能按"含 { "过滤。
                if ($refBody !== null) {
                    $rows[] = $refBody;
                }
            }
        }
        return $rows;
    }

    /** 枚举字面量表：`static constexpr const char* kX[] = {"a","b"};` */
    private static function literalTable(string $text, string $ident): array
    {
        if ($ident === '' || !preg_match('/\b' . preg_quote($ident, '/') . '\s*\[\s*\]\s*=\s*\{/', $text, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $body = self::braceBody($text, $m[0][1] + strlen($m[0][0]) - 1);
        if ($body === null) { return []; }
        preg_match_all('/"([^"]*)"/', $body, $sm);
        return $sm[1];
    }

    /** 一个 ArgumentDescriptor 条目 → [名字, schema 类别, 枚举字面量]。 */
    private static function parseArg(string $entry, string $text): array
    {
        $name = self::firstName($entry);
        if (preg_match('/SchemaEnum\(\s*([A-Za-z_]\w*)/', $entry, $em)) {
            return [$name, 'enum', self::literalTable($text, $em[1])];
        }
        if (preg_match('/Schema(Entity|Callback)\(\s*"([^"]*)"/', $entry, $en)) {
            return [$name, 'entity:' . $en[2], []];
        }
        if (preg_match('/Schema(Optional|Array)\(\s*&?(\w+)/', $entry, $om)) {
            return [$name, strtolower($om[1]), []];
        }
        if (preg_match('/Schema([A-Za-z]+)\(\s*\)/', $entry, $sm)) {
            return [$name, strtolower($sm[1]), []];
        }
        return [$name, 'unknown', []];
    }

    /** 一个 descriptor 条目的实参表标识符（第二个位置上的数组名）。 */
    private static function argListOf(string $entry, string $text): array
    {
        if (!preg_match('/,\s*([A-Za-z_]\w*)\s*,/', $entry, $am)) { return []; }
        $ident = trim(preg_replace('/\s+/', '', $am[1]));
        $args = [];
        foreach (self::arrayEntries($text, $ident) as $ae) { $args[] = self::parseArg($ae, $text); }
        return $args;
    }

    /** @return list<string> */
    public static function kinds(string $specH): array
    {
        $text = is_file($specH) ? (string) file_get_contents($specH) : '';
        $out = [];
        $in = false;
        foreach (explode("\n", $text) as $line) {
            if (!$in) {
                if (preg_match('/^enum class ComponentKind/', $line)) { $in = true; }
                continue;
            }
            if (preg_match('/^\};/', $line)) { break; }
            $t = trim($line);
            // 注意取捕获组而不是整行：整行带着行尾逗号，比对时会全线判"枚举里没有"。
            if (preg_match('/^([A-Z][A-Za-z0-9]*),$/', $t, $m)) { $out[] = $m[1]; }
        }
        return $out;
    }

    /** @return list<string> materialize 行为链认得的 op 名 */
    public static function ops(string $materializeCpp): array
    {
        $text = is_file($materializeCpp) ? (string) file_get_contents($materializeCpp) : '';
        preg_match_all('/StrEq\(\s*op\.name\s*,\s*StrL\("([a-z0-9_]+)"\)\)/', $text, $m);
        return array_values(array_unique($m[1]));
    }

    /**
     * `role()` 能认的角色名（上游权威表 a11y.cpp 的 kRoleNames）。
     *
     * 上游只收 snake_case：RoleNameMatches 把表里的 PascalCase 逐项按下划线拆开再比，
     * 认不出 'Button' / 'listItem' 这类写法，而 materialize 那边是
     * `if (role != AccessibilityRole::None) element->Role(role)` —— 名字错了就静默不加，
     * 与 #66 那五个糖同一形状（错名不报，只是这里连像素都不变）。
     * 所以这张表必须取到、并且和 PHP 侧白名单对齐。
     *
     * @return list<string>
     */
    public static function roles(string $a11yCpp): array
    {
        $text = is_file($a11yCpp) ? (string) file_get_contents($a11yCpp) : '';
        // 字面量是跨行相邻拼接的（"DescriptionLis" "t\0"），所以先按相邻串接整体取出。
        if (!preg_match('/const char kRoleNames\[\]\s*=\s*([^;]*);/s', $text, $m)) { return []; }
        preg_match_all('/"([^"]*)"/', $m[1], $sm);
        $blob = '';
        foreach ($sm[1] as $seg) { $blob .= str_replace('\0', "\0", $seg); }
        $out = [];
        foreach (explode("\0", $blob) as $name) {
            if ($name === '') { continue; }
            $snake = '';
            $len = strlen($name);
            for ($i = 0; $i < $len; $i++) {
                $c = $name[$i];
                if ($c >= 'A' && $c <= 'Z') {
                    if ($i > 0) { $snake .= '_'; }
                    $c = chr(ord($c) + 32);
                }
                $snake .= $c;
            }
            $out[] = $snake;
        }
        return $out;
    }

    /**
     * style.cpp 的样式关键字表 `static const Keyword kKeywords[] = {…}`，
     * 外加族表 kFamilies[]（`{"w", Fam::W, Suffix::Box, Arg::Length}`）与 kOtherParamNames。
     *
     * `struct Keyword { const char* name; void (*apply)(El* e); uint32_t fields; }`
     * —— 第三个字段**不是 arity，也不是"生不生效"**。这是查过消费端才敢下的结论：
     * `ApplyNullaryStyle()`（style.cpp:1642）拿到条目就无条件 `keyword->apply(element)`，
     * `fields` 全树只被 `StyleFieldsOf(name)`（style.cpp:1694）读一次，用途是
     * "这个名字动了哪些 Style 字段"，给 transition()/spring() 判可动画属性用。
     * 表上方那句 "Where this tree's Style has no field for what the method sets,
     * the entry says so and changes nothing" 说的是**空 apply**，而实测全表空 lambda
     * 条目 0 个（`grep -cE '\[\]\(El[^)]*\) *\{ *\}'`）。
     * 所以键名是 animated 而不是 inert：fields != 0 = 这关键字动到被追踪的字段。
     *
     * @return array{all: list<string>, animated: list<string>, missing: list<string>, families: list<string>, otherParams: list<string>}
     *         missing 是解析器自己没取到 fields 的名字——非零即说明取数端有洞
     */
    public static function styleKeywords(string $styleCpp): array
    {
        $text = is_file($styleCpp) ? (string) file_get_contents($styleCpp) : '';
        $all = [];
        $animated = [];
        $missing = [];
        $decl = 'static const Keyword kKeywords[] = {';
        $head = strpos($text, $decl);
        if ($head === false) {
            return ['all' => [], 'animated' => [], 'missing' => [], 'families' => [], 'otherParams' => []];
        }
        $body = self::braceBody($text, $head + strlen($decl) - 1);
        if ($body !== null) {
            // 逐条定位 `{"名字", [](`，再从 lambda 的第一个 { 配对花括号；其后紧跟的
            // 就是 fields 表达式（0 / 标识符 / 标识符|标识符，可跨行）。
            if (preg_match_all('/\{\s*"([a-z0-9_]+)"\s*,\s*\[\]\s*\(/', $body, $dm, PREG_OFFSET_CAPTURE)) {
                foreach ($dm[0] as $k => $hit) {
                    $name = $dm[1][$k][0];
                    $all[] = $name;
                    $brace = strpos($body, '{', $hit[1] + strlen($hit[0]));
                    if ($brace === false) { $missing[] = $name . '(取不到 lambda 体)'; continue; }
                    $end = self::matchingBrace($body, $brace);
                    if ($end === null) { $missing[] = $name . '(花括号不配对)'; continue; }
                    if (!preg_match('/^\s*,\s*([A-Za-z0-9_|()\s]+?)\s*[,}]/',
                            substr($body, $end + 1, 120), $fm)) {
                        $missing[] = $name . '(无 fields 字段)';
                        continue;
                    }
                    if (preg_replace('/\s+/', '', $fm[1]) !== '0') { $animated[] = $name; }
                }
            }
        }
        // paramStyle() 发的名字要在族表或"其他带参名"里在册。
        $families = [];
        if (preg_match_all('/\{\s*"([a-z0-9_]+)"\s*,\s*Fam::/', $text, $fm2)) {
            $families = $fm2[1];
        }
        $others = [];
        if (preg_match('/kOtherParamNames\[\]\s*=\s*\{/', $text, $om, PREG_OFFSET_CAPTURE)) {
            $ob = self::braceBody($text, $om[0][1] + strlen($om[0][0]) - 1);
            if ($ob !== null) { preg_match_all('/"([a-z0-9_]+)"/', $ob, $os); $others = $os[1]; }
        }
        return ['all' => $all, 'animated' => $animated, 'missing' => $missing,
            'families' => $families, 'otherParams' => $others];
    }

    /** 从 $open 处的 '{' 找到配对闭合位的下标（字符串字面量里的花括号不算）。 */
    private static function matchingBrace(string $text, int $open): ?int
    {
        $depth = 0;
        $len = strlen($text);
        for ($i = $open; $i < $len; $i++) {
            $c = $text[$i];
            if ($c === '"' || $c === "'") {
                $q = $c;
                $i++;
                while ($i < $len && $text[$i] !== $q) {
                    if ($text[$i] === '\\') { $i++; }
                    $i++;
                }
                continue;
            }
            if ($c === '{') { $depth++; } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) { return $i; }
            }
        }
        return null;
    }

    /**
     * 一份描述能用的槽名与状态样式名。
     *
     * 权威源是 runtime.cpp 的 prelude 字面量表（`for (const name of [...])` 那两张），
     * 不是 component_registry.cpp 的 IsRegisteredCommonSlot —— 后者少一个 `addon`，
     * 拿它当白名单会把 InputGroup 那条合法路径判成错。
     *
     * @return array{0: list<string>, 1: list<string>} [slots, stateStyles]
     */
    public static function slotTables(string $runtimeCpp): array
    {
        $text = is_file($runtimeCpp) ? (string) file_get_contents($runtimeCpp) : '';
        $slots = [];
        $states = [];
        if (preg_match('/for \(const name of \[([^\]]*?"trigger"[^\]]*?)\]\)/s', $text, $m)) {
            preg_match_all('/"([a-z_]+)"/', $m[1], $sm);
            $slots = $sm[1];
        }
        if (preg_match('/for \(const name of \[([^\]]*?"range_style"[^\]]*?)\]\)/s', $text, $m)) {
            preg_match_all('/"([a-z_]+)"/', $m[1], $sm);
            $states = $sm[1];
        }
        return [$slots, $states];
    }

    /** @return array<string,array{...}> */
    public static function catalog(string $dir): array
    {
        $inv = [];
        if (!is_dir($dir)) { return $inv; }
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && substr($f->getFilename(), -4) === '.cpp') { $files[] = $f->getPathname(); }
        }
        sort($files);

        $take = static function (array $entries, string $text, string $file) use (&$inv): void {
            $component = self::firstName((string) ($entries[0] ?? ''));
            if ($component === null) { return; }
            $ctorIdent = trim(preg_replace('/\s+/', '', (string) ($entries[1] ?? '')));
            $methIdent = trim(preg_replace('/\s+/', '', (string) ($entries[2] ?? '')));
            $rec = ['file' => $file, 'ctors' => [], 'methods' => []];
            foreach (self::arrayEntries($text, $ctorIdent) as $e) {
                $export = self::firstName($e);
                if ($export === null) { continue; }
                $rec['ctors'][$export] = self::argListOf($e, $text);
            }
            foreach (self::arrayEntries($text, $methIdent) as $e) {
                $mname = self::firstName($e);
                if ($mname === null) { continue; }
                $rec['methods'][$mname] = self::argListOf($e, $text);
            }
            $inv[$component] = $rec;
        };

        foreach ($files as $path) {
            $text = (string) file_get_contents($path);
            $file = basename($path);
            // 形状一：一文件一个 descriptor。
            if (preg_match_all('/ComponentDescriptor\s+\w+\s*=\s*\{/', $text, $dm, PREG_OFFSET_CAPTURE)) {
                foreach ($dm[0] as $hit) {
                    $body = self::braceBody($text, $hit[1] + strlen($hit[0]) - 1);
                    $take(self::splitEntries((string) $body), $text, $file);
                }
            }
            // 形状二：家族表 `ComponentDescriptor kX[] = { {…}, {…} };`
            if (preg_match_all('/ComponentDescriptor\s+\w+\s*\[\s*\]\s*=\s*\{/', $text, $am2, PREG_OFFSET_CAPTURE)) {
                foreach ($am2[0] as $hit) {
                    $body = self::braceBody($text, $hit[1] + strlen($hit[0]) - 1);
                    if ($body === null) { continue; }
                    foreach (self::splitEntries($body) as $entry) {
                        $pos = strpos($entry, '{');
                        if ($pos === false) { continue; }
                        $inner = self::braceBody($entry, $pos);
                        if ($inner === null) { continue; }
                        $take(self::splitEntries($inner), $text, $file);
                    }
                }
            }
        }
        return $inv;
    }
}

/** 供 php -r / CLI 直接看一眼规模。 */
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $dir = dirname(__DIR__) . '/upstream/gpui-kit-cpp';
    foreach (array_slice($argv, 1) as $a) {
        if (strncmp($a, '--', 2) !== 0) { $dir = $a; break; }
    }
    $v = UpstreamVocab::load($dir);
    $exports = 0;
    $methods = 0;
    foreach ($v['catalog'] as $rec) {
        $exports += count($rec['ctors']);
        $methods += count($rec['methods']);
    }
    printf("kinds=%d ops=%d roles=%d styles=%d个关键字(其中%d个动到被追踪字段)+%d族+%d其他带参 catalog=%d 组件 / %d 导出名 / %d 方法名（树：%s）\n",
        count($v['kinds']), count($v['ops']), count($v['roles']),
        count($v['styleKeywords']['all']), count($v['styleKeywords']['animated']),
        count($v['styleKeywords']['families']), count($v['styleKeywords']['otherParams']),
        count($v['catalog']), $exports, $methods, $dir);
    if ($v['styleKeywords']['missing'] !== []) {
        printf("⚠ 样式表有 %d 条没解析出 fields 字段：%s\n",
            count($v['styleKeywords']['missing']),
            implode(', ', $v['styleKeywords']['missing']));
    }
    if (in_array('--dump-styles', $argv, true)) {
        echo '动到被追踪字段（transition/spring 才有得可动画）：',
            implode(', ', $v['styleKeywords']['animated']), "\n";
    }
    if (in_array('--dump-roles', $argv, true)) {
        echo "    " . implode(",\n    ", array_map(
            static fn (string $r): string => "'" . $r . "'",
            $v['roles']
        )) . ",\n";
    }
}
