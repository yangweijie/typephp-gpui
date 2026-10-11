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

        public static function reset(): void
        {
            self::$inputValues = [];
            self::$snapshotRequests = [];
            self::$toasts = [];
            self::$dialogCount = 0;
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
}
