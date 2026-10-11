<?php

declare(strict_types=1);

namespace TypePHP\Gpui;

/**
 * App — PHP 侧应用框架。
 *
 * 直接调用 gpui_* 全局桥接函数（由 php-src/gpui.stub.php 声明、
 * cpp-src/gpui_bridge.cc 实现）。测试时这些函数由 src/FakeBridge.php
 * 提供同签名的纯 PHP 实现，因此领域逻辑无需 gpui 或编译器即可单测。
 *
 * 用法：
 *   $app = new App();
 *   $app->run('My App', 800, 600, function () use (&$state) {
 *       return El::vFlex([...]);
 *   });
 */
final class App
{
    /** @var mixed 不透明窗口句柄（真实环境是 Box resource） */
    private mixed $window = null;

    /** @var array<string, array<string, array{0: callable, 1: int}>> 事件处理器表 */
    private array $handlers = [];

    /** @var callable[] 每帧求值的视图函数 */
    private array $views = [];

    private bool $running = false;
    private int $frames = 0;
    private string $lastError = '';

    /** Sheet 可滑出的边。 */
    public const SHEET_ANCHORS = ['top', 'bottom', 'left', 'right'];

    /** 帧率 HUD 的位置，取自 gpui 的 FpsAnchorNames()。 */
    public const FPS_ANCHORS = [
        'top_left', 'top_right', 'bottom_left', 'bottom_right',
        'top_center', 'bottom_center', 'left_center', 'right_center',
    ];

    // ── 生命周期 ──

    /**
     * 创建应用并运行主循环，直到窗口关闭。
     *
     * $view 是一个返回 Element 树的闭包，每帧重新求值。
     * 事件处理器只改外部状态，下一帧 view 读到新状态自动重渲染。
     */
    public function run(string $title, int $width, int $height, callable $view): void
    {
        \gpui_app_create();
        $this->window = \gpui_window_open($title, $width, $height);
        $this->views[] = $view;
        $this->loop();
    }

    /** 手动模式：先 create，再逐帧 run。用于多窗口或自定义循环。 */
    public function create(): void
    {
        \gpui_app_create();
    }

    public function createWindow(string $title, int $width = 800, int $height = 600): mixed
    {
        $this->window = \gpui_window_open($title, $width, $height);
        return $this->window;
    }

    public function handle(): mixed
    {
        if ($this->window === null) {
            throw new \RuntimeException('No window created. Call run() or createWindow() first.');
        }
        return $this->window;
    }

    // ── 渲染 ──

    /** 注册一个"每帧求值"的视图函数。 */
    public function view(callable $builder): void
    {
        $this->views[] = $builder;
    }

    /** 渲染一棵 Element 树（一次性，非每帧）。 */
    public function render(Element $tree): void
    {
        \gpui_render($this->handle(), $tree->toArray());
    }

    /** 离屏渲染当前窗口内容存成 PNG（$w/$h 逻辑点，内部 2x）。供 --shot 视觉验收。 */
    public function snapshot(string $path, int $w = 800, int $h = 600): bool
    {
        return (bool) \gpui_snapshot($this->handle(), $path, $w, $h);
    }

    /** 本帧最近一次 catalog（registered）组件构造/方法失败的原因；无错误返回空串。 */
    public function registeredError(): string
    {
        return (string) \gpui_last_error($this->handle());
    }

    // ── 事件 ──

    /**
     * 注册元素事件处理器。$type 见事件表（click/change/hover/key_down/...）。
     *
     * 处理器可写成 `function () {...}`（不关心事件内容）或
     * `function (array $event) {...}`（需要读 value/payload）。
     * 参数个数在注册时探测（AOT 下闭包实参个数必须精确匹配）。
     */
    public function on(string $id, string $type, callable $handler): void
    {
        $this->handlers[$id][$type] = [$handler, $this->arityOf($handler)];
    }

    /** 注册通配处理器，接收所有元素的该类型事件。 */
    public function onAny(string $type, callable $handler): void
    {
        $this->handlers['*'][$type] = [$handler, $this->arityOf($handler)];
    }

