# System Capabilities

This page separates available desktop APIs from the evidence that they work.
The current implementation target is macOS arm64; a C++ upstream symbol does not
imply that the PHP binding has implementations on every upstream platform.

## Evidence levels

- **Wrapper**: Pest and FakeBridge verify PHP argument handling, return shapes,
  null semantics, and enum validation.
- **Native**: C++ tests exercise actual gpui predicates or service implementations.
- **Route**: a compiled example drives a native round trip through the ABI.
- **Pixel**: a window snapshot is inspected and compared against a baseline.
- **Manual**: a separate visible interaction is needed, particularly outside the
  application window. The current window-snapshot tests do not cover system UI.

An existence count or successful return code is not interchangeable with visible
behavior. Historical measurements below describe specific checks, not a claim
that current CI or every platform is green.

## Connected APIs

| PHP API | Native implementation | Evidence and boundary |
|---|---|---|
| `clipboardText(): ?string`, `setClipboardText(string)` | `ClipboardGetText/SetText` | Wrapper and native clipboard round trip |
| `clipboardItem(): array` | `ClipboardGetItem`, plus macOS file-URL pasteboard reading | Text/image/path flags and real-data combinations below |
| `promptForPath(bool $dirs, string $title): ?string` | `PromptForPathTemp` | Manual; call from an event handler |
| `openUrl(string): bool` | `OpenUrl` and `IsOpenableUrl` | Native predicate table and route rejection; successful opening has an external side effect |
| `toggleInspector()` | `WindowToggleInspector` | Pixel evidence for picking/highlight mode, not an Inspector panel |
| `El::inspector($id)` | `component::Inspector` through ChildView | Actual Inspector panel pixels |
| `El::webview($url, $id)`, `El::webviewHtml($html, $id)` | `WebViewNew` / `WebViewEl` through ChildView | Native creation/bounds and local-page snapshot evidence; not arbitrary network loading |
| `webviewBack($id)`, `webviewForward($id)` | Page-side history evaluation | Native invocation route, not proof of a particular page history |
| `webviewProbe($id)` | `WebViewUrlTemp` / `WebViewBounds` | Reads the native control's applied rectangle and URL |
| `httpGet($url, $id): bool`, `httpResponse($id): ?array` | `HttpSendAsync` | Offline failure-path round trip; URL predicate tests |
| `watchDir($dir, $id, $create): int`, `unwatchDir($handle)` | `DirWatchStart` / macOS FSEvents | Native test seam verifies posting and dispatch; not an OS file-change test |
| `App::sysInfo($topProcs = 0): array` | `SysStateInit/Refresh/SortProcesses` | Direct values and two-sample CPU checks |
| `App::inflate($bytes, $gzip = false): ?string` | `shell::ZlibInflate` | Same external zlib/gzip fixtures in C++, native PHP, and Pest |
| `showFpsHud($anchor)`, `hideFpsHud()`, `fpsHudVisible()` | ShellRoot FPS monitor | Lifecycle route; resource-row pixel stability remains open |
| `openSheet(Element, $anchor)`, `closeSheet()`, `hasSheet()` | ShellRoot sheet APIs | Wrapper, lifecycle route, and pixels |
| `toast(...)`, `openDialog(...)` | ShellRoot toast/dialog APIs | See [Components](./components) and [Events](./events) |
| `tray($labels, $id, $tooltip)`, `removeTray()`, `trayCount()` | NSStatusItem and NSMenu | Lifetime counts; visible icon and menu interaction need acceptance |
| `onHotkey($id, $keycode, $mods)`, `removeHotkey()`, `hotkeyCount()` | Carbon hotkey APIs | Synthesized dispatch route; capture while another app has focus needs manual acceptance |
| `App::dockBadge($text)` | `NSDockTile.badgeLabel` | Visible Dock badge needs manual acceptance |
| `App::osNotify($title, $body): bool` | `NSUserNotificationCenter` | Handoff only; bundle identity and visible system delivery are separate |
| `App::grantRoot`, `grantExecute`, `grantStorage` | Capability read/write roots, execute allowlist, storage grant | Native allowed and denied paths |
| `App::fsRead/fsWrite/fsList/fsExists/fsMkdir/fsRemove` | `shell::FsRun` | Bidirectional PHP/native data checks and denied ungranted roots |
| `App::processRun($cmd, $args): ?array` | `ProcessRunBounded` | Allowed executable and rejected executable checks |
| `App::storagePath/storageSet/storageGet/storageRemove/storageLen` | Storage and persistence APIs | Grant boundary, data shape, and persistence-file existence |
| `notify(...)`, `dismissNotification($handle)`, `clearNotifications()`, `notificationCount()` | Component Notification APIs | Close-event route and card pixels; click delivery requires its own interaction check |
| `El::notifications($id)` | NotificationList through ChildView | Explicit visual layer required for cards |
| `setAppMenus($menus)`, `appMenuCount()` | `BaseSetAppMenus` | Installation route; OS menu bar is outside window snapshots |
| `menuRows()` | Menu row/action lookup | Reads declared action ids; separate from a physical menu click |

