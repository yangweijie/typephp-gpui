<?php

declare(strict_types=1);

/**
 * examples/_shared/state.php — 所有示例共用的可变状态表。
 *
 * 为什么单独一支文件：各功能域独立示例（examples/input 等）要共用同一批
 * 节构造器（examples/_shared/features/*.php），而构造器读的就是这张表。表在示例目录里，
 * 独立示例就得把整个画廊拖进编译；放在 _shared，两边都只列自己需要的那几份源。
 * nano 不支持 require/include，"共用"完全靠 yml 的 sources 列表体现——所以这张表必须能被
 * 任意子集单独编译，它也确实可以：这里只有函数与类声明，顶层不调任何 gpui_* 原生函数。
 *
 * 为什么不是"main() 里的一个数组 + 到处 use (&$state)"：nano 编译下，把固定类型变量
 * （php::Array）按引用交给一个动态调用会得到 RefWrap<php::Array>，它在 commit() 时
 * 直接 abort（typephp_helper.h:124）。事件处理器都是动态调用，所以引用式状态在
 * 这条路上走不通；静态表既能被处理器写，又能被每帧的 view 读到，不需要任何引用。
 *
 * 类名里的 "Example" 而非 "Showcase"：它服务的是一整族示例，不再只属于画廊。
 */

/** 每节的初始状态（示例建状态表时合并）。 */
function exampleState(): array
{
    return [
        'count' => 0, 'name' => '', 'vol' => 30, 'agree' => false, 'lastBtn' => '—',
        // NumberInput 的 ± 步进事件值（increment/decrement）。
        'lastStep' => '—', 'split' => '—',
        // choices 域：ch-select 声明成"开着"才谈得上 confirm/dismiss（画廊里恒 false）。
        'selectOpen' => false,
        'lastDd' => '—', 'sidebarCollapsed' => false, 'dialogCount' => 0,
        'hasSheet' => false, 'sheetAnchor' => '—', 'notifyCount' => 0, 'clip' => '',
        'picked' => '', 'sysLog' => [], 'http' => '', 'watchId' => 0, 'watchHits' => 0,
        'inflate' => '', 'sysInfo' => ['（还没采样）'], 'hud' => false, 'theme' => 'light',
        'probe' => '', 'note' => '', 'hover' => '—', 'key' => '—',
        'size' => '—', 'collapse' => 'icon', 'align' => 'start', 'placement' => '—',
        'fpsAnchor' => '—', 'booted' => false,
        'trayId' => 0, 'hotkeyId' => 0, 'hotkeyHits' => 0, 'hotkeyMods' => '—',
        'dock' => '', 'osNotify' => '—', 'menuCount' => 0, 'trace' => false,
        'front' => true, 'frontTries' => 0, 'evSeen' => [],
        // 像素/自检路径置 true：内嵌域那颗加载真地址的 webview 换成同几何的本地页。
        // 基线里不能含"外网页面这次加载进来没有"——那既不确定，又让门禁每跑一次
        // 就打一次外部请求（见 examples/embedded 的 runShot 注释）。
        'offline' => false,
        // data 域的虚拟列表：PHP 闭包被要过几批、几行、是否要过第 0 行。
        'listBatches' => 0, 'listRows' => 0, 'listSawFirst' => false, 'listRanges' => '',
        'dockChanges' => 0, 'dockButton' => false,
        'listMaxStart' => 0, 'listRowHandlers' => true,
        'listClicked' => '', 'listRight' => '', 'listItem' => '', 'listSecondary' => [],
    ];
}

final class ExampleState
{
    /** @var array<string, mixed> */
    public static array $v = [];

    public static function boot(array $over = []): void
    {
        self::$v = array_merge(exampleState(), ['section' => 1], $over);
    }

    /** 当前状态快照，给 builder 当参数用。 */
    public static function get(): array
    {
        return self::$v;
    }
}
