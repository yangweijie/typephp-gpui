# Component API

`Element` (also available as `El`) is a fluent builder. Factories create nodes; style and
behavior methods return `$this`. `toArray()` recursively serializes the tree for the bridge.

## Base primitives

Common factories include:

| Group | Factories |
|---|---|
| Layout | `div()`, `hFlex($children = [])`, `vFlex($children = [])` |
| Text and actions | `text($content)`, `button($label)`, `link($label, $href = '')` |
| Retained input | `input($id = '')`, `textarea($id = '')`, `slider($min = 0, $max = 100, $value = 0)` |
| Selection | `checkbox($label, $checked = false)`, `switchBox($label, $checked = false)` |
| Media | `image($src, $alt = '')`, `svg($path)`, `avatar($src)` |
| Data | `progress($value = 0)`, `table($id)`, `list($id, $count = 0)` |
| Tabs | `tabs($id)`, `tab($label)` |
| Rich text | `textView($markdown, $id = '')` for Markdown/HTML |
| Pickers | `select($id, $options)`, `combobox($id, $options)`, `datePicker($id)` |
| Containers | `popover($trigger, $content)`, `hoverCard(...)`, `collapsible(...)`, `accordionItem($header, $panel)` |

These are **base primitives**, not the styled component catalog. The base Button provides
click behavior and a child text label, without the catalog's background, rounded corners
or variants. Use `El::catalogButton($id, $label)` for a styled Button, or
`El::catalog($exportName, $constructorArguments)` for other catalog components.

Catalog Button has `label`, `tooltip`, `loading`, `size`, `outline`, `primary`, `secondary`,
`danger`, `success`, `warning`, `ghost`, `link` and `compact` methods. It has no `variant`
method; that method belongs to components such as DropdownButton, Tag, Bubble, Marker,
GroupBox and TabBar.

### Convenience factories

Additional base factories include `numberInput($id)`, `otpInput($id)`, `progressTrack($children)`,
`progressIndicator()`, `tableHead($children)`, `tableCaption($text)`, `tableRow($children)`,
`tableCell($children)`, `hResizable($children)`, `vResizable($children)`,
`resizablePanel($child)`, `popup()`, `radioGroup($children)` and `toggleGroup($children)`.

### Build semantic tables explicitly

Upstream table parts are divs with accessibility roles, not an automatic layout engine.
Give rows a layout and provide row/column counts and indices yourself:

```php
El::table('t')->rowCount(2)->columnCount(2)->children([
    El::tableHeader([
        El::tableRow([
            El::tableHead([El::text('Name')])->columnIndex(0),
            El::tableHead([El::text('Count')])->columnIndex(1),
        ])->id('row-0')->rowIndex(0)->flexRow()->gap(18.0),
    ]),
    El::tableBody([
        El::tableRow([
            El::tableCell([El::text('alpha')])->columnIndex(0),
            El::tableCell([El::text('3')])->columnIndex(1),
        ])->id('row-1')->rowIndex(1)->flexRow()->gap(18.0),
    ]),
]);
```

`rowCount()` and `columnCount()` emit method operations. `rowIndex()` and `columnIndex()`
write the same `Component::index` slot, interpreted according to the node kind; do not use
both on one node. `App::specIndex('row-1')` reads the stored value and returns `-1` for a
missing id. The `table` selftest checks this distinction. Missing counts/indices can leave
assistive technology with an apparently empty table or every cell in column zero.

## Fluent styles and behavior

```php
El::div()->flexCol()->gap(8)->p(16)->px(8)->w(240)->h('100%')
    ->itemsCenter()->justifyCenter()->flex1()->rounded(6)->border();
El::text('Status')->textColor('#334455')->font(16)->bold();
El::div()->sizeFull()->overflowYScroll();
El::input('name')->disabled(false)->onChange();
```