### URL policy

`openUrl()` uses gpui's own allowlist: HTTP and HTTPS only, nonempty host, no
control characters, DEL, or backslashes in the authority, and a decimal port no
larger than 65535. Link hrefs and TextView links use the same rule.

Tests do not launch a browser merely to exercise the accepted branch. They check
the native predicate and the rejected route separately. Validate any actual URL
opening workflow with appropriate user consent.

### Capability-bounded services

File operations use capability roots and a POSIX traversal based on `openat` and
`O_NOFOLLOW`, rather than bypassing grants with ordinary PHP I/O. Native tests
must check both directions: gpui-written bytes are readable by PHP, and PHP-written
bytes are returned unchanged by gpui, including embedded NUL bytes.

Process execution is allowlisted, uses an empty environment, and is bounded to
30 seconds and 8 MiB per output stream. Storage requires its own grant. These
contracts are distinct from whether the nano build also contains ordinary PHP
filesystem functions.

## Clipboard channels

`clipboardItem()` returns `text`, `image`, and `paths`. Missing text or image is
null; paths are a string array. A text-only round trip cannot verify image or file
formats. Use the compiled clipboard diagnostic after preparing real data:

```bash
php bin/gpui run hello --clip
```

The report contains `text=/image=/paths=`; -1 means the text or image representation
is absent. Historical AppKit `writeObjects:` checks produced:

| Clipboard contents | Report |
|---|---|
| 16-byte text | `text=16 image=-1 paths=0` |
| 25,110-byte PNG | `text=-1 image=25110 paths=0` |
| Two `public.file-url` objects | `text=-1 image=-1 paths=2` |
| Text and PNG together | `text=10 image=25110 paths=0` |

Image data is an **encoded stream**, not RGBA. macOS prefers PNG and falls back to
TIFF. Decode it with a library that supports the available representation.

The macOS upstream clipboard implementation supplies text and image, but not its
Windows `externalPaths` channel. The shim therefore reads file URLs from
NSPasteboard and returns absolute paths.

An AppleScript alias list is not a file-URL representation. For example, setting
the clipboard to a list of POSIX-file aliases can produce `paths=0` without any
binding bug. Copy in Finder or write actual `public.file-url` objects. Clipboard
tests replace clipboard contents, so run them deliberately.

## System sampling semantics

```php
$first = App::sysInfo();
usleep(200000);
$second = App::sysInfo(10);
```

The CPU measurement is a difference between samples. On the first process-wide
sample, `cpu` and process CPU values are zero; after that they represent the
interval since the previous call. Memory, disk, core count, and battery presence
are direct readings and can be useful immediately.

- CPU percentages are normalized across the whole machine to 0–100, not
  0–100 multiplied by the number of cores.
- Sizes are bytes. On macOS, disk readings describe the startup volume, not all
  mounted disks.
- Native sampling state is process-wide. A call from another window advances the
  same previous-sample state, which is why `sysInfo()` is static.
- A positive `$topProcs` adds the highest-CPU processes, capped at 256. Truncating
  the returned list does not truncate the native previous-sample table.
- `batteryPct` is not meaningful when `batteryPresent` is false.

