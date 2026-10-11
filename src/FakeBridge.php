<?php

declare(strict_types=1);

/**
 * FakeBridge — 纯 PHP 桥接替身。
 *
 * 提供与 php-src/gpui.stub.php 同签名的全局函数实现，使 App/Element 的领域逻辑
 * 无需 gpui-kit-cpp 或编译器即可 Pest 单测。
 *
 * autoload-dev.files 加载本文件后，App.php 里的 `\gpui_app_create()` 等调用落到这里。
 * 类在 TypePHP\Gpui 命名空间；gpui_* 函数必须在全局命名空间（App 用 `\gpui_*` 调用），
 * 故用花括号命名空间分块。
 */

namespace TypePHP\Gpui {
    final class FakeBridge
    {
        /** @var array<int, array<string, mixed>> 模拟窗口状态 */
        public static array $windows = [];

        /** @var int 下一个窗口 id */
        public static int $nextWindowId = 1;

        /** @var array<int, array{window:int, type:string, id:string, value:mixed}> 待取事件队列 */
        public static array $eventQueue = [];

        /** @var array<int, array<string, mixed>[]> 每窗口的渲染历史（用于断言） */
        public static array $renderLog = [];

        /** @var bool 窗口是否打开（模拟） */
        public static bool $windowOpen = true;

        /** @var array<string,string> 模拟输入框文本值（按 id） */
        public static array $inputValues = [];

        /** @var array<int, array{window:int,path:string,w:int,h:int}> 截图请求记录 */
        public static array $snapshotRequests = [];

        /** @var array<int, array{title:string,level:string}> toast 请求记录 */
        public static array $toasts = [];

        /** @var int 模拟打开的 dialog 数 */
        public static int $dialogCount = 0;

        /** app 菜单条：最近一次 set_app_menus 装入的菜单数 */
        public static int $appMenuCount = 0;

        /** @var string|null 模拟系统粘贴板（null = 空剪贴板） */
        public static ?string $clipboard = null;

        /** @var string|null 模拟粘贴板上的图像流字节（编码后的 PNG/TIFF） */
        public static ?string $clipboardImage = null;

        /** @var array<int,string> 模拟粘贴板上的文件绝对路径 */
        public static array $clipboardPaths = [];

        /** @var string|null 下一次 prompt_for_path 的答案（null = 用户取消） */
        public static ?string $nextPromptPath = null;

        /** @var array<int, array{directories:bool,title:string}> prompt_for_path 调用记录 */
        public static array $promptRequests = [];

        /** @var array<int, string> 被白名单放行的 open_url */
        public static array $openedUrls = [];

        /** @var array<int, string> 被白名单拒绝的 open_url */
        public static array $rejectedUrls = [];

        /** 模拟 inspector 开合（toggle 翻转） */
        public static bool $inspectorOpen = false;

        /** @var string|null 帧率 HUD 的锚点（null = 未显示） */
        public static ?string $fpsAnchor = null;

        /** 模拟打开的 sheet 数（同一时刻只有一个，故只取 0/1） */
        public static int $sheetCount = 0;

        /** @var array<int, array{id:string,dir:int}> webview 历史导航调用记录 */
        public static array $webviewNavs = [];

        /** @var array<string,string> 脚本化的 http 应答体：请求 id → body（替身不联网） */
        public static array $httpBodies = [];

        /** @var array<int, array{id:string,url:string}> httpGet 调用记录 */
        public static array $httpRequests = [];

        /** 下一个假 watch id（0 保留为"没有 watch"，与 gpui 一致） */
        public static int $nextWatchId = 1;

        /** @var array<int,string> 活着的目录监听：watch id → phpId */
        public static array $watches = [];

        /**
         * @var array<string,mixed> 脚本化的系统状态覆盖项（App::sysInfo 的返回字段）。
         * 替身没有"两次采样"这回事，cpu 这类只能由测试自己给定。
         */
        public static array $sysInfo = [];