Other helpers include `wFull()`, `hFull()`, `roundedMd/Lg/Full()`, `bg(0xRRGGBB)`,
`semibold()`, `overflowXScroll()`, `selected()`, `checked()`, `tooltip($text)` and
`slot($name, $child)`. Scrolling emits a method/behavior operation, not a nullary style.
`open(true)` declares the current open state of Select/Combobox/DatePicker; their bindings
use it to interpret Confirm and Cancel. Event markers such as `onClick()` declare listeners;
register PHP handlers separately with `App::on()`.

Some visible parts are **decoration slots**. The common slot names are `content`, `trigger`,
`input`, `decrement_button`, `increment_button`, `image`, `fallback`, `header`, `footer`
and `panel`. Empty slots can produce zero-size controls. NumberInput's step buttons take
an id, not a label, in the native constructor; provide their contents:

```php
El::numberInput('in-num')->value('42')->onStep()
    ->slot('decrement_button', El::button('−')->id('num-dec'))
    ->slot('increment_button', El::button('+')->id('num-inc'));
```

The shell Select/Combobox root is only a `role=combobox` container. Its trigger and popup
must be composed by the host; upstream script stories still mark these compositions as
pending. The event tests therefore use `focusElement()` and `dispatchAction()`, not an
assumed built-in trigger.

Use the right Button family:

```php
El::catalogButton('btn-save', 'Save')->onClick()->primary();
El::catalog('Accordion', ['acc-1'])->child(El::catalog('AccordionItem', ['i1']));
```

Variant helpers (`primary/secondary/danger/ghost/outline/success/warning/compact/loading`)
and catalog methods (`title/banner/description/label/side/align/columns/...`) apply only
to catalog nodes. Applying them to base nodes throws `InvalidArgumentException`. The old
implementation emitted unrecognized behavior operations and silently changed no pixels.

Catalog Button centers and clips its label to its box. A base Button can be more suitable
for fixed-width navigation with long labels. Its constructor id and element `id()` must
agree for event routing; `catalogButton()` sets both.

There are 72 exposed nullary style names out of 137 upstream keywords. `style()`,
`paramStyle()`, `method()` and `callback()` are private; there is no public arbitrary-name
escape hatch for base operations. Remaining gaps are listed in [Upstream coverage](./upstream-coverage).

### Additional style keywords

The 47 added helpers cover these groups:

```php
El::div()->block()->grid()->visible()->invisible();
El::div()->flexRowReverse()->flexColReverse()->flexWrapReverse();
El::div()->flexNowrap()->flexAuto()->flexInitial();
El::div()->itemsBaseline()->itemsStretch();
El::div()->selfStart()->selfCenter()->selfEnd()->selfStretch();
El::div()->justifyAround()->justifyEvenly();
El::div()->contentCenter()->contentBetween()->contentAround()
    ->contentEvenly()->contentStretch();
El::div()->overflowHidden()->aspectSquare();
El::text('Text')->whitespaceNowrap()->textEllipsis()->lineThrough();
El::div()->borderDashed()->shadowNone()->shadowLg();
El::div()->cursorText()->cursorMove()->cursorNotAllowed()->cursorGrab();
El::text('Text')->fontThin()->fontLight()->fontNormal()->fontMedium()->fontBlack();
El::text('Text')->textXs()->textSm()->textBase()->textLg()->textXl()->text2xl()->text3xl();
```

Each maps to one upstream keyword rather than combining unrelated concepts. The seven
`text*` size steps and `font($pixels)` modify the same property: the later call wins.
Those seven keywords are the only nullary entries with tracked style-field masks, which
upstream animation uses; this does not mean PHP exposes `transition()` or `spring()`.

The native `style` selftest invokes all 47 helpers and checks distinct emitted names.
Running the compiled artifact matters: a missing nano method can abort instead of throwing.

### Accessibility and keyboard navigation

These behavior operations change semantics, not pixels:

