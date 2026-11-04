# 测试与门禁

门禁清单如下（数目以本表为准，别在别处再抄一份数字——以前"六道/七道"就和表不同步）。
改代码后按此清单跑；改 stub/C++ 后**必跑原生 selftest**（PHP 层门禁抓不到 AOT 运行期问题）。

| # | 门禁 | 命令 | 覆盖 | 依赖 gpui？ |
|---|------|------|------|------------|
| 1 | Pest | `composer test` | PHP 声明层：Element 序列化、App 路由、FakeBridge | 否 |
| 2 | PHPStan | `composer analyse` | `src/` 类型/静态（level 5） | 否 |
| 3 | C++ shim_test | `composer test:cpp` | shim 纯函数：kindFromString / AStr arena 拷贝 / toComponentArg schema / buildComponentArgs / ComponentKind 漂移警报（排除 Registered/ChildView 后未映射值钉在 17） | 是（gpui.o） |
| 4 | selftest | `php bin/gpui selftest hello` | 21 链路端到端：输入取值 / 点击 / 定时器 / dialog / catalog 回调 / 错误回传 / app 菜单 / 剪贴板 / url 白名单 / HUD / Sheet / WebView / 解压 / dirChange / http / 剪贴板完整一项 / 系统状态 / 通知中心 / mac 集成 / 授权文件系统 / 进程与存储 | 是（二进制） |
| 5 | shotdiff | `php bin/gpui shotdiff check hello` | 视觉回归：逐树像素比对基线（18 棵）。tree30 的 WebView 出图前先泵到区域平均色变成 HTML 的蓝（`App::regionRgb` 内容判据，采样落在平整蓝区避开圆角）：帧数预算不可靠，120 与 600 帧都曾拍到整块空白；泵不出来照拍并打印 `painted=0`，红得可诊断。tree29/31 出图前 `App::setInspectorPicking(false)`——拾取高亮画在真实指针最后命中的那个框上，不关它整张图就取决于鼠标当时停在哪儿；inspector 开合本身改由 `--selftest` #26 的 `App::inspectorEnabled()` 硬断（读数 `insp=1/1/0`）。红帧自己落盘到 `tmp/shotdiff-fail/<示例>-tree<N>-<时间戳>-<md5前 8>.png` 并把路径打进 ✗ 行——以前只把分数打在终端，偶发帧在下一遍就被覆盖，#106 因此连 20 遍才红 1 次还抓不到证据 | 是（二进制） |
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
| 18 | token 徽章像素判别臂 | `bash cmd/check-token-paint-png.sh` | kit `tokenDeliveryRender` 门（硬门仍在 selftest）的**第二条色通道**复核：selftest 在 ON 帧在屏时 `App::snapshot()` 离屏出图（`sys_get_temp_dir()` 读环境 TMPDIR——脚本侧必须 `${TMPDIR:-/tmp}` 同源，写死 `/tmp` 会报"没出图"），脚本用 PHP GD 按 selftest 同一网格找红徽章（GD 判读不进 AOT 编译域——examples 无 GD 先例）。出读数不出门红：PNG 有红=合成帧里有徽章（**A2 修复后的期望态**，2026-11-01 实测 有红@(42,44)，与 selftest 的 regionRgb 硬门同片像素）；**PNG 无红=徽章没进 paint/合成帧**——那是修复前（2026-10-31）实测到的缺陷态，如今出现即 A2 回归，回归红由 kit `tokenDeliveryRender` 承担。selftest 门红/没出图/PNG 尺寸≠420x200 都 rc=2 未判定 | 是（二进制 + 窗口会话） |
| 19 | always 滚动条真画探针（前提式） | `bash cmd/check-scrollbar-paint.sh` | "scrollbarMode always 的 thumb 真画出来"的 `--shot` 通道验收：kit tree4（`sectionKitScrollbarPair`，hover/always 两盒同构 w220/h120/gap40/p40，探针 (244,48)/(504,48) 右缘 16x60 竖条——**builder 必须带 `p(40)`**，首版漏了盒从 (0,0) 起、探针读到根底色）。断两盒区域均色互异；同色 ⇒ **applicable=false rc=2 未判定**（2026-10-31 实测同色 rgb(223,223,223)：离屏合成也不画 always thumb——"无真人鼠标事件 NSScroller overlay 自动隐藏"对 --shot 同样成立）。不设 rc=1：没画出滚动条不是回归红，是前提不成立；出不了图/PNG≠900x680 也 rc=2。"always 真画"目前只能真人 HID 前提式验收 | 是（二进制 + 窗口会话） |
| 20 | kit 门批量入口（可达性收口） | `composer check:kit-gates`（= `bash cmd/check-kit-gates.sh`；CI 原生 job 一步）／`composer check:kit-arms` | 五道 kit 域门此前**只有文档没有入口**（composer / CI / tests 都不点名它们），写好的门没人跑等于没有门。按 rc 语义分两把：`check:kit-gates` 收有"红=回归"这一档的**五道**（`cmd/check-accordion-toggle.sh` = v2 H3、`cmd/check-token-link-hits.sh` = v2 H5、`cmd/check-click-survival.php` = 广度逐颗点击，2026-11-03 补、`cmd/check-scrollbar-hover-hide.php` = #112 回归位 hover thumb 必须能隐藏、`cmd/check-dialog-open.php` = #110 回归位 WindowState 插件在册，2026-11-04 补），任一 rc=1 总 rc=1，否则有 rc=2 总 rc=2；`check:kit-arms` 收只有"实证(0)/前提不成立(2)"两档的三条（`check-scrollbar-click.sh`、`check-scrollbar-paint.sh`、`check-token-paint-png.sh`），**不进 CI**，免得把"CI 没给条件"读成回归。两把都逐道打自己的判定行再打 `GATE/ARM <名> rc=`——**包装器 rc 不是门判定**，结论读每道门那一行；kit 二进制缺、未知开关都 rc=2 未判定 | 是（二进制 + 窗口会话） |
| 21 | 能力对照表不许与代码打架（两个方向） | `php cmd/check-doc-claims.php`（`composer check:doc-claims`；CI docs job 里紧跟锚点门，先跑 `--self-check`） | 同一判据的正反两面，共扫四张表。**A 少报**：`upstream-coverage.md`（中英）"上游概念对照"表里带缺口标记（❌/未接/not exposed/not supported）又反引号点名了一个**真实存在的 `El`/`Element`/`App` 公开方法** ⇒ 红，打 file:line 与方法全名。起因是 2026-11-02 实测到的自相矛盾：第 3 节写着动画糖已接（`El::transition()`/`El::spring()` + kit `transitionInterpolation` 门），对照表里却还留着 `Animation ❌ transition/spring 整族未接`。**B 多报**（同日补）：`system-capabilities.md`（中英）「已接」/「Connected APIs」表**第一列**签名的方法在代码里根本不存在 ⇒ 红；带前缀的（`El::x()`、`$app->x()`）按那个类判，裸名按三张表并集判，`App::a/b/c` 这种斜杠清单逐成员判。判据刻意窄：A 不扫"端口里有、还没接"那类表，因为那里把**已接**的方法当对照上下文写进同一格（deflate 那行的"解压侧已经接了（`App::inflate`）"），扫它必假红；B 只扫第一列且只认调用形（名字后紧跟 `(` 或 `/`），所以第二列的 C++ 符号与 `move_to` 一类上游名不误红。窄界是量出来的：把 A 放宽到两份 coverage + 两份能力页 + components + events 的**全部**表格，多出来的命中只有一条——`upstream-coverage.md:181` 里路径 op 名 `close` 恰好等于真方法 `App::close()` ⇒ 放宽就是 1 条假红，所以 A 停在概念表。正向读数 `DOC CLAIMS OK — 扫 4/4 张表（A:21 A:20 B:34 B:34 行），方法并集 587 名`。红臂实测（逐条往真文档插一行再撤销）：插 `El::notARealSurface()` ⇒ `FAIL (1 = 少报 0 / 多报 1)`；插 `El::clipboardText()` 加 `$app->notHere()` ⇒ `多报 2` 且分别打「El 类没有」「App 类没有」（跨类误配判得出）；A 侧插裸名与带前缀两种写法各 ⇒ `少报 1`；负对照插 `shell::ZlibDeflate` 与 `move_to` ⇒ 仍 rc=0；前提臂把 zh 标题改成「接通清单」⇒ rc=2 未判定并打「『已接』表里没读到任何行」，撤销后回 rc=0。`--self-check` 是探测器自检：A、B 各内置一对样本（矛盾必须判红、合法必须不判红） | 否 |
| 22 | 逐颗点击不许带走宿主（广度门，#111/#110 那一类） | `php cmd/check-click-survival.php`（`composer check:kit-gates` 批量入口的第三道；CI 原生 job 一步；`--tree <1..4>` 只扫一棵，红臂复测用） | 具名门（`accordionRealClick`／`linkRealClick`／`checkboxRealClick`…）各守一颗控件，它们全绿只说明"写过关的那几颗活着"。#111 的根因是上游把 content 实体**裸转**成 `ScriptView` 后读首字段（`Entity<T>::Get` 无类型校验，`gpui.h:6830`），命中范围是 `component_shell` 整个家族——2026-11-03 实测同一棵树 18 颗可点控件 **8 颗 SIGSEGV**，多数没人写过关。本门把四棵树里每颗 `click=1` 的控件**各起一个 automation 会话**点一遍：坐标从当轮 `hits.txt` 现算，只按"宽度≥8"筛、**不看高度**（h=0 的行照样吃得到点击，修前崩的正是那批）。硬判据两条：每颗会话 rc 必须为 0（139=SIGSEGV／134=SIGABRT），且 stdout+stderr 不得出现 `belongs to a superseded render` 或 `window state is missing` 这两条签名；其余 `shell:` 告警只计数不判红——这是本门自己声明的边界。"点了没反应"不归本门（那要先有 PHP 处理器才有读数，由具名门负责）。未判定面（rc=2）：命中表**一行都没有**（探测没量到东西）、全树零可点（本轮没有一棵树证明提取链路通）、产物缺、未知或缺失的 `--tree` 值；**模态等待会浮动**（2026-11-04：树 3 的 NativeMenu 触发器行同一天一次堵成 rc=2、单树复跑 11/11 rc=0——模态与否随会话状态浮动，rc=2 的正确用法是"重跑一次并如实记两头"，不许无声重跑到绿）；命中表**有行但全 click=0**（树 4 本就是纯文本滚动盒）＝量出来的空集，按通过计——前提是同轮他树量到 >0 颗（单树 `--tree 4` 跑给不了这个前提，实测 rc=2）。正向读数（2026-11-04 产物）：`CLICK-SURVIVAL OK（判 59/60 颗全活着、无致命签名；1 颗模态等待点名另计——进程在 OS 菜单跟踪里活着，非缺陷；树 4 可点 0 颗（量出的空集，同轮他树已证链路通））`。**模态等待语义演进（2026-11-04）**：批19 给触发器行登记真 action 后 NativeMenu::Show 必然成功，automation 没人关菜单 ⇒ 树 3 的菜单触发器行每轮必堵——它对"有没有把宿主带走"的答案是"活着"，点名另计 rc=0；rc=2 只留给命中表空（批18 的"重跑一次记两头"在必现面前失效）；红臂＝把 `PhpView` 首字段换回 `Box2*` 后同门在树 2 判红（实测该臂下 18 颗里 8 颗 rc=139） | 是（二进制 + 窗口会话） |

