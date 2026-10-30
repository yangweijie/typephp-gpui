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

### Batch-3 refinements (2026-10-28)

- `El::collapsible($label, $content)`: upstream Collapsible reads only the **content**
  slot — the label is now the block's first line (a single collapsing block; controlled
  expansion via `->open(true/false)` re-render, no callback channel).
- `El::tab($label)`: the label is wrapped into a text child automatically (base Tab's
  text is only a motion identity; four-way placement is a catalog TabBar capability).
- `El::accordionItem($header, $panel, $changeId = null)`: the three-part contract
  (AccordionItem > AccordionHeader > AccordionTrigger; the panel is an AccordionPanel);
  with `$changeId` the trigger wires on_change (routes as `$changeId, change`), and
  `->open(true)` remains the controlled-expansion anchor. Gate: `accordionChangeRoute`
  in `php bin/gpui selftest catalog`.
- `El::accordion($id, $items)` (2026-10-30 batch 3): the base-kind **root** — upstream's
  body is a Div+Id+Role(Group) (base/accordion.cpp:5-8); its value is legalizing a tree
  of the three-part assemblies. The shim `kKindNames` gained the
  `{"accordion", K::Accordion}` entry (without it the node is rejected outright).
  Items accept only accordionItem() (typed check). See the three-expression comparison
  under [Accordion catalog version](#accordion-catalog-version).
- `El::otpInput($id, $length = 4)`: the length travels through the bridge-internal
  `otp_length` op into the retained state; read it back with `App::otpLength($id)`.
  The styled one is still `El::catalogOtpInput($id, $length)`.
- `El::slider($min, $max, $value)`: range/initial travel through bridge-internal
  `slider_min`/`slider_max` ops (the initial value is applied with the public
  SliderSetValue — CreateRetainedSlider always starts at min); read back with
  `App::text($id)`. Gate: `sliderRangeInitial` in the input selftest.
- `El::avatar($src, $fallback = '')`: default circle crop + square ratio + 40px, with
  the first character in the fallback slot (taken with a preg `/u` match — the nano
  extension table has no mbstring, and `mb_substr` fails module startup outright).
  The presence dot needs absolute overlay and is not done.
- `El::image($src, $alt)`: `$alt` feeds the aria_label op (it used to be dropped).
  Ely's framed border is not a combined sugar (stack borderWidth+rounded; see the
  style-sugar discipline).
- `El::svg($path)`: empty strings and non-.svg suffixes throw on the spot (svg reads
  arbitrary asset paths via SvgDraw — icon()'s icons/ prefix check does not apply;
  a valid shape with a missing file still paints nothing silently).
- Tab/Shift-Tab focus roving: the upstream window layer already binds it
  (gpui/window_common.cpp: an unwanted Tab runs FocusTrapTab) and the host key seam
  reaches it — gate: `focusRovingTab` in the kit selftest (precondition-style: skipped
  as unverified when the window is not key/main).

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

There are **135** exposed nullary style names out of 137 upstream keywords
(2026-10-30 batch 1 closed the 63-keyword diff; the remaining two, `h_flex`/`v_flex`,
go through `El::hFlex()`/`El::vFlex()` kind names and never belonged on the nullary
style call surface). `style()`,
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

### Batch 1 style keyword diff (2026-10-30)

The 63 keywords that lacked helpers before batch 1 are now all exposed as one-line sugars
(72→135). Grouped as in `src/Element.php`:

```php
// Grid placement (grid without template columns falls back to auto flow)
El::text('…')->colSpanFull()->rowSpanFull()->colStartAuto()->colEndAuto()
    ->rowStartAuto()->rowEndAuto();
// Flex fixed values (interlocked with paramStyle flexGrow($v)/flexShrink($v); later call wins)
El::text('…')->flexGrow0()->flexGrow1()->flexShrink0()->flexShrink1();
// Multi-row distribution + main-axis fill
El::div()->contentStart()->contentEnd()->contentNormal()->justifyEnd();
// Cross-axis self_* and weight
El::text('…')->selfBaseline()->selfFlexStart()->selfFlexEnd();
El::text('…')->fontExtrabold()->fontExtralight();
// Text face
El::text('…')->textLeft()->textEllipsisMiddle()->textEllipsisStart()
    ->whitespaceNormal()->notItalic();
El::text('…')->overflowXHidden()->overflowYHidden();
// Shadow four steps
El::div()->shadow2xs()->shadowXs()->shadowXl()->shadow2xl();
// Text decoration (thickness needs a decoration line to be visible; pair with underline()/lineThrough())
El::text('…')->underline()->textDecorationSolid()->textDecoration8();
// Cursors (18; only visible on hover, not in static snapshots)
El::text('…')->cursorAlias()->cursorGrabbing()->cursorWResize(); // …all 18
// Debug family (7; no-op in release builds via DEBUG/NDEBUG, op still emitted)
El::text('…')->debugRed()->debugPink()->debug();
```

The native `style` selftest invokes all 110 helpers (47 + 63) on a detached element and
checks distinct emitted names. Running the compiled artifact matters: a missing nano
method can abort instead of throwing.

### Element-level overlay behavior sugar (batch 1, 2026-10-30)

Four sugars run on the kind-node behavior chain as method ops, for `El::popover()` /
`El::hoverCard()` / `El::dialog()` and any other kind node:

```php
El::popover('t', $body)->defaultOpen(true)->overlayClosable(false);
El::hoverCard('t', $body)->openDelayMs(120)->closeDelayMs(80);
```

- `default_open`: read only on the **first-frame seeding** of a held popup state
  (materialize.cpp:2503-2508); does not flip an already-open held state.
- `overlay_closable`: whether clicking outside closes the popup
  (base/popover.cpp:174/237).
- `open_delay` / `close_delay`: milliseconds, consumed by HoverCard only
  (base/hover_card.cpp:58, materialize.cpp:2633-2634); range 0–60000 enforced in PHP,
  out-of-range throws (upstream `AsNumber` silently accepts negative/oversized values —
  rejecting is better than silent modification).

**Channel boundary**: registered catalog nodes (`catalogPopover` / `catalogHoverCard`)
route their same-named configuration through the descriptor's `registered_method` table
(factory ctor args already cover this). These four sugars are for kind nodes; the two
channels must not be confused.

### Advanced scroll op sugar (batch 1, 2026-10-30)

Same family as the existing `overflowYScroll()` / `overflowScroll()`, but each sugar
has a different consumer:

```php
El::div()->overflowScrollbar()->scrollbarMode('always');
El::div()->viewportFromLayout(true)->scrollSize(600.0, 400.0);
El::uniformList('u', 10)->withItemToMeasureIndex(2);
```

- `overflow_scrollbar`: scrolls both axes and **attaches a native scrollbar**
  (behavior.scrollbar=true skips HideScrollbar, materialize.cpp:2782-2786) — a sibling
  of `overflowScroll()`.
