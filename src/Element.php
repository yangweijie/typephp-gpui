<?php

declare(strict_types=1);

namespace TypePHP\Gpui;

/**
 * Element — gpui 元素树的 PHP 侧链式 builder。
 *
 * 每个 Element 实例对应一个 SpecNode：持有 kind（组件类型）、text/value、
 * 一个 ops[] 列表（样式/行为/回调操作）和 children[] 子元素。
 *
 * C++ 桥接层（gpui_bridge.cc）在 render 时把整棵 Element 树翻译成
 * SpecArena 的 Push/PushOp/Attach 调用序列，交给 ShellMaterializeSpec
 * 渲染成原生 El* 树。
 *
 * 用法：
 *   El::div()
 *     ->flexCol()->sizeFull()->gap(8)->itemsCenter()->justifyCenter()
 *     ->child(El::text('Hello')->font(16))
 *     ->child(El::button('Go')->primary()->onClick($handler));
 */
class Element
{
    /** @var string 组件类型，映射 shell::ComponentKind */
    public string $kind = 'div';

    /** @var string 主文本（Button label / Text content / id 等） */
    public string $text = '';

    /** @var string 值（TextView 的 markdown/html 内容） */
    public string $value = '';

    /** @var array<int, array<string, mixed>> 操作列表：nullary_style/param_style/method/callback/slot/registered_method，各 op 携带的键不同（args 或 child） */
    public array $ops = [];

    /** @var array<int, int|self> 子元素：通常是嵌套 Element，也可能是 bridge 回填的 specId */
    public array $children = [];

    /** @var mixed 不透明句柄（Input/Slider 等保留状态） */
    public mixed $handle = null;

    /** @var string 元素稳定 id（App->on(id,type) 用它路由事件） */
    public string $id = '';

    /** @var bool catalog（Registered）组件：kind 是导出名，走 gpui_shim_registered */
    public bool $registered = false;

    /** @var array<int, mixed> 构造器实参，按 descriptor 声明顺序（字符串/布尔/数字/数组/回调标记） */
    public array $ctorArgs = [];

    // ── catalog 枚举白名单（严格对齐 component_shell descriptor 的字面量表）──
    // 用途：sugar 方法在 render 前预校验，拼错的枚举立刻抛异常，而非等到 debug_tree。

    /** support.h kSizeLiterals（Alert/Icon/Form 的 size）。 */
    public const SIZES = ['xsmall', 'small', 'medium', 'large'];
    /** sidebar kSideLiterals。 */
    public const SIDES = ['left', 'right'];
    /** sidebar kModeLiterals（collapsible）。 */
    public const COLLAPSE_MODES = ['icon', 'offcanvas', 'none'];
    /** form kAlignLiterals（Field.align）。 */
    public const FIELD_ALIGN = ['start', 'center', 'end'];
    /** sidebar kIconLiterals（SidebarMenuItem.icon）。 */
    public const NAV_ICONS = ['home', 'components', 'settings', 'archive', 'account'];
    /** alert 变体 → 导出名映射。 */
    public const ALERT_VARIANTS = [
        'default' => 'Alert',
        'info' => 'InfoAlert',
        'success' => 'SuccessAlert',
        'warning' => 'WarningAlert',
        'error' => 'ErrorAlert',
    ];

    /** 枚举预校验：不在白名单即抛 InvalidArgumentException（含允许集）。 */
    private static function enumCheck(string $method, string $value, array $allowed): void
    {
        if (!in_array($value, $allowed, true)) {
            throw new \InvalidArgumentException(
                "Element::$method(): '$value' 非法，允许 [" . implode(', ', $allowed) . ']'
            );
        }
    }

    // ── 构造工厂 ──

    public static function div(): self
    {
        return new self();
    }

    public static function hFlex(array $children = []): self
    {
        $e = new self();
        $e->kind = 'h_flex';
        foreach ($children as $c) { $e->child($c); }
        return $e;
    }

    public static function vFlex(array $children = []): self
    {
        $e = new self();
        $e->kind = 'v_flex';
        foreach ($children as $c) { $e->child($c); }
        return $e;
    }