**2026-11-03/04 真人 HID 轮的账（电脑控制 + 真人六步，读数全部以 pty 的 KIT-EVENT/KIT-HOVER
与全屏截图为准；"a11y 缝"= macOS 辅助功能 AXPress → 上游 `accessibilityPerformPress` →
`WindowAccessibilityPerform`，是 fire 缝、真鼠标缝之外的第三条机器侧通道）**：

- **H3 手风琴：过**。真人真点「第一节（点我翻页）」→ `KIT-EVENT accI0=open`；a11y 缝点同一颗
  再点「第二节」共 6 次事件双向翻转（`accI0=open→closed→accI1=open`）。真鼠标缝、a11y 缝、
  fire 缝三条通道都能把 on_change 送进 PHP。
- **H6 悬停非零侧：过（首次实测）**。真人光标停进「悬停我」→ `KIT-HOVER hoverId=4`（非零），
  移出回 `hoverId=0`。合成缝喂不出的那一侧（批7 记账）被真人缝补上了，#106 色态判据的
  headroom 从此有真人实测背书。
- **H2 Resizable：终态过，连续性未逐帧测**。真人按住把手拖动后分隔条停在
  面板甲 ≈335px（默认 `panelSize(180)`、range 上限 320——顶到钳制位），松手后保持；
  "随拖连续变化"只有真人单方陈述 + 终态像素，没有逐帧证据，如实记账。
