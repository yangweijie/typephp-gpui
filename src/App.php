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
    private int $renders = 0;
    private bool $dirty = true;
    private int $foregroundBudget = 0;
    private int $foregroundUsed = 0;
    private string $lastError = '';

    /** Sheet 可滑出的边。 */
    public const SHEET_ANCHORS = ['top', 'bottom', 'left', 'right'];

    /** 帧率 HUD 的位置，取自 gpui 的 FpsAnchorNames()。 */
    public const FPS_ANCHORS = [
        'top_left', 'top_right', 'bottom_left', 'bottom_right',
        'top_center', 'bottom_center', 'left_center', 'right_center',
    ];

    /** 通知的类型，与 toast() 的 $level 同一套字面。 */
    public const NOTICE_TYPES = ['info', 'success', 'warning', 'error'];

    /** 通知堆叠的角落（gpui 的 Anchor，默认 top_right）。 */
    public const NOTICE_PLACEMENTS = self::FPS_ANCHORS;

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
        // 走 view() 而不是直接压数组：那里有"首帧多渲染一遍把命中表备齐"这一步。
        $this->view($view);
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
        // 首帧建不出命中表：实测只跑 1 帧时真点击产生 0/38 个事件，跑到第 2 帧就稳定 18/38
        // 且 nav-* 真的到达 PHP。窗口此刻已经在了，所以这里立刻多渲染一遍，把命中表在第一帧
        // 之后就备齐——不然用户在"刚启动那几帧"里点什么都没反应，而画面上看着一切正常。
        if ($this->window !== null) {
            $tree = $builder();
            if ($tree instanceof Element) {
                $this->render($tree);
            }
        }
    }

    /** 渲染一棵 Element 树（一次性，非每帧）。 */
    public function render(Element $tree): void
    {
        \gpui_render($this->handle(), $tree->toArray());
    }

    /**
     * 真输入验收缝：把一次点击/移动/按键交给 AppKit 的正常派发路径
     * （-[NSWindow sendEvent:]），于是 hit-test、behavior 解析、focus 迁移都真的走一遍。
     * 返回 true 表示这一趟 PHP 队列里确实多出了事件——也就是"元素真的挂上了监听器"。
     *
     * 与 --selftest 里那条合成缝（gpui_test_event）的区别是要害：合成缝按 callbackId
     * 直接入队，**绕过监听器到底挂没挂**。bridge 曾经把 "on_click" 剥成 "click" 再交给
     * materialize，而 materialize 逐字比较 op.name=="on_click"，于是真实鼠标一个事件都
     * 进不来，而 21 条自检链路全绿。所以交互能力的地基只认这三个方法。
     *
     * 坐标是视图坐标（左上原点、整数逻辑点），与 snapshot 同一套。事件仍要下一帧才派发，
     * 所以调用后得 loop(1) 以上。
     */
    public function realClick(int $x, int $y): bool
    {
        return \gpui_real_mouse($this->handle(), $x, $y, 1) === 1;
    }

    public function realMouseMove(int $x, int $y): bool
    {
        return \gpui_real_mouse($this->handle(), $x, $y, 0) === 1;
    }

    /**
     * 窗口/应用状态：active（NSApp 是否前台）、key（窗口是否钥匙窗口）、visible、main、
     * bundled（进程有没有 .app bundle 身份），外加 nsappDeliveries——realClick/realKey 里
     * 已经走真路由（NSApp 队列 → nextEventMatchingMask → -[NSApp sendEvent:]）的事件条数，
     * 单调累加。
     * 真实鼠标/键盘能不能落地就看前四个数——钥匙窗口收不到 keyDown，非 active 应用的
     * 第一次点击可能被激活动作吃掉。把它读出来才能在"代码里全绿、手里点不动"时定位到底
     * 哪一环没成立；nsappDeliveries 则用来说明这份绿是哪条路给的：0 表示事件缝退回了
     * 直投 -[NSWindow sendEvent:]，跳过了 NSApplication 的路由判定。
     */
    public function windowState(): array
    {
        return \gpui_window_state($this->handle());
    }

    /**
     * 有界地再申请一次前台并把窗口升成 key，返回是否已经拿到（active && key）。
     *
     * 为什么需要它而不是一次性 activate：前台是**和当时握着焦点的一方竞争**出来的状态，
     * 实测同一个 bundle 二进制一次启动量到 active=true、另一次量到 false——申请的回执要
     * 靠 runloop 走几拍才落地，所以这里内部已经跑了几拍，调用方按帧重试即可。
     *
     * 边界（别当成万能药）：没有 `.app` bundle 身份的裸可执行文件，这里补上
     * `SetFrontProcessWithOptions` 也照样 active=false（实测），真前台只能从 bundle 起，
     * 见 `bin/gpui` 的 appBundle。
     */
    public function activate(): bool
    {
        return \gpui_activate($this->handle()) === 1;
    }

    /**
     * 输入框诊断：gpui 在 paint 里登记过几颗 input，以及第 $index 颗的 inputBounds
     * （-1 = 最后一颗，越界则四值为 0），外加 hits 条数。
     * 点击能不能聚焦取决于这张框，所以"打不进字"先分清三种可能：根本没登记（渲染侧）、
     * 框不在那个坐标（换算）、框在但 press 没走到（分发）。
     * 按索引取，是为了能把屏上**每一颗**输入框都点一遍验一遍，而不是只验最后一颗。
     */
    public function inputProbe(int $index = -1): array
    {
        return \gpui_input_probe($this->handle(), $index);
    }

    /**
     * 命中表探针：读出 gpui 点击 hit-test 真的查那张表（paint.hits）的第 $index 条矩形。
     * 真实鼠标先过这张表才轮得到 behavior/回调，所以"那一行点不动"在这里只有三种读法：
     * 表里根本没这条（绘制侧没登记）、这条不在那个位置（坐标换算）、位置对但 click=0
     * （监听器没挂上）。按 y 扫屏幕猜行位置会被这些情况全部伪装成"点不动"。
     */
    public function hitProbe(int $index = -1): array
    {
        return \gpui_hit_probe($this->handle(), $index);
    }

    /**
     * 焦点探针：gpui 认为当前持有焦点的实体 id（0 = 没有，-1 = 窗口不可用）。
     * 真键盘只发给有焦点的元素，所以"打不进字"要先分清是没聚焦还是按键没到。
     */
    public function focusedId(): int
    {
        return \gpui_focused_id($this->handle());
    }

    /**
     * 合成一次 ShellAction 派发（按名字）。菜单行的动作以 `action` 事件回到 PHP，
     * id 就是 MenuItem 写的那个 action 名；原生菜单条在窗口之外，真点击够不到，
     * 所以这条缝是给门禁覆盖"监听器 → shim 钩子 → PHP"整条路用的。
     */
    public function dispatchAction(string $name): bool
    {
        return \gpui_dispatch_action($this->handle(), $name) === 1;
    }

    /** keycode 是 Carbon 的 kVK_*（如 kVK_ANSI_G = 9）；chars 是那个键产出的字符。 */
    public function realKey(int $keycode, string $chars = ''): bool
    {
        return \gpui_real_key($this->handle(), $keycode, $chars) === 1;
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

    /**
     * 当前生效的焦点陷阱 id，0 = 没有。
     *
     * dialog/sheet 的键盘行为（Escape 关框、Tab 只在框内走）都以"焦点在不在陷阱里"
     * 为前提，所以这个读数比"按 Escape 看它关不关"更早定位问题（#62 就卡在这）。
     */
    public function focusTrap(): int
    {
        return (int) \gpui_focus_trap($this->handle());
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
     * 带 action 的项被点到时，动作以 `action` 事件回到 PHP：App->onAny('action', fn)。返回装入菜单数。
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

    /**
     * 原生菜单条各行携带的动作 id（按行号顺序，无动作的行不计入）。
     *
     * 为什么要读这张表：系统菜单栏不在本进程那扇窗口里，注入式 NSEvent 到不了，
     * "真点一下原生菜单行"这一跳没法进门禁；能进的是"行里装的确实是 PHP 声明的那个 id"
     * （本方法）与"该 id 的动作会回到 PHP"（dispatchAction()）。两段合起来覆盖整条链，
     * 只差 NSMenuItem 的 target/action——那是上游自己的代码。
     */
    public function menuRows(): array
    {
        return (array) \gpui_menu_rows();
    }

    /**
     * 内部各容器的条数（诊断/回归用）：内存增长类修复的仪表。
     * 例：同一个 id 反复 setTimer 之后 timerKeys 必须还是 1，而不是每挂一次加一条。
     *
     * 末两项是**进程级**的：`boxesAlive`（活着的窗口/overlay 包装数）与 `viewsAlive`
     * （view→包装 反查表的键数）。overlay 的回收只能靠这两个数证明——`dialogs` 读的是
     * vector 长度，而 pop 在调用方做，回收与否它都是 0。
     *
     * 注意它有副作用：读之前会先把 overlay 包装与上游真实状态对齐（dialog/sheet 可以被
     * Escape/点背板**原生**关掉，那条路不经过本 shim），否则仪表会报一个马上就被纠正的
     * 假状态。
     */
    public function stateCounts(): array
    {
        return (array) \gpui_state_counts($this->handle());
    }

    /**
     * 某元素最近一次随 `change` 上报的值（checkbox/switch 是 bool，slider 是数，input 是串）。
     * 从没上报过返回 null——注意 null 与 false 是两回事，别把"没读过"当成"没勾上"。
     */
    public function elementValue(string $id): mixed
    {
        return \gpui_element_value($this->handle(), $id);
    }

    /** 开关/勾选框的布尔值；没上报过时为 false。要看"是否报过"用 elementValue()。 */
    public function checked(string $id): bool
    {
        return (bool) \gpui_element_value($this->handle(), $id);
    }

    /**
     * 回读 spec 里该元素的序号槽（TableRow 的行号 / TableHead·TableCell 的列号）。
     *
     * 这是自检用的读数，不是给应用逻辑用的：aria 行/列号最终落在物化后的 El 上，
     * PHP 侧看不到，能确证的只有"我们写进去的号进了 spec"。查无此 id 返回 -1，
     * 以便与"有、且号是 0"区分。
     */
    public function specIndex(string $id): int
    {
        return (int) \gpui_spec_index($this->handle(), $id);
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

    // ── 授权文件系统 / 进程 / 存储（Capabilities）──
    //
    // gpui 的 FsRun 不吃 ambient 路径：它要 (root, relative) 两半，root 必须是事先授予过的
    // 目录，遍历时逐级 openat + O_NOFOLLOW。所以先 grantRoot()，之后每个调用内部自己选根。
    // 失败原因留在 self::$capError 里，用 App::fsError() 看。

    private static string $capError = '';

    /** 授予一个根目录（$writable=true 是读写根）。返回该类根的数量，0 = 失败。 */
    public static function grantRoot(string $dir, bool $writable = false): int
    {
        return (int) \gpui_grant_root($dir, $writable);
    }

    /** 命令白名单；$unrestricted=true 全部放行（那时 $commands 被忽略）。 */
    public static function grantExecute(array $commands, bool $unrestricted = false): void
    {
        \gpui_grant_execute($commands, $unrestricted);
    }

    public static function grantStorage(bool $allowed): void
    {
        \gpui_grant_storage($allowed);
    }

    /**
     * 组件 materialize 失败的读数：['count'=>自启动以来累计次数, 'last'=>最后一次原因]。
     * registered 组件建不出来时 gpui 不失败整帧，而是在画面上渲染成 "Failed to render X"，
     * 这个计数是宿主唯一能拿到"出过错"的地方（registeredError() 照不到 materialize 阶段）。
     */
    public static function componentFailure(): array
    {
        return \gpui_component_failure();
    }

    public static function storageGranted(): bool
    {
        return (bool) \gpui_storage_granted();
    }

    /** 存储落盘的位置（不给路径就只能 Set 到内存，重启就没了）。 */
    public static function storagePath(string $path): bool
    {
        $r = \gpui_storage_path($path);
        self::$capError = (string) ($r['error'] ?? '');

        return (bool) ($r['ok'] ?? false);
    }

    /** 上一次文件/进程/存储调用的失败原因；成功时是空串。 */
    public static function fsError(): string
    {
        return self::$capError;
    }

    /** 读一个授予过的路径；失败返回 null（原因看 fsError()）。 */
    public static function fsRead(string $path): ?string
    {
        $r = \gpui_fs_read($path);
        self::$capError = (string) ($r['error'] ?? '');
        if (!($r['ok'] ?? false)) {
            return null;
        }

        return (string) ($r['data'] ?? '');
    }

    public static function fsWrite(string $path, string $data): bool
    {
        $r = \gpui_fs_write($path, $data);
        self::$capError = (string) ($r['error'] ?? '');

        return (bool) ($r['ok'] ?? false);
    }

    /**
     * 列目录。返回 [['name'=>string,'dir'=>bool], ...]；失败 null。
     * $truncated 按引用回"是否被上限截断"（一次最多 2048 条）。
     *
     * @param-out bool $truncated
     */
    public static function fsList(string $path, ?bool &$truncated = null): ?array
    {
        $r = \gpui_fs_list($path);
        self::$capError = (string) ($r['error'] ?? '');
        $truncated = (bool) ($r['truncated'] ?? false);
        if (!($r['ok'] ?? false)) {
            return null;
        }

        return (array) ($r['entries'] ?? []);
    }

    public static function fsExists(string $path): bool
    {
        return (bool) \gpui_fs_exists($path);
    }

    /** $dir=true 删目录（$recursive 才生效），否则删文件。 */
    public static function fsRemove(string $path, bool $dir = false, bool $recursive = false): bool
    {
        $r = \gpui_fs_remove($path, $dir, $recursive);
        self::$capError = (string) ($r['error'] ?? '');

        return (bool) ($r['ok'] ?? false);
    }

    /** $dir=true 时递归建（多级）。 */
    public static function fsMkdir(string $path, bool $dir = true): bool
    {
        $r = \gpui_fs_mkdir($path, $dir);
        self::$capError = (string) ($r['error'] ?? '');

        return (bool) ($r['ok'] ?? false);
    }

    /**
     * 跑一个被授权的可执行文件，返回 ['code'=>int,'out'=>string,'err'=>string]；
     * 失败（不在白名单、起不来）返回 null。
     *
     * ⚠ **阻塞**：子进程跑完才返回（上限 30s、每路输出 8 MiB、子进程环境是空的），
     * 所以它和 promptForPath 一样只该在事件回调里调，不要在 App::view() 里调。
     */
    public static function processRun(string $command, array $args = []): ?array
    {
        $r = \gpui_process_run($command, $args);
        self::$capError = (string) ($r['error'] ?? '');
        if (!($r['ok'] ?? false)) {
            return null;
        }

        return [
            'code' => (int) ($r['code'] ?? -1),
            'out' => (string) ($r['out'] ?? ''),
            'err' => (string) ($r['err'] ?? ''),
        ];
    }

    public static function storageSet(string $key, string $value): bool
    {
        $r = \gpui_storage_set($key, $value);
        self::$capError = (string) ($r['error'] ?? '');

        return (bool) ($r['ok'] ?? false);
    }

    /** 没有这个键返回 null。 */
    public static function storageGet(string $key): ?string
    {
        $r = \gpui_storage_get($key);
        if (!($r['ok'] ?? false)) {
            return null;
        }

        return (string) ($r['value'] ?? '');
    }

    public static function storageRemove(string $key): bool
    {
        return (bool) \gpui_storage_remove($key);
    }

    public static function storageLen(): int
    {
        return (int) \gpui_storage_len();
    }

    // ── macOS 系统集成（托盘 / 全局快捷键 / Dock 角标 / OS 通知）──
    //
    // 这一组的自动化上限是"注册成功 + 事件打得回来"：托盘图标在不在菜单栏里、快捷键在
    // 别的 app 有焦点时是否仍触发、通知横幅有没有出现，都在窗口之外，机器看不见。

    /** Carbon 修饰键位（对齐 Events.h 的 cmdKey/shiftKey/optionKey/controlKey）。 */
    public const HOTKEY_MODS = [
        'cmd' => 256, 'shift' => 512, 'option' => 2048, 'control' => 4096,
    ];

    /**
     * 挂一个托盘项，返回 id（>0）；失败 0。
     *
     * $labels 非空时点击弹这个菜单，被点的文字作为 ['type'=>'tray','id'=>$id,'value'=>label]
     * 回来；$labels 为空时点击本身就是事件（value 为 null）。
     */
    public function tray(array $labels, string $id, string $tooltip = ''): int
    {
        return (int) \gpui_tray_add($this->handle(), $id, $tooltip, $labels);
    }

    /** 撤掉一个托盘项。窗口关闭时它名下的托盘项会被自动撤掉。 */
    public function removeTray(int $trayId): void
    {
        \gpui_tray_remove($this->handle(), $trayId);
    }

    /** 当前托盘项数量（app 级）。existence-green：只证明"没崩、没泄漏"。 */
    public static function trayCount(): int
    {
        return (int) \gpui_tray_count();
    }

    /**
     * 注册全局快捷键：$keycode 是 kVK_*（F13=105、F14=107），$mods 写名字数组
     * （['cmd','shift']），命中时回 ['type'=>'hotkey','id'=>$id]。返回 id（>0），失败 0。
     */
    public function onHotkey(string $id, int $keycode, array $mods = []): int
    {
        $bits = 0;
        foreach ($mods as $m) {
            if (!isset(self::HOTKEY_MODS[$m])) {
                throw new \InvalidArgumentException(
                    'onHotkey(): 不认识的修饰键 ' . $m
                    . '，可选：' . implode('/', array_keys(self::HOTKEY_MODS))
                );
            }
            $bits |= self::HOTKEY_MODS[$m];
        }
        return (int) \gpui_hotkey_register($this->handle(), $id, $keycode, $bits);
    }

    public function removeHotkey(int $hotkeyId): void
    {
        \gpui_hotkey_unregister($hotkeyId);
    }

    public static function hotkeyCount(): int
    {
        return (int) \gpui_hotkey_count();
    }

    /**
     * 测试接缝：合成第 $index 个已注册快捷键的事件，走完整条
     * "Carbon 队列 → 派发 → handler → 事件出口 → 窗口队列 → poll"。
     * 它验的是派发链，**不是**"系统真的全局捕获了这组键"——后者只能人按。
     */
    public static function fireHotkeyForTest(int $index): bool
    {
        return (bool) \gpui_hotkey_fire($index);
    }

    /** Dock 角标文字（空串清除）。 */
    public static function dockBadge(string $text): void
    {
        \gpui_dock_badge($text);
    }

    /**
     * 发一条 OS 通知。true = 已交给系统。
     *
     * ⚠ 不代表屏幕上出现了横幅：裸可执行文件没有 bundle id，macOS 会静默丢弃。
     * 要可靠出横幅得先打包成 .app（UNUserNotificationCenter + Info.plist + 签名）。
     */
    public static function osNotify(string $title, string $body): bool
    {
        return (bool) \gpui_os_notify($title, $body);
    }

    // ── 应用内通知中心 ──

    /**
     * 推一条应用内通知，返回它的 id（>0）；失败返回 0。
     *
     * 与 toast 的区别：通知有标题 + 正文 + 图标位、可堆叠成列表、可点、可被用户关掉，
     * 位置和 maxItems 由这一层自己管。$placement 见 self::NOTICE_PLACEMENTS。
     * $timeoutMs <= 0 表示不自动消失，得自己 dismissNotification()。
     *
     * $id 非空时，这条通知**被点**与**消失**分别送回 ['type'=>'click'|'close','id'=>$id]。
     * 注意 close 是"已从列表移除"（到期或 dismiss 完成），不是"用户按了关闭按钮"。
     * 在场时间是 400ms 入场 + $timeoutMs + 200ms 退场（动画由 50ms 墙钟计时器推），
     * 所以 close 比 $timeoutMs 晚约 200ms 到。
     *
     * ⚠ 得先往树里放一颗 `El::notifications()`，否则通知照样 push 成功、只是看不见
     * （状态在窗口层持有，画出来那一层要人挂——我们的窗口根是 ShellRoot，它只挂 Toast）。
     */
    public function notify(string $title, string $message = '', string $type = 'info',
                           string $placement = 'top_right', int $timeoutMs = 5000,
                           string $id = ''): int
    {
        self::oneOf('notify', $type, self::NOTICE_TYPES);
        self::oneOf('notify', $placement, self::NOTICE_PLACEMENTS);
        return (int) \gpui_notify($this->handle(), $id, $title, $message, $type,
                                  $placement, $timeoutMs);
    }

    /** 关掉一条通知（开始它的退场）。原本没有这条 id 返回 false。 */
    public function dismissNotification(int $notifyId): bool
    {
        return (bool) \gpui_dismiss_notification($this->handle(), $notifyId);
    }

    public function clearNotifications(): void
    {
        \gpui_clear_notifications($this->handle());
    }

    /** 当前条数（含正在退场的）。不代表"看得见"——没挂通知层时也有数。 */
    public function notificationCount(): int
    {
        return (int) \gpui_notification_count($this->handle());
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
        $first = true;
        $sig = null;

        while ($this->running && \gpui_window_is_open($this->handle())) {
            if ($maxFrames > 0 && $this->frames >= $maxFrames) {
                break;
            }
            $this->frames++;

            // 1. 泵事件并分派
            \gpui_pump_events($this->handle());
            $dispatched = $this->drainEvents();

            // 2. 抢前台的自举放在框架里而不是 app 的每帧回调里：下面第 3 步可能不画这一帧，
            //    交给 app 就会变成"没画→没尝试→永远不是前台"的死结。
            $this->pumpForeground();

            // 3. 脏判据：首帧 / 有事件 / 窗口状态变了（HUD 与"非前台"横幅就是它的函数）/
            //    app 自己 invalidate() 过。空闲窗口因此不再每帧重建整棵 PHP 树。
            $st = $this->windowState();
            $next = $st['active'] . '|' . $st['key'] . '|' . $st['visible'] . '|' . $st['main'];
            if (!($first || $this->dirty || $dispatched > 0 || $next !== $sig)) {
                continue;
            }
            $first = false;
            $sig = $next;
            $this->dirty = false;
            $this->renders++;

            foreach ($this->views as $builder) {
                $tree = $builder();
                if ($tree instanceof Element) {
                    \gpui_render($this->handle(), $tree->toArray());
                }
            }
        }

        $this->running = false;
    }

    /**
     * 让框架在头 $tries 帧里替 app 抢前台（每帧读一次窗口状态，成了就停）。
     * 默认不开——抢焦点是对用户手头工作的打扰，得 app 自己说要。
     */
    public function keepTryingForeground(int $tries = 12): void
    {
        $this->foregroundBudget = $tries;
    }

    /** 已经试了几次抢前台（0 = 没开这个能力）。 */
    public function foregroundTries(): int
    {
        return $this->foregroundUsed;
    }

    /** 真正重画过几回。与 frameCount() 对比就是脏门的证据：空闲时应远小于帧数。 */
    public function renderCount(): int
    {
        return $this->renders;
    }

    /**
     * 标脏：下一帧必须重画。
     * app 改了自家状态但没有任何事件（比如外部定时器算了点什么）时调它。
     */
    public function invalidate(): void
    {
        $this->dirty = true;
    }

    /** loop 每帧调一次：有预算、还没成前台才试；成功或预算用尽就收手。 */
    private function pumpForeground(): void
    {
        if ($this->foregroundBudget <= 0) {
            return;
        }
        $st = $this->windowState();
        if (!empty($st['active']) && !empty($st['key'])) {
            $this->foregroundBudget = 0;
            return;
        }
        if ($this->foregroundUsed >= $this->foregroundBudget) {
            return;
        }
        $this->foregroundUsed++;
        $this->activate();
        $this->dirty = true;   // 试过就要重画：横幅与 HUD 的文案取决于结果
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

    /** 排空事件队列并分派，返回**派发掉的事件数**（帧的脏判据之一）。 */
    private function drainEvents(): int
    {
        $n = 0;
        while (true) {
            $event = \gpui_poll_event($this->handle());
            if (empty($event)) {
                break;
            }
            $this->handleEvent($event);
            $n++;
        }
        return $n;
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
