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

    /**
     * @var int 组件的序号槽，最终落到上游 Component::index。
     *
     * 只有三类节点读它：TableRow 当行号、TableHead/TableCell 当列号
     * （materialize.cpp:1838-1842 → El::AriaRowIndex/AriaColumnIndex）。
     * 也就是说这个数不影响布局，只影响屏幕阅读器报出的位置——但报错位置对
     * 用读屏的人来说就是"第 1 列念了 13 遍"。用 rowIndex()/columnIndex() 写。
     */
    public int $index = 0;

    /** list / uniform_list 的总条数（行本身不进树，见 App::listRenderer）。 */
    public int $listCount = 0;

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

    /**
     * 上游无障碍角色名（shell/a11y.cpp 的 kRoleNames，snake 化后共 181 个）。
     * 权威源在上游：cmd/check-upstream-parity.php 把这张表与树里重解析的结果**双向**对齐，
     * 上游加名/删名而这里没跟上，门就红。role() 只认这些写法（'heading'，不是 'Heading'）。
     */
    public const ROLES = [
        'unknown', 'text_run', 'cell', 'label', 'image', 'link',
        'row', 'list_item', 'list_marker', 'tree_item', 'list_box_option', 'menu_item',
        'menu_list_option', 'paragraph', 'check_box', 'radio_button', 'text_input', 'button',
        'default_button', 'pane', 'row_header', 'column_header', 'row_group', 'list',
        'table', 'layout_table_cell', 'layout_table_row', 'layout_table', 'switch', 'menu',
        'multiline_text_input', 'search_input', 'date_input', 'date_time_input', 'week_input', 'month_input',
        'time_input', 'email_input', 'number_input', 'password_input', 'phone_number_input', 'url_input',
        'abbr', 'alert', 'alert_dialog', 'application', 'article', 'audio',
        'banner', 'blockquote', 'canvas', 'caption', 'caret', 'code',
        'color_well', 'combo_box', 'editable_combo_box', 'complementary', 'comment', 'content_deletion',
        'content_insertion', 'content_info', 'definition', 'description_list', 'details', 'dialog',
        'disclosure_triangle', 'document', 'embedded_object', 'emphasis', 'feed', 'figure_caption',
        'figure', 'footer', 'form', 'grid', 'grid_cell', 'group',
        'header', 'heading', 'iframe', 'iframe_presentational', 'ime_candidate', 'keyboard',
        'legend', 'line_break', 'list_box', 'log', 'main', 'mark',
        'marquee', 'math', 'menu_bar', 'menu_item_check_box', 'menu_item_radio', 'menu_list_popup',
        'meter', 'navigation', 'note', 'plugin_object', 'progress_indicator', 'radio_group',
        'region', 'root_web_area', 'ruby', 'ruby_annotation', 'scroll_bar', 'scroll_view',
        'search', 'section', 'section_footer', 'section_header', 'slider', 'spin_button',
        'splitter', 'status', 'strong', 'suggestion', 'svg_root', 'tab',
        'tab_list', 'tab_panel', 'term', 'time', 'timer', 'title_bar',
        'toolbar', 'tooltip', 'tree', 'tree_grid', 'video', 'web_view',
        'window', 'pdf_actionable_highlight', 'pdf_root', 'graphics_document', 'graphics_object', 'graphics_symbol',
        'doc_abstract', 'doc_acknowledgements', 'doc_afterword', 'doc_appendix', 'doc_back_link', 'doc_biblio_entry',
        'doc_bibliography', 'doc_biblio_ref', 'doc_chapter', 'doc_colophon', 'doc_conclusion', 'doc_cover',
        'doc_credit', 'doc_credits', 'doc_dedication', 'doc_endnote', 'doc_endnotes', 'doc_epigraph',
        'doc_epilogue', 'doc_errata', 'doc_example', 'doc_footnote', 'doc_foreword', 'doc_glossary',
        'doc_gloss_ref', 'doc_index', 'doc_introduction', 'doc_note_ref', 'doc_notice', 'doc_page_break',
        'doc_page_footer', 'doc_page_header', 'doc_page_list', 'doc_part', 'doc_preface', 'doc_prologue',
        'doc_pullquote', 'doc_qna', 'doc_subtitle', 'doc_tip', 'doc_toc', 'list_grid',
        'terminal',
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

    /**
     * 单行输入。$id 进的是 **id 字段**（不是 text）：bridge 按 node['id'] 建/复用
     * retained InputState（gpui_bridge.cc:149,177）。id 落在 text 里等于没建，
     * materialize 于是 `return Div(cx->a)`——输入框静默变成一颗 Div：不聚焦、不能打字、
     * 点它也没事件。App::text(id) 与 onChange 的路由键同样是这个 id。
     */
    public static function input(string $id = ''): self
    {
        $e = new self();
        $e->kind = 'input';
        $e->id = $id;
        return $e;
    }

    /** 多行输入。id 的去处同 input()：必须是 id 字段，否则 retained 建不起来。 */
    public static function textarea(string $id = ''): self
    {
        $e = new self();
        $e->kind = 'textarea';
        $e->id = $id;
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

    /**
     * 通知层的挂载点。App::notify() 的状态住在窗口层，但**画出来**这一层得有人挂——
     * gpui 的基座 Root 自己挂，我们的窗口根是 ShellRoot（只挂 Toast），所以要放这颗
     * 节点。通常放在树的最外层并铺满窗口。
     */
    public static function notifications(string $id = ''): self
    {
        $e = new self();
        $e->kind = 'notifications';
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
        // $alt 以前收下即丢；现在接进上游认的 aria_label op（读屏念它）。
        // Ely image.rs 的 framed 带框不做成组合糖——borderWidth+rounded 两条正交
        // 样式链自己叠（组件页的样式糖纪律），比例校验（checked_ratio）需上游加。
        if ($alt !== '') {
            $e->ariaLabel($alt);
        }
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
        // min/max 以前三处静默丢弃（bridge 只传默认量程 0..100，CreateRetainedSlider
        // 初值恒为 min）。现在发桥内专用 op：bridge 建 retained 时读走，传进
        // gpui_shim_retained_slider 并按 $value 设初值；这两个名字不到上游
        // （check-upstream-parity.php 的 $internalOps 白名单）。
        $e->method('slider_min', [$min]);
        $e->method('slider_max', [$max]);
        return $e;
    }

    /**
     * 表格容器。$id 进 **id 字段**（同 input 的规则，见本文件 input() 的注释）：
     * bridge 的回调路由键是 node['id']，id 落在 text 里等于这颗元素没名字，
     * 挂 ->onClick() 注册的是空 id，事件永远到不了 PHP。
     */
    public static function table(string $id = ''): self
    {
        $e = new self();
        $e->kind = 'table';
        $e->id = $id;
        return $e;
    }

    /**
     * 虚拟列表（行高各自测量）。**行不在树里**：只给总条数，行由
     * `App::listRenderer($id, fn(int $start, int $end): array)` 登记的闭包在需要时给——
     * gpui 只为可见的那一段（加少量预取）要行，滚到哪儿要到哪儿。
     *
     * 机制：上游 RenderVirtualItems 在 materialize 途中同步要行，cmd/patch-host-list.sh
     * 把宿主登记的那种回调分叉给 shim，shim 再经 bridge 的 php::call 调到这个闭包。
     * 行点击可挂在行上；list 的 onItemClick/onItemSecondaryClick 用行根 id 为 key，缺 id 时用行号。
     */
    public static function list(string $id, int $count = 0): self
    {
        $e = new self();
        $e->kind = 'list';
        $e->id = $id;
        $e->listCount = max(0, $count);
        return $e;
    }

    /** 等高虚拟列表：只量一行（第一行）就排所有行，比 list() 更省。其余同 list()。 */
    public static function uniformList(string $id, int $count = 0): self
    {
        $e = new self();
        $e->kind = 'uniform_list';
        $e->id = $id;
        $e->listCount = max(0, $count);
        return $e;
    }

    /** Dock 由 App::createDock/addDockPanel 管理保留态；此节点只挂载已有布局。 */
    public static function dockArea(string $id): self
    {
        $e = new self();
        $e->kind = 'dock_area';
        $e->id = $id;
        return $e;
    }

    public static function tabs(string $id = ''): self
    {
        $e = new self();
        $e->kind = 'tabs';
        $e->id = $id;
        return $e;
    }

    public static function tab(string $label): self
    {
        $e = new self();
        $e->kind = 'tab';
        // base Tab 的 text 只作 motion identity（materialize.cpp:836-837 的
        // ComponentName），屏上不渲染——可见内容来自子元素（tab.cpp 的
        // item.children）。工厂把 label 包成 text 子节点，调用方不用再手写
        // ->child(El::text(...))；要加角标/图标就继续 ->child() 追加。
        $e->children[] = self::text($label);
        return $e;
    }

    /** Markdown 文本视图：正文进 value（native 读的是它），$id 进 id 字段。 */
    public static function textView(string $markdown, string $id = ''): self
    {
        $e = new self();
        $e->kind = 'text_view';
        $e->value = $markdown;
        $e->id = $id;
        return $e;
    }

    // ── 低复杂度 / Slot 消费组件 ──

    public static function svg(string $path): self
    {
        if ($path === '' || \substr($path, -4) !== '.svg') {
            // svg 的绘制走 SvgDraw 直读资源路径（icon() 那套 icons/ 前缀 + 编译表
            // 校验不适用），能当场拦的是必然画不出的形状：空串与非 .svg 后缀。
            // 路径对但文件不在 → 仍是静默空白（无错误通道），与 icon() 同限。
            throw new \InvalidArgumentException(
                "svg(): 路径要长成 <name>.svg，收到 '{$path}'"
            );
        }
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
        // 上游 Collapsible 只读 **content** 槽（materialize.cpp:2484 的
        // `Slot(cx, runtime, specs, node, "content", error)`）——以前发 header/panel
        // 两个不存在的槽名，物化近似空。Ely collapse.rs 的 Collapsible 是"单块折叠"：
        // 没有内建头部件，label 就是折叠块的第一行，整块随 open 收展。
        $e->slot('content', self::vFlex([
            self::text($label)->font(13.0)->semibold(),
            $content,
        ])->gap(6.0));
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

    public static function avatar(string $src, string $fallback = ''): self
    {
        $e = new self();
        $e->kind = 'avatar';
        // base Avatar 零样式全靠脚本（materialize 自证）。工厂给一套默认形状：
        // 圆形裁剪 + 方形比例 + 40px——链式样式后调覆盖。$fallback 非空时
        // fallback 槽放它的首字（Ely avatar.rs 的首字兜底形）。
        $e->slot('image', self::image($src));
                // 首字兜底用 preg 的 /u 修饰取首字符——nano 的扩展表（composer_extensions.cpp）
        // 没有 mbstring，\mb_substr 会让模块 MINIT 直接失败（"Unable to start PHP Nano
        // extensions"，实测）。
        $first = '';
        if ($fallback !== '' && preg_match('/^./u', $fallback, $m) === 1) {
            $first = $m[0];
        }
        $e->slot('fallback', self::text($first)
            ->roundedFull()->aspectSquare()->w(40.0)->h(40.0)
            ->itemsCenter()->justifyCenter());
        // Ely 的在线态色点（Presence）需要 absolute 叠加容器，改返回结构，未做。
        return $e;
    }

    public static function accordionItem(string $header, self $panel,
        ?string $changeId = null): self
    {
        $e = new self();
        $e->kind = 'accordion_item';
        // 三段契约（materialize.cpp:2548-2605）：AccordionItem 的 header/panel 槽各吃
        // 一个部件；header 槽里必须是 AccordionHeader（它的 trigger 槽里是
        // AccordionTrigger——真正可点、翻 open 的那颗，没有 on_change 时上游 Warn
        // "cannot open anything"，只剩 ->open(true) 强展可用）；panel 槽用
        // AccordionPanel 挂内容（aria 语义位）。open 在 item 上设一次、下推两半。
        $trigger = new self();
        $trigger->kind = 'accordion_trigger';
        $trigger->children[] = self::text($header);
        if ($changeId !== null && $changeId !== '') {
            $trigger->id($changeId)->onChange();
        }
        $hdr = new self();
        $hdr->kind = 'accordion_header';
        $hdr->slot('trigger', $trigger);
        $pane = new self();
        $pane->kind = 'accordion_panel';
        $pane->children[] = $panel;
        $e->slot('header', $hdr);
        $e->slot('panel', $pane);
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

    public static function otpInput(string $id = '', int $length = 4): self
    {
        $e = new self();
        $e->kind = 'otp_input';
        $e->id = $id;
        // 格数以前固定 4（bridge 自证注释）。发桥内专用 op otp_length：bridge 建
        // retained 时读走、传进 gpui_shim_retained_otp（上游 CreateRetainedOtp
        // 本就按任意 length 建）；名字不到上游（parity 白名单）。要带样式/焦点态
        // 的那颗走 El::catalogOtpInput($id, $length)。
        if ($length > 0 && $length != 4) {
            $e->method('otp_length', [(float) $length]);
        }
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

    /**
     * 表格的一行。上游 Table 的 typed children 只收 TableRow
     * （component_shell/structured/table.cpp:49 `kAllowed[] = {"TableRow"}`），
     * 所以"Table 里直接塞 text"那种写法拿到的不是表格，只是一个盒子。
     * 行号用 ->rowIndex(n) 给（aria 用，见 $index）。
     */
    public static function tableRow(array $children = []): self
    {
        $e = new self();
        $e->kind = 'table_row';
        foreach ($children as $c) { $e->child($c); }
        return $e;
    }

    /** 数据格：只能挂在 TableRow 下（那里 kAllowed = {TableHead, TableCell}）。 */
    public static function tableCell(array $children = []): self
    {
        $e = new self();
        $e->kind = 'table_cell';
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

    public static function hResizable(array $children = [], string $name = ''): self
    {
        $e = new self();
        $e->kind = 'h_resizable';
        // 组件名进 component.text：materializer 用它做 ResizableState 的 element-state 键。
        // 不传就等于两颗组分到一个键上（#94 实测：后渲染的组覆盖前一个的 resize 监听）。
        if ($name !== '') { $e->text = $name; }
        foreach ($children as $c) { $e->child($c); }
        return $e;
    }

    public static function vResizable(array $children = [], string $name = ''): self
    {
        $e = new self();
        $e->kind = 'v_resizable';
        if ($name !== '') { $e->text = $name; }
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

    /**
     * 通用 catalog 工厂：上游 component_shell 的 137 个导出名里，77 颗有专用工厂
     * （tier-A 批 27 + 「纯工厂热身」批 5 + 批2 六颗 Label/Toolbar/StatusBar/
     * Clipboard/HorizontalRadioGroup + 批3 七颗 Notification/DropdownButton/Bubble/
     * Marker/Message/MessageScroller/Attachment + 图表五连，加上更早的
     * sidebar/menu/form/输入族），
     * 这条是其余那些的门（Accordion、TabBar、InputGroup 组合件…；SplitButton 是
     * 组合糖，非导出名）。
     *
     * 导出名写错 PHP 侧**不会**报（nano 里没有上游 descriptor 表）：它走
     * gpui_shim_registered 的错误通道 —— 节点降级成一个带错误文本的 Div，
     * 文本用 App::registeredError() 读。CI 上 cmd/check-upstream-parity.php 会把
     * 源码与示例里用到的导出名逐个对一遍上游表，那条门才是名字写错时的哨兵。
     *
     * @param array<int, mixed> $ctorArgs 按 descriptor 声明顺序（可少传，缺的须是 Optional）
     * @param array<int, self>  $children typed / ordinary 子元素
     */
    public static function catalog(string $export, array $ctorArgs = [], array $children = []): self
    {
        return self::reg($export, $ctorArgs, $children);
    }

    /**
     * 带组件库样式、有变体的按钮（catalog Button）。
     *
     * 与 `El::button()` 的区别不是糖度而是**两个不同的组件**：`El::button()` 是
     * gpui-base 的 `ComponentKind::Button`（只有点击与文本，没有圆角/底色/变体），
     * 上游文档画廊里那个带 `primary/ghost/danger` 的是 component_shell 的 `Button`。
     * 两者的方法表不通用 —— 在 kind 版上调 `->primary()` 现在会直接抛。
     *
     * Button 的构造器只要一个非空 id，标签走 `label(string)` 方法。
     */
    public static function catalogButton(string $id, string $label): self
    {
        return self::reg('Button', [$id])->id($id)->label($label);
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

    /**
     * Icon：画一个 gpui 编译进去的图标（或资源根下自己的 .svg）。拒 children。
     *
     * 路径必须长成 `icons/<base>.svg`——`AssetIconForPath`（gpui svg.cpp:1377）逐条要求
     * 前缀 `icons/`、后缀 `.svg`，再把 base 去 111 个编译表里找。三条任意一条不满足就返回
     * 空，而渲染侧对空是**静默什么都不画**（没有错误通道），所以形状写错或 base 不在表里
     * 只会得到一片空白。注意 base 与 `sidebarMenuItem()->navIcon('home')` 那五个名字不是一套
     * 命名空间：后者是上游的逻辑名，映射到 SquareTerminal/LayoutDashboard/Settings2/BookOpen/User。
     */
    public static function icon(string $path): self
    {
        if (\strncmp($path, 'icons/', 6) !== 0 || \substr($path, -4) !== '.svg') {
            throw new \InvalidArgumentException(
                "icon(): 路径要长成 icons/<name>.svg，收到 '{$path}'（写错不会报错，只会静默不画）"
            );
        }
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

    /**
     * catalog 组件的方法：只有 registered 节点吃得到。
     *
     * 为什么要在 PHP 侧拦：这些名字走的是 gpui_shim_registered_method，打在 kind 节点上
     * 时上游把它记成 RegisteredMethod op，而 `ResolveBehavior` 开头就是
     * `if (op.kind != Method) continue;`（materialize.cpp:314）—— 连"未知名字"都算不上，
     * 直接跳过，一个像素都不变。以前 primary() 那批就是这么哑掉的。
     */
    private function catalogMethod(string $name, array $args = []): self
    {
        if (!$this->registered) {
            throw new \InvalidArgumentException(
                'Element::' . $name . '() 只对 catalog 组件有效，而当前节点的 kind=\'' . $this->kind
                . '\' 走的是 gpui-base 原语。带样式的版本用 El::catalog(\'Button\', [$id]) 造，'
                . '或去掉这个调用（它发出去会被上游静默跳过）。'
            );
        }
        return $this->rm($name, $args);
    }

    public function title(string $s): self { return $this->catalogMethod('title', [$s]); }
    public function banner(): self { return $this->catalogMethod('banner'); }
    public function description(string $s): self { return $this->catalogMethod('description', [$s]); }
    /** Button 的标签、Field 的标题：两边都是 catalog 方法名 label(string)。 */
    public function label(string $s): self { return $this->catalogMethod('label', [$s]); }
    public function columns(float $n): self { return $this->catalogMethod('columns', [$n]); }
    public function labelWidth(float $w): self { return $this->catalogMethod('label_width', [$w]); }
    public function side(string $s): self { self::enumCheck('side', $s, self::SIDES); return $this->catalogMethod('side', [$s]); }
    public function collapsibleMode(string $mode): self { self::enumCheck('collapsibleMode', $mode, self::COLLAPSE_MODES); return $this->catalogMethod('collapsible', [$mode]); }
    public function collapsed(bool $v): self { return $this->catalogMethod('collapsed', [$v]); }
    public function navIcon(string $name): self { self::enumCheck('navIcon', $name, self::NAV_ICONS); return $this->catalogMethod('icon', [$name]); }
    public function rotate(float $rad): self { return $this->catalogMethod('rotate', [$rad]); }
    public function color(string $c): self { return $this->catalogMethod('color', [$c]); }
    /** catalog 组件的 pad 方法（批7 AnimatedNumber：整数位补零）。 */
    public function pad(int $digits): self { return $this->catalogMethod('pad', [$digits]); }
    public function align(string $a): self { self::enumCheck('align', $a, self::FIELD_ALIGN); return $this->catalogMethod('align', [$a]); }
    /** catalog 组件的语义尺寸（走 registered_method 'size'，枚举预校验）。 */
    public function sizeEnum(string $s): self { self::enumCheck('sizeEnum', $s, self::SIZES); return $this->catalogMethod('size', [$s]); }

    /**
     * 按钮变体。上游把它们做成 catalog Button 的**零参方法**
     * （Button methods: label, tooltip, loading, size, outline, primary, secondary,
     * danger, success, warning, ghost, link, compact），不是 `variant('primary')`——
     * `variant(enum)` 是 DropdownButton / Tag / Bubble / Marker / GroupBox / TabBar 的方法。
     * 所以这里必须发方法名本身；发 variant 时 Button 上查无此名，kind 路径上更是静默丢。
     */
    public function primary(): self { return $this->catalogMethod('primary'); }
    public function secondary(): self { return $this->catalogMethod('secondary'); }
    public function danger(): self { return $this->catalogMethod('danger'); }
    public function ghost(): self { return $this->catalogMethod('ghost'); }
    public function outline(): self { return $this->catalogMethod('outline'); }
    public function success(): self { return $this->catalogMethod('success'); }
    public function warning(): self { return $this->catalogMethod('warning'); }
    public function compact(): self { return $this->catalogMethod('compact'); }
    public function loading(bool $v = true): self { return $this->catalogMethod('loading', [$v]); }

    /**
     * 语义变体（registered_method 'variant'）。与 primary()/danger() 那批**不是**同一族：
     * 那五个是 Button 的零参方法，`variant(enum)` 属于 Tag / DropdownButton / Bubble /
     * Marker / GroupBox / TabBar 的方法表——打在 Button 上会被 descriptor 拒
     * （"no such registered method"），打在 kind 节点上则静默丢（见上面 primary 的注释）。
     * 允许集是各组件字面量表（descriptor 逐个核过）的并：Tag（display.cpp:182）
     * primary/secondary/danger/success/warning/info + GroupBox（group_box.cpp:78，
     * 2026-10-28 纯工厂批并入）normal/fill/outline。其余带 variant 的组件各有各的集、
     * 尚未开糖（DropdownButton/InputGroupButton/TabBar/Bubble/Marker），要发用 ->rm()。
     */
    public function variant(string $v): self
    {
        self::enumCheck('variant', $v, ['primary', 'secondary', 'danger', 'success', 'warning', 'info', 'normal', 'fill', 'outline']);
        return $this->catalogMethod('variant', [$v]);
    }

    /** Tag 的圆角药丸形（registered_method 'rounded_full'，与 paramStyle 的 rounded 是两回事）。 */
    public function pill(): self { return $this->catalogMethod('rounded_full'); }

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

    // ── 补齐的样式关键字（2026-10-13）──
    //
    // 每一个都是 style.cpp 的 kKeywords 表里的零参关键字，名字由
    // cmd/check-upstream-parity.php 的 2c 段静态核过（发错名会在 materialize 写错误，
    // 但那要等真跑起来才响）。这里刻意**只发关键字本身**，不做组合糖：
    // 组合（如 `flexBetween()`）看着省一个调用，实际是把两个正交概念焊死，
    // 上游改名时又是一条新的静默路。

    // 显示模式
    public function block(): self { return $this->style('block'); }
    public function grid(): self { return $this->style('grid'); }
    public function visible(): self { return $this->style('visible'); }
    public function invisible(): self { return $this->style('invisible'); }

    // 弹性布局的其余面（方向 / 换行 / 收缩语义）
    public function flexRowReverse(): self { return $this->style('flex_row_reverse'); }
    public function flexColReverse(): self { return $this->style('flex_col_reverse'); }
    public function flexWrapReverse(): self { return $this->style('flex_wrap_reverse'); }
    public function flexNowrap(): self { return $this->style('flex_nowrap'); }
    public function flexAuto(): self { return $this->style('flex_auto'); }
    public function flexInitial(): self { return $this->style('flex_initial'); }

    // 交叉轴对齐：items_* 管容器，self_* 管子元素自己
    public function itemsBaseline(): self { return $this->style('items_baseline'); }
    public function itemsStretch(): self { return $this->style('items_stretch'); }
    public function selfStart(): self { return $this->style('self_start'); }
    public function selfCenter(): self { return $this->style('self_center'); }
    public function selfEnd(): self { return $this->style('self_end'); }
    public function selfStretch(): self { return $this->style('self_stretch'); }

    // justify_* 是主轴；content_* 是"多行之间"的分布，别和 justify_* 混用
    public function justifyAround(): self { return $this->style('justify_around'); }
    public function justifyEvenly(): self { return $this->style('justify_evenly'); }
    public function contentCenter(): self { return $this->style('content_center'); }
    public function contentBetween(): self { return $this->style('content_between'); }
    public function contentAround(): self { return $this->style('content_around'); }
    public function contentEvenly(): self { return $this->style('content_evenly'); }
    public function contentStretch(): self { return $this->style('content_stretch'); }

    public function overflowHidden(): self { return $this->style('overflow_hidden'); }
    public function aspectSquare(): self { return $this->style('aspect_square'); }
    public function whitespaceNowrap(): self { return $this->style('whitespace_nowrap'); }
    public function textEllipsis(): self { return $this->style('text_ellipsis'); }
    public function lineThrough(): self { return $this->style('line_through'); }
    public function borderDashed(): self { return $this->style('border_dashed'); }
    public function shadowNone(): self { return $this->style('shadow_none'); }
    public function shadowLg(): self { return $this->style('shadow_lg'); }

    public function cursorText(): self { return $this->style('cursor_text'); }
    public function cursorMove(): self { return $this->style('cursor_move'); }
    public function cursorNotAllowed(): self { return $this->style('cursor_not_allowed'); }
    public function cursorGrab(): self { return $this->style('cursor_grab'); }

    // 字重（font_bold / font_semibold 早就有了，这里补其余档）
    public function fontThin(): self { return $this->style('font_thin'); }
    public function fontLight(): self { return $this->style('font_light'); }
    public function fontNormal(): self { return $this->style('font_normal'); }
    public function fontMedium(): self { return $this->style('font_medium'); }
    public function fontBlack(): self { return $this->style('font_black'); }

    /**
     * 字号档位。text_size 的 ramp（text_xs…text_3xl）是上游 style.cpp 里
     * **唯一动到被追踪字段的零参样式**（fields=StyleFieldFontSize），
     * 也就是说它们是 transition()/spring() 能动画的那批字号；
     * 与 paramStyle('text_size', [px]) 互斥，后调用者覆盖前一个。
     */
    public function textXs(): self { return $this->style('text_xs'); }
    public function textSm(): self { return $this->style('text_sm'); }
    public function textBase(): self { return $this->style('text_base'); }
    public function textLg(): self { return $this->style('text_lg'); }
    public function textXl(): self { return $this->style('text_xl'); }
    public function text2xl(): self { return $this->style('text_2xl'); }
    public function text3xl(): self { return $this->style('text_3xl'); }

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

    /**
     * 声明这颗部件**现在是开着的**（Select / Combobox / DatePicker 的弹层）。
     *
     * 为什么得能设：`ShellSelectBinding::open` 取的就是它，而 `SelectActionOf`
     * 是按这个位决定动作含义的——open=false 时 Confirm 被读成"再开一次"、
     * Cancel 被读成"不归我管"（`base/select.cpp:43-49`），于是 `onConfirm` /
     * `onDismiss` 永远不会发。没有这颗糖，那两个名字就算投递链通了也测不到。
     */
    public function open(bool $v = true): self
    {
        return $this->method('open', [$v]);
    }

    public function selected(bool $v = true): self
    {
        return $this->method('selected', [$v]);
    }

    public function checked(bool $v = true): self
    {
        return $this->method('checked', [$v]);
    }

    // ── 无障碍与焦点（都在行为链上，materialize.cpp:420 附近那一段）──

    /**
     * ARIA 角色。上游是 `if (role != None) element->Role(role)`：名字不在 ROLES 里就
     * 静默不加（连像素都不变），所以在这里当场拒。写法只认 snake_case——
     * RoleNameMatches 把上游表逐项拆下划线再比，'Heading' / 'heading_' 都不认。
     */
    public function role(string $role): self
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException(
                'Element::role(): \'' . $role . '\' 不在上游 ' . count(self::ROLES)
                . ' 个角色名里（shell/a11y.cpp kRoleNames 的 snake_case 写法：'
                . "'heading'、'list_item'、'tab_panel'…）"
            );
        }
        return $this->method('role', [$role]);
    }

    /** 读屏时替代可见文本的标签（accessibility_label 是同一个槽的上游别名）。 */
    public function ariaLabel(string $label): self
    {
        return $this->method('aria_label', [$label]);
    }

    /** aria_level：上游 base 默认 3 且把 <1 夹成 1（materialize.cpp aria_level），这里直接拒。 */
    public function ariaLevel(int $level): self
    {
        if ($level < 1) {
            throw new \InvalidArgumentException('ariaLevel(): 层级从 1 起，收到 ' . $level);
        }
        return $this->method('aria_level', [(float) $level]);
    }

    public function ariaSelected(bool $on = true): self
    {
        return $this->method('aria_selected', [$on]);
    }

    /** 零参旗子：只声明"本节点的子树里有 active descendant"，值由上游自己算。 */
    public function ariaActiveDescendant(): self
    {
        return $this->method('aria_active_descendant', []);
    }

    /** Tab 序。上游取 AsNumber 后转 int（负数=可聚焦但不进 Tab 序，是合法写法）。 */
    public function tabIndex(int $index): self
    {
        return $this->method('tab_index', [(float) $index]);
    }

    /** 是否参与 Tab 轮询。dialog 的 popup 自己就是 TabStop(false)，别照抄到内容上。 */
    public function tabStop(bool $on = true): self
    {
        return $this->method('tab_stop', [$on]);
    }

    /** 命名按键上下文：Escape/Enter 这类动作按上下文解析，不是按焦点元素解析。 */
    public function keyContext(string $context): self
    {
        return $this->method('key_context', [$context]);
    }

    public function selectable(bool $on = true): self
    {
        return $this->method('selectable', [$on]);
    }

    /**
     * 表格的行列总数（aria 用）。上游读 `row_count`/`column_count` 两个 op
     * （materialize.cpp:352-354），不读子节点个数——所以不写就是"表格没有行列数"。
     */
    public function rowCount(int $n): self
    {
        if ($n < 0) {
            throw new \InvalidArgumentException('rowCount(): 行数不能为负，收到 ' . $n);
        }
        return $this->method('row_count', [(float) $n]);
    }

    public function columnCount(int $n): self
    {
        if ($n < 0) {
            throw new \InvalidArgumentException('columnCount(): 列数不能为负，收到 ' . $n);
        }
        return $this->method('column_count', [(float) $n]);
    }

    /**
     * 本节点在表里的序号槽。rowIndex 给 TableRow、columnIndex 给 TableHead/TableCell；
     * 两者写的是同一个 Component::index（上游按 kind 决定它的含义），所以别在同一颗上
     * 两个都调 —— 后一个会盖掉前一个。
     */
    public function rowIndex(int $i): self
    {
        if ($i < 0) {
            throw new \InvalidArgumentException('rowIndex(): 行号不能为负，收到 ' . $i);
        }
        $this->index = $i;
        return $this;
    }

    public function columnIndex(int $i): self
    {
        if ($i < 0) {
            throw new \InvalidArgumentException('columnIndex(): 列号不能为负，收到 ' . $i);
        }
        $this->index = $i;
        return $this;
    }

    // primary()/secondary()/danger()/ghost()/outline() 在这里改过一轮：它们原先发
    // method('variant', [...]) 与 method('outline')，上游两个名字都不认（Button 的变体是
    // 零参**方法** primary/ghost/…，`variant(enum)` 属于 DropdownButton/Tag/…），
    // 于是这五个糖一个像素都不改变。现在的实现在上面 catalogMethod 那一节。

    public function tooltip(string $tip): self
    {
        return $this->method('tooltip', [$tip]);
    }

    public function placeholder(string $p): self
    {
        return $this->method('placeholder', [$p]);
    }

    /** Combobox 的搜索占位提示（registered_method 'search_placeholder'）。 */
    public function searchPlaceholder(string $p): self
    {
        return $this->catalogMethod('search_placeholder', [$p]);
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

    /**
     * 挂 key_down 回调，事件的 `value` 是按下的字符（UTF-8）。
     *
     * 实现位置值得知道：gpui 原本把键事件交给 ScriptView → ShellRuntime::DispatchKey →
     * QuickJS，而 PHP 宿主没有脚本引擎——真实按键会直接段错误。上游补丁
     * cmd/patch-key-view.sh 按 OnClick 的同一形状，在 view 层就把字符交给宿主队列。
     * 代价与点击一致：没有脚本端的 stopPropagation，传播由宿主决定。
     */
    public function onKeyDown(): self
    {
        return $this->callback('on_key_down');
    }

    /** 按下鼠标键。事件 value 是 "x,y,button,clicks,mods"（button：Left=0/Right=1/Middle=2，
     *  mods 位掩码 control=1 alt=2 shift=4 Command=8 Fn=16）。投递需要 cmd/patch-mouse-view.sh。 */
    public function onMouseDownLeft(): self { return $this->callback('on_mouse_down_left'); }
    public function onMouseDownRight(): self { return $this->callback('on_mouse_down_right'); }
    public function onMouseDownMiddle(): self { return $this->callback('on_mouse_down_middle'); }
    /** 抬起鼠标键，value 同 onMouseDownLeft（clicks 恒为 1，与上游一致）。 */
    public function onMouseUpLeft(): self { return $this->callback('on_mouse_up_left'); }
    public function onMouseUpRight(): self { return $this->callback('on_mouse_up_right'); }
    public function onMouseUpMiddle(): self { return $this->callback('on_mouse_up_middle'); }
    /** 在盒子内移动。value 是 "x,y,pressed,button,mods"。 */
    public function onMouseMove(): self { return $this->callback('on_mouse_move'); }
    /** 按下发生在盒子**之外**（拖拽起手在别处时才有）。value 同 onMouseDownLeft。 */
    public function onMouseDownOut(): self { return $this->callback('on_mouse_down_out'); }
    /** 滚轮。value 是 "dx,dy,x,y,mods"，dx/dy 是 DIP（上游注释：一格滚轮 48）。 */
    public function onScrollWheel(): self { return $this->callback('on_scroll_wheel'); }
    /** 抬起键。与 onKeyDown 同一条宿主路（cmd/patch-key-view.sh），value 是键名或字符。
     *  注意真键送达本身仍受前台竞争影响，见 #56。 */
    public function onKeyUp(): self { return $this->callback('on_key_up'); }

    // ── 部件自己发出的那批事件 ──
    //
    // 这一族的共同点：callbackId 不是直接挂在元素监听器上（像 on_click 那样走
    // ClickListener），而是存进部件自己的 binding（ShellSelectBinding / ShellBoolBinding /
    // …），由部件在合适的时刻把它发出去。
    //
    // 2026-10-14 之前这里写的"不需要新补丁就能到 PHP"是**错的**，两个前提都不成立：
    // 一是 `ResolveBehavior` 把 `op.callback` 原样塞进 binding，而那个 id 是 **Box2 自己的**
    // callbackId，从来不是 runtime 回调表里的条目（只有 catalog 那条 `gpui_shim_new_callback`
    // 会 `PushShimCallback` 登记），所以 `CallbackArena::Get()` 扫不到 ⇒ 事件静默丢；
    // 二是这条扫描本身在 `impl` 不健康时直接段错误（实测 6 次跑崩 4 次，
    // 栈 `DispatchArgs ← ScriptView::OnSelectAction ← WindowDispatchActionFrom`）。
    // 现在由 cmd/patch-select-view.sh 在 view 层换投递点（与点击/按键/指针那三支同形状），
    // 完全不进回调表，Box2 id 直接回本 box 的队列——open_change 带 true/false、
    // step 带 increment/decrement，PHP 侧 elementValue()/checked() 读得到。
    //
    // 同形状还没接的两处：on_resize（一串 float）与 on_item_secondary_click。
    // 另外 confirm/dismiss 光接通还不够，还要 `open(true)` 把这颗声明成开着——
    // 那颗糖有（本文件 `open()`），binding.open 取的就是它；关着时 SelectActionOf
    // 把 Confirm 解释成"再开一次"、把 Cancel 解释成"不归我管"（见 examples/choices 的
    // confirmDismissDelivered 硬门，它渲染的第二棵就是把 ch-select 声明成开着的）。
    // 各自的送达状态见 docs/guide/events.md。

    /** Select / Combobox / DatePicker 的开合变化。上游带 bool，到 PHP 后是 elementValue() 的真/假。 */
    public function onOpenChange(): self { return $this->callback('on_open_change'); }
    /** 选中确认（Select / Combobox 的 Confirm 动作）。无载荷（DispatchSignal）。 */
    public function onConfirm(): self { return $this->callback('on_confirm'); }
    /** 弹层被收回（Esc / 再点一次 / 选中后关闭）。无载荷。 */
    public function onDismiss(): self { return $this->callback('on_dismiss'); }
    /** 数字输入的 ± 步进。上游带 "increment"/"decrement"，到 PHP 后是 elementValue() 的字符串。 */
    public function onStep(): self { return $this->callback('on_step'); }
    /** 尺寸变化（可伸缩面板）。带宽高，现经 shim 路丢值。 */
    public function onResize(): self { return $this->callback('on_resize'); }
    /** Markdown 里的链接被点。上游带 url。 */
    public function onLinkClick(): self { return $this->callback('on_link_click'); }
    /** 列表/网格的某一行被点。上游带行号。 */
    public function onItemClick(): self { return $this->callback('on_item_click'); }
    /** 列表/网格某一行被辅助键（右键）点。 */
    public function onItemSecondaryClick(): self
    {
        return $this->callback('on_item_secondary_click');
    }
    /** TokenInput 的某个 token 被点。 */
    public function onTokenClick(): self { return $this->callback('on_token_click'); }
    /** TokenInput 的某个 token 被悬停。 */
    public function onTokenHover(): self { return $this->callback('on_token_hover'); }

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

    /**
     * catalog 的**保留态实体**实参（#81）：`Input` / `NumberInput` / `OtpInput` /
     * `Slider` / `ColorPicker` / `Calendar` / `DatePicker` / `TimeField` 的构造都只吃
     * 一个 `state`，schema 是 `SchemaEntity("...State")`。
     *
     * 这里只放标记，真正的实体由 bridge 在 emitTree 时经 `gpui_shim_state` 造
     * （建树时窗口可能还不存在），并按 (kind, key) 缓存——PHP 每帧重建同一棵树，
     * 不缓存就是每帧往上游的实体表里塞一颗新的。`$key` 用元素 id 就够。
     *
     * @param array<int, string|int|float|bool|array<int, string|int|float|bool>|null> $args 按该 state 的 schema 顺序；null = 不传（optional 缺席）。数组实参合法（DataTableState 的列名就是字符串数组），bridge 的 toArgOne 递归转 GPUI_ARG_ARRAY
     */
    public static function state(string $kind, string $key, array $args = []): array
    {
        return ['__state__', $kind, $key, $args];
    }

    /**
     * 带组件库样式的输入框（catalog `Input`）。
     *
     * 与 `El::input()` 的区别是**两个不同的组件**，不是糖度：`El::input()` 是 shell 的
     * `ComponentKind::Input`，materialize 走的是不带样式那个重载
     * （`InputBase::New(cx, id, interactive, role)`，base/input.cpp:34-39），所以它天生裸框；
     * 带边框/底色/焦点态的是 catalog 这颗，代价是必须先造一颗 `InputState` 实体。
     */
    public static function catalogInput(string $id, string $placeholder = '', string $initial = ''): self
    {
        return self::reg('Input', [
            self::state('InputState', $id, [
                $placeholder === '' ? null : $placeholder,
                $initial === '' ? null : $initial,
            ]),
        ])->id($id);
    }

    /**
     * catalog 表单族剩下那七颗（#82）：每颗的构造都只吃一个 `state`，差别只在
     * 那颗实体的 schema（`retained_forms/mod.cpp:470-518`）。
     *
     * 与 `El::input()`/`El::slider()`/`El::otpInput()` 那批 kind 版的区别同
     * `catalogInput()`：kind 版不带样式（materialize 走无样式的重载），带边框/焦点态的
     * 是 catalog 这一族。**取值回读只覆盖 kind 版**：`App::text()`/`checked()` 读的是
     * shim 的 inputHandles 表，catalog 那颗不在里面（见 examples/input 的门注释）。
     */
    public static function catalogNumberInput(string $id, string $placeholder = '', string $initial = ''): self
    {
        // NumberInput 与 Input 共用 InputState（"shared by Input and NumberInput"）。
        return self::reg('NumberInput', [
            self::state('InputState', $id, [
                $placeholder === '' ? null : $placeholder,
                $initial === '' ? null : $initial,
            ]),
        ])->id($id);
    }

    /** OtpState 的 `length` 是**必填** Number（正整数），不是 optional 文本。 */
    public static function catalogOtpInput(string $id, int $length = 6): self
    {
        return self::reg('OtpInput', [self::state('OtpState', $id, [$length])])->id($id);
    }

    /** SliderState 只有一个 optional Number，且上游限定 0..100。 */
    public static function catalogSlider(string $id, float $initial = 0.0): self
    {
        return self::reg('Slider', [self::state('SliderState', $id, [$initial])])->id($id);
    }

    /** Calendar/ColorPicker/DatePicker/TimeField 四颗的 state 不吃实参。 */
    public static function catalogCalendar(string $id): self
    {
        return self::reg('Calendar', [self::state('CalendarState', $id)])->id($id);
    }

    public static function catalogColorPicker(string $id): self
    {
        return self::reg('ColorPicker', [self::state('ColorPickerState', $id)])->id($id);
    }

    public static function catalogDatePicker(string $id): self
    {
        return self::reg('DatePicker', [self::state('DatePickerState', $id)])->id($id);
    }

    public static function catalogTimeField(string $id): self
    {
        return self::reg('TimeField', [self::state('TimeFieldState', $id)])->id($id);
    }

    /** catalog `Textarea`：多行编辑，state 只有一个 optional 初值。 */
    public static function catalogTextarea(string $id, string $initial = ''): self
    {
        return self::reg('Textarea', [
            self::state('TextareaState', $id, [$initial === '' ? null : $initial]),
        ])->id($id);
    }

    /** catalog `Editor`：源码编辑器，`language` 是 optional 语法名（空 = 不传）。 */
    public static function catalogEditor(string $id, string $initial, string $language = ''): self
    {
        return self::reg('Editor', [
            self::state('EditorState', $id, [
                $initial,
                $language === '' ? null : $language,
            ]),
        ])->id($id);
    }

    /** catalog `Diff`：只读补丁视图，`mode` 是 optional 枚举（unified/split）。 */
    public static function catalogDiff(string $id, string $patch, string $mode = ''): self
    {
        return self::reg('Diff', [
            self::state('DiffState', $id, [
                $patch,
                $mode === '' ? null : $mode,
            ]),
        ])->id($id);
    }

    /**
     * catalog `Command`：命令面板。子节点契约是硬的（command.cpp:258-262 逐字校验）——
     * 只收 `CommandItem` / `CommandGroup` / `CommandSeparator`，挂错就是每帧错误节点。
     */
    public static function catalogCommand(string $id, array $children = []): self
    {
        return self::reg('Command', [self::state('CommandState', $id)], $children)->id($id);
    }

    // ── tier-A 差距清单的 catalog 工厂（2026-10-28 批1）──
    //
    // 这一批的共同前提：上游 component_shell 全部**已导出**（tier-A 的定义），
    // 工厂只是把 El::catalog('导出名', …) 的裸通道换成有名字、有校验、有默认值的糖。
    // 导出名的权威在各自 descriptor 文件里（下表每条注明）；写错名字的下场是
    // 降级成错误节点（registeredError() 报得出），cmd/check-upstream-parity.php
    // 会把这里出现过的每个导出名/方法名与上游表静态对一遍。

    /** Separator 变体 → 导出名（separator.cpp:90-97 kConstructors）。 */
    public const SEPARATOR_VARIANTS = [
        'horizontal' => 'Separator',
        'vertical' => 'VerticalSeparator',
        'dashed' => 'DashedSeparator',
        'vertical_dashed' => 'VerticalDashedSeparator',
    ];

    /**
     * 分割线。Ely divider.rs 的可嵌字 label("or") 上游**有**：Separator 的方法表
     * 带 label(string)/color(string)（separator.cpp:98-102），分别用既有链式糖
     * ->label() / ->color() 发；dashed 变体直接走 DashedSeparator 导出名，不用再发方法。
     */
    public static function separator(string $variant = 'horizontal'): self
    {
        if (!isset(self::SEPARATOR_VARIANTS[$variant])) {
            throw new \InvalidArgumentException(
                'Element::separator(): variant \'' . $variant . '\' 非法，允许 ['
                . implode(', ', array_keys(self::SEPARATOR_VARIANTS)) . ']'
            );
        }
        return self::reg(self::SEPARATOR_VARIANTS[$variant]);
    }

    /**
     * Tooltip：一颗自带受管提示文本的按钮触发器（lifecycle/tooltip.cpp）。
     * 三个构造实参都**必须非空**（空串会被 factory 拒、loud 报错）；
     * label 是按钮上的字，text 是悬停提示。
     *
     * 与实例方法 ->tooltip(string) 的区别：那是给任意 catalog 组件挂提示文本的
     * registered_method；这是 Tooltip 组件本身。同名冲突按 catalogButton 先例加前缀。
     */
    public static function catalogTooltip(string $id, string $label, string $text): self
    {
        return self::reg('Tooltip', [$id, $label, $text]);
    }

    /**
     * Skeleton 骨架屏：无构造实参，上游唯一变体是 ->secondary()（骨架次色）。
     * Ely 的 SkeletonText/Card/Table/Avatar 变体与 Shimmer 扫光上游没有对应导出——
     * 变体先用 div+样式链组合（见 examples/kit），真变体需要上游加导出名（tier B）。
     */
    public static function skeleton(): self
    {
        return self::reg('Skeleton');
    }

    /**
     * EmptyState 空状态：组装式工厂，内部发 Empty 五件套导出名。
     *
     * 默认（$headerSlot=false）走 Empty 根的**普通子节点**（empty.cpp:74-83 Materialize
     * Root：header/content 槽之后追加普通 children），media 固定发 variant('icon')：
     * Ely EmptyState 的图标形；$content（动作按钮区）给了才发 EmptyContent。
     *
     * $headerSlot=true 走上游原生 header 槽：EmptyHeader 的 media/title/description
     * 是方法实参 SchemaElement（empty.cpp:100-119 SlotValue 经 RecordSlot 方法表），
     * 由 elementArg 桥（cmd/patch-element-arg.sh：registered_method 的 SchemaElement
     * 实参先 emitTree 子元素拿 SpecId 再发方法）解锁；实参必须是注册侧 Empty 部件
     * 元素（RecordSlot 的 kind=Element 要求 + TakeElementAs 按 tag 取值——裸
     * icon/text 会被拒，见函数内注释）；根的 header 走具名槽。两条路视觉不同：
     * header 槽路的图标走上游 EmptyMedia 的 icon 形（内部 El::icon 的同族装饰元素），
     * 普通子节点路的 variant('icon') 是另一条链。
     *
     * $icon 是 El::icon 同一款形状串：icons/<base>.svg。
     */
    public static function emptyState(string $icon, string $title, string $description,
        ?self $content = null, bool $headerSlot = false): self
    {
        if ($headerSlot) {
            // header 槽路（patch-element-arg.sh 解锁）：EmptyHeader 的 media/title/
            // description 是方法实参 SchemaElement——经 elementArg 桥（子元素先建、
            // SpecId 回填）；根的 header 走具名槽（slot 通道原本就能喂元素）。
            // 旧路（children 五件套）保留（$headerSlot=false 默认）。
            $header = self::reg('EmptyHeader', []);
            // media/title/description 的实参必须是**注册侧 Empty 部件元素**（EmptyMedia/
            // EmptyTitle/EmptyDescription）——上游 RecordSlot（empty.cpp）要求
            // ComponentArgumentKind::Element，SlotValue 经 TakeElementAs 按 tag 取值：
            // 裸 icon/text 是无 tag 的普通元素，TakeElement 会报
            // "registered EmptyMedia materialized an incompatible element"。
            $header->elementArg('media',
                self::reg('EmptyMedia', [], [self::icon($icon)])->rm('variant', ['icon']));
            $header->elementArg('title', self::reg('EmptyTitle', [], [self::text($title)]));
            $header->elementArg('description',
                self::reg('EmptyDescription', [], [self::text($description)]));
            $root = self::reg('Empty');
            $root->slot('header', $header);
            if ($content !== null) {
                $root->child(self::reg('EmptyContent', [], [$content]));
            }
            return $root;
        }
        $media = self::reg('EmptyMedia', [], [self::icon($icon)])->rm('variant', ['icon']);
        $root = self::reg('Empty', [], [
            $media,
            self::reg('EmptyTitle', [], [self::text($title)]),
            self::reg('EmptyDescription', [], [self::text($description)]),
        ]);
        if ($content !== null) {
            $root->child(self::reg('EmptyContent', [], [$content]));
        }
        return $root;
    }

    /**
     * Badge 徽标：数字 / 圆点两种形（controls/display.cpp kBadgeMethods）。
     * $dot=true 发 dot()（CountBadge 形）；否则 $count>0 发 count(n)（0 会隐藏数字徽标，
     * 上游语义如此）；$max>0 发 max(n)（超过变 +n）。徽标盖在**普通子节点**上，
     * 用 ->child() 把宿主元素挂进来。语气色上游有 color(token)，用既有 ->color() 糖。
     */
    public static function badge(int $count = 0, bool $dot = false, int $max = 0): self
    {
        $e = self::reg('Badge');
        if ($dot) {
            $e->rm('dot', []);
        } elseif ($count > 0) {
            $e->rm('count', [(float) $count]);
        }
        if ($max > 0) {
            $e->rm('max', [(float) $max]);
        }
        return $e;
    }

    /**
     * Tag 标签：label 进普通子节点，variant 走 registered_method（Tag 方法表里
     * variant 枚举：primary/secondary/danger/success/warning/info）。
     * outline 用既有 ->outline() 糖；pill 圆角用 ->pill()（本文件链式糖一节）。
     */
    public static function tag(string $label, string $variant = 'primary'): self
    {
        self::enumCheck('tag', $variant, ['primary', 'secondary', 'danger', 'success', 'warning', 'info']);
        return self::reg('Tag', [], [self::text($label)])->rm('variant', [$variant]);
    }

    /**
     * 步骤条。$items 每项一个步骤标签（发 StepperItem 导出名）；$selected 零基
     * （selected_index）；$vertical 纵排；$onChangeId 给了就挂 on_change 回调，
     * 事件以 ($onChangeId, 'change') 路由到 App->on。Wizard 那种分步驱动按清单计划
     * 用 PHP 条件渲染+回调组合，不在本工厂的职责里。
     */
    public static function stepper(string $id, array $items, int $selected = 0,
        bool $vertical = false, ?string $onChangeId = null): self
    {
        $children = [];
        foreach ($items as $label) {
            $children[] = self::reg('StepperItem', [], [self::text((string) $label)]);
        }
        $e = self::reg('Stepper', [$id], $children);
        if ($selected > 0) {
            $e->rm('selected_index', [(float) $selected]);
        }
        if ($vertical) {
            $e->rm('vertical', [true]);
        }
        if ($onChangeId !== null && $onChangeId !== '') {
            $e->rm('on_change', [self::cb('change', $onChangeId)]);
        }
        return $e;
    }

    /** 单独一颗步骤（StepperItem 只收普通子节点当内容，typed_compound/mod.cpp:827-836）。 */
    public static function stepperItem(string $label, bool $disabled = false): self
    {
        $e = self::reg('StepperItem', [], [self::text($label)]);
        if ($disabled) {
            $e->rm('disabled', [true]);
        }
        return $e;
    }

    /**
     * 树形。children 只收 TreeItem（collections/tree.cpp:55 kAllowed），子项用
     * ->treeItem() 组；TreeItem 自身的初始展开态用 ->rm('expanded', [true]) 发
     * （TreeItem 方法表只有 expanded/disabled 两个，不值得各开一条糖）。
     */
    public static function tree(string $id, array $items): self
    {
        return self::reg('Tree', [$id], $items);
    }

    /** 树节点：id+label 都必填非空（tree.cpp:295），子节点再收 TreeItem。 */
    public static function treeItem(string $id, string $label): self
    {
        return self::reg('TreeItem', [$id, $label]);
    }

    /**
     * 分页。$current/$total 是 1 基页号与总页数（0 不发，用上游默认）；
     * $visible 限制可见页钮数；$onChangeId 给了就挂 on_change，事件以
     * ($onChangeId, 'change') 路由。compact 形用 ->rm('compact', [])。
     */
    public static function pagination(string $id, int $current = 0, int $total = 0,
        int $visible = 0, ?string $onChangeId = null): self
    {
        $e = self::reg('Pagination', [$id]);
        if ($current > 0) { $e->rm('current_page', [(float) $current]); }
        if ($total > 0) { $e->rm('total_pages', [(float) $total]); }
        if ($visible > 0) { $e->rm('visible_pages', [(float) $visible]); }
        if ($onChangeId !== null && $onChangeId !== '') {
            $e->rm('on_change', [self::cb('change', $onChangeId)]);
        }
        return $e;
    }

    /**
     * 评分（兼只读 Stars 展示）：readOnly 走 common behavior 的 disabled 位
     * （Rating 方法表带 kDisabledMethod，materialize 把 Method op 'disabled' 读进
     * request->disabled——Element::disabled() 发的正是这个名字）。$onChangeId 给了
     * 就挂 on_change，事件以 ($onChangeId, 'change') 路由。
     */
    public static function rating(string $id, float $value = 0.0, int $max = 0,
        bool $readOnly = false, ?string $onChangeId = null): self
    {
        $e = self::reg('Rating', [$id]);
        if ($value > 0) { $e->rm('value', [$value]); }
        if ($max > 0) { $e->rm('max', [(float) $max]); }
        if ($readOnly) { $e->disabled(true); }
        if ($onChangeId !== null && $onChangeId !== '') {
            $e->rm('on_change', [self::cb('change', $onChangeId)]);
        }
        return $e;
    }

    /** 键帽：keystroke 一个非空串（controls/text.cpp kKeystrokeArgs）；outline 用 ->outline() 糖。 */
    public static function kbd(string $keystroke): self
    {
        return self::reg('Kbd', [$keystroke]);
    }

    /** 组合键糖：一次发多颗 Kbd，横排包一层（Ely KbdCombo 的形状）。 */
    public static function kbdCombo(array $keys): self
    {
        $e = self::hFlex([]);
        foreach ($keys as $k) {
            $e->child(self::kbd((string) $k));
        }
        return $e->gap(4.0)->itemsCenter();
    }

    /**
     * 微光文字（chat.cpp kShimmerConstructors）。变体走 rm：duration_ms/spread/
     * reverse/once（ShimmerText 方法表，chat.cpp:590-600）。
     */
    public static function shimmerText(string $text): self
    {
        return self::reg('ShimmerText', [$text]);
    }

    /**
     * 批7 特效三件（唯一从零写 C++ widget 的批）：
     *
     * Typewriter 打字机文本——逐字 reveal，MotionAppear 动画，speed = ms/字符。
     * descriptor 在 controls/text.cpp（cmd/patch-text-effects.sh）。ctorArgs = [text]。
     * 方法：speed(float)/id(string)/color(string)。
     */
    public static function typewriter(string $text): self
    {
        return self::reg('Typewriter', [$text]);
    }

    /**
     * GradientText 渐变文字——静态渐变，每字 RgbaMix(from,to,t)，无动画。
     * ctorArgs = [text, from, to]（from/to 是颜色字符串）。方法：id(string)。
     */
    public static function gradientText(string $text, string $from, string $to): self
    {
        return self::reg('GradientText', [$text, $from, $to]);
    }

    /**
     * AnimatedNumber 数字滚动——count_up 动画，MotionAppear 0→value。
     * ctorArgs = [id, value, decimals]。方法：color(string)/pad(int)。
     * id 是 MotionName 键（动画跨帧续跑）+ 事件路由键。
     */
    public static function animatedNumber(string $id, float $value, int $decimals = 0): self
    {
        return self::reg('AnimatedNumber', [$id, $value, $decimals])->id($id);
    }

    /**
     * Dialog 家族：真按钮触发的原生窗口效果（window_effects/mod.cpp）。构造三实参
     * id/label/on_effect_error：label 是触发按钮的字，on_effect_error 是效果失败
     * 的上报回调——这里自动以 ($id-error, 'click') 挂上（通常无人接，只在效果
     * 失败时才有事件）。
     *
     * $content 是弹层展开后的正文，走 **content 具名槽**——Dialog/Sheet 对它是硬约束
     * （window_effects/mod.cpp:467-476：没有 content 槽就 Fail "require exactly one
     * content(element) named slot"，且**只**收这一个槽名）；AlertDialog/Notification
     * 则相反、拒绝任何具名槽（正文走 description 方法）。
     *
     * $okId/$cancelId 给了就发 on_ok/on_cancel（Dialog）；$closeId 发 on_close。
     * Ely 的 ConfirmDialog/PromptDialog 内容预设上游没有导出：Confirm 形就是
     * dialog + okId + cancelId；Prompt 形还需在弹层里放输入框——content 槽里挂
     * El::catalogInput() 就能凑出来，示例里见（kit 树 3 故意没挂，避免多余实体）。
     */
    public static function dialog(string $id, string $label, self $content,
        string $title = '', ?string $okId = null, ?string $cancelId = null,
        ?string $closeId = null): self
    {
        $e = self::reg('Dialog', [$id, $label, self::cb('click', $id . '-error')]);
        $e->slot('content', $content);
        if ($title !== '') { $e->rm('title', [$title]); }
        if ($okId !== null && $okId !== '') { $e->rm('on_ok', [self::cb('click', $okId)]); }
        if ($cancelId !== null && $cancelId !== '') { $e->rm('on_cancel', [self::cb('click', $cancelId)]); }
        if ($closeId !== null && $closeId !== '') { $e->rm('on_close', [self::cb('click', $closeId)]); }
        return $e;
    }

    /** AlertDialog：Dialog 家族的正文走 description 方法（它**拒绝**具名槽）。 */
    public static function alertDialog(string $id, string $label, string $description,
        bool $showCancel = true, string $title = '', ?string $okId = null,
        ?string $cancelId = null): self
    {
        $e = self::reg('AlertDialog', [$id, $label, self::cb('click', $id . '-error')]);
        if ($title !== '') { $e->rm('title', [$title]); }
        if ($description !== '') { $e->rm('description', [$description]); }
        $e->rm('show_cancel', [$showCancel]);
        if ($okId !== null && $okId !== '') { $e->rm('on_ok', [self::cb('click', $okId)]); }
        if ($cancelId !== null && $cancelId !== '') { $e->rm('on_cancel', [self::cb('click', $cancelId)]); }
        return $e;
    }

    /** Sheet：贴边抽屉，placement ∈ top/right/bottom/left；$content 同 Dialog 的硬约束。 */
    public static function sheet(string $id, string $label, self $content,
        string $placement = 'bottom', ?string $closeId = null): self
    {
        self::enumCheck('sheet', $placement, ['top', 'right', 'bottom', 'left']);
        $e = self::reg('Sheet', [$id, $label, self::cb('click', $id . '-error')]);
        $e->slot('content', $content);
        $e->rm('placement', [$placement]);
        if ($closeId !== null && $closeId !== '') { $e->rm('on_close', [self::cb('click', $closeId)]); }
        return $e;
    }

    /**
     * 描述列表。$items 混收两种：DescriptionItem Element（需要 span 跨列时直接用
     * ->descriptionItem() 造），或 label => value 的关联表项（内部发
     * DescriptionItem 导出名）；$vertical 纵排、$bordered 横排时的边框。
     */
    public static function descriptionList(array $items, bool $vertical = false,
        bool $bordered = false): self
    {
        $children = [];
        foreach ($items as $label => $value) {
            $children[] = $value instanceof self
                ? $value
                : self::descriptionItem((string) $label, (string) $value);
        }
        $e = self::reg('DescriptionList', [], $children);
        if ($vertical) { $e->rm('vertical', []); }
        if ($bordered) { $e->rm('bordered', [true]); }
        return $e;
    }

    /** 描述列表的一项：label 进构造，value 走方法（横排格子的右半）。 */
    public static function descriptionItem(string $label, string $value = ''): self
    {
        $e = self::reg('DescriptionItem', [$label]);
        if ($value !== '') { $e->rm('value', [$value]); }
        return $e;
    }

    /**
     * 高级表格。构造吃三实参：DataTableState 实体（列名非空且唯一——上游 NewState
     * 逐条验）+ rows 回调 + render_cell 回调（data_table/mod.cpp kArguments）。
     *
     * ⚠ **数据通道今天是断的**：rows/render_cell 必须是"返回数据/元素"的回调，
     * 而 shim 回调通道只会发事件、不带返回值（runtime.cpp:12922 的 shimId 分支
     * 转 enqueue 后即返回）——真渲染时上游 SnapshotRowsWith 会拿到 true 而不是数组，
     * 当帧 Fail "DataTable rows callback must return an array of rows"。所以本工厂
     * 现在只能建出结构并把失败**响亮地**报出来（examples/kit 的
     * dataTableRowsCallbackLoud 门钉着这条）；数据通道要走 examples/_shared 的
     * listRenderer 同款宿主回路（patch-host-list.sh 的形状），属 tier B。
     * stripe/sortable/column_resizable 等行为参数用 ->rm() 透传（都在 kMethods）。
     * to_csv 是纯数据变换，见 tableToCsv()。
     */
    public static function dataTable(string $id, array $columns): self
    {
        return self::reg('DataTable', [
            self::state('DataTableState', $id, [$columns]),
            self::cb('render_rows', $id),
            self::cb('render_cell', $id),
        ])->id($id);
    }

    /** 行集 → CSV 文本（Ely to_csv 的 PHP 侧对应物，纯数据变换，不碰渲染）。 */
    public static function tableToCsv(array $columns, array $rows): string
    {
        $cell = static function (mixed $v): string {
            $s = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
            if (strpbrk($s, ",\"\n\r") !== false) {
                return '"' . str_replace('"', '""', $s) . '"';
            }
            return $s;
        };
        $lines = [implode(',', array_map($cell, $columns))];
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $c) {
                $line[] = $cell(is_array($row) ? ($row[$c] ?? '') : '');
            }
            $lines[] = implode(',', $line);
        }
        return implode("\n", $lines);
    }

    /**
     * 设置页族：Settings > SettingPage > SettingGroup > SettingItem 四层
     * （settings/mod.cpp，typed children 逐层校验 kAllowed）。$pages/$groups/$items
     * 各是下一层的 Element 数组；SettingItem 的字段控件经 content 槽挂入
     * （MaterializeItem 取 TakeSlotFactory("content")，缺了就 Fail），所以
     * settingItem 必须给 $field。搜索关键词/字段排布用 ->rm('keywords'/'layout') 透传。
     */
    public static function settings(string $id, array $pages, int $defaultPage = 0): self
    {
        $e = self::reg('Settings', [$id], $pages);
        if ($defaultPage > 0) { $e->rm('default_selected_page', [(float) $defaultPage]); }
        return $e;
    }

    /** 设置页：title 进构造；groups 只收 SettingGroup。 */
    public static function settingPage(string $title, array $groups): self
    {
        return self::reg('SettingPage', [$title], $groups);
    }

    /** 设置分组：title 走方法（构造无实参）；items 只收 SettingItem。 */
    public static function settingGroup(string $title, array $items): self
    {
        return self::reg('SettingGroup', [], $items)->rm('title', [$title]);
    }

    /** 设置项：title 进构造，$field 是右侧的字段控件（content 槽）。 */
    public static function settingItem(string $title, self $field): self
    {
        return self::reg('SettingItem', [$title])->slot('content', $field);
    }

    /**
     * 轮播：组装式工厂，一次发齐八件套（Carousel/Content/Item/Previous/Next/
     * Pagination/PaginationItem + 共享 CarouselState，carousel.cpp kAllowed 与
     * kIndexedArgs）。八处构造引用**同一颗** state 实体（El::state 按 (kind,key)
     * 缓存，key=$id）——各写各的 key 就是五颗互不相干的轮播（catalog 域踩过的错）。
     * $items 每项一颗内容 Element。
     */
    public static function carousel(string $id, array $items): self
    {
        $n = count($items);
        $st = self::state('CarouselState', $id, [$n, 0, 'horizontal', true]);
        $content = self::reg('CarouselContent', [$st], []);
        $i = 0;
        foreach ($items as $item) {
            $content->child(self::reg('CarouselItem', [$id . '-item-' . $i, (float) $i, $st], [$item]));
            $i++;
        }
        $pagination = self::reg('CarouselPagination', [], []);
        for ($j = 0; $j < $n; $j++) {
            $pagination->child(self::reg('CarouselPaginationItem', [$id . '-dot-' . $j, (float) $j, $st], []));
        }
        return self::reg('Carousel', [$id, $st], [
            $content,
            self::reg('CarouselPrevious', [$st], []),
            self::reg('CarouselNext', [$st], []),
            $pagination,
        ]);
    }

    // ── 「纯工厂热身」批（2026-10-28）：五颗纯 reg() 通道工厂 ──
    //
    // Spinner / TableFooter / GroupBox / VForm / InputGroupText。与上面 tier-A 批
    // 同一条管线的最纯形态：五颗组件上游**早已导出**（本批零上游改动、零 shim
    // 改动），工厂只是把 El::catalog('导出名', …) 换成有名字、有默认值、有枚举
    // 预校验的糖。真调用与结构门在 examples/kit（spinnerShape 等五门），文档在
    // components.md「kit 工厂批」一节。

    /** Spinner 图标字面量（spinner.cpp:112 kIconLiterals）。 */
    public const SPINNER_ICONS = ['loader', 'loader_circle'];

    /** Spinner 旋转缓动字面量（spinner.cpp:113-114 kEaseLiterals）。 */
    public const SPINNER_EASES = ['linear', 'ease_in_out', 'ease_out_quint'];

    /**
     * 加载指示器（spinner.cpp）。无构造实参、**不收子节点**——Materialize 只把
     * 旋转图标包进一颗 Div，children 从不被取用（传了就是无声丢弃）。
     * size 用既有 ->sizeEnum()（kSizeLiterals 与 El::SIZES 同表）、color 用
     * ->color()（上游 ParseColorArgument 收色串）；icon/ease 是本组件私有枚举，
     * 用 ->spinnerIcon() / ->spinnerEase()（白名单预校验，拼错当场抛）。
     * 旋转是逐帧动画：进像素基线必须 ->hidden()（同 Skeleton 的相位抖动）。
     */
    public static function spinner(): self
    {
        return self::reg('Spinner');
    }

    public function spinnerIcon(string $name): self
    {
        self::enumCheck('spinnerIcon', $name, self::SPINNER_ICONS);
        return $this->catalogMethod('icon', [$name]);
    }

    public function spinnerEase(string $ease): self
    {
        self::enumCheck('spinnerEase', $ease, self::SPINNER_EASES);
        return $this->catalogMethod('ease', [$ease]);
    }

    /**
     * 表格页脚行组（structured/table.cpp:270-297 kFooter）。**只有 registered 一条路**：
     * shell 的 ComponentKind 枚举里没有 TableFooter（spec.h 的 Table 段止于
     * TableCaption），shim 的 kindFromString 到不了它。children 只收 TableRow
     * （MaterializeGroup 的 kAllowed 逐字校验），且必须是**注册侧**的行——
     * TakeTypedChildren 只给 Registered 子节点记 componentName（component_registry.cpp
     * 的实现），kind 版 El::tableRow() 记出来是空名，挂进来每帧报错误节点；
     * 行与格用 El::catalog('TableRow') / El::catalog('TableCell') 组。
     * 挂在注册侧 Table 下：kAllowed = {TableHeader, TableBody, TableFooter,
     * TableCaption}（table.cpp:186-187）。基座 kind 表（El::table()）是无样式
     * div 族（base/table.h），与这套主题表格不是一个家族，别混。
     */
    public static function tableFooter(array $children = []): self
    {
        return self::reg('TableFooter', [], $children);
    }

    /**
     * 标题分组盒（display/group_box.cpp）。构造无实参；title 走方法（->title()
     * 既有糖），children 是普通子节点（TakeChildren，不挑名字）。
     * variant 的字面量集是 normal/fill/outline（group_box.cpp:78）——与 Tag 的
     * 语气色不是一组，->variant() 的白名单已按 descriptor 并表（见该方法的注释）。
     */
    public static function groupBox(string $title, array $children = []): self
    {
        return self::reg('GroupBox', [], $children)->title($title);
    }

    /**
     * 纵排 Form 的第二导出名（form.cpp:276-280——'VForm' 与 'Form' 是同一构造器
     * ConstructForm<false> 的两个名字，descriptor 同一张方法表）。形状与
     * El::form() 全同：children 只收 Field（kAllowed 逐字校验）。何时用它而非
     * 'Form'：对着上游示例/文档的名字逐字复刻时。
     */
    public static function vForm(array $fields = []): self
    {
        return self::reg('VForm', [], $fields);
    }

    /**
     * 输入组里的弱化文字（input_group/mod.cpp:573-574 构造、710-714 descriptor）。
     * 无构造实参、无方法、普通子节点（FinishPart 照单全收），$text 包成一颗 text。
     * 今天只能单放：整个 InputGroup 组合拼不齐——组的 addon 方法吃 SchemaElement
     * 实参（shim 通道给不出 SpecId，同 EmptyHeader media/title 的缺口）、input 槽
     * 要 InputGroupInput/Textarea（另需 retained state 实体）。装饰件本身独立渲染。
     */
    public static function inputGroupText(string $text): self
    {
        return self::reg('InputGroupText', [], [self::text($text)]);
    }

    // ── 「带前置作业的 S 工厂 + 组合糖」批（2026-10-28）──
    //
    // Label / Toolbar / StatusBar / Clipboard / HorizontalRadioGroup（tier-A 尾洞
    // 收尾）+ SplitButton（组合糖）。与批1 同一条 reg() 管线，但每颗带一项开工前
    // 作业的结论（写工厂前逐个核过 descriptor）：
    //  · Label 的 highlights 实参 schema 是 **SchemaString**（text.cpp:193-194，
    //    单个匹配串），不是清单里猜的数组；
    //  · StatusBar 的 left_content/right_content 吃 **SchemaElement**
    //    （status_bar.cpp:59-60）——shim 的 Element 分支只会把一个数字当 SpecId
    //    （gpui_shim.cpp toComponentArg），PHP 侧给不出，只发中段（普通子节点）；
    //  · Clipboard 的复制是部件内置行为（ui/clipboard.cpp OnCopy →
    //    ClipboardSetText），descriptor 没有 on_copied 导出，合成 fire 到不了；
    //  · SplitButton 上游与参考仓均无 widget（grep 零命中），是组合形。

    /**
     * 文本标签（controls/text.cpp:199-215）。构造一个非空字符串（上游拒空串）；
     * 次级灰字/掩码/搜索高亮走 ->secondaryLabel() / ->masked() / ->labelHighlights()。
     *
     * 为什么不叫 El::label()：本类已有实例糖 ->label(string)（Button/Field 的标题），
     * PHP 不允许同一类里静态/实例同名方法并存——按 catalogTooltip 先例加 catalog
     * 前缀。语义区别同 catalogButton：这是 Label 组件本身，那是给别的组件挂标题。
     */
    public static function catalogLabel(string $text): self
    {
        if ($text === '') {
            throw new \InvalidArgumentException(
                'Element::catalogLabel(): text 不能为空（上游 ConstructLabel 拒空串）'
            );
        }
        return self::reg('Label', [$text]);
    }

    /** Label 的次级灰字（registered_method 'secondary'，text.cpp:204）。 */
    public function secondaryLabel(string $s): self
    {
        return $this->catalogMethod('secondary', [$s]);
    }

    /** Label 的掩码显示（registered_method 'masked'，text.cpp:206——密文/敏感值形）。 */
    public function masked(bool $v = true): self
    {
        return $this->catalogMethod('masked', [$v]);
    }

    /**
     * Label 的搜索高亮（registered_method 'highlights'）。实参是**单个匹配串**
     * （SchemaString，shell 侧 label->Highlights(Str) 走 Full 全文匹配，
     * text.cpp:123）——不是数组，上游也没有 prefix 变体的导出参数。
     */
    public function labelHighlights(string $s): self
    {
        return $this->catalogMethod('highlights', [$s]);
    }

    /**
     * 工具栏容器（display/toolbar.cpp:36-44）。id 非空；**无方法表**；普通子节点
     * 按源序进 Contents（"transparent, sizable toolbar container"）——按钮/输入框
     * 等直接 ->child() 挂进来。
     */
    public static function toolbar(string $id, array $children = []): self
    {
        return self::reg('Toolbar', [$id], $children);
    }

    /**
     * 三段状态条（display/status_bar.cpp:62-74）。构造无实参；**普通子节点填中段**
     * （Materialize 逐颗 bar->Center()）。left_content/right_content 两方法吃
     * SchemaElement 实参——shim 的 Element 分支只会把一个数字当 SpecId
     * （gpui_shim.cpp toComponentArg 的 Element case），PHP 侧给不出合法实参，
     * 与 EmptyHeader media/title 同一个 shim 缺口（tier B）。本工厂**不发**这两个
     * 方法；硬发 ->rm('left_content', …) 的下场见 kit selftest 的
     * statusBarElementArgLoud 探针（放最后，理由同 dataTable 探针）。
     */
    public static function statusBar(array $center = []): self
    {
        return self::reg('StatusBar', [], $center);
    }

    /**
     * 复制按钮（display/clipboard.cpp:69-76）。构造 id 非空；value/tooltip 走方法；
     * **拒 children**（EnsureNoChildren）。点击复制是部件内置行为（ui/clipboard.cpp
     * 的 ClipboardState::OnCopy → ClipboardSetText）：descriptor 没有 on_copied
     * 导出、没有 PHP 回调，selftest 的合成 fire 按 (id,type) 查回调表——查无此名
     * 必返回 0，真复制要真人点击（kit 的 clipboardCopyRoute 门前提式钉这个事实，
     * 未验不判红，参照 focusRovingTab 先例）。
     */
    public static function clipboard(string $id, string $value = '', string $tooltip = ''): self
    {
        $e = self::reg('Clipboard', [$id]);
        if ($value !== '') { $e->rm('value', [$value]); }
        if ($tooltip !== '') { $e->rm('tooltip', [$tooltip]); }
        return $e;
    }

    /**
     * 横排受控单选组（typed_compound/mod.cpp:456-465 'HorizontalRadioGroup'——与
     * 'RadioGroup' 同一构造器模板的 Axis::Horizontal 分支）。children 只收**注册侧**
     * Radio（kRadioName={"Radio"} 逐字校验；compound/radio.cpp:140 的 ctor id +
     * label 方法）——kind 版 El::radio() 没有 componentName，挂进来每帧报错误节点。
     * $radios 收标签串，内部发 Radio（id 派生 $id-r<i>）；$selected 零基走
     * selected_index；$onChangeId 给了就挂 on_change，事件以 ($onChangeId,'change')
     * 路由（值是选中下标；合成 fire 只验"到了 PHP"，分工见 kitChangeRoutes 注）。
     */
    public static function hRadioGroup(string $id, array $radios, int $selected = 0,
        ?string $onChangeId = null): self
    {
        $children = [];
        $i = 0;
        foreach ($radios as $label) {
            $children[] = self::reg('Radio', [$id . '-r' . $i])->label((string) $label);
            $i++;
        }
        $e = self::reg('HorizontalRadioGroup', [$id], $children);
        if ($selected > 0) { $e->rm('selected_index', [(float) $selected]); }
        if ($onChangeId !== null && $onChangeId !== '') {
            $e->rm('on_change', [self::cb('change', $onChangeId)]);
        }
        return $e;
    }

    /**
     * 分裂按钮（**组合糖，非独立导出**）：上游与参考仓都没有 SplitButton widget
     * （grep src/ 零命中；Ely 的实现 menus/hosts.rs 本身就是"按钮+挂 Menu"的
     * 组合件，不值得为它写新 widget）。本工厂只发 DropdownMenu 导出名：$label 是
     * 触发钮文本，$menuItems 每项一个菜单标签，item 回调 id 派生为 $id-item-<i>
     * （事件以 (该id,'click') 路由到 App->on）。要"主动作 + 菜单"双通道的场景，
     * 用 El::catalogButton() + El::dropdownMenu() 各发一颗再排一起——那才是两个
     * 独立组件的组合；本糖只是把"纯菜单触发钮"这最常见的一形起了名字。
     */
    public static function splitButton(string $id, string $label, array $menuItems = []): self
    {
        $menu = self::reg('DropdownMenu', [$id, $label]);
        $i = 0;
        foreach ($menuItems as $item) {
            $menu->item((string) $item, $id . '-item-' . $i);
            $i++;
        }
        return $menu;
    }

    // ── 「批3 弹层/显示/图表 M 工厂」（结构 + 响亮失败态）──
    //
    // 六颗 tier-A 收尾 + 五连图表。全走既有通道：El::cb 标记（menu_item/on_click/
    // rows 回调）、枚举白名单常量（与批1/批2 同形状）、数组实参先例。上游零改动。
    // 共同形态：回调实参的组件（Notification 的 on_* 回调、DropdownButton 的
    // menu_item 回调、图表的 rows 回调）sham 通道只发事件不带返回值——真数据等批6；
    // 本批只交"建结构 + 失败响亮可见"（图表负向渲染给出 "Failed to build … data"
    // 错误文本节点，见 kit selftest 的 chartRowsLoud 门）。

    /** Notification 的 type 枚举（window_effects/mod.cpp:642 kTypeLiterals）。 */
    public const NOTIFICATION_TYPES = ['info', 'success', 'warning', 'error'];

    /** DropdownButton 的 variant 枚举（basic/dropdown_button.cpp kVariantLiterals）。 */
    public const DB_DROPDOWN_VARIANTS = ['primary', 'secondary', 'danger', 'ghost'];

    /** DropdownButton 的 menu_anchor 枚举（dropdown_button.cpp kAnchorLiterals）。 */
    public const DB_DROPDOWN_ANCHORS = ['top_right', 'bottom_right', 'bottom_left', 'top_left'];

    /** Bubble 的 alignment 枚举（chat.cpp kAlignmentLiterals）。上游字面量是
     * {"start", ""}（第二个是空字面量，实际有效值就是 'start'）。 */
    public const BUBBLE_ALIGNMENTS = ['start'];

    /** Bubble 的 variant 枚举（chat.cpp kBubbleVariantLiterals）。 */
    public const BUBBLE_VARIANTS = ['filled', 'secondary', 'muted', 'tinted', 'outline', 'ghost', 'destructive'];

    /** Marker 的 variant 枚举（chat.cpp kMarkerVariantLiterals）。 */
    public const MARKER_VARIANTS = ['plain', 'separator', 'border'];

    /** Marker 的 loading_style 枚举（chat.cpp kLoadingStyleLiterals）。 */
    public const MARKER_LOADING_STYLES = ['spinner', 'shimmer'];

    /** Attachment 的 status 枚举（chat.cpp kStatusLiterals）。 */
    public const ATTACHMENT_STATUS = ['pending', 'uploading', 'processing', 'failed', 'complete'];

    /** Attachment 的 axis 枚举（chat.cpp kAxisLiterals）。 */
    public const ATTACHMENT_AXES = ['horizontal', 'vertical'];

    /**
     * 通知（window_effects/mod.cpp:730-738）。catalog 组件版 Notification——与
     * App::notify() 是**两条路**（catalog 版没有宿主保留态窗层，见
     * docs/guide/upstream-coverage.md 的分工段）：catalog 版是 descriptor 的
     * Notification 导出名，本工厂按 dialog 家族先例（auto 挂 on_effect_error 回调）。
     * 构造三实参 id/label/on_effect_error 必填；message/type/on_click/on_close/
     * autohide 走方法糖（内部 rm）。
     */
    public static function catalogNotification(string $id, string $label,
        string $message = '', string $type = 'info',
        ?string $onClickId = null, ?string $onCloseId = null, bool $autohide = false): self
    {
        $e = self::reg('Notification', [$id, $label, self::cb('click', $id . '-error')]);
        if ($message !== '') { $e->rm('message', [$message]); }
        if ($type !== '') { self::enumCheck('notificationType', $type, self::NOTIFICATION_TYPES); $e->rm('type', [$type]); }
        if ($onClickId !== null && $onClickId !== '') {
            $e->rm('on_click', [self::cb('click', $onClickId)]);
        }
        if ($onCloseId !== null && $onCloseId !== '') {
            $e->rm('on_close', [self::cb('click', $onCloseId)]);
        }
        $e->rm('autohide', [$autohide]);
        return $e;
    }

    public function notificationOnClick(string $id = ''): self
    {
        return $this->catalogMethod('on_click', [self::cb('click', $id)]);
    }

    public function notificationOnClose(string $id = ''): self
    {
        return $this->catalogMethod('on_close', [self::cb('click', $id)]);
    }

    /** 变体枚举允许集 normal/fill/outline 之外，这里按 descriptor 单独白名单（dropdown 糖先例）。 */
    public static function dropdownButton(string $id, string $label,
        string $variant = 'primary', ?string $anchor = null, string $onClickId = '',
        array $menuItems = []): self
    {
        $e = self::reg('DropdownButton', [$id, $label]);
        if ($variant !== '') {
            self::enumCheck('dropdownButtonVariant', $variant, self::DB_DROPDOWN_VARIANTS);
            $e->rm('variant', [$variant]);
        }
        if ($anchor !== null && $anchor !== '') {
            self::enumCheck('dropdownButtonAnchor', $anchor, self::DB_DROPDOWN_ANCHORS);
            $e->rm('menu_anchor', [$anchor]);
        }
        if ($onClickId !== '') {
            $e->rm('on_click', [self::cb('click', $onClickId)]);
        }
        $i = 0;
        foreach ($menuItems as $item) {
            $e->dropdownMenuItem((string) $item, $id . '-item-' . $i);
            $i++;
        }
        return $e;
    }

    /** DropdownButton 的 item 方法糖（与 dropdownMenu->item() 同形：label + cb 标记）。 */
    public function dropdownMenuItem(string $label, string $itemId = ''): self
    {
        return $this->catalogMethod('menu_item', [$label, self::cb('click', $itemId)]);
    }

    public function dropdownButtonVariant(string $v): self
    {
        self::enumCheck('dropdownButtonVariant', $v, self::DB_DROPDOWN_VARIANTS);
        return $this->catalogMethod('variant', [$v]);
    }

    public function dropdownButtonAnchor(string $a): self
    {
        self::enumCheck('dropdownButtonAnchor', $a, self::DB_DROPDOWN_ANCHORS);
        return $this->catalogMethod('menu_anchor', [$a]);
    }

    public function dropdownButtonOnClick(string $id = ''): self
    {
        return $this->catalogMethod('on_click', [self::cb('click', $id)]);
    }

    /**
     * 消息气泡（chat.cpp：ctor 无实参；方法 alignment/variant）。普通子节点是其可见内容。
     * 枚举白名单分开（alignment 两端对齐、variant 七种语气形）。
     */
    public static function bubble(string $align = '', string $variant = ''): self
    {
        $e = self::reg('Bubble');
        if ($align !== '') {
            self::enumCheck('bubbleAlignment', $align, self::BUBBLE_ALIGNMENTS);
            $e->rm('alignment', [$align]);
        }
        if ($variant !== '') {
            self::enumCheck('bubbleVariant', $variant, self::BUBBLE_VARIANTS);
            $e->rm('variant', [$variant]);
        }
        return $e;
    }

    /** 聊天消息行（chat.cpp：ctor 无实参；方法 alignment）。普通子节点是消息正文。 */
    public static function message(string $align = '', array $children = []): self
    {
        $e = self::reg('Message');
        if ($align !== '') {
            self::enumCheck('messageAlignment', $align, self::BUBBLE_ALIGNMENTS);
            $e->rm('alignment', [$align]);
        }
        foreach ($children as $c) { $e->child($c); }
        return $e;
    }

    /**
     * 聊天状态标记（chat.cpp Marker 段）：ctor id 非空；方法 variant/loading/loading_style。
     */
    public static function marker(string $id, string $variant = '', bool $loading = false,
        string $loadingStyle = ''): self
    {
        $e = self::reg('Marker', [$id]);
        if ($variant !== '') {
            self::enumCheck('markerVariant', $variant, self::MARKER_VARIANTS);
            $e->rm('variant', [$variant]);
        }
        if ($loading) { $e->rm('loading', [true]); }
        if ($loadingStyle !== '') {
            self::enumCheck('markerLoadingStyle', $loadingStyle, self::MARKER_LOADING_STYLES);
            $e->rm('loading_style', [$loadingStyle]);
        }
        return $e;
    }

    /**
     * 附件（chat.cpp Attachment 段）：ctor id 非空；方法 status/axis/size 三个枚举。
     */
    public static function attachment(string $id, string $status = 'pending',
        string $axis = 'horizontal', string $size = 'medium'): self
    {
        $e = self::reg('Attachment', [$id]);
        if ($status !== '') {
            self::enumCheck('attachmentStatus', $status, self::ATTACHMENT_STATUS);
            $e->rm('status', [$status]);
        }
        if ($axis !== '') {
            self::enumCheck('attachmentAxis', $axis, self::ATTACHMENT_AXES);
            $e->rm('axis', [$axis]);
        }
        if ($size !== '') {
            self::enumCheck('attachmentSize', $size, self::SIZES);
            $e->rm('size', [$size]);
        }
        return $e;
    }

    /**
     * 消息滚动区（chat.cpp MessageScroller 段）：ctor 三实参 id/state/render_item，
     * 其中 render_item 是**返回元素的行渲染回调**——shim 回调通道只发事件不带返回值
     * （DataTable 实证，Element.php:1797-1806），真数据等批6宿主回路；本批只建结构：
     * state 经 El::state 标记（按 (kind,key) 缓存，同 CarouselState 先例），render_item
     * 发 cb 标记；scrollbar/jump_button/jump_button_label 三个零参方法照发（descriptor
     * 方法表，上游 materialize 会读）。**不进任何树**（会让 componentStates 计数涨，
     * 破坏 tree2CarouselState 的 ==1 门）。
     */
    public static function messageScroller(string $id, int $itemCount = 0,
        ?string $onChangeId = null): self
    {
        $e = self::reg('MessageScroller', [
            $id,
            self::state('MessageScrollerState', $id, [$itemCount]),
            self::cb('render_item', $id),
        ])->id($id);
        $e->rm('scrollbar', []);
        $e->rm('jump_button', []);
        $e->rm('jump_button_label', ['']);
        if ($onChangeId !== null && $onChangeId !== '') {
            $e->rm('on_change', [self::cb('change', $onChangeId)]);
        }
        return $e;
    }

    /**
     * 图表五连（chart/mod.cpp：BarChart/LineChart/AreaChart/PieChart/RadarChart，
     * kBarName…kRadarName:495-499）。每个构造只吃 **rows 回调**
     * （"(cx: Context) => readonly { label: string; value: number }[]"，
     * chart/mod.cpp:424-427）——回调通道只发事件不带返回值（DataTable 实证），
     * 真数据等批6宿主数据回路。本批只建结构（发 rows cb 标记，cb 里带固定 demo id
     * 'kit-chart'）；负向响亮失败门未落地（chart/mod.cpp Render 的错误文本路径在
     * native 渲染负向时进程段错误、且 ChartErrorProbe 探针 shim 无安装/读口），
     * 卡点见 kit selftest 的 skipped 记录，不假装门过。导出名本轮已实核（chart/mod.cpp:495-499）。
     *
     * 参数里没有 $id：图表 ctor 只吃 rows（schema 按位置对），id 的注册通道要求
     * 进 ctorArgs[0]（ElementTest 的 $id 位置门），此类不适用；节点 id 由 materialize
     * 按 request->elementId 取（chart/mod.cpp Render），固定 demo id 即可。
     */
    public static function barChart(): self
    {
        return self::reg('BarChart', [self::cb('render_rows', 'kit-chart')])->id('kit-chart');
    }
    public static function lineChart(): self
    {
        return self::reg('LineChart', [self::cb('render_rows', 'kit-chart')])->id('kit-chart');
    }
    public static function areaChart(): self
    {
        return self::reg('AreaChart', [self::cb('render_rows', 'kit-chart')])->id('kit-chart');
    }
    public static function pieChart(): self
    {
        return self::reg('PieChart', [self::cb('render_rows', 'kit-chart')])->id('kit-chart');
    }
    public static function radarChart(): self
    {
        return self::reg('RadarChart', [self::cb('render_rows', 'kit-chart')])->id('kit-chart');
    }
    /**
     * CandlestickChart 蜡烛图（批6：chart/mod.cpp kCandlestickChart，
     * cmd/patch-candlestick.sh）。widget 早已在上游（ui/chart.h:706-799：
     * CandlestickChart::New(opens,highs,lows,closes,n)，up/down 色由 New 取
     * theme chartBullish/chartBearish），descriptor 两仓同缺、本批补上。
     *
     * rows 回调吃 {open,high,low,close} 四字段行（schema 见 kCandlestickRowsArgs），
     * 与五图同一条 SnapshotRowsWith 路——即批6 宿主数据回路的第一个图表受益者。
     * 与五图不同：本工厂带 $id（五图固定 demo id 'kit-chart' 只建结构；本图要
     * 真数据，handler 键必须是独立 id，否则与五图的 render_rows 键撞车）。
     * ctorArgs[0] 是 cb('render_rows',$id)，element id 走 ->id($id)（chart/mod.cpp
     * Render 按 request->elementId 取名，与 dataTable 同款）。
     */
    public static function candlestickChart(string $id = 'kit-candle'): self
    {
        return self::reg('CandlestickChart', [self::cb('render_rows', $id)])->id($id);
    }
    /**
     * SankeyChart 桑基图（批6：chart/mod.cpp kSankeyChart，cmd/patch-sankey.sh）。
     * widget 早已在上游（ui/chart.h:1019-1104：SankeyChart::New(cx) + Node/Link/
     * ShowValues/IntoEl，布局在 base/sankey.h），descriptor 两仓同缺、本批补上。
     *
     * 与五图/Candlestick 不同的数据面：桑基图是**命令式**建图（New → Node×n →
     * Link×n），descriptor 吃**双回调**——nodes（[{label}…]）+ links
     * （[{source,target,value}…]），分别经 SnapshotRowsWith 快照（批6宿主数据回路
     * 的第三个受益者）。ctorArgs = [cb('render_nodes',$id), cb('render_links',$id)]，
     * 两个回调共用同一 $id 但 type 不同（bridge 按 (id,type) 键存 handler）。
     */
    public static function sankeyChart(string $id = 'kit-sankey'): self
    {
        return self::reg('SankeyChart', [
            self::cb('render_nodes', $id),
            self::cb('render_links', $id),
        ])->id($id);
    }
    /**
     * SearchableList 可搜索列表（批6：delegate_collections/searchable_list.cpp，
     * cmd/patch-searchable-list.sh）。widget 早已在上游（ui/searchable_list.h:336-378：
     * SearchableList::New(cx,id,state,query) + Items/Delegate/IntoEl），descriptor
     * 两仓同缺、本批新建。
     *
     * rows 回调吃 [{title,value?,section?,disabled?,badge?}]——批6宿主数据回路的
     * 第四个受益者。descriptor 经 SnapshotRowsWith 快照 → SearchableListItem 数组 →
     * SearchableListDelegate::Items 工厂（默认函数表：matches 是 title 的大小写
     * 不敏感子串）。query 传 null（widget 安全跳过搜索输入框）。
     *
     * ctorArgs = [id, cb('render_rows',$id)]——与 List 同款（id 是字符串实参进
     * ctorArgs[0]，rows 回调进 ctorArgs[1]）。行渲染不发 render_item 回调：delegate
     * 的 renderItem 留 null，widget 用默认行（title + icon + badge）。元素通道
     * （render_cell/renderRow）未实现（批6 只做数据通道），发元素回调会走
     * BuildComponentElement 的 shimId 分叉 → 非确定性 SIGBUS（DataTable 实证）。
     */
    public static function searchableList(string $id, string $rowsId = ''): self
    {
        $rowsId = $rowsId !== '' ? $rowsId : $id;
        return self::reg('SearchableList', [
            $id,
            self::cb('render_rows', $rowsId),
        ])->id($id);
    }

    // ── 批4：嵌套结构 + catalog 第二张脸（Questionnaire 家族 / Accordion catalog 版 /
    // catalog 四颗升级糖）──
    //
    // 与批3 同管线（纯 PHP 工厂），但这是"已落地件上的第二层"：Questionnaire 是
    // 全新嵌套家族（嵌套最深：根→Item→Choice/Input，typed children 逐层校验），
    // Accordion 是 kind 版（El::accordionItem 三段件）的 catalog 第二导出名，
    // Select/Combobox/Popover/HoverCard/TabBar 是带方法表/开延迟/构造实参的同名件升级糖。
    //
    // Questionnaire 的事件面核对（开工前置作业）：该家族**没有**选项变化回调——
    // ChoiceOp/ItemOp 只有 description/bool 方法，焦点/导航/提交由 retained state 内部
    // 驱动，PHP 侧没有 callback 通道，无需新 patch（非 binding/click 系）。

    /** Questionnaire 的 shortcuts 枚举（questionnaire/mod.cpp:545 kModeLiterals）。 */
    public const QUESTIONNAIRE_SHORTCUTS = ['letters', 'numbers'];

    /** Popover / HoverCard 的 card_anchor 枚举（overlays/popover.cpp:112、
     *  hover_card.cpp 的 8 个角）——两个组件同表。 */
    public const POPOVER_ANCHORS = [
        'top_left', 'top_center', 'top_right', 'bottom_left',
        'bottom_center', 'bottom_right', 'left_center', 'right_center',
    ];

    /** TabBar 的 variant 枚举（typed_compound/mod.cpp:783 kTabVariantLiterals）。 */
    public const TAB_VARIANTS = ['tab', 'outline', 'pill', 'segmented', 'underline'];

    /**
     * 问卷（questionnaire/mod.cpp：Questionnaire/QuestionnaireItem/
     * QuestionnaireChoice/QuestionnaireInput 四个导出成族）。
     * ctor 只吃 id（非空）；children 只收 QuestionnaireItem（typed kAllowed，逐字
     * 校验）——嵌套最深的一族，child 必须用本工厂的 item 版。根的 shortcuts 方法
     * 发枚举（letters/numbers——快捷键字母/数字，发错当场抛；白名单 QUESTIONNAIRE_SHORTCUTS）。
     */
    public static function questionnaire(string $id, array $items, ?string $shortcuts = null): self
    {
        if ($id === '') {
            throw new \InvalidArgumentException('Element::questionnaire(): id 不能为空（上游拒空串）');
        }
        $e = self::reg('Questionnaire', [$id]);
        foreach ($items as $item) {
            // items 必须是本工厂的 questionnaireItem()（name+label+typed 子节点）——
            // 单字符串没有问卷项 name，不能代表一个选项。
            $e->child($item);
        }
        if ($shortcuts !== null && $shortcuts !== '') {
            self::enumCheck('questionnaireShortcuts', $shortcuts,
                self::QUESTIONNAIRE_SHORTCUTS);
            $e->rm('shortcuts', [$shortcuts]);
        }
        return $e;
    }

    /**
     * 问卷项（questionnaire/mod.cpp：Item 导出，ctor 2 实参 name/label 非空；方法
     * description/required/multiple/disabled 各布尔）。children 只收
     * QuestionnaireChoice / QuestionnaireInput（typed kAllowed，逐字校验）。
     */
    public static function questionnaireItem(string $name, string $label,
        array $children = [], ?string $description = null, bool $required = false,
        bool $multiple = false, bool $disabled = false): self
    {
        $e = self::reg('QuestionnaireItem', [$name, $label]);
        if ($description !== null) {
            $e->rm('description', [$description]);
        }
        if ($required) { $e->rm('required', [true]); }
        if ($multiple) { $e->rm('multiple', [true]); }
        if ($disabled) { $e->rm('disabled', [true]); }
        foreach ($children as $c) { $e->child($c); }
        return $e;
    }

    /**
     * 问卷选项（questionnaire/mod.cpp：Choice 导出，ctor 2 实参 value/label 非空；
     * 方法 description/disabled/default_selected 各布尔）。
     */
    public static function questionnaireChoice(string $value, string $label,
        ?string $description = null, bool $disabled = false, bool $defaultSelected = false): self
    {
        $e = self::reg('QuestionnaireChoice', [$value, $label]);
        if ($description !== null) {
            $e->rm('description', [$description]);
        }
        if ($disabled) { $e->rm('disabled', [true]); }
        if ($defaultSelected) { $e->rm('default_selected', [true]); }
        return $e;
    }

    /**
     * 问卷自由表单输入（questionnaire/mod.cpp：Input 导出，ctor 2 实参 state
     * （Entity InputState）+ label 非空；方法 disabled）。state 是 retained
     * InputState——经 El::state 标记（bridge 按 (kind,key) 建实体，建树时窗口
     * 可能还不存在，同 CarouselState 先例）。$stateKey 是 state 缓存的 key。
     */
    public static function questionnaireInput(string $label, ?string $stateKey = null,
        bool $disabled = false): self
    {
        if ($label === '') {
            throw new \InvalidArgumentException(
                'Element::questionnaireInput(): label 不能为空（上游拒空串）');
        }
        $e = self::reg('QuestionnaireInput', [
            self::state('InputState', $stateKey ?? 'kit-q-input', [null, null]),
            $label,
        ])->id('kit-q-input');
        if ($disabled) { $e->disabled($disabled); }
        return $e;
    }

    /**
     * Accordion catalog 版（typed_compound/mod.cpp：registered Accordion/AccordionItem
     * 导出，ctor id + 方法 multiple/bordered/size/on_toggle）。kind 版是
     * El::accordionItem 三段件（header/trigger/panel）——catalog 版是带 multiple 多开
     * 语义 + 样式/尺寸的注册侧出口，二者同组件、两套孩子形态。
     *
     * ⚠ title 的实参是 SchemaElement（kTitleArgs = {"title", SchemaElement()}，
     * "Sets the interactive title row element"）——shim 的 Element 分支只会把一个数字
     * 当 SpecId（gpui_shim.cpp toComponentArg），PHP 侧给不出合法元素实参，与
     * StatusBar left_content 同一个 shim 缺口（发字符串会 native 段错误，实测）。
     * 所以本工厂按**项数**建 item（无 title），标题留到 shim Element 通道（tier B）。
     * items 参数因此是计数而非标签。on_toggle 回调 id 给了就挂（事件以 (id,'click') 路由）。
     */
    public static function catalogAccordion(string $id, int $itemCount, bool $multiple = false,
        bool $bordered = false, string $size = '', ?string $onToggleId = null): self
    {
        if ($id === '') {
            throw new \InvalidArgumentException('Element::catalogAccordion(): id 不能为空（上游拒空串）');
        }
        $children = [];
        for ($i = 0; $i < $itemCount; $i++) {
            $children[] = self::reg('AccordionItem', []);   // title 是 SchemaElement 缺口，不发
        }
        $e = self::reg('Accordion', [$id], $children);
        if ($multiple) { $e->rm('multiple', [true]); }
        if ($bordered) { $e->rm('bordered', [true]); }
        if ($size !== '') {
            self::enumCheck('catalogAccordionSize', $size, self::SIZES);
            $e->rm('size', [$size]);
        }
        if ($onToggleId !== null && $onToggleId !== '') {
            $e->rm('on_toggle', [self::cb('click', $onToggleId)]);
        }
        return $e;
    }

    /**
     * Select（delegate_select/mod.cpp）。catalog 第二张脸：kind 层同名能力不等价，
     * catalog 版带 placeholder/menu_width/disabled 方法表。ctor 四实参——id + 三回调
     * （rows/render_row/on_select），其中 rows 是**返回数据**的回调，shim 回调通道
     * 只发事件不带返回值（DataTable 实证），所以本工厂只建结构、不发数据通道
     * （真数据等批6宿主回路；SelectProbe 探针无 shim 安装，同图表五连的卡点）。
     */
    public static function catalogSelect(string $id, string $placeholder = '',
        string $onSelectId = '', string $renderRowId = ''): self
    {
        $cb = [self::cb('render_rows', $id),
               self::cb('render_row', $renderRowId),
               self::cb('on_select', $onSelectId)];
        $e = self::reg('Select', [$id, ...$cb])->id($id);
        if ($placeholder !== '') { $e->placeholder($placeholder); }
        return $e;
    }

    public function selectMenuWidth(float $w): self
    {
        return $this->catalogMethod('menu_width', [$w]);
    }

    /**
     * Combobox（delegate_combobox/mod.cpp）。ctor 四实参——id + 三回调
     * （rows 数据通道 + on_change/on_confirm 事件回调）；方法表 placeholder/
     * search_placeholder/searchable/menu_width/disabled。
     */
    public static function catalogCombobox(string $id, string $placeholder = '',
        string $searchPlaceholder = '', string $onChangeId = '',
        string $onConfirmId = ''): self
    {
        $e = self::reg('Combobox', [
            $id,
            self::cb('render_rows', $id),
            self::cb('change', $onChangeId),
            self::cb('click', $onConfirmId),
        ])->id($id);
        if ($placeholder !== '') { $e->placeholder($placeholder); }
        if ($searchPlaceholder !== '') { $e->searchPlaceholder($searchPlaceholder); }
        return $e;
    }

    public function comboboxMenuWidth(float $w): self
    {
        return $this->catalogMethod('menu_width', [$w]);
    }

    public function comboboxSearchable(bool $v = true): self
    {
        return $this->catalogMethod('searchable', [$v]);
    }

    /**
     * Popover（overlays/popover.cpp）。ctor 两实参 id/label；**content 走具名槽**
     *（Materialize 硬约束：require content(element)，缺槽每帧 Fail）；方法表
     * card_anchor（8 角）/default_open/open/appearance/overlay_closable 布尔 +
     * on_open_change 回调。anchor 走白名单（POPOVER_ANCHORS）。
     */
    public static function catalogPopover(string $id, string $label, self $content,
        string $anchor = '', bool $defaultOpen = false, bool $open = false,
        bool $appearance = false, bool $overlayClosable = false,
        ?string $onOpenChangeId = null): self
    {
        $e = self::reg('Popover', [$id, $label]);
        $e->slot('content', $content);
        if ($anchor !== '') {
            self::enumCheck('catalogPopoverAnchor', $anchor, self::POPOVER_ANCHORS);
            $e->rm('card_anchor', [$anchor]);
        }
        if ($defaultOpen) { $e->rm('default_open', [true]); }
        if ($open) { $e->rm('open', [true]); }
        if ($appearance) { $e->rm('appearance', [true]); }
        if ($overlayClosable) { $e->rm('overlay_closable', [true]); }
        if ($onOpenChangeId !== null && $onOpenChangeId !== '') {
            $e->rm('on_open_change', [self::cb('click', $onOpenChangeId)]);
        }
        return $e;
    }

    /**
     * HoverCard catalog 版（与 kind 版 `hoverCard()` 同组件——catalog 第二张脸，
     * 带样式/开延迟/构造实参与 on_open_change 回调；kind 版是 trigger+content
     * 的组合）。ctor 单实参 id（非空）；方法
     * trigger_element（SchemaElement 实参——shim 缺口，与 StatusBar left_content 同，
     * 不给空参、文档注明）、card_anchor（8 角白名单 POPOVER_ANCHORS）、
     * open_delay/close_delay（毫秒，0–60000 范围校验）+ appearance +
     * on_open_change 回调；content 走具名槽（require content(element)）。
     */
    public static function catalogHoverCard(string $id, self $content,
        string $anchor = '', float $openDelay = 0.0, float $closeDelay = 0.0,
        bool $appearance = false, ?string $onOpenChangeId = null): self
    {
        if ($id === '') {
            throw new \InvalidArgumentException('Element::hoverCard(): id 不能为空（上游拒空串）');
        }
        $e = self::reg('HoverCard', [$id]);
        $e->slot('content', $content);
        if ($anchor !== '') {
            self::enumCheck('hoverCardAnchor', $anchor, self::POPOVER_ANCHORS);
            $e->rm('card_anchor', [$anchor]);
        }
        if ($openDelay > 0.0) {
            if (!(\is_finite($openDelay) && $openDelay >= 0.0 && $openDelay <= 60000.0)) {
                throw new \InvalidArgumentException(
                    'Element::hoverCard(): open_delay 要有限、0 到 60000，收到 ' . $openDelay);
            }
            $e->rm('open_delay', [(int) $openDelay]);
        }
        if ($closeDelay > 0.0) {
            if (!(\is_finite($closeDelay) && $closeDelay >= 0.0 && $closeDelay <= 60000.0)) {
                throw new \InvalidArgumentException(
                    'Element::hoverCard(): close_delay 要有限、0 到 60000，收到 ' . $closeDelay);
            }
            $e->rm('close_delay', [(int) $closeDelay]);
        }
        if ($appearance) { $e->rm('appearance', [true]); }
        if ($onOpenChangeId !== null && $onOpenChangeId !== '') {
            $e->rm('on_open_change', [self::cb('click', $onOpenChangeId)]);
        }
        return $e;
    }

    /**
     * TabBar catalog 版（typed_compound/mod.cpp：registered TabBar，
     * children 只收 Tab（kTabName={"Tab"} 要求注册侧——kind 版 El::tab() 不是
     * 注册节点，挂进去每帧报错）+ selected_index/variant/menu/size/on_change）。
     * 构造只吃 id+tabs（带 bool/int 默认值的可选参数放进实例方法：TypePhp 翻译器
     * 在"混合默认值 + 实参个数不完整"的签名形状上会生成默认值 re-assign 缺陷，
     * 会直接让示例的翻译失败——三个批次踩过同类坑）。
     */
    public static function catalogTabBar(string $id, array $tabs): self
    {
        if ($id === '') {
            throw new \InvalidArgumentException('Element::catalogTabBar(): id 不能为空（上游拒空串）');
        }
        $children = [];
        foreach ($tabs as $label) {
            $children[] = self::reg('Tab', [])->label((string) $label);
        }
        return self::reg('TabBar', [$id], $children);
    }

    /** TabBar 的溢出菜单开关（registered_method 'menu'）。 */
    public function tabBarMenu(bool $menu = false): self
    {
        return $this->catalogMethod('menu', [$menu]);
    }

    /** TabBar 的选中下标（零基，上游默认 0——不传即默认）。 */
    public function tabBarSelectedIndex(int $index = 0): self
    {
        if ($index <= 0) return $this;
        return $this->catalogMethod('selected_index', [(float) $index]);
    }

    /**
     * TabBar 的 variant（typed_compound kTabVariantLiterals：tab/outline/pill/
     * segmented/underline）。与 ->variant() 是两族（后者只认 Tag/GroupBox 集）。
     */
    public function tabBarVariant(string $v = 'tab'): self
    {
        self::enumCheck('catalogTabBarVariant', $v, self::TAB_VARIANTS);
        return $this->catalogMethod('variant', [$v]);
    }

    /** TabBar 的 size（kSizeLiterals，走通用 sizeEnum 的 schema）。 */
    public function tabBarSize(string $size = ''): self
    {
        if ($size === '') return $this;
        self::enumCheck('catalogTabBarSize', $size, self::SIZES);
        return $this->catalogMethod('size', [$size]);
    }

    /** TabBar 的 on_change 回调（IndexListener 形态，值是选中下标）。 */
    public function tabBarOnToggle(?string $onChangeId = null): self
    {
        if ($onChangeId === null || $onChangeId === '') return $this;
        return $this->catalogMethod('on_change', [self::cb('change', $onChangeId)]);
    }

    // ── 批5 底层补洞：descriptor 补洞（ProgressCircle）、弹层家族补洞
    //    （PopupMenu/ContextMenu）、shim 桥（EmptyHeader 的元素实参）──
    //
    // 上游改动全部经 cmd/patch-*.sh 幂等补丁（build/gpui-kit-cpp 沙箱）：
    //  · patch-progress-circle.sh：compound/progress.cpp 补 ProgressCircle descriptor
    //    （widget 已在 vendored 上游 ui/progress.h:40-64，只差 descriptor——五件套
    //    Payload/Op/Record/Component/Materialize，注册并入 RegisterCompoundProgress）。
    //  · patch-popup-menu.sh：新建 overlays/popup_menu.cpp（PopupMenu/ContextMenu 两导出，
    //    rows 走普通 children——子元素的 text 是行标签；行不带点击处理器：Func0 的
    //    userData 打包不暴露回调 id，如实记限制）+ families.h 声明 + overlays/mod.cpp 链。
    //  · patch-element-arg.sh：桥的 element_arg op——registered_method 的 SchemaElement
    //    实参先 emitTree 子元素拿 SpecId 再发方法（slot 分支的同形状），EmptyHeader 的
    //    media/title/description 因此可喂（empty.cpp 的 RecordSlot 方法表吃 SchemaElement）。
    // 三个 descriptor 都是新导出名，coverage 计数 137→140。

    /**
     * 环形进度（ProgressCircle，patch-progress-circle.sh 补的上游 descriptor）。
     * ctor id 非空；value 是百分比 Number（0–100，widget 自己 clamp）；loading 转
     * 不定态旋转（动画——进像素基线要 hidden，同 Skeleton）；label 显示环内数值；
     * size 是像素 Number（widget 的 Size(float)，不是 kSizeLiterals 枚举）；color 走
     * ParseColorArgument（spinner 同款）。
     */
    public static function progressCircle(string $id, float $value = 0.0): self
    {
        if ($id === '') {
            throw new \InvalidArgumentException('Element::progressCircle(): id 不能为空（上游拒空串）');
        }
        $e = self::reg('ProgressCircle', [$id])->id($id);
        if ($value > 0.0) { $e->rm('value', [$value]); }
        return $e;
    }

    public function progressCircleLoading(bool $v = true): self
    {
        return $this->catalogMethod('loading', [$v]);
    }

    public function progressCircleLabel(bool $v = true): self
    {
        return $this->catalogMethod('label', [$v]);
    }

    public function progressCircleSize(float $size): self
    {
        return $this->catalogMethod('size', [$size]);
    }

    public function progressCircleColor(string $color): self
    {
        return $this->catalogMethod('color', [$color]);
    }

    /**
     * 弹层菜单（PopupMenu，patch-popup-menu.sh 补的上游 descriptor）。
     * ctor id 非空；$rows 是行标签串数组（内部包成 text 子节点——上游读子元素 text 当
     * 行标签）。行不带点击处理器（Func0 打包不暴露回调 id，见 patch 脚本注释）。
     */
    public static function popupMenu(string $id, array $rows): self
    {
        if ($id === '') {
            throw new \InvalidArgumentException('Element::popupMenu(): id 不能为空（上游拒空串）');
        }
        $e = self::reg('PopupMenu', [$id])->id($id);
        foreach ($rows as $row) { $e->child(self::text((string) $row)); }
        return $e;
    }

    /**
     * 上下文菜单（ContextMenu，patch-popup-menu.sh 补的上游 descriptor）。
     * ctor id 非空；第一个子节点是**右键目标**（上游 ContextMenu::Child——包进
     * PathClick/OnMouseDown 接线，右键在目标上按下开菜单），其余是菜单行。
     * 真右键行为要真人 HID 点击——selftest 里是前提式门（同 clipboard）。
     */
    public static function contextMenu(string $id, self $target, array $rows): self
    {
        if ($id === '') {
            throw new \InvalidArgumentException('Element::contextMenu(): id 不能为空（上游拒空串）');
        }
        $e = self::reg('ContextMenu', [$id])->id($id);
        $e->child($target);
        foreach ($rows as $row) { $e->child(self::text((string) $row)); }
        return $e;
    }

    /**
     * 元素实参桥（patch-element-arg.sh）：给 registered_method 的 SchemaElement 实参
     * 喂一个元素——bridge 先 emitTree 子元素拿 SpecId、再以它当实参发方法（slot 分支
     * 的同形状；emitTree 的 op 顺序在 children 之前，所以必须像 slot 一样先建子）。
     * EmptyHeader 的 media/title/description（empty.cpp 的 RecordSlot 方法表）由此可喂。
     */
    public function elementArg(string $name, self $element): self
    {
        $this->ops[] = ['kind' => 'element_arg', 'name' => $name,
            'arg' => $element->toArray()];
        return $this;
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
            'index' => $this->index,
            'ops' => $ops,
        ];
        if ($this->kind === 'list' || $this->kind === 'uniform_list') {
            $node['listCount'] = $this->listCount;
        }
        $kids = [];
        foreach ($this->children as $c) {
            if ($c instanceof self) { $kids[] = $c->toArray(); }
        }
        $node['children'] = $kids;
        return $node;
    }
}