- **H4 scrollbar always：主断言过 + #112 复现**。真人悬停 always 盒滚动 → 内容真滚了
  （首行从 第01 变 第04）且右缘 thumb 真画出（截图）；同一帧里 hover 盒**无人悬停**却
  挂着 thumb、隔 4 秒再拍仍在 ⇒ **#112（hover 盒滚动条常驻）在树 4 也复现**；
  "thumb 随滚动移动"未做两帧对比，记边界。
- **H1 NativeMenu：#115 已修（2026-11-04 批19），真人最后一跳待验**。批17 立账后读出的
  死点：行选中派发 `OnDispatchAction → WindowDispatchAction` 只查两处——元素级 OnAction
  （触发器的行是 carried Entry，不是活元素，没有监听）与 app 级动作表（登记入口
  `registerMenuAction` 只服务 app 菜单条，触发器路径从不登记）⇒ 两处都不中即静默丢。
  `patch-native-menu-action-reg.sh` 走 weak-seam 惯例把每行 action 登记进 app 表；
  回归位 = selftest 步 `nativeMenuActionDelivery`（红臂关登记 false/无送达，绿臂
  true/delivered）。NSMenu 模态会话里的真人那一跳（点行）待下一次真人轮确认。
- **H5 link 半边：#116 已修（2026-11-04 批18）**。批17 的"两条缝分叉"框架是**错的**：
  探针逐层下钻（up-gate→OnTextLink→DispatchString→shim 路由→box 入队 `found=1`）发现
  C++ 侧整条链**真鼠标与合成完全同一条**，事件死在最后一厘米——wireKit 用 op 长名
  `on_link_click` 注册处理器，而 bridge 的 `eventType()` 剥掉 `on_` 前缀、队列事件叫
  `link_click`，`handleEvent` 查 `handlers[$id][$type]` 落空即静默丢。修复：wireKit
  注册名改短名（kit.php，一处）；`cmd/check-token-link-hits.sh` 加第三判据
  （**PHP 处理器回声** `KIT-EVENT tokClick/linkClick=delivered`，红臂实测：还原旧注册名
  rc=1、修复后 rc=0）——fire 返回值只证明 C++ 半路入队，处理器回声才是"到了 PHP"。
- **H5 token 半边：#117 撤账，真鼠标命中仍挂真人清单**。批17 把「提及」chip 记成缺陷是
  **误判**：那颗 chip 是 token 渲染回调的**标记物**（`El::text('提及')`，宿主渲染正路的
  可辨读标记，kit.php 的 `tokenRenderNull` 对拍才回落 label），文字本来就不该是 @张三。
  真正剩下的实测障碍：h=680 命中表里 token 输入框与 chip 的行 **click=0 down=0**（鼠标
  惰性），真鼠标点 chip 走不进命中链 ⇒ token 真鼠标命中继续挂真人清单（#96 边界）；
  "无法输入"已定性（2026-11-04 批22/23）：**键入链路是通的**（clickat 进输入框 →
  焦点 -1001 落对 → key a/b/c → `value` 读到 "@张三abc"）；**渲染也是通的**——
  批23 的探针把文本 run 的绘制链走全了：发射正常（slice 渐长）→ 布局正常
  （'abc' 20.7x20 紧跟 chip）→ **绘制调用真执行**（TextLayoutDraw：x=49.9、
  line0w=20.7、色 10/10/10/255、recording=0、裁剪全窗），且 **selftest 实时循环的
  regionRgb 在文字区读到深色字形**（均色 246→198）⇒ **#118 前提被推翻**（文本 run
  不绘制）——automation 截图/rgb 的"字没画"是**显示缓冲事件后不同步**的老假象
  （该限制早已在册）。回归位 = selftest 步 `tokenTypingPaint`（实时循环里真点真键，
  regionRgb 断文字区均色 <200；红臂不键入 ⇒ 底色 246 ⇒ FAIL）。真人"无法输入"的
  剩下的解释是**视觉可供性弱**（chip 主导、输入框边框不显眼），不是渲染缺陷。
  批22 同轮修掉 `value` 动词必崩（`$ev` 类型推断陷阱，见动词表）。
- **#113 已修（2026-11-04 批21）**：树 1 的路径绘制红绿三角压住「第一节」文字。真机制：
  bridge 的 path 分支（gpui_bridge.cc）追加 rest ops 时**只放行 method 类**，`param_style`
  （w/h）被静默丢弃 ⇒ path 元素恒 0x0 布局（`element->Bounds()` 宽高为 0），绘制命令相对
  锚点画 90px 直接压住下一行内容。修复 = path 分支补 param_style 放行（与普通元素同一条
  gpui_shim_param_style）。回归位 = selftest 步 `pathOverlapGate`（带尺寸 path + 红底块
  下方：修复后上点不得红/下点必须红；红臂实测吞 op 后上点 (204,2,2) 红 ⇒ FAIL）+ `pathScrollGate`
  （path 随容器滚动，滚后原位净/新位蓝）。shotdiff 基线不变（path 段在 680 折叠线下）。
