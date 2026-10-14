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

    public static function list(string $id = ''): self
    {
        $e = new self();
        $e->kind = 'list';
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
        $e->text = $label;
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

    /**
     * 通用 catalog 工厂：上游 component_shell 的 137 个导出名里，只有 22 个有专用工厂，
     * 这条是剩下那些的门（Accordion、Pagination、Rating、Stepper、TabBar、Settings、
     * Tree、Badge、Tag、Label、Toolbar、Skeleton、Spinner、图表五种、Empty…）。
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
        $kids = [];
        foreach ($this->children as $c) {
            if ($c instanceof self) { $kids[] = $c->toArray(); }
        }
        $node['children'] = $kids;
        return $node;
    }
}