```php
El::vFlex($items)->role('list')->ariaLabel('Scrollable list')
    ->tabIndex(0)->tabStop()->keyContext('DataList');
El::text('Item 3')->role('list_item')->ariaSelected(true);
El::text('Table and list')->role('heading')->ariaLevel(2);
```

- `role()` accepts only the 181 upstream snake_case names in `Element::ROLES`. An unknown
  name such as `Heading` throws instead of silently doing nothing.
- `ariaLevel(0)` throws; upstream would silently clamp it to 1.
- Table parts already supply their roles. Do not add redundant roles to rows/cells.
- `keyContext()` must match the context used by your key bindings.
- `track_focus` and `content_focus_handle` take bridged native handles and have no PHP
  element helper. The bridge supplies them for retained inputs and selection controls.

## Virtual lists: synchronous visible-range rendering

`El::list($id, $count)` measures variable-height rows. `El::uniformList($id, $count)` measures
one row and is suitable for equal-height data. Do not attach rows as children. Register a
renderer and let GPUI request the half-open interval `[start,end)` as scrolling changes it.
Registration is allowed before window creation.

```php
$app->listRenderer('items', static function (int $start, int $end): array {
    $rows = [];
    for ($i = $start; $i < $end; $i++) {
        $rows[] = El::text("Row {$i}")->font(12.0)->truncate()->id("item-{$i}");
    }
    return $rows;
});
$list = El::uniformList('items', 10000)->w(260.0)->h(90.0)
    ->onItemClick()->onItemSecondaryClick();
```

- Return exactly `end-start` Elements. Wrong counts/types and exceptions are rejected.
  PHP exceptions are reported by `lastError()`; native missing-row errors by `registeredError()`.
- A row closure only describes elements. It runs during native painting and must not call
  window APIs such as render, loop or close.
- Uniform-list measurement uses MinContent constraints. Use `truncate()`/`whitespaceNowrap()`
  for single-line text or an explicit row height; otherwise narrow measurement can wrap each
  character and inflate row height.
- Row listeners remain available. List-level `item_click` carries the row root id as `value`,
  or the global row index as a string when no id is provided.
- `item_secondary_click` also contains `button`, `click_count`, `position`, `local_position`
  and `modifiers`.

The `table` selftest checks lazy rendering, scrolling and variable-height rows with
`virtualListLazy`, `virtualListScroll` and `virtualVariableRows`. `virtualRowListeners`
and `virtualItemEvents` check row and list-level event delivery separately.

`patch-host-list.sh` routes `RenderVirtualItems` through a C function pointer to `php::call`.
Temporary descriptions borrow frame memory so strings survive layout/painting without
mutating the main tree. This is native virtualization, not a PHP estimate of scroll offset.

## Dock: retained native layout

Create a window, then the Dock and its panels. `El::dockArea()` only mounts an existing Dock;
children do not define panels. PHP describes panel contents; native `DockState` and themed
chrome manage layout, tab selection, closing and drag behavior.

```php
$app->create();
$app->createWindow('Dock', 820, 620);
$app->createDock('area');
$app->addDockPanel('area', 'editor', 'Editor', El::text('Editor contents'));
$app->addDockPanel('area', 'console', 'Console', El::text('Console contents'));
$app->addDockPanel('area', 'files', 'Files', El::text('File list'), 'left', 180.0);
$app->render(El::dockArea('area')->sizeFull());
$app->selectDockPanel('area', 'editor');
$layout = $app->dockLayout('area');
$app->removeDockPanel('area', 'console');
$app->loadDockLayout('area', $layout);
```

- Reusing a panel name updates its title/content without duplicating it or resetting the
  user's layout. Updates are staged and validated before commit. Failed creation is not
  registered; a failed update leaves the original panel intact.
- Placement is `center/left/right/bottom`; size must be finite and nonnegative. Closing
  respects native close constraints.
- `dockLayout()` returns complete JSON. Restoration accepts only registered panels;
  malformed JSON or unknown panels return false without changing the old layout.
- `dockProbe()` returns `registered/open/active/nodes/x/y/w/h`. `active` is the central
  group's native panel index.