        public static function reset(): void
        {
            self::$inputValues = [];
            self::$snapshotRequests = [];
            self::$toasts = [];
            self::$dialogCount = 0;
            self::$appMenuCount = 0;
            self::$clipboard = null;
            self::$clipboardImage = null;
            self::$clipboardPaths = [];
            self::$nextPromptPath = null;
            self::$promptRequests = [];
            self::$openedUrls = [];
            self::$rejectedUrls = [];
            self::$inspectorOpen = false;
            self::$fpsAnchor = null;
            self::$sheetCount = 0;
            self::$webviewNavs = [];
            self::$httpBodies = [];
            self::$httpRequests = [];
            self::$nextWatchId = 1;
            self::$watches = [];
            self::$sysInfo = [];
            self::$windows = [];
            self::$nextWindowId = 1;
            self::$eventQueue = [];
            self::$renderLog = [];
            self::$windowOpen = true;
        }

        /** 测试用：入队一个事件，下一次 poll_event 取走。 */
        public static function pushEvent(int $windowId, string $type, string $id, mixed $value = null): void
        {
            self::$eventQueue[] = ['window' => $windowId, 'type' => $type, 'id' => $id, 'value' => $value];
        }

        /**
         * gpui::IsOpenableUrl 的 PHP 重写，判据取同一条规则：只放行 http/https，
         * 长度 ≤32768，authority 不含控制字符/DEL/反斜杠，host 非空，
         * 端口是 ≤65535 的十进制，IPv6 字面量须成对且非空。
         */
        public static function isOpenableUrl(string $url): bool
        {
            if ($url === '' || strlen($url) > 32768) {
                return false;
            }
            $sep = strpos($url, '://');
            if ($sep === false || $sep === 0) {
                return false;
            }
            $scheme = strtolower(substr($url, 0, $sep));
            if ($scheme !== 'http' && $scheme !== 'https') {
                return false;
            }
            $rest = substr($url, $sep + 3);
            $authority = '';
            for ($i = 0, $n = strlen($rest); $i < $n; $i++) {
                $c = $rest[$i];
                if ($c === '/' || $c === '?' || $c === '#') {
                    break;
                }
                $o = ord($c);
                if ($o <= 0x20 || $o >= 0x7f || $c === '\\') {
                    return false;
                }
                $authority .= $c;
            }
            $at = strrpos($authority, '@');
            $hostPort = $at === false ? $authority : substr($authority, $at + 1);

            if ($hostPort === '') {
                return false;
            }
            if ($hostPort[0] === '[') {
                // 真实判据是 close <= hostStart + 1 即拒：方括号里必须有内容。
                $close = strpos($hostPort, ']');
                if ($close === false || $close <= 1) {
                    return false;
                }
                $after = substr($hostPort, $close + 1);
                if ($after === '') {
                    return true;
                }

                return $after[0] === ':' && self::isValidPort(substr($after, 1));
            }
            $colon = strpos($hostPort, ':');
            if ($colon === false) {
                return true;
            }
            if ($colon === 0) {
                return false;
            }

            return self::isValidPort(substr($hostPort, $colon + 1));
        }

        private static function isValidPort(string $port): bool
        {
            return $port !== '' && ctype_digit($port) && (int) $port <= 65535;
        }

        /**
         * 该窗口渲染过的树里出现过的 webview 节点：id => 地址（本地 HTML 节点记空串）。
         * 替身没有控件实体，只能靠"这棵树里有过这个 id"来应答；真实侧答的是 wry 报的
         * 当前地址与布局应用给 OS 控件的盒子，所以这里 w/h 恒为 0——不拿它当验收。
         *
         * @return array<string,string>
         */
        public static function renderedWebviews(mixed $window): array
        {
            $found = [];
            foreach (self::$renderLog[(int) $window] ?? [] as $tree) {
                self::collectWebviews($tree, $found);
            }

            return $found;
        }

