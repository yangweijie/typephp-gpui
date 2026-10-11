# 系统能力

这一页回答两个问题：**PHP 现在能调到哪些桌面能力**，以及**哪些是真的接不上**。
每条都标了它的验收级别——"有 API"和"验过"不是一回事。

- **wrap-green**：`tests/SystemTest.php`（Pest + FakeBridge）证明 PHP 层的包装、空值语义、枚举校验
- **native-green**：`tests/cpp/shim_test.cpp` 直接量 gpui 的真实判据函数
- **route-green**：`hello --selftest` 在真原生二进制里跑一次真实往返
- **pixel-green**：`hello --shot` 出图，`shotdiff` 比对
- **manual**：窗口外的东西（系统对话框、菜单栏、通知中心）机器看不见，只能人看

## 已接

| PHP API | 原生实现 | 验收 |
| --- | --- | --- |
| `$app->clipboardText(): ?string`<br>`$app->setClipboardText(string)` | `gpui::ClipboardGetText/SetText`（`window_mac.cpp:2005/2044`） | wrap + route（selftest #8，写系统粘贴板再读回） |
| `$app->promptForPath(bool $dirs, string $title): ?string` | `gpui::PromptForPathTemp` | **manual**（见下方硬约束） |
| `$app->openUrl(string): bool` | `gpui::OpenUrl` + `gpui::IsOpenableUrl` | wrap + native（20 条 URL 表）+ route 的**拒绝侧** |
| `$app->toggleInspector()` | `gpui::WindowToggleInspector` | pixel（tree29）——**这是拾取模式，不是面板** |
| `El::inspector($id)` | `component::Inspector::New(cx)->IntoEl()` 经 `ChildView` 挂载 | pixel（tree31：面板真画出来了）|
| `El::webview($url, $id)`<br>`El::webviewHtml($html, $id)` | `gpui::WebViewNew` + `WebViewEl` 经 `ChildView` 挂载 | route（selftest #12）+ pixel（tree30，只到"没把布局搞塌"）|
| `$app->webviewBack($id)` / `webviewForward($id)` | `gpui::WebViewBack/Forward`（页内 eval `history.*`） | route（#12） |
| `$app->webviewProbe($id)` | `wry::WebViewUrlTemp` + `WebViewBounds` | route（#12，读出 400×300 的应用盒子） |
| `$app->httpGet($url, $id): bool`<br>`$app->httpResponse($id): ?array` | `gpui::HttpSendAsync`（**不是**阻塞的 `HttpSend`） | route（selftest #15：打 loopback 关闭端口，验整条异步路而不碰外网）+ native（`HttpUrlIsRemote` 判据表） |
| `$app->watchDir($dir, $id, $create): int`<br>`$app->unwatchDir($watchId)` | `gpui::DirWatchStart`（macOS 走 FSEvents） | route（#14，走 gpui 的 DirWatchAdd/Signal 测试接缝，不碰文件系统） |
| `App::inflate($bytes, $gzip = false): ?string` | `gpui::shell::ZlibInflate`（真解 Huffman） | 三方锚：同一批向量在 C++ 测试、原生 selftest #13、Pest 里各解一遍 |
| `$app->showFpsHud(string $anchor): bool`<br>`$app->hideFpsHud()` / `fpsHudVisible()` | `ShellRootShow/HideFpsMonitor` | wrap + route（#10）+ pixel（tree27） |
| `$app->openSheet(Element, string $anchor): int`<br>`$app->closeSheet()` / `hasSheet()` | `ShellRootOpenSheetView` 等 | wrap + route（#11）+ pixel（tree28） |
| `$app->toast(...)` / `openDialog(...)` | `ShellRootPushToast` / `ShellRootOpenDialogView` | 已有，见[组件](/guide/components) |
| `$app->setAppMenus(array)` / `appMenuCount()` | `BaseSetAppMenus` | route（#7）；macOS 系统菜单栏在窗口外，无像素验收 |

`openUrl` 用的是 gpui 自己那条白名单（`capability.cpp:5`）：只放行 `http`/`https`，authority
里不许有控制字符、DEL、反斜杠，host 非空，端口必须是 ≤65535 的十进制。`Link.href` 和
TextView 的链接处理走的是同一条规则，PHP 不另开一套。

## 三条硬约束

1. **`promptForPath` 只能在事件回调里调。** 原生对话框自带嵌套消息循环，一直跑到用户选完
   （头注释原文："they run their own loop until the user is done"）。在 `App::view()` 里调它，
   等于在一次 render 中间重入平台模态循环。此刻 PHP 阻塞在桥接调用内、`gpui_pump_events`
   没被驱动，gpui 的模态循环只会把事件入队、不会重入脚本 VM——所以它是安全的，但**仅限
   于从事件回调发起**。也正因为它要等人，`--selftest` 里没有这一步；交互 demo 的
   `Open…` 按钮就是它的手工验收路径。
2. **`HttpSend` 系不能上 UI 线程。** `sys/http.h:18` 写明它阻塞；只接
   `HttpSendAsync`，结果走 `poll_event`。
3. **`WebViewNew` 会重入窗口消息循环。** `webview/webview.h:118-123` 警告：它靠跑窗口消息
   循环来创建，那一帧可能被重建第二次，所以"已经建过了"的标志必须在调用**之前**落。

