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
    /** @return array{kinds: list<string>, ops: list<string>, slots: list<string>, stateStyles: list<string>, catalog: array<string,array{ctors:array<string,list<array{0:?string,1:string,2:list<string>>}>,methods:array<string,list<array{0:?string,1:string,2:list<string>>}>,file:string}>} */
    public static function load(string $treeDir): array
    {
        [$slots, $states] = self::slotTables($treeDir . '/src/shell/runtime.cpp');
        return [
            'kinds' => self::kinds($treeDir . '/src/shell/spec.h'),
            'ops' => self::ops($treeDir . '/src/shell/materialize.cpp'),
            'slots' => $slots,
            'stateStyles' => $states,
            'catalog' => self::catalog($treeDir . '/src/component_shell'),
        ];
    }

    /** 从 $text 的 $open（'{' 的下标）取出配对花括号内的内容。 */
    private static function braceBody(string $text, int $open): ?string
    {
        $depth = 0;
        $len = strlen($text);
        for ($i = $open; $i < $len; $i++) {
            $c = $text[$i];
            if ($c === '"' || $c === "'") {          // 跳过字符串字面量里的花括号
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
            if (strpos($e, '{') !== false) { $rows[] = $e; }
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
    $dir = $argv[1] ?? dirname(__DIR__) . '/upstream/gpui-kit-cpp';
    $v = UpstreamVocab::load($dir);
    $exports = 0;
    $methods = 0;
    foreach ($v['catalog'] as $rec) {
        $exports += count($rec['ctors']);
        $methods += count($rec['methods']);
    }
    printf("kinds=%d ops=%d catalog=%d 组件 / %d 导出名 / %d 方法名（树：%s）\n",
        count($v['kinds']), count($v['ops']), count($v['catalog']), $exports, $methods, $dir);
}
