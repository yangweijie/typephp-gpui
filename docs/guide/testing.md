# 测试与门禁

门禁清单如下（数目以本表为准，别在别处再抄一份数字——以前"六道/七道"就和表不同步）。
改代码后按此清单跑；改 stub/C++ 后**必跑原生 selftest**（PHP 层门禁抓不到 AOT 运行期问题）。

| # | 门禁 | 命令 | 覆盖 | 依赖 gpui？ |
|---|------|------|------|------------|
| 1 | Pest | `composer test` | PHP 声明层：Element 序列化、App 路由、FakeBridge | 否 |
| 2 | PHPStan | `composer analyse` | `src/` 类型/静态（level 5） | 否 |
| 3 | C++ shim_test | `composer test:cpp` | shim 纯函数：kindFromString / AStr arena 拷贝 / toComponentArg schema / buildComponentArgs / ComponentKind 漂移警报（排除 Registered/ChildView 后未映射值钉在 17） | 是（gpui.o） |
| 4 | selftest | `php bin/gpui selftest hello` | 21 链路端到端：输入取值 / 点击 / 定时器 / dialog / catalog 回调 / 错误回传 / app 菜单 / 剪贴板 / url 白名单 / HUD / Sheet / WebView / 解压 / dirChange / http / 剪贴板完整一项 / 系统状态 / 通知中心 / mac 集成 / 授权文件系统 / 进程与存储 | 是（二进制） |
| 5 | shotdiff | `php bin/gpui shotdiff check hello` | 视觉回归：逐树像素比对基线（18 棵）。tree30 的 WebView 出图前先泵到区域平均色变成 HTML 的蓝（`App::regionRgb` 内容判据，采样落在平整蓝区避开圆角）：帧数预算不可靠，120 与 600 帧都曾拍到整块空白；泵不出来照拍并打印 `painted=0`，红得可诊断 | 是（二进制） |
| 6 | doctor | `php bin/gpui doctor` | 工具链 + 全部补丁 marker 校验（清单见 `GPUI.lock`） | 部分 |
| 7 | C++17 可解析性 | `bash cmd/check-cxx17.sh` | `cmd/patch-concepts.sh` 的门禁：`gpui.h` 在 `-std=c++17` 与 `-std=c++20` 下都要能解析，且 `EmitsEvent` 降级后判据**双向**正确（有特化为真、无特化为假） | 是（头文件） |
| 8 | C ABI 纯 C | `bash cmd/check-c-abi.sh` | 防火墙的主张本身：`gpui_shim.h` 用 `clang -x c -std=c11 -Wall -Wextra` 编得过，且文本里没有 `namespace`/`template`/`std::`/非 C 标准头的 `#include <>`。头里漏进 C++ 时，报错原本要晚到几分钟后的链接期 | 否 |
| 9 | 构建输入在册 | `php cmd/check-build-inputs.php` | 「克隆出来能不能构建」：bin/gpui 点名的每支补丁脚本都已跟踪、cmd/ 下每支 `patch-*.sh` 都在清单里、每个示例 yml 的 `sources:` 都在版本控制里，外加一条全仓不变式——**索引里不许有"路径在册但 blob 是空的"的文件**。最后这条是 2026-10-15 补的：`git add -N`（intent-to-add，本仓库登记新文件的惯例）只记路径，索引里是空 blob（sha `e69de29…`），而 `git ls-files -- <path>` 照样把它列成"在册"，于是前三条会在"提交出去是 0 字节文件"的状态下全绿——实测当时有 13 个这种路径（含 4 支补丁脚本、整族英文指南、一份像素基线）。所以判"在版本控制里"要看 **内容 sha**，不是看名字；提交前必须先 `git add <路径>`。审核 P0-1 量到这三类输入当时全未跟踪，而本地跑起来一切正常。现在共六条：除上述三条 + 空 blob 不变式外，还核 **CI 点名的 `cmd/*.(php\|sh)` 都在册**（新增步骤忘了 `git add` = runner 打不开文件）与 **`composer.json` scripts 点名的本地路径/示例名都存在**（删掉一个示例后最容易留下的就是这种坏入口）| 否 |
| 10 | 三层契约 parity | `composer test`（`tests/ContractTest.php`） | stub ↔ FakeBridge ↔ `gpui_bridge.cc` 的名字与元数一一对齐；`src/App.php` 里调用的 `gpui_*` 必须在 stub 里声明过；Element 能发出的每个 kind 都必须在 shim 的 `kKindNames` 表里在册（静态解析表的 `{...}` 体内，不越界读后面的代码；webview/inspector/notifications/dock_area 四个专用 bridge 路径豁免）。`setAppMenus` 键名那族错就是四层各自"看着对"，编译期没人查 | 否 |
| 11 | 上游词表 parity | `composer parity:upstream` / `php cmd/check-upstream-parity.php` | 我们发出的每个"上游按名字查表"的符号都必须在册：kind ↔ `ComponentKind` 枚举、method/callback/槽名 ↔ `materialize.cpp` 的 101 个 op 名与 prelude 槽表、catalog 导出名与方法名 ↔ descriptor 表（含 bridge 里硬编码的那两个）。上游对**带参未知名**是静默丢弃（`materialize.cpp:451-454` 链尾无 else），五层门禁都不报，这条是唯一的哨兵。取数端 `cmd/upstream-vocab.php` 读的是 vendored 的 `upstream/gpui-kit-cpp`，克隆即可跑。批5 起补丁在沙箱侧加的 descriptor 导出名（ProgressCircle/PopupMenu/ContextMenu）经**自校验豁免表**放行：每个豁免名必须在 `cmd/patch-*.sh` 里真出现，防「加了导出名却忘了补丁」或「豁免了没补丁的名字」 | 否 |
| 12 | 示例覆盖率与拆分漂移 | `composer test`（`tests/ExampleCoverageTest.php`） | 反射出 `Element`/`App` 的每个公开成员与每个枚举取值，断言**任一示例**（`examples/**/*.php`）里真调用过，再静态核每次调用的实参个数。同一支文件里还有四条防漂移的门：每支示例都有 `project.macos.yml` 且它列的源都在、`examples/_shared/features/*.php` 每份至少被一支收录、逐支起无头进程把每棵树在 FakeBridge 上建一遍并核 handler 不是孤儿（门本体是 `cmd/headless-trees.php`）、以及**带"未知开关"扫描的示例里实际读取的开关集合与提示文本里声明的集合双向相等**（2026-10-22 加：`--automation` 曾只在 `runInteractive()` 里读 `$argv`，被自己那道扫描拒掉，说明书上也没这颗）——把一域搬去独立示例却忘记补 yml，无头 PHP 全绿、只有编译产物缺那个函数 | 否 |
| 13 | 文档锚点解析 | `php cmd/check-doc-anchors.php`（CI docs job 里跟着 `docs:build` 跑） | 补上 `docs:build` 看不到的那一半：它**只查页面存在、不解析 `#fragment`**（实测放过一根指向已改名标题的真死锚）。这条门拿**产物里真实渲染出的 `id="…"`** 比对照料内链接的锚（比源码标题可靠：VitePress 生成 id 含中文转写与去重），当前扫 60 条站内链接 / 11 条带锚 / 0 问题；一条链接都没扫到就 rc=2，防空跑 | 否 |
| 14 | bundle 在屏资格 | `php cmd/check-bundle-signature.php`（`composer check:bundle`；CI 原生 job 里跟着 `Native selftest` 跑） | #56 的常驻哨兵。断两件**产物属性**（不是 OS 竞争态，所以能硬断）：① `Contents/MacOS/<CFBundleExecutable>` 必须是**正规文件**——符号链接会让 `codesign` 直接拒签（"the main executable or Info.plist must be a regular file"）；② bundle 签名必须把 Info.plist 绑进去（`codesign -dvv` 出 `Info.plist entries=N`，再过 `codesign --verify --strict`）。这两条坏掉时**窗口从不在 WindowServer 的在屏账本里**，而 `windowState()` 的 active/key/visible/main 全真、像素门全绿（`--shot` 走进程内 `cacheDisplayInRect`），只有真人手点才撞得上——所以它必须在 CI 里，不能靠人记着查。实测四象限：symlink / 真文件未签 / 硬链接未签 ⇒ `onScreen=false`；真文件 + 绑定 plist 的签名当时读到 `onScreen=true`，但同一产物隔 20 分钟连五次读到 `false` ⇒ 这两条是**必要而非充分**条件，本门保证的是"bundle 造得可签、身份齐"，不保证"这扇窗一定上屏"（在屏资格在本机仍会飘，见 17-E）。一个 `.app` 都没扫到就 rc=2（"产物没建"不算通过），未知开关也 rc=2 | 否 |
| 15 | 帧/节点预算棘轮 | `composer bench:tree`（= `php cmd/bench-tree.php --check cmd/bench-tree-budgets.txt`；CI 的 php job 里排在 Pest 之后） | 只量 PHP 半边（view builder + `toArray()`）：5 个规模各跑 5 次取 **min** 与预算比，超一档即红，`us/node` 单独一档。取 min 而不是中位数是同机 A/B 量出来的——中位数随机器状态漂（med 48ms 的序列里混着 max 94ms 的停顿），min 在快/慢状态间只差 10~20%。内存只报不门（2 万行峰值 168MB 是框架特性，不是回归信号）。红臂实测：注入真 O(n²) ⇒ 大档全超（60001 档 302ms、µs/node 5.04）rc=1，小档仍 OK。机器正忙时的显式逃生阀 `GPUI_SKIP_BENCH_TREE=1` | 否 |
| 16 | 热键泵不吃鼠标（#99） | `composer check:hotkey-pump`（= `bash cmd/check-hotkey-pump.sh`；CI 原生 job 里跟着 bundle 门跑） | 驱动**生产 `gpui_mac_pump()`**（把 `cpp-src/gpui_mac.mm` 编进来一起链），不是它的抄本——抄本只能证明设计成立，生产改回去它照样绿。免人手三臂：①注册一颗真热键（泵在 `g_hotkeys` 为空时早退，不注册就没测到那条路），塞「热键在队头、鼠标紧跟其后」一对再跑生产泵 ⇒ 鼠标必须还在、热键必须被取走**且 sink 真收到一条 hotkey**（「事件不见了」≠「事件被派发了」）；②撤掉热键再塞一对，泵必须一个都不碰（`empty()` 早退那条路）；③负向对照用修复前那版「任意事件」排空，它**必须**吃掉鼠标，否则这条自证没有判别力 ⇒ rc=2 未判定。**自带红臂**：把生产源里 peek 的 `false` 翻成 `true` 编一份临时副本（生产文件一个字不动），门必须随之变红。实测正向 `旧排空吃鼠标=1 生产泵留鼠标=1 留热键=0 热键送达=1 无热键时留鼠标=1 留热键=1`、红臂 `生产泵留鼠标=0 热键送达=0` ⇒ `HOTKEY-PUMP GATE OK（正向绿 + 红臂红）`。量的是**队列层**（down 有没有被摘走），不是像素也不是真人送达；前置产物缺、热键注册不上、红臂锚点不唯一、未知开关都 rc=2 | 是（gpui.o + 窗口会话） |
| 17 | 正常退出不段错误（#101） | `composer check:exit-clean`（= `bash cmd/check-exit-clean.sh`；CI 原生 job 里跟着热键泵门跑） | 交互 quit／关窗走「main 正常返回 → exit → `__cxa_finalize_ranges` → 静态析构」，而 bridge 的登记表里存着 PHP callable（`php::Variant`）：Zend 引擎先关、析构后跑 ⇒ `~Variant → zval_ptr_dtor` 摸已释放内存，SIGSEGV。**`--selftest` 走 PHP `exit()`，不跑这些析构**，所以十几支自检全绿也照不出这条路——真人一关窗就崩。免人手复现：`--automation <dir>` 放一条 `quit`，**目录每轮新建**（命令文件吃过就 unlink，复用旧目录会永远等下一号，那是夹具错不是门红）。门先 `build --no-native` 再量（失败的建设会留下上一版二进制），断 rc=0 **且**回执里 `commands=1` 与 `#1 quit -> bye` 都在（「没跑起来」不许冒充通过）；挂住超 40s 看门狗、产物建不出来、未知开关都 rc=2 未判定。红侧修前实测 hello／input 都 139，修后都 0 | 是（二进制 + 窗口会话） |