    public static function text(string $content): self
    {
        $e = new self();
        $e->kind = 'text';
        $e->text = $content;
        return $e;
    }

    public static function button(string $label): self
    {
        $e = new self();
        $e->kind = 'button';
        // gpui Button 的可见标签来自子元素（materialize: button->Child(...)），
        // 不是 component.text。
        $e->children[] = self::text($label);
        return $e;
    }

    public static function input(string $id = ''): self
    {
        $e = new self();
        $e->kind = 'input';
        $e->text = $id;
        return $e;
    }

    public static function textarea(string $id = ''): self
    {
        $e = new self();
        $e->kind = 'textarea';
        $e->text = $id;
        return $e;
    }

    public static function checkbox(string $label, bool $checked = false): self
    {
        $e = new self();
        $e->kind = 'checkbox';
        $e->text = $label;
        if ($checked) { $e->method('checked', [true]); }
        return $e;
    }

    public static function switchBox(string $label, bool $checked = false): self
    {
        $e = new self();
        $e->kind = 'switch';
        $e->text = $label;
        if ($checked) { $e->method('checked', [true]); }
        return $e;
    }

    /**
     * 内嵌浏览器（WKWebView / WebView2 / WebKitGTK 那一层）。$url 是地址，$id 是
     * 跨帧定位用的元素 id——同一个 id 复用同一个控件，换 url 走 load 而不是重建。
     *
     * ⚠ 它不是 gpui 画的：盖住元素树里排在它后面的东西、不随容器滚动、没有圆角，
     * 所以要给它自己的一块盒子。
     *
     * 不要链式 `->id()`：那会多记一个 method('id') op，而 ChildView 节点不接方法。
     */
    public static function webview(string $url, string $id = ''): self
    {
        $e = new self();
        $e->kind = 'webview';
        $e->value = $url;
        $e->id = $id;
        return $e;
    }

    /**
     * 内嵌浏览器，内容是一段本地 HTML（不联网、内容确定，才配当回归对照）。
     * 同一个 id 换 $html 会走 load 而不是重建控件；同一帧里 url 和 html 都给时以
     * url 为准。
     */
    public static function webviewHtml(string $html, string $id = ''): self
    {
        $e = new self();
        $e->kind = 'webview';
        $e->text = $html;
        $e->id = $id;
        return $e;
    }

    /**
     * 把 DevTools（元素 inspector）作为一块面板挂进自己的布局里。
     * 与 ⌘I / App::toggleInspector() 是同一套组件——但那个只是"拾取模式开关"，
     * 面板本体得由这里摆出来。story 里的用法就是把它停靠在右侧。
     */
    public static function inspector(string $id = ''): self
    {
        $e = new self();
        $e->kind = 'inspector';
        $e->id = $id;
        return $e;
    }

    public static function link(string $label, string $href = ''): self
    {
        $e = new self();
        $e->kind = 'link';
        $e->children[] = self::text($label);
        if ($href !== '') { $e->method('href', [$href]); }
        return $e;
    }

    public static function image(string $src, string $alt = ''): self
    {
        $e = new self();
        $e->kind = 'image';
        $e->text = $src;
        return $e;
    }

    public static function progress(float $value = 0.0): self
    {
        $e = new self();
        $e->kind = 'progress';
        $e->method('value', [$value]);
        return $e;
    }

    public static function slider(float $min = 0.0, float $max = 100.0, float $value = 0.0): self
    {
        $e = new self();
        $e->kind = 'slider';
        $e->method('value', [$value]);
        return $e;
    }

    public static function table(string $id = ''): self
    {
        $e = new self();
        $e->kind = 'table';
        $e->text = $id;
        return $e;
    }

    public static function list(string $id = ''): self
    {
        $e = new self();
        $e->kind = 'list';
        $e->text = $id;
        return $e;
    }

    public static function tabs(string $id = ''): self
    {
        $e = new self();
        $e->kind = 'tabs';
        $e->text = $id;
        return $e;
    }