- Layout changes emit `dock_change` with null value. Read `dockLayout()` to save the layout;
  the event's fixed-size string buffer is not suitable for that JSON.
- `php bin/gpui run layout --dock` opens the demo. `selftest layout` checks native tab clicks,
  panel buttons, layout round trips and reclamation. C++ tests cover repeated restoration.

The host uses the complete upstream default `DockSkin`, not a PHP imitation. PHP-defined
chrome callbacks are not exposed. Physical drag acceptance remains a separate manual HID
item because synthetic mouse events do not establish the OS pressed-button state.

## Catalog components: Registered construction

The component catalog dispatches by export name through `component_shell`. Factories set
`registered=true`, `kind` to the export name, and `ctorArgs` to constructor arguments.

| Family | Factories |
|---|---|
| Feedback/media | `alert($id, $message, $variant = 'default')`, `breadcrumb($labels)`, `icon($path)` |
| Sidebar | `sidebar($id, $menus)`, `sidebarMenu($items)`, `sidebarMenuItem($label, $children)` |
| Sidebar slots | `sidebarHeader($child)`, `sidebarFooter($child)`, `sidebarToggleButton()` |
| Forms | `form($horizontal = false, $fields = [])`, `field($children)` |
| Menus | `menu($label, $items)`, `menuItem($label, $action)`, `menuSeparator()`, `menuBar($label, $menus)` |
| Dropdown | `dropdownMenu($id, $label)->item($label, $routeId, $type = 'click')` |

Alert variants are `default/info/success/warning/error`. Breadcrumb takes an array of strings.
Menu children are a strict contract: MenuBar accepts only Menu; Menu accepts only MenuItem
or MenuSeparator, not nested Menu nodes. Invalid composition produces a materialization error.

```php
El::menuBar('File', [
    El::menu('File', [El::menuItem('New', 'new'), El::menuSeparator()]),
    El::menu('Recent', [El::menuItem('a.txt', 'open-a')]),
]);
```

MenuBar works with the PHP host because `patch-host-application.sh` supplies two pieces:

1. One shared host application identity for component app effects. Per-view identities would
   create separate generations that install/uninstall the same menu data.
2. A host-safe delayed-effect trampoline. Treating the small host `PhpView` as a ScriptView
   and reading `self->runtime` was an out-of-bounds access that caused intermittent crashes.