一键：`composer test:all`（Pest + PHPStan + 上游词表 parity + shim_test）。
全量示例跑一遍：`composer examples:all` = `bash cmd/all-examples.sh`
（清单就是 `examples/*/project.macos.yml`，新拆一支不用改脚本或 CI）。
示例与像素树数以目录及各产物的 `--sections` 为准，不沿用旧的总数与耗时。
改了 `cpp-src/` 之后第一遍用 `bash cmd/all-examples.sh first-native`（第一支完整构建，其余 `--no-native`）；
不要同时运行完整 sandbox 同步与依赖同一个 `gpui.o` 的 C++ 测试。
全量扫的像素行失败时会**当场分类**：打出 `现场=`（最多 3 棵超差树，形如 `✗ tree 18：gdfrac=…`），
再复跑一次并按结果标 `复跑绿＝抖动（仍计红，别当通过）` 或
`复跑仍红＝需单跑复核（可能是上下文相关抖动）`——紧邻的复跑会继承同一上下文，
**不能**据此判回归；13:45 `overlay` 那一例在扫里两次红、单跑 3/3 绿。
**复跑只分类、不豁免**——抖动照样算 FAIL；可复述的证据是打印出来的那一行
（`/tmp` 里的日志副本不保证留存，别把它当记录）。

