<?php

/**
 * cmd/check-doc-anchors.php —— 文档锚点（`#fragment`）死链门。
 *
 * 为什么要有它：`npm run docs:build` 是死链门，但它**只解析页面是否存在，不解析 `#fragment`**。
 * 2026-10-15 实测：构建全绿的同时，中文 `components.md` 里一根指向已改名标题的锚
 * （`/guide/system-capabilities#六条硬约束`，标题当时已改成「运行约束」）就这么发出去了。
 * 也就是说"docs:build 过了"这句话对锚点这一类引用**不构成证据**，所以这里按产物真扫一遍。
 *
 * 判的不是源码里的标题字符串，而是**构建产物里实际渲染出来的 `id="…"`**：
 * VitePress 给标题生成 id 的规则含中文转写与去重，手推容易和实际不一致，照产物比才不会误报。
 *
 * 用法：php cmd/check-doc-anchors.php [dist 目录]
 *   前置：先 `cd docs && npm run docs:build`（产物 docs/.vitepress/dist）。
 *   退出码：0 全解析；1 有死锚或缺页；2 产物不存在或本轮根本没链接可查（防空跑的门）。
 */

$root = dirname(__DIR__);
$dist = $argv[1] ?? "$root/docs/.vitepress/dist";
$docsSrc = "$root/docs";

if (!is_dir($dist)) {
    fwrite(STDERR, "ERROR: 找不到构建产物 $dist（先 cd docs && npm run docs:build）\n");
    exit(2);
}

/** 收集产物路由 → 该页真实存在的 id 集合。 */
$routeIds = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dist, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $f) {
    if (!$f->isFile() || strtolower(substr($f->getFilename(), -5)) !== '.html') {
        continue;
    }
    $rel = substr($f->getPathname(), strlen($dist));
    $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
    if (basename($rel) === 'index.html') {
        $rel = dirname($rel);
    }
    $rel = preg_replace('/\.html$/', '', $rel);
    $route = '/' . ltrim($rel, '/');
    if ($route === '/index' || $route === '/') {
        $route = '/';
    }
    $html = (string) file_get_contents($f->getPathname());
    $ids = [];
    if (preg_match_all('/\sid="([^"]+)"/', $html, $m)) {
        foreach ($m[1] as $id) {
            $ids[urldecode(strtolower($id))] = true;
        }
    }
    $routeIds[$route] = $ids;
}

/** markdown 源里的站内链接（含锚点）逐条解析。 */
$fails = [];
$checked = 0;
$withFrag = 0;
$mdi = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($docsSrc, FilesystemIterator::SKIP_DOTS));
foreach ($mdi as $f) {
    if (!$f->isFile() || strtolower(substr($f->getFilename(), -3)) !== '.md') {
        continue;
    }
    $path = $f->getPathname();
    // 跳过主题/缓存/产物目录：那里可能有第三方 md，不是本站正文。
    if (preg_match('#/(\.vitepress|node_modules|dist|cache)/#', $path)) {
        continue;
    }
    $dirRel = str_replace(DIRECTORY_SEPARATOR, '/', substr(dirname($path), strlen($docsSrc)));
    $text = (string) file_get_contents($path);
    $label = str_replace($root . '/', '', $path);
    if (!preg_match_all('/\]\(\s*<?([^)\s>]+)>?\s*(?: "[^"]*")?\)/', $text, $mm)) {
        continue;
    }
    foreach ($mm[1] as $href) {
        if (preg_match('#^(https?:|mailto:|//)#i', $href)) {
            continue;
        }
        if ($href === '' || $href[0] === '#') {
            continue;   // 纯页内跳转，交给浏览器与 docs:build
        }
        $frag = '';
        if (($p = strpos($href, '#')) !== false) {
            $frag = substr($href, $p + 1);
            $href = substr($href, 0, $p);
        }
        if ($href === '') {
            continue;
        }
        $checked++;
        if ($frag !== '') {
            $withFrag++;
        }
        // 解析目标路由：绝对路径按站点根，相对路径按当前 md 的目录。
        if ($href[0] === '/') {
            $target = rtrim($href, '/');
            if ($target === '') {
                $target = '/';
            }
        } else {
            $joined = ($dirRel === '' ? '' : $dirRel) . '/' . $href;
            $parts = [];
            foreach (explode('/', $joined) as $seg) {
                if ($seg === '' || $seg === '.') {
                    continue;
                }
                if ($seg === '..') {
                    array_pop($parts);
                    continue;
                }
                $parts[] = $seg;
            }
            $target = '/' . implode('/', $parts);
        }
        // 路由归一：目录首页 /x/ 与 /x 都映射到 /x；带 .html 的写法也接受。
        $cands = [$target];
        $cands[] = preg_replace('/\.html$/', '', $target);
        $cands[] = rtrim($target, '/') . '/index';
        $route = null;
        foreach ($cands as $c) {
            if (isset($routeIds[$c])) {
                $route = $c;
                break;
            }
        }
        if ($route === null) {
            $fails[] = "$label → 缺页 '$href'（解析为 '$target'）";
            continue;
        }
        if ($frag === '') {
            continue;
        }
        $needle = urldecode(strtolower($frag));
        if (!isset($routeIds[$route][$needle])) {
            $fails[] = "$label → 死锚 '$href#$frag'（'$route' 的产物里没有 id='$frag'）";
        }
    }
}

if ($checked === 0) {
    fwrite(STDERR, "ERROR: 一条站内链接都没扫到——这条门空跑了（检查 docs 路径与产物）\n");
    exit(2);
}

if ($fails !== []) {
    foreach ($fails as $e) {
        echo "  ✗ $e\n";
    }
    echo "DOC ANCHORS FAIL (" . count($fails) . ") — 扫了 $checked 条站内链接（其中带锚 $withFrag 条）\n";
    exit(1);
}

echo "DOC ANCHORS OK — 扫了 $checked 条站内链接（其中带锚 $withFrag 条），产物路由与真实 id 全解析\n";
exit(0);
