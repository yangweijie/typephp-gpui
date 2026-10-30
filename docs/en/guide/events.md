# Events and Callbacks

Events cross the C ABI from gpui into a PHP queue, then reach handlers in the
application loop. Base controls, retained controls, catalog callbacks, and
asynchronous services enter that queue through different native paths.

The snippets below use the shared example state table, `ExampleState::$v`.
Initialize the referenced keys before registering handlers. In an application,
use your own shared state object or static table; do not rely on reference
captures in nano mode.

## Base control events

```php
$button = El::button('Go')->id('go')->onClick();
$app->on('go', 'click', static function (array $event): void {
    ExampleState::$v['count']++;
});
```

`onClick()` declares a callback operation. The bridge allocates a callback id and
records `(elementId, type)`. A native click reaches the patched
`ScriptView::OnClick`, calls `gpuiEnqueueClick`, resolves the owning window through
`boxForView`, and queues a `GpuiEvent`.

The loop pumps native events, polls the queue, and dispatches by `(id, type)`.
A handler with no required parameters is called without an argument. A handler
with one required parameter receives the **whole event array**, including `type`,
`id`, and `value`, not just its value.

```php
$app->onAny('click', static function (array $event): void {
    ExampleState::$v['lastClick'] = $event['id'];
});
```

`onAny($type, $handler)` subscribes to that type across all element ids. It does
not subscribe to all event types. The id-specific and wildcard handlers can both
run for the same event.

The native operation retains its `on_` prefix, such as `on_hover`. The PHP route
uses the short name, such as `hover`. Dropping the prefix before materialization
prevents native listeners from attaching even when direct queue tests pass.

## Pointer, wheel, and key-up events

| Element helper | Event type | `value` format |
|---|---|---|
| `onMouseDownLeft/Right/Middle()` | `mouse_down_left` / `_right` / `_middle` | `x,y,button,clicks,mods` |
| `onMouseUpLeft/Right/Middle()` | `mouse_up_left` / `_right` / `_middle` | Same format; upstream reports one click |
| `onMouseDownOut()` | `mouse_down_out` | `x,y,button,clicks,mods` |
| `onMouseMove()` | `mouse_move` | `x,y,pressed,button,mods` |
| `onScrollWheel()` | `scroll_wheel` | `dx,dy,x,y,mods` in DIP; one wheel unit is 48 |
| `onKeyUp()` | `key_up` | Key name or character |

Button numbers are Left=0, Right=1, Middle=2, NavigateBack=3, NavigateForward=4.
Modifier bits are control=1, alt=2, shift=4, platform/Command=8, function/Fn=16.
These pointer events use ordered CSV fields in `value`. The structured
virtual-list right-click payload described below is a separate format.

`cmd/patch-mouse-view.sh` redirects the ScriptView mouse callbacks to host hooks.
Without that patch, the upstream callbacks enter QuickJS, which this host does
not provide. A negative-control build without the patch crashed on native mouse
down; this is not merely an event that gets ignored. The build manifest and
`doctor` check that the required patch markers exist.

The script-side `propagate`/`stopPropagation` contract is not exposed. Native host
code determines propagation before PHP handles the queued event.

## Component-generated events

These callback ids are stored in component bindings rather than ordinary element
listeners. The PHP id belongs to the window's callback table; it is not
necessarily an entry in the upstream runtime's callback arena.

`cmd/patch-select-view.sh` redirects binding dispatch to the host queue. Without
it, looking up the window-local id in the runtime table could silently drop the
event or reach invalid script-engine state. The host hooks carry a boolean,
no payload, or a string as required.

### Open, confirm, and dismiss

`examples/choices` verifies:

- `open_change` reaches PHP with a boolean value after focusing the component and
  dispatching its open action.
- `confirm` and `dismiss` reach PHP when the component is declared open.
- The final open-change value is false after dismissal.

Both focus and the dispatch patch are required. Use `App::focusElement($id)` to
focus the native handles associated with the element.

Declare the component's current open state with `->open(true)`. In the upstream
Select action state machine, Confirm on a closed component means “open it”, while
Cancel on a closed component is not handled as dismissal. Removing `open()` from
the native test makes confirm/dismiss fail while open-change ends at true. That
negative control proves the state declaration is part of the contract.