> 在 composer 的脚本里写 shell 命令要用 `bash cmd/xxx.sh`，不能用 `@bash cmd/xxx.sh`：
> composer 只把 `@php`/`@composer`/`@putenv` 当内置前缀，`@bash` 被解析成"另一个叫 bash 的脚本"，
> 只打一行 `You made a reference to a non-existent script` 而 **rc 仍为 0**。
> `test:cpp` / `test:all` 里曾各有一处，于是本地一键门长期根本不编 C++（CI 另有独立步骤所以没漏）。
> 判据：跑完必须在输出里看到 shim_test 自己的分节名，看不到就是没跑。

## Pest（PHP 无头）

`tests/ElementTest.php` / `tests/AppTest.php` / `tests/SystemTest.php`，走 `FakeBridge`
（纯 PHP 替身，不碰 gpui）。
函数式 `test()` / `expect()`。覆盖每个工厂的 kind、children/ops 序列化、registered 标记、
枚举预校验。

## C++ shim_test

`tests/cpp/shim_test.cpp` `#include` 整个 `gpui_shim.cpp`，直接测其匿名命名空间里的纯函数。
headless、不开窗口，故任意环境可跑。`cmd/test-shim.sh` 两步编链（`-x objective-c++` 只作用
`.cpp`，链接 amalgam `.o`，不链 `libgpui_shim.a` 以免重复符号）。

它是 **bug #18（arena 字符串悬垂）** 的回归守卫：`AStr` 测试改写源缓冲后断言 arena 副本不变。

还有一条**上游 kind 表漂移警报**：`kKindNames` 当前 50 项；C++ 门排除 `Registered` 与
`ChildView` 后，钉住剩余未映射值为 14（批3 登记 accordion_header/trigger/panel 三件后
从 17 降到 14）。parity 信息行不作这两项排除，所以原始差集是 16。
两个数都不是“PHP 不可用功能数”：例如 DockArea 仍在差集中，却已有专用宿主 ABI。
上游增删 kind 或删掉表里已登记的条目会触发漂移门，要求明确决定是否暴露，不是强求每个
特殊 kind 都能通过字符串构造。

## selftest（原生二进制）

`hello --selftest` 跑真实编译产物，验证跨边界事件链。退出码 0 = 通过。二十一步：
1. 输入取值回环（setInput → InputSetValue → InputValue）
2. 点击事件全链路（合成 fire → gpuiEnqueueClick → boxForView → 队列 → poll → dispatch）
3. 定时器 tick
4. dialog 开/关计数
5. catalog 回调回环（DropdownMenu.item → InvokeWith → shimId 旁路 → 队列）
6. registered 错误回传（空 id Alert → registeredError 非空）
7. 原生 app 菜单条：安装（`setAppMenus` → `BaseSetAppMenus` → `appMenuCount` 读回）、
   表里装的 id（`menuRows()` → `AppMenuRowForId` + `ShellActionScriptId`）、动作回到 PHP
   （`dispatchAction` → `AppOnAction` → `action` 事件）。三条各自是门：读数打成
   `menus=1 · rows=st-new/rowsOk=true · action=true/dispatched=true`。
   为什么拆三段——原生菜单行的"真点一下"进不了门禁（`realClick` 只在进程内把 `NSEvent`
   投给自己的窗口，够不到系统菜单条那个窗口），所以"行里装对了 id"与"id 会回 PHP"
   必须分别取证。把 `action` 键写成 `id` 时 `rowsOk` 立刻红（实测反向对照 rc=1）。
8. 剪贴板回环（写系统粘贴板再读回，走 NSPasteboard 不是我们的缓存）
9. `openUrl` 白名单**被拒的一侧**（`javascript:` → false）。放行的一侧会真拉起浏览器，
   无人值守的自检不该有这种副作用；接受路径由 `tests/cpp/shim_test.cpp` 直接量
   `gpui::IsOpenableUrl` 钉住
10. 帧率 HUD 生命周期（show → visible → 重复 show → hide → 再 hide 为 false）
11. Sheet 生命周期（openSheet → hasSheet → closeSheet → hasSheet 假）
12. WebView（`ChildView` + host nested-view 注册真建出 OS 控件）。断言的是
    `webviewProbe()` 里**控件自己报**的数：`wry::WebViewUrlTemp` + `WebViewBounds` 给出
    布局实际应用给它的盒子（400×300），再加 back/forward 两次页内 eval 成功。
    OK 行末把那个盒子打出来，便于看出"存在但零尺寸"这种假通过。