- 过程账（不冒充结论）：CUA 对 gpui canvas 的应用内截图与坐标动作被
  "no usable pixel-to-pointer rectangle" 帧门挡死（授权全给也拿不到像素矩形）⇒ 本轮用的是
  a11y 元素点击 + 键盘（`{name,pid}` 形状才被解析）+ 宿主 `screencapture` 截屏 + pty 读数
  的混合法；共享机器上注入事件与真人操作互相打架（发进菜单的 Return 落空）。

### 真人 HID 复验清单 v2（批4 C1）

门禁表量的是机器可判的部分；下面六项的**期望证据只能真人手做**。每项格式 =
前提 → 操作 → 期望读数 → 前提不成立记 `applicable=false`（未验，不判红也不假绿）——
语义照门 16/17 的「未判定 rc=2」与「前提式纪律」（testing.md shotdiff 节：
「焦点/HID 依赖的门必须写成前提式……环境不给焦点那属真人 HID 那一格」）；
像素证据与真人验收互不背书（见下文论述）。汇总依据：kit selftest 的 advisory
读数（2026-10-31 实跑）。

**这六格怎么打开**（裸二进制直起，别走 `php bin/gpui run`——它每次重放整条构建链）：

```bash
./examples/kit/build/kit               # 或 --section 1：H2 Resizable 把手、H4 hover/always 两盒
./examples/kit/build/kit --section 2   # H3 Accordion 的 trigger
./examples/kit/build/kit --section 3   # H1 NativeMenu 触发器、H5 token 徽章 + link 文字
./examples/kit/build/kit --section 4   # H4 的独立对拍页（两盒同构，只差 scrollbarMode）
```

交互模式里每收到一个事件打一行 `KIT-EVENT <键>=<值>`（H1 的 `nmCopy=action`、H3 的
`accI0=open`、H5 的 `tokClick=delivered` / `linkClick=delivered`），悬停 id 变化打一行
`KIT-HOVER hoverId=<n>`（H6 要看的"非零侧"就是它变成非零）。开窗先打一行
`KIT-TREE n=<树号> title=<标题>`——这条是自证：新开关不报自己打开了哪棵树，就没法区分
"生效"与"被静默忽略"（pty 实测四棵树各打各的 n/title）。读数走 stdout 且只在 tty 里即时：
重定向到文件时 PHP 块缓冲，要等进程退出才落盘，所以人在屏幕前直接跑。
**2026-11-02 真人第一轮回报的三条缺陷，到 2026-11-03 的账**（先说结论：崩溃的那两条同根同因，已修；
剩下的是两条视觉缺陷 + 一个设计题）：

- 根因只有一个：上游把窗口/层的 content 实体**按 `ScriptView` 裸转**再用——
  `Entity<T>::Get` 是无校验的 `(T*)EntityGet(...)`（`gpui.h:6830`），而 `component_shell` 的事件回调体
  读的就是它的首字段（15 处 `view->runtime`）。我们的 `PhpView` 原本只有一个 8 字节的 `Box2*`，
  所以那颗"runtime"其实是 `Box2*`：`LiveComponentCallback` 拿 Box2 的字节当回调表头读。
  读到的东西决定读数——同一棵树里 18 颗可点控件 **8 颗 SIGSEGV、3 颗打
  `belongs to a superseded render`**，两种读数是同一个根因，而"回调过期"这个诊断本身是误导
  （表压根不是那张表）。修法：`PhpView` 首字段改成 `scriptViewRuntime` 别名并指向真的
  `g_runtime`（`cpp-src/gpui_shim.cpp`），两条 `static_assert` 把两端偏移钉死；
  只覆盖 `runtime` 是有依据的——唯一会被写的另一个字段 `dirty` 只在 `isScript=true` 的覆盖层重建里碰
  （`shell/root.cpp:174`），而我们开层走 `ShellRootOpenDialogView`，那里硬编 `isScript=false`（`root.cpp:340`），
  那条越界写在本宿主不可达。
- **手风琴（#111，已修并有回归位）**：新增 kit selftest 步 `accordionRealClick`——独立小窗放
  catalog Accordion，头部矩形从当轮 `hitProbe` 现算，`realClick` 走
  `DispatchMouseUp → ShellComponentValueRelay::OnValue → InvokeWith → LiveComponentCallback`
  整条真链（`gpui_selftest_fire` 绕开了这条缝，所以旧门 `accordionToggleGate` 全绿也照不出来）。
  绿侧 6/6 次 `候选 3 条 → 到 PHP 3`；红臂（把别名换回 `Box2*`）6 次里 **5 次 SIGSEGV、1 次两门各自 FAIL**。
- **TextView 链接（#114，同根因，一起掉了）**：新增 `linkRealClick` 步，独立窗真点链接，
  6/6 次送达 `link_click`。批15 量的"同坐标 6 连跑 3 崩 3 拦"不是 id 丢了，而是查表查到了假表上；
  `cmd/patch-dispatch-string-shimid.sh` 的守卫因此在本路径上不再触发（实测 0 条告警），留着当"未知 id"的兜底网。
- **广度门**：`cmd/check-click-survival.php` 把四棵树里每一颗 `click=1` 的控件逐个单独起会话点一遍，
  硬判据是"宿主 rc 必须为 0"加两条致命签名（`superseded render` / `window state is missing`）；
  其余 `shell:` 告警只计数不判红——这是本门自己的边界。"没有可点行"分两种：命中表一行都没有＝
  探测没量到东西（未判定）；有行但全 click=0（树 4 本就是纯文本滚动盒）＝量出来的空集按通过计，
  前提是同轮他树量到 >0 颗（全树皆 0 ⇒ 未判定，门不拿空转冒充判定）。修复后读数（2026-11-03）：
  **判 60/60 颗全活着、致命签名 0 条、advisory 告警 60 条；树 4 可点 0 颗（量出的空集）**。
