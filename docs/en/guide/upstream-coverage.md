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
| `ComponentKind` | 66 enum values | 50 names in `kKindNames` (2026-10-28 batch 3 added accordion_header/trigger/panel), plus dedicated ABI paths | Subtracting 50 from 66 does not measure missing features |
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
render_item is a row-returning callback — data channel pending batch 6; it is never put
in a tree because it would raise `componentStates`), and the five chart families
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
TabBar accepts only registered-side Tab; catalogHoverCard's trigger_element is a hard gap
so it never enters a tree; optional parameters with bool/int defaults always go through
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

`El::catalog()` remains the channel for the rest: the Accordion root (`El::accordionItem()` uses the base-kind three parts, batch 3),
CommandGroup/Item/Separator, NativeMenu, and Resizable.

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
`src/` as well. Together these cover 13 of the 15 state kinds for creation and
materialization — not every value or event payload.

MessageScrollerState and ScrollbarHandle still need complete host compositions;
they never appear in `src/` or the examples. DataTableState's **data half** is also
open: `El::dataTable()` builds the entity plus the `rows`/`render_cell` callback
markers, but the shim callback channel returns no values, so the row snapshot fails
per frame — the failure is pinned by the probe counters
(`stateCounts()['dataTableFails']`) and the data channel is a tier-B item. A state
constructor alone does not finish those integrations.

### Alternate host APIs