    private function arityOf(callable $handler): int
    {
        try {
            // 方法数组走 ReflectionMethod，其余走 ReflectionFunction。用两个独立
            // 变量：AOT 类型推断不允许同一变量先绑定 ReflectionMethod 再 ReflectionFunction。
            if (is_array($handler)) {
                $methodRef = new \ReflectionMethod($handler[0], $handler[1]);
                return $methodRef->getNumberOfRequiredParameters();
            }
            $functionRef = new \ReflectionFunction($handler);
            return $functionRef->getNumberOfRequiredParameters();
        } catch (\Throwable) {
            return 1;
        }
    }

    /** 处理单个事件（测试可直接调用）。 */
    public function dispatch(array $event): void
    {
        $this->handleEvent($event);
    }

    // ── 取值 ──

    /** 读取输入框当前值。 */
    public function text(string $id): string
    {
        return (string) \gpui_input_value($this->handle(), $id);
    }

    /** 程序化设置输入框文本（供自检/预填）。 */
    public function setInput(string $id, string $value): bool
    {
        return (bool) \gpui_set_input_value($this->handle(), $id, $value);
    }

    // ── Overlay：toast / dialog ──

    /** 弹一条 toast。$level = info|success|warning|error；$timeoutMs<=0 用默认。 */
    public function toast(string $title, string $desc = '', string $level = 'info', int $timeoutMs = 0): void
    {
        \gpui_toast($this->handle(), $title, $desc, $level, $timeoutMs);
    }

    /** 用一棵 Element 树打开 dialog，返回打开后的 dialog 数。 */
    public function openDialog(Element $tree, bool $escape = true, bool $backdrop = true): int
    {
        return (int) \gpui_open_dialog($this->handle(), $tree->toArray(), $escape, $backdrop);
    }

    public function dialogCount(): int
    {
        return (int) \gpui_dialog_count($this->handle());
    }

    public function closeDialog(): bool
    {
        return (bool) \gpui_close_dialog($this->handle());
    }

    /**
     * 从窗口边缘滑出 Sheet（同一时刻只有一个，后开的替换先开的），返回 1 表示已打开。
     * $anchor = top|bottom|left|right。
     */
    public function openSheet(Element $tree, string $anchor = 'right'): int
    {
        self::oneOf('openSheet', $anchor, self::SHEET_ANCHORS);
        return (int) \gpui_open_sheet($this->handle(), $tree->toArray(), $anchor);
    }

    public function closeSheet(): bool
    {
        return (bool) \gpui_close_sheet($this->handle());
    }

    public function hasSheet(): bool
    {
        return (bool) \gpui_has_sheet($this->handle());
    }

    /**
     * 装原生 app 菜单条（macOS 系统菜单栏，非窗口内）。
     * $menus = [['name'=>'File','items'=>[['label'=>'New','action'=>'file.new'],['separator'=>true],...]], ...]
     * 带 action 的项点击后经基石旁路回 PHP：App->on($action, 'action', $handler)。返回装入菜单数。
     */
    public function setAppMenus(array $menus): int
    {
        return (int) \gpui_set_app_menus($this->handle(), $menus);
    }

    /** 读回已装 app 菜单数（验证安装）。 */
    public function appMenuCount(): int
    {
        return (int) \gpui_app_menu_count($this->handle());
    }

    public function checked(string $id): bool
    {
        return (bool) \gpui_element_value($this->handle(), $id);
    }

    // ── 定时器 ──

    /** 注册周期定时器，到点收到 ['type'=>'timer','id'=>$id] 事件。 */
    public function setTimer(string $id, int $intervalMs): void
    {
        \gpui_set_timer($this->handle(), $id, $intervalMs);
    }

    // ── 主题 ──

    public function setTheme(string $mode): void
    {
        \gpui_set_theme($this->handle(), $mode);
    }

    // ── 系统能力 ──

