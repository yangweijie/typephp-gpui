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
