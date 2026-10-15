# 系统能力

这一页回答两个问题：**PHP 现在能调到哪些桌面能力**，以及**哪些是真的接不上**。
每条都标了它的验收级别——"有 API"和"验过"不是一回事。

- **wrap-green**：`tests/SystemTest.php`（Pest + FakeBridge）证明 PHP 层的包装、空值语义、枚举校验
- **native-green**：`tests/cpp/shim_test.cpp` 直接量 gpui 的真实判据函数
- **route-green**：`hello --selftest` 在真原生二进制里跑一次真实往返
- **pixel-green**：`hello --shot` 出图，`shotdiff` 比对
- **manual**：窗口外的东西（系统对话框、菜单栏、系统通知中心）机器看不见，只能人看

## 已接

| PHP API | 原生实现 | 验收 |
| --- | --- | --- |
| `$app->clipboardText(): ?string`<br>`$app->setClipboardText(string)` | `gpui::ClipboardGetText/SetText`（`window_mac.cpp:2005/2044`） | wrap + route（selftest #8，写系统粘贴板再读回） |
| `$app->clipboardItem(): array`<br>`['text'=>?string,'image'=>?string,'paths'=>string[]]` | `gpui::ClipboardGetItem` + mac 侧自读的 `NSPasteboardTypeFileURL` | route（#16 文本通道 + flags 判别）+ **真数据实测**（见下） |
| `$app->promptForPath(bool $dirs, string $title): ?string` | `gpui::PromptForPathTemp` | **manual**（见下方硬约束） |
| `$app->openUrl(string): bool` | `gpui::OpenUrl` + `gpui::IsOpenableUrl` | wrap + native（20 条 URL 表）+ route 的**拒绝侧** |
| `$app->toggleInspector()` | `gpui::WindowToggleInspector` | pixel（tree29）——**这是拾取模式，不是面板** |
| `El::inspector($id)` | `component::Inspector::New(cx)->IntoEl()` 经 `ChildView` 挂载 | pixel（tree31：面板真画出来了）|
| `El::webview($url, $id)`<br>`El::webviewHtml($html, $id)` | `gpui::WebViewNew` + `WebViewEl` 经 `ChildView` 挂载 | route（selftest #12）+ pixel（tree30，只到"没把布局搞塌"）|
| `$app->webviewBack($id)` / `webviewForward($id)` | `gpui::WebViewBack/Forward`（页内 eval `history.*`） | route（#12） |
| `$app->webviewProbe($id)` | `wry::WebViewUrlTemp` + `WebViewBounds` | route（#12，读出 400×300 的应用盒子） |
| `$app->httpGet($url, $id): bool`<br>`$app->httpResponse($id): ?array` | `gpui::HttpSendAsync`（**不是**阻塞的 `HttpSend`） | route（selftest #15：打 loopback 关闭端口，验整条异步路而不碰外网）+ native（`HttpUrlIsRemote` 判据表） |
| `$app->watchDir($dir, $id, $create): int`<br>`$app->unwatchDir($watchId)` | `gpui::DirWatchStart`（macOS 走 FSEvents） | route（#14，走 gpui 的 DirWatchAdd/Signal 测试接缝，不碰文件系统） |
| `App::sysInfo($topProcs = 0): array` | `gpui::SysStateInit/SysRefresh/SysSortProcesses` | route（selftest #17：烧 150ms 单核后 cpu 必须报 >0，实测 46.2%）+ 本机文件系统对照（Pest） |
| `App::inflate($bytes, $gzip = false): ?string` | `gpui::shell::ZlibInflate`（真解 Huffman） | 三方锚：同一批向量在 C++ 测试、原生 selftest #13、Pest 里各解一遍 |
| `$app->showFpsHud(string $anchor): bool`<br>`$app->hideFpsHud()` / `fpsHudVisible()` | `ShellRootShow/HideFpsMonitor` | wrap + route（#10）+ pixel（tree27） |
| `$app->openSheet(Element, string $anchor): int`<br>`$app->closeSheet()` / `hasSheet()` | `ShellRootOpenSheetView` 等 | wrap + route（#11）+ pixel（tree28） |
| `$app->toast(...)` / `openDialog(...)` | `ShellRootPushToast` / `ShellRootOpenDialogView` | 已有，见[组件](/guide/components) |
| `$app->tray(array $labels, string $id, string $tooltip = ''): int`<br>`removeTray()` / `trayCount()` | `NSStatusItem` + `NSMenu`（自写 `cpp-src/gpui_mac.mm`） | existence（计数）+ **manual**（菜单栏里看不看得见） |
| `$app->onHotkey(string $id, int $keycode, array $mods): int`<br>`removeHotkey()` / `hotkeyCount()` / `App::fireHotkeyForTest($i)` | Carbon `RegisterEventHotKey` + `InstallApplicationEventHandler` | **route**（selftest #19：合成事件走完 队列→派发→handler→出口→poll）+ **manual**（别的 app 有焦点时是否仍触发） |
| `App::dockBadge(string $text)` | `NSDockTile.badgeLabel` | **manual**（Dock 上有没有数字） |
| `App::osNotify(string $title, string $body): bool` | `NSUserNotificationCenter` | **manual**，且见下方 bundle 前提 |
| `App::grantRoot($dir, $writable)` / `grantExecute()` / `grantStorage()` | `Capabilities::AddReadRoot/AddWriteRoot/SetExecute/Storage` | native（shim_test 真跑 gpui `FsRun`）+ route（selftest #20/#21） |
| `App::fsRead/fsWrite/fsList/fsExists/fsMkdir/fsRemove` | `shell::FsRun`（POSIX 侧 `openat` + `O_NOFOLLOW` 逐级保权遍历） | 同上：双向对照（gpui 写的 PHP 读得到、反之亦然）+ 未授予路径必须被拒 |
| `App::processRun($cmd, array $args): ?array` | `shell::ProcessRunBounded`（空环境、30s、每路 8 MiB） | native（白名单内跑通、名单外被拒） |
| `App::storagePath/storageSet/storageGet/storageRemove/storageLen` | `shell::Storage` + `StoragePersist` | native（授权门前后的形状）+ 落盘文件存在性 |
| `$app->notify(string $title, string $message, string $type, string $placement, int $timeoutMs, string $id): int`<br>`$app->dismissNotification($id)` / `clearNotifications()` / `notificationCount()` | `component::NotificationPush/Dismiss/Clear`（`ui/notification.cpp:507/557/618`） | wrap + route（selftest #18 打的是 `close` 那条；`click` 只静态核对过 listener 接线，合成指针点不到浮层）+ pixel（tree32：两张卡各自落在 `top_right`/`bottom_left`，边距与宽度即主题默认值）|
| `El::notifications($id)` | `component::NotificationList::IntoEl()` 经 `ChildView` 挂载 | pixel（tree32）——**这一颗必须显式摆**，见下方硬约束 |
| `$app->setAppMenus(array)` / `appMenuCount()` | `BaseSetAppMenus` | route（#7）；macOS 系统菜单栏在窗口外，无像素验收 |
| `$app->menuRows()` | `AppMenuRowForId` + `ShellActionScriptId` | route：读回菜单条各行携带的 action id，键名写错（`label`/`id`）时它是空的 |