The shell base Select is a combobox-role container, not a fully composed trigger
and popup. A missing clickable trigger must not be confused with a broken event
route; use the supported composition or the focused action path.

### Number input steps

`onStep()` delivers `increment` or `decrement`. The host must provide the contents
of the two step-button slots:

```php
$number = El::numberInput('amount')->value('42')->onStep()
    ->slot('decrement_button', El::button('−')->id('amount-dec'))
    ->slot('increment_button', El::button('+')->id('amount-inc'));
```

The native constructor's string is an id, not a label. Without slot contents the
buttons can have zero-size hit boxes; a zero event count then says nothing about
the dispatch route. `examples/input` tests delivery from a populated button.

**Value semantics of `on_step` (since 2026-10-25)**: once `on_step` is declared,
the native built-in stepping is **turned off** — materialize only passes the
`NumberStep*` when `!behavior.onStep && retained->number.hasStep`, so declaring
the callback leaves it `nullptr`. Pressing ± then **emits the event only and does
not change the number**: the value belongs to the script. The handler must read
the current value with `text(id)`, apply `increment`/`decrement`, and write the
result back with `setInput(id, …)` — otherwise the event chain is green while
the on-screen number never moves (the shape of bug B; the `stepDelivered` gate
now asserts `text('in-num')` changed by exactly ±1 in the delivered direction).

### Virtual-list item events

`App::listRenderer()` supplies rows synchronously. The host attaches list-level
item listeners to the row wrappers. Both `item_click` and `item_secondary_click`
use the row root id as `value`, or the global row index string when no id is set.

The secondary-click event also contains:

```text
button: "right"
click_count: number
position: {x, y}
local_position: {x, y}
modifiers: {control, alt, shift, platform, function}
```

`examples/table` checks row keys and coordinates through AppKit left/right clicks,
including a negative control that left-click does not emit secondary-click.
`virtualRowListeners` separately verifies listeners attached to the returned row
itself. List-level and row-level delivery are different checks.

### Resize

Attach `onResize()` to the **resizable group**, not an individual panel. Upstream
reads the callback from the group; the panel branch only consumes size, range,
and visibility.

The host dispatch patch converts the upstream float array into CSV, such as
`236,552`. Routing is connected, but physical drag acceptance remains open.
`App::realDrag()` reports `{pressed, moved, released}`: pressed/released indicate
window delivery; moved counts PHP queue growth and is not the number of OS moves.

On macOS, the native drag state machine reads `[NSEvent pressedMouseButtons]`.
In-process synthesized down/move/up events do not establish that physical state.
The layout probe has observed successful down/up delivery with zero queued moves
and no resize result. Do not mark resize as passed from those readings; test it
with human HID input.

### Links and inline tokens