    public static function tab(string $label): self
    {
        $e = new self();
        $e->kind = 'tab';
        $e->text = $label;
        return $e;
    }

    public static function textView(string $markdown, string $id = ''): self
    {
        $e = new self();
        $e->kind = 'text_view';
        $e->value = $markdown;
        $e->text = $id;
        return $e;
    }

    // ── 低复杂度 / Slot 消费组件 ──

    public static function svg(string $path): self
    {
        $e = new self();
        $e->kind = 'svg';
        $e->text = $path;
        return $e;
    }

    public static function radio(string $label, bool $checked = false): self
    {
        $e = new self();
        $e->kind = 'radio';
        $e->children[] = self::text($label);
        if ($checked) { $e->method('checked', [true]); }
        return $e;
    }

    public static function toggle(string $label): self
    {
        $e = new self();
        $e->kind = 'toggle';
        $e->children[] = self::text($label);
        return $e;
    }

    public static function collapsible(string $label, self $content): self
    {
        $e = new self();
        $e->kind = 'collapsible';
        $e->slot('header', self::text($label));
        $e->slot('panel', $content);
        return $e;
    }

    public static function popover(string $trigger, self $content): self
    {
        $e = new self();
        $e->kind = 'popover';
        $e->slot('trigger', self::text($trigger));
        $e->slot('content', $content);
        return $e;
    }

    public static function hoverCard(string $trigger, self $content): self
    {
        $e = new self();
        $e->kind = 'hover_card';
        $e->slot('trigger', self::text($trigger));
        $e->slot('content', $content);
        return $e;
    }

    public static function avatar(string $src): self
    {
        $e = new self();
        $e->kind = 'avatar';
        $e->slot('image', self::image($src));
        $e->slot('fallback', self::text(''));
        return $e;
    }

    public static function accordionItem(string $header, self $panel): self
    {
        $e = new self();
        $e->kind = 'accordion_item';
        $e->slot('header', self::text($header));
        $e->slot('panel', $panel);
        return $e;
    }

    public static function tableHeader(array $children = []): self
    {
        $e = new self();
        $e->kind = 'table_header';
        foreach ($children as $c) { $e->child($c); }
        return $e;
    }

    public static function tableBody(array $children = []): self
    {
        $e = new self();
        $e->kind = 'table_body';
        foreach ($children as $c) { $e->child($c); }
        return $e;
    }

    public static function select(string $id, array $options = []): self
    {
        $e = new self();
        $e->kind = 'select';
        $e->id = $id;
        foreach ($options as $o) { $e->child(self::text((string) $o)); }
        return $e;
    }

    public static function combobox(string $id, array $options = []): self
    {
        $e = new self();
        $e->kind = 'combobox';
        $e->id = $id;
        foreach ($options as $o) { $e->child(self::text((string) $o)); }
        return $e;
    }

    public static function datePicker(string $id): self
    {
        $e = new self();
        $e->kind = 'date_picker';
        $e->id = $id;
        return $e;
    }

    // ── C 类便捷工厂（kindFromString 已认这些 kind，bridge 走通用 push）──

    public static function numberInput(string $id = ''): self
    {
        $e = new self();
        $e->kind = 'number_input';
        $e->id = $id;
        return $e;
    }

    public static function otpInput(string $id = ''): self
    {
        $e = new self();
        $e->kind = 'otp_input';
        $e->id = $id;
        return $e;
    }

    public static function progressTrack(array $children = []): self
    {
        $e = new self();
        $e->kind = 'progress_track';
        foreach ($children as $c) { $e->child($c); }
        return $e;
    }

    public static function progressIndicator(): self
    {
        $e = new self();
        $e->kind = 'progress_indicator';
        return $e;
    }

    public static function tableHead(array $children = []): self
    {
        $e = new self();
        $e->kind = 'table_head';
        foreach ($children as $c) { $e->child($c); }
        return $e;
    }

    public static function tableCaption(string $text): self
    {
        $e = new self();
        $e->kind = 'table_caption';
        $e->text = $text;
        return $e;
    }