`openUrl` 用的是 gpui 自己那条白名单（`capability.cpp:5`）：只放行 `http`/`https`，authority
里不许有控制字符、DEL、反斜杠，host 非空，端口必须是 ≤65535 的十进制。`Link.href` 和
TextView 的链接处理走的是同一条规则，PHP 不另开一套。

## 剪贴板三通道是这么验的

自检里"写文本再读回"只能证明文本通道通、以及 flags 判别对——图像与文件要有**真数据**
才验得到。`hello --clip` 打开一个窗口、读一次粘贴板、打成一行 `text=/image=/paths=`
（`-1` 表示该表示没有），于是任何能改粘贴板的手段都成了测试装置：

```
printf 'hello clip text\n' | pbcopy && hello --clip   →  text=16 image=-1 paths=0
（⌘⇧⌃3 截一张图）           && hello --clip   →  text=-1 image=<TIFF 字节数> paths=0
（Finder 里复制一个文件）    && hello --clip   →  text=-1 image=-1 paths=1
```

本轮实测记录（2026-10-11，用 AppKit `writeObjects:` 脚本化写入，比手工截屏更可重复）：

| 粘贴板内容 | `--clip` 输出 |
|---|---|
| 16 字节文本 | `text=16 image=-1 paths=0` |
| 一张 25110 字节 PNG | `text=-1 image=25110 paths=0` |
| 两个 `public.file-url` | `text=-1 image=-1 paths=2` |
| 文本 + PNG 同时 | `text=10 image=25110 paths=0` |