Batch 4 (2026-10-31) quantified delivery for this family. `App::inputReplaceToken(
$id, $tokenId, $text, $label)` (InputReplaceWithToken, base/input.cpp:5780) mints a
token in a retained input; `on_token_click` / `on_token_hover` then reach PHP
through DispatchTokenClick/Hover → Dispatch → DispatchArgs's shimId early-return —
the same queue as clicks. The kit selftest's tokenDeliveryRender gate asserts
`gpui_selftest_fire_registered` delivery (fire=true, delivered ✓); native
mouse hit-testing of the token itself remains human-HID. **The queue type is the
short name** (`token_click` / `token_hover` — eventType strips the `on_` prefix);
`App::on` and fire both use short names. `on_link_click`'s real consumer is the
**TextView markdown link** (materialize.cpp:1728 OnLinkWithContext; the base-kind
Link's click semantics open the href and never consume on_link_click) — delivery
quantified in the linkDelivery gate (fire=true ✓; native realClick reached the
OnTextLink dispatch point, but the down/up frame-boundary href dangling crashes
under the current machine state — see the batch-3 environment record).

The `token` operation is the rendering callback that must return an element or
null; its host wiring landed with batch 4 via two patches:
`cmd/patch-token-render.sh` forks RenderInlineToken on shimId (weak
gpuiRenderShimToken), and `cmd/patch-host-element-cb.sh` forks
BuildComponentElement for the whole element-returning family (MessageScroller
render_item, DataTable render_cell, Select render_row — same seam). Two traps
are pinned in the patch headers: the shim's re-prefix turns `token` into
`on_token` (the upstream op name is the unprefixed `token`, compared verbatim —
special-cased), and the element fork's batch must live in the cx->a frame arena —
the JS path's RenderSnapshot/frameSnapshots must NOT be used for shim entries
(their entry->view is a PhpView entity; Get<ScriptView> has no type check and the
Vec gets a garbage allocator — measured as a malloc abort). Acceptance boundary:
the callback chain is fully proven (tokenDeliveryRender renderCalls=2630,
messageRowRender renderCalls=15); the chip/row pixel placement depends on
upstream BoundsOut multi-frame settlement and is unstable headless under the
current machine state — recorded as an advisory reading.

The current total is 24 declared callback names, with 20 having native delivery
evidence. That count does not include a claim of human-input acceptance.

### Dialog actions and probe isolation

`dispatchAction('ui::Cancel')` can close an open dialog after its focus trap is
active. The independent overlay selftest covers this non-HID action route. The
`overlay --escape` diagnostic exercises native Escape delivery separately:

```bash
php bin/gpui run overlay --escape
```

The trap can be absent immediately after opening and become active after pumping
frames. This diagnostic is not a foreground-independent selftest gate.

Keep invasive input probes separate from idle-render assertions: opening an
animated overlay legitimately changes frame activity. Do not click every hit-box
center indiscriminately, either; resize borders can enter an AppKit tracking loop
that waits for another event. Select a known control and use a bounded watchdog.

## Native input probes and hit testing

A direct synthetic callback test queues an event by callback id. It bypasses
native hit testing and listener attachment. The native-input probes exercise more
of the real path:

```php
$app->realClick(100, 104);
$app->loop(2);
$app->realMouseMove(300, 522);
$app->realKey(9, 'g');
$input = $app->inputProbe();
```

`realClick`, `realMouseMove`, and `realKey` report whether the call added a PHP
event. They synthesize native events inside the process; they do **not** prove
WindowServer/human HID delivery. `inputProbe()['n'] > 0` establishes that paint
registered actual Input controls rather than fallback divs.

Inspect geometry before choosing a target:

```php
$count = $app->hitProbe();
$hit = $app->hitProbe($index);
```

The first result contains `n`; an indexed result adds geometry, id, path,
click-listener presence, and parent information. A control can fail because its
hit rectangle is missing, coordinates are wrong, a listener is absent, or a
native child view intercepts the region outside gpui's hit table.

WKWebView demonstrated the last case: removing its element from a new tree did
not automatically remove the persistent NSView. `WebViewFrameVisibility` now
hides views not rendered in the current frame. A complete regression test must
render the WebView, switch away, and click the previously covered region; bounds
or a successful return to an input page alone are weaker evidence.

That sequence is now a gate of its own: `examples/embedded` renders the WebView,
re-renders without it, and requires a real click in the freed region to reach PHP
(`clickAfterWebView`). `input` implements `--trace` and `--wait-key` for the
human-side checks; the other domains expose only what their `--selftest` asserts,
and an unknown flag exits 2 instead of opening a window. Do not read a missing
flag as a missing capability — check the domain's `--selftest` gate list first.

**Right-click menus (ContextMenu) have no acceptance seam**: `El::contextMenu()`'s
right-click wiring lives in upstream's `ContextMenuState::OnMouseDown` (Bubble phase +
stopPropagation), but the synthetic channel `gpui_selftest_fire` accepts only click/
change/hover (no mouse_down_right), and `realClick` sends left clicks only — so the kit
selftest treats it as a **premise-based gate**: it asserts the fact that "no synthetic
route exists" (fire returns false), and the real right-click behavior needs a human HID
acceptance run (the precedent is Clipboard's copy).

## Retained input and slider values

Inputs, textareas, number inputs, OTP inputs, and sliders with ids create retained
native entities. The id must be in the node's `id` field:
`El::input('name')` and `El::input()->id('name')` are equivalent.

Failure to obtain the required retained state can leave an upstream fallback div
without a catalog error. A rendered label is therefore not proof of an editable
control. Native input bounds, focus, and value changes provide stronger checks.

```php
$app->render(El::input('name')->placeholder('Your name')->onChange());
$app->on('name', 'change', static function () use ($app): void {
    // Read the **state's current text**, not the event payload: a synthetic change
    // (the automation `change` verb, the selftest fire) carries `true`, and trusting
    // the payload paints "Hello, 1!" (measured on a real window, 2026-10-22).
    // gpui-kit-cpp's examples/input.cpp reads InputCStr(&state) in OnChange for the
    // same reason. Sliders have no state getter, so they must trust the payload.
    ExampleState::$v['name'] = $app->text('name');
});
$app->setInput('name', 'prefill');
$text = $app->text('name');
```

The change handler receives the complete event array. `text()` pulls the current
retained value; a programmatic setter is not, by itself, proof of user-input event
delivery. Styled catalog-state construction and its value/event coverage must
also be evaluated separately.

## Timers

```php
$app->on('tick', 'timer', static function (): void {
    ExampleState::$v['ticks']++;
});
$app->setTimer('tick', 1000);
```

`WindowSetInterval` reaches the host through `gpuiEnqueueTimer`. Calling
`setTimer('tick', 0)` cancels that timer. Use stable ids for repeat registration;
timer events flow through the same poll/dispatch loop and can invalidate the view.

## Catalog callbacks

Catalog components store a runtime callback in `ComponentArgument.callback`.
`ComponentCallback::InvokeWith` reaches the `shimId` bypass installed by
`cmd/patch-registered-cb.sh`, then `gpuiEnqueueShimCallback` queues the PHP event.

```php
$app->render(El::dropdownMenu('actions', 'Actions')->item('New', 'file-new'));
$app->on('file-new', 'click', static function (): void {
    ExampleState::$v['action'] = 'new';
});
```

`item($label, $routeId, $type = 'click')` encodes `El::cb($type, $routeId)` as
`['__cb__', type, id]`. The bridge registers a synthetic runtime entry with
`gpui_shim_new_callback`. This is different from the window-local callback ids
used by base behavior operations.

## Asynchronous services

HTTP and directory watches publish completion events rather than returning their
results synchronously from rendering:

```php
$app->on('api', 'http', function () use ($app): void {
    $response = $app->httpResponse('api');
    if ($response !== null && $response['ok']) {
        ExampleState::$v['body'] = $response['body'];
    }
});
$app->httpGet('https://example.com/api', 'api');

$app->on('themes', 'dirChange', function () use ($app): void {
    $app->toast('Theme directory changed', 'Reload the directory');
});
$watch = $app->watchDir('/path/to/themes', 'themes');
```

The URL and directory are illustrative application inputs, not test fixtures.
Cancel the watch with `$app->unwatchDir($watch)` when it is no longer needed.

- HTTP events carry status, not the response body. `httpResponse($id)` returns
  `ok`, `status`, `body`, and `contentType`, consumes that response, and returns
  null on a second read. Read it when the event arrives to avoid retaining results.
- Directory changes are coalesced and do not name an individual file. Rescan the
  directory rather than inferring which file changed.
- Upstream completion callbacks are posted to the main thread. They still require
  the event loop to be pumped. `httpGet()` returning true means queued, not completed.

## Notification click and close

Mount `El::notifications()` in the tree before expecting application notification
cards to be visible. A nonempty route id connects both events:

```php
$app->on('saved', 'click', static function (): void {
    ExampleState::$v['lastNotice'] = 'clicked';
});
$app->on('saved', 'close', static function (): void {
    ExampleState::$v['lastNotice'] = 'closed';
});
$notification = $app->notify('Saved', 'Three files written', 'success',
    'top_right', 5000, 'saved');
```

The returned numeric handle is what `dismissNotification($notification)` accepts;
the string `saved` is the event route id.

Without a route id there are no click/close events. `close` means the item has
left the list, whether from timeout, explicit dismissal, or clearing all notices;
it does not mean the user pressed a close button. Entry and exit animations add
time around the display timeout. A close event is not a measure of readership.

Clicking a card does not remove it. The two route keys remain until close, when
both are reclaimed. Registration therefore scales with live notices rather than
every notification ever created. Native selftests cover close delivery; card-click
acceptance still needs its own interaction evidence.

## Events outside the window

```php
$app->tray(['Show window', 'Quit'], 'tray-main', 'Example');
$app->on('tray-main', 'tray', static function (array $event): void {
    ExampleState::$v['trayChoice'] = $event['value'];
});

$app->onHotkey('focus', 105, ['cmd', 'shift']);
$app->on('focus', 'hotkey', static function (): void {
    ExampleState::$v['hotkeySeen'] = true;
});
```

A tray menu item supplies its label as `value`. With a menu attached, NSMenu owns
the click; use an empty label array to receive clicks on the icon itself, with a
null value. Hotkey modifier names are validated; accepted names are `cmd`,
`shift`, `option`, and `control`.

`App::fireHotkeyForTest()` tests the Carbon dispatch route with a synthesized
event. It does not prove global capture while another application has focus.
Tray existence counts likewise do not prove a visible, clickable menu-bar icon.

## Menu actions

Element menus and macOS application menus deliver the same `action` event.
Application menu bars use `name`; actionable rows use `action`:

```php
$app->setAppMenus([
    ['name' => 'File', 'items' => [
        ['label' => 'New', 'action' => 'file.new'],
        ['separator' => true],
        ['label' => 'About this application'],
    ]],
]);
$app->onAny('action', static function (array $event): void {
    ExampleState::$v['action'] = $event['value'];
});
```

The native path is NSMenuItem target/action, `AppMenuChosen`, action lookup,
window focus-chain dispatch, and finally the app-level `AppOnAction` handler.
A root-element listener is not a substitute for that app-level fallback.

`menuRows()` reads back actionable row ids. Using `label` instead of bar `name`,
or `id` instead of row `action`, can install a menu that looks valid but has no
action. Check row contents and action dispatch separately. The process-wide action
table is bounded; overflow is reported through `App::lastError()`.

Window-local `realClick()` cannot click the separate macOS menu-bar window. A
row-table check plus `dispatchAction()` proves those two stages, not the actual
OS menu click. Preserve that distinction in test reports.

Batch 2 (2026-10-30) adds a third source: the catalog NativeMenu rows. Inside
`El::nativeMenuTrigger(...)` hang `El::nativeMenuItem('Copy path', 'kit-nm-copy')`
rows; clicking the trigger pops an OS native menu (a keyed event effect), and row
selection dispatches a ShellAction through the same reverse-lookup seam
(`gpuiAppActionHandler`, cpp-src/gpui_shim.cpp:293-302) — the event id is the action
name. Unlike sources ①/②, the trigger lives **inside the window content**: really
clicking it and choosing a row are two human interactions (NSMenu row selection is a
modal session synthetic clicks cannot reach) — the kit selftest only pins the factory
shape and the `$app->on($action, 'action', …)` wiring; delivery evidence stays with
human acceptance.

## Foreground state and human input

`php bin/gpui run` **executes the bare binary by default** (`--bundle` opts into the `.app`);
`selftest` still goes through the bundle, because what it asserts is a product property —
that the artifact *has* bundle identity (`--expect-bundle`).
**The bare window needs an actual human click:** it composites and its `active/key` are real, but
handing it the frontmost from outside is refused (`System Events`' `set frontmost` → error -10006),
so `keystroke` injection lands on whichever app owns the session frontmost. A physical click is
hit-tested by WindowServer, bypassing that LaunchServices path, and only then is the frontmost ours.
The reason *for that default* is one same-second
measurement on identical bytes: `bare onScreen=true / bundle onScreen=false`. The older claim
that "a bare binary can't reach `isActive`" stopped holding once the foreground-retry patch
landed — bare now reports `active=true key=true` too.
Inspect
`App::windowState()` for `active`, `key`, `visible`, `main`, `bundled`,
`nsappDeliveries`, `appkitDeliveries` (keyDown/leftMouseDown that AppKit dispatched
into this process), `onScreen` (is this window in WindowServer's on-screen ledger at
all?) and its self-proof arm `onScreenPeers` (how many windows that ledger lists).

**Bundle identity only buys the self-report half.** Measured: an app launched from a
`.app` can report `active/key/visible/main` all true while `onScreen=false` — the window
exists in the window store with a real surface yet is never composited, so hardware
keyboard and mouse deliver nothing. Pixel gates cannot see this either, because `--shot`
renders in-process through `cacheDisplayInRect`. On-screen qualification needs both
conditions: `Contents/MacOS/<exe>` must be a **regular file** (a symlink makes `codesign`
refuse the bundle) **and** the bundle signature must bind `Info.plist`
(`codesign -dvv` shows `Info.plist entries=N`). **Both are necessary, not sufficient**:
after satisfying them `onScreen` still fluctuates on this machine — the same artifact read
true twice, then false five times in a row 20 minutes later — so read `onScreen` before
blaming any layer below it. Conversely a bare binary measured
`onScreen=true` on this machine — the two properties are independent, so never substitute
one for the other. Gate 14 (`composer check:bundle`) keeps that checked in CI.

Keyboard delivery needs a suitable key window and responder. Initial clicks may
be used for activation; `acceptsFirstMouse` is not proof that all pointer and
hover paths ignore activation. Foreground ownership competes with the terminal,
IDE, and other apps. Automation can sometimes acquire it, but must not assume it.

`nsappDeliveries` counts synthesized events that traversed the application queue
and `NSApp sendEvent:`. Zero can indicate direct window delivery instead. Neither
value proves human HID input. Keep physical typing, inactive-window hover, and
pressed-button drags as explicit manual acceptance items.

## First-frame hit tables and key routing

`App::view()` invokes the view callback immediately and materializes the initial
tree. `run()` uses the same route. This prepares the initial structure before the
loop's first painted frame; custom loops must account for that eager callback.

The initial-click regression originally produced no events after one frame but
did after two. A valid test must click a known target on the first frame, not set
navigation state directly. Broad navigation probes are still being migrated to
independent hosts and must not be silently discarded.

`cmd/patch-key-view.sh` redirects keys at the ScriptView layer, before QuickJS.
`KeyEvent::vk` and `ch` are mutually exclusive: physical key down/up supplies
`KeyName(vk)`, while typed character events supply UTF-8 from the code point.
The `input` selftest checks actual native typing and key payloads.

Hit width also matters. A button in `itemsStart()` may occupy only its text width;
clicking the unused half of a row is not a failed listener. Give navigation rows
an intended width and check their actual rectangles.

`cmd/patch-hover-always.sh` changes the macOS tracking area from key-window-only
to always-active tracking. Its inactive-window effect requires an external pointer
check; an in-process synthetic move does not validate that OS policy.

## Dirty-driven rendering

`App::loop()` pumps and dispatches events each iteration, optionally makes a
bounded foreground attempt, then decides whether to render.

| Trigger | Reason |
|---|---|
| First frame of each `loop()` call | Complete a usable painted frame after materialization |
| Events were dispatched | Handlers may have changed state |
| Active/key/visible/main changed | Window-state-dependent UI needs an update |
| `invalidate()` was called | State changed without a queued event |

Otherwise the loop skips the view callback, serialization, and materialization.

```php
$app->keepTryingForeground(12);
$attempts = $app->foregroundTries();
$renders = $app->renderCount();
$frames = $app->frameCount();
$app->invalidate();
```

Foreground retries belong in the loop, not only in the view callback: a skipped
render must not prevent the next activation attempt. They are disabled by default.
`frameCount()` measures loop iterations; it is not a render counter.

## Lifetime and idempotency

- Stable `(elementId, type)` callback registrations are reused across frames.
- A route key selects one id-specific handler; wildcard handlers are per type.
- Retained state keys must stay stable if the component is meant to retain state.
- Catalog failures are available through `registeredError()`, not only debug trees.
- Dock layout-change events carry null; read `dockLayout()` for the complete JSON
  instead of trying to fit it into the fixed-size event string.

See [Testing](./testing) for checks that distinguish declaration, route, pixel,
and human-input evidence.