- 树 3 的『打开确认框 / 警告框 / Sheet』：**#110 已修（2026-11-04 批24）**——ShellRoot
  继承 gpui::Root + shim 开窗后 `RootCapturePlugins` + `RootMount`，把 app 级根插件
  （含 component::kWindowStatePlugin）挂到 ShellRoot 上；`Root::Read` 从此找得到它，
  `WindowLayersOf` 不再返回 null——真机点触发器弹出确认框与 Sheet（截图实证），
  回归位 `cmd/check-dialog-open.php`（stderr 告警判据，红臂实测 rc=1）。
  `cmd/patch-window-layers.sh` 的 fail-soft 保留为兜底。原设计题记录保留在下面：
  `LayersOf()` 的 Panic 换成"告警一次 + 返回 null"（那 14 个调用点本来就都 `if (!l) return`），
  复测 11/11 颗活着；但**真点击开不出层**，要弹层走 `App::openDialog/openSheet/notify`。
  剩下的问题是"要不要给 ShellRoot 窗口补 component 层的 `WindowState` 插件"。
- 树 1 的 `hover` 盒**滚动条常驻**（真人截图里左右两盒都有 thumb），与清单期望"hover 仅悬停时出现"相反
  ⇒ **task #112 已修（2026-11-04 批20）**：根因是 IdCollect 给未命名元素零路径贡献——结构相同的
  hover/always 两盒坍缩到同一 scrollId，共用一条可见性状态，每帧互踢（always 盒 settle 到 1、
  hover 盒启动退出）永不完结。机器复现（scroll→移光标→垫 3 秒→截图，hover thumb 仍悬）；
  `cmd/patch-scroll-id-ordinal.sh` 把兄弟序数折进未命名路径使用者，回归门
  `cmd/check-scrollbar-hover-hide.php`（红臂实测：关掉折叠 rc=1）。同页"路径绘制"那块的图形压住了
  下面的文字 ⇒ **task #113 已修（2026-11-04 批21，机制见上：bridge 吞 param_style）**。
  这两块 shotdiff 看不见：基线只拍到 900x680 的上半屏，那两块都在视口之外。
**读数本分的实测状态**：`KIT-EVENT <键>=<值>` 这一行现在**已被机器亲眼验过**——`--automation` 也开了
kitEcho（三道消费 automation stdout 的门都只读 `snapshot.txt`，所以多打行不动判据），逐颗点击的扫描里
读到过 `step=` / `page=` / `rate=`；`KIT-HOVER` 在一次自然退出的会话里读到过 `hoverId=0`。
交互模式那半（人真点）从 2026-11-03 起 H3/H5 不再被缺陷挡着。这颗开关是 2026-11-02 补的——之前
交互模式把像素树写死成 1，且在建窗**之前**就 `wireKit`（里面有 `bindKeys`/`elementCallback`
这类窗口 API），于是裸跑 `./examples/kit/build/kit` 直接 FATAL「No window created」：
H1/H3/H5/H6 不是"没验"，是**没有入口**。