## 端口里有、还没接

符号已在 `gpui.o` 里（`nm` 实测为 `T`），包一层就行，属后续波次：

| 能力 | 符号 | 卡在哪 |
| --- | --- | --- |
| 剪贴板图片/文件 | `gpui::ClipboardGetItem`（含 `image`、`externalPaths`） | 只差跨边界的二进制缓冲 |
| 系统信息 | `SysStateInit` / `SysRefresh`（cpu/mem/disk/battery/processes） | 无 |
| 压缩 | `shell::ZlibDeflate` | **不做**：它写的是 stored 块（`DeflateStored`），只加 zlib/gzip 外壳、不压缩。要压缩请自己实现或在 PHP 侧生成。解压侧已经接了（`App::inflate`），它既读得懂 gpui 自己写的容器，也读得懂真 deflate |
| 哈希 / 随机 | `shell::Sha256` / `SecureRandom` | **不做**：产物里 `hash()`、`hash_hmac()`、`random_bytes()`、`random_int()` 都在，桥过来是净负价值 |
| 文件 / 进程 / 存储 | `shell::FsRun` / `ProcessRunBounded` / `StoragePersist` | 受 `Capabilities` 读写根授权模型约束（`ResolvePath` 要求先配 root），且进程调用阻塞——要单独设计，不是包装活。`FsRun` 优先级低：`file_get_contents`/`scandir`/`touch` 在产物里可用 |
| 应用内通知中心 | `NotificationPush` / `Dismiss` / `Clear`（`ui/notification.h:192`） | 要在元素树里挂 `component::NotificationList::IntoEl()`，比 Toast 大一档；Toast 已可用 |

## 「要不要桥」由产物自己回答

`hello --caps` 打印编译产物里到底有哪些原生函数：

```
hash     hash, hash_hmac
random   random_bytes, random_int
zlib     -
curl     -
socket   -
proc     -
fs       file_get_contents, scandir, touch
dirwatch -
```

计划里"桥 sha256 / secureRandom / zlib"那一条就是被这张表砍掉的：PHP 侧已经有的东西，
桥过来只会多一条 C ABI 加一个会漂移的替身实现。反过来 `curl`/`socket`/`inotify` 全空，
说明 `HttpSendAsync` 和 `DirWatchStart` 是真缺口。

探针用 `function_exists`，不能用"把函数名放进变量再 `$fn()`"：nano 里对不存在的函数
走动态调用会报 `Invalid callback ... not found or invalid function name` 并 **abort
（SIGABRT）**，不抛可 catch 的 `Error`。nano 的扩展集由 Composer 在编译前决定，不按源码
用没用裁，所以 `function_exists` 就是忠实探针。

## 端口里根本没有

这几项常被当成"gpui 有、我们没接"，其实 **C++ 移植版没有它们**（`nm` 在 `gpui.o` 里 0 命中，
源码里也没有）。它们是 Rust gpui 的 API，不是待办，是自写平台层：

recent documents · JumpList · `register_url_scheme` · `screen_capture_sources` ·
`prevent_idle_sleep` / ActivityGuard · `read_credentials` · `observe_displays` ·
`set_activation_policy`（只有 `window_mac.cpp:2134` 一处内部硬调，不是对外 API）·
TCP / WebSocket（`typings_data.cpp:22144` 那份是 TS 声明，没有实现）

另外三项在端口里有**位置**但没有 mac/linux 实现：

- **系统通知**：`SysNotifyShow` 在 Windows 是真实现（`notify_win.cpp:155`，`Shell_NotifyIcon`），
  在 mac（`notify_mac.cpp:17`）和 Linux（`notify_linux.cpp:19`）是 `return false` 的桩。
- **系统托盘**、**全局快捷键**：三处平台代码里都没有。

## 这三项不需要外部库

`cmd/build-shim.sh` 已经用 `-std=c++20 -x objective-c++ -fobjc-arc` 编译 shim，链接表里已经有
`Cocoa / WebKit / Carbon / CoreServices / IOKit / AVFoundation / Speech / Metal / QuartzCore`
（见 `examples/hello/project.macos.yml`）。所以 mac 侧的托盘（`NSStatusItem`）、全局快捷键
（Carbon `RegisterEventHotKey`，不像 `CGEventTap` 那样要输入监控授权）、Dock 角标
（`NSDockTile.badgeLabel`）都是**自己加一个 `.mm` 编译单元**的事，不引第三方库。

Linux/Windows 的对等实现（AppIndicator、XGrabKey、Toast XML）另说。参考实现里那些同步泵
自己事件循环的写法（例如 ui3 的 `gtk4.c:18-19`、`x11.c:675-677`）**不能照搬**——它和这里的
`gpui_pump_events` 主循环会互相等死。

## 手工验收清单

接了窗口外能力之后，这几条只能人看，且必须看：

- 托盘图标在菜单栏里**看得见**；点一下 PHP 收到 `tray` 事件
- 全局快捷键在**别的 app 有焦点时**仍能触发（否则等于没做"全局"）
- 通知中心出现条目；点击后 PHP 收到响应
- Dock 角标显示数字 / 进度

自动化的 `*_count()` 类断言读的是我们自己记的数，只能证明"没崩、没泄漏"，不能证明屏幕上
真有那个图标——别把这种绿当成能力可用。