        /** @param array<string,string> $found */
        private static function collectWebviews(array $node, array &$found): void
        {
            if (($node['kind'] ?? null) === 'webview') {
                $id = (string) ($node['id'] ?? '');
                if ($id !== '' && !isset($found[$id])) {
                    $found[$id] = (string) ($node['value'] ?? '');
                }
            }
            foreach (($node['children'] ?? []) as $kid) {
                if (is_array($kid)) {
                    self::collectWebviews($kid, $found);
                }
            }
        }
    }
}

namespace {
    use TypePHP\Gpui\FakeBridge;

    // ── 应用生命周期 ──

    function gpui_app_create(): void
    {
    }

    function gpui_window_open(string $title, int $width, int $height): mixed
    {
        $id = FakeBridge::$nextWindowId++;
        FakeBridge::$windows[$id] = ['title' => $title, 'w' => $width, 'h' => $height];
        FakeBridge::$renderLog[$id] = [];
        return $id;
    }

    function gpui_render(mixed $window, array $tree): void
    {
        FakeBridge::$renderLog[(int) $window][] = $tree;
    }

    function gpui_window_is_open(mixed $window): bool
    {
        return FakeBridge::$windowOpen;
    }

    function gpui_pump_events(mixed $window): void
    {
        // no-op in fake
    }

    function gpui_poll_event(mixed $window): array
    {
        $wid = (int) $window;
        foreach (FakeBridge::$eventQueue as $i => $ev) {
            if ($ev['window'] === $wid) {
                unset(FakeBridge::$eventQueue[$i]);
                FakeBridge::$eventQueue = array_values(FakeBridge::$eventQueue);
                return ['type' => $ev['type'], 'id' => $ev['id'], 'value' => $ev['value']];
            }
        }
        return [];
    }

    function gpui_window_close(mixed $window): void
    {
        FakeBridge::$windowOpen = false;
    }

    function gpui_input_value(mixed $window, string $id): string
    {
        return FakeBridge::$inputValues[$id] ?? '';
    }

    function gpui_set_input_value(mixed $window, string $id, string $text): bool
    {
        FakeBridge::$inputValues[$id] = $text;
        return true;
    }

    function gpui_selftest_fire(mixed $window, string $id, string $type): bool
    {
        FakeBridge::pushEvent((int) $window, $type, $id, null);
        return true;
    }

    function gpui_selftest_fire_registered(mixed $window, string $id, string $type): bool
    {
        FakeBridge::pushEvent((int) $window, $type, $id, null);
        return true;
    }

    function gpui_snapshot(mixed $window, string $path, int $w, int $h): bool
    {
        // 无头替身：记录请求即可，不真正出图。
        FakeBridge::$snapshotRequests[] = ['window' => (int) $window, 'path' => $path, 'w' => $w, 'h' => $h];
        return true;
    }

    function gpui_debug_tree(mixed $window): string
    {
        return '';
    }

    function gpui_last_error(mixed $window): string
    {
        return '';
    }

    function gpui_toast(mixed $window, string $title, string $desc, string $level, int $timeoutMs): void
    {
        FakeBridge::$toasts[] = ['title' => $title, 'level' => $level];
    }

    function gpui_open_dialog(mixed $window, array $tree, bool $escape, bool $backdrop): int
    {
        FakeBridge::$dialogCount++;
        return FakeBridge::$dialogCount;
    }

    function gpui_dialog_count(mixed $window): int
    {
        return FakeBridge::$dialogCount;
    }

    function gpui_close_dialog(mixed $window): bool
    {
        if (FakeBridge::$dialogCount > 0) {
            FakeBridge::$dialogCount--;
            return true;
        }
        return false;
    }

    function gpui_set_app_menus(mixed $window, array $menus): int
    {
        FakeBridge::$appMenuCount = count($menus);
        return FakeBridge::$appMenuCount;
    }

    function gpui_app_menu_count(mixed $window): int
    {
        return FakeBridge::$appMenuCount;
    }

    function gpui_element_value(mixed $window, string $id): mixed
    {
        return null;
    }

    function gpui_set_timer(mixed $window, string $id, int $intervalMs): void
    {
        // no-op
    }

