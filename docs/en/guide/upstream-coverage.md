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
| `ComponentKind` | 66 enum values | 47 names in `kKindNames`, plus dedicated ABI paths | Subtracting 47 from 66 does not measure missing features |
| Catalog | 137 export names; 148 distinct method names | Convenience factories and `El::catalog($name, $args)` | Stateful constructors and synchronous callbacks still need host integration |

Distinct method names are not the sum of methods across descriptors. A shared
method such as `size` occurs in several descriptors but counts once in the distinct
vocabulary. Do not compare that number with older descriptor-occurrence totals.

### Base and catalog are not interchangeable

`El::button()` constructs the base `ComponentKind::Button`.
`El::catalogButton()` constructs the styled catalog `Button`. The latter supports
`label`, `tooltip`, `loading`, `size`, `outline`, `primary`, `secondary`, `danger`,
`success`, `warning`, `ghost`, `link`, and `compact`.

It does **not** have a `variant` method. That method belongs to other descriptors,
such as DropdownButton, Tag, Bubble, Marker, GroupBox, and TabBar. A matching
component name does not imply a matching method table.

### Generic catalog construction

`El::catalog()` provides access to descriptors without dedicated PHP factories.
Examples include Accordion, Pagination, Rating, Stepper, TabBar, Settings,
Tree/TreeItem, separators, Badge, Tag, Label, Kbd, Toolbar, StatusBar, Skeleton,
Spinner, ShimmerText, Chat, Empty, InputGroupText, charts, DropdownButton,
GroupBox, DescriptionList, CommandGroup/Item/Separator, NativeMenu, Resizable,
Clipboard, Questionnaire, TableFooter, and VForm.

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
DiffState, CommandState, and CarouselState. Together these cover 12 of the 15
state kinds for creation and materialization, not every value or event payload.

DataTableState, MessageScrollerState, and ScrollbarHandle still need complete
host compositions. The first two require data or element-returning callbacks;
Scroll requires a shared viewport/handle relationship. A state constructor alone
does not finish those integrations.

### Alternate host APIs

| Upstream concept | PHP route |
|---|---|
| Dialog / AlertDialog | `App::openDialog()` |
| Sheet | `App::openSheet()` |
| Application notification | `App::notify()` plus `El::notifications()` |
| Tooltip | Element `tooltip()` method |
| Clipboard | `App::clipboardText()` / `setClipboardText()` / `clipboardItem()` |
| FpsMonitor | `App::showFpsHud()` |
| DockArea | `App::createDock()` and `El::dockArea()` |
| Native child views | Dedicated WebView, Inspector, and notification-layer factories |

## 2. What the kind table does not express

The 19 enum values outside the ordinary string table are:

ChildView, Accordion, AccordionHeader, AccordionPanel, AccordionTrigger,
Pagination, AvatarImage, AvatarFallback, PathFill, PathStroke, FpsMonitor,
SliderTrack, SliderIndicator, SliderThumb, DockArea, DockContent, VVirtualList,
HVirtualList, and Registered.

This is a **table difference**, not a list of 19 unavailable PHP features:

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