13. 解压（`App::inflate`）：喂**外部真数据**——两串由开发机上 PHP 的 zlib 生成的
    "hello gpui" 容器（zlib 与 gzip 各一），不是 gpui 自己写的 stored 壳。坏数据返回 null。
    同一批向量在 `tests/cpp/shim_test.cpp` 与 `tests/SystemTest.php` 里也各解一遍，三方钉一处。
14. 目录监听（`dirChange`）：走 gpui 自己的测试接缝（`DirWatchAdd` + `DirWatchSignal`，
    `dir_watch.h:86` 明说这就是给测试留的），**完全不碰文件系统**，验的是
    "信号 → 主线程 post → 入队 → poll → dispatch" 整条路。OS 那一半（FSEvents/inotify/
    ReadDirectoryChangesW）是 gpui 自己的代码，不在本项目的验收面里。
15. HTTP（`http` 事件）：打 `http://127.0.0.1:1/` ——loopback 上必然没人监听的端口，
    于是 executor → 主线程 done → 入队 → poll → `httpResponse()` 整条异步路都走通，
    而**不碰外网**。断言的是"事件到了 + 应答是 ok=false/status=0 + 读第二次为 null"，
    不是"能上网"；`data:` 这类 scheme 在发送前就被拒。

第 15 步是**故意只验失败路径**的：无人值守的门禁不该有外网副作用，也不该有超时抖动。
成功路径靠 `HttpUrlIsRemote` 的判据表（C++ 侧）+ 需要时人跑一次。

16. 剪贴板完整一项（`clipboardItem`）：写过文本之后同一快照里应当只有文本——图像与文件
    必须报"没有"。这验的是 flags 判别，不依赖粘贴板里恰好有什么。
17. 系统状态（`App::sysInfo`）：`memPct`/`diskPct`/`memTotal`/`diskTotal`/`ncpu` 是直接
    读数，第一次采样就能断言非零；`cpu` 是差值量，所以先断言"第一次报 0"（这是文档承诺的
    行为，不是巧合），再烧 150ms 单核、泵两帧，第二次采样必须 > 0。烧单核在 8 核机器上约
    12%，离 0 有足够余量——不是靠机器噪声过关。`procs` 验前 5 条非空且首条有 pid 与名字。
18. 通知中心（`App::notify`）：推一条带 id 的通知 → `notificationCount` 1 → `dismiss` →
    泵帧后计数归 0，并且 PHP 侧收到一条 `close` 事件。验的是"推得进、退得出、回调打得回来"
    这条路由；卡片画不画得出来归 tree 32 的像素。
    **`click` 不在自检里**：合成指针打不到浮层里那张卡，所以它只有两条路——静态核对
    listener 接线（`OnItemClick` → `items[at].onClick`）与交互 demo 的 `Notify` 按钮
    （点卡片正文，状态行会报"卡片被点了"）。
19. mac 系统集成：托盘挂上→计数 1→撤掉→计数 0；注册一条 `cmd+shift+F13`，然后
    `fireHotkeyForTest(0)` **合成**一条 Carbon 事件，PHP 必须收到 `hotkey`。
    这一步顺手答掉了 Phase 10 的探针 A：我们的泵只有 `nextEventMatchingMask:`，
    所以 `gpui_shim_pump` 里自己排空 Carbon 队列（`ReceiveNextEvent` +
    `SendEventToEventTarget`）——合成事件能走完派发链，说明这条路是通的。
    **它不证明系统级全局捕获**，那一条在手工清单里。
20. 授权文件系统：`grantRoot('/tmp', true)` → `fsMkdir` → `fsWrite` → 用 PHP 自己的
    `file_get_contents` 读回来（gpui 写的 PHP 要看得见），再反向：PHP 写、`fsRead` 读
    （含 `\0` 的二进制要原样穿过）。未授予的 `/etc/hosts` 必须返回 null 且
    `fsError()` 说得出"根"。最后 `fsRemove(dir, recursive)` 把整棵树带走。
21. 进程与存储：白名单里的 `/bin/echo` 跑通并带回 stdout；`/bin/cat` 被拒且原因含
    "白名单"。存储没授权时 `storageSet` 必须 false，授权后 set/get/len 自洽。

## 宿主数据回路（批6：rows 回调的返回值通道）

批6 的 `cmd/patch-host-data.sh` 让 rows 回调（`render_rows` 等）能**返回真数据**——
补丁在 `CallInLayout` 顶部加 shimId 分叉：回调表项的 shimId 非零 ⇒ 宿主登记的回调
（`PushShimCallback`），走 `gpuiInvokeShimDataCallback` → bridge 的 `php::call` 同步
调 PHP handler → 返回值编组回填（`GpuiDataNode` 树 → `ComponentDataValue` → JS）。
四段式与 `cmd/patch-host-list.sh` 同款（宿主登记 → shim 分叉 → php::call → 回填）。

**怎么测**（kit selftest 的正/负路门）：
- `dataTableRowsCallbackSuccess`：注册 `render_rows` 处理器返回对象行数组 → 探针
  fails 不动（快照成功）、账本干净——"成功看得见"。
- `dataTableRowsCallbackLoud`：不注册处理器 → 探针 fails +1——"失败也看得见"。
- `candlestickChartRenderSuccess` / `sankeyChartRenderSuccess` / `searchableListRowRender`：
  三张新图的正路门（rows 回调返回真数组 → 图表/列表渲染无 registeredError）。
- 负路门（`*NoHandlerQuiet`）：不注册处理器 → Failure Div（不进 request->Fail，账本干净）。

**已知限制**：元素通道（`render_cell` / `render_row` 等返回**元素**的回调）未实现——
走 `BuildComponentElement` 的 shimId 分叉 → 非确定性 SIGBUS（DataTable paint 实证，
约 1/3 概率崩）。批6 只做数据通道（返回**数据**的回调）。

## `--clip`：把"能改粘贴板的东西"变成测试装置

图像与文件两条通道要有**真数据**才验得到，而自检不能依赖粘贴板里恰好有什么。
`hello --clip` 因此存在：开一个窗口、读一次粘贴板、打成一行 `text=/image=/paths=`
（`-1` = 该表示没有）。于是：