    public static function hResizable(array $children = []): self
    {
        $e = new self();
        $e->kind = 'h_resizable';
        foreach ($children as $c) { $e->child($c); }
        return $e;
    }

    public static function vResizable(array $children = []): self
    {
        $e = new self();
        $e->kind = 'v_resizable';
        foreach ($children as $c) { $e->child($c); }
        return $e;
    }

    public static function resizablePanel(?self $child = null): self
    {
        $e = new self();
        $e->kind = 'resizable_panel';
        if ($child !== null) { $e->child($child); }
        return $e;
    }

    public static function popup(): self
    {
        $e = new self();
        $e->kind = 'popup';
        return $e;
    }

    public static function radioGroup(array $children = []): self
    {
        $e = new self();
        $e->kind = 'radio_group';
        foreach ($children as $c) { $e->child($c); }
        return $e;
    }

    public static function toggleGroup(array $children = []): self
    {
        $e = new self();
        $e->kind = 'toggle_group';
        foreach ($children as $c) { $e->child($c); }
        return $e;
    }

    // ── catalog（Registered）组件工厂：kind 存【导出名】，走 gpui_shim_registered ──

    /** 内部：造一个 registered 元素（导出名 + 构造实参 + 可选 typed/ordinary 子元素）。 */
    private static function reg(string $export, array $ctorArgs = [], array $children = []): self
    {
        $e = new self();
        $e->registered = true;
        $e->kind = $export;
        $e->ctorArgs = $ctorArgs;
        foreach ($children as $c) { $e->child($c); }
        return $e;
    }

    /** Alert 家族：id+message 必填（id 非空），variant 选导出变体。Alert 拒 children。 */
    public static function alert(string $id, string $message, string $variant = 'default'): self
    {
        if (!isset(self::ALERT_VARIANTS[$variant])) {
            throw new \InvalidArgumentException(
                'Element::alert(): variant \'' . $variant . '\' 非法，允许 ['
                . implode(', ', array_keys(self::ALERT_VARIANTS)) . ']'
            );
        }
        return self::reg(self::ALERT_VARIANTS[$variant], [$id, $message]);
    }

    /** Breadcrumb：一条有序标签数组（Array<String> 构造实参）。 */
    public static function breadcrumb(array $labels): self
    {
        return self::reg('Breadcrumb', [$labels]);
    }

    /** Icon：从资源根下的相对路径加载 SVG。拒 children。size/color/rotate 走 rm/糖。 */
    public static function icon(string $path): self
    {
        return self::reg('Icon', [$path]);
    }

    public static function sidebar(string $id, array $menus = []): self
    {
        return self::reg('Sidebar', [$id], $menus);
    }

    public static function sidebarMenu(array $items = []): self
    {
        return self::reg('SidebarMenu', [], $items);
    }

    public static function sidebarMenuItem(string $label, array $children = []): self
    {
        return self::reg('SidebarMenuItem', [$label], $children);
    }

    public static function sidebarHeader(?self $child = null): self
    {
        return self::reg('SidebarHeader', [], $child === null ? [] : [$child]);
    }

    public static function sidebarFooter(?self $child = null): self
    {
        return self::reg('SidebarFooter', [], $child === null ? [] : [$child]);
    }

    public static function sidebarToggleButton(): self
    {
        return self::reg('SidebarToggleButton');
    }

    /** Form：纵向 Form / 横向 HForm，children 只收 Field。拒非 Field 的 typed children。 */
    public static function form(bool $horizontal = false, array $fields = []): self
    {
        return self::reg($horizontal ? 'HForm' : 'Form', [], $fields);
    }

    /** Field：普通控制元素子容器（input 等），label/description/required/align/col_span 走糖或 rm。 */
    public static function field(array $children = []): self
    {
        return self::reg('Field', [], $children);
    }

    public static function menu(string $label, array $items = []): self
    {
        return self::reg('Menu', [$label], $items);
    }