| # | 项 | selftest 现状（机器半边） | 真人操作 | 期望读数 |
|---|---|---|---|---|
| H1 | NativeMenu 行选中送达 | `nativeMenuShape` 只断工厂形状（trigger 硬带 on_effect_error + item(label,action) + children 白名单） | 前提=应用前台；真点触发器弹 OS 菜单（keyed event effect），再真选一行（两次真人交互——NSMenu 行选择是模态会话，合成点击够不到） | `$app->on($action,'action',…)` 的处理器收到一条事件，事件 id = 行声明的 action 名 || H1 | NativeMenu 行选中送达 | `nativeMenuShape` 只断工厂形状（trigger 硬带 on_effect_error + item(label,action) + children 白名单） | 前提=应用前台；真点触发器弹 OS 菜单（keyed event effect），再真选一行（两次真人交互——NSMenu 行选择是模态会话，合成点击够不到） | `$app->on($action,'action',…)` 的处理器收到一条事件，事件 id = 行声明的 action 名；2026-11-03/04 真人轮：弹菜单两侧实测 ✓；行选中 0 送达 ⇒ **#115 已修（2026-11-04 批19）**：触发器行的 action 此前从不登记 app 级动作表（登记入口只服务 app 菜单条），行派发两处都不中即静默丢；patch-native-menu-action-reg.sh 走 weak-seam 补上，回归位 selftest 步 nativeMenuActionDelivery（dispatchAction 红臂 false→绿臂 true+送达），NSMenu 模态会话里的真人最后一跳仍归真人 HID |
| H2 | Resizable 拖拽把手 | `catalogResizableShape` 只断形状（无拖拽） | 前提=窗口前台；真按住面板间把手拖动 | 面板尺寸随拖动连续变化（ResizablePanel 的 resize 读数/视觉宽度），松手后保持 || H2 | Resizable 拖拽把手 | `catalogResizableShape` 只断形状（无拖拽） | 前提=窗口前台；真按住面板间把手拖动 | 面板尺寸随拖动连续变化（ResizablePanel 的 resize 读数/视觉宽度），松手后保持；2026-11-03/04 真人轮：拖后分隔条停在 ≈335px（默认 180/上限 320 钳制位）松手保持 ✓；连续性未逐帧测 |
| H3 | Accordion trigger on_change 送达 | `accordionCatalogShape` 断形状（header/panel 槽契约）+ `accordionToggleGate`（独立窗口 regionRgb 面板开/关两态——关态无蓝底、开态有蓝底，依赖批1 open 参数暴露）+ **`accordionRealClick`（2026-11-03 补：独立窗真点 catalog 头部，矩形现算，断 on_toggle 到 PHP；#111 的回归位，红臂实测 5/6 SIGSEGV）** | 前提=窗口前台；真点 trigger 头 | `on_change` 事件到 PHP（open 状态翻转），panel 展开/收起与事件一致 |
| H4 | scrollbar always 真画 | `scrollbarModePaintPair` regionRgb 两盒同色（advisory，macOS 无头渲染自动隐藏）；`cmd/check-scrollbar-paint.sh` --shot 通道也同色 **applicable=false 亲测**（2026-10-31，rgb(223,223,223)）；`scrollbarHandleProbe` 独立窗口硬门（自绘路径 ApplyBar 画条——非 NSScroller overlay，批1 的自动隐藏结论不适用这条） | 前提=窗口前台；鼠标悬停 always 盒并滚动 | always 盒右缘出现 thumb 且随滚动移动；hover 盒仅悬停时出现——两盒右缘区域色从此可判互异（2026-11-04 批20 起 hover thumb 可隐藏由 `check-scrollbar-hover-hide.php` 机器断言，#112 的"常驻"已修） || H4 | scrollbar always 真画 | `scrollbarModePaintPair` regionRgb 两盒同色（advisory，macOS 无头渲染自动隐藏）；`cmd/check-scrollbar-paint.sh` --shot 通道也同色 **applicable=false 亲测**（2026-10-31，rgb(223,223,223)）；`scrollbarHandleProbe` 独立窗口硬门（自绘路径 ApplyBar 画条——非 NSScroller overlay，批1 的自动隐藏结论不适用这条） | 前提=窗口前台；鼠标悬停 always 盒并滚动 | always 盒右缘出现 thumb 且随滚动移动；hover 盒仅悬停时出现——两盒右缘区域色从此可判互异；2026-11-03/04 真人轮：真滚动生效（首行 01→04）+ always thumb 真画出 ✓；hover 盒无人悬停 thumb 常驻 ⇒ #112 在树 4 复现 |
| H5 | token + link 鼠标命中 | `tokenDeliveryRender`（造粒/事件送达/renderCalls/徽章像素落位全硬门绿——Phase 32 A2 批2 root=0 物化修复后徽章@必有值）+ `linkDelivery`（fire 通道量化）+ **`linkRealClick`（2026-11-03 补：独立窗真点 markdown 链接，断 `link_click` 到 PHP；#114 随 #111 同根因掉，6/6 绿）** | 前提=窗口前台；真点击输入框内的 token 徽章、真点击 link 文字 | onTokenClick / on_link_click 事件到 PHP，事件载荷含 token/link 的 id || H5 | token + link 鼠标命中 | `tokenDeliveryRender`（造粒/事件送达/renderCalls/徽章像素落位全硬门绿——Phase 32 A2 批2 root=0 物化修复后徽章@必有值）+ `linkDelivery`（fire 通道量化）+ **`linkRealClick`（2026-11-03 补：独立窗真点 markdown 链接，断 `link_click` 到 PHP；#114 随 #111 同根因掉，6/6 绿）** | 前提=窗口前台；真点击输入框内的 token 徽章、真点击 link 文字 | onTokenClick / on_link_click 事件到 PHP，事件载荷含 token/link 的 id；2026-11-03/04 真人轮：link 真点 0 送达 ⇒ **#116（与 linkRealClick 合成缝 6/6 分叉）**；token 预造 chip 画成「提及」≠ @张三 ⇒ **#117**，真鼠标 token 命中仍未验 |
| H6 | 悬停态非零侧（`hoverId`） | selftest 第 27 步硬断"出图后 `hoverId` 必须为 0"与"命中表里找得到可悬停元素"，并把 `hoverArm=<armed>/<cleared>/hits=<n>` 作例行读数打出（实测 `0/1/hits=2`）；非零侧**断不了**：合成 `mouseMoved` 与 `mouseDown/Up` 打到按钮中心（主窗 `mainKey=1`）后 `hoverId` 仍为 0 | 前提=窗口前台；把真实光标移进那颗 `hover-me` 按钮并停住 | `hoverArm` 的第一位变 1（gpui 记到悬停），且此时出的图带 hover 底色——这一条同时给 #106 色态判据补上真正的 headroom || H6 | 悬停态非零侧（`hoverId`） | selftest 第 27 步硬断"出图后 `hoverId` 必须为 0"与"命中表里找得到可悬停元素"，并把 `hoverArm=<armed>/<cleared>/hits=<n>` 作例行读数打出（实测 `0/1/hits=2`）；非零侧**断不了**：合成 `mouseMoved` 与 `mouseDown/Up` 打到按钮中心（主窗 `mainKey=1`）后 `hoverId` 仍为 0 | 前提=窗口前台；把真实光标移进那颗 `hover-me` 按钮并停住 | `hoverArm` 的第一位变 1（gpui 记到悬停），且此时出的图带 hover 底色——这一条同时给 #106 色态判据补上真正的 headroom；2026-11-03/04 真人轮：**hoverId=4 非零首次实测**（悬停我，移出回 0）⇒ 非零侧不再是断不了的那侧 |

前提不成立的标准记法：会话无 GUI/窗口不在前台/无法人工交互 ⇒ 该项记
`applicable=false`（未验），**不得**引用 selftest 绿冒充已验，也**不得**因做不到
而判红——那正是「前提式纪律」的定义（testing.md shotdiff 节）。