    /** 读系统粘贴板文本；空剪贴板或内容非文本返回 null。 */
    public function clipboardText(): ?string
    {
        $s = (string) \gpui_clipboard_text($this->handle());
        return $s === '' ? null : $s;
    }

    public function setClipboardText(string $text): void
    {
        \gpui_set_clipboard_text($this->handle(), $text);
    }

    /**
     * 读剪贴板的完整一项：['text'=>?string,'image'=>?string,'paths'=>string[]]。
     * 一次快照读全所有表示，所以三者可能同时有。
     *
     * - image 是**已编码的图像流**（mac 上先取 PNG，没有再取 TIFF，所以一张截图拿到的
     *   是 TIFF 字节），不是 RGBA。要位图请交给 GD/Imagick 解。
     * - paths 是复制的文件绝对路径。gpui 只在 Windows 上读这个（CF_HDROP）；mac 上由
     *   我们的 shim 直接读 NSPasteboard 的文件 URL 补齐，形状与 Windows 一致。
     *
     * @return array{text:?string,image:?string,paths:array<int,string>}
     */
    public function clipboardItem(): array
    {
        /** @var array{flags?:int,text?:string,image?:string,paths?:array<int,mixed>} $raw */
        $raw = \gpui_clipboard_item($this->handle());
        $flags = (int) ($raw['flags'] ?? 0);

        $paths = [];
        if (($flags & 4) !== 0) {
            foreach ($raw['paths'] ?? [] as $p) {
                $paths[] = (string) $p;
            }
        }
        return [
            'text' => ($flags & 1) !== 0 ? (string) ($raw['text'] ?? '') : null,
            'image' => ($flags & 2) !== 0 ? (string) ($raw['image'] ?? '') : null,
            'paths' => $paths,
        ];
    }

    /**
     * 原生文件/目录选择对话框，返回选中的单个路径；取消返回 null。
     *
     * ⚠ 只能在事件回调里调：对话框自带嵌套消息循环，一直跑到用户选完。
     * 渲染期间调用会在一次 render 里重入平台模态循环。
     */
    public function promptForPath(bool $directories = false, string $title = ''): ?string
    {
        $p = (string) \gpui_prompt_for_path($this->handle(), $directories, $title);
        return $p === '' ? null : $p;
    }

    /**
     * 交给系统浏览器。URL 先过 gpui 自己的白名单（IsOpenableUrl），
     * javascript: 之类被拒时不打开并返回 false。无窗口参数：这是 app 级动作。
     */
    public function openUrl(string $url): bool
    {
        return (bool) \gpui_open_url($url);
    }

    /** 开/关窗口 inspector（DevTools），与 ⌘I 同一个。 */
    public function toggleInspector(): void
    {
        \gpui_toggle_inspector($this->handle());
    }

    /**
     * 弹帧率 HUD。$anchor 见 self::FPS_ANCHORS。
     * HUD 由窗口根持有：已在位时重复调用只是移动它，隐藏再显示保留历史。
     */
    public function showFpsHud(string $anchor = 'top_right'): bool
    {
        self::oneOf('showFpsHud', $anchor, self::FPS_ANCHORS);
        return (bool) \gpui_show_fps($this->handle(), $anchor);
    }

    /** 收起帧率 HUD；原本没弹返回 false。 */
    public function hideFpsHud(): bool
    {
        return (bool) \gpui_hide_fps($this->handle());
    }

    public function fpsHudVisible(): bool
    {
        return (bool) \gpui_fps_visible($this->handle());
    }

    /**
     * 内嵌浏览器历史后退（页内 history.back()）。$id 是 El::webview() 给的那个 id；
     * 该 id 还没渲染出 webview 时返回 false。换地址不用这里——改 El::webview($url, $id)
     * 的 $url 即可，同一 id 复用同一个控件、走 load 而不是重建。
     */
    public function webviewBack(string $id): bool
    {
        return (bool) \gpui_webview_nav($this->handle(), $id, -1);
    }