    /** MenuItem：label+action 两个非空字符串（action 是 ShellAction 名）。是数据节点，无自身盒子。 */
    public static function menuItem(string $label, string $action): self
    {
        return self::reg('MenuItem', [$label, $action]);
    }

    public static function menuSeparator(): self
    {
        return self::reg('MenuSeparator');
    }

    /** MenuBar：安装原生/窗口内菜单条，children 只收 Menu。是菜单家族里唯一可见成盒的。 */
    public static function menuBar(string $label, array $menus = []): self
    {
        return self::reg('MenuBar', [$label], $menus);
    }

    /** DropdownMenu：按钮触发的原生弹出菜单。id+label 非空；item 走 rm（回调经 cb 旁路）。 */
    public static function dropdownMenu(string $id, string $label): self
    {
        return self::reg('DropdownMenu', [$id, $label]);
    }

    /** 给 DropdownMenu 追加一项：label 显示文本，$id 事件路由 id（App->on($id,'click')），走 registered_method。 */
    public function item(string $label, string $id, string $type = 'click'): self
    {
        return $this->rm('item', [$label, self::cb($type, $id)]);
    }

    // ── catalog 组件常用方法糖（registered_method）；与静态工厂重名的用别名 ──

    public function title(string $s): self { return $this->rm('title', [$s]); }
    public function banner(): self { return $this->rm('banner', []); }
    public function description(string $s): self { return $this->rm('description', [$s]); }
    public function columns(float $n): self { return $this->rm('columns', [$n]); }
    public function labelWidth(float $w): self { return $this->rm('label_width', [$w]); }
    public function side(string $s): self { self::enumCheck('side', $s, self::SIDES); return $this->rm('side', [$s]); }
    public function collapsibleMode(string $mode): self { self::enumCheck('collapsibleMode', $mode, self::COLLAPSE_MODES); return $this->rm('collapsible', [$mode]); }
    public function collapsed(bool $v): self { return $this->rm('collapsed', [$v]); }
    public function navIcon(string $name): self { self::enumCheck('navIcon', $name, self::NAV_ICONS); return $this->rm('icon', [$name]); }
    public function rotate(float $rad): self { return $this->rm('rotate', [$rad]); }
    public function color(string $c): self { return $this->rm('color', [$c]); }
    public function align(string $a): self { self::enumCheck('align', $a, self::FIELD_ALIGN); return $this->rm('align', [$a]); }
    /** catalog 组件的语义尺寸（走 registered_method 'size'，枚举预校验）。 */
    public function sizeEnum(string $s): self { self::enumCheck('sizeEnum', $s, self::SIZES); return $this->rm('size', [$s]); }

    // ── 样式方法（nullary style，无参关键字）──

    public function flex(): self { return $this->style('flex'); }
    public function flexRow(): self { return $this->style('flex_row'); }
    public function flexCol(): self { return $this->style('flex_col'); }
    public function flexWrap(): self { return $this->style('flex_wrap'); }
    public function flex1(): self { return $this->style('flex_1'); }
    public function flexNone(): self { return $this->style('flex_none'); }
    public function itemsStart(): self { return $this->style('items_start'); }
    public function itemsCenter(): self { return $this->style('items_center'); }
    public function itemsEnd(): self { return $this->style('items_end'); }
    public function justifyStart(): self { return $this->style('justify_start'); }
    public function justifyCenter(): self { return $this->style('justify_center'); }
    public function justifyBetween(): self { return $this->style('justify_between'); }
    public function sizeFull(): self { return $this->paramStyle('size', ['100%']); }
    public function wFull(): self { return $this->paramStyle('w', ['100%']); }
    public function hFull(): self { return $this->paramStyle('h', ['100%']); }
    public function absolute(): self { return $this->style('absolute'); }
    public function relative(): self { return $this->style('relative'); }
    public function hidden(): self { return $this->style('hidden'); }
    public function truncate(): self { return $this->style('truncate'); }
    public function bold(): self { return $this->style('font_bold'); }
    public function semibold(): self { return $this->style('font_semibold'); }
    public function italic(): self { return $this->style('italic'); }
    public function underline(): self { return $this->style('underline'); }
    public function cursorPointer(): self { return $this->style('cursor_pointer'); }
    // 滚动是 behavior（method op），非 nullary style：materialize 的 ResolveBehavior
    // 认 overflow_y_scroll / overflow_x_scroll / overflow_scroll。gpui 原生滚动该容器。
    public function overflowYScroll(): self { return $this->method('overflow_y_scroll', []); }
    public function overflowXScroll(): self { return $this->method('overflow_x_scroll', []); }
    public function overflowScroll(): self { return $this->method('overflow_scroll', []); }
    public function roundedMd(): self { return $this->paramStyle('rounded', [6.0]); }
    public function roundedLg(): self { return $this->paramStyle('rounded', [8.0]); }
    public function roundedFull(): self { return $this->paramStyle('rounded', [9999.0]); }
    public function border(): self { return $this->paramStyle('border', [1.0]); }
    public function shadowSm(): self { return $this->style('shadow_sm'); }
    public function shadowMd(): self { return $this->style('shadow_md'); }
    public function textCenter(): self { return $this->style('text_center'); }
    public function textRight(): self { return $this->style('text_right'); }

