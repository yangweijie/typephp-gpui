# Testing and Gates

Use the gates below according to the layer changed. Changes to the stub, bridge,
shim, or upstream patches require native checks: PHP tests cannot detect every
AOT return-type mismatch, lifetime bug, or missing native listener.

A green declaration test, dispatch route, screenshot, and human interaction are
four different claims. Report which one a check actually establishes.

## Gate inventory

| Gate | Command | Scope | Native dependency |
|---|---|---|---|
| Pest | `composer test` | Element serialization, App routing, FakeBridge, contracts, and example coverage | No |
| PHPStan | `composer analyse` | Level 5 analysis of `src/` | No |
| C++ shim tests | `composer test:cpp` | Kind lookup, arenas, argument schemas, native service predicates, Dock layout checks | Built `gpui.o` |
| Native selftest | `php bin/gpui selftest hello` | Compiled PHP/bridge/shim round trips | Native build and window session |
| Pixel comparison | `php bin/gpui shotdiff check hello` | Per-tree screenshots against baselines | Built binary and usable display |
| Doctor | `php bin/gpui doctor` | Toolchain, C ABI, and required patch markers | Partial |
| C++17 parsing | `bash cmd/check-cxx17.sh` | Header parsing under C++17/C++20 and both outcomes of the EmitsEvent trait | Upstream headers |
| Pure C ABI | `bash cmd/check-c-abi.sh` | C11 compilation and forbidden C++ syntax/header checks | No gpui object |
| Tracked build inputs | `php cmd/check-build-inputs.php` | Patch scripts, manifest membership, example source lists, plus a repo-wide invariant that no indexed path holds an empty blob while the worktree file has content. That fourth check was added after measuring 13 paths registered via `git add -N` (intent-to-add): the path shows up in `git ls-files`, so the first three checks stay green while the commit would write 13 zero-byte files (four patch scripts, the whole English guide set, a pixel baseline). "Tracked" is decided by blob sha, not by name. The gate now runs six checks in total: those three plus the empty-blob invariant, **every `cmd/*.(php\|sh)` that CI names is tracked** (a new workflow step forgotten from the index = the runner can't open the file), and **every local path/example name that `composer.json` scripts reference exists** (after an example is removed, this is the stale-entry class that survives longest). | No |
| Three-layer contracts | `composer test` | Stub/FakeBridge/C++ names and arities; App calls; Element kinds and dedicated bridge paths | No |
| Upstream vocabulary | `composer parity:upstream` | Kinds, methods, callbacks, slots, catalog names, state kinds, and style vocabulary | Vendored sources only. Batch 5 added a self-verifying patch allowlist: descriptor export names the sandbox patches add (ProgressCircle/PopupMenu/ContextMenu) pass only if each name really appears in some `cmd/patch-*.sh` — catching both "export added but patch forgotten" and "exempted name with no patch" |
| Example coverage | `composer test` | Public API/enum usage and argument counts across examples, plus four anti-drift legs in the same file: every example ships a `project.macos.yml` whose listed sources exist, every `examples/_shared/features/*.php` is claimed by at least one example, every tree of every example builds headless under FakeBridge with no orphan handler (the gate body is `cmd/headless-trees.php`), and — added 2026-10-22 — in any example that rejects unknown flags, the set of flags it actually reads must equal the set its rejection message documents, in both directions (`--automation` used to be read only inside `runInteractive()`, i.e. behind that very guard, so using it alone was rejected by its own CLI; it was also missing from the message) | No |
| Doc anchor ids | `php cmd/check-doc-anchors.php` (runs in the CI docs job after `docs:build`) | Covers what `docs:build` cannot see: the build checks that pages exist but never resolves `#fragment` (it shipped a real dead anchor to a renamed heading). This gate resolves every in-site anchor against the `id="…"` values actually rendered in the built output — safer than matching source headings, since VitePress' id generation transliterates CJK and de-duplicates. Currently 60 in-site links / 11 with anchors / 0 problems; exits 2 when zero links were scanned, so "not looked at" can't pass as "checked and clean". | No |
| Bundle on-screen qualification | `php cmd/check-bundle-signature.php` (`composer check:bundle`; runs in the CI native job right after `Native selftest`) | The standing sentinel for #56. It asserts two **artifact properties** (not OS-contested state, so they can be hard-asserted): ① `Contents/MacOS/<CFBundleExecutable>` must be a **regular file** — a symlink makes `codesign` refuse outright ("the main executable or Info.plist must be a regular file"); ② the bundle signature must bind `Info.plist` (`codesign -dvv` shows `Info.plist entries=N`, and `codesign --verify --strict` passes). When either breaks, the window **never enters WindowServer's on-screen ledger** while `windowState()`'s active/key/visible/main all read true and every pixel gate stays green (`--shot` renders in-process via `cacheDisplayInRect`) — only a human pressing keys hits it, which is exactly why it belongs in CI. Measured across four combinations: symlink / regular file unsigned / hardlink unsigned ⇒ `onScreen=false`; regular file + plist-bound signature ⇒ read `onScreen=true` at the time, but the same artifact measured `false` five consecutive times 20 minutes later — these are **necessary, not sufficient** conditions, so this gate proves the bundle is signable and correctly shaped, not that its window will be on screen. Exits 2 when no `.app` was found ("artifacts not built" is not a pass) and on unknown flags | No |
| Frame/node budget ratchet | `composer bench:tree` (= `php cmd/bench-tree.php --check cmd/bench-tree-budgets.txt`; runs in the CI php job right after Pest) | Measures only the PHP half (view builder plus `toArray()`): five sizes, five runs each, and the **min** is compared against the budget file — one row over budget is a red, and `us/node` has its own row. Min rather than median came out of a same-machine A/B: the median drifts with machine state (a series medians at 48ms contained a 94ms max), while min differs by only 10~20% between fast and slow states. Memory is reported but not gated (a 20k-node tree peaks at 168MB — that is a framework property, not a regression signal). Red arm measured: injecting a real O(n²) makes the large tiers blow through (60001 tier: 302ms, 5.04 µs/node) with rc=1 while the small tiers stay OK. Explicit escape valve for a busy machine: `GPUI_SKIP_BENCH_TREE=1` | No |
| Hotkey pump leaves mouse events (#99) | `composer check:hotkey-pump` (= `bash cmd/check-hotkey-pump.sh`; runs in the CI native job right after the bundle gate) | Drives the **production `gpui_mac_pump()`** (`cpp-src/gpui_mac.mm` is compiled and linked in), not a copy of it — a copy would only prove the design works and would stay green if production were reverted. Three hands-free arms: ① register a real hotkey (the pump early-returns while `g_hotkeys` is empty, so without one that path is never exercised), post a pair with the hotkey at the queue head and the mouse right behind, then run the production pump ⇒ the mouse must still be there, the hotkey must be taken, **and the sink must really receive one hotkey event** ("the event is gone" ≠ "the event was dispatched"); ② unregister the hotkey and post another pair — the pump must touch neither (the `empty()` early-return path); ③ the negative control runs the pre-fix "any event" drain, which **must** eat the mouse, otherwise the self-proof has no discriminating power and the gate exits 2 (undetermined). **It ships its own red arm**: flip the production peek's `false` to `true` in a temporary copy (the production file is left untouched) and the gate must go red. Measured green arm `old drain ate mouse=1, pump left mouse=1, left hotkey=0, sink hits=1, idle mouse=1, idle hotkey=1`; red arm `pump left mouse=0, sink hits=0` ⇒ `HOTKEY-PUMP GATE OK`. It measures the **queue layer** (whether the down was stolen), not pixels and not human delivery; missing prerequisites, a failed hotkey registration, a non-unique red-arm anchor and unknown flags all exit 2 | Built `gpui.o` + window session |
| Clean exit on the interactive quit path (#101) | `composer check:exit-clean` (= `bash cmd/check-exit-clean.sh`; runs in the CI native job right after the hotkey-pump gate) | An interactive quit / window close takes the "return from main → exit → `__cxa_finalize_ranges` → static destructors" path, and the bridge registries hold PHP callables (`php::Variant`): the Zend engine shuts down first, the destructors run afterwards ⇒ `~Variant → zval_ptr_dtor` touches freed memory, SIGSEGV. **`--selftest` goes through PHP `exit()`, which does not run those destructors**, so a dozen green selftests cannot see this path at all — a human closing the window hits it. Hands-free repro: put a single `quit` in an `--automation <dir>`, and **create the directory fresh every round** (a command file is unlinked once consumed, so reusing a directory waits forever for the next number — that is a harness error, not a red gate). The gate runs `build --no-native` before measuring (a failed build leaves the previous binary behind) and asserts rc=0 **and** that the receipt contains `commands=1` and `#1 quit -> bye`, so "never ran" cannot pass as "checked and clean"; a hang past the 40s watchdog, an unbuildable artifact and unknown flags all exit 2 (undetermined). Red side measured before the fix: hello and input both 139; after the fix both 0 | Built binary + window session |

Combined PHP and C++ checks:

```bash
composer test:all
```

This runs Pest, PHPStan, upstream parity, and shim tests. It does **not** include
all native example selftests, screenshots, or the documentation build.

Run all discovered examples with:

```bash
composer examples:all
bash cmd/all-examples.sh first-native
```

The second form rebuilds shared native artifacts with the first example and
reuses them for the rest. Do not run a full sandbox sync/build concurrently with
tests that need that same `gpui.o`. The example list comes from
`examples/*/project.macos.yml`, not a second fixed list.

When a pixel row fails in the full sweep, the sweep **classifies it on the spot**:
it prints `现场=` (up to three offending trees, e.g. `✗ tree 18：gdfrac=…`), re-runs that
example once, and labels the row `复跑绿＝抖动（仍计红，别当通过）` or
`复跑仍红＝需单跑复核（可能是上下文相关抖动）`. The immediate retry inherits the same context,
so it **cannot** establish a regression: on 2026-10-17 13:45 the `overlay` row was red twice inside
the sweep and green 3/3 when run standalone.
The retry only classifies — a flake still counts as FAIL. The durable evidence is that
printed line; log copies under `/tmp` are not guaranteed to survive.

Composer shell scripts must use `bash cmd/script.sh`, not `@bash`: Composer can
interpret the latter as a missing script reference without running the intended
test. Verify that output contains the shim test's own section reports.

## Pest and contract tests

Pest uses function-style `test()` / `expect()` and FakeBridge, without opening
gpui windows. Element, App, and System tests cover declarations, serialized
children and operations, enum validation, handler arity, and return shapes.

Contract tests compare `php-src/gpui.stub.php`, FakeBridge, and
`cpp-src/gpui_bridge.cc`. Every `gpui_*` call in App must have a stub declaration.
The Element kind check parses only the actual `kKindNames` table body, not nearby
source that happens to contain a similar string.

Dedicated bridge paths are explicit exceptions: `webview`, `inspector`,
`notifications`, and `dock_area`. The Dock exception also checks for its bridge
branch; exceptions are not permission for arbitrary unknown kinds.

Example coverage reflects public Element/App members and enum values, searches
all example PHP sources for calls, and checks argument counts. It also asserts that every
example has a `project.macos.yml` whose listed sources exist, that every shared feature
source is compiled by at least one example, and — one headless process per example — that
each tree builds and no handler is orphaned (`cmd/headless-trees.php`).
A passing reflection check alone does not show that an AOT source list contains
the function or that a native call works.

## C++ shim tests

`tests/cpp/shim_test.cpp` includes the shim implementation so it can exercise
internal helpers directly. `cmd/test-shim.sh` compiles the test as Objective-C++,
then links the amalgam object without linking the shim archive again. The tests
are headless but still require the supported native toolchain and built object.

The arena regression changes the original source buffer after `AStr` copies it,
then checks that the arena-owned string is unchanged. This detects a lifetime
bug rather than merely asserting matching bytes before the source is destroyed.

The kind drift alarm currently sees 50 mapped names and expects 14 unmapped enum
values **after excluding Registered and ChildView** (batch 3 registered
accordion_header/trigger/panel for `El::accordionItem`, lowering the count from 17).
The parity report's raw difference is 16 because it does not make those two
exclusions. DockArea remains
outside the ordinary kind table despite having a dedicated host ABI. Neither
number is the count of unavailable PHP features.

Changing the upstream enum or removing a mapped name should trip the drift alarm.
Its purpose is to force an explicit exposure decision, not require every special
kind to be constructible through an ordinary string.

## Native selftests

The `hello` selftest covers these 21 service and ABI groups:

1. **Input values**: set a value through the bridge and read retained state back.
2. **Click route**: a direct callback test traverses enqueue, window lookup, poll,
   and PHP dispatch; it does not validate listener attachment.
3. **Timer**: native interval tick reaches PHP.
4. **Dialog**: opening and closing updates the native count.
5. **Catalog callback**: DropdownMenu item invokes the runtime shim callback path.
6. **Catalog error**: invalid construction produces a readable registered error.
7. **Application menu**: installation count, actionable row ids, and action
   dispatch are checked separately. Writing `id` instead of `action` must fail the
   row check even when the OS menu can still be installed.
8. **Clipboard text**: write and read NSPasteboard, not a PHP-side cache.
9. **URL rejection**: reject a forbidden scheme without launching another app.
   The accepted predicate is covered directly by C++ tests.
10. **FPS HUD lifetime**: show, inspect visibility, repeat show, hide, and repeat
    hide. This does not guarantee stable HUD pixels.
11. **Sheet lifetime**: open, inspect, close, and verify absence.
12. **WebView**: create the actual OS child view, inspect its own applied bounds
    and URL, and invoke back/forward. The historical rectangle was 400×300; a
    count without nonzero native geometry would be weaker evidence.
13. **Inflate**: decode externally generated zlib and gzip fixtures, and reject
    invalid data. Use the same fixtures in Pest, C++, and the AOT executable.
14. **Directory watch route**: `DirWatchAdd/Signal` exercises the upstream testing
    seam, main-thread posting, and PHP delivery. It does not generate real FSEvents.
15. **HTTP failure route**: use a closed loopback endpoint, expect an error
    response, and verify that a second response read returns null. Do not replace
    it with an external network dependency or claim it proves successful HTTP.
16. **Clipboard flags**: a known text-only item must report no image or file paths.
17. **System state**: direct memory/disk/core readings plus an initial zero CPU
    sample, deliberate CPU work, and a later positive sample; inspect process data.
18. **Application notifications**: push, dismiss, pump the exit animation, and
    observe count reclamation plus `close`. Card clicks require separate evidence.
19. **macOS integration route**: tray registration/removal and a synthesized Carbon
    hotkey event. Global capture and a visible icon remain manual checks.
20. **Capability filesystem**: granted writes read correctly through PHP and vice
    versa, including binary bytes; ungranted paths are rejected. Cleanup is confined
    to the test-created directory.
21. **Process and storage**: an allowlisted executable succeeds, an unlisted one
    is rejected, and storage rejects calls before its grant then preserves data.

Use the independent domain selftests for their focused contracts:

```bash
php bin/gpui selftest input --no-native
php bin/gpui selftest choices --no-native
php bin/gpui selftest table --no-native
php bin/gpui selftest layout --no-native
php bin/gpui selftest catalog --no-native
```

Only use `--no-native` when shared native artifacts match current native sources.
The input example covers real native typing/focus and styled-state reuse. Choices
covers open-change/confirm/dismiss. Table covers semantic indices, synchronous
virtual-list ranges, scrolling, item payloads, and row listeners. Layout covers
retained Dock state, panels, JSON round trips, rejected updates, and reclamation.
Catalog covers the additional state-backed component family.

A negative control earns a regression claim only for what it removes. Removing
the Dock event wiring and observing failures is not the same experiment as
removing the complete upstream Dock patch.

## Clipboard and modal-dialog diagnostics

```bash
php bin/gpui run hello --clip
```

Prepare known text, encoded image, or Finder file URLs first. A report includes
`text=/image=/paths=` with -1 for absent text/image representations. Real AppKit
fixtures covered text, PNG, file URLs, and mixed text+PNG; see
[System Capabilities](./system-capabilities#clipboard-channels).

Do not use an AppleScript alias list as a substitute for `public.file-url` data.
A zero path count then reflects a different clipboard format, not a broken file
channel.

`promptForPath()` is deliberately absent from unattended selftests because its
native modal loop waits for a person. Invoke it from an interactive button's event
handler and test both selection and cancellation.

## `--automation <dir>`: driving the real window from outside

`--selftest` is the binary driving itself, which cannot answer "what did an external
writer produce in a *real* window". `--automation <dir>` covers that: start
`gpui run hello --automation /tmp/auto`, drop `command-<n>.txt` files into the directory,
and the app consumes one per frame and writes the receipt to `snapshot.txt`. No ports, no
accessibility permission, drivable from a shell script or CI; the session survives a
process restart.

```
mkdir -p /tmp/auto
printf 'widget-click inc\n'  > /tmp/auto/command-1.txt
printf 'key a a\n'           > /tmp/auto/command-2.txt
printf 'screenshot after\n'  > /tmp/auto/command-3.txt
sleep 2 && cat /tmp/auto/snapshot.txt
```

| Verb | Effect | Notes |
|---|---|---|
| `widget-click <id>` | synthetic click by element id | element seam first, then the registered seam |
| `key <name> [chars]` | one key through the **real keyboard path** | `a` / `tab` / `enter` / `esc` / `left`… or a raw keycode; this path emits real `change` events |
| `input <id> <text>` | set the retained widget value directly | **no `change` event** — state-driven views do not update from it |
| `change <id> [text]` | optionally set the value, then synthesize a `change` event | closes the gap left by #93 and by session-less process trees: in agent/CI environments this is the only verb that can verify the change path |
| `value <id>` | read the widget's current value into the receipt | use this to assert the write landed |
| `focus <id>` | focus the widget | the real side returns `-1001` and `focused` stays `-`; focus is still an open gap (#62/#56) — diagnostic only |
| `clickat <x> <y>` | one synthetic real click at coordinates (same seam as the selftest's `realClick`) | widgets the id seam cannot reach (slot buttons, the catalog date trigger) can only be clicked by rect; this is the discriminating arm for #103. input's `--automation <dir> [height]` takes an optional third argument to raise the window so below-the-fold targets become reachable |
| `hits` | dump this frame's whole hit table to `<dir>/hits.txt` | rows are `i id x y w h click down drag` (tab-separated, gpui view coordinates); comparing against a same-frame `screenshot` separates "product hit-testing is wrong" from "the synthetic seam's coordinate conversion is wrong" |
| `screenshot <label>` | force one frame, then emit a PNG | the label is sanitized, so artifacts cannot escape the directory |
| `quit` | leave the main loop and write the final receipt | a prerequisite for CI runs, which otherwise have to kill the process |

`snapshot.txt` is rewritten once per consumed command (not per frame): frames, renders,
window state (`onScreen`/`key`/`visible`…), `focused`, `lastError`, the number of commands
consumed, and one result line per command. Commands are consumed in order and a missing
number is waited for — when `commands=` stops advancing, the driver knows which number
was lost.

**Who launches the process decides whether OS delivery is zero** (#96, same machine, same
binary): an agent shell descends from `launchd ← daemon ← .app`, so it is **not in your login
GUI session** — the window can be frontmost and composited while OS input delivery stays at
zero (`appkitDeliveries=0`, `sessionId=0`). The "human keystroke" layer therefore has to be
verified by the user launching from Terminal.app (`input --wait-key 8` gives a one-line
answer); agent and CI can only reach the in-process dispatch chain. That is a boundary, not
a defect.

Measured 2026-10-20 with `gpui run hello --automation`: two clicks put `Clicked: 2 times`
on screen; `input` set the value without updating the greeting (no `change` event), while
three `key a a` commands changed it to `Hello, abc!` — a real event, real state, real
re-render. The headless twin lives in `tests/AutomationTest.php` (9 tests, covering
out-of-order waiting, label escape, and quit shutdown).

`input` opens the same door and **takes the flag on its own**: `php bin/gpui run input
--automation /tmp/auto` no longer needs `--trace`. That was a real defect fixed 2026-10-22 —
the flag was read only inside `runInteractive()`, which sits behind the "reject unknown flags"
guard, so using it alone was rejected with rc=2. The gate that keeps a flag list and what the
code reads from agreeing is the fourth leg of the Example coverage row above.

## Pixel comparison

`cmd/shotdiff.php` invokes `--shot`, captures a real window NSView into PNG, and
compares against `tests/baseline/<example>/tree<N>.png`. It uses Imagick RMSE or a
GD fallback with a threshold of 0.001.

```bash
php bin/gpui shotdiff check hello
php bin/gpui shotdiff record hello
```

`record` changes baselines. Use it only after inspecting an intentional visual
change, not as a way to make an unexplained failure green.

Shotdiff does not build. Its freshness check rejects a binary older than any
source listed in the example's yml, with exit code 2. Native changes also require
an appropriate shared rebuild; source freshness is not a universal dependency
tracker. Record mode must not legitimize an old binary's screenshots.

The runner also detects when different trees produce the same frame fingerprint.
A zero exit code from `--shot` alone proves only that a file was written, not that
the requested tree or visible content was captured.

### Snapshot stability

Before capture, the shim blocks incoming physical mouse events, drains queued
events, synchronously parks the window pointer outside the content at (-40,-40),
and pumps four frames. This prevents incidental hover or Inspector picking state
from depending on the physical pointer position.

Window selection is bound to the window that was actually passed in (#90). The
capture used to take `[NSApp keyWindow]` and, when that was nil, the first entry
of `[NSApp windows]` — the requested window played no part in the choice, so
foreground contention could photograph a different window entirely (hello trees
29/31 showed whole-frame differences, `gdfrac=1.00000`).
`cmd/patch-window-handle.sh` adds an upstream `WindowNativeHandle(Window*)` that
returns that window's own native handle, and the capture fails rather than falling
back to another window. The gate runs inside `hello --selftest` as `snaparm`: a
second window of a different size is opened in the same process, each handle is
captured, and the two PNG widths must differ. Measured on both sides — with the
old selection `A420px/B420px` and rc=1 (both shots hit the same window), after the
fix `A400px/B420px` and rc=0.

**Focus/HID-dependent gates must be written as preconditions, not bare assertions.**
Readings such as `focusedId()` and `focusTrap()` only mean something when the window is
actually key/main. On the hosted runner, two runs of the same commit both reported
`active=false / key=false / main=false` with `focus=0`, so a gate that asserts
"focus equals that widget" is guaranteed red there — and that red means *the environment
withheld focus* (a human-HID check), not a regression. Write it the way the bundle gates are
written: assert only when the precondition holds, and when it doesn't, print
`applicable=false` / `focusHeld=false` so the run records "unverified" instead of failing.
Halves that don't depend on focus stay hard-asserted (`focusElement()` returning a non-zero
handle, `trap !== 0`, the `elementValue()` read-back chain). **Then re-verify, on a machine
where the precondition *is* met, that the assertion still fully executes** — otherwise
"unverified" silently degrades into "never executed". The three gates that carried this
defect were checked that way: `focusFollowsClick … applicable=true`,
`focusTrap … trap=993756471 focusedId=993756471 focusHeld=true`,
`openChangeDelivered … 持有焦点=true`, each rc=0.

**`active/key/main` is only the app's own claim, not proof that physical input can arrive.**
Measured on this machine (2026-10-15): `--wait-key` reported `active=true key=true visible=true
main=true` for the whole run while the window was absent from WindowServer's **on-screen ledger**
(`CGWindowListCopyWindowInfo(OnScreenOnly)` returned zero records; the entry in the full list
lacked `kCGWindowIsOnscreen`), whereas a 60-line plain AppKit program in the same session did map
on-screen. Hardware keyboard and mouse are dispatched strictly from that ledger, so a human
press delivered nothing. `windowState()` therefore also carries `onScreen` (our window number
looked up in that ledger) plus its self-proof arm `onScreenPeers` (how many windows the same
ledger does list; non-zero is what makes `onScreen=false` a verdict rather than a broken query —
measured `false` / `37`). Ask `onScreen`, not slots 0..3, when a human cannot type.
**Same fact, pixel side:** `--shot` captures through `[view cacheDisplayInRect:]`, which renders
in-process, so a correct PNG comes out even when the window is not on screen at all — pixel
evidence never proves "the user can see it", and it does not vouch for the human-HID check.

**Whether a window is composited at all has a re-runnable verdict: `bash cmd/onscreen-probe.sh [example]`.**
It runs six processes **in the same second** — three programs (a plain AppKit control, gpui plus our shim
with zero PHP, and our full artifact), each launched two ways (exec'd straight from a directory, exec'd
from inside a `.app`) — and reads only WindowServer's on-screen ledger: `0` all six cells on screen;
`1` only the bundle column missing (= #56, reproducible, and the shippable route is the bare binary);
`2` a bare column cell missing, or even the plain AppKit bundle cell (fix the host/session before reading
code); `3` prerequisites unmet. Why a matrix rather than a trio: this state flips in time blocks and
cross-time single comparisons have fooled me three times — but same-second is not sufficient either.
The previous version paired "zero-PHP, run bare" against "full artifact, run from a bundle", which varies
two things at once (PHP present × where it launches from); the "defect is in the nano/PHP runtime"
conclusion it produced was retracted on 2026-10-15 23:05. Four same-second rounds here read: plain AppKit
on screen in both columns; gpui-without-PHP on screen bare in 4/4 but missing in 2/4 bundles; the full
artifact on screen bare in 4/4 and missing in 4/4 bundles (the in-process `onScreen` self-report matched
the ledger cell for cell). It is deliberately **not in CI** — a hosted runner has no session that
composites windows, so it would always report `2`.
**Physical mouse clicks: a known boundary (measured 2026-10-17).** On this host, with a bare window
in a remote session, a physical `leftMouseDown` reaches the process (the local monitor counts it)
but **never reaches the gpui view** — a probe inside `-[GpuiView mouseDown:]` never prints.
Physical keys are unaffected (they go to the key window's first responder). Human acceptance that
depends on mouse hit-testing (dragging a splitter) therefore cannot be recorded on this link; use
the `App::realClick()/realDrag()` instrument, or switch to a display link without the remote session.

That fix stabilized pointer-dependent captures; it is not proof that every source
of timing variation is gone. **hello tree 27 was a separate, now-closed issue**:
its FPS HUD grows from 132 to 150 points once the sampler has a second sample, and
`runShot` used to pump only three frames after `showFpsHud()`, so which state got
captured depended on wall-clock time (five runs produced five different images,
0.01298 away from the baseline). Pumping to the settled state (120 frames) fixed it:
five runs now share one size and differ pairwise by 0.00013-0.00023, which is only
the live digits inside the HUD and sits under the 0.001 tolerance. A stable pointer
does not imply a stable resource sample.

A sibling risk is still open (**#90**): hello trees 29 and 31 can alternately report
`gdfrac=1.00000` — a whole-frame difference, not a small residual — while both only
`toggleInspector()` and pump three frames before capturing. Do not resolve a 1.0-class
difference by re-recording or by widening the tolerance; keep the offending frame and
decide first whether the panel never mounted or the capture grabbed a different surface.

For any unexpected difference:

1. Keep the failed image and inspect it, not only its score.
2. Verify that the binary includes the changed sources.
3. Repeat under controlled content and pointer conditions.
4. Corroborate with logs, crash reports, layout values, or a targeted negative
   control that measures the same failure.
5. Explain the cause before accepting new reference pixels.

A later green retry and no crash report can postpone attribution; they do not
prove “rendering jitter”. An embedded WebView failure once came from a remote
page loading only some of the time. Offline test mode now uses local HTML with
the same id and geometry. Interactive mode may load the real URL; visual tests
must not depend on external network timing.

### Tree-specific boundaries

- **hello tree 24** has a MenuBar builder but is outside the fixed baseline list.
  Do not infer a current root-app failure from that exclusion: host MenuBar
  integration has been fixed, and the existing exclusion comments are historical.
- **tree 27** is the FPS HUD timing issue above.
- **tree 29** tests Inspector picking/highlight mode; **tree 31** contains the
  actual Inspector panel. These are different features.
- **tree 30** still exists in hello. Local WebView content can appear in a snapshot;
  the claim that cross-process surfaces are always blank is false. Embedded also
  has its own independent tree.
- **tree 32** requires wall-clock time for notification entry. Its two persistent
  cards occupy different corners so neither is hidden by stack collapse. The
  existing test pumps 80 frames; a few fast frames can capture transparency instead.

Hello's fixed baseline list contains 18 trees. Other examples report their own
`--sections` list. Derive the total from those sources rather than copying an old
repository-wide tree count.

## CI configuration versus CI evidence

The current workflow defines:

| Job | Configuration |
|---|---|
| `php` | Ubuntu 24.04, PHP 8.5, lint, Pest, build-input checks, upstream parity, PHPStan |
| `docs` | Ubuntu 24.04, Node 24, VitePress build, nonempty HTML output, project-base assertion |
| `native` | macOS 15, upstream build, parse/C-ABI/shim checks, native selftests for hello and every domain example |
| `deploy` | GitHub Pages deployment after docs, for pushes to main |

This table describes configuration, not a successful run of the current working
tree. Historical green runs on older commits do not validate uncommitted changes.
Confirm the exact revision and logs before reporting CI acceptance.

The native workflow currently starts raw binaries. Local CLI selftests start
`.app` bundles. Bundle identity and NSApplication routing are therefore separate
checks; a raw-binary CI pass cannot establish them.

Pixel comparisons remain local or require a separately verified GUI runner.
Self-hosted resources and current-revision execution are open acceptance items;
configuration alone does not show that such a runner exists. Do not register a
runner or change shared infrastructure merely to turn this document green.

## Native event path versus human input

`realClick()`, `realMouseMove()`, and `realKey()` synthesize AppKit events in the
process. Unlike direct callback firing, they exercise hit testing, listener
attachment, and focus movement. They still do not represent a physical keyboard
or mouse action from WindowServer.

Use `inputProbe()` to identify actual input rectangles and `hitProbe()` for known
click targets. Avoid coordinate scans that may hit resize borders and enter an
unbounded AppKit tracking loop. Monitor a bounded test with an external watchdog.

`realDrag()` splits delivery and event counts into `{pressed, moved, released}`.
Successful down/up delivery does not set the physical pressed-button state that
macOS uses for resizable dragging. The layout probe's zero-move result remains a
reading, not a passing resize test.

A WebView visibility regression requires a test that shows the view, switches
away, and clicks the covered region. A round trip to another page can remain green
with that bug present. That sequence is now a gate of its own: `examples/embedded`
renders the WebView, re-renders without it, and requires a real click in the freed
region to reach PHP (`clickAfterWebView`).

The same preservation rule applies to dirty-frame counts, timer idempotency,
first-frame hit testing, role sweeps, overlay churn, keyboard diagnostics, and OS
menu actions. Domain examples share feature source files; that avoids duplicated
UI implementations but does not automatically migrate every assertion.

## Diagnosing an unresponsive window

1. Launch the relevant example through `php bin/gpui run` — it builds first,
   executes the bare binary by default, and forwards example flags; add
   `--bundle` when you explicitly need the `.app` launch.
2. Inspect `windowState()` for active/key/visible/main and bundle identity.
3. Click a known control and inspect delivered event id/type/value.
4. If no event arrives, check hit geometry, attached listeners, focus, and native
   views that may intercept the region.
5. If the event reaches PHP but the view does not change, check shared state and
   invalidation rather than blaming input dispatch.

`acceptsFirstMouse` is not a universal exemption from activation requirements.
Keyboard, hover tracking, and initial click handling each have their own system
conditions. Foreground ownership is contested; do not make a nondeterministic
activation attempt a hard gate or call a failed attempt proof of a broken route.

The independent `input` example checks native typing inside its selftest and owns
the human-side diagnostics: `--wait-key <sec>` prints a verdict and exits 0 only if
a real key arrived, and `--trace` appends every event that reaches PHP to
`/tmp/input-events.log` (the consumer is `cmd/real-input-probe.py`). Unknown flags
are rejected with exit 2 rather than falling through to the interactive window.
For a small Escape diagnostic use:

```bash
php bin/gpui run overlay --escape
```

Human HID typing and physical resize/Dock dragging remain explicit acceptance
items. A black desktop capture or a window-only snapshot cannot close them.

Continue with [CLI](./cli) or the [contribution guide in Chinese](/contributing).