两点值得注意，都是实测出来的：

- **图像是编码流，不是 RGBA。** mac 上 `ClipboardGetItem` 先取 `NSPasteboardTypePNG`、
  没有再退 `NSPasteboardTypeTIFF`（`window_mac.cpp:2074`），所以从截图软件粘过来的常常是
  TIFF 字节。要位图自己交给 GD/Imagick 解。
- **文件路径那一半是我们自己读的。** gpui 只在 Windows 上填 `externalPaths`
  （`window_win.cpp:1636` 的 `CF_HDROP`），mac/linux 的实现只给 text+image。所以 mac 上
  shim 直接读 `NSPasteboardTypeFileURL`，输出成与 Windows 同形状的"换行分隔绝对路径"。
  顺带一个坑：用 AppleScript `set the clipboard to {POSIX file ...}` **验不出这条**——它写的是
  alias list（`dyn.ah62...` / `'list'` flavor），不是 `public.file-url`；要用
  `writeObjects:` 或直接从 Finder 复制才是真场景。

## `App::sysInfo()` 的采样语义

```php
$a = App::sysInfo();            // cpu 一定是 0：这是第一次采样，没有"上一次"
usleep(200000);                 // 或者用 setTimer 周期采
$b = App::sysInfo(10);          // 这一次的 cpu 才是 200ms 窗口里的真实占用
```

- **`cpu` 与 `procs[].cpu` 是两次采样之间的差值**，第一次必然报 0。`memPct`、`diskPct`、
  `memTotal/Used`、`diskTotal/Used`、`ncpu`、电池都是直接读数，第一次就有效。
- 百分比都是 0..100，且 cpu 是**全机跨核归一**的（不是"单核 0..100×n"）。
- 字节单位是 B；`disk` 指**启动卷**（gpui 在 mac 上 `statfs("/")`），不含其它挂载点。
- 采样状态在原生侧是**进程内一份**，不是每窗口一份——mac 的墙钟差本身就是文件静态量
  （`sysinfo_mac.cpp` 的 `sPrevWall`），多份交替采样只会互相污染读数。所以
  `App::sysInfo()` 是静态方法，任何一处调用都在推进那个"上一次"。
- `$topProcs > 0` 才带进程列表：gpui 一次采样列出全机几百个进程，前 N 条由原生侧按 CPU
  降序截（上限 256），免得把几百条搬过边界再挑。截断不会污染下一次读数——下一次按 pid 找
  前值（`FindPrevCpu`），而 `prevProcs` 是另一份完整数组。
- 电池在没有电池的设备上 `batteryPresent=false`，此时 `batteryPct` 无意义。