**编号冲突警示**：本表 H1–H5 的编号（H1=NativeMenu、H2=Resizable、H3=Accordion、
H4=scrollbar、H5=token+link）与 `findings.md`「真人 HID 类五项」顺序一致，是**既有
编号**；H6（`hoverId` 非零侧）是 2026-11-02 为 #106 新增的一项，findings.md 里对应
「合成缝喂不出悬停」那段。批1 施工用的 H1–H3 编号（H1=Accordion、H2=scrollbar、H3=token/link）来自
施工批次内部的工作计划，与本表编号**完全冲突**（如批1 的 H1 Accordion 对应本表 H3）。
后续施工文档一律沿用本表/findings.md 编号，否则撞号。批1 的三支 automation 脚本
（`cmd/check-accordion-toggle.sh`、`cmd/check-scrollbar-click.sh`、
`cmd/check-token-link-hits.sh`）沿用批1 内部编号，在脚本注释里标注了对应的本表编号
（accordion→H3、scrollbar→H4、token/link→H5）以避免歧义。

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
    行为，不是巧合）。烧核那一条 2026-11-02 重写，因为旧口径"烧 150ms 后单次采样断 cpu>0"
    会间歇红——全机 ticks 有**发布量化**（本机实测 60 个 150ms 窗口里 39 个差值为 0，
    冻结最长 851ms），而改成"本进程 cpu>0"也不行：那是**不看烧核的空转判据**（把自旋整个
    删掉，空闲泵帧照样读到 ~10）。现在两条硬判据各说各的：**①配对差**——同一进程连续两窗
    （空闲窗只泵帧、烧核窗主线程全程自旋），`procs[].cpu` 必须差出 ≥3 个百分点，实测
    0.4% vs 10.0%（差 9.6）；判别臂＝两窗顺序调换 ⇒ 差值 −10.0 ⇒ 门红（量过，还原回绿）。
    **②全机 `cpu` 差值通道**——必须在 ≤24 个烧核窗内读到 >0；它只证"通道通到 PHP"，不证
    "是这次自旋填的"（机器忙时不烧也读到 66.7%），所以旧那句"离 0 有足够余量"作废。
    读数 `sysIdle/sysBurn/delta`、`sysTries/retryWin/cpu2`、`sysTop5` 进 EXTRA 行，
    红时能立刻分辨是哪条没满足。`procs` 仍验前 5 条非空且首条有 pid 与名字。
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
| `value <id>` | 把输入框/部件当前值读进回执（2026-11-04 批22 修：原实现复用 `$ev`——poll 动词已把它在函数级类型推断里钉成 `php::Array`，再赋 `gpui_element_value` 的 Str 结果触发编译器插的 checkArray 运行时崩，任何 `value` 调用必 rc=134；改用独立变量名） | 断言"写进去了没有"用这句 |
| `focus <id>` | 把焦点放到部件上 | 真侧目前返回 `-1001`、`focused` 仍为 `-`，焦点这一层仍是缺口（见 #62/#56）；作诊断用 |
| `clickat <x> <y>` | 按坐标合成一次真点击（与 selftest 的 `realClick` 同源） | id 缝够不着的部件（slot 按钮、catalog 日期触发器）只能按矩形点；#103 的判别臂用它。input 的 `--automation <dir> [height]` 第三参可加高窗，够到折线外的目标 |
| `hits` | 把这一帧命中表整张落盘 `<dir>/hits.txt` | 行格式 `i id x y w h click down drag`（tab 分隔，gpui 视图系坐标）；与同帧 `screenshot` 对照可分「产品命中错」与「合成缝坐标换算错」 |
| `screenshot <标签>` | 先强制渲染一帧再出 PNG | 标签净化过，产物逃不出目录 |
| `fire <id> <event>` | 泛化的事件派发缝：先试元素缝（`gpui_selftest_fire` 只认 click/change/hover），不中再试 registered 缝（`gpui_selftest_fire_registered` 按 (id,type) 查 shimCallbacks 表，任意事件名都走 `ComponentCallback::InvokeWith` → 队列 → PHP handler） | widget-click 的 event 名固定 'click'，token_click/link_click 等不同事件名在 **verb 面**里没有别的途径——fire 是 automation 侧唯一的送达读数面。真点击那半从 2026-11-03 起也通（`accordionRealClick` / `linkRealClick` 两步走的就是 `InvokeWith` 同一条链），所以"只有 fire 能验 H3"这句已经旧了 |
| `rgb <x> <y> <w> <h>` | 区域均色读数（走 `regionRgb` 窗口 paint 缓冲） | screenshot 只截可见区（内容视口高度），折叠线以下的目标在 PNG 里不可见。本动词可读任意坐标——但 display buffer 在 automation 路径下不同步更新（handler 运行、ExampleState 更新、kitTree 重建都确认，像素不变），这是渲染时序限制不是派发链问题 |
| `scroll <x> <y> <deltaY>` | 发一个滚轮事件（`realScroll` → `gpui_mac_send_scroll`） | overflowYScroll 视口里的目标在折叠线以下时 hits 表 h=0、clickat 返回 0。滚动事件在下一帧 pump 里处理。**不调 automationForceFrame**——那会重建树、重置 ScrollView 滚动位置 |
| `poll` | 诊断：poll 一次事件队列，把事件原文打回执 | 用来分"事件没入队"与"入队了但 handler 没收到" |
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
（11 项，含乱序等待、标签逃逸、quit 停机、fire 派发自定义事件名）。

`kit` 这一支也接了同一张嘴：`php bin/gpui run kit --automation <dir> [tree] [height]`——
tree 选像素树（1-4，默认 1），height 加高窗（默认 680，树1 滚动视口样例在折叠线下时
加高使上屏）。**kit-tok-in 的 token 预造粒**在树3 路径里自动做（`runKitAutomation`
在首帧物化后调 `App::inputReplaceToken`），否则 hits 表里没有 token chip 的命中几何。

**scrollbar 命中口径**（Phase 33 批1 H2）：catalogScrollbar 是 registered 视图，
hits.txt 可见 rect（click=1）。但 Scrollbar 鼠标语义是拖拽（press+move+release），
同点按下抬起不滚动且自动化协议无 drag 动词——这是 #96 边界内的半边（滚动语义不可达）。
NSScroller overlay 那条路无真人鼠标自动隐藏（`cmd/check-scrollbar-paint.sh` 亲测
两盒同色 applicable=false，rc=0/rc=2 前提式形状）。`cmd/check-scrollbar-click.sh`
验的是 scrollbar 在命中表里（click=1），clickat 结果记读数。**h=0 不等于点不到**——2026-11-03
逐颗点击实测过：整页长窗里 h=0 的行照样吃到点击（#110/#111 修前崩的正是那批 h=0 行），
所以 `cmd/check-click-survival.php` 按"宽度≥8"筛候选、不看高度；旧的"h=0 就不可点"推断作废。

**token/link 命中口径**（Phase 33 批1 H3）：widget-click 只发 'click'，
token_click/link_click 是不同事件名——**在 automation 的 verb 面里**只有 fire 能发它们。
2026-11-03 起 **link 的真鼠标半边也进了机器断言**（`linkRealClick` 步：独立窗 + hitProbe 现算
矩形 + realClick，6/6 次送达 `link_click`），所以"link 只能靠 fire 验"这句作废；
**token 半边仍未通**——真人鼠标命中 token 归真人 HID（#96 边界），agent 只验进程内派发链
（fire 缝送达 + hits 命中）。
树3 的 El::link 已换成 El::textView（base kind Link 的 click 语义是打开 href
不消费 on_link_click）。