The FPS HUD also samples resources. Once CPU/MEM rows become available, its
height can change. Lifecycle tests passing do not resolve that screenshot timing
problem; see [Testing](./testing#snapshot-stability).

## Runtime constraints

1. **Open path dialogs from event handlers, not a view callback.** The native
   dialog runs a nested message loop until the user finishes. PHP is blocked in
   the bridge call while native events may be queued. Do not start this modal
   operation while materializing a tree. It is intentionally absent from unattended
   selftests; use the interactive Open button.
2. **Do not call blocking HTTP on the UI thread.** The binding exposes
   `HttpSendAsync`, not `HttpSend`. Read the result after its queued event.
3. **WebView creation can reenter the window message loop.** Set the host's
   creation guard before calling `WebViewNew`, or the same frame can create the
   view a second time.
4. **Mount and own the notification layer explicitly.** This host uses ShellRoot
   for Dialog/Toast/Sheet, not the component Root that automatically mounts
   NotificationList. The shim retains per-window notification state; PHP supplies
   the visual layer:

```php
El::notifications('notices')->sizeFull()->absolute();
```

A successful `notify()` without that layer creates invisible state. Notification
entry uses a 400 ms animation driven by a wall-clock timer, so a few fast frames
can still produce a transparent snapshot. The current host layer also does not
apply the component Root's sheet-dependent insets: cards use the whole window and
can cover an open sheet.

## Available upstream symbols not wrapped

| Capability | Upstream symbol | Decision |
|---|---|---|
| Compression | `shell::ZlibDeflate` | Not wrapped: it writes stored blocks with a zlib/gzip container, not useful compression. The inflater is already exposed |
| Hashing / random | `shell::Sha256`, `SecureRandom` | Not wrapped when the nano artifact already provides PHP hash and secure-random functions |

Avoid adding ABI surface and a FakeBridge duplicate for functionality that already
exists in the compiled PHP runtime.

## Ask the artifact, not the development PHP runtime

```bash
php bin/gpui run hello --caps
```

The capability probe uses `function_exists()` inside the compiled binary. A
historical build reported:

```text
hash     hash, hash_hmac
random   random_bytes, random_int
zlib     -
curl     -
socket   -
proc     -
fs       file_get_contents, scandir, touch
dirwatch -
```

Re-run the probe when dependencies change. The local `php` executable is not
evidence of nano's compiled extension set.

Do not discover capabilities by dynamically calling a function name that might
not exist. Nano can abort with `Invalid callback ... not found or invalid function
name`, rather than throwing a catchable PHP `Error`.

## Missing from the C++ port

These are not simply unwrapped Rust APIs. The inspected C++ source and native
symbols did not provide implementations for recent documents, JumpList,
`register_url_scheme`, `screen_capture_sources`, `prevent_idle_sleep` /
ActivityGuard, `read_credentials`, or `observe_displays`.

`set_activation_policy` has an internal macOS call, not a public API. TCP and
WebSocket names in generated TypeScript declarations are not implementation
symbols. Adding these capabilities would require platform work, not just a bridge.

The upstream `SysNotifyShow` implementation is platform-dependent: Windows has
an implementation, while macOS and Linux have false-returning stubs. The PHP
macOS host supplies its own notification wrapper. Tray and global-hotkey support
also comes from this host's platform layer rather than a shared upstream API.

## macOS integration without another library

The build already compiles Objective-C++ and links Cocoa, WebKit, Carbon,
CoreServices, IOKit, AVFoundation, Speech, Metal, and QuartzCore. The separate
`gpui_mac.mm` translation unit implements status items, Carbon hotkeys, and Dock
badges without exposing gpui types across the C ABI.

Carbon registration does not use CGEventTap input monitoring. However, Carbon's
event queue still needs pumping: `gpui_shim_pump` calls the macOS pump, which uses
`ReceiveNextEvent` and `SendEventToEventTarget`. AppKit's `nextEventMatchingMask`
alone did not dispatch those events in the tested host.

Linux/Windows equivalents require separate work. Do not copy a platform example
that synchronously owns another event loop without integrating it with
`gpui_pump_events`; two blocking loops can wait on each other.

## Manual acceptance checklist

Use the `.app` generated and launched by the CLI. Packaging is already available;
it is no longer a missing prerequisite implementation. Still verify actual
system behavior:

- The tray icon is visible, and selecting an item delivers its label to PHP.
- A registered global hotkey triggers while another application has focus.
- An OS notification appears in the system notification center, not merely as an
  application card rendered by `El::notifications()`.
- The Dock badge displays the requested text.
- A native path dialog returns a selected path and handles cancellation.
- Application notification cards can be clicked, not just counted and dismissed.

`osNotify()` returning true only confirms handoff to the system. Bundle identity
is necessary for the tested notification path, but is not sufficient evidence of
a displayed banner. OS permission and presentation settings still matter.

Likewise, `trayCount()`, `hotkeyCount()`, and `notificationCount()` describe host
state and lifetime. Do not label those checks as visible-feature acceptance.

Continue with [Events](./events) for payloads and [Testing](./testing) for the
native and manual checks.