`El::menuBar()` and `App::setAppMenus()` are separate declaration paths. Both return an
`action` event whose value is the declared action id; see [Menu actions](./events#menu-actions).
Application menu arrays use `name` for the menu, `action` for rows, and
`['separator' => true]` for separators. Using `label/id` instead can install a visible menu
with no action. `menuRows()` reads the installed action ids.

Check `componentFailure()` as well as `registeredError()`. A catalog component can fail
during materialization and render `Failed to render X` without failing the entire frame;
the component failure counter detects that later stage.

Catalog method helpers include:

```php
$alert->title('Notice')->banner()->description('Details');
$form->columns(2)->labelWidth(120);
$sidebar->side('left')->collapsibleMode('icon')->collapsed(true);
$menuItem->navIcon('home');
$field->align('center');
$icon->sizeEnum('large')->rotate(1.57)->color('#e07b39');
```

`rm($methodName, $args)` is the escape hatch for an arbitrary registered method.
`El::cb('click', 'routeId')` encodes a callback argument, as used by DropdownMenu items.
Enum constants include `SIZES`, `SIDES`, `COLLAPSE_MODES`, `FIELD_ALIGN`, `NAV_ICONS`
and `ALERT_VARIANTS`; helper methods validate them before rendering.

## Styled form components require state entities

A base `El::input()` uses the unstyled `InputBase::New(cx,id,interactive,role)` overload.
Missing catalog borders/backgrounds are not a theme configuration error. Catalog Input
expects one `SchemaEntity("InputState")` argument instead.

The original entity factory required a live JS script scope. `patch-host-state.sh` exposes
`ShellRuntime::NewHostState` to the native host; `gpui_shim_state` calls it.

```php
El::catalogInput('in-cat', 'Your name', 'Initial value');
El::catalog('Input', [El::state('InputState', 'in-cat', ['Placeholder', 'Initial value'])]);
```

`El::state($kind, $key, $args = [])` is an argument marker. Arguments follow that state's
schema order; null represents an absent optional argument.

| Helper | State kind | State arguments |
|---|---|---|
| `catalogInput($id, $placeholder, $initial)` | InputState | Two optional strings |
| `catalogNumberInput($id, $placeholder, $initial)` | InputState | Same schema |
| `catalogOtpInput($id, $length = 6)` | OtpState | Required positive length |
| `catalogSlider($id, $initial = 0.0)` | SliderState | Optional number, upstream range 0..100 |
| `catalogColorPicker($id)` | ColorPickerState | None |
| `catalogCalendar($id)` | CalendarState | None |
| `catalogDatePicker($id)` | DatePickerState | None |
| `catalogTimeField($id)` | TimeFieldState | None |

The `input` example shows these beside their base counterparts. **Live value reads are not
interchangeable.** `App::text()`/`checked()` use the base retained-input table; catalog
entities are not in that table. Creation and pixel tests do not prove catalog live-value
or callback-payload behavior, which has not been accepted component by component.

A second family lives in `catalog` tree 2: `catalogTextarea`, `catalogEditor` (languages
`rust/json`), `catalogDiff` (a real unified diff including a hunk header such as `@@ -1 +1 @@`),
`catalogCommand` (only CommandItem/CommandGroup/CommandSeparator children), and the Carousel
family. Carousel root, Content, Items and Previous/Next controls must share the same
`CarouselState` key. The example references one state seven times; distinct keys would create
unrelated state entities even if a screenshot looked plausible.

Three state rules are essential:

- **The key is identity.** Entities are reused by `(kind,key)`. Measure the actual upstream
  entity-store length with `stateCounts()['componentStates']`, not just the host cache size:
  a leaking implementation can overwrite one cache key while allocating a new entity each frame.
  `input`'s `catalogStateReuse` requires 8 entities after both early and later frames.
- **Handle zero is valid.** The upstream store starts at zero. Use a separate success result
  plus an output handle; do not treat the first successfully created entity as failure.
- **Creation failures are visible.** The bridge sends NIL, not a fabricated zero handle.
  The shim rejects it as an entity argument and reports `registeredError()`. The
  `catalogStateFailureVisible` test exercises an unknown state kind.

Successful creation is only the first half. Check materialization counters and native
bounds too. The first catalog Input increased the observed input count from 4 to 5; the
expanded form family has its own current count and reuse checks.

## Known limits

- MenuBar is connected, but its Menu/Item child contract remains mandatory.
- Dock has native default chrome and layout/panel APIs; custom PHP chrome and physical drag
  acceptance are not delivered.
- `webview`, `webviewHtml`, `inspector` and `notifications` use host ChildView registration.
  WebView is an OS view over the GPUI surface: it can cover later elements, does not scroll
  with its container and does not inherit rounded corners. Give it a dedicated rectangle.
- Notifications position themselves against window edges, not their mount's rectangle.
  Mount `El::notifications('notes')->sizeFull()->absolute()` explicitly; ShellRoot does not
  install that layer. See [Runtime constraints](./system-capabilities#runtime-constraints).
- Alert, Icon and SidebarMenuItem reject arbitrary children; do not treat them as generic
  styled containers.
- `icon($path)` checks the `icons/<base>.svg` shape but not membership in the compiled icon
  table. An unknown base can silently draw nothing. Native lookup tests check known assets.
  `navIcon('home')` uses a separate logical-name vocabulary mapped to native icons, not SVG
  basenames.

## Next

- [Events and callbacks](./events)