**批1 automation 脚本 ↔ v2 编号映射**：`cmd/check-accordion-toggle.sh` → v2 H3
（Accordion）、`cmd/check-scrollbar-click.sh` → v2 H4（scrollbar）、
`cmd/check-token-link-hits.sh` → v2 H5（token+link）。脚本注释里也标注了对应编号。

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
  **结案（#105，2026-11-02）**：上面那两个候选都不对，第三条才是机制——**拾取高亮跟着指针坐标**。
  `WindowToggleInspector` 开 inspector 时顺带把 `picking` 打开（`entity.cpp:381`），而 picking
  开着时每帧按 `win->mouseX/mouseY`（窗口记下的指针位置）命中一个元素，把 20% 强调色铺满它的框
  （`window_common.cpp:626` + `gpui.cpp:7600`）⇒ 指针算不算在窗口内就是两张整帧不同的图。
  一层分工要说清：出图函数本身早就做**指针归位**（`gpui_shim.cpp:1729` 起；它的注释点名"hello
  tree31 整张不同"，说明这症状早被量到并试图治），但归位**不足以**定住拾取态——归位照跑、
  拾取仍开时，8 次单跑实测 4 张近白、3 张带强调色、1 张介于两者；真正让它定下来的是**关掉拾取**。
  帧数也不是杠杆（泵到 80 帧照样间歇红）。
  收法两条：① 出图前 `App::setInspectorPicking(false)`（面板只看 `inspector.on`，关拾取不关面板）；
  ② 开合本身改由 `App::inspectorEnabled()` 硬断（hello `--selftest` 第 26 步，三段读数 `insp=1/1/0`：
  开→真、关拾取后仍真、再 toggle→假）。实测：tree29 连拍 20/20 逐字节相同且 md5 等于旧基线
  （旧基线记的就是"指针不在窗口内"那一态，逐像素 diff=0）；tree31 连拍 20/20 相同，与旧基线差
  998/480000=0.208%，差异全部落在 (489,0)-(520,31) 那个 32×32 的放大镜按钮里（`#d4d4d4` 拾取按下态
  变回 `#f8f8f8` 未按下态），所以只重录 tree31 一张。处置边界照旧：先有机制、再确认差异只在一处，
  才动基线——不是拿重录把 1.0 咽下去。
  **同类扫面**：`toggleInspector()` 在出图路径里还有第二颗成员——`examples/embedded` 的 `--shot`
  （它那棵树有 `El::inspector('em-inspector')`）。已同样补 `setInspectorPicking(false)`；关掉拾取后
  连拍 20 次逐字节相同，与旧基线差 998/395200=0.253%、同样只落在那个 32×32 放大镜上
  （`worst=0.141` 与 hello 一致），故只重录 `tests/baseline/embedded/tree1.png` 一张。
  那里旧注释写"面板只在**拾取模式**开着时画"也是错的——`IntoEl()` 读的是 `on`，拾取只管 hover 高亮。
- 链跑偶发红**不是一类**（#106 取证，2026-11-02；红帧落在 `tmp/shotdiff-fail/`）：
  chrome tree1 差 0.494%、只在一颗 89×32 的 ghost 按钮上，页面底色 `#f6f3ee` 变 hover 底色
  `#f5f5f5` ⇒ 真实光标在归位之后被**移动**进可悬停元素；mac tree1 差 1.764%、222×32 一块深色
  整体变浅（`#171717→#262626`），放大看图是那颗 `->primary()` 的"装 app 菜单"按钮整块换色
  ⇒ **同一类的按钮色态**（hoverId 是粘的：只由移动事件改写，光标停在同一处就次次重现同一帧，
  所以"两次同帧"不是另一种成因；hover 还是 pressed 未分开）；
  overlay tree1 两次红互差 4.905% 且带浅绿/浅红底（`#d5f4e1`/`#fcdcdc`）⇒ 动画在飞。
  ⇒ 至少两类（色态 / 动画在飞），各自要各自的修法。快照里的 `ignoresMouseEvents` **挡不住**坐标被改回去
  （实测 ignores=1 时 `win->mouseX` 仍是窗口内真实坐标）——gpui 从 app 队列取事件。
  **动画那一类已收**：`examples/overlay` 的 `--shot` 出图前泵到「右上那张卡的图标读回成功绿」
  （`App::regionRgb(526,68,4,4)`，判据 `g > r + 60`；静止态实测均值 (145,226,175)、入场半透明
  (200,240,214)、卡没在位的另一态 (247,162,162)），泵不出来照拍并打印 `note_ready=0`。
  判别臂有 headroom：把判据前的 `loop(80)` 临时降到 `loop(2)`，连 6 次都报 `tries=4`
  （判据把没落定的帧拒了），而产物仍与基线逐字节相同（`8cdc13c9…`）⇒ 不用重录。
  **色态那一类现在可自证**：`windowState()` 多了 `hoverId`（gpui 记的悬停元素 id），hello
  `--selftest` 出图后硬断它为 0，并逐列点名键表（读数 `shotHover=0/missKeys=0`）。三条篡改臂
  都量过：无头侧少一列 ⇒ Pest 键表门红；原生侧少一列 ⇒ `missKeys=hoverId` 且 rc=1；把 `hoverId`
  报成非零 ⇒ 同一条 selftest 红。键表的单一来源是 `App::WINDOW_STATE_KEYS`。
  selftest 第 27 步把这条读数固定成例行输出（`hoverArm=<armed>/<cleared>/hits=<n>`，实测
  `0/1/hits=2`）：`cleared`（出图后必须为 0）与「命中表里找得到那颗可悬停元素」两侧硬断，
  篡改臂量过（把目标条件改成 `w > 5000` ⇒ rc=1，还原 ⇒ rc=0）。**但 `armed` 只作读数不断**：
  画一颗带 onClick 的按钮、按命中表算出中心、把主窗升回 key（`mainKey=1`）之后投合成
  `mouseMoved` 与 `mouseDown/Up`，`hoverId` 仍是 0 ⇒ 合成缝喂不出悬停，能证伪这条读数的非零侧
  只有真实光标移动（已并入真人 HID 清单）。
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