## 运行约束

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
4. **通知层要自己挂、状态要自己养。** gpui 的通知层由**基座 `Root`** 挂在窗口 overlay 上
   （`ui/root.cpp:62` 的 `WindowNotifications(cx)`），而我们的窗口根是 `ShellRoot`——为了
   Dialog/Toast/Sheet 才换过去的，两者互斥。直接调 `WindowNotifications` 会撞上
   `component window state is missing; call component::Init before KitOpenWindow`（SIGABRT）。
   所以：状态 entity 按窗口自己持有一份（`EnsureNotifyState`，`notification.cpp` 全篇不读
   `Root`/`Layers`，实测成立），层由 PHP 显式摆一颗 `El::notifications($id)`。摆法用
   `->sizeFull()->absolute()`：它自己就是绝对定位的浮层，盖在窗口上而不参与外层排布
   （tree32 与 demo 用的都是这一形状）。
   另外**入场是动画**：`Starting` 段不透明度 = `elapsedMs/400`，推它的是 50ms 墙钟计时器，
   所以截图要跑到动画走完（tree32 跑 80 帧），不然拍到的是全透明。
   与基座差一档的地方：基座那层会按打开的 Sheet 内缩（`ui/root.cpp:64` `RootNotificationInsets`），
   我们这颗不会 ⇒ Sheet 开着时通知卡会压在它上面（卡片定位读的是整窗尺寸）。

## 端口里有、还没接

符号已在 `gpui.o` 里（`nm` 实测为 `T`），包一层就行，属后续波次：

| 能力 | 符号 | 卡在哪 |
| --- | --- | --- |
| 压缩 | `shell::ZlibDeflate` | **不做**：它写的是 stored 块（`DeflateStored`），只加 zlib/gzip 外壳、不压缩。要压缩请自己实现或在 PHP 侧生成。解压侧已经接了（`App::inflate`），它既读得懂 gpui 自己写的容器，也读得懂真 deflate |
| 哈希 / 随机 | `shell::Sha256` / `SecureRandom` | **不做**：产物里 `hash()`、`hash_hmac()`、`random_bytes()`、`random_int()` 都在，桥过来是净负价值 |

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

mac 集成（`App::tray` / `onHotkey` / `dockBadge` / `osNotify`）已经接上，但这四条机器看不见，
只能人看，且必须看：

- 托盘图标在菜单栏里**看得见**；点一下 PHP 收到 `tray` 事件（带被点的 label）
- 全局快捷键在**别的 app 有焦点时**仍能触发（否则等于没做"全局"）——自检里那条是**合成**事件，
  它只证明派发链通
- 系统通知中心（OS 那个，不是 `El::notifications` 画在窗口里的卡片）出现条目。CLI 已生成并从
  `.app` 启动，打包不再是未实现项；仍要核对 bundle 身份、系统通知权限与实际可见条目，
  `osNotify()` 返回 true 只代表交给系统，不算可见性通过。
- Dock 角标显示数字 / 进度

自动化的 `*_count()` 类断言读的是我们自己记的数，只能证明"没崩、没泄漏"，不能证明屏幕上
真有那个图标——别把这种绿当成能力可用。

两条动手前先量的探针，结论记在这儿（它们决定了上面这张单为什么长这样）：

1. **Carbon 快捷键在我们的泵下派不派发？** 不派。`GpuiAppRunStep` 只有
   `[NSApp nextEventMatchingMask:… dequeue:YES]`（`window_mac.cpp:2309-2320`），没有
   `RunCurrentEventLoop`，Carbon 队列不会自己排空。解法在我们自己的调用点里：
   `gpui_shim_pump` 每帧先 `gpui_mac_pump()`（`ReceiveNextEvent(…kEventDurationNoWait…)`
   + `SendEventToEventTarget`）。handler 与泵同在主线程，所以入队不需要锁。
   这条是 selftest #19 能成立的前提。
2. **OS 通知要不要 bundle 身份？** 要。早期探针跑的是裸 Mach-O；当前 CLI 已生成 `.app`，
   应从 bundle 跑再核对实际显示。历史“没有 .app”不代表当前产物，bundle 存在也不等于通知可见。
   托盘与 Dock 角标不吃同一个通知身份前提。