    /** 内嵌浏览器历史前进。见 webviewBack()。 */
    public function webviewForward(string $id): bool
    {
        return (bool) \gpui_webview_nav($this->handle(), $id, 1);
    }

    /**
     * 读内嵌浏览器**控件自己**报的状态：['ok'=>bool,'url'=>string,'w'=>float,'h'=>float]。
     * w/h 是元素布局那一帧真正应用给 OS 控件的盒子（逻辑点），不是 PHP 侧记的数。
     *
     * 为什么需要它：webview 是盖在窗口上的 OS 控件、不是 gpui 画的，离屏截图
     * （cacheDisplayInRect）合成不了跨进程表面，所以 --shot 里它那块是空白。空白既
     * 可能是"控件没建成"也可能是"建成了但抓不到"——这条把它们分开。在屏是否真显示
     * 仍需人看。
     *
     * @return array{ok:bool,url:string,w:float,h:float}
     */
    public function webviewProbe(string $id): array
    {
        /** @var array{ok?:bool,url?:string,w?:float,h?:float} $raw */
        $raw = \gpui_webview_probe($this->handle(), $id);
        return [
            'ok' => (bool) ($raw['ok'] ?? false),
            'url' => (string) ($raw['url'] ?? ''),
            'w' => (float) ($raw['w'] ?? 0),
            'h' => (float) ($raw['h'] ?? 0),
        ];
    }

    // ── 缺口服务：HTTP / 目录监听 / 解压 ──

    /**
     * 发一个异步 HTTP GET。$id 是这次请求的标识，应答落地时收到
     * ['type'=>'http','id'=>$id] 事件，再用 httpResponse($id) 读内容。
     * 走 gpui 的异步路（HttpSend 阻塞，绝不能在渲染期间调）。url 必须是 http(s)，
     * 否则返回 false 且不发请求。
     */
    public function httpGet(string $url, string $id): bool
    {
        return (bool) \gpui_http_get($this->handle(), $id, $url);
    }

    /**
     * 取回并丢掉一条已落地的应答：['ok'=>bool,'status'=>int,'body'=>string,'contentType'=>string]。
     * 没有这个 id（或已经读过）返回 null。body 是原始字节，二进制安全。
     *
     * @return array{ok:bool,status:int,body:string,contentType:string}|null
     */
    public function httpResponse(string $id): ?array
    {
        /** @var array{ok?:bool,status?:int,body?:string,contentType?:string} $raw */
        $raw = \gpui_http_response($this->handle(), $id);
        if ($raw === []) {
            return null;
        }
        return [
            'ok' => (bool) ($raw['ok'] ?? false),
            'status' => (int) ($raw['status'] ?? 0),
            'body' => (string) ($raw['body'] ?? ''),
            'contentType' => (string) ($raw['contentType'] ?? ''),
        ];
    }

    /**
     * 监听一个目录（非递归）。有文件被创建/写入/改名/删除时收到
     * ['type'=>'dirChange','id'=>$id]；一串连续变更由 gpui 合并成一次通知，
     * 且不报告是哪个文件——上游唯一的用法（主题热重载）就是整目录重扫。
     *
     * @return int watch id（>0）；失败返回 0
     */
    public function watchDir(string $dir, string $id, bool $create = false): int
    {
        return (int) \gpui_watch_dir($this->handle(), $id, $dir, $create);
    }

    public function unwatchDir(int $watchId): void
    {
        \gpui_unwatch_dir($this->handle(), $watchId);
    }

    /**
     * 解 zlib（$gzip=false）或 gzip（$gzip=true）的字节；解不开返回 null。
     *
     * 为什么要桥它：TypePHP nano 产物里没有 ext/zlib（`hello --caps` 实测
     * gzcompress/gzdecode 全空）。压缩侧故意不接——gpui 的 ZlibDeflate 写 stored 块，
     * 只加壳不压缩，接过来是个骗人的 API。
     */
    public static function inflate(string $data, bool $gzip = false): ?string
    {
        /** @var array{ok?:bool,data?:string,error?:string} $raw */
        $raw = \gpui_inflate($data, $gzip);
        if (!($raw['ok'] ?? false)) {
            return null;
        }
        return (string) ($raw['data'] ?? '');
    }

