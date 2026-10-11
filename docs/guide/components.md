# 组件 API

`Element`（别名 `El`）是链式 builder。工厂方法造节点，样式/行为方法返回 `$this` 可继续链。
`toArray()` 递归序列化成数组交给 bridge。

## 基础原语（gpui base ComponentKind）

```php
El::div()
El::hFlex(array $children = [])   // 水平弹性容器
El::vFlex(array $children = [])   // 垂直弹性容器
El::text(string $content)
El::button(string $label)         // 标签是子 text 元素
El::link(string $label, string $href = '')
El::input(string $id = '')        // 保留态：可 onChange / App::text(id)
El::textarea(string $id = '')
El::slider(float $min = 0, float $max = 100, float $value = 0)
El::checkbox(string $label, bool $checked = false)
El::switchBox(string $label, bool $checked = false)
El::image(string $src, string $alt = '')
El::progress(float $value = 0)
El::table(string $id) / El::list(string $id) / El::tabs(string $id) / El::tab(string $label)
El::textView(string $markdown, string $id = '')   // Markdown / HTML
El::select(string $id, array $options) / El::combobox(...) / El::datePicker(string $id)
El::popover(string $trigger, El $content) / El::hoverCard(...) / El::collapsible(...)
El::avatar(string $src) / El::accordionItem(string $header, El $panel) / El::svg(string $path)
```

### C 类便捷工厂

```php
El::numberInput($id) / El::otpInput($id)
El::progressTrack(array $children = []) / El::progressIndicator()
El::tableHead(array $children) / El::tableCaption(string $text)
El::hResizable(array $children) / El::vResizable(array $children) / El::resizablePanel(?El $child)
El::popup() / El::radioGroup(array $children) / El::toggleGroup(array $children)
```

## 样式与行为（链式）

```php
->flexCol()->flexRow()->gap(8)->p(16)->px(8)->w(240)->h('100%')
->sizeFull()->wFull()->hFull()                 // 参数化 size/w/h + "100%"
->itemsCenter()->justifyCenter()->flex1()
->rounded(6)->roundedMd()->roundedLg()->roundedFull()->border()
->bg(0xRRGGBB)->textColor('#hex')->font(16)->bold()->semibold()
->overflowYScroll()->overflowXScroll()          // gpui 原生滚动（method/behavior op）
->id('name')->disabled()->selected()->checked()->primary()->ghost()->tooltip('..')
->onClick()->onChange()->onHover()->onKeyDown()  // 声明事件；handler 在 App->on 注册
->slot('header', $child)                          // 命名槽（Popover/Avatar/Sidebar…）
```

## catalog 富组件（Registered 机制）

经 `component_shell` catalog 按导出名分发。工厂设 `registered=true`、`kind=导出名`、`ctorArgs`。

```php
El::alert($id, $msg, $variant='default')   // variant: default|info|success|warning|error
El::breadcrumb(array $labels)              // Array<String> 构造实参
El::icon(string $path)                     // 资源根下相对路径
El::sidebar($id, array $menus) / El::sidebarMenu(array $items) / El::sidebarMenuItem($label, array $children=[])
El::sidebarHeader(?El $child) / El::sidebarFooter(?El $child) / El::sidebarToggleButton()
El::form(bool $horizontal=false, array $fields=[]) / El::field(array $children=[])
El::menu($label, array $items) / El::menuItem($label, $action) / El::menuSeparator() / El::menuBar($label, array $menus)
El::dropdownMenu($id, $label)->item($label, $routeId, $type='click')
```

catalog 方法糖（走 `registered_method`，枚举 render 前预校验）：

```php
->title('..')->banner()->description('..')          // Alert / Field
->columns(2)->labelWidth(120)                       // Form
->side('left')->collapsibleMode('icon')->collapsed(true)   // Sidebar（枚举校验）
->navIcon('home')                                   // SidebarMenuItem（枚举校验）
->sizeEnum('large')                                 // Alert/Icon/Form 语义尺寸（枚举校验）
->align('center')                                   // Field（枚举校验）
->rotate(1.57)->color('#e07b39')                    // Icon
```

底层逃生舱（一般不用，糖方法覆盖不到时）：

```php
$el->rm('methodName', [arg1, arg2])   // 任意 registered_method
$el::cb('click', 'routeId')           // 回调实参标记（DropdownMenu.item 用）
```

枚举常量（对齐 descriptor 白名单）：`El::SIZES` `El::SIDES` `El::COLLAPSE_MODES`
`El::FIELD_ALIGN` `El::NAV_ICONS` `El::ALERT_VARIANTS`。

## 已知限制

- **MenuBar**：`AppEffects` 需 gpui 的 root-application（基座 `Root`），与 ShellRoot 根模型
  互斥 → 暂不能成盒。`Menu/MenuItem/MenuSeparator` 作为数据节点可 materialize。见
  [架构](/guide/architecture#渲染根模型-为什么是-shellroot)。
- **VirtualList / Dock**：依赖"回调进脚本渲染"，属同一 root-app/ScriptView 类，暂缓。
- **WebView / Inspector 面板**：已可用作元素——`El::webview($url, $id)` /
  `El::webviewHtml($html, $id)` / `El::inspector($id)`，走 `ComponentKind::ChildView` +
  host 侧 nested-view 注册（第 7 个补丁）。webview 是盖在窗口上的 OS 控件：它挡住元素树里
  排在它后面的东西、不随容器滚动、没有圆角，所以要给它自己的一块盒子。
- **Alert / Icon / SidebarMenuItem**：拒 children / 无样式——工厂里别 `->child()`。

## 下一步

- [事件与回调](/guide/events)