    function gpui_set_theme(mixed $window, string $mode): void
    {
        // no-op
    }

    function gpui_clipboard_text(mixed $window): string
    {
        return FakeBridge::$clipboard ?? '';
    }

    function gpui_set_clipboard_text(mixed $window, string $text): void
    {
        FakeBridge::$clipboard = $text;
    }

    function gpui_clipboard_item(mixed $window): array
    {
        // 文本与 clipboardText() 共用同一份状态：真实现里它们本来就是同一个快照。
        $text = FakeBridge::$clipboard ?? '';
        $image = FakeBridge::$clipboardImage ?? '';
        $paths = FakeBridge::$clipboardPaths;
        $flags = ($text !== '' ? 1 : 0) | ($image !== '' ? 2 : 0) | ($paths !== [] ? 4 : 0);

        return ['flags' => $flags, 'text' => $text, 'image' => $image, 'paths' => $paths];
    }

    function gpui_prompt_for_path(mixed $window, bool $directories, string $title): string
    {
        FakeBridge::$promptRequests[] = ['directories' => $directories, 'title' => $title];

        return FakeBridge::$nextPromptPath ?? '';
    }

    function gpui_open_url(string $url): bool
    {
        if (!FakeBridge::isOpenableUrl($url)) {
            FakeBridge::$rejectedUrls[] = $url;

            return false;
        }
        FakeBridge::$openedUrls[] = $url;

        return true;
    }

    function gpui_toggle_inspector(mixed $window): void
    {
        FakeBridge::$inspectorOpen = !FakeBridge::$inspectorOpen;
    }

    function gpui_show_fps(mixed $window, string $anchor): bool
    {
        if (!in_array($anchor, \TypePHP\Gpui\App::FPS_ANCHORS, true)) {
            return false;
        }
        FakeBridge::$fpsAnchor = $anchor;

        return true;
    }

    function gpui_hide_fps(mixed $window): bool
    {
        $wasUp = FakeBridge::$fpsAnchor !== null;
        FakeBridge::$fpsAnchor = null;

        return $wasUp;
    }

    function gpui_fps_visible(mixed $window): bool
    {
        return FakeBridge::$fpsAnchor !== null;
    }

    function gpui_open_sheet(mixed $window, array $tree, string $anchor): int
    {
        // Sheet 同一时刻只有一个：后开的替换先开的，而不是叠两层。
        FakeBridge::$sheetCount = 1;

        return 1;
    }

    function gpui_close_sheet(mixed $window): bool
    {
        $wasOpen = FakeBridge::$sheetCount > 0;
        FakeBridge::$sheetCount = 0;

        return $wasOpen;
    }

    function gpui_has_sheet(mixed $window): bool
    {
        return FakeBridge::$sheetCount > 0;
    }

    function gpui_webview_nav(mixed $window, string $id, int $dir): bool
    {
        FakeBridge::$webviewNavs[] = ['id' => $id, 'dir' => $dir];

        return array_key_exists($id, FakeBridge::renderedWebviews($window));
    }

    function gpui_webview_probe(mixed $window, string $id): array
    {
        $seen = FakeBridge::renderedWebviews($window);
        $ok = array_key_exists($id, $seen);

        return [
            'ok' => $ok,
            'url' => $ok ? $seen[$id] : '',
            // 替身没有布局，应用盒子无从谈起——真实侧由 wry::WebViewBounds 报。
            'w' => 0.0,
            'h' => 0.0,
        ];
    }

    function gpui_http_get(mixed $window, string $id, string $url): bool
    {
        // 只放行 http(s)，与 gpui::HttpUrlIsRemote 同一条规则（data:/file:/ftp: 归别人）。
        if ($id === '' || !preg_match('#^https?://#i', $url)) {
            return false;
        }
        FakeBridge::$httpRequests[] = ['id' => $id, 'url' => $url];

        return true;
    }