    /**
     * 机器状态快照：
     * `['cpu','memPct','memTotal','memUsed','diskPct','diskTotal','diskUsed',
     *   'ncpu','batteryPresent','batteryCharging','batteryPct','procs']`
     * 百分比都是 0..100（cpu 是全机跨核归一后的占用），字节是 B，disk 指**启动卷**。
     * procs 每条是 `['pid','name','cpu','memory']`。
     *
     * ⚠ `cpu` 与 `procs[].cpu` 是**两次采样之间**的差值：第一次调用报 0。要真数字就
     * 采两次（中间泵一下循环），或者用 setTimer 周期采——状态在原生侧是进程内一份，
     * 所以任何一处调用都在推进那个"上一次"。
     *
     * $topProcs>0 才带进程列表（gpui 一次采样列出全机几百个进程，前 N 条由原生侧按
     * CPU 降序截，最多 256）。这是静态方法：机器级的读数不属于某个窗口。
     *
     * @return array<string,mixed>
     */
    public static function sysInfo(int $topProcs = 0): array
    {
        return \gpui_sys_info($topProcs);
    }

    // ── 事件循环 ──

    /**
     * 运行事件循环，直到窗口关闭。
     *
     * 每帧顺序：泵 gpui 事件 → 分派给处理器（改状态）→ 按新状态渲染。
     * 先处理再渲染，事件引起的状态变化在同一帧可见。
     */
    public function loop(int $maxFrames = 0): void
    {
        $this->running = true;
        $this->frames = 0;

        while ($this->running && \gpui_window_is_open($this->handle())) {
            if ($maxFrames > 0 && $this->frames >= $maxFrames) {
                break;
            }
            $this->frames++;

            // 1. 泵事件并分派
            \gpui_pump_events($this->handle());
            $this->drainEvents();

            // 2. 按最新状态渲染每个 view
            foreach ($this->views as $builder) {
                $tree = $builder();
                if ($tree instanceof Element) {
                    \gpui_render($this->handle(), $tree->toArray());
                }
            }
        }

        $this->running = false;
    }

    /** 跑固定帧数后退出，用于无头截图。 */
    public function runFrames(int $frames = 3): void
    {
        $this->loop($frames);
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function close(): void
    {
        \gpui_window_close($this->handle());
    }

    public function isOpen(): bool
    {
        return $this->window !== null && \gpui_window_is_open($this->window);
    }

    public function frameCount(): int
    {
        return $this->frames;
    }

    public function lastError(): string
    {
        return $this->lastError;
    }

    // ── 内部 ──

    /**
     * @param list<string> $allowed
     */
    private static function oneOf(string $method, string $value, array $allowed): void
    {
        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException(
                "App::$method(): '$value' 非法，允许 [" . implode(', ', $allowed) . ']'
            );
        }
    }

    private function drainEvents(): void
    {
        while (true) {
            $event = \gpui_poll_event($this->handle());
            if (empty($event)) {
                break;
            }
            $this->handleEvent($event);
        }
    }

    private function handleEvent(array $event): void
    {
        $id = (string) ($event['id'] ?? '');
        $type = (string) ($event['type'] ?? '');

        if ($id !== '' && isset($this->handlers[$id][$type])) {
            $this->safely($this->handlers[$id][$type], $event);
        }
        if (isset($this->handlers['*'][$type])) {
            $this->safely($this->handlers['*'][$type], $event);
        }
    }

    /**
     * 按注册时探测到的参数个数调用处理器。
     * AOT 下闭包实参个数必须精确匹配。
     *
     * @param array{0: callable, 1: int} $entry
     */
    private function safely(array $entry, array $event): void
    {
        [$handler, $arity] = $entry;
        $this->lastError = '';
        try {
            if ($arity === 0) {
                $handler();
            } else {
                $handler($event);
            }
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
        }
    }
}
