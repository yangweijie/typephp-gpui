# Upstream Coverage

TypePHP GPUI links the C++ port, `gpui-kit-cpp`. The concepts and API names follow
[gpui-kit documentation](https://gpui-kit.com/docs/), but a Rust API, a C++ symbol,
and a working PHP feature are three different things.

This page distinguishes missing bindings, alternate host APIs, and bindings that
exist but do not yet have interaction evidence. A recognized name is necessary,
but it does not prove construction, materialization, event delivery, or pixels.

Refresh the vocabulary comparison with:

```bash
php cmd/upstream-vocab.php
php cmd/check-upstream-parity.php --info
```

The scripts read the vendored source, not a remote documentation snapshot.

## 1. Components

| Surface | Upstream vocabulary | PHP access | Boundary |
|---|---|---|---|
| `ComponentKind` | 66 enum values | 51 names in `kKindNames` (2026-10-28 batch 3 added accordion_header/trigger/panel; 2026-10-30 batch 3 added the accordion root — one shim entry plus `El::accordion()`), plus dedicated ABI paths | Subtracting 51 from 66 does not measure missing features |
| Catalog | 146 export names; 161 distinct method names (pristine tree: 137/158; nine export names — batch 5's ProgressCircle/PopupMenu/ContextMenu + batch 6's CandlestickChart/SankeyChart/SearchableList + batch 7's Typewriter/GradientText/AnimatedNumber — come from the sandbox patches `cmd/patch-*.sh`) | Dedicated factories and `El::catalog($name, $args)` | Stateful constructors and synchronous callbacks still need host integration. Factories without a dedicated constructor still lack PHP-side argument hints and enum validation; failures come back through `App::registeredError()` |

Distinct method names are not the sum of methods across descriptors. A shared
method such as `size` occurs in several descriptors but counts once in the distinct
vocabulary. Do not compare that number with older descriptor-occurrence totals.
(The 2026-10-28 kit batch raised the count from 148 to 157: the vocabulary
extractor previously could not see through named constexpr method descriptors
collected by reference, such as the Dialog family's `kDialogMethods = {kTitle,
kOnOk, …}`. Of the nine newly visible names, PHP actually emits only `on_ok` and
`on_close`; the other seven (`grid`, `linear`, `natural`, `step_after`,
`tick_margin`, `x_axis`, `scroll_axis`) surface from the chart and Scrollbar
families and are never sent from PHP. A later tree sync brought the distinct count to
158; batch 5 added three more export names in the sandbox, which brings the usable total
to 140 — the numbers above are recomputed with `php cmd/upstream-vocab.php`.)

### Base and catalog are not interchangeable

`El::button()` constructs the base `ComponentKind::Button`.
`El::catalogButton()` constructs the styled catalog `Button`. The latter supports
`label`, `tooltip`, `loading`, `size`, `outline`, `primary`, `secondary`, `danger`,
`success`, `warning`, `ghost`, `link`, and `compact`.

It does **not** have a `variant` method. That method belongs to other descriptors,
such as DropdownButton, Tag, Bubble, Marker, GroupBox, and TabBar. A matching
component name does not imply a matching method table.

### Generic catalog construction

Since 2026-10-28, dedicated factories exist in `src/Element.php` across three batches
(tier-A gap list, pure-factory warm-up, and the S-factory-prerequisite batches) — 27,
then 5, then 6 tier-A factories plus one composition sugar (SplitButton) — each replacing
the bare `El::catalog('ExportName', …)` channel:
Separator (four variants, with the embedded-label method), Tooltip (the trigger
component), Skeleton, Empty (assembled from the quintet), Badge/Tag (with
`->variant()`/`->pill()`), Stepper/StepperItem, Tree/TreeItem, Pagination, Rating
(read-only goes through the disabled behavior), Kbd/KbdCombo, ShimmerText,
Dialog/AlertDialog/Sheet (real button-triggered native window effects; Dialog/Sheet
require the `content` named slot), DescriptionList/Item, DataTable (**structure only —
the data channel is not connected**), Settings, and Carousel (assembled eight-piece set).
Signatures, snippets and pitfalls:
[Components · Kit factories](./components#kit-factories-dedicated-factories-for-tier-a-families).
The "pure factory warm-up" batch added five more on the plain `reg()` channel: Spinner
(`icon`/`ease` are component-private enums with their own whitelisted sugar), TableFooter
(**registered-only** — the ComponentKind enum has no such kind; rows and cells must be
registered-side TableRow/TableCell), GroupBox (variant set
normal/fill/outline, merged into the `->variant()` whitelist), VForm (a second export
name of the same constructor as `'Form'`), and InputGroupText (the full InputGroup
composition is not assemblable today; the decoration renders standalone — see the
components page).
The "S factories with prerequisite work" batch added six more with prerequisite checks
plus one composition sugar: Label/Toolbar/StatusBar/Clipboard/HorizontalRadioGroup and
SplitButton (a composition sugar on `DropdownMenu`, not an independent export). The
prerequisite conclusions: Label's `highlights` argument is a `SchemaString` (one match
string, not the array the checklist guessed), StatusBar's `left_content`/`right_content`
take a SchemaElement (shim gap, middle region only), Clipboard's copy is built-in widget
behavior (premise-based gate), HorizontalRadioGroup accepts only registered-side Radio.

The "m" batch added seven factories on the same `reg()` channel with structural gates:
Notification (catalog component, two-way alongside `App::notify()`), DropdownButton
(`->menuItem()` sugar, private variant/anchor whitelists), Bubble/Marker (component-private
enum whitelists), Message/MessageScroller/Attachment (MessageScroller's
render_item is an **element-returning** callback — corrected 2026-10-30: the earlier text
called it row-returning and said "data channel pending batch 6"; the batch-6 host data loop
only carries data-returning callbacks, and element-returning callbacks are the proven
SIGBUS fork (`BuildComponentElement` shimId split), so this half stays unconnected — it is
never put in a tree because it would raise `componentStates`), and the five chart families
(structure only — their data-channel failure gate is not landed: rendering the negative
path segfaults the native process and the `ChartErrorProbe` seam has no shim install or
read access; the keypoint is recorded in the kit selftest's skipped list).

The batch-4 batch adds nested structure and second faces: the Questionnaire family (four
layers of typed nesting — root > Item > Choice/Input, checked layer by layer; the event
surface audit found no option-change callback — focus/navigation live inside the retained
state, no new patch needed), the catalog Accordion (multiple/bordered/size/on_toggle; the
title argument is a SchemaElement shim gap — items are built by count with no title), and
the four catalog upgrade sugars (Select/Combobox's rows is a row-returning callback —
structure only, real data pending batch 6; Popover's content goes through the named slot;
TabBar accepts only registered-side Tab; catalogHoverCard's trigger_element (corrected
2026-10-30 batch 2: the earlier "hard gap, never enters a tree" note is stale — the
batch-5 element_arg bridge already carries SchemaElement method arguments, and the
factory now takes a `?self $trigger` fed through `->elementArg('trigger_element', …)`;
upstream treats a missing trigger as a loud per-frame Fail, not a silent gap);
optional parameters with bool/int defaults always go through
instance sugars — the TypePhp translator generates a default-value re-assign defect on
mixed-default + partial-arity signatures, which fails the example translation outright,
measured).

Batch 5 is the first batch to reach past the PHP layer: all upstream changes go through
idempotent `cmd/patch-*.sh` scripts applied to the **sandbox** `build/gpui-kit-cpp` (the
`upstream/` tree is read-only; `sync-upstream.sh` copies upstream → sandbox and the patch
chain replays from there). Four more components got factories: ProgressCircle (the widget
shipped with the vendored tree long ago — only the descriptor was missing; the five-piece
pattern is replicated into compound/progress.cpp), PopupMenu/ContextMenu (widget layer
present, descriptors entirely missing — `overlays/popup_menu.cpp` is created, rows are
ordinary children), and `EmptyHeader`'s `media/title/description` (a SchemaElement shim
gap — `cmd/patch-element-arg.sh` adds an `element_arg` op to the bridge so `->elementArg()`
can feed them, and `emptyState()` gains a `$headerSlot = true` switch for the upstream
native header slot route).

Batch 6 continues the patch pattern and adds the host data loop (`cmd/patch-host-data.sh`
routes shim callbacks through `php::call` for return values): CandlestickChart and
SankeyChart (chart/mod.cpp — widgets already vendored, descriptors added; both take rows
callbacks that return real arrays through the data loop), and SearchableList
(delegate_collections/searchable_list.cpp — new file; rows callback → SearchableListItem
array → delegate defaults).

Batch 7 is the only batch that writes C++ widgets from scratch in the upstream ui layer
(the Ely spec describes them; neither the upstream tree nor the reference repo has them):
Typewriter, GradientText, and AnimatedNumber (`ui/text_effects.h/.cpp` — new files;
`cmd/patch-text-effects.sh`). All three follow the ShimmerText three-layer pattern:
widget → descriptor (`controls/text.cpp`) → PHP factory. Typewriter and GradientText are
pure style; AnimatedNumber uses MotionAppear for count-up. Visual verification of the
effect family requires frame-by-frame snapshots or human inspection — not fully
automatable, which is why this batch is last.

`El::catalog()` remains the channel for the rest: the Accordion root (`El::accordionItem()` uses the base-kind three parts, batch 3).
Batch 2 (2026-10-30) shrank the bare-channel list by three groups — dedicated factories
now exist for CommandGroup/Item/Separator (plus typed-children validation on
`catalogCommand`), the NativeMenu trio (NativeMenuTrigger hard-requires an
`on_effect_error` callback, so the factory takes the error-callback id as a required
third argument), and the catalog Resizable/ResizablePanel second face
(`axis()`/`crossSize()` + alias sugars `panelSize`/`panelSizeRange`/`panelVisible` for
the names that collide with kind-face helpers).

Consult the constructor schema from `upstream-vocab.php` before using a generic
factory. These names do not all have PHP-side argument hints or enum validation.
Read `App::registeredError()` and component failure diagnostics after rendering;
a descriptor being present is not a guarantee that every argument shape is usable.

### State-backed catalog components

`El::state($kind, $key, $args)` uses the host state entry point installed by
`cmd/patch-host-state.sh`. See [Components](./components) for construction examples.

The eight styled form factories have native construction, retained-state reuse,
and pixel evidence in `examples/input`:

- `catalogInput` and `catalogNumberInput`
- `catalogOtpInput` and `catalogSlider`
- `catalogColorPicker` and `catalogCalendar`
- `catalogDatePicker` and `catalogTimeField`

They cover seven state kinds; the reuse check expects eight entities on both the
early and late frame. `examples/catalog` tree 2 adds TextareaState, EditorState,
DiffState, CommandState, and CarouselState; the 2026-10-28 kit batch put
CarouselState (via `El::carousel()`) and DataTableState (via `El::dataTable()`) into
`src/` as well. Together these cover 14 of the 15 state kinds for creation and
materialization — not every value or event payload.

`ScrollbarHandle` — the last of the 15 — landed with batch 3 (corrected 2026-10-30:
this paragraph used to say it "still needs a complete host composition"; the
`catalogScrollbar()` / `catalogScroll()` factories construct it through the #81
`NewHostState` channel — the creator takes no arguments (scroll.cpp:153-158 NewHandle
ignores them), `gpui_shim_state` passes argc=0 straight through, and the kit selftest's
`scrollbarHandleProbe` gate proves entity creation (+2), a clean registeredError, and
the always-mode bar painted at the viewport's right edge via the shared handle).
Earlier correction kept for the record: this paragraph also used to say
MessageScrollerState "never appears in `src/`" — false; the
batch-3 `El::messageScroller()` factory constructs it via
`self::state('MessageScrollerState', …)`, and `php cmd/check-upstream-parity.php --info`
reports it among the used state kinds. What is open is its render_item half, above).
DataTableState's **data half** landed with batch 6 (corrected 2026-10-30: the earlier
text said the shim callback channel returns no values and kept the data channel at
tier B — `cmd/patch-host-data.sh` routes shim callbacks through `php::call`, rows
snapshots return real arrays via `App::on($id, 'render_rows', …)`; the positive/negative
gates are `dataTableRowsCallbackSuccess` / `dataTableRowsCallbackLoud` in the kit
selftest, and the `stateCounts()['dataTableFails']` probe remains as the negative-path
evidence). **The render_cell / render_item element half landed with batch 4** (2026-10-31
correction: the "element callbacks are the proven SIGBUS fork" note below was
accurate for the pre-batch-4 world — the fork in question was the **JS path's
ElementId branch**; batch 4's patch-host-element-cb.sh adds a shimId fork in
BuildComponentElement, so host element descriptions flow through emitTree into a
frame arena and bypass it. The SankeyChart-era segfault's faulting frame was the
snapshot bookkeeping misusing a PhpView entity — the `Get()` template tag has no
type check for ScriptView — and is gone now that the fork uses the frame arena).
MessageScroller's
render_item has real-render evidence = the kit selftest's messageRowRender gate
(renderCalls>0, ledgers clean; row pixels advisory). render_cell's mechanism is
the same seam and works; the examples keep the cellText default rendering.

### Alternate host APIs

Since 2026-10-28 some of these have a second route through the catalog's
trigger-based window effects (see
[Components · Kit factories](./components#kit-factories-dedicated-factories-for-tier-a-families)):

| Upstream concept | PHP route |
|---|---|
| Dialog / AlertDialog | `App::openDialog()`, or the trigger factory `El::dialog()` / `El::alertDialog()` |
| Sheet | `App::openSheet()`, or the trigger factory `El::sheet()` |
| Application notification | `El::notifications()` (the kind notification layer) + `App::notify()` + `El::catalogNotification()` (corrected 2026-10-30: the note "the catalog `Notification` export has no factory yet" was stale — the batch-3 factory exists in `src/Element.php`, with `message`/`type`/`on_click`/`on_close`/`autohide` as method sugar) |
| Tooltip | Element `tooltip()` method, or the trigger factory `El::catalogTooltip()` |
| Clipboard | `App::clipboardText()` / `setClipboardText()` / `clipboardItem()` |
| FpsMonitor | `App::showFpsHud()` |
| DockArea | `App::createDock()` and `El::dockArea()` |
| Native child views | Dedicated WebView, Inspector, and notification-layer factories |

## 2. What the kind table does not express

The 15 enum values outside the ordinary string table are:

ChildView, Pagination, AvatarImage, AvatarFallback, PathFill, PathStroke,
FpsMonitor, SliderTrack, SliderIndicator, SliderThumb, DockArea, DockContent,
VVirtualList, HVirtualList, and Registered. (AccordionHeader/Panel/Trigger joined the
kind table on 2026-10-28 — `El::accordionItem()` assembles its three parts from them;
the **Accordion root joined on 2026-10-30 batch 3** — one shim `kKindNames` entry plus
`El::accordion($id, $items)`, shrinking this list from 16 to 15.)

This is a **table difference**, not a list of 15 unavailable PHP features:

- Registered is constructed by descriptor index.
- ChildView is used by dedicated native-view APIs.
- DockArea has a retained Dock ABI, including panels and layout persistence.
- FpsMonitor has a window service API.
- Pagination can also be represented through catalog descriptors.
- `list` and `uniform_list` have a synchronous host renderer; `uniform_list` is
  already in the string table. VVirtualList/HVirtualList remain distinct enum names.

The base Slider and Accordion families do not expose every low-level child kind.
Use their supported catalog compositions rather than inventing unknown child names.
PathFill/PathStroke and the related drawing operations are not exposed.

`table_row` and `table_cell` have factories, examples, and index tests.
The **catalog** Scrollbar pair (Scroll + Scrollbar sharing a ScrollbarHandle) got
dedicated factories in batch 3 (`catalogScroll()` / `catalogScrollbar()`); the bare
`scrollbar` **kind** in the vocabulary table still has no kind factory, and the catalog
Scrollbar rejects children and generic style (the bar is painted by the viewport that
shares the handle). It must not be confused with the `scroll_bar` accessibility role.

## 3. Operations and styles

`method()`, `callback()`, `style()`, and `paramStyle()` are private implementation
methods. PHP cannot send arbitrary behavior names through a public escape hatch.
Catalog `registered_method` calls use a separate descriptor method table.

The parity report currently finds 34 distinct method names, including bridge
literals (25 before batch 1 on 2026-10-30; the four overlay-behavior and five
advanced-scroll op sugars raised the method-op vocabulary to 34), and 24 callback
names. Supported behavior includes identity, value and
selection, `open`, tooltip/link properties, table dimensions, scrolling overflow
and the advanced-scroll modes, overlay behavior, accessibility, and keyboard focus
metadata. These vocabulary counts are not
interaction counts.

### Remaining operation gaps

| Family | Missing or incomplete access |
|---|---|
| Overlay configuration | Element-level helpers added in batch 1 (2026-10-30): `defaultOpen`, `overlayClosable`, `openDelayMs`, `closeDelayMs` — these run on the kind-node behavior chain, and the delay helpers are consumed by HoverCard only (0–60000 range rejected in PHP). Factory-level sugar also exists (catalogPopover sends default_open/overlay_closable, catalogHoverCard sends open_delay/close_delay through the descriptor's registered_method table — a different channel); `open` and `on_open_change` are already connected |
| Events | Resize routing is connected, but physical drag delivery remains unverified; link/token delivery remains unmeasured |
| Token rendering | Integrated in batch 4 (2026-10-31): the `token` render callback is host-rendered via patch-token-render.sh (the RenderInlineToken shimId fork), and `on_token_click`/`on_token_hover` delivery is quantified (the tokenDeliveryRender gate); the chip's pixel placement is advisory (BoundsOut multi-frame settlement — see Events). The token-minting entry point is `App::inputReplaceToken` (InputReplaceWithToken) |
| Animation | Sugars added in batch 3 (2026-10-30): `transition($property, $ms, $delayMs, $easing)` and `spring($property, $response, $damping, $epsilon)` as method ops — the C++ sampler, the JS sugar, and the ops vocabulary were all already there; PHP only lacked the names. **Only five properties are actually sampled** (materialize.cpp:900 properties array opacity/width/height/left/top; width reads the w/size style op, height reads h/size) — "upstream animatable fields are not limited to five" refers to the StyleFieldsOf mask table (the animatability surface for transition()/spring()), while the sampling surface is exactly these five; both readings live on this page. Easing whitelist linear/ease-in/ease-in-out; the cross-frame motion identity is the element `id` op (behavior.key → MotionIdentity's first choice) — an element you animate must carry `->id()`, otherwise the identity falls to specId and degrades into a per-frame jump-cut; the kit selftest's transitionInterpolation gate asserts "early frame ≠ final, later frames = final" interpolation |
| Custom drawing | Batch 5 (2026-10-31): PathFill/PathStroke kind entries + `gpui_shim_push_path` (fills background/strokeWidth before Push) + the six command sugars (moveTo/lineTo/curveTo/cubicBezierTo/arcTo/closePath, arities aligned with runtime.cpp:8154-8177); pixel evidence = the pathPaintGate (the triangle centroid reads the exact fill color) |
| Advanced scrolling | General helpers added in batch 1 (2026-10-30): `overflowScrollbar`, `scrollbarMode` (three-value whitelist), `viewportFromLayout`, `scrollSize`, `withItemToMeasureIndex`. Consumption boundaries, stated as they are: `overflow_scrollbar`/`mode` have real consumers (the ScrollMode assembly at materialize.cpp:2803-2804; the kit selftest adds a `scrollbarModePaintPair` gate — structural hard gate, regionRgb pixel A/B downgraded to advisory: macOS headless rendering auto-hides native scrollbars, both boxes' right-edge readings are expected to be identical, "always actually draws a scrollbar" needs real HID or --shot verification); `with_item_to_measure_index` lands on UniformList only (plain `list` logs "ignored"); `viewport_from_layout`/`scroll_size` are parsed on the behavior chain but **consumed nowhere** (recorded at :386-390, no read site below — the former's real consumer is the catalog Scrollbar descriptor; since batch 3 PHP can create the ScrollbarHandle, through `catalogScrollbar($id, …, viewportFromLayout: true)` on the registered_method channel); `track_scroll` — see the note below |
| Other behavior | No general-purpose access to every upstream `pressed`, `indeterminate`, positioning, panel, mounting, or frame-budget operation; inspect the specific factory and ABI rather than assuming a generic method exists |

`track_focus` and `content_focus_handle` are emitted by the bridge for Select.
Their arguments are native `Bridged` handles, so a PHP string helper would be
misleading. Use `App::focusElement($id)` to focus the handles owned by the bridge.
`track_scroll` has the same limitation (materialize.cpp:434-440 wants a
`BridgedKind::Number` EntityHandle that only the bridge can produce); the batch-1
scroll helpers deliberately do not swallow it.

### Event evidence

Twenty of the 24 callback names have native delivery evidence: the original
click/change/hover/key-down group, ten pointer/wheel/key-up callbacks,
open-change/confirm/dismiss/step, and the two virtual-list item events.

`examples/table` checks stable row keys, right-button payload coordinates, and a
negative left-button control. It separately tests listeners on the row itself.
`examples/choices` checks open-change and confirm/dismiss; `examples/input` checks
step delivery. These use native AppKit paths, not proof of human HID input.

Resize, link-click, token-click, and token-hover must not be presented as fully
accepted interactions. See [Events](./events) for the individual boundaries.

### Style vocabulary

PHP emits **135 of 137 nullary keywords** (72 before batch 1 on 2026-10-30; the 63-keyword
diff was closed the same day) and 27 parameterized style names. The
upstream parameter tables contain 47 families and 12 other parameter names. The
parity gate checks that emitted names are recognized.

The keywords that lacked helpers before batch 1 were `col_span_full`, `row_span_full`,
`self_flex_start/_end`, `content_normal`, axis-specific hidden overflow,
`shadow_2xs/xs/xl/2xl`, `not_italic`, the text-decoration variants, `text_left`,
fixed flex-grow/shrink keywords, most cursors, and the debug styles — 50 in the named
group plus 13 more the old list never named (`col/row_start/end_auto`, `content_start/end`,
`justify_end`, `self_baseline`, `font_extrabold/extralight`, `text_ellipsis_middle/start`,
`whitespace_normal`), 65 in the measured diff. All but two are one-line sugars now,
each called for real in the examples (the style domain's selftest calls all 110 in the
compiled artifact on a detached element). The remaining two, `h_flex`/`v_flex`, are not
gaps: their upstream keyword is a display+direction+align triple, and the PHP route is
`El::hFlex()` / `El::vFlex()` building the kind name directly — they never belonged on
the nullary style call surface.

The `fields` member of an upstream `Keyword` is a tracked-field mask, **not arity
and not an effectiveness flag**. `ApplyNullaryStyle()` still calls its function.
Only seven nullary keywords have nonzero masks: the text-size family from
`text_xs` through `text_3xl`. Other animation masks come from hard-coded color,
opacity, and text-size names, plus `FamilyFields()` for parameter families.
Consequently, the old claim that only opacity/width/height/left/top can animate is
not a complete description of the C++ style tables. PHP animation remains
unimplemented regardless of that upstream detail.

## 4. Corrected APIs and their evidence limits

### Accessibility and focus

`role`, `aria_label`, `aria_level`, `aria_selected`, `aria_active_descendant`,
`tab_index`, `tab_stop`, `key_context`, and `selectable` are behavior operations.
The 181 role names are validated against the upstream table in both directions.
An unknown role throws instead of disappearing silently.

These operations need not change pixels. Operation tests and unchanged images do
not prove what VoiceOver announces; screen-reader behavior still needs its own
acceptance. Dialog focus-trap and native Escape closure have been observed;
human keyboard acceptance is a separate pending task.

### Button variants

`primary()`, `secondary()`, `danger()`, `ghost()`, and `outline()` now emit the
actual catalog Button methods. Previously they emitted unknown base behavior
operations and silently did nothing. Applying these helpers to a base kind throws
`InvalidArgumentException`; use `catalogButton()` for the styled component.

### Table semantics

`tableRow()`, `tableCell()`, `rowCount()`, `columnCount()`, `rowIndex()`, and
`columnIndex()` carry the semantic shape and indices. The C ABI has an index slot;
`App::specIndex($id)` reads it back and returns -1 for an unknown id.

The native checks live in `examples/table`. Upstream table parts are role-bearing
divs: row/column indices describe semantics, while styles still control layout.

### Virtual lists and Dock

The host list renderer receives the requested `[start, end)` range synchronously
and returns exactly those rows. Native tests cover 10,000 items without building
all of them, scrolling to a new range, row listeners, and item events.

Dock retains native layout state and uses the full default DockSkin renderer.
`examples/layout` covers selection, panel callbacks, atomic panel replacement,
JSON save/load, rejected layouts, retained reuse, and reclamation. Custom PHP Dock
chrome and physical drag acceptance are not implied by those checks.

## 5. Silent and reported failures

Upstream categorizes operations before materialization:

| Input | Result |
|---|---|
| Unknown nullary style name | Reported error during materialization |
| Unknown parameterized behavior name | May be silently ignored by the behavior chain |
| Invalid parameterized style | `invalid style call` error |
| Unknown base kind | Rejected by the shim; read `App::lastError()` |
| Invalid catalog name or arguments | Construction failure; read `App::registeredError()` |

This is why a successful build is not sufficient. `composer parity:upstream`
compares emitted kinds, methods, callbacks, slots, catalog names, and state kinds
with the vendored vocabulary. It catches names that otherwise fail silently, but
cannot prove that a listener was attached to the correct node or ever triggered.

## 6. Upstream concepts mapped to PHP

| Upstream topic | PHP counterpart or boundary |
|---|---|
| Installation / packaging | Native build and `.app` bundle through [CLI](./cli); no auto-update |
| Windows | `createWindow()` accepts `['borderless','clientTitleBar']` options and `windowAction()` exposes the three verbs (batch 5, the TitleBar period-B slice); **period C landed** (batch 2 A4, 2026-10-31): `El::titleBar()` (kind=`title_bar`) plus a dual-channel descriptor (`component_shell/title_bar.cpp`), `cmd/patch-title-bar.sh` with eight insertion points (spec.h enum / spec.cpp name / runtime.cpp lexicon / materialize dispatch / Construct exhaustiveness case / descriptor file / families.h / lib.cpp), kit selftest `titleBarShape` + the `titlebarWindowGate` wiring arm (debug_tree contains the TitleBar node), see Components |
| Entity / context / render / task / global / view-cache | Not a one-to-one Rust API mapping: PHP declarations, retained native entities, and a dirty-driven loop; see [Architecture](./architecture) |
| Styles | 135/137 nullary keywords (batch-1 diff closed; h_flex/v_flex go through kind names) plus parameterized helpers |
| Focus | Focus metadata, `focusElement()`, native focus-trap/Escape evidence; human HID still separate |
| Actions | `dispatchAction()` and menu-action routing; **element-level `on_action` landed** (batch 2 A5, 2026-10-31): `El::onAction('name')` ops intern via `gpui_shim_on_action` (`cmd/patch-host-action-cb.sh`) into ShellActions bound through `element->OnAction`; the DispatchArgs shim fork routes the event back to the PHP queue (short type `action`, handler `App::on(id,'action',fn)`). kit `actionDeliveryGate`: the fire_registered hard arm is green across all three stages (op registered + queue + delivery); the dispatchAction arm cannot reach the OnAction listener without a real focus in the synthesized state → advisory, tracked in C1 |
| Keybindings | Batch 5 (2026-10-31): `bindKeys` landed — KeyChordsParse → ShellActionOf → KeymapBind, the three steps of NativeBindKeys (whole-list pre-validation); unclaimed actions enqueue as the action event. Install receipt/table probe = the bindKeysGate hard assertion; synthesized cmd-key delivery is advisory (NSApp swallows cmd chords with no menu row; native keyboard is HID) | Events | 24 declared callback names, 20 with native delivery evidence |
| Animation | `transition` and `spring` not exposed |
| Paint / geometry / text | Layout and text available; no general path drawing |
| Accessibility | Validated roles and ARIA operations; VoiceOver acceptance remains manual |
| Images / assets / fonts | Embedded icon paths; shape validation is not a full base-icon whitelist. **HTTP images**: the remote core is implemented (the HttpFetch state machine at image.cpp:223-234 — Pending/None retrying next frame IS the whole retry policy; `data:` URIs decode locally, local assets walk `AssetsExists`) — `El::image($src)` passes src straight into the text field, and there is **no capability check on the img path** (the TextView path has one; the img path follows the reference repo's convention — whether to add a whitelist is a design decision); system-domain `imageAssetRenders`/`imageUrlPolicy` gates (batch 3 A8). **Font loading: none** — design section below |
| Internationalization | No application locale API; documentation locales are unrelated. Design section: [i18n design section (batch 4 D1)](#i18n-design-section-batch-4-d1) — the reference repo's `LocaleNow` (ui/i18n.cpp:47) plus the ThemeTokensSync revision refresh make a future `App::locale()` a shell-level addition, not a gpui-core one |
| System notifications | macOS wrapper exists; visible OS delivery needs separate acceptance |
| WebView | WKWebView through ChildView, including frame visibility management |
| Native extensions | PHP is the host; script-side host modules are not the same abstraction |
| WebAssembly / mobile | Not supported by this binding; current target is macOS arm64. Conclusion section: [wasm/mobile conclusion (batch 4 D2)](#webassemblymobile-conclusion-batch-4-d2) — the reference repo's window backend matrix actually ships a complete wasm pair (window_wasm.cpp + paint_wasm.cpp); what is missing is this repo's host chain, not the upstream surface |
| Tests / FPS | Native selftests, snapshots, shotdiff, and FPS HUD; HUD resource-row stability is still open |
| Design tokens | `App::setTheme()` exists; **the semantic token table is now exposed to PHP** (batch 3 A6, 2026-10-31): `App::themeTokens()` reads the whole table as one JSON document through `gpui_shim_theme_tokens` (a pure `char*` out-param per the C-ABI gate, testing.md gate 8) — colors as 17 `#rrggbb` keys + 7 spacing steps + 6 radius steps + typography sans/mono family names, built once per palette upstream (ThemeTokensSync), JSON shaped after the JS-route snapshot in runtime.cpp; system-domain `tokenTableExpose` gate asserts the 17-key set, the step lists and non-empty values. Semantic names like `bg('primary')` now have a PHP-side queryable value |

## Font loading channel (design section, batch 3 A7)

**Measured status**: the reference repo (the same-shaped C++ pin as upstream) has
**zero font-file loading seams** — a tree-wide grep for
`CTFontManagerRegisterFonts` / `AddFontMemResource` / `loadFont` /
`registerFont` hits nothing. Font resolution is system family lookup only:
`static CTFontRef FontFor(PaintApp*, float fontSize, uint16_t weight)` at
`src/gpui/paint_mac.cpp:1097` goes through `NSFontManager fontWithFamily:name`
and falls back to Menlo/system fonts on a miss (:1127-1139); `FontFamilyIntern`
at `src/gpui/gpui.cpp:2599` is just a string→uint8 intern (`gFontFamilies[128]`
cap). `El::FontFamily(Str)` (gpui.h:3172) takes an **interned family name**, not
a file.

**Future path** (a gpui-core paint-layer change, tier-B/L — outside this repo's
patch-*.sh shell convention):
1. `CTFontManagerRegisterFontsForURL` (or the in-memory
   `CTFontManagerRegisterFontRefs`) to register font files with CoreText;
2. cache invalidation for `pa->fonts` in `FontFor` (paint_mac.cpp:1097) — after
   registration the same family name must re-resolve, or the stale CTFontRef
   keeps hitting the fallback;
3. the id face of `FontFamilyIntern`: the `gFontFamilies[128]` cap and the
   lifetime of registered names (registry entries must outlive intern ids)
   need to line up;
4. a new shim ABI (say `int gpui_shim_font_load(const char* path, char* outName,
   int cap)`), pure C per testing.md gate 8 (no std::).

**Relation to A6**: `typography.sans/mono` in the semantic table
(`ThemeTypographyTokens` at theme_tokens.h:27, exposed to PHP by batch 3 A6 via
`App::themeTokens()['typography']`) are exactly the two family-name slots a
future loading channel fills: after loading a custom font, point sans/mono at
the registered name and `El::fontFamily(...)` plus the type scale take effect
as a whole.

## i18n design section (batch 4 D1)

**Status**: no locale mechanism in this repo (the ❌ row above) — no PHP-side
reader, no example consumption, no bridge channel.

**Reference-repo shape** (precedents exist; both are reproducible runtime
readers):
- `build/gpui-kit-cpp/port-map.md:26` records `locale` → `LocaleNow`; the
  implementation lives at `src/ui/i18n.cpp:47` (`Str LocaleNow()` returns
  `kLocaleNames[gLocale]`, with a setter in the same file that looks the name
  up and sets the index) — locale is **ui/base-side runtime state**, not a
  build-time constant;
- the refresh precedent is `src/shell/theme_tokens.h:21`'s
  `ThemeTokensSync(app)` (batch 3 A6's four-layer reader already plugs into
  it): appearance/theme changes bump the revision and rebuild the thread_local
  table — a locale change is the same family of refresh, and the semantic
  token table (including typography.sans/mono) should be invalidated together
  with locale/appearance.

**Future shape** (tier-B, a shell-level addition, no gpui-core changes):
1. an `App::locale(): string` reader — wrap `LocaleNow` following A6's
   `themeTokens()` four-layer precedent (shim pure-C ABI `char*` → bridge →
   stub → FakeBridge → App.php);
2. the semantic token table refreshing with locale/appearance:
   `ThemeTokensSync`'s revision mode is already in place — adding locale to
   its invalidation key is enough, `App::themeTokens()` keeps its shape;
3. an `App::setLocale(string)` writer, if ever needed, must first align with
   the `i18n.cpp` setter's allowlist (only names inside `kLocaleNames`).

**Why no code this round**: the upstream pin's locale face stops at a ui-layer
reader — the shell never exposes it to the host bridge, and no existing
example consumes it (no UI copy goes through a locale table). A reader with
nothing to assert is cost without benefit. When a real localization need
appears, follow the three steps above; the scale matches A6.

## wasm/mobile conclusion (batch 4 D2)

**Conclusion: still not doing it**, and the evidence is now stronger after a
correction — first, one ledger fix: the reference repo's window backend matrix
is not "mac/win/linux three platforms"; the actual listing is **mac/win/
linux/wasm four backends plus posix/mem_posix/window_common shared pieces**
(`ls src/gpui/`: window_mac/window_win/window_linux/**window_wasm**/
window_posix/window_mem_posix/window_common + paint_mac/paint_win/paint_linux/
**paint_wasm**), and no iOS/Android. A first-hand read of `window_wasm.cpp`'s
header: "The browser window: the canvas, the DOM events, the animation-frame
loop… mirror of Window_win.cpp and Window_linux.cpp" — the reference repo's
wasm window backend and Canvas2D paint backend (paint_wasm.cpp, EM_JS straight
into JavaScript) are **complete**.

So the blocker for wasm/mobile lives **entirely in the host chain**, three
counts:
1. **The host chain is bound to Cocoa**: the phpx AOT host plus
   `-framework Cocoa` linking is part of the artifact shape — seen at
   `cmd/onscreen-probe.sh:56`, `cmd/check-hotkey-pump.sh:51` and
   `cmd/build-tap-probe.sh:16` (`-framework Cocoa … -framework Metal
   -framework QuartzCore -framework Carbon`); a wasm target has no such chain;
2. **Ownership of the wasm face differs**: the reference repo's wasm face is
   C++-shell-drawn (canvas + DOM events + the animation-frame loop); this
   repo's host is the PHP → shim → gpui chain — landing wasm means rewriting
   the host runtime (no browser counterpart for the PHP interp/AOT layer);
3. **No mobile backend in the matrix**: beyond mac/win/linux/wasm there is no
   iOS/Android backend, so "mobile" has nothing to hang on in the reference
   repo either.

Decision record: wasm/mobile stays ❌ (the table row above is unchanged) unless
a browser-distribution need shows up on the product side — and the first thing
to evaluate then is not the gpui layer (the backends exist) but a wasm path
for the PHP host layer.

Continue with [Events](./events), [System Capabilities](./system-capabilities),
and [Testing](./testing) for the evidence behind each boundary.