    // ── 参数化样式（带值）──

    public function w(float|string $v): self { return $this->paramStyle('w', [$v]); }
    public function h(float|string $v): self { return $this->paramStyle('h', [$v]); }
    public function gap(float $v): self { return $this->paramStyle('gap', [$v]); }
    public function gapX(float $v): self { return $this->paramStyle('gap_x', [$v]); }
    public function gapY(float $v): self { return $this->paramStyle('gap_y', [$v]); }
    public function p(float $v): self { return $this->paramStyle('p', [$v]); }
    public function px(float $v): self { return $this->paramStyle('px', [$v]); }
    public function py(float $v): self { return $this->paramStyle('py', [$v]); }
    public function pt(float $v): self { return $this->paramStyle('pt', [$v]); }
    public function pb(float $v): self { return $this->paramStyle('pb', [$v]); }
    public function pl(float $v): self { return $this->paramStyle('pl', [$v]); }
    public function pr(float $v): self { return $this->paramStyle('pr', [$v]); }
    public function m(float $v): self { return $this->paramStyle('m', [$v]); }
    public function mt(float $v): self { return $this->paramStyle('mt', [$v]); }
    public function mb(float $v): self { return $this->paramStyle('mb', [$v]); }
    public function ml(float $v): self { return $this->paramStyle('ml', [$v]); }
    public function mr(float $v): self { return $this->paramStyle('mr', [$v]); }
    public function bg(string|int $color): self { return $this->paramStyle('bg', [$color]); }
    public function textColor(string|int $color): self { return $this->paramStyle('text_color', [$color]); }
    public function borderColor(string|int $color): self { return $this->paramStyle('border_color', [$color]); }
    public function font(float $px): self { return $this->paramStyle('text_size', [$px]); }
    public function opacity(float $v): self { return $this->paramStyle('opacity', [$v]); }
    public function rounded(float $v): self { return $this->paramStyle('rounded', [$v]); }
    public function borderWidth(float $v): self { return $this->paramStyle('border', [$v]); }
    public function flexGrow(float $v): self { return $this->paramStyle('flex_grow', [$v]); }
    public function flexShrink(float $v): self { return $this->paramStyle('flex_shrink', [$v]); }

    // ── 行为方法 ──

    public function id(string $name): self
    {
        $this->id = $name;
        return $this->method('id', [$name]);
    }

    public function disabled(bool $v = true): self
    {
        return $this->method('disabled', [$v]);
    }

    public function selected(bool $v = true): self
    {
        return $this->method('selected', [$v]);
    }

    public function checked(bool $v = true): self
    {
        return $this->method('checked', [$v]);
    }

    public function primary(): self
    {
        return $this->method('variant', ['primary']);
    }

    public function secondary(): self
    {
        return $this->method('variant', ['secondary']);
    }

    public function danger(): self
    {
        return $this->method('variant', ['danger']);
    }

