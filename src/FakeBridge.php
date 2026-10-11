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

        public static function reset(): void
        {
            self::$inputValues = [];
            self::$snapshotRequests = [];
            self::$toasts = [];
            self::$dialogCount = 0;
            self::$appMenuCount = 0;
            self::$clipboard = null;
            self::$nextPromptPath = null;
            self::$promptRequests = [];
            self::$openedUrls = [];
            self::$rejectedUrls = [];
            self::$inspectorOpen = false;
            self::$fpsAnchor = null;
            self::$sheetCount = 0;
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
}