- `mode`: three-value whitelist `hover` / `always` / `scrolling`; unknown values are
  silently mapped to Scrolling upstream (:385-388 has no else-reject), so the whitelist
  rejects on the spot.
- `viewport_from_layout`: parsed on the behavior chain (:386-387 records the flag) but
  **consumed nowhere today**; the real consumer is the catalog Scrollbar descriptor
  (whose constructor needs a ScrollbarHandle PHP cannot create). Passing `false` emits
  no op — upstream sets true on name alone, ignoring the argument.
- `scroll_size`: parsed (:388-390) but **no read site in the tree**; the descriptor side
  also lacks this method name. The sugar emits, upstream does not consume — the name is
  kept, the boundary pinned by comment.
- `with_item_to_measure_index`: lands on UniformList only (:1180-1181 logs "ignored on
  a list" for plain list, :1209-1210 reads the value).

The kit selftest's `scrollbarModePaintPair` gate checks the structural side
(registeredError/componentFailure cleared), with regionRgb pixel A/B downgraded to an
advisory reading — macOS headless rendering auto-hides native scrollbars, so both
boxes' right-edge readings are expected to be identical; "always actually draws a
scrollbar" needs real HID or --shot verification (see [Testing and gates](./testing)).

### Path drawing: PathFill / PathStroke + six commands (batch 5, 2026-10-31)

```php
El::pathFill('tri', '#2f6fed')
    ->moveTo(10, 90)->lineTo(50, 10)->lineTo(90, 90)->closePath()
    ->w(100.0)->h(100.0);
El::pathStroke('arc', 3.0, '#e05a2b')
    ->moveTo(10, 80)->arcTo(40, 40, 0, false, true, 90, 80)
    ->w(100.0)->h(100.0);
El::pathFillGradient('g', '#ff0000', '#0000ff')->moveTo(…)->…;
```

- The six commands: `moveTo` (2 args) / `lineTo` (2) / `curveTo` (4, quadratic —
  upstream converts to cubic) / `cubicBezierTo` (6) / `arcTo` (7: rx,ry,rot,
  large,sweep,x,y) / `closePath` (0), arities verbatim against
  runtime.cpp:8154-8177. Coordinates are bounds origin + pixels (percent strings
  scale by extent); **the element needs w/h** (the source of bounds).
- background/strokeWidth go through the dedicated `gpui_shim_push_path` ABI
  (fills Component.background/strokeWidth before Push); the bridge's emitTree
  path branch consumes the `path_bg` / `path_bg_gradient` / `path_stroke_width`
  meta ops.
- Pixel evidence: the kit selftest's pathPaintGate (the triangle centroid reads
  the exact #2f6fed fill).

### In-app keymap bindKeys (batch 5, 2026-10-31)

```php
$app->bindKeys([
    ['keystroke' => 'cmd-s', 'action' => 'kit::save'],
]);
$app->on('kit::save', 'action', fn () => …);   // unclaimed actions arrive as action events
```

Three steps after upstream NativeBindKeys (runtime.cpp:9568-9641): KeyChordsParse
per entry → ShellActionOf → KeymapBind; **whole-list pre-validation** (an
unparsable keystroke rejects the whole list and throws). The install receipt and
the table probe (KeymapAnyBindingForAction) are the bindKeysGate hard assertion;
synthesized cmd-key delivery is advisory (NSApp swallows cmd chords with no menu
row; native keyboard is human HID).

### Window options and action verbs (batch 5, the TitleBar period-B slice)

```php
$app->createWindow('Borderless', 360, 220,
    ['borderless' => true, 'clientTitleBar' => true]);
$app->windowAction('zoom');      // minimize | zoom | close
```

- With clientTitleBar the top 34px (title_bar.h kTitleBarHeight) is the caption
  band: double-click maximize goes through window_common.cpp:2526, and macOS's
  system traffic lights are still drawn by Cocoa over the transparent title bar
  (the WinOpts comment in gpui.h) — PHP draws the title-strip visuals; the
  controls stay with the system/window actions.
- `windowAction('close')` leaving isOpen=false is the titlebarWindowGate hard
  evidence.
- **Period-C design (not implemented)**: upstream component::TitleBar is neither
  a descriptor export nor a ComponentKind (the PHP spec channel cannot build it)
  — it needs cmd/patch-title-bar.sh to give title_bar.h a descriptor/kind plus a
  `kDialogMethods`-style named-method table (the coverage.md:65-67 precedent) and
  an `El::titleBar()` factory. The blocker and path live on the coverage page.

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
Enum constants include `SIZES`, `SIDES`, `COLLAPSE_MODES`, `FIELD_ALIGN`, `NAV_ICONS`,
`ALERT_VARIANTS` and `SEPARATOR_VARIANTS`; helper methods validate them before rendering.

## Kit factories: dedicated factories for tier-A families

This covers three batches of dedicated factories landed 2026-10-28 (the "tier-A gap list",
the "pure factory warm-up" and "S factories with prerequisite work" sections in
`src/Element.php`): 27 in the tier-A batch plus 5 in the pure-factory batch
(`Spinner`/`TableFooter`/`GroupBox`/`VForm`/`InputGroupText`, plain `reg()` channel,
zero upstream changes) plus 6 in the S-factory batch (`Label`/`Toolbar`/`StatusBar`/
`Clipboard`/`HorizontalRadioGroup` and the `SplitButton` composition sugar). The
components were already exported by `component_shell`; each factory replaces the bare
`El::catalog('ExportName', …)` channel with a named, defaulted, enum-validated
constructor. The snippets match the real calls in `examples/_shared/features/kit.php` and
`examples/kit/src/main.php` (the animated ones render with `->hidden()` in the example,
see Skeleton); each factory has at least one gate in `php bin/gpui selftest kit`
(38 gates: 29 structural shape checks plus per-tree `registeredError()`/`componentFailure()`
cleanliness plus synthetic fire routing plus one premise-based behavior gate).

### Separator

```php
El::separator('horizontal')->label('or')->color('#e07b39');   // label/color come from the method table
El::separator($v);   // $v is a key of El::SEPARATOR_VARIANTS:
                     // horizontal | vertical | dashed | vertical_dashed
```

The four variants are **four export names** (`Separator`, `VerticalSeparator`,
`DashedSeparator`, `VerticalDashedSeparator`; separator.cpp kConstructors), not constructor
arguments — an unknown variant throws `InvalidArgumentException` instead of degrading to a
plain horizontal rule. The embedded label (Ely's `label("or")`) and tone color use the
descriptor's `label`/`color` methods through the existing `->label()` / `->color()` sugar.

### Tooltip

```php
El::catalogTooltip('kit-tt', 'Hover me', 'Managed tooltip text from the catalog'); // (id, button label, text), all non-empty
```

Upstream `Tooltip` is "a button with a managed tooltip" (it materializes a
`component::Button`), not a hover decoration for arbitrary elements — that is the instance
method `->tooltip('…')`, which attaches a tooltip to another catalog component. The factory
takes the `catalog` prefix because of that name collision, following the `catalogButton`
precedent.

### Skeleton

```php
El::skeleton()->w(120.0)->h(16.0);              // default color; shape comes from styles
El::skeleton()->secondary()->w(120.0)->h(16.0); // ->secondary() is the only upstream variant
```

**Skeleton is a pulse animation** ("An animated loading placeholder"): inside a snapshot
viewport its phase drifts with frame timing and the pixel baseline flaps every run
(measured gdfrac≈0.025). Kit tree 1 renders this row with `->hidden()`: display-level
hiding keeps materialization running (the `registeredError()`/`componentFailure()` gates
still cover it) while the pixels stay exactly zero. Ely's `SkeletonText/SkeletonCard/…`
variants have no upstream exports: compose cards and text bars from divs and style chains
(several Skeletons of different sizes inside an `El::vFlex([...])`). Real variants need
new upstream export names.

### Empty state

```php
El::emptyState('icons/square-terminal.svg', 'Nothing here yet', 'Create your first item',
    El::button('Create'));              // 4th argument (action area) is optional
```

An assembly factory that emits the `Empty` quintet: `EmptyMedia` (icon variant with
`El::icon($icon)`), `EmptyTitle`, `EmptyDescription`, and `EmptyContent` (only when
`$content` is given). The icon argument follows `El::icon()` rules — it must be
`icons/<base>.svg` with a compiled base name, otherwise nothing is drawn silently. The
composition uses the Empty root's **ordinary children** (the Root branch in empty.cpp) by
default. The fifth argument, `$headerSlot = true`, takes the upstream-native **header slot**
route: `EmptyHeader`'s `media/title/description` are SchemaElement method arguments (empty.cpp's
RecordSlot table), fed through `->elementArg()` (see the "Batch 5 low-level gap filling" section
below). The arguments must be **registered Empty part elements** (`El::catalog('EmptyMedia', …)`
etc.) — bare icon/text nodes are rejected by `TakeElementAs`'s tag check. The two routes look
different.

### Badge and Tag

```php
El::hFlex([El::button('Inbox'), El::badge(9, false, 99)])->gap(4.0); // numeric badge (+n past max)
El::hFlex([El::button('Alerts'), El::badge(0, true)]);               // dot badge
foreach (['primary', 'secondary', 'success', 'warning', 'danger', 'info'] as $tv) {
    $tagVariants[] = El::tag($tv, $tv);            // variant: primary|secondary|danger|success|warning|info
}
El::tag('Draft', 'secondary')->outline();        // outlined
El::tag('Pill', 'info')->pill();                 // pill corners (registered_method 'rounded_full')
El::tag('Retoned', 'secondary')->variant('warning'); // ->variant() is Tag's enum sugar
```

CountBadge/DotBadge need no separate factories: the Badge method table already carries
`dot`/`count`/`max`, and `El::badge()` passes them through (count 0 hides a numeric badge
by upstream semantics). Attach the host element with `->child()` (ordinary children).
`->variant()` is not the same family as the five Button variant sugars: those are zero-arg
Button methods, while `variant(enum)` belongs to Tag / DropdownButton / Bubble / Marker /
GroupBox / TabBar.

### Stepper

```php
El::stepper('kit-steps', ['Cart', 'Order', 'Pay'], 1, false, 'kit-steps');
//                id       labels             zero-based  vertical?  on_change route id
El::stepper('kit-steps-v', ['Start', 'Middle', 'End'], 2, true);        // vertical, no callback
El::stepperItem('Disabled step', true);   // a single step (optional disabled flag)
```

`on_change` routes to `App->on($id, 'change')` through an `El::cb('change', $onChangeId)`
marker; the `kitChangeRoutes` gate proves delivery to a PHP handler with a synthetic fire
(whether a real click emits the event stays a manual-acceptance item, like every part
callback). The child contract is strict: Stepper accepts only `StepperItem`.

### Tree

```php
El::tree('kit-tree', [
    El::treeItem('kit-tree-src', 'src/')->rm('expanded', [true])->child(
        El::treeItem('kit-tree-main', 'main.php')),      // TreeItem nests TreeItem
    El::treeItem('kit-tree-lock', 'GPUI.lock'),
]);
```

Both `TreeItem(id, label)` arguments must be non-empty (tree.cpp validates verbatim);
initial expansion uses `->rm('expanded', [true])` — the TreeItem method table has only
`expanded`/`disabled`, not worth a sugar each. Tree accepts only TreeItem children
(kAllowed); anything else renders an error node every frame.

### Pagination

```php
El::pagination('kit-pg', 2, 10, 5, 'kit-pg');   // (id, 1-based current, total, visible buttons, on_change id)
El::pagination('kit-pg')->rm('compact', []);    // compact form (illustrative; kit tree 2 shows the parameterized form)
```

Controlled semantics: upstream `on_change` only reports the requested page; the script
writes `current_page` back (descriptor wording: "so the script can drive `currentPage`").

### Rating

```php
El::rating('kit-rate', 3.0, 5, false, 'kit-rate');       // interactive, on_change → kit-rate
El::rating('kit-rate-ro', 4.0, 5, true)->color('#e07b39'); // read-only (Ely Stars display)
```

Read-only uses the common `disabled` behavior: Rating's method table includes
`kDisabledMethod`, and materialize reads the `'disabled'` method op into
`request->disabled` — exactly the name `El::disabled(true)` emits.

### Kbd / KbdCombo

```php
El::kbd('⌘');
El::kbd('K')->outline();                 // outlined keycap (zero-arg outline method)
El::kbdCombo(['⌘', 'Shift', 'P']);       // combo sugar: several Kbd in a row
```

Kbd itself is static; it shares a row with ShimmerText for layout, and kit tree 1 hides
the whole row with `->hidden()` for the same reason as Skeleton (the sweep is animated).

### ShimmerText

```php
El::shimmerText('Loading…');
El::shimmerText('Slow reverse sweep')->rm('duration_ms', [2400.0])->rm('reverse', [true]);
```

Variants (`id`/`duration_ms`/`spread`/`reverse`/`once`) pass through `->rm()` — all five
names are in the ShimmerText method table; no dedicated sugar per name.

### Batch 7 text effects (the only batch that writes C++ widgets from scratch)

```php
El::typewriter('Typewriter effect demo text');
El::typewriter('Fast typing')->rm('speed', [10.0]);
El::gradientText('Gradient text effect demo', '#ff6600', '#0066ff');
El::animatedNumber('kit-an', 42.5, 1)->pad(3);
```

- **Typewriter**: reveals characters one by one, `speed` = ms per character (default 50).
  MotionAppear animation with the same internal state management as ShimmerText. Methods:
  `speed`/`id`/`color`.
- **GradientText**: static gradient, each character gets `RgbaMix(from,to,t)` interpolation,
  no animation. Ctor takes `text/from/to` (from/to are color strings). Methods: `id`.
- **AnimatedNumber**: counts up from 0 to value, `decimals` decimal places, `pad` leading
  zeros. MotionAppear manages the animation state — no separate retained entity needed.
  Methods: `color`/`pad`.

All three live in `ui/text_effects.h/.cpp` (new-written, described by the Ely spec, absent
from upstream); descriptors are in `controls/text.cpp` (`cmd/patch-text-effects.sh`). The
effect family's visual verification requires frame-by-frame snapshots or human inspection —
the selftest only asserts "no crash + clean ledger"; the animation itself is managed by
MotionAppear internally.

### Dialog / AlertDialog / Sheet triggers

```php
El::dialog('kit-dlg', 'Open confirm',
    El::vFlex([El::text('Continue this operation?')])->gap(4.0),
    'Confirm', 'kit-dlg-ok', 'kit-dlg-cancel');
El::alertDialog('kit-ald', 'Delete data', 'This cannot be undone.', true, 'Warning', 'kit-ald-ok');
El::sheet('kit-sheet', 'Open drawer',
    El::vFlex([El::text('Drawer body: slides in from the right.'), El::text('Close via on_close (kit-sheet-close).')])->gap(4.0),
    'right', 'kit-sheet-close');
```

These are a **separate path** from `App::openDialog()` / `openSheet()` / `notify()`: each
renders a real trigger button on the page and the native side opens the window effect on
click. Three hard constraints (window_effects/mod.cpp:467-476, pinned by the `dialogFamily`
gate):

- **Dialog and Sheet require the `content` named slot** (`->slot('content', $body)`; the
  factory's third argument is exactly that) and accept only this one slot name; a missing
  slot fails every frame ("require exactly one content(element) named slot").
- **AlertDialog rejects every named slot**; its body goes through the `description` method
  (the factory's third argument).
- The third constructor argument `on_effect_error` reports asynchronous effect failures;
  the factory attaches it automatically as `($id-error, 'click')` — normally with no
  handler, only firing when an effect fails.

The `on_ok`/`on_cancel`/`on_close` callback ids come from parameters and route as
`(that id, 'click')`; the `kitClickRoutes` gate proves delivery to PHP with synthetic fires.

### Description list

```php
El::descriptionList(['Name' => 'typephp-gpui', 'Purpose' => 'Native PHP GUI'], false, true);
//                                associative items (label => value)   vertical?  border?
El::descriptionList([
    El::descriptionItem('Spanning', 'two columns')->rm('span', [2.0]),   // pass Elements for spans
    El::descriptionItem('Normal', 'one column'),
], true);
```

Child contract: DescriptionList accepts only `DescriptionItem`. Ely's PropertyGrid has no
upstream export; approximate it with this factory (per the plan).

### DataTable (structure connected, data channel not)

```php
// The real calls live in kit's --selftest gates (the trees deliberately omit it, see below):
El::dataTable('dt-x', ['Name', 'Count']);           // structural gate: DataTableState + rows/cell markers
El::dataTable('kit-dt-neg', ['Name', 'Count']);     // negative gate: real renders fail on the spot, probe fails rise
El::tableToCsv(['Name', 'Count'], [                 // PHP-side to_csv, pure data transform (kit tree 3 shows it)
    ['Name' => 'comma, inside', 'Count' => 3],
    ['Name' => 'with "quotes"', 'Count' => 'x'],
]);
```

The constructor takes a `DataTableState` entity plus `rows` and `render_cell` callbacks;
the factory encodes the latter two as `cb` markers, but **the shim callback channel only
emits events and returns no values** (the shimId branch at runtime.cpp:12922 returns right
after enqueuing), so upstream `SnapshotRowsWith` never receives a row array and real
renders fail on the spot — what works today is the structure, not a data table. Behavior
arguments (`stripe`, `bordered`, `scrollbar_visible`, `row_selectable`,
`column_selectable`, `cell_selectable`, `row_header`, `sortable`, `column_resizable`,
`column_movable`) pass through `->rm()`. Two measured facts worth knowing:

1. **This failure reaches no ledger**: DataTable's own `Failure()` returns a Div carrying
   the error text, so `registeredError()` and `componentFailure()` both miss it. The shim
   installs upstream's `SetDataTableProbe` seam (families.h:84-85) and reports the counts
   as `App::stateCounts()['dataTableBuilds'/'dataTableFails']`; the `dataTableRowsCallbackLoud`
   gate asserts "fails rise, builds stay flat" (not exactly +1: every frame after the
   negative render re-materializes and fails again, and how many frames run is up to
   the dirty-driven loop).
2. The data channel needs a host round trip like `App::listRenderer()`
   (`cmd/patch-host-list.sh` shape) — tier B. `query`/`filters`/`sorts`/`paged`/`group_by`
   are not in the upstream kMethods either.

### Settings

```php
El::settings('kit-set', [
    El::settingPage('General', [
        El::settingGroup('Appearance', [
            El::settingItem('Theme', El::switchBox('Dark')),   // the field control goes into the content slot
        ]),
    ]),
])
    ->rm('sidebar_width', [200.0])
    ->rm('sidebar_size_range', [160.0, 240.0]);
```

A four-level typed-children contract, validated level by level: Settings accepts only
`SettingPage` (title in the constructor) → `SettingGroup` (title via method) →
`SettingItem` (title in the constructor). **A SettingItem without the content slot fails**
("requires content(element)") — the second `settingItem()` argument is that field. Three
measured pitfalls: ① **Settings and SettingPage reject styles** — adding `->w()` fails
every frame ("carries data rather than a box, so it does not implement Styled"; the
descriptor says "style is rejected"); ② **the native sidebar width is a settling layout
feedback** (ui/setting.cpp: from the second frame it shrinks toward the bottom of its
size range) — un-pinned it is a pixel-jitter source, so the example pins it with
`sidebar_width` plus `sidebar_size_range`; ③ search keywords and field layout pass
through `->rm('keywords', [...])` / `->rm('layout', ['horizontal'])`, and the default
page uses `default_selected_page`. Ely's SettingsSearch needs an upstream
searchable_list export name and is not available yet.

### Carousel

```php
El::carousel('kit-car', [
    El::vFlex([El::text('Page 1')->font(16.0)->semibold(), El::text('First slide')])->gap(4.0),
    El::vFlex([/* page 2 */]),
    El::vFlex([/* page 3 */]),
])->w(480.0)->h(120.0);
```

An assembly factory that emits the full eight-piece set (`Carousel`, `CarouselContent`,
`CarouselItem`, `CarouselPrevious`, `CarouselNext`, `CarouselPagination`,
`CarouselPaginationItem`); all eight constructors reference **one** `CarouselState`
(cached by `(kind,key)` with key=`$id`). This is the same semantics as the seven shared
`El::state('CarouselState', 'ca-car', …)` references in `examples/catalog` tree 2 —
distinct keys would create unrelated carousels with no visual difference (only
`stateCounts()['componentStates']` would catch it).

### Spinner

```php
El::spinner();                                                    // default: medium, loader, linear
El::spinner()->sizeEnum('large')->spinnerIcon('loader_circle')    // one call per method
    ->color('#e07b39')->spinnerEase('ease_in_out');
```

No constructor arguments and **no children** (Materialize wraps the rotating icon in a
Div; children are never taken). `size` reuses `->sizeEnum()` (upstream `kSizeLiterals`
matches `El::SIZES`), `color` reuses `->color()` (upstream `ParseColorArgument` takes a
color string); `icon` (`loader|loader_circle`) and `ease`
(`linear|ease_in_out|ease_out_quint`) are component-private enums with their own
whitelisted sugar `->spinnerIcon()` / `->spinnerEase()` (`El::SPINNER_ICONS` /
`El::SPINNER_EASES`) — a misspelling throws immediately. **Spinner is a rotation
animation**: it must render `->hidden()` inside a pixel baseline (same phase drift as
Skeleton; kit tree 1 does exactly that).

### TableFooter

```php
El::catalog('Table', [], [
    El::catalog('TableHeader', [], [ /* TableRow > TableHead… */ ]),
    El::catalog('TableBody', [], [ /* TableRow > TableCell… */ ]),
    El::tableFooter([
        El::catalog('TableRow', [], [
            El::catalog('TableCell', [], [El::text('Total')]),
        ]),
    ]),
]);
```

**The registered channel is the only path**: the shell `ComponentKind` enum has no
`TableFooter` (the Table block ends at `TableCaption`), so the shim's `kindFromString`
cannot reach this export (structured/table.cpp kFooter) — it must go through `reg()`.
Children accept only `TableRow` (verified literal-by-literal by `MaterializeGroup`'s
kAllowed), and those rows must be **registered-side**: `TakeTypedChildren` records a
componentName only for `Registered` children, so the kind-based `El::tableRow()` records
an empty name and every frame reports an error node. The whole themed table
(`Table`/`TableHeader`/`TableBody`/`TableFooter`) lives on the registered side; the base
kind table (`El::table()` and friends) is the unstyled div family (base/table.h) — do not
mix the two families.

### GroupBox

```php
El::groupBox('Appearance', [El::text('Content A')])->variant('fill');
El::groupBox('Border', [El::text('outline form')])->variant('outline');
El::groupBox('Default', [El::text('normal form')]);   // no variant call means normal
```

No constructor arguments; the title goes through the method (reusing the `->title()`
sugar) and children are ordinary children. The variant literal set is
**normal/fill/outline** (group_box.cpp:78) — not the Tag tone colors. The `->variant()`
whitelist is therefore the **union** of the components' literal sets (Tag's six tones +
GroupBox's three forms): values outside the union (like `'card'`) throw in PHP
immediately, while literals another component does not know (like `'primary'` on
GroupBox) are rejected loudly by the upstream enum (visible through `registeredError()`)
— the shared sugar does not double-check that. That is the known cost of one shared
method name.

### VForm

```php
El::vForm([
    El::field([El::checkbox('Weekly digest')])->label('Subscribe'),
    El::field([El::switchBox('Dark mode')])->label('Appearance'),
]);
```

`'VForm'` and `'Form'` are two export names of the **same constructor**
(`ConstructForm<false>`, form.cpp:276-280; the descriptor table is shared) — the shape is
identical to `El::form()`: children accept only `Field` (kAllowed checked literally). Use
it instead of `El::form()` when mirroring upstream example/doc names verbatim.

### InputGroupText

```php
El::inputGroupText('https://');    // prefix/suffix decoration: muted text
```

No constructor arguments, no methods, ordinary children (`FinishPart` takes them all);
`$text` is wrapped in a text node. Standalone rendering is all that works today: the full
`InputGroup` composition cannot be assembled — the group's `addon` method takes a
SchemaElement argument (the shim channel cannot produce a SpecId, same gap as EmptyHeader
media/title), and the `input` slot needs `InputGroupInput`/`InputGroupTextarea` (which
need retained state entities). The decoration itself renders on its own.

### Text label Label

```php
El::catalogLabel('src/Element.php')->secondaryLabel('secondary text')
    ->masked()->labelHighlights('Element');
```

`->label(string)` is the Button/Field title sugar; PHP does not allow a static and a
non-static method with the same name in one class, so following the `catalogTooltip`
precedent the factory carries a `catalog` prefix. The three style methods are the
registered_methods of upstream Label's method table: `secondary` (muted secondary text),
`masked` (masking), and `highlights` (search highlight) — **the highlights argument is a
single match string** (SchemaString; the shell passes it through to
`Label::Highlights(Str)` as a full-text match, not an array). An empty text is rejected in
PHP first (upstream `ConstructString` refuses empty strings).

### Toolbar

```php
El::toolbar('kit-tb', [
    El::catalogButton('kit-tb-new', 'New')->primary(),
    El::button('Open'),
]);
```

The id must be non-empty and there is no method table; ordinary children fill the contents
in source order (the "transparent, sizable toolbar container"). Children are not filtered by
name.

### Status bar StatusBar

```php
El::statusBar([El::catalogLabel('Ready'), El::catalogLabel('UTF-8')]);
```

No constructor arguments; ordinary children fill the **middle** region (Materialize adds
them one by one via `Center`). `left_content`/`right_content` take a
**SchemaElement** argument (status_bar.cpp:59-60) — the shim's Element branch can only cast
a number to a SpecId (the Element case in `toComponentArg` in gpui_shim.cpp), so PHP cannot
produce a legal argument and this factory does **not** emit those two methods; the real
content waits on the shim Element argument channel (tier B, same gap as EmptyHeader
media/title). Feeding a string from PHP in a headless environment segfaults native
materialization (SpecId 0, measured rc=139) — the "loud failure" claim is therefore not
verifiable headlessly and is not pinned by an assertion that would crash.

### Clipboard

```php
El::clipboard('kit-clip', 'typephp-gpui', 'Click to copy the repo name');
```

The id goes into the constructor (non-empty); `value`/`tooltip` go through methods;
**children are rejected** (`EnsureNoChildren`). The click-to-copy is built-in widget
behavior (`ClipboardState::OnCopy` -> `ClipboardSetText` in ui/clipboard.cpp): the
descriptor has no `on_copied` export and no PHP callback, so a synthetic fire cannot reach
it (kit's premise-based `clipboardCopyRoute` gate pins "no synthetic route"; the real copy
needs a real click over HID, not verified, not judged red).

### Horizontal radio group HorizontalRadioGroup

```php
El::hRadioGroup('kit-hrg', ['By name', 'By path', 'By size'], 0, 'kit-hrg');
```

Children accept only **registered-side** `Radio` (kRadioName={"Radio"} verified literally in
typed_compound/mod.cpp — the kind-based `El::radio()` has no componentName and would
report an error node every frame). `$radios` holds label strings, and the factory emits a
`Radio` per label (derived id `$id-r<i>`, label via the `->label()` sugar); `$selected`
is zero-based and goes through `selected_index`; if `$onChangeId` is given, `on_change` is
emitted, the event routes as `($onChangeId,'change')` with the selected index as its value
(synthetic fire only checks that the route reaches PHP, see the `kitChangeRoutes` note).

### Split button SplitButton (composition sugar, not an independent export)

```php
El::splitButton('kit-split', 'Export…', ['Export as PNG', 'Export as CSV']);
```

Neither upstream nor the reference repository has a SplitButton widget (zero hits from grep;
Ely's implementation is itself a composed piece, menus/hosts.rs). No new widget is worth
asking for upstream for it. This factory emits only the `DropdownMenu` export name: `$label`
is the trigger button's text and each `$menuItems` label becomes an item, with the item
callback id derived as `$id-item-<i>` (events route as `(id,'click')` to `App->on`). For
"action + menu" two-channel use, emit `El::catalogButton()` and `El::dropdownMenu()` as
two separate components; this sugar only gives the common "pure menu trigger" shape a name.

### Notification Notification

```php
El::catalogNotification('kit-not', 'Title', 'A notification message', 'warning',
    'kit-not-ok', 'kit-not-close')
    ->notificationOnClick('kit-not-ok')
    ->notificationOnClose('kit-not-close');
```

This is **two paths alongside `App::notify()`**: `App::notify()` is the host retained
notification window effect (retained state, clickable, tracked — see
[Events and Callbacks](./events)); this factory is the catalog `Notification` export
name (window_effects/mod.cpp Notification descriptor, 3 ctor args
id/label/on_effect_error), a component-layer-only node without host retained state.
The ctor takes three required arguments; `message`/`type` (`info`|`success`|`warning`|
`error`, `NOTIFICATION_TYPES` whitelist), `on_click`/`on_close`/`autohide` go through the
method sugars.

### DropdownButton

```php
El::dropdownButton('kit-db-btn', 'Export menu', 'secondary', 'bottom_right',
    'kit-db-btn', ['Export as PNG', 'Export as CSV']);
// sugars: ->dropdownButtonVariant('danger') / ->dropdownButtonAnchor('top_left') /
//     ->dropdownButtonOnClick($id) / ->dropdownMenuItem('Item X', $itemId)
```

The ctor takes two non-empty strings, id and label; the variant (`primary`/`secondary`/
`danger`/`ghost`) and anchor (`top_right`/`bottom_right`/`bottom_left`/`top_left`) are
private enum whitelists (`DB_DROPDOWN_*` constants); `menu_item` takes label plus a callback
marker (same shape as `dropdownMenu`'s `item()` sugar). The popup menu is created under
the button's name in the constructor (each named button keeps its own menu), and the item
callback ids are derived as `$id-item-<i>`.

### Bubble / Marker

```php
El::bubble('start', 'filled')->child(El::catalogLabel('Title bubble'));   // alignment/variant
El::bubble('', 'secondary')->child(El::catalogLabel('Plain bubble'));
El::marker('kit-mk', 'separator', true, 'spinner');                       // variant/loading/loading_style
```

Bubble takes no ctor arguments (its ordinary children form the visible content);
alignment ∈ {start} (chat.cpp `kAlignmentLiterals` is actually `{"start", ""}` — the
second element is an empty literal) and the variant has seven tone forms; Marker takes a
non-empty id and its variant (`plain`/`separator`/`border`), `loading` (bool), and
`loading_style` (`spinner`/`shimmer`) go through private enum whitelists
(`MARKER_*` constants).

### Message / MessageScroller / Attachment

```php
El::message('start', [El::catalogLabel('Message A')]);                    // alignment + children
El::attachment('kit-att', 'uploading', 'vertical', 'large');                // status/axis/size enums
```

Message takes no ctor arguments (alignment enum + ordinary children into the content slot);
Attachment takes a non-empty id and its `status` (`pending`/`uploading`/`processing`/
`failed`/`complete`), `axis` (`horizontal`/`vertical`), and `size` through private enum
whitelists (`ATTACHMENT_*` constants). **MessageScroller is structure-only**: its ctor takes
three args id/state/render_item, and `render_item` is a row-returning callback — the shim
callback channel emits events but does not return values (DataTable's experience), so the
data channel waits for batch 6 host circuit; it is never put in a tree because that would
raise `componentStates` and break tree2CarouselState's `==1` gate.

### Seven chart families (batch 6 data loop connected)

```php
// Five original charts (structure connected, data channel not yet live)
El::barChart(); El::lineChart(); El::areaChart(); El::pieChart(); El::radarChart();
// five exports (chart/mod.cpp:495-499): each ctor takes only the rows callback
// (cx) => { label: string; value: number }[] — real data waits for the host data loop.

// Batch 6 adds two charts with the data loop live:
El::candlestickChart('kit-candle');
// CandlestickChart (chart/mod.cpp kCandlestickChart, cmd/patch-candlestick.sh):
// rows callback takes {open,high,low,close} rows, same SnapshotRowsWith path as the
// five. up/down colors come from the widget's New (theme chartBullish/chartBearish).

El::sankeyChart('kit-sankey');
// SankeyChart (chart/mod.cpp kSankeyChart, cmd/patch-sankey.sh): dual callbacks
// render_nodes ([{label}…]) + render_links ([{source,target,value}…]), built
// imperatively (New → Node×n → Link×n). New Op::ShowValues (show_values).
```

### SearchableList (batch 6 data loop connected)

```php
El::searchableList('kit-sl', 'kit-sl-rows')->w(320.0)->h(200.0);
// SearchableList (delegate_collections/searchable_list.cpp, cmd/patch-searchable-list.sh):
// rows callback takes [{title,value?,section?,disabled?,badge?}], the descriptor converts
// to SearchableListItem array + SearchableListDelegate::Items factory (default function
// table: matches is a case-insensitive title substring). query is null (the widget safely
// skips the search input). Row rendering does not send a render_item callback: the delegate's
// renderItem stays null and the widget uses the default row (title + icon + badge). The
// element channel (render_cell/renderRow) is not implemented (batch 6 does the data channel
// only); sending an element callback hits the shimId branch in BuildComponentElement →
// non-deterministic SIGBUS (DataTable-verified).
```

### Questionnaire family

```php
El::questionnaire('kit-q', [
    El::questionnaireItem('age', 'Age', [
        El::questionnaireChoice('18', 'Under 18', null, false, true),
    ])->rm('required', [true]),
], 'letters');
// root shortcuts ∈ {letters, numbers} (whitelist QUESTIONNAIRE_SHORTCUTS)
```

The deepest nested family upstream: the Questionnaire root (ctor id) →
QuestionnaireItem (ctor name/label) → QuestionnaireChoice (ctor value/label) /
QuestionnaireInput (ctor state + label), typed children checked layer by layer (the root
accepts only Items; an Item accepts only Choices/Inputs). The root's shortcuts emits an
enum (letter/number shortcuts). Event-surface audit: the family has **no option-change
callback** — focus/navigation/submission are driven inside the retained state
(QuestionnaireState keyed by id, schema-fingerprint-rebuilt only when the schema changes),
so no new patch is needed. The `questionnaireShape` gate pins the nesting shape plus the
shortcuts whitelist.

### Accordion catalog version

```php
El::catalogAccordion('acc-cat', 3, false, true, 'medium', 'acc-cat-tog');
// the kind version is El::accordionItem's three-part assembly (header/trigger/panel + open(true))
```

The kind version (`El::accordionItem`) is a three-part assembly (header/trigger/panel four
kinds); the catalog version (registered Accordion/AccordionItem exports) carries the
multiple/bordered/size/on_toggle method table — the second face of the same component; pick
by need. **The title argument is a SchemaElement shim gap** (kTitleArgs =
{"title", SchemaElement()} — sending a string crashes the native materialize, measured) —
this factory builds items by **count**, sends no title; titles wait for the shim Element
channel (tier B). The `accordionCatalogShape` gate pins the ctor shape plus the empty
childOps (the expected shape with no title).

**The kind-version root exists since batch 3** (2026-10-30): `El::accordion($id, $items)`
— upstream's body is a Div+Id+Role(Group) (base/accordion.cpp:5-8, very thin); its value
is legalizing a tree of accordionItem three-part assemblies. The shim's `kKindNames`
gained the `{"accordion", K::Accordion}` entry this batch (without it kindFromString
refuses the name and gpui_shim_push rejects the node with lastError — the three
three-part entries sit right beside it). Items accept only `accordionItem()` (typed
check throws on the spot). The accordion now has **three expressions**: kind three-part
parts, kind root (aria group semantics), and the catalog finished product
(multiple/bordered/size).

### Scroll viewport + Scrollbar bar (batch 3, 2026-10-30, shared ScrollbarHandle)

The last of the 15 upstream state kinds, `ScrollbarHandle`, closed in this batch (the
creator takes no arguments; `NewHostState` passes argc=0 straight through, measured):

```php
// Scroll viewport: children + generic style both legal, vertical by default; Scrollbar
// bar: rejects children/generic style (materializes to an absolutely-placed empty Div
// over the viewport), the bar itself is painted by the viewport sharing the handle.
El::vFlex([
    El::catalogScroll('kit-sb', [$rows])->gap(4.0)->p(8.0)->w(220.0)->h(96.0),
    El::catalogScrollbar('kit-sb', 'vertical', 'always'),   // same key = shared handle
])->relative();
```

- **The same key string is the "handle controls viewport" seam**: `El::state('ScrollbarHandle', $key)`
  caches by (kind,key); Scroll and Scrollbar must pass the same `$key` to share one entity.
- The `scrollAxis()` sugar whitelists `vertical|horizontal|both` — the method is named
  `scroll_axis`, not `axis` (the runtime element prototype intercepts `axis` first and
  `both` would never reach the registered component — upstream renamed it on purpose).
- The Scrollbar's `mode` three values share the names of the kind-face `scrollbarMode()`
  but run on a different channel (registered_method vs behavior chain).
- Pixel evidence: the kit selftest's `scrollbarHandleProbe` gate (entity probe plus
  with-bar/without-box right-edge regionRgb comparison — the upstream ApplyBar
  self-painting path, unaffected by macOS NSScroller auto-hide).

### transition / spring motion sugars (batch 3, 2026-10-30)

```php
El::vFlex([El::text('')])
    ->id('kit-anim-box')                      // must carry an id: the cross-frame motion
                                              // identity is the id op
    ->w(240.0)
    ->transition('width', 300.0);             // or spring('width', 250, 1, 0.001)
```

- Upstream is a **sampler** inside materialize (MotionValue/SpringValue write
  element->style back every frame, stored in WindowMotionState which persists across
  frames), not a CSS animation.
- **Only five properties are actually sampled**: opacity/width/height/left/top (width
  reads the `w`/`size` style op, height reads `h`/`size`) — the `MOTION_PROPERTIES`
  whitelist rejects other names on the spot.
- Easing whitelist `linear|ease-in|ease-in-out` (empty string = upstream default);
  spring response/damping/epsilon default 250/1/0.001.
- The kit selftest's `transitionInterpolation` gate runs a three-arm assertion: initial
  frame probe = background, 2 frames after the flip still = background (ruling out the
  per-frame jump-cut that a specId identity drift would cause), pumped-to-convergence =
  box color — the early arm and the converged arm together are what "interpolation
  visible" means.

### Catalog upgrade sugar for four families (Select/Combobox/Popover/TabBar)

```php
El::catalogSelect('kit-sel', 'Choose one')->selectMenuWidth(200.0);
El::catalogCombobox('kit-cb', 'Search', 'Search hint', 'kit-cb-change', 'kit-cb-confirm')
    ->comboboxSearchable()->comboboxMenuWidth(220.0);
El::catalogPopover('kit-po', 'Popover', El::vFlex([El::catalogLabel('Popover body')])->gap(4.0));
El::catalogTabBar('kit-tb', ['Name', 'Path', 'Size'])
    ->tabBarMenu()->tabBarSelectedIndex(1)->tabBarVariant('tab')->tabBarSize('large')
    ->tabBarOnToggle('kit-tb-toggle');
```

The kind layer's same-named kinds are not capability-equivalent (bare frame vs styled
frame with a method table) — the catalog second face follows the catalogButton/button()
dual-track precedent. The argument shapes per family:

- **Select**: ctor takes four args (id + three callbacks rows/render_row/on_select);
  rows is a **row-returning** callback (the shim callback channel does not return values —
  DataTable's experience) — structure only, no data channel; real data waits for batch 6
  (the SelectProbe seam has no shim read access, same keypoint as the chart families);
  placeholder goes through the method.
- **Combobox**: ctor takes four args (id + rows + on_change + on_confirm); method table
  placeholder/search_placeholder/searchable/menu_width/disabled.
- **Popover**: ctor takes two args (id/label), content goes through the named slot
  (hard requirement "require content(element)"); method table card_anchor (8 corners,
  POPOVER_ANCHORS whitelist) / default_open/open/appearance/overlay_closable booleans +
  the on_open_change callback. **catalogHoverCard can enter a tree since batch 2**
  (corrected 2026-10-30: the earlier "never put in a tree — trigger_element is a shim
  gap" note is stale; the batch-5 element_arg bridge already carries SchemaElement
  method arguments, and the factory takes an `?self $trigger` eleventh argument fed
  through `->elementArg('trigger_element', …)`; upstream treats a missing trigger as a
  loud per-frame Fail "HoverCard requires trigger_element(element)"
  (hover_card.cpp:79-82/145) — a hard requirement, not a silent gap; the kit tree 3
  carries a real-trigger sample).
- **TabBar**: ctor takes two args (id + tabs); children accept only registered-side Tab
  (the kind `El::tab()` is not a registered node — attaching one errors every frame);
  method table menu/selected_index/variant (tab|outline|pill|segmented|underline
  whitelist)/size/on_change — each goes through its own instance sugar (parameters with
  bool/int defaults in the ctor signature are always taken as sugar — the TypePhp translator
  generates a default-value re-assign defect on mixed-default + partial-arity signatures,
  which makes the example translation fail outright, measured).

### Bare-channel dedicated factories, three groups (batch 2, 2026-10-30)

Of the bare-channel list on the coverage page, everything but the Accordion root got
factories read straight off the upstream descriptors (pure PHP, no bridge, no patches):

```php
// Command palette trio (command.cpp): Group(label) accepts only Items; Item(label)
// methods disabled/keyword/checked/action go through ->rm(); Separator() takes nothing.
// catalogCommand's children whitelist is tightened to the three export names.
El::catalogCommand('kit-cmd', [
    El::commandGroup('Files', [
        El::commandItem('New file')->rm('action', ['kit-cmd-new'])->rm('keyword', ['new create']),
    ]),
    El::commandSeparator(),
    El::commandItem('Toggle theme')->rm('checked', [true]),
]);

// NativeMenu trio (native_menu.cpp): the trigger's on_effect_error is a materialize
// **hard requirement** (missing = per-frame Fail), so the error-callback id is a
// required third argument; children accept only Item(label,action)/Separator. Row
// selection dispatches ShellAction → the 'action' event (event id = the action name);
// `$app->on('kit-nm-copy', 'action', …)` receives it — real selection needs a human.
El::nativeMenuTrigger('kit-nm', 'Native menu', 'kit-nm-err', [
    El::nativeMenuItem('Copy path', 'kit-nm-copy'),
    El::nativeMenuSeparator(),
]);

// Resizable catalog second face (layout/resizable.cpp): same component as the kind-face
// hResizable()/vResizable(), different child shape — catalog ctor id, children accept
// only catalogResizablePanel, layout state held by upstream keyed state internally
// (PHP feeds no ResizableState).
El::catalogResizable('kit-rz', [
    El::catalogResizablePanel(El::text('A'))->panelSize(180.0)->panelSizeRange(120.0, 320.0),
    El::catalogResizablePanel(El::text('B'))->panelVisible(true),
])->axis('horizontal')->crossSize(96.0);
```

- `axis()` has a two-value whitelist `horizontal|vertical` (upstream loud-Fails unknown
  values); `crossSize`/`panelSize`/`panelSizeRange` front-run the upstream FinitePositive
  and min≤max checks and throw on the spot.
- The panel's `size`/`visible` collide with kind-face helpers of the same names
  (the `size` enum sugar, the `visible` nullary style), so they ship under the
  catalogButton alias convention as `panelSize`/`panelSizeRange`/`panelVisible`.
- The Resizable drag handle needs a real human (the patch-resize-handle.sh probe
  standard) — the static baseline only pins that the panel tree builds and the sugars
  emit their names.

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

### Ring progress ProgressCircle (upstream descriptor gap filled)

```php
El::progressCircle('pc-x', 62.0)      // id + percentage (0–100, upstream clamps)
    ->progressCircleLabel()          // percentage printed in the ring (registered_method 'label')
    ->progressCircleSize(72.0)       // pixel Number (widget's Size(float), not kSizeLiterals)
    ->progressCircleColor('#e07b39') // ParseColorArgument (same as Spinner)
    ->progressCircleLoading();       // indeterminate spin (animated — hide for pixel baselines)
```

The widget shipped with the vendored upstream tree long ago (ui/progress.h); what was missing was
the catalog surface. Batch 5 added the descriptor via `cmd/patch-progress-circle.sh`, replicating
the kProgress five-piece pattern in compound/progress.cpp and hooking it into
`RegisterCompoundProgress` — **a new export name, vocabulary count 137→140**. `value` is a
percentage Number; `loading` switches to the indeterminate spin, which is animated.

### Popup menus PopupMenu / ContextMenu (upstream descriptor gap filled)

```php
El::popupMenu('pm-x', ['New task', 'Export CSV']);                  // rows = label strings
El::contextMenu('cm-x', El::button('Right-click me'), ['Copy', 'Paste']); // 1st child = target
```

The widget layer (ui/menu.h's `component::PopupMenu`/`ContextMenu` plus base/popup_menu.h's row
model) was already in the vendored tree; the catalog surface was missing. Batch 5 created
`overlays/popup_menu.cpp` via `cmd/patch-popup-menu.sh` and hooked it into the overlays
registration chain. **Rows are ordinary children**: a child element's text is the row label.
Rows carry **no click handler** — the Func0 userData packing does not expose a callback id
(honest limitation, documented in the patch script). The ContextMenu's first child is the
right-click target (upstream `ContextMenu::Child` wiring via OnMouseDownRight; real right-clicks
need a human HID — the selftest treats it as a premise-based gate).

### Batch 5 low-level gap filling: elementArg and EmptyHeader

`->elementArg($name, $element)` feeds an element into a registered_method's **SchemaElement
argument**: the bridge (cmd/patch-element-arg.sh) emits the child element first to obtain its
SpecId, then sends the method with that SpecId as the argument (the slot branch's shape —
emitTree runs ops before children, so the child must be built first, like a slot).
`EmptyHeader`'s `media/title/description` are thereby unlocked:

```php
El::emptyState('icons/square-terminal.svg', 'Title', 'Description', null, true) // 5th arg = header slot route
// or the direct form:
El::catalog('EmptyHeader', [])
    ->elementArg('media', El::catalog('EmptyMedia', [], [El::icon('icons/…svg')])->rm('variant', ['icon']))
    ->elementArg('title', El::catalog('EmptyTitle', [], [El::text('Title')]))
    ->elementArg('description', El::catalog('EmptyDescription', [], [El::text('Description')]));
```

The arguments must be registered Empty part elements (`TakeElementAs` resolves the
TypedChildBox by tag — feeding a bare icon/text yields "registered EmptyMedia materialized an
incompatible element", measured). `ResolveElement` is upstream's established SchemaElement
argument resolution (the same mechanism status_bar/hover_card/input_group use). This bridge
also unlocks StatusBar's `left_content`/`right_content` (previously a shim gap) — but those two
do not get factories in this batch.

## Next


- [Events and callbacks](./events)
