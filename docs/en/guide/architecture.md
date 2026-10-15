# Architecture and the C ABI Firewall

## Overview

```text
PHP declarative API       C++17 bridge          C++20 shim          gpui amalgam
App / Element / El --array--> gpui_bridge.cc --C ABI--> gpui_shim.cpp --gpui.h--> SpecArena
                              phpx + shim.h        owns gpui types             ShellMaterialize
       ^                                                |                      native El* tree
       +------------- gpui_poll_event <--- GpuiEvent <---+
```

PHP serializes an `Element` tree into arrays. The bridge recursively emits C calls for
`push/style/method/callback/attach/slot/registered`. The shim builds a gpui `SpecArena`;
`ShellMaterializeSpec` turns its description into a native `El*` tree. Events travel back
through a queue of fixed-size `GpuiEvent` structures. The PHP loop polls and dispatches them.

**A rendered frame is not a loop iteration.** `App::loop()` rebuilds the view only when dirty:
on its first iteration, after event dispatch, after an active/key/visible/main window-state
change, or after [`invalidate()`](./events#dirty-driven-rendering). An idle window therefore
does not reconstruct the PHP tree 60 times per second. State changes that produce no event
must explicitly invalidate the view. `renderCount()` and `frameCount()` expose the difference.

## Why keep a firewall?

The upstream header uses C++20 `concept` and `requires`, while TypePHP nano compiles with
C++17. The original solution separates the translation units and exposes only a C ABI.

| Unit | Standard | Includes | Responsibility |
|---|---|---|---|
| `gpui_bridge.cc` | C++17 | phpx, `gpui_shim.h` | PHP arrays to C calls; event structures to PHP arrays |
| `gpui_shim.cpp` | C++20 | `gpui.h` | App, window, arena and view lifetimes; event queues |
| `gpui_shim.h` | C | `<stdint.h>` | `GpuiArg`, `GpuiEvent`, opaque handles and exported functions |

Neither `gpui::` nor `php::` types cross this boundary. A window is an opaque `void*`, wrapped
on the bridge side by `WindowBox`, a `php::Box` subclass exposed as a PHP mixed resource.

`cmd/patch-concepts.sh` now replaces seven incompatible constructs: one concept with a
partially specialized trait, five requires clauses with `enable_if`, and one explicit
copy-initialization with default initialization. A translation unit that only includes
`gpui.h` went from 20 errors to none under C++17. `cmd/check-cxx17.sh` checks both standards
and both positive and negative cases of the `EmitsEvent` constraint.

This does **not** make the firewall redundant:

- The C++17 parse still relies on Clang extensions for default member initialization of
  bit-fields. A successful parse is not proof of strict ISO C++17 portability.
- Keeping the large amalgamated header out of the bridge reduces incremental compilation.
- Objective-C/ARC code, including `gpui_mac.mm`, needs a separate translation unit anyway.
- The C contract isolates upstream type changes from the PHP-facing bridge.

The firewall is now a measured engineering tradeoff, not a claim that C++17 parsing is impossible.

## Synchronous host row rendering

C ABI calls can include synchronous function pointers. `cmd/patch-host-list.sh` adds a host
branch to upstream `RenderVirtualItems`; the bridge invokes the PHP row closure on the same
thread with `php::call`.

Rows use a temporary `SpecArena` backed by frame memory. They must not append to or reset
the main window description while materialization is reading it. Their `Ctx` must retain
the host view's `self` entity, or row listeners created during prepaint cannot dispatch.
The PHP wrapper rejects window API reentry from a row closure, including rendering,
pumping and closing the window that is currently painting.

## Retained Dock state and panel descriptions

`patch-host-dock.sh` exposes retained Dock creation and release to the host. It also frees
old node vectors before replacing a layout.

Each panel owns a separate `SpecArena`. Native Dock rendering materializes the active panel;
the main window tree contains only the Dock mount. The default chrome delegates to the
complete upstream `DockSkin` renderer table, including separators and drag previews, rather
than forwarding only four script hooks.

Panel updates first build into a pending arena. A valid update replaces the committed
description; a failed update preserves the old panel. Uncommitted panels cannot participate
in layout restoration. Restoring a layout accepts only registered panels and retains its
parse arena until replacement or Dock release.

Window teardown releases the retained Dock **before** destroying panel descriptions because
the native state still contains panel render callbacks and data pointers.

## Build artifacts

1. Synchronize the vendored tree into the build sandbox, then apply the idempotent patch
   manifest. `php bin/gpui doctor` reports the authoritative marker list; do not maintain a
   separate hard-coded patch count. Patches cover event routing, retained entities, focus,
   registered callbacks, host views/application identity, state factories, lists and Dock.
2. `bun cmd/build.ts` produces `.work/gpui.h`, `.work/gpui.cpp` and `gpui.o`.
3. `cmd/build-shim.sh` compiles the shim as C++20 and archives it with
   `gpui.o/quickjs.o/AppLog.o` into `cpp-src/build/libgpui_shim.a`.
4. `tpc --nano` compiles PHP and the C++17 bridge, then links that archive and system frameworks.

`GPUI.lock` documents the build contract; `bin/gpui` validates each patch marker.

## Why the window root is ShellRoot

The host opens `WindowOpenView(ShellRoot)` directly. `KitOpenWindow` would wrap it in the
base `Root`, which breaks `ShellRootOf(window)` and prevents Dialog/Toast overlays from
finding their host.

The base Root's root-application context is not directly present. However,
`patch-host-application.sh` supplies host application identity and an app-effect trampoline.
`MenuBar` can therefore materialize and return actions to PHP. ShellRoot alone is no longer
a valid explanation for an unavailable MenuBar.

## Invariants to preserve

- **Copy arena strings.** Pointers received from `emitTree` can be frame-local bridge
  temporaries. Use `AStr` or `StrDup` in the shim. Keeping those pointers previously corrupted
  `size("100%")` and collapsed layouts.
- **Keep registered arguments alive through materialization.** `ComponentArgument`, strings
  and nested arrays must all use arena storage.
- **Match stub and bridge return types.** Declaring `gpui_app_create` as void while the C++
  bridge returns a Variant caused invalid AOT result writes and a segmentation fault.
  Run native selftests after changing either side; PHP checks cannot catch this.
- **Button and Link labels are children**, not `component.text`.
- **The host owns ChildView entities.** WebView and Inspector wrappers use `EntityNewRaw`
  and `RegisterHostView`. Rendering a ChildView does not transfer ownership; host entries
  have no script owner/policy for the script sweep to release. Drop them before `AppClose`,
  since the OS WebView must be released before its parent window.
- **Do not delete a payload after dropping its holder.** `EntityNewRaw` already installs a
  deleter. A second delete caused the dialog double-free.
- **WebView is state, not a renderable view.** Its `EntityNewState` object needs a wrapper
  that renders `WebViewEl(...)` before it can enter the tree.
- **Avoid implicit temporary string storage.** Calling a helper that accepts only
  `const std::string&` with a `const char*` can return a `Str` pointing into a destroyed
  temporary, especially with small-string optimization. The `cppStr(const char*)` overload
  prevents this; the old bug rendered `Saved` as the first five bytes of `success`.
- **Initialize output buffers before calls, not before reading them.** Clearing an error
  buffer in `ErrText` erased the shim's failure message. Initialize at declaration and read
  without modifying the result.
- **Scratch results expire at the next call.** Copy `gpui_shim_out()` into `php::String`
  within the same call stack. For directory results, finish growing `vector<string>` before
  taking any `c_str()` pointers.
- **Revoke macOS callback owners on close.** `gpui_mac.mm` receives an opaque `Box2*` owner.
  `gpui_mac_release_owner(box)` must remove tray/hotkey callbacks before that owner becomes invalid.
- **Notification state belongs to the window.** `WindowNotifications(cx)` reads base Root
  layers and aborts under ShellRoot. `EnsureNotifyState` owns a `NotificationListState` per
  window. Set its `self` and `useThemeSettings`; without `self`, animation timers never start
  and notifications remain transparent. PHP must mount `El::notifications($id)` explicitly.

## Next

- [Events and callbacks](./events)
- [Testing and gates](./testing)