    public function ghost(): self
    {
        return $this->method('variant', ['ghost']);
    }

    public function outline(): self
    {
        return $this->method('outline', []);
    }

    public function tooltip(string $tip): self
    {
        return $this->method('tooltip', [$tip]);
    }

    public function placeholder(string $p): self
    {
        return $this->method('placeholder', [$p]);
    }

    public function value(float|string $v): self
    {
        return $this->method('value', [$v]);
    }

    public function size(string $s): self
    {
        return $this->method('size', [$s]);
    }

    // ── 回调标记（声明该元素有此事件；handler 在 App->on(id, type, fn) 注册）──

    public function onClick(): self
    {
        return $this->callback('on_click');
    }

    public function onChange(): self
    {
        return $this->callback('on_change');
    }

    public function onHover(): self
    {
        return $this->callback('on_hover');
    }

    public function onKeyDown(): self
    {
        return $this->callback('on_key_down');
    }

    /** 命名槽：把 child 作为本元素的具名 slot（Popover trigger/content、Avatar image/fallback…）。 */
    public function slot(string $name, self $child): self
    {
        $this->ops[] = ['kind' => 'slot', 'name' => $name, 'child' => $child];
        return $this;
    }

    /**
     * 调用 catalog 组件 descriptor 声明的方法（走 gpui_shim_registered_method）。
     * args 按声明顺序；用 Element::cb() 生成回调标记。
     */
    public function rm(string $name, array $args = []): self
    {
        $this->ops[] = ['kind' => 'registered_method', 'name' => $name, 'args' => $args];
        return $this;
    }

    /**
     * 回调实参标记：编码成数字数组 ['__cb__', type, id]，bridge 端识别后
     * 经 gpui_shim_new_callback 预分配 callbackId 塞进 ComponentArgument.callback。
     */
    public static function cb(string $type = 'click', string $id = ''): array
    {
        return ['__cb__', $type, $id];
    }

    // ── 子节点 ──

    public function child(self $element): self
    {
        $this->children[] = $element;
        return $this;
    }

    public function children(array $elements): self
    {
        foreach ($elements as $e) {
            if ($e instanceof self) { $this->children[] = $e; }
        }
        return $this;
    }

    // ── 内部操作记录 ──

    private function style(string $name): self
    {
        $this->ops[] = ['kind' => 'nullary_style', 'name' => $name, 'args' => []];
        return $this;
    }

    private function paramStyle(string $name, array $args): self
    {
        $this->ops[] = ['kind' => 'param_style', 'name' => $name, 'args' => $args];
        return $this;
    }

    private function method(string $name, array $args): self
    {
        $this->ops[] = ['kind' => 'method', 'name' => $name, 'args' => $args];
        return $this;
    }

    private function callback(string $name): self
    {
        $this->ops[] = ['kind' => 'callback', 'name' => $name, 'args' => []];
        return $this;
    }

    /** 是否有回调 op（bridge 需要为元素分配 callbackId） */
    public function hasCallbacks(): bool
    {
        foreach ($this->ops as $op) {
            if ($op['kind'] === 'callback') { return true; }
        }
        return false;
    }

    /**
     * 序列化为数组，供 C++ bridge（buildSpecTree）读取翻译进 SpecArena。
     * 递归展开 children。
     */
    public function toArray(): array
    {
        $ops = [];
        foreach ($this->ops as $op) {
            if (($op['kind'] ?? '') === 'slot' && ($op['child'] ?? null) instanceof self) {
                $op['child'] = $op['child']->toArray();
            }
            $ops[] = $op;
        }
        $node = [
            'kind' => $this->kind,
            'text' => $this->text,
            'value' => $this->value,
            'id' => $this->id,
            'registered' => $this->registered,
            'ctorArgs' => $this->ctorArgs,
            'ops' => $ops,
        ];
        $kids = [];
        foreach ($this->children as $c) {
            if ($c instanceof self) { $kids[] = $c->toArray(); }
        }
        $node['children'] = $kids;
        return $node;
    }
}
