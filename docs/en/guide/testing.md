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
| Pixel comparison | `php bin/gpui shotdiff check hello` | Per-tree screenshots against baselines. Before capturing tree30's WebView the shot pumps until the region mean colour turns the HTML's blue (`App::regionRgb`, a content predicate sampled on the flat blue area away from the rounded corners): frame budgets are unreliable — 120 and 600 frames both produced fully blank captures; if it never paints, the shot is taken anyway and prints `painted=0` so the red is diagnosable. Trees 29/31 capture with `App::setInspectorPicking(false)`: the picking highlight paints over whichever element the window's recorded pointer lands on, so leaving it on makes the whole frame depend on where the mouse is; the toggle itself is asserted by `App::inspectorEnabled()` in selftest step 26 (reading `insp=1/1/0`). A failing frame is copied to `tmp/shotdiff-fail/` and that path is printed inside the ✗ line: earlier runs only reported a score on the terminal, so the rare frame was overwritten before anyone could look at it (#106 went 1 red in 20 chain runs with no evidence left) | Built binary and usable display |
| Doctor | `php bin/gpui doctor` | Toolchain, C ABI, and required patch markers | Partial |
| C++17 parsing | `bash cmd/check-cxx17.sh` | Header parsing under C++17/C++20 and both outcomes of the EmitsEvent trait | Upstream headers |
| Pure C ABI | `bash cmd/check-c-abi.sh` | C11 compilation and forbidden C++ syntax/header checks | No gpui object |
| Tracked build inputs | `php cmd/check-build-inputs.php` | Patch scripts, manifest membership, example source lists, plus a repo-wide invariant that no indexed path holds an empty blob while the worktree file has content. That fourth check was added after measuring 13 paths registered via `git add -N` (intent-to-add): the path shows up in `git ls-files`, so the first three checks stay green while the commit would write 13 zero-byte files (four patch scripts, the whole English guide set, a pixel baseline). "Tracked" is decided by blob sha, not by name. The gate now runs six checks in total: those three plus the empty-blob invariant, **every `cmd/*.(php\|sh)` that CI names is tracked** (a new workflow step forgotten from the index = the runner can't open the file), and **every local path/example name that `composer.json` scripts reference exists** (after an example is removed, this is the stale-entry class that survives longest). | No |
| Three-layer contracts | `composer test` | Stub/FakeBridge/C++ names and arities; App calls; Element kinds and dedicated bridge paths | No |
| Upstream vocabulary | `composer parity:upstream` | Kinds, methods, callbacks, slots, catalog names, state kinds, and style vocabulary | Vendored sources only. Batch 5 added a self-verifying patch allowlist: descriptor export names the sandbox patches add (ProgressCircle/PopupMenu/ContextMenu) pass only if each name really appears in some `cmd/patch-*.sh` — catching both "export added but patch forgotten" and "exempted name with no patch" |
| Example coverage | `composer test` | Public API/enum usage and argument counts across examples, plus four anti-drift legs in the same file: every example ships a `project.macos.yml` whose listed sources exist, every `examples/_shared/features/*.php` is claimed by at least one example, every tree of every example builds headless under FakeBridge with no orphan handler (the gate body is `cmd/headless-trees.php`), and — added 2026-10-22 — in any example that rejects unknown flags, the set of flags it actually reads must equal the set its rejection message documents, in both directions (`--automation` used to be read only inside `runInteractive()`, i.e. behind that very guard, so using it alone was rejected by its own CLI; it was also missing from the message) | No |
| Capability table vs code (both directions) | `php cmd/check-doc-claims.php` (`composer check:doc-claims`; runs in the CI docs job right after the anchor gate, preceded by `--self-check`) | One criterion, two faces, four tables. **A under-report**: a row in the "upstream concepts mapped to PHP" table of `upstream-coverage.md` (both languages) that carries a gap marker (❌ / 未接 / not exposed / not supported) and names, in backticks, a `El`/`Element`/`App` public method that does exist is reported with file:line and the full method name. It exists because of a contradiction measured on 2026-11-02: section 3 documented the animation sugars as landed (`El::transition()`/`El::spring()` plus the kit `transitionInterpolation` gate) while the mapping table still said `Animation ❌ transition and spring not exposed`. **B over-report** (added the same day): a method signed in the **first column** of the "connected" table of `system-capabilities.md` (zh 「已接」 / en "Connected APIs") that exists nowhere in the code is red; `El::x()` and `$app->x()` are checked against that class, bare `x()` against the union of the three tables, and slash lists such as `App::a/b/c` member by member. Both rules are deliberately narrow: A skips the "in the port, not wrapped" tables because those rows cite already-connected methods as contrast inside the same cell (the deflate row says "the inflate side is connected (`App::inflate`)"), which would be a guaranteed false red; B reads only the signature column and only call forms (a name immediately followed by `(` or `/`), so column-2 C++ symbols and upstream op names like `move_to` stay green. The narrowness was measured rather than assumed: widening A to **every** table in the two coverage pages plus the two capability pages plus components and events yields exactly one extra hit - `upstream-coverage.md:181`, where the path op name `close` happens to equal the real method `App::close()` - so widening would manufacture one false red, and A stays on the concept table. Forward reading: `DOC CLAIMS OK — 扫 4/4 张表（A:21 A:20 B:34 B:34 行），方法并集 587 名`. Red arms measured on the real docs, each row inserted then reverted: `El::notARealSurface()` ⇒ `FAIL (1 = 少报 0 / 多报 1)`; `El::clipboardText()` plus `$app->notHere()` ⇒ `多报 2` with "El 类没有" and "App 类没有" (a cross-class mix-up is caught); bare and prefixed A rows ⇒ `少报 1` each; the negative control `shell::ZlibDeflate` / `move_to` ⇒ still rc=0; renaming the zh heading to something the gate does not know ⇒ rc=2 未判定 printing "the 「已接」 table yielded no rows", and reverting returns rc=0. `--self-check` proves the detector: each direction ships one contradiction that must go red and one legitimate row that must not | No |
| Doc anchor ids | `php cmd/check-doc-anchors.php` (runs in the CI docs job after `docs:build`) | Covers what `docs:build` cannot see: the build checks that pages exist but never resolves `#fragment` (it shipped a real dead anchor to a renamed heading). This gate resolves every in-site anchor against the `id="…"` values actually rendered in the built output — safer than matching source headings, since VitePress' id generation transliterates CJK and de-duplicates. Currently 60 in-site links / 11 with anchors / 0 problems; exits 2 when zero links were scanned, so "not looked at" can't pass as "checked and clean". | No |
| Bundle on-screen qualification | `php cmd/check-bundle-signature.php` (`composer check:bundle`; runs in the CI native job right after `Native selftest`) | The standing sentinel for #56. It asserts two **artifact properties** (not OS-contested state, so they can be hard-asserted): ① `Contents/MacOS/<CFBundleExecutable>` must be a **regular file** — a symlink makes `codesign` refuse outright ("the main executable or Info.plist must be a regular file"); ② the bundle signature must bind `Info.plist` (`codesign -dvv` shows `Info.plist entries=N`, and `codesign --verify --strict` passes). When either breaks, the window **never enters WindowServer's on-screen ledger** while `windowState()`'s active/key/visible/main all read true and every pixel gate stays green (`--shot` renders in-process via `cacheDisplayInRect`) — only a human pressing keys hits it, which is exactly why it belongs in CI. Measured across four combinations: symlink / regular file unsigned / hardlink unsigned ⇒ `onScreen=false`; regular file + plist-bound signature ⇒ read `onScreen=true` at the time, but the same artifact measured `false` five consecutive times 20 minutes later — these are **necessary, not sufficient** conditions, so this gate proves the bundle is signable and correctly shaped, not that its window will be on screen. Exits 2 when no `.app` was found ("artifacts not built" is not a pass) and on unknown flags | No |
| Frame/node budget ratchet | `composer bench:tree` (= `php cmd/bench-tree.php --check cmd/bench-tree-budgets.txt`; runs in the CI php job right after Pest) | Measures only the PHP half (view builder plus `toArray()`): five sizes, five runs each, and the **min** is compared against the budget file — one row over budget is a red, and `us/node` has its own row. Min rather than median came out of a same-machine A/B: the median drifts with machine state (a series medians at 48ms contained a 94ms max), while min differs by only 10~20% between fast and slow states. Memory is reported but not gated (a 20k-node tree peaks at 168MB — that is a framework property, not a regression signal). Red arm measured: injecting a real O(n²) makes the large tiers blow through (60001 tier: 302ms, 5.04 µs/node) with rc=1 while the small tiers stay OK. Explicit escape valve for a busy machine: `GPUI_SKIP_BENCH_TREE=1` | No |
| Hotkey pump leaves mouse events (#99) | `composer check:hotkey-pump` (= `bash cmd/check-hotkey-pump.sh`; runs in the CI native job right after the bundle gate) | Drives the **production `gpui_mac_pump()`** (`cpp-src/gpui_mac.mm` is compiled and linked in), not a copy of it — a copy would only prove the design works and would stay green if production were reverted. Three hands-free arms: ① register a real hotkey (the pump early-returns while `g_hotkeys` is empty, so without one that path is never exercised), post a pair with the hotkey at the queue head and the mouse right behind, then run the production pump ⇒ the mouse must still be there, the hotkey must be taken, **and the sink must really receive one hotkey event** ("the event is gone" ≠ "the event was dispatched"); ② unregister the hotkey and post another pair — the pump must touch neither (the `empty()` early-return path); ③ the negative control runs the pre-fix "any event" drain, which **must** eat the mouse, otherwise the self-proof has no discriminating power and the gate exits 2 (undetermined). **It ships its own red arm**: flip the production peek's `false` to `true` in a temporary copy (the production file is left untouched) and the gate must go red. Measured green arm `old drain ate mouse=1, pump left mouse=1, left hotkey=0, sink hits=1, idle mouse=1, idle hotkey=1`; red arm `pump left mouse=0, sink hits=0` ⇒ `HOTKEY-PUMP GATE OK`. It measures the **queue layer** (whether the down was stolen), not pixels and not human delivery; missing prerequisites, a failed hotkey registration, a non-unique red-arm anchor and unknown flags all exit 2 | Built `gpui.o` + window session |
| Clean exit on the interactive quit path (#101) | `composer check:exit-clean` (= `bash cmd/check-exit-clean.sh`; runs in the CI native job right after the hotkey-pump gate) | An interactive quit / window close takes the "return from main → exit → `__cxa_finalize_ranges` → static destructors" path, and the bridge registries hold PHP callables (`php::Variant`): the Zend engine shuts down first, the destructors run afterwards ⇒ `~Variant → zval_ptr_dtor` touches freed memory, SIGSEGV. **`--selftest` goes through PHP `exit()`, which does not run those destructors**, so a dozen green selftests cannot see this path at all — a human closing the window hits it. Hands-free repro: put a single `quit` in an `--automation <dir>`, and **create the directory fresh every round** (a command file is unlinked once consumed, so reusing a directory waits forever for the next number — that is a harness error, not a red gate). The gate runs `build --no-native` before measuring (a failed build leaves the previous binary behind) and asserts rc=0 **and** that the receipt contains `commands=1` and `#1 quit -> bye`, so "never ran" cannot pass as "checked and clean"; a hang past the 40s watchdog, an unbuildable artifact and unknown flags all exit 2 (undetermined). Red side measured before the fix: hello and input both 139; after the fix both 0 | Built binary + window session |
| Token badge pixel discriminator arm | `bash cmd/check-token-paint-png.sh` | A **second color-channel** recheck behind kit's `tokenDeliveryRender` gate (the hard gate stays in the selftest): the selftest calls `App::snapshot()` offscreen while the ON frame is on screen (`sys_get_temp_dir()` honors the TMPDIR env — the script must read `${TMPDIR:-/tmp}` to stay in sync; a hard-coded `/tmp` reports "no image"), then the script scans the same grid with PHP GD for the red badge (GD decoding stays out of the AOT domain — examples have no GD precedent). It produces readings, not gate failures: red in PNG = the badge is in the composite frame (**the expected state after the A2 fix**; measured 有红@(42,44) on 2026-11-01, the same pixels kit's regionRgb hard gate asserts); **no red in PNG = the badge never reached the paint/composite frame**, which is the pre-fix defect measured 2026-10-31 — seeing it now means an A2 regression, and that red is owned by kit's `tokenDeliveryRender`. Red selftest gate, missing image, or PNG size ≠ 420x200 all exit 2 (undetermined) | Built binary + window session |
| always-scrollbar paint probe (preconditioned) | `bash cmd/check-scrollbar-paint.sh` | `--shot`-channel acceptance for "scrollbarMode always actually draws the thumb": kit tree4 (`sectionKitScrollbarPair`, hover/always boxes shaped identically w220/h120/gap40/p40, probes (244,48)/(504,48) on the 16x60 right-edge strips — **the builder must carry `p(40)`**; the first draft omitted it so boxes started at (0,0) and the probes read the root background). Asserts the two boxes' region averages differ; equal color ⇒ **applicable=false, exit 2 (undetermined)** (measured 2026-10-31: rgb(223,223,223) in both — offscreen compositing does not draw the always thumb either, so "NSScroller overlay auto-hides without real mouse events" holds for --shot too). No rc=1: not drawing the scrollbar is not a regression red, it is an unmet precondition; no image or PNG ≠ 900x680 also exit 2. "always actually draws" currently requires real-HID, preconditioned acceptance | Built binary + window session |
| kit gate batch entry points (reachability) | `composer check:kit-gates` (= `bash cmd/check-kit-gates.sh`; one CI native step) / `composer check:kit-arms` | Five kit-domain gates had documentation but **no entry point** — no composer script, CI step or test named them, so nothing ever ran them. Split by rc semantics: `check:kit-gates` runs the four that can genuinely fail (`cmd/check-accordion-toggle.sh` = v2 H3, `cmd/check-token-link-hits.sh` = v2 H5, `cmd/check-click-survival.php` = the per-control breadth sweep, 2026-11-03, `cmd/check-scrollbar-hover-hide.php` = the #112 regression slot, 2026-11-04); any rc=1 makes the aggregate 1, otherwise any rc=2 makes it 2. `check:kit-arms` runs the three that only offer "proven (0)" or "precondition unmet (2)" (`check-scrollbar-click.sh`, `check-scrollbar-paint.sh`, `check-token-paint-png.sh`) and deliberately stays out of CI, so a condition the runner cannot provide is never read as a regression. Both print each gate's own verdict line and then `GATE/ARM <name> rc=` — the wrapper rc is not the gate verdict; read each gate's line. A missing kit binary or an unknown flag exits 2 | Built binary + window session |

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
17. **System state**: direct memory/disk/core readings plus the documented initial
    zero sample, then two hard criteria that each claim only what it can see. The old
    single-window rule ("spin 150 ms, then whole-machine `cpu` must be above zero")
    reddened intermittently because those ticks are publish-quantized - 39 of 60
    measured 150 ms windows saw a zero delta, with freezes up to 851 ms - and the
    tempting replacement ("this process's `cpu` must be above zero") is a criterion
    that never looks at the spin: with the spin deleted, idle frame pumping still read
    about 10. So step 17 now compares two consecutive windows of the same process,
    an idle one and a spinning one, and requires `procs[].cpu` to differ by at least 3
    points (measured 0.4 versus 10.0); swapping the window order yields -10.0 and the
    gate goes red, which is the discriminating arm. The whole-machine field must still
    reach a positive value within 24 spinning windows, but is read as proof that the
    delta channel works, not that this spin filled it. `sysIdle`/`sysBurn`/`delta`,
    `sysTries`/`retryWin`/`cpu2` and `sysTop5` print in the EXTRA line.
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

## Host data loop (batch 6: rows-callback return-value channel)

Batch 6's `cmd/patch-host-data.sh` lets rows callbacks (`render_rows` etc.) return
**real data** — the patch adds a shimId branch at the top of `CallInLayout`: a callback
table entry with a non-zero shimId means a host-registered callback (`PushShimCallback`),
routed through `gpuiInvokeShimDataCallback` → the bridge's `php::call` synchronously
invokes the PHP handler → the return value is marshalled back (`GpuiDataNode` tree →
`ComponentDataValue` → JS). The four-piece pattern matches `cmd/patch-host-list.sh`
(host registers → shim fork → php::call → backfill).

**How to test** (kit selftest positive/negative gates):
- `dataTableRowsCallbackSuccess`: register a `render_rows` handler returning object row
  arrays → the probe's fails count does not move (snapshot succeeded), ledger clean —
  "success is visible."
- `dataTableRowsCallbackLoud`: no handler registered → probe fails +1 — "failure is visible."
- `candlestickChartRenderSuccess` / `sankeyChartRenderSuccess` / `searchableListRowRender`:
  positive-path gates for the three new charts (rows callback returns real arrays → chart/
  list renders with no registeredError).
- Negative-path gates (`*NoHandlerQuiet`): no handler → Failure Div (does not enter
  request->Fail, ledger stays clean).

**Known limitation**: the element channel (`render_cell` / `render_row` etc. — callbacks
that return **elements**) is not implemented — it goes through the `BuildComponentElement`
shimId branch → non-deterministic SIGBUS (DataTable paint verified, ~1/3 crash rate).
Batch 6 does the data channel only (callbacks that return **data**).

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
| `fire <id> <event>` | generic event dispatch seam: element seam first (`gpui_selftest_fire` only accepts click/change/hover), then registered seam (`gpui_selftest_fire_registered` looks up `(id,type)` in the shimCallbacks table, any event name goes through `ComponentCallback::InvokeWith` → queue → PHP handler) | widget-click's event name is fixed at 'click'; token_click/link_click and other distinct event names have no other **verb** - `fire` is the only automation-side delivery seam. Since 2026-11-03 a real click delivers them too (`accordionRealClick` / `linkRealClick` walk the same `InvokeWith` chain), so "fire is the only way to verify H3" is no longer true |
| `rgb <x> <y> <w> <h>` | region average-color read (via `regionRgb` window paint buffer) | screenshot only captures the visible area (content viewport height); below-the-fold targets are invisible in the PNG. This verb can read any coordinate — but the display buffer does not sync-update in the automation path (handler runs, ExampleState updates, kitTree rebuilds with new state are all confirmed, yet the pixel does not change). This is a rendering timing limitation, not a dispatch-chain problem |
| `scroll <x> <y> <deltaY>` | send one scroll-wheel event (`realScroll` → `gpui_mac_send_scroll`) | targets in an overflowYScroll viewport below the fold have h=0 in the hits table and clickat returns 0. The scroll event is processed in the next frame's pump. **Does not call automationForceFrame** — that rebuilds the tree and resets the ScrollView scroll position |
| `poll` | diagnostic: poll one event from the queue and print it raw | distinguishes "event was never enqueued" from "event was enqueued but the handler never received it" |
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

The `kit` example opens the same door: `php bin/gpui run kit --automation <dir> [tree] [height]`
— `tree` picks the pixel tree (1-4, default 1), `height` raises the window (default 680;
the tree-1 scrollbar sample sits below the fold until the window is taller). The tree-3
token pre-granulation (`kit-tok-in`) is done automatically by `runKitAutomation` after the
first-frame materialization (`App::inputReplaceToken`), so the hits table contains the token
chip's hit geometry.

**Scrollbar hit-reading** (Phase 33 batch 1 H2): `catalogScrollbar` is a registered view with
a visible rect in the hits table (click=1). But Scrollbar mouse semantics are drag (press +
move + release) — a same-point press-and-release does not scroll, and the automation protocol
has no drag verb. This is the in-#96-boundary half (scroll semantics unreachable). The
NSScroller overlay path auto-hides without a real mouse (`cmd/check-scrollbar-paint.sh`
measured two boxes the same color, applicable=false, rc=0/rc=2 premise-style).
`cmd/check-scrollbar-click.sh` verifies the scrollbar is in the hit table (click=1); the
clickat result is recorded as a reading. **h=0 does not mean "unclickable"**: the 2026-11-03
per-control sweep measured h=0 rows in the full-height page receiving the click (the rows that
crashed under #110/#111 were h=0 rows), so `cmd/check-click-survival.php` filters candidates by
width >= 8 and ignores height - the earlier "h=0 therefore below the fold, not clickable"
inference is retracted.

**Token/link hit-reading** (Phase 33 batch 1 H3): `widget-click` only fires 'click', and
token_click/link_click are distinct event names — among the automation **verbs** only `fire` can
emit them. Since 2026-11-03 the **link's real-mouse half is machine-asserted too**
(`linkRealClick`: dedicated window, rect derived from that run's hitProbe, `realClick`, 6/6 runs
delivering `link_click`), so "fire is the only way to verify a link click" is retired.
**The token half is still not covered by a real click** - real-mouse hits on a token chip belong
to the human pass (#96 boundary); the agent verifies the in-process dispatch chain only
(fire delivery + hits geometry).
The tree-3 `El::link` has been replaced with `El::textView` (base kind Link's click semantics
open the href and do not consume on_link_click).

**Numbering note**: the zh guide's v2 checklist uses H1–H5 (H1=NativeMenu, H2=Resizable,
H3=Accordion, H4=scrollbar, H5=token+link), matching `findings.md`, plus H6 added on
2026-11-02 for #106: the non-zero side of `hoverId`, which no synthetic seam can produce
(`mouseMoved` and `mouseDown/Up` aimed at a button's hit-table centre leave `hoverId` at 0 even
with `mainKey=1`), so only a real cursor move can falsify it. Batch 1's internal
H1–H3 (H1=Accordion, H2=scrollbar, H3=token/link) conflict with this. The automation scripts
(`cmd/check-accordion-toggle.sh` → v2 H3, `cmd/check-scrollbar-click.sh` → v2 H4,
`cmd/check-token-link-hits.sh` → v2 H5) annotate their v2 numbering in comments.

**Opening each row for a human pass** - launch the built binary directly, since
`php bin/gpui run` replays the whole build chain on every call:

```bash
./examples/kit/build/kit               # or --section 1: H2 resize handle, H4 hover/always pair
./examples/kit/build/kit --section 2   # H3 Accordion trigger
./examples/kit/build/kit --section 3   # H1 NativeMenu trigger, H5 token chip + link text
./examples/kit/build/kit --section 4   # H4 standalone pair (identical boxes, only scrollbarMode differs)
```

**CUA/machine rounds must go through the bundle** (human hands are unaffected): the bare
binary has no bundle id, so the computer-use screenshot capture binding is skipped
(`has_image:false`, every coordinate action blocked by the frame gate - see the findings
entry of late 2026-11-04).

```bash
./examples/kit/build/kit.app/Contents/MacOS/kit --section 3   # exec the bundled binary directly
php bin/gpui run kit --bundle --section 3                     # or via run's --bundle path
open examples/kit/build/kit.app                               # bring to front (event keys need frontmost)
```

Interactive mode prints `KIT-EVENT <key>=<value>` for every event that reaches PHP
(`nmCopy=action`, `accI0=open`, `tokClick=delivered`, `linkClick=delivered`) and
`KIT-HOVER hoverId=<n>` when the hover id changes - that line turning non-zero is the H6
reading. On launch it prints `KIT-TREE n=<tree> title=<title>`, which is the self-proof that
the new flag actually moved something: without it there is no way to tell "the selector works"
from "the selector was silently ignored" (a pty run printed a distinct n/title for all four
trees). Readings go to stdout and are prompt only on a tty; a redirected stdout is
block-buffered, so run it in a terminal rather than piping to a file.

**The three defects the first human pass reported (2026-11-02), as of 2026-11-03.** The two that
crashed turned out to share one root cause, which is now fixed; what is left is two visual defects
and one design question.

- One root cause: upstream **reinterprets the window/overlay content entity as a `ScriptView`** before
  using it - `Entity<T>::Get` is an unchecked `(T*)EntityGet(...)` (`gpui.h:6830`) - and the component
  event bodies read exactly its first field (15 sites of `view->runtime` in `component_shell`). Our
  `PhpView` used to be a single 8-byte `Box2*`, so that "runtime" *was* the `Box2*`:
  `LiveComponentCallback` was reading a callback-table header out of Box2's bytes. Whatever it finds
  decides the reading - in one tree, **8 of 18 clickable controls SIGSEGV and 3 printed
  `belongs to a superseded render`**; both readings are the same defect, and the "superseded"
  diagnosis itself is misleading, because the table being consulted was never the right table.
  Fix: `PhpView`'s first field is now the `scriptViewRuntime` alias pointing at the real `g_runtime`
  (`cpp-src/gpui_shim.cpp`), with two `static_assert`s pinning both offsets. Covering only `runtime`
  is justified, not lucky: the one other field upstream writes, `dirty`, is touched solely in the
  overlay rebuild guarded by `isScript` (`shell/root.cpp:174`), and our layers open through
  `ShellRootOpenDialogView`, which hard-codes `isScript=false` (`root.cpp:340`) - that out-of-bounds
  write is unreachable in this host.
- **Accordion (#111, fixed, with a regression slot)**: new kit selftest step `accordionRealClick` -
  a dedicated window with the catalog Accordion, header rect derived from that run's `hitProbe`, and
  `realClick` walking the whole real chain `DispatchMouseUp → ShellComponentValueRelay::OnValue →
  InvokeWith → LiveComponentCallback` (`gpui_selftest_fire` bypasses that seam, which is why the old
  `accordionToggleGate` stayed green over this). Green side: 6/6 runs, "3 candidates → 3 reached PHP".
  Red arm (alias put back to `Box2*`): 6 runs = **5 SIGSEGV, 1 with both new steps FAILing**.
- **TextView link (#114, same root cause, closed with it)**: new `linkRealClick` step really clicks the
  markdown link in its own window and asserts `link_click` reaches PHP - 6/6. The batch-15 reading
  ("6 runs at one coordinate: 3 crash, 3 blocked") was not a lost id; the lookup was hitting a fake
  table. The guard in `cmd/patch-dispatch-string-shimid.sh` therefore no longer fires on this path
  (measured: 0 warnings) and stays as the net for a genuinely unknown id.
- **Breadth gate**: `cmd/check-click-survival.php` clicks every `click=1` control in all four trees,
  each in its own automation session. Hard verdicts: process rc must be 0, plus the two fatal
  signatures (`superseded render` / `window state is missing`). Other `shell:` warnings are counted,
  not judged - that is this gate's own declared limit. "No clickable rows" splits into two verdicts:
  a hits table with **zero rows at all** means the probe measured nothing (undetermined); rows that
  exist but are all click=0 (tree 4 is two plain text scroll boxes) is a **measured empty set** and
  counts as a pass - on the premise that another tree in the same run measured >0 candidates (a
  lone `--tree 4` run cannot provide that premise; measured rc=2), and an all-trees-zero sweep is
  undetermined too, so the gate cannot go green by vacuity alone. Reading on the 2026-11-03 post-fix
  build: **CLICK-SURVIVAL OK (60/60 controls alive, 0 fatal signatures; 60 advisory `shell:` warnings
  counted, not judged; tree 4 clickable 0 - measured empty set, other trees proved the pipeline in
  the same run)**.
- The tree 3 "open dialog / alert / sheet" triggers: **#110 is still a design question**.
  `cmd/patch-window-layers.sh` replaced `LayersOf()`'s panic with "warn once and return null" (all 14
  call sites already treated null as "do nothing"), and the sweep survives 11 of 11 clicks; but a real
  click still opens no layer - use `App::openDialog()/openSheet()/notify()`. **Fixed 2026-11-04 batch 24**: ShellRoot now inherits gpui::Root and the shim calls
  `RootCapturePlugins` + `RootMount` after opening, mounting the app-registered root
  plugins (incl. component::kWindowStatePlugin) onto the ShellRoot — `Root::Read` finds
  it and `WindowLayersOf` stops returning null; a real click opens the confirm dialog
  and the sheet (captures). Regression slot: `cmd/check-dialog-open.php` (stderr-warn
  verdict; red arm measured rc=1). The fail-soft in `cmd/patch-window-layers.sh`
  stays as the backstop. The original design-question record follows.
- In tree 1 the `hover` box shows its scrollbar **permanently** (both boxes had a thumb in the
  screenshot), contradicting "hover only while hovering" - **task #112, fixed 2026-11-04 batch 20**:
  the root cause was `IdCollect` giving unnamed elements zero path contribution, so the structurally
  identical hover/always boxes collapsed to one scrollId and shared one fade entry - the always box
  settled it visible every frame while the hover box started the exit, and neither bar ever hid
  (machine-reproduced: scroll, move the cursor away, dwell 3s, capture - the hover thumb still up;
  `cmd/patch-scroll-id-ordinal.sh` folds the sibling ordinal into unnamed path users; regression
  gate `cmd/check-scrollbar-hover-hide.php`). On the same page the
  path-drawing block paints over the text below it - **task #113**. Neither is visible to
  shotdiff: the recorded baseline only covers the top 900x680 of the page, and both blocks sit
  below the viewport. State of the readout evidence: `KIT-EVENT <key>=<value>` **is** now machine-seen -
  `--automation` enables the echo too (the three gates that consume automation stdout read
  `snapshot.txt`, so extra lines move no verdict), and the per-control sweep printed `step=`, `page=`
  and `rate=` lines; `KIT-HOVER hoverId=0` was observed in a natural exit. From 2026-11-03 the human
  half of H3/H5 is no longer blocked by a defect. The selector and the echo were added on 2026-11-02:
  the interactive path was pinned to tree 1, and it called `wireKit()` before creating the
  window - `wireKit` uses window APIs (`bindKeys`, `elementCallback`) - so a bare
  `./examples/kit/build/kit` died with "No window created". Rows H1, H3, H5 and H6 therefore
  had no entry point at all, which is a different thing from "not yet verified".

**The 2026-11-03/04 human HID round (computer control + a six-step human card).** All verdicts
come from the pty `KIT-EVENT`/`KIT-HOVER` lines and full-screen captures; the "a11y seam" = macOS
AXPress → upstream `accessibilityPerformPress` → `WindowAccessibilityPerform`, a third machine-side
channel beside the `fire` seam and the real-mouse seam.

- **H3 Accordion: pass.** A real click on the first header printed `KIT-EVENT accI0=open`; the a11y
  seam on the same headers flipped both ways across 6 events (`accI0=open→closed→accI1=open`). Real
  mouse, a11y press and `fire` all deliver `on_change` to PHP.
- **H6 hover non-zero side: pass, first-ever measurement.** The real cursor resting on the hover
  trigger printed `KIT-HOVER hoverId=4` (non-zero) and back to `0` on leave. The side no synthetic
  seam could produce (batch 7) now has a human-seen reading behind #106's color-state headroom.
- **H2 Resizable: end state holds, continuity not frame-sampled.** After the human drag the divider
  rests at panel A ≈335px (default `panelSize(180)`, range cap 320 - sitting on the clamp) and stays
  after release; "changes continuously while dragging" rests on the human's report plus this single
  end-state frame, recorded as such.
- **H4 scrollbar always: main assertion passes, #112 reproduces.** Real hover + scroll moved the
  content (first row 01 → 04) and the right-edge thumb is painted (capture); the same frame shows the
  hover box's thumb with **nobody hovering**, still there 4s later ⇒ **#112 (permanent hover thumb)
  reproduces on tree 4**. "Thumb moves with scroll" was not sampled across two frames - noted edge.
- **H1 NativeMenu: #115 fixed (2026-11-04 batch 19), the human hop still pending.** The dead point
  read after batch 17: the row dispatch (`OnDispatchAction → WindowDispatchAction`) consults
  exactly two places - element-level OnAction listeners (the trigger's rows are carried data, not
  live elements - no listeners) and the app-level action table (whose only registrar served the app
  menu bar, so the trigger path never registered) ⇒ neither knows the action and the selection is
  dropped silently. `patch-native-menu-action-reg.sh` registers every row's action through the
  established weak-seam convention; the regression slot is the new selftest step
  `nativeMenuActionDelivery` (red arm: registration off → false/no delivery; green arm:
  true/delivered). **The "human-only" verdict is overturned (late 2026-11-04, see
  findings)**: the capture failure came down to the bare binary having no bundle id, so CUA's
  PiP capture binding was skipped (helper log "PiP session capture binding skipped (missing
  bundle id)") - once launched from the bundle, the frame gate opens. Coordinate clicks remain
  unreliable (synthetic clicks never move the real cursor; the modal session hit-tests by the
  cursor position), but **event-strategy global keys DO enter the modal tracking queue while
  the app is frontmost**: a11y-click the trigger → `Down` highlights the first row (captured) →
  `Return` activates it ⇒ `KIT-EVENT nmCopy=action` delivered (pty reading, reproduced twice).
  "Row selection → PHP" is **machine-reachable**; the machine recipe: bundle launch + `open`
  to bring frontmost + **observe once between every step** (back-to-back keystrokes race and
  drop steps)
- **H5 link half: #116 fixed (2026-11-04 batch 18).** Batch 17's "seam divergence" framing was
  **wrong**: probing layer by layer (up-gate → OnTextLink → DispatchString → shim route → box
  enqueue `found=1`) showed the C++ chain is **the same chain for real and synthetic clicks**; the
  event died in the last centimetre - wireKit registered the handler under the op long-name
  `on_link_click`, while the bridge's `eventType()` strips the `on_` prefix so the queue event is
  `link_click`, and `handleEvent`'s `handlers[$id][$type]` lookup silently missed. Fix: wireKit
  registers short names (one place in kit.php); `cmd/check-token-link-hits.sh` gained a third
  verdict - the **PHP handler echo** (`KIT-EVENT tokClick/linkClick=delivered`; red arm measured:
  old registration rc=1, fixed rc=0). A `fire` return value only proves the C++ side enqueued; the
  handler echo is what proves "reached PHP".
- **H5 token half: #117 retracted; the real-mouse hit stays on the human list.** Batch 17's
  "chip text mismatch" was a **misreading**: the 「提及」 chip is the token render callback's
  **marker** (`El::text('提及')` - the host-render callback's identifiable mark; the
  `tokenRenderNull` A/B falls back to the label chip), never meant to show @张三. What actually
  remains: in the h=680 hit table the token input and chip rows are **click=0 down=0**
  (mouse-inert), so a real chip click cannot even enter the hit chain ⇒ token real-mouse hit stays
  on the human list (#96 boundary); the reported "cannot type into the input" is now
  characterized (2026-11-04 batches 22/23): **the typing chain works** (clickat focuses the
  field, focus id -1001 lands, key a/b/c, `value` reads "@张三abc"); **the rendering works
  too** - batch 23's probes walked the text run's whole draw chain: emitted (slice grows),
  laid out ('abc' 20.7x20 right after the chip), **draw call really executes**
  (TextLayoutDraw: x=49.9, line0w=20.7, color 10/10/10/255, recording=0, clip = full window),
  and the **selftest live loop's regionRgb reads dark glyphs in the text area** (average
  246→198) ⇒ **#118's premise is refuted** (the text run IS painted) - the automation
  screenshot/rgb "nothing renders" was the display buffer's post-event staleness (a known,
  documented limitation). Regression slot: selftest step `tokenTypingPaint` (real click +
  real keys in the live loop, regionRgb asserts the text area's average < 200; red arm
  without keys ⇒ background 246 ⇒ FAIL). What remains of the human "cannot type" report - the **weak visual affordance** - is
  also handled (batch 26): the kit token input gained a white background + gray border
  (`bg('#ffffff') + borderColor('#c8c8c8') + borderWidth(1)`; the demo may dress its own
  field - the single-line input's bare frame is the inputs.php pixel contract, the host
  default is untouched). Baselines re-recorded for kit trees 3/4 and hello tree 27. The
  same round fixed the `value` verb's guaranteed crash (the `$ev` type-inference trap,
  see the verb table).
- **#113 fixed (2026-11-04 batch 21)**: the path-drawing triangle used to paint over the first
  accordion header in tree 1. True mechanism: the bridge's path branch (gpui_bridge.cc) appended
  only `method` ops from its rest list, silently dropping `param_style` (w/h) - path elements laid
  out 0x0 (element->Bounds() w/h = 0) and the drawing commands painted 90px from the anchor right
  over the next row. Fix: the path branch passes param_style through (same gpui_shim_param_style
  as ordinary elements). Regression slots: selftest step `pathOverlapGate` (sized path + red block
  below: top point must not be red / bottom must be red; red arm with the drop restored reads
  (204,2,2) at the top => FAIL) and `pathScrollGate` (the path scrolls with its container: old spot
  clean, new spot blue). shotdiff baselines unchanged - the path section sits below the 680 fold.
- Process notes (not conclusions): CUA's in-app screenshot and coordinate actions are gated behind
  "no usable pixel-to-pointer rectangle" for this gpui canvas even with every TCC grant in place ⇒
  this round used a11y element presses + keyboard (`{name,pid}` is the only app_ref shape that
  resolves) + host-side `screencapture` + pty readings as ground truth; on a shared machine injected
  events and the human's own input fight each other (the Return aimed at the open menu landed
  nowhere).

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

Closed (**#105**, 2026-11-02), and neither candidate was the mechanism: toggling the
inspector also enters picking mode (`entity.cpp:381`), and while picking is on each frame
paints a 20% accent fill across whichever element the window's recorded pointer position
(`win->mouseX/mouseY`) lands on (`window_common.cpp:626` plus `gpui.cpp:7600`). Pointer
inside and pointer outside are therefore two whole-frame-different images. One division of
labour is worth naming: the capture function has long parked that pointer (`gpui_shim.cpp:1729`
onwards — its comment even lists "hello tree31 whole-frame different" as the symptom it was
written for), but parking is **not enough** while picking is on: with the park in place, eight
solo captures of tree31 still split 4 / 3 / 1 between near-white, accent-heavy and a
mid-animation frame. What actually settles the frame is turning picking off. Frame count was
never the lever either: 80 frames still went intermittent. Two changes: capture with
`App::setInspectorPicking(false)` (the panel keys off `on`, so it keeps drawing), and assert
the toggle itself through `App::inspectorEnabled()` (hello selftest step 26, reading
`insp=1/1/0` — on, still on after picking off, off after the second toggle). Measured: tree29
captured 20/20 byte-identical with the same md5 as its committed baseline (that baseline
recorded the pointer-outside state; pixel difference 0), and tree31 captured 20/20 identical
while differing from its old baseline in 998/480000 = 0.208% of pixels, all inside the 32×32
magnifier button at (489,0)-(520,31), where the pressed `#d4d4d4` reverts to the unpressed
`#f8f8f8`. Only tree31's reference pixels were re-recorded. The boundary still holds: name
the mechanism, then show the delta is one localized box, before touching a baseline.

Sweeping the same defect class found a second member: `examples/embedded`'s `--shot` path also
toggles the inspector (its tree contains `El::inspector('em-inspector')`), so its baseline was
eating the same pointer-dependent highlight. It now captures with
`App::setInspectorPicking(false)` too: 20 consecutive captures are byte-identical, and the delta
against its old baseline is 998/395200 = 0.253% confined to the same 32×32 magnifier box
(`worst = 0.141`, identical to hello's), so only `tests/baseline/embedded/tree1.png` was
re-recorded. The comment there claimed the panel "only draws while picking mode is on" — the
mechanism is wrong: `IntoEl()` keys off `on`, and picking only controls the hover highlight,
which is exactly why turning picking off keeps the panel.

The chain's intermittent reds are not one class (#106, evidence kept in `tmp/shotdiff-fail/`):
chrome tree1 differs by 0.494% confined to one 89x32 ghost button, page background `#f6f3ee`
turning into hover background `#f5f5f5`, i.e. the real cursor was *moved* onto a hoverable
element after the park; mac tree1 differs by 1.764% over one 222x32 dark block that merely
lightens (`#171717` to `#262626`) — zoomed in, that is the `->primary()` "装 app 菜单" button
swapping its whole fill, i.e. the same class of button colour state, and the reason it reproduced
as the identical frame twice is that `hoverId` is sticky (only move events rewrite it, so a cursor
resting in the same place re-creates the same frame; hover vs pressed is not yet separated);
overlay tree1 produced two frames differing from each other by 4.905% with pale green/red toast
tints, i.e. an animation caught mid-flight. So there are at least two classes (colour state vs
animation in flight), each needing its own fix. Also measured: `ignoresMouseEvents` inside the
snapshot does **not** keep the coordinate parked (`win->mouseX` still read a point inside the
window while the flag was 1), because gpui pulls events off the application queue.

The animation class is now closed: `examples/overlay`'s `--shot` pumps until the top-right card's
icon reads success-green (`App::regionRgb(526,68,4,4)`, predicate `g > r + 60`; resting mean
measured (145,226,175), mid-entry (200,240,214), card-absent (247,162,162)) and otherwise captures
anyway, printing `note_ready=0`. The arm has headroom: dropping the pre-predicate `loop(80)` to
`loop(2)` made six consecutive runs report `tries=4` — the predicate rejected the unsettled frames —
while the produced PNG stayed byte-identical to the baseline (`8cdc13c9…`), so no re-record was
needed. The colour-state class is now self-provable: `windowState()` carries `hoverId`, and hello's
`--selftest` hard-asserts it is 0 after a capture and names any missing column (reading
`shotHover=0/missKeys=0`). All three tamper arms were measured: dropping the column on the headless
side reddens the Pest key-set test; dropping it in the bridge yields `missKeys=hoverId` with rc=1;
reporting a non-zero `hoverId` reddens the same selftest. The key list has one owner,
`App::WINDOW_STATE_KEYS`.
Selftest step 27 makes that reading a standing output (`hoverArm=<armed>/<cleared>/hits=<n>`,
measured `0/1/hits=2`): the cleared side (must be 0 after a capture) and "the hit table contains a
hoverable element" are both hard-asserted, and the latter's arm was measured (changing the target
predicate to `w > 5000` yields rc=1; restoring yields rc=0). The `armed` side is only a reading,
never an assertion: after drawing a button with `onClick`, computing its centre from the hit table,
and re-promoting the main window to key (`mainKey=1`), a synthetic `mouseMoved` and
`mouseDown/Up` still leave `hoverId` at 0 — the synthetic seam cannot produce a hover, so only a
real cursor move can falsify the non-zero side (added to the manual HID checklist).

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