    function gpui_http_response(mixed $window, string $id): array
    {
        if (!array_key_exists($id, FakeBridge::$httpBodies)) {
            return [];
        }
        $body = FakeBridge::$httpBodies[$id];
        unset(FakeBridge::$httpBodies[$id]);

        return ['ok' => true, 'status' => 200, 'body' => $body,
            'contentType' => 'text/plain'];
    }

    function gpui_watch_dir(mixed $window, string $id, string $dir, bool $create): int
    {
        if ($id === '' || $dir === '') {
            return 0;
        }
        $watchId = FakeBridge::$nextWatchId++;
        FakeBridge::$watches[$watchId] = $id;

        return $watchId;
    }

    function gpui_unwatch_dir(mixed $window, int $watchId): void
    {
        unset(FakeBridge::$watches[$watchId]);
    }

    function gpui_watch_fake(mixed $window, string $id): int
    {
        if ($id === '') {
            return 0;
        }
        $watchId = FakeBridge::$nextWatchId++;
        FakeBridge::$watches[$watchId] = $id;
        // 真实现是"排一次主线程回调"，要 pump 一下才落地；替身直接入队。
        FakeBridge::pushEvent((int) $window, 'dirChange', $id);

        return $watchId;
    }

    function gpui_inflate(string $data, bool $gzip): array
    {
        // 先看容器头，不拿坏数据去撞 PHP 的 warning（Pest 的 error handler 不吃 @）。
        // gpui 的 ZlibInflate 同样从头开始判（standard.cpp 里那句 "invalid zlib header"）。
        // 这里只认 PHP 自己写出来的那两种头：zlib 的 0x78、gzip 的 1f 8b。
        $headOk = $data !== '' && ($gzip
            ? strncmp($data, "\x1f\x8b", 2) === 0
            : $data[0] === "\x78");
        if (!$headOk) {
            return ['ok' => false, 'data' => '', 'error' => 'bad container header'];
        }

        // 与 gpui::ZlibInflate 同一档语义：gzip=true 吃 gzip 容器，false 吃 zlib 头
        // （不是 raw deflate）。跑测试的是普通 PHP，ext/zlib 在——所以这条断言量的是
        // "两侧的 zlib 语义一致"，而原生门禁量的是 gpui 自己那个解压器。
        $out = $gzip ? @gzdecode($data) : @gzuncompress($data);
        if ($out === false) {
            return ['ok' => false, 'data' => '', 'error' => 'inflate failed'];
        }

        return ['ok' => true, 'data' => $out, 'error' => ''];
    }

    function gpui_sys_info(int $topProcs): array
    {
        // disk 两侧可比：gpui 在 mac 上 statfs("/")，used = total - f_bavail；
        // PHP 的 disk_total_space/disk_free_space 是同一个算法。所以这条不是编数，
        // 是拿本机文件系统当对照。
        $total = function_exists('disk_total_space') ? (disk_total_space('/') ?: 0) : 0;
        $free = function_exists('disk_free_space') ? (disk_free_space('/') ?: 0) : 0;
        $used = $total > $free ? $total - $free : 0;
        $info = [
            // cpu 在真侧是两次采样的差值，替身没有这回事 → 默认 0，由测试脚本化。
            'cpu' => 0.0,
            'memPct' => 0.0,
            'memTotal' => 0,
            'memUsed' => 0,
            'diskPct' => $total > 0 ? $used * 100.0 / $total : 0.0,
            'diskTotal' => (int) $total,
            'diskUsed' => (int) $used,
            'ncpu' => 1,
            'batteryPresent' => false,
            'batteryCharging' => false,
            'batteryPct' => 0.0,
            // 替身不枚举真进程；要测 procs 就把它脚本化塞进来。
            'procs' => [],
        ];
        foreach (FakeBridge::$sysInfo as $key => $value) {
            $info[(string) $key] = $value;
        }
        if ($topProcs <= 0) {
            $info['procs'] = [];
        } elseif (count($info['procs']) > $topProcs) {
            $info['procs'] = array_slice($info['procs'], 0, $topProcs);
        }

        return $info;
    }
}