```
printf 'hello clip text\n' | pbcopy && hello --clip   →  text=16 image=-1 paths=0
（⌘⇧⌃3 截一张图）           && hello --clip   →  text=-1 image=<字节数> paths=0
（Finder 里复制文件）        && hello --clip   →  text=-1 image=-1 paths=1
```

本轮用 AppKit `writeObjects:` 脚本化写入验过全部四种组合（含文本+PNG 同时存在的一例），
结果记在[系统能力](/guide/system-capabilities#剪贴板三通道是这么验的)那张表里。
**别用 AppleScript 的 `set the clipboard to {POSIX file ...}` 验文件通道**——它写的是
alias list，不是 `public.file-url`，读回来永远是 `paths=0`，看着像代码坏了。

`promptForPath` **不在自检里**：原生对话框自带嵌套消息循环，一直等用户选完，无人值守会挂死。
它的手工路径在交互 demo 里（`Open…` 按钮，从点击回调中调用），见
[系统能力](/guide/system-capabilities) 的验收矩阵。

## `--automation <目录>`：从外部驱动真实窗口

`--selftest` 是"二进制自己驱动自己"，有些事它做不到——比如验证外部写入的命令在**真实窗口**上
产生了什么。`--automation <目录>` 补这一刀：`gpui run hello --automation /tmp/auto` 起窗口后，
往目录里写 `command-<n>.txt`，应用每帧吃一口，执行完把回执写进 `snapshot.txt`。没有端口、
不需要辅助功能权限、shell 脚本与 CI 都能驱动，进程退出也不丢会话。

```
mkdir -p /tmp/auto
printf 'widget-click inc\n'  > /tmp/auto/command-1.txt
printf 'key a a\n'           > /tmp/auto/command-2.txt
printf 'screenshot after\n'  > /tmp/auto/command-3.txt
sleep 2 && cat /tmp/auto/snapshot.txt
```

| 动词 | 作用 | 备注 |
|---|---|---|
| `widget-click <id>` | 按元素 id 合成一次点击 | 先试元素缝，不中再试 registered 缝 |
| `key <键名> [字符]` | 走**真键盘路径**发一键 | `a`/`tab`/`enter`/`esc`/`left`…或直接给键码；这条会产出真 `change` 事件 |
| `input <id> <文本>` | 直接往 retained 控件里设值 | **不发 change 事件**——只设值，状态驱动的界面不会因此更新 |
| `change <id> [文本]` | 设值（给了文本时）+ 合成一条 change 事件 | 补 #93 与"无会话环境"的空缺：这一路在 agent/CI 里只能靠它验 |
| `value <id>` | 把输入框/部件当前值读进回执 | 断言"写进去了没有"用这句 |
| `focus <id>` | 把焦点放到部件上 | 真侧目前返回 `-1001`、`focused` 仍为 `-`，焦点这一层仍是缺口（见 #62/#56）；作诊断用 |
| `clickat <x> <y>` | 按坐标合成一次真点击（与 selftest 的 `realClick` 同源） | id 缝够不着的部件（slot 按钮、catalog 日期触发器）只能按矩形点；#103 的判别臂用它。input 的 `--automation <dir> [height]` 第三参可加高窗，够到折线外的目标 |
| `hits` | 把这一帧命中表整张落盘 `<dir>/hits.txt` | 行格式 `i id x y w h click down drag`（tab 分隔，gpui 视图系坐标）；与同帧 `screenshot` 对照可分「产品命中错」与「合成缝坐标换算错」 |
| `screenshot <标签>` | 先强制渲染一帧再出 PNG | 标签净化过，产物逃不出目录 |
| `quit` | 退出主循环并写最后一轮回执 | CI 能跑完的前提，否则只能 kill 进程 |

回执 `snapshot.txt` 每处理一口命令重写一次（不是每帧），内容含帧数/渲染数、窗口状态
（`onScreen`/`key`/`visible`…）、`focused`、`lastError`、已消费命令数与每条命令的结果行。
命令按序号消费，**缺号先等**：驱动方看 `commands=` 不涨就知道丢了哪一号。

**启动者身份会决定 OS 投递是否为 0**（#96，同机同二进制实测）：agent 的 shell 是
`launchd ← 守护进程 ← .app` 这条链，**不在你的登录图形会话里**，于是窗口可以是前台、
也可以上屏，但 OS 键鼠投递恒 0（`appkitDeliveries=0`、`sessionId=0`）。所以
"真人按键"这一层必须由**用户在 Terminal.app 里启动**来验——`input --wait-key 8` 一行读数即可定性；
agent 与 CI 只能验到"进程内派发链"，这是边界不是缺陷。

实测（2026-10-20，`gpui run hello --automation`）：两次点击让 `Clicked: 2 times` 上线；
`input` 设值后问候语不变（因为没发 `change`），改走 `key a a`×3 后问候语变成 `Hello, abc!`
——这条链是真事件、真状态、真重渲染。无头侧的同名门在 `tests/AutomationTest.php`
（9 项，含乱序等待、标签逃逸、quit 停机）。

`input` 这一支也接了同一张嘴，且**单独用即可**：`php bin/gpui run input --automation /tmp/auto`
不需要搭 `--trace`。这一条是 2026-10-22 修出来的——它原先只在 `runInteractive()` 里读
`$argv`，而那道"未知开关必须拒绝"的扫描排在前面，于是单独用被自己的扫描拒掉（rc=2）。
开关名与实际读取的集合现在由一条静态门双向锁住（见门禁表第 12 行）。

## shotdiff（视觉回归）

`cmd/shotdiff.php`：`--shot` 抓真实在屏 NSView 出 PNG，与 `tests/baseline/<ex>/tree<N>.png`
逐像素比对（imagick RMSE，回落 gd，容差 0.001）。

- `php bin/gpui shotdiff record hello` —— 生成/刷新基线
- `php bin/gpui shotdiff check hello` —— 比对，超容差 rc=1

它**不构建**，所以第一步先挡"量的是上一次构建的产物"：二进制比它 yml `sources:` 里任何一份
输入旧就直接 rc=2 拒绝出数。这条 2026-10-14 加，因为当天真踩了一次——改了共享的 feature 源之后
`shotdiff record` 报"整批树的字节数与基线完全相同"，那看起来正好像
"这次改动不影响像素"，而真实原因是那支二进制没重编；record 模式还会把旧图再写一遍，把假绿坐实。
（同一族的老教训：`grep -c` 为 0 会吞掉 `&&` 链的后半条。）

**快照前指针归位（2026-10-15）。** `--shot` 拍的是在屏窗口的当前绘制结果，而悬停态与
Inspector 拾取高亮读的是窗口记下的指针坐标——窗口常常就开在物理光标底下，于是基线一度取决于
"鼠标那一刻在哪"：`embedded` 整片蓝/白翻转（0.67751）、`hello` tree29/31 整张不同（1.0）、
几支输入域的 0.002 级小差。`gpui_shim_snapshot` 现在拍之前做四步：窗口
`ignoresMouseEvents=YES`（挡住 OS 之后再发来的真实移动）→ 排空队列 → 经 `WindowDispatchInput`
**同步**把指针停到窗口外 (-40,-40)（那是 `win->mouseX` 唯一的写入点）→ 泵四帧。
经 NSEvent 路由停指针的版本实测 6 次出 3 种图，同步版 8/8 逐字节相同。
附带效果：多泵的几帧让 `hello` tree30 的 WebView 本地页**真的进了图**（旧基线那块是白的）——
再一次说明"WebView 离屏截图必然空白"是错的，它只是当时还没上屏。

**快照绑到哪一扇窗（#90，2026-10-15）。** 上面那段治的是指针位置，没治选窗：
`gpui_shim_snapshot` 当时用 `[NSApp keyWindow]`，拿不到就退化成 `[NSApp windows]` 的第一个，
**传进来的窗口句柄根本没参与选窗** —— 前台归谁是 OS 仲裁的竞争态，于是 `hello` tree29/31
一度整帧不同（`gdfrac=1.00000`）。现在它经 `cmd/patch-window-handle.sh` 新增的上游
`WindowNativeHandle(Window*)`（定义只能落在看得见 `PlatWindow` 完整定义的那个 TU，
返回 `(__bridge void*)` 的原生窗口）解析出**传入那一扇自己的**窗口，解析不到就失败，
不再随手拍另一扇当标准答案。判别臂在 `hello --selftest` 的 `snaparm`：同进程再开一扇
420×320 的窗，各按句柄取帧，断两张 PNG 的 IHDR 宽度不相等（自检里两扇画同一棵树，
只有尺寸能区分）。两侧都实测过 —— 旧选窗 `A420px/B420px` 且 rc=1（两次拍到同一扇），
修后 `A400px/B420px` 且 rc=0。

**焦点/HID 依赖的门必须写成"前提式"。** `focusedId()`、`focusTrap()` 这类读数要成立，
前提是这扇窗真当上了 key/main window；托管 runner 上两次同 commit 的读数都是
`active=false / key=false / main=false`、`focus=0`，所以任何直接把"焦点等于那颗"当硬断言的门
在那台机器上**必红**，且红的含义不是回归，而是环境不给焦点（那属真人 HID 那一格）。
写法与 `bundled`/`routeGreen` 同形：前提成立才断言，前提不成立在读数里打
`applicable=false` / `focusHeld=false` 记"未验"；不依赖焦点的那一半（`focusElement()` 句柄非零、
`trap !== 0`、`elementValue()` 回读链）照旧硬断。
**并且要回验前提成立时断言仍完整执行**——否则"未验"会悄悄变成"永不执行"。
本机三条都这样量过：`focusFollowsClick … applicable=true`（六颗各异）、
`focusTrap … trap=993756471 focusedId=993756471 focusHeld=true`、
`openChangeDelivered … 持有焦点=true`，各自 rc=0。

**但 `active/key/main` 只是应用自己的说法，不是物理输入能否到位的判据。** 实测（本机，2026-10-15）：
`--wait-key` 全程 `active=true key=true visible=true main=true`，而 WindowServer 的**在屏账本**
里没有这扇窗（`CGWindowListCopyWindowInfo(OnScreenOnly)` 零条，`All` 里那条缺 `kCGWindowIsOnscreen`），
同一会话里一个 60 行的普通 AppKit 程序却在屏——物理键鼠只按账本派发，于是真人按键零送达。
所以 `windowState()` 另有两格：`onScreen`（按窗口号查在屏账本）与自证臂 `onScreenPeers`
（同一账本的在屏总数；非零才说明 `onScreen=false` 是判决而非查询失效，实测 `false` / `37`）。
判"人为什么按不动"看 `onScreen`，不要看 0..3。
**同一件事对像素门的含义**：`--shot` 走 `[view cacheDisplayInRect:]`，是进程内渲染，窗口不在屏
也照样出正确 PNG——像素证据从不证明"用户看得见"，它和真人 HID 那一格互不背书。

**"到底能不能上屏"有一条可重跑的判据：`bash cmd/onscreen-probe.sh [示例]`。** 它在**同一秒**并跑六颗程序
——三颗程序（纯 AppKit 对照 / gpui + 我们的 shim，零 PHP / 我们的完整产物）各跑两种启动方式
（从目录裸 exec、从 `.app` 里 exec）——只查 WindowServer 的在屏账本：
`0` 六格全在屏；`1` 只有 bundle 那一列缺格（= #56，可复现，可交付路径是裸二进制）；
`2` 连裸跑那一列都缺格，或纯 AppKit 的 bundle 格也缺（先修主机/会话，别查代码）；`3` 前置不满足。
为什么必须是矩阵：这个状态会成段地翻，跨时刻的单次对比已骗过我三次；而**同秒也不够**——
上一版探针把"零 PHP 那颗裸跑"和"完整产物从 bundle 跑"当对照，一次比了两个变量
（有没有 PHP × 从哪儿启动），由此得出的"缺陷在 nano/PHP 运行时"已在 2026-10-15 23:05 作废。
本机四组同秒读数的形状：纯 AppKit 两列都在屏；gpui 零 PHP 裸列全在屏、bundle 列 2/4 缺；
完整产物裸列全在屏、bundle 列 4/4 缺（进程内自报 `onScreen` 与账本逐格吻合）。
它**不进 CI**——托管 runner 上没有可合成的 GUI 会话，在那里恒为 `2`。
**物理鼠标点击的边界（2026-10-17 实测，已知限制）**：本机"裸窗口 + 远程会话"下，物理
`leftMouseDown` 能到达本进程（本地监视器计数增长），但**到不了 gpui 的视图**
（`-[GpuiView mouseDown:]` 里一条探针都不打印）；物理键盘不受影响（走 key window 的 first responder）。
所以依赖鼠标命中的真人验收（如拖分隔条）在这条链路上做不出记录——
用 `App::realClick()/realDrag()` 的合成判据，或换一条不经过远程会话的显示链路再补真人记录。

**复跑变绿只够推迟定罪，不够结案。** 先保存并查看失败图，核对构建新鲜度，再控制内容与指针复测，
用日志、崩溃报告或同一量的探针辅助归因。“三次变绿且无 crash”不能证明只是渲染抖动。
`embedded` 曾出现 `gdfrac=0.67751`：红图右侧 WebView 空白，绿图却有外网页面正文，
根因是网络加载时序，不是抗锯齿。不要为了变绿而重录基线或提高容差。

这条门曾抓到 dialog double-free（tree13 未关就 close → SIGABRT）。各树的边界另列：

- hello tree24 有 MenuBar builder，但不在固定基线表；宿主 MenuBar 集成已修，不能再用历史
  root-app 报错解释当前状态。系统菜单条与窗口内容也不是同一截图对象。
- tree29 是 Inspector 拾取高亮；真正的面板在 tree31。
- hello tree30 **仍存在**，embedded 另有独立 tree1。跨进程 WebView 表面能进图，
  本地 HTML 也已实际进图，不能说必空白；它不证明任意远程页面加载成功。
  embedded 像素/自检路径已换同 id 同几何的离线 HTML，真实地址只在交互模式加载。
- tree27 的 HUD 资源行（原 **#86**）已修：根因是 `runShot` 在 `showFpsHud()` 之后只泵 3 帧，
  而 CPU/MEM 那两行要等采样器凑齐**第二次**样本才画——行数一变框就从 132 长到 150，
  拍到哪一态取决于墙钟（改前同一支二进制 5 次出 5 张不同图，与基线差 0.01298）。
  改法是泵到稳定态（120 帧）再拍：5 张同尺寸、两两只差 0.00013~0.00023（HUD 上的实时数字，
  容差 0.001 之内），`check hello` 三遍全绿。**指针稳定不等于资源采样稳定**——这条当时被误当成
  鼠标抖动，靠加容差或重录是掩盖不掉的。
- 新开的一条同类风险（**#90**）：tree29/31 会**交替**报 `gdfrac=1.00000`（整帧不同，不是小残差）。
  两棵树都只 `toggleInspector()` + `loop(3)` 就拍，而同一函数里 tree32 的注释已经写明"只跑 3 帧
  等于拍在动画的 0 上"。撤掉一次顺手误重录的基线后连三遍又全绿，所以它是间歇性的。
  处置边界：1.0 级的差异**不许**用重录或加容差解决，必须先抓到那一帧看过、判定是"面板没挂上"
  还是"拍到了别的表面"。
tree 32（通知层）**必须跑够墙钟才拍得到**：通知入场是动画，`Starting` 段的不透明度
= `elapsedMs / 400`（`ui/notification.cpp:948`），而推它的是 50ms 的墙钟计时器——只泵
3 帧等于拍在 0 上，图上只有宿主标题、两张卡一片透明，`SHOT OK` 却照样成立。现在跑
80 帧（约 1.6s），并且与 600 帧的产物逐字节相同，说明拍到的是动画走完的静止帧。
两条通知故意分放 `top_right` / `bottom_left`：同一角会叠成 collapsed stack（新的在前、
旧的只露一条边），分开才两张都拍全，顺带把 `placement` 的锚点映射钉在像素上。

## CI

`.github/workflows/ci.yml` 定义四个 job。下表是配置与历史耗时，**不是当前工作区/HEAD 已通过 CI 的证明**；
当前版本的运行日志、bundle 路由及可用 GUI/self-hosted runner 仍需分别核查。

| job | 跑什么 | runner | 实测 |
| --- | --- | --- | --- |
| `php` | `php -l` 全源 + Pest + PHPStan | ubuntu-24.04 / PHP 8.5 | ~14s |
| `docs` | `npm ci` + `vitepress build`（顺带是死链门禁）+ 产物页数与 `base` 断言 | ubuntu-24.04 / Node 24 | ~12s |
| `native` | `bin/gpui build hello`（同步沙箱→全部补丁→amalgam→shim 库→tpc nano）+ `cmd/check-cxx17.sh` + `cmd/test-shim.sh` + `hello --selftest` + **Domain examples 循环**（`examples/*/project.macos.yml` 里 hello 之外每支 `build --no-native` 再 `--selftest`） | **macos-15** | ~3m27s + 每支约 40 秒 |
| `deploy` | 把 `docs` 的产物发到 GitHub Pages（只在 main 的 push） | ubuntu-24.04 | ~45s |

各支 `--selftest` 里还带**只认真实输入**的验收：`realClick` / `realMouseMove` /
`realKey` 把事件交给 `-[NSWindow sendEvent:]`，于是 AppKit 的 hit-test、materialize 的
behavior 解析、focus 迁移全部真的走一遍；`inputProbe()` 再证明 paint 里登记的是真 Input。
导航那条是**命中表驱动**的：先用 `hitProbe($i)` 量出 13 颗按钮各自的矩形，再逐颗点自己的中心，
断言"13 条矩形、13 次进队列、到达 13 个不同节号"——按 y 猜行位置会把四种不同的错
（表没登记 / 坐标换算错 / 监听没挂 / 表外的原生子视图挡着）统一伪装成"那一行点不动"。
为什么需要它：自检原有的合成事件按 callbackId 直接入队，绕过"监听器挂没挂"这一步，
所以 `on_` 前缀失配（真实鼠标一个事件都进不来）时它照样全绿。

`realDrag($x0,$y0,$x1,$y1,$steps)` 是这条缝的"按住再拖"档（`gpui_shim_real_mouse` 的
type 2/3 = 只按下/只抬起，中间按段发移动并在每段后跑一帧）。它回三个读数
`{pressed, moved, released}` 而不是一个 bool，因为这两类语义在这个动作里不是一回事：
按下/抬起回的是"**送进窗口了没有**"（把手上没有 PHP 回调，拿入队当判据会让每次拖都假阴），
`moved` 沿用 `realMouseMove` 的既有语义 = "**PHP 队列多没多一条**"。
它的边界也记在这里：mac 层判断"此刻按着哪个键"读的是 `[NSEvent pressedMouseButtons]`
（物理状态，`window_mac.cpp:511-520`），合成事件伪造不了，所以**拖不动 resizable 的把手**——
`selftest layout` 的 `resizeDelivered(读数)` 量的就是这个（`按下=true 抬起=true 移动入队=0`）。
需要真按住的东西归 #56 那一族（真人 HID）。

画廊的换节也走真点击（`navClickTo()` 从命中表取那一行的矩形再点它），不再用
`ExampleState::$v['section'] = N` 直接改状态——改状态等于绕过 hit-test 假装"点过了"。
那条常驻原生视图吃点击的**回归位**今天住在 `examples/embedded`：`clickAfterWebView`
先画 WebView、再换走、再点原来那块区域，点不到就 rc=1。它当初是被反向对照选出来的——
把"这帧没渲染到就隐藏"掐掉之后，画廊那条导航门红成 `afterAll=7/13`，而当时那条打字往返
（点进挂原生视图那节再点回来打字）仍然绿。一条门能不能抓住房 bug，只看它被否掉时会不会红，
不看它长得像不像。

按功能域拆出去的独立示例（`ls examples/` 里 hello 之外的那十三支，名字就是域——
2026-10-28 起多了 kit：tier-A 工厂批那 16 组组件的门住在它那支 `--selftest` 里）
各带一支自己的 `--selftest`，CI 里由 `Domain examples`
那一步按 `examples/*/project.macos.yml` 自动发现并逐支编译（`--no-native`，只跑 tpc 那一段）+ 跑门。
它们的分工是：**同一份 UI 源**（`examples/_shared/features/*.php`）被各支的 yml 各自编译，
所以不存在第二份实现；而"域自己的断言"住在独立那支，因为总报告只在末尾打印一次，
域的门一崩，外部看见的只是退出码非零、一个字没打——独立那支是边跑边打的，崩在哪行停在哪行。
各支 `--selftest` 那一圈是表里「示例覆盖率与拆分漂移」那道的运行时那一半：`tests/ExampleCoverageTest.php`（在 `php job` 里）
反射出 Element/App 的每个公开成员、断言**任一示例**真调用过、并静态核每次调用的实参个数；
二进制再证明这些调用在原生侧不产出错误节点。**像素那一半不进 CI**——
`php cmd/shotdiff.php check <域>` 那批基线要抓真在屏 NSView，托管 runner 没有窗口会话，
与 hello 的 18 张同理，只在本地跑。

**表里 `C++ shim_test` 与 `selftest` 那两道现在真的进 CI 了**——前提是 gpui-kit-cpp 已 vendored 进本仓库
（`upstream/gpui-kit-cpp`，构建时由 `cmd/sync-upstream.sh` 复制成沙箱 `build/gpui-kit-cpp`，
补丁与 amalgam/out 都落沙箱，跟踪树不被弄脏）。这一跑同时答了一个没把握的问题：
**托管 macOS runner 能开真窗口**——`--selftest` 的 21 条链路里有窗口、有事件、有 retained
取值，它绿了。

**门禁 5（`--shot` / shotdiff）仍只在本地**：它抓的是真实在屏 `NSView`，CI 上没有可依赖的
窗口会话与显示，硬跑会得到"能出图但图是空的"这种最坏证据。这一刀归人。

## 手点没反应时先分这一刀（不需要任何工具权限）

门禁全绿而人点不动，通常不是同一件事。按这三步走，5 秒内就能分出来：

1. `php bin/gpui run input --trace`（默认跑裸二进制，那正是窗口可合成的一路；
   要验 bundle 身份才显式加 `--bundle`）。
2. 跑 `php bin/gpui run input --wait-key 15`：开局打一行 `WAIT-KEY-STATE`（完整
   `windowState()`），窗口里逐帧画 `active / key / 在屏 / 焦点 id` 读数，结束时
   `WAIT-KEY-VERDICT` 自己给结论——没有 HUD，别再找"焦点 前台/非前台"那一行
   （那是已删画廊的界面）。
3. 点一下任意输入框、再敲几个字，然后看 `tail -f /tmp/input-events.log`：input 域的
   元素 id 基本都以 `in-` 开头（如 `click id=in-name` / `change id=in-name`），例外是
   NumberInput 的 ± 两颗按钮 `num-dec` / `num-inc`，别拿"全是 in-*"当判据。

读数怎么解释：

- `WAIT-KEY-VERDICT` 报 **onScreen=false / 事件增量=0**：窗口不在 WindowServer 的在屏
  账本，或 OS 没把事件送进本进程——这只能定位系统状态这一层（#56 那一格），不能一概
  判成监听器断线；`acceptsFirstMouse=YES` 也不能证明鼠标、hover 与首次激活不受前台
  状态影响。
- 事件有增量，而日志里连 `click id=in-*` 都没有：那是真派发链坏了（命中表、
  监听器、或被原生子视图挡住），用 `App::hitProbe()` 那一节的方法查。
- 日志里有 `click id=in-*` 但画面没换：路由到了 PHP，问题在状态或每帧回调，不在输入链。

`--trace` 落盘的只有真到达 PHP 的事件，所以它是"到了 / 没到"的硬证据，比看画面猜可靠。

## 下一步

- [gpui CLI](/guide/cli) 看这些命令的入口
- [贡献指南](/contributing) 看补丁工作流