Since 2026-10-28 some of these have a second route through the catalog's
trigger-based window effects (see
[Components · Kit factories](./components#kit-factories-dedicated-factories-for-tier-a-families)):

| Upstream concept | PHP route |
|---|---|
| Dialog / AlertDialog | `App::openDialog()`, or the trigger factory `El::dialog()` / `El::alertDialog()` |
| Sheet | `App::openSheet()`, or the trigger factory `El::sheet()` |
| Application notification | `App::notify()` plus `El::notifications()` (the catalog `Notification` export has no factory yet) |
| Tooltip | Element `tooltip()` method, or the trigger factory `El::catalogTooltip()` |
| Clipboard | `App::clipboardText()` / `setClipboardText()` / `clipboardItem()` |
| FpsMonitor | `App::showFpsHud()` |
| DockArea | `App::createDock()` and `El::dockArea()` |
| Native child views | Dedicated WebView, Inspector, and notification-layer factories |

## 2. What the kind table does not express

The 16 enum values outside the ordinary string table are:

ChildView, Accordion, Pagination, AvatarImage, AvatarFallback, PathFill, PathStroke,
FpsMonitor, SliderTrack, SliderIndicator, SliderThumb, DockArea, DockContent,
VVirtualList, HVirtualList, and Registered. (AccordionHeader/Panel/Trigger joined the
kind table on 2026-10-28 — `El::accordionItem()` assembles its three parts from them.)

This is a **table difference**, not a list of 16 unavailable PHP features:

- Registered is constructed by descriptor index.
- ChildView is used by dedicated native-view APIs.
- DockArea has a retained Dock ABI, including panels and layout persistence.
- FpsMonitor has a window service API.
- Accordion and Pagination can also be represented through catalog descriptors.
- `list` and `uniform_list` have a synchronous host renderer; `uniform_list` is
  already in the string table. VVirtualList/HVirtualList remain distinct enum names.

The base Slider and Accordion families do not expose every low-level child kind.
Use their supported catalog compositions rather than inventing unknown child names.
PathFill/PathStroke and the related drawing operations are not exposed.

`table_row` and `table_cell` have factories, examples, and index tests.
`scrollbar` is in the kind vocabulary but has no dedicated PHP factory; it must
not be confused with the `scroll_bar` accessibility role.

## 3. Operations and styles

`method()`, `callback()`, `style()`, and `paramStyle()` are private implementation
methods. PHP cannot send arbitrary behavior names through a public escape hatch.
Catalog `registered_method` calls use a separate descriptor method table.

The parity report currently finds 25 distinct method names, including bridge
literals, and 24 callback names. Supported behavior includes identity, value and
selection, `open`, tooltip/link properties, table dimensions, scrolling overflow,
accessibility, and keyboard focus metadata. These vocabulary counts are not
interaction counts.

### Remaining operation gaps

| Family | Missing or incomplete access |
|---|---|
| Overlay configuration | No PHP helpers for `default_open`, `overlay_closable`, `open_delay`, or `close_delay`; `open` and `on_open_change` are already connected |
| Events | Resize routing is connected, but physical drag delivery remains unverified; link/token delivery remains unmeasured |
| Token rendering | The content-returning `token` callback is not integrated; `on_token_click` and `on_token_hover` are separate ordinary events |
| Animation | No PHP `transition(prop, ms, delay, easing)` or `spring(prop, response, damping, epsilon)` API |
| Custom drawing | No `move_to`, `line_to`, `curve_to`, `cubic_bezier_to`, `arc_to`, `close`, PathFill, or PathStroke composition |
| Advanced scrolling | No general helper layer for `mode`, `scroll_size`, `viewport_from_layout`, `overflow_scrollbar`, `track_scroll`, or `with_item_to_measure_index` |
| Other behavior | No general-purpose access to every upstream `pressed`, `indeterminate`, positioning, panel, mounting, or frame-budget operation; inspect the specific factory and ABI rather than assuming a generic method exists |

`track_focus` and `content_focus_handle` are emitted by the bridge for Select.
Their arguments are native `Bridged` handles, so a PHP string helper would be
misleading. Use `App::focusElement($id)` to focus the handles owned by the bridge.

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

PHP emits **72 of 137 nullary keywords** and 27 parameterized style names. The
upstream parameter tables contain 47 families and 12 other parameter names. The
parity gate checks that emitted names are recognized.

Examples without helpers include `col_span_full`, `row_span_full`,
`self_flex_start/_end`, `content_normal`, axis-specific hidden overflow,
`shadow_2xs/xs/xl/2xl`, `not_italic`, several text-decoration variants, `text_left`,
fixed flex-grow/shrink keywords, most cursors, and debug styles.

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
| Windows | `createWindow()` and `windowState()`; validate each required multi-window workflow |
| Entity / context / render / task / global / view-cache | Not a one-to-one Rust API mapping: PHP declarations, retained native entities, and a dirty-driven loop; see [Architecture](./architecture) |
| Styles | 72/137 nullary keywords plus parameterized helpers |
| Focus | Focus metadata, `focusElement()`, native focus-trap/Escape evidence; human HID still separate |
| Actions | `dispatchAction()` and menu-action routing |
| Keybindings | System hotkeys exist; no general application keymap/chord API |
| Events | 24 declared callback names, 20 with native delivery evidence |
| Animation | `transition` and `spring` not exposed |
| Paint / geometry / text | Layout and text available; no general path drawing |
| Accessibility | Validated roles and ARIA operations; VoiceOver acceptance remains manual |
| Images / assets / fonts | Embedded icon paths; shape validation is not a full base-icon whitelist; no font-loading or HTTP-image policy API |
| Internationalization | No application locale API; documentation locales are unrelated |
| System notifications | macOS wrapper exists; visible OS delivery needs separate acceptance |
| WebView | WKWebView through ChildView, including frame visibility management |
| Native extensions | PHP is the host; script-side host modules are not the same abstraction |
| WebAssembly / mobile | Not supported by this binding; current target is macOS arm64 |
| Tests / FPS | Native selftests, snapshots, shotdiff, and FPS HUD; HUD resource-row stability is still open |
| Design tokens | `App::setTheme()` exists; no PHP semantic-token table |

Continue with [Events](./events), [System Capabilities](./system-capabilities),
and [Testing](./testing) for the evidence behind each boundary.
