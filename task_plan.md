# Task Plan: TypePHP GPUI 移植

## Goal
用 TypePHP (AOT) 参考 gpui-kit-cpp（本地源码 `/Volumes/data/git/c/gpui-kit-cpp`）重新实现一套 PHP 原生桌面 GUI 框架，让 PHP 开发者能用声明式 API 构建跨平台 GPU 加速桌面应用。

## Current Phase
**当前 = Phase 15**：把 `examples/showcase` 按功能域拆成独立示例（用户令，参考上游
`/Volumes/data/git/c/gpui-kit-cpp` 的 `examples/*.cpp` 布局），目的是让单域测试能各自出声。
Phase 1–5、7 完成（含 P0 加固、P1 门禁、P2 文档/基石）；Phase 6 只剩"独立示例应用集"一项
——Phase 15 正是把它做完。
Phase 8「系统能力桥接 Wave 1–2」于
2026-10-11 完成：剪贴板三通道、原生对话框、OpenUrl 白名单、Inspector、帧率 HUD、Sheet、
WebView、Inspector 面板、http/dirChange/inflate、`App::sysInfo()`、应用内通知中心。
剩余：**Phase 9 mac 系统集成**（托盘/全局快捷键/OS 通知/Dock 角标，自写 `.mm`，不引外部库）、
`FsRun/ProcessRunBounded/Storage`（受 `Capabilities` 读写根授权模型约束，要单独一轮设计）、
以及 root-app epic 那面墙（catalog MenuBar / VirtualList / Dock 的同步取值，防火墙不可达）。
Phase 9（三个真 bug）/ Phase 10（mac 集成）/ Phase 11（Capabilities 三服务）于
2026-10-11 全部完成并**已提交**（`f00614a` feat / `159e885` docs），六道门禁全绿
（Pest 65/296 · PHPStan 0 · shim_test 10 组 · selftest 21 链路 · shotdiff 18 树 · doctor 7 marker）。
下一段是 Phase 12：远端与 CI 首跑（CI 从未执行过，见 P1 的订正）。
"缺失"清单已收敛到真缺失：Linux/Windows 的托盘与 OS 通知（端口里是桩或空实现）。

## Phases

### Phase 1: 架构设计与桥接契约定义
- [x] 理解 gpui-kit-cpp 架构：703 个源文件，核心是 `src/gpui/gpui.h`（El 树、App/Window/Ctx、Style、Entity 系统）、`src/base.h`（Str/Arena/Vec 基础类型）、`src/ui/`（主题化组件）、`src/component_shell/`（脚本绑定层）
- [x] 理解 TypePHP AOT 模式：PHP → C++17 → native bin，`.stub.php` 声明 C++ ABI，`php_` 前缀符号约定，`main(int $argc, array $argv)` 入口
- [x] 参考 typephp-qt 桥接模式：PHP 描述控件树数组 → C++ diff 渲染 → 事件回传 PHP 主循环
- [x] 深入阅读 shell/materialize.cpp（2806 行）：确认了 ScriptView→RenderSnapshot→SpecArena→MaterializeNode→El* 的完整 materialize 路径——这就是"脚本语言描述 UI → 原生 El 树"的先例
- [x] 深入阅读 shell/style.cpp（1704 行）：确认了 nullary style（flex_row/items_center/bg 等关键字表）+ parametric style（w/h/gap/p/m/rounded/border 家族 + tailwind ramp 后缀文法）两套样式应用机制
- [x] 深入阅读 shell/spec.cpp（766 行）：确认 SpecNode（Component+ops+children）、SpecOp（style/method/callback）、SpecArena（arena 分配+结构指纹）的数据模型
- [x] 确定桥接方案：**复用 ShellRuntime 的 spec/materialize 通道**，PHP 侧直接构造 SpecArena 描述（Push Component + PushOp + Attach children），C++ 侧 ShellMaterializeSpec 翻译成 El 树。这比从零写 bridge 更可靠，因为 materialize.cpp 已覆盖全部组件行为解析
- [x] **验证 macOS 构建**：`bun cmd/build.ts -rel hello_world` 成功，产出 8MB arm64 二进制；amalgam 在 `.work/gpui.h`(54K行)+`.work/gpui.cpp`(268K行)
- [x] **确定最终桥接方案**：不依赖 QuickJS VM。用 sed 补丁 `src/shell/view.cpp`，把 ScriptView::OnClick/OnChange/OnInputEvent 等改为入队到 bridge event queue；PHP 主循环 poll 取走分派。SpecArena/ShellMaterialize/RetainedStore 通过 gpui_shell_patch.h（friend 注入）暴露给 bridge C++
- [x] 设计 PHP 侧 API 风格：链式 Element builder（`El::div()->flexCol()->sizeFull()->gap(8)->child(...)`），每个方法记录一个 SpecOp，最终一次性写入 SpecArena
- **Status:** complete

### Phase 2: 项目骨架搭建
- [x] 目录结构：composer.json、project.yml、cmd/patch-view.sh、php-src/gpui.stub.php、cpp-src/gpui_bridge.cc、src/App.php、src/Element.php、src/El.php、src/FakeBridge.php、examples/hello/
- [x] vendor/gpui-kit-cpp symlink → /Volumes/data/git/c/gpui-kit-cpp（amalgam 已就绪）
- [x] cmd/patch-view.sh：sed 替换 view.cpp 的 DispatchClick/Change/Hover/Input/Slider 为 gpuiEnqueue*()，**内联 weak 桩定义**避免污染上游 amalgam include 图
- [x] **验证补丁后 amalgam 仍编译+链接**：`bun cmd/build.ts -rel hello_world` rc=0，weak 符号被 ld 正确解析
- [x] php-src/gpui.stub.php：gpui_app_new/window_open/render/pump_events/poll_event/input_value/set_timer/set_theme
- [x] cpp-src/gpui_bridge.cc：GpuiBox(php::Box) + Element→SpecArena 翻译 + php_gpui_* 实现（部分 TODO 标注 Phase 3）
- [x] src/App.php + src/Element.php + src/El.php + src/FakeBridge.php
- [x] tests/ElementTest.php：**PHPUnit 6 tests / 18 assertions 全绿**（无头，走 FakeBridge）
- [x] examples/hello/src/main.php：点击计数示例
- **Status:** complete

### Phase 3: 核心组件移植（端到端跑通）
- [x] buildSpecTree 拆分为「扁平 C ABI 指令流」：bridge(c++17) 递归展开 PHP 数组树 → push/nullary_style/param_style/method/callback/attach C 调用；shim(c++20) 落到 SpecArena
- [x] ScriptView→Box 反查注册表（g_views: PhpView* → Box2*），gpuiEnqueue* 据渲染实体 self 指针反查入队
- [x] callbackId：shim 的 Box2.newCallback 分配，记 callbacks[id]→(elementId,type)；poll 回填进定长 GpuiEvent struct
- [x] **关键架构转向（nano c++17 与 amalgam c++20 冲突）**：gpui.h 用了 C++20 `concept`/`requires`，nano 强制 c++17 → bridge 不能 include gpui.h。改为 C-ABI 防火墙：cpp-src/gpui_shim.h（纯 C ABI）、gpui_shim.cpp（c++20，唯一 include gpui.h）、gpui_bridge.cc（c++17，仅 phpx + shim 头，WindowBox 包 void* 句柄）
- [x] cmd/build-shim.sh：clang++ -std=c++20 -x objective-c++ 编 gpui_shim.cpp，与 bun 产出的 gpui.o/quickjs.o/AppLog.o 归档成 cpp-src/build/libgpui_shim.a
- [x] cmd/patch-view.sh 扩展：window_mac.cpp 加 GpuiAppRunStep + 改名入口 main→gpui_kit_main_disabled + weak GpuiMain（TypePHP 自带 main，避免重复符号与 GpuiMain 未定义）
- [x] project.macos.yml 改 nano/c++17，sources 只含 PHP+bridge，ld-flags 链 libgpui_shim.a（绝对路径）+ frameworks
- [x] AOT 约束修复：El.php class_alias→`class El extends Element`（Element 去 final）；App.php arityOf 反射用分离变量
- [x] **cmd/patch-nano.sh**：修 php-nano v1.1.0 依赖解析缺口——find_available 数组未命中时回落 module_registry（小写键），否则项目模块的 `ZEND_MOD_REQUIRED("Core")` 被判 unsatisfiable → "Unable to start PHP Nano extensions"
- [x] **端到端跑通**：nano 编译 examples/hello → build/hello (8.9MB) 运行 5s 存活、零 stderr、lsappinfo 确认注册为带窗口的 GUI app = Div/Text/Button/Flex/OnClick 全链路成功
- **Status:** complete

### Phase 4: 高级组件与特性
- [x] **4a Retained Input/Slider**：cmd/patch-retained.sh 加 `CreateRetainedInput/Slider`；shim 按 elementId 幂建 retained 写 `component.handle`；`gpuiEnqueueInput/Slider` 反查 elementId+读值入队；input_value 取文本。
- [x] **4b 定时器**：`gpui_shim_set_timer` 用公开 `WindowSetInterval`+`gpuiEnqueueTimer` 强符号（零 gpui 补丁）；selftest timer hits>0 验证。
- [x] **4c Slot 基建 + 低/中复杂度组件**：`gpui_shim_slot`(Claim+Slot op)、Element `->slot()`、kindFromString 补 20+ kind；radio/toggle/collapsible/popover/hover_card/avatar/accordion/table_*/progress/svg/select/combobox/date_picker + 工厂。--shot gallery 验证渲染。
- [x] **4d Markdown/Select/DatePicker**：`cmd/patch-focus.sh` 加 `CreateRetainedFocus`；DatePicker 用 component.handle、Select 用 focus/content_focus method op。Markdown TextView 直接渲染（policy=null）。--shot 验证。
- [x] **渲染 bug 修复（关键）**：字符串悬垂（arena 不拷 Str→加 AStr/StrDup）+ Button/Link 标签须为子元素 + sizeFull 参数化。--shot 实测文本/按钮/input/居中全恢复。
- [x] **Stage 5 Dialog/Toast**：窗口根 PhpView→`ShellRoot::New`（用 `WindowOpenView` 直接作根，绕开 KitOpenWindow 的 Base Root 包装，否则 ShellRootOf 判定失败）；`gpui_shim_dialog_begin/show/count/close`（dialog 内容是独立 Box2，复用 push/attach）+ `gpui_shim_toast`（ShellRootPushToast）。--shot 实测模态 dialog+遮罩+toast 渲染；selftest dialog 开/关断言。
- [x] **Stage 6 List（A1 原生滚动）**：`overflow_y_scroll` 实为 method/behavior op（materialize.cpp:364），非 nullary——恢复 overflowYScroll/XScroll/Scroll 为 method op。scrollable 容器装全部 children，gpui 原生滚动。--shot 实测 50 项列表可滚动。（A2 真虚拟化需 scroll→elementId 映射，ShellScrollPosition* 不带 id，留作后续。）
- [x] **Stage 7 Dock（设计交付）**：依赖 chrome 的 4 个 callback"回调进脚本渲染"，与 List 虚拟化同属 gpui-shell-假定-QuickJS 的不可达类；见 findings.md 设计。推荐 PHP 自绘 dock chrome 或给 materialize 大改。计划允许仅设计。
- **Status:** complete（4a–7 全部交付；Dock 为设计交付，A2 虚拟化/Sheet 为后续优化）

### Phase 5: 测试与验证
- [x] **Pest 无头测试**（FakeBridge）：13 tests 全绿；修复 FakeBridge 命名空间 bug + AppTest 拆独立文件。
- [x] **--selftest 事件覆盖验收**：真实二进制 rc=0，验证输入取值回环 + 点击事件全链路 + 定时器 tick。
- [x] macOS 编译运行 hello world（nano 二进制带窗口存活 + lsappinfo 确认）。
- [x] **--shot 像素级截图验收**：抓真实在屏 NSView（cacheDisplayInRect）出 PNG，Read 人工核对；正是它抓出并驱动修复了渲染 bug #18。
- [x] --shot 视觉回归基线比对（`cmd/shotdiff.php`，imagick RMSE / gd 回落，容差 0.001）：见 Phase 7 P1。
- **Status:** complete（六道门禁成型：Pest / PHPStan / shim_test / selftest / shotdiff / doctor）

### Phase 6: 交付
- [x] README 文档：架构图、构建/测试/自检、组件覆盖、CLI 用法。
- [x] 打包工具链：`bin/gpui`（doctor/build/run/shot/selftest/new）+ composer scripts + .gitignore。
- [~] 示例应用集：hello（含 gallery 树 tree10/11/12）；独立 gallery/list/dialog 示例目录待补。
- **Status:** in_progress（CLI+文档完成，示例集部分）

### Phase 7: 工程加固（hardening backlog，2026-10-11 审计）
> 功能面（A/B/C/D + 前序阶段）已交付；本阶段是"从工程上还能完善什么"的排序清单。按 P0>P1>P2。

**P0 — 正确性/内存 — ✅ 2026-10-11 完成**
- [x] **每帧回调累积泄漏**：`newCallback` 改按 `(elemId,type)` 幂等复用（加 `cbByKey` 反查）；`gpui_shim_new_callback` 命中 `shimCallbacks` 缓存则跳过再 `PushShimCallback`。两 map 按 distinct (id,type) 有界。selftest 多帧（timer hits=18）+ demo 出图验证不崩、事件路由正常。
- [x] **窗口/dialog 析构泄漏**：`gpui_shim_close` 现释放 `box->dialogs`（Box2+PhpView+entity，无 PHP 句柄引用故安全）+ 清 timerIds/Keys。主 Box2 保守不删（PHP `WindowBox->handle` 仍指，删则 UAF；注释说明）。selftest step4 的 dialog Box2 在 close 时被释放，未崩。
- [x] **错误只进 debug_tree**：`Box2.lastError` 记 registered 失败原因（`pushErrorNode`/`registered_method` 各失败点写、`render_begin` 每帧清）；新增 `gpui_shim_last_error`+`php_gpui_last_error`+stub+`App::registeredError()`（避开既有 `lastError()` handler 异常属性）。selftest step6 断言空 id Alert → 非空错误。

**P0 — 可复现构建 — ✅ 2026-10-11 完成**
- [x] **纳入 git**：`git init` + 基线提交 d3eeac1（.gitignore 已完善，纳管 29 源/配置文件，无产物/密钥）。
- [x] **钉住 gpui-kit-cpp**：上游**无 git 历史**，submodule/SHA 不可行 → `GPUI.lock` 契约（源码位置/GPUI_DIR·NANO_DIR 覆盖/补丁 marker 表/构建链）+ `bin/gpui verifyPatches()` 逐 marker 校验（doctor 展示、build 缺则中止）。
- [x] **去绝对路径**：`project.macos.yml` shim .a 改 `@ROOT@` 占位，`bin/gpui build` 编译期解析为 `project.macos.gen.yml`（同目录、gitignore；tpc 只认 .yml 扩展名）。
- [x] **一键构建链**：`bin/gpui build` 补丁列表补 `patch-registered-cb.sh`（原漏）+ build 后 verifyPatches；`composer patch` 补 registered-cb，新增 `composer bootstrap`。`bin/gpui build hello` rc=0 端到端验证。

**P1 — 质量门禁**
- [x] **C++ 侧测试**（2026-10-11）：`tests/cpp/shim_test.cpp` `#include` 整个 gpui_shim.cpp，headless 测匿名命名空间纯函数——`kindFromString` 映射、`AStr` arena 拷贝（bug #18 守卫）、`toComponentArg` 各 SchemaKind + Enum 白名单 + Array/Optional/Callback、`buildComponentArgs` 元数补齐/缺必填返 -1。`cmd/test-shim.sh` 两步编链（-x 只作用 .cpp），`SHIM CPP TESTS OK`。composer `test:cpp` / `test:all`。
- [x] **CI**（2026-10-11 跑通）：`.github/workflows/ci.yml` 定义 `php -l` 全源 + Pest + PHPStan（自足，ubuntu runner）。**原生链（build/selftest/shot/test:cpp）依赖 gpui-kit-cpp 源码，托管 runner 拉不到 → 暂不进 CI**，本地/self-hosted 跑（workflow 顶部注明）。
  ⚠ 订正（同日）：这行当时是"写了但从没跑过"——仓库还没有 remote。用户建好
  `yangweijie/typephp-gpui` 后首跑失败（php-version 8.3 vs lock 要 >=8.4），改 8.5 后
  run `38140545523` **两 job 全绿**：`composer install` 从 lock 装齐、`php -l` 全源、
  **Pest 65/296**（与本地逐字一致）、PHPStan 0、VitePress 构建 10 页 HTML。
  原生链（shim_test / selftest / shotdiff）仍只在本地这台 mac 上验过。
- [x] **--shot 基线 diff**（2026-10-11）：`cmd/shotdiff.php`（PHP imagick/gd，无新系统依赖）record/check 两模式，逐树出图与 `tests/baseline/<ex>/tree<N>.png` 像素比对（RMSE/gdfrac，容差 0.001）。`bin/gpui shotdiff` + composer `shot:check`/`shot:record`。**首跑即抓到 P0-mem 引入的 dialog double-free**（tree13 未关 dialog 直接 close → SIGABRT，见 Errors 表）。
- [x] **静态分析/风格**（2026-10-11，PHP 侧）：phpstan 2.3（composer dev）level 5 扫 `src`，`phpstan.neon` 固化；修 3 处真问题（`$ops`/`$children`/`$eventQueue` 的 `@var` 与实际形状不符 + `gpui_app_create` stub 与 FakeBridge 改 `: void`），**0 error**。composer `analyse` / 并入 `test:all`；CI 加 phpstan 步。C++ 侧：加 `.clang-format` 约定（LLVM/4空格/80列），但环境无 clang-format（装 LLVM ~GB 且启动盘紧）→ 暂不强制、不入门禁。

**P2 — 覆盖面/DX/文档**（用户 2026-10-11 定序：先 4+1，2+3 归 root-app epic）
- [x] **root-app epic 基石**（2026-10-11，commit d9672d8）：`cmd/patch-dispatch-cb.sh` 把 Stage D 的 shimId 旁路从 `InvokeWith` 扩到通用调用器 `DispatchArgs`（+前向声明）→ 所有 runtime 回调（菜单动作/retained/点击）可回 PHP。shimId 对 JS 恒 0，六门禁全绿零回归。**这是可复用资产**。
- [~] **MenuBar**：catalog MenuBar 被 `AppEffects`（root-app）挡死（见 findings）。替代路 `BaseSetAppMenus` 装的是 **macOS 系统菜单栏**（屏幕顶，`--shot` 抓不到窗口 → 不可视验证），且动作路由需每帧在聚焦视图绑 `ActionCallback`（复杂，且 Quit/Cut/Copy/Paste 多已被系统接管）→ **ROI 低**。基石已就位，但 MenuBar 本身不建议强做。
- [ ] **A 类 VirtualList/Dock**：基石只解锁"事件型回调"，VirtualList 行渲染要"回调返回 Element"（BuildWith 型），是**另一种机制**，基石不直接覆盖。仍需专门设计。
  - **定论（2026-10-11，见 findings）**：gpui 原生 list 虚拟化靠 `spec->renderItems` **渲染委托**（`RenderVirtualItems` 同步回调 JS），与 build-delegate 同属"gpui→脚本同步取值"类 → **纯 C ABI 防火墙不可达**。epic 边界：防火墙支持 gpui→PHP **异步**（事件），不支持 **同步取值**。VirtualList/Dock/catalog-MenuBar 全在此墙外。唯一可行是 PHP 侧近似窗口化（`on_scroll_wheel` deltaY 累加估偏移，非真惰性）。
- [x] **文档**（2026-10-11）：**VitePress** 文档站 `docs/`（用户定）——index + guide{getting-started, architecture, components, events, testing, cli} + contributing。`npm install`+`docs:build` 验证 rc=0（8 页渲染）。node_modules/dist 由 docs/.gitignore 排除，package-lock 入库。

### Phase 8: 系统能力桥接 Wave 1–2（2026-10-11 完成）
> 起点是"探测补全可能"：上一轮三层地图继承了一个错前提（把 Rust-only API 当成端口里的缺口）。
> 订正后按"符号是否真在 `gpui.o` 里"重排，见 findings.md「能力盘点订正」。

- [x] **Wave1 A–E**：`gpui_shim.h` 11 个新 ABI + `php_gpui_*` + `App::clipboardText/setClipboardText/promptForPath/openUrl/toggleInspector/showFpsHud/hideFpsHud/fpsHudVisible/openSheet/closeSheet/hasSheet`。`openUrl` 复用 gpui 自己的白名单（`capability.cpp:5`，20 条 URL 表在 C++/PHP 两侧各验一遍）。
- [x] **Wave2-A 上游补丁**：`cmd/patch-nested-view.sh`（第 7 个补丁）给 `ShellRuntime` 加 host 侧 `RegisterHostView(EntityId, App*)`，entry 的 `owner/policy/application` 留空（`policy.cpp:56` 对 null 直接 return，两条 sweep 都按不到）→ 打开 `ComponentKind::ChildView` 这道口子。
- [x] **Wave2-B WebView**：`El::webview/webviewHtml` + `webviewBack/Forward/webviewProbe`。`WebViewNew` 会重入消息循环 ⇒ `started` 标志必须在调用**之前**落。
- [x] **Wave2-C Inspector 面板**：`El::inspector($id)`（关着时 `IntoEl()` 返 null，所以 tree31 要先 `toggleInspector()`）。
- [x] **Wave2-E 真缺口服务**：`httpGet/httpResponse`（只接 `HttpSendAsync`，`HttpSend` 阻塞）、`watchDir/unwatchDir` + `dirChange`、`App::inflate`（解压侧真解 Huffman；压缩侧 `ZlibDeflate` 只写 stored 块 ⇒ 不桥）。
- [x] **剪贴板完整一项**：`App::clipboardItem(): {text,image,paths}`；mac 的文件路径**我们自己读** `NSPasteboardTypeFileURL`（gpui 只有 Windows 填）。图像是二进制流 ⇒ 新增 `copyBounded` 二进制通道。三通道用 `hello --clip` + AppKit `writeObjects:` 真数据实测四种组合。
- [x] **`App::sysInfo()`**：`SysStateInit/SysRefresh/SysSortProcesses`；差值量（cpu）与直接读数（mem/disk/ncpu）分开断言，采样状态进程内一份。
- [x] **应用内通知中心**：`App::notify/dismissNotification/clearNotifications/notificationCount` + `El::notifications($id)` + `click`/`close` 事件。撞到的墙与绕法见 findings「应用内通知中心」；`WindowNotifications()` 在 ShellRoot 下 SIGABRT ⇒ 状态 entity 按窗口自持、层由 PHP 显式摆。
- [x] **Wave2-D 被砍**：`sha256/secureRandom/deflate` 不桥——`hello --caps` 让编译产物自己回答有哪些原生函数，产物里 `hash()/hash_hmac()/random_bytes()/random_int()` 都在，桥过来是净负价值。
- **Status:** complete（门禁：Pest 56/237 · PHPStan 0 · shim_test 8 组 · selftest 18 链路 · shotdiff 18 树 · doctor 7 marker）

### Phase 9: 已交付面上的三个真 bug（P0）— ✅ 2026-10-11 完成
> 图标"不画"不是端口缺口——查出来是我们自己的两处缺陷，且第二处是内存安全级的。详见
> findings「图标不是端口缺口」。一条外部调查结论（说字体从未落地）已逐条核对后作废。

- [x] **`cppStr(const char*)` 造出悬垂 `Str`**：`cppStr` 只收 `const std::string&`，用 `const char*`
  调它会隐式构造临时 `std::string`，SSO 的短串就死在语句末尾。站点：窗口标题(763)、
  input placeholder/value(912/913)、`InputSetValue`(967)、toast title/description(1068/1069)。
  tree13 的 toast 显示 `"succe"` 而不是 `"Saved"` 就是它（`{死栈槽, len=5}` 被后面的
  `"success"` 临时对象复用）。**修法**：加 `cppStr(const char*)` 重载转调 `cstr`，一处覆盖全部。
- [x] **`gpui_shim_notify` 漏 `hasType`**：`notification.cpp:982` 的门是 `if (item.hasType)`，
  我们只写了 `item.type` ⇒ 通知卡片永远没有类型图标。一行（`WithType()` 或补 `hasType = true`）。
- [x] **`El::icon()` 的静默空画**：`AssetIconForPath`（`svg.cpp:1377-1393`）要求字面
  `icons/<base>.svg` 且 base 必须在 111 个编译表里，否则 `nullptr` ⇒ 什么都不画、**没有错误通道**
  （上游 `port-status.md:269-270` 承诺的 `DrawIcon` 兜底在 `src/` 里不存在）。我们的 demo tree21
  正踩在这上面：`El::icon('icons/home.svg')` 形状对但 `home` 不在表里（`grep -c '"home"'
  asset_icons.cpp` = 0），基线已经把它录成"那里是白的"。修法：tree21 换成 `icons/square-terminal.svg`
  / `icons/settings-2.svg`（重录后应出图）+ 在 `Element::icon()` 侧把可用名字白名单化或写明契约，
  别让下一个人名对了形状、名错了。
- [x] **探针（写码前钉死图标链路）**：`shim_test.cpp` 里 `AssetIconFind("circle-check")` /
  `AssetIconForPath("icons/circle-check.svg")` 断言非空且 `len>0`（纯查表，headless 可跑）。
  渲染那一半靠像素：修完重录 **tree32**（应出现绿勾/警告三角）、**tree13**（toast 应显示
  "Saved" + 描述）、**tree21**（应出现两个图标）。图标数据实测已编进二进制：`asset_icons.cpp`，
  111 个、`kAssetIconsDataLen=33038`。

### Phase 10: Wave 3 — 自写 mac 系统集成 — ✅ 2026-10-11 完成
- [x] **探针 A：Carbon 快捷键在我们的泵下派不派发**。泵是 `GpuiAppRunStep` →
  `[NSApp nextEventMatchingMask:... dequeue:YES]`（`window_mac.cpp:2309-2320`），没有
  `RunCurrentEventLoop`。不派发就在 `gpui_shim_pump`（我们自己的调用点）里先
  `ReceiveNextEvent(...kEventDurationNoWait...)` + `DispatchEvent` 自泵一手。handler 与泵
  同在主线程 ⇒ 入队不需要锁。
- [x] **探针 B：OS 通知要 bundle 身份**。产物是裸 Mach-O（无 `.app`、无 bundle id），
  `NSUserNotification` 通常被静默丢弃 ⇒ 要么先做打包，要么退到 `osascript display notification`。
- [x] `cpp-src/gpui_mac.mm`（同一条 `clang++ -x objective-c++ -fobjc-arc` 编、进 `libgpui_shim.a`
  归档，全文件 `#ifdef __APPLE__`）：**托盘 `NSStatusItem`**（菜单数组直接复用
  `GpuiMenu/GpuiMenuItem`）与 **Dock 角标 `NSDockTile`** 不吃探针 B 的约束，可先做；全局快捷键
  用 Carbon `RegisterEventHotKey`（不选 `CGEventTap`：要 Input Monitoring 授权弹窗）。
  Cocoa/Carbon/CoreServices 实测已在二进制的链接表里 ⇒ **不需要外部库**。
- [x] 手工清单（窗口外能力机器看不见）：`*_count()` 只到 existence-green，不能证明屏幕上真有图标。

### Phase 11: Capabilities 授权的文件 / 进程 / 存储 — ✅ 2026-10-11 完成
- [x] shim 里放**一份进程级 `Capabilities`**：`AddReadRoot` / `AddWriteRoot` /
  `SetExecute(ExecuteGrant)` / `Storage(bool)`；PHP 启动时 `App::grantRoot($dir, 'rw')` 声明。
- [x] `App::fsRead/fsWrite/fsList/fsExists/mkdir/remove`：`ResolvePath(path, access, &out, &err)`
  → `FsRun(op, out.root, out.relative, ...)`。`FsRun` 自己不看授权表，root+relative 由调用方给
  （`filesystem.h:41`）；POSIX 侧是真实现（`openat` + `O_NOFOLLOW` + `O_DIRECTORY` 逐级保权遍历，
  `filesystem_posix.cpp:49-66`）。
- [x] `App::processRun`：`ProcessRunBounded` 阻塞 ⇒ 要么只在事件回调里调（同 `promptForPath` 的
  约束），要么走 `ProcessCancellation` + 工作线程；命令白名单由 `ExecuteGrant::Allows` 判。
- [x] `App::storage*`：`BeginWrite/AbortWrite/StoragePersist` 是状态机 + `ShellSetStoragePath` 前置。
- [x] 跨边界截断语义：`kFsMaxReadBytes=64MB` / `kFsMaxWriteBytes=8MB` / 目录项 10000 ⇒ 大缓冲
  必须走 `copyBounded` 那套"真实长度与 cap 无关"的契约，否则调用方分不清"就这么长"和"被截了"。
- 符号实测全为 `T`：`FsRun` / `ProcessRunBounded` / `StoragePersist` / `ResolvePath` / `AddReadRoot`。
- **交付时踩到的三处契约意外**（都写进了不变量）：① `ResolvePath` 按 access **只查对应那张表**，
  写根不含读权限 ⇒ `grantRoot(dir, writable=true)` 现在两张表都加；② `FsRun(RemoveDirectory)`
  就是 `unlinkat(AT_REMOVEDIR)`，非空目录失败，`recursive` 只对 `MakeDirectory` 生效 ⇒
  递归删是 shim 里补的一趟授权遍历；③ 桥侧 `ErrText()` 先清缓冲再返回，把失败原因擦成了空串。
- **验收**：selftest #20/#21（gpui 写的 PHP 读得到、反之亦然、含 `\0` 二进制；未授予路径被拒
  且原因含"根"；`/bin/echo` 通、`/bin/cat` 被拒且原因含"白名单"；存储授权门前后的形状）
  + `shim_test.cpp::testCapabilityServices()` 在 headless 里真跑同一套。

### Phase 12: 交付面收口 — ✅ 完成（2026-10-11）：CI 三 job 全绿 + 文档站上线
- [x] **建远端并首跑 CI**（2026-10-11，用户建好 `origin = github.com:yangweijie/typephp-gpui`，PUBLIC）。
  首跑 run 38139715827 **失败**：`composer install` 在 "Setup PHP 8.3" 上撞 lock——
  `phpunit/phpunit 13.4.1 requires php >=8.4.1 -> your php version (8.3.35)`。
  改成 **8.5**（`swoole/typephp` 与 `phpx` 要 `>=8.4 <8.6`、开发机是 8.5.7，8.5 是唯一同时满足
  两端的选择）。
- [x] **VitePress 进 workflow**：新增 `docs` job（`setup-node 24` + `npm ci` + `npm run docs:build`
  + 产物页数断言）。本地先跑过：`npm ci` 成功、build rc=0、10 个 HTML。config 里没设
  `ignoreDeadLinks` ⇒ 死链会让 build 失败，这一 job 顺带就是死链门禁。
- [x] **第二跑盯过了**：`composer install` 从 lock 装齐 —— 那个"腾讯镜像 dist URL"的风险
  **没咬人**（runner 在 southcentralus，照样拉得动）。兜底办法仍记在 findings：真要坏时
  只重写 dist URL，不动版本号。
- [x] runner 侧两行都收了：`checkout`/`setup-node`/`configure-pages`/`upload-pages-artifact`/
  `deploy-pages` 全升 **@v5**（v4 还跑在已弃用的 Node 20 上）；`runs-on` 从 `ubuntu-latest`
  钉到 **`ubuntu-24.04`**（latest 2026-10-19 迁 Ubuntu 26，那该是一次独立决策）。
- [x] **文档站上 GitHub Pages**（用户报 404，实测根因两条）：
  ① Pages 已是 `build_type=workflow` 但**没有任何 job 上传过产物**（`status: null`）——CI 只
  build 不 deploy；② `config.mts` **没设 `base`**，项目页挂在 `/typephp-gpui/` 下，base 留
  默认 `/` 会得到"页面在、资源 404"的站，而本地在 localhost 根路径完全看不出来。
  补 `upload-pages-artifact` + `deploy` job（`environment: github-pages`、只在 main push 上跑）
  与 `base: '/typephp-gpui/'`，并加一步"产物里必须出现 `/typephp-gpui/`"的断言，让 base 忘设
  这种静默错配在 CI 里就红。run `38140955210` 三 job 全绿，线上实测：根 200、
  `/guide/testing` 200（cleanUrls 生效）、`/typephp-gpui/assets/app.*.js` 200。
- [ ] 若 CI 绿了，再考虑加 self-hosted runner 跑原生链（六道门禁里 3/4/5 目前只在本地这台机器上验过）。

### Phase 13: 文档站增强 + 上游托管 + 原生链进 CI — ✅ 完成（2026-10-11）
- [x] **站内搜索**：VitePress 自带 `search.provider: 'local'`（MiniSearch，构建期建索引、
  纯前端查询）。不选 Algolia：要 DocSearch 授权与爬取。产物实测：
  `@localSearchIndexroot.*.js` + `@localSearchIndexen.*.js`（每个 locale 一份索引）
  + `VPLocalSearchBox` chunk。
- [x] **多语言骨架**：`locales` 里 **中文留在根路径**（站点已上线、外链都指向 `/guide/…`，
  挪 locale 等于打断已有链接），英文挂 `/en/`。已翻 `en/index.md` 与
  `en/guide/getting-started.md`；**en 的 nav/sidebar 只列真存在的页**，因为 CI 的
  vitepress build 就是死链门禁（config 没设 ignoreDeadLinks）。
- [ ] 英文正文其余 7 页（约 5.6 万字符）——**用户决定暂停**：文档内容还要调整，现在翻了白翻。
  骨架已就位（locales / 语言切换 / en nav 只列真存在的页），补页时不用改结构。
- [x] **gpui-kit-cpp 本地托管 = vendored 源码子集**（用户选定）：
  `upstream/gpui-kit-cpp`（src/assets/cmd/examples/gpui_shell + 根文档，22M / 1198 文件；
  去掉 out/.work/tests/bench/web 与上游留下的 3MB `*.bak`）。这份树**已含 7 个宿主补丁**，
  补丁脚本保留是为了幂等复现与审计。构建改走沙箱：`cmd/sync-upstream.sh` 每次 build 前
  复制到 `build/gpui-kit-cpp`，补丁/amalgam/out 全落沙箱 ⇒ 跟踪树不被弄脏（实测
  `diff -r upstream/src 沙箱/src` 为 0 行）。所有脚本默认 `GPUI_DIR` 从 `vendor/` 改到
  `build/`，显式设 `GPUI_DIR` 即外部源码树模式（同步自动跳过）；`vendor/gpui-kit-cpp`
  符号链接删除。**顺序坑**：同步必须在补丁之前，否则干净 checkout 上沙箱还不存在。
- [x] 结论并实测：**托管后防火墙仍然需要，也降不到 C++17**。tpc 硬编码 `-std=c++17`
  （NativeSourceProjectBuilder.php:104）；`#include "gpui.h"` 的 TU 在 C++17 下
  **20 个 error**（`concept`/`requires` 在 gpui.h:6960、7017、amalgam:10773，外加
  6480 的 explicit copy-init），真实站点约 6–8 处。理论上可打第 8 个补丁降级，
  代价是每次上游动模板都要重打 + bridge 吞 54K 行头文件 + 还要改编译器的常量 ⇒ 不划算。
  详见 findings「本地托管后能不能不要防火墙」。
- [x] **原生链进 CI**（vendoring 的全部意义）：新增 `native` job（macos-15：
  `bin/gpui build hello` → `cmd/test-shim.sh` → `hello --selftest`），run `38142514806`
  **3m27s 全绿**，日志里 `SHIM CPP TESTS OK` + `SELFTEST OK`。顺带答掉一个没把握的问题：
  **托管 macOS runner 能开真窗口**。门禁 5（`--shot`/shotdiff）仍留本地——它抓真实在屏
  NSView，CI 上硬跑只会得到"能出图但图是空的"这种最坏证据。

### Phase 14: 展示全部特性的示例 app（进行中）
> 目标不是"再多一个 hello"，而是**一个把 Element 全部工厂、全部链式方法、App 全部能力都走一遍的
> 示例**，并且"走没走过"要能被机器查——否则"展示了所有特性"只是一句自述。

- [x] `examples/showcase/`：**13 节**（基础原语 / 输入与保留态 / 选择开关按钮变体 / 容器布局滚动 /
  表格列表进度 / catalog 富组件 / 侧栏表单菜单 / 浮层 dialog·sheet·toast·notifications /
  系统能力 剪贴板·url·fs·进程·存储 / 服务 http·watch·inflate·sysInfo·HUD·Inspector·主题 /
  WebView·Inspector / 样式图标弹层原语 / mac 集成 托盘·全局快捷键·Dock·OS 通知）。
- [x] 三模式：交互（`run()` 主入口）、`--selftest`（逐节渲染断言 `registeredError()` 为空 +
  真调每个 App 能力，含生命周期 `runFrames/frameCount/isOpen/stop/lastError`）、
  `--shot <path> <tree>` + `--sections`（一节一棵树，基线树号由二进制自报）。
- [x] **覆盖率门禁** `tests/ShowcaseTest.php`（9 个测试，进 CI 的 php job）：反射 Element 全部公开
  工厂/链式方法 + App 全部 API + 每个枚举取值，逐个断言被真调用（注释先剥掉）；再加两条：
  **调用 arity 与签名比对**（用 php-parser 走真 AST）与**孤儿 handler**（注册到元素的 id 必须
  在某一节里渲染得出来）。四个断言都做过反向锚点，见 findings.md。
- [x] 接线：`bin/gpui build showcase` 可用；CI native job 增加 build showcase + selftest 两步；
  `cmd/shotdiff.php` 支持 `[example]` 参数并向二进制要 `--sections`。
- [x] 逐节像素基线：`record showcase` 录了 13 张（tests/baseline/showcase/），`check showcase` 全部 gdfrac=0；
  **check 不进 CI**（--shot 抓真在屏 NSView，托管 runner 无窗口会话，与 hello 的 18 张同理）。
- [x] 文档引用：README 快速上手 + getting-started 加了 showcase 段；testing.md 写了第八道门禁的
  两层（反射/AST 静态核在 php job，原生 selftest 在 native job，像素留本地）。`vitepress build` 过。

## Key Questions
1. gpui-kit-cpp 的 Arena 内存模型如何在 TypePHP 中表达？TypePHP 支持 `std::any()` 和 Box 类型，但 Arena 分配需要 C++ 侧管理——桥接层负责 arena 生命周期，PHP 侧只持有 EntityId/handle。
2. El 树的链式 builder 在 PHP 中如何实现？gpui 的 `Div(cx->a)->FlexCol()->SizeFull()->Child(...)` 返回 `El*` 指针；PHP 侧用 Element 对象持有 id，每个方法返回 `$this`，最终序列化为数组传给 C++。
3. 事件回调如何跨越 PHP↔C++ 边界？typephp-qt 用 `on(id, type, handler)` 注册，C++ 入队事件，PHP 主循环取走分派。gpui 的 Listener 是函数指针+EntityId，需要映射为 PHP callable + window/entity handle。
4. 是否复用 gpui-kit-cpp 的全部源码作为子模块，还是只链接其预编译库？复用全部源码更灵活但首次编译慢；预编译库快但版本耦合。建议初期直接 include 源码参与 tpc 编译。

## Decisions Made
| Decision | Rationale |
|----------|-----------|
| 采用 typephp-qt 的"源码内联"模式 | 桥接 C++ 直接参与应用编译，不依赖预编译二进制，避免版本脱节 |
| PHP 侧用链式 Element builder 而非纯数组 | gpui 的核心 API 就是链式 builder（`Div(cx->a)->FlexCol()->W(800)`），保持一致降低学习成本 |
| 桥接层用 opaque handle（int64 entity id）传递 App/Window/Entity | gpui 的 EntityId 是 POD generational handle，TypePHP stub 可用 int 表达；Arena*/El* 不跨边界 |
| 第一版只做 macOS arm64 | gpui-kit-cpp 在 macOS 有 cairo/paint_mac.cpp 后端，开发环境是 Mac，先跑通单平台 |
| 复用 gpui-kit-cpp 全量源码（git submodule 或 vendor） | 703 个文件涵盖 gpui 运行时+taffy 布局+组件库+主题，重写代价极大 |
| **构建走 nano 模式（c++17），不走 embed** | embed 需 libphp.dylib/a（Homebrew PHP 无，php-builder 需从源码编 PHP）；nano 源码 compose、免 libphp、产出自包含二进制，且已在纯 PHP 探针上验证 6s 构建成功。nano 强制 c++17。 |
| **amalgam + 所有 include gpui.h 的代码 = 独立 c++20 静态库；bridge 经纯 C ABI 与之通信（防火墙）** | gpui.h 用了 C++20 `concept`/`requires`（line 6960+），c++17 下硬错误 → nano 里 bridge 不能 include gpui.h。C++17↔C++20 冲突只能用 C-ABI 防火墙解：shim（c++20，碰 gpui）与 bridge（c++17，碰 phpx）分离，树用扁平 push/style/method/callback/attach C 调用序列化、事件用定长 C struct 回传。bridge 侧仍用 php::Box 包裹 shim 的 void* 句柄。 |
| **能力盘点以 `nm gpui.o` 为准，不以 Rust 侧 API 为准** | 上一轮的三层地图把 Rust 才有的 API 记成"端口里有、还没接"，据此排出来的波次会去做不存在的活。订正后 Wave 3 也证明不需要外部库（Cocoa/WebKit/Carbon 已链）。 |
| **"要不要桥"交给编译产物自己回答（`hello --caps`）** | 本机 `php` 有的函数 ≠ nano 产物编进去的函数；产物里动态调用未编进的函数是 `SIGABRT`（catch 不住）。所以 `sha256/secureRandom/deflate` 被 `--caps` 报"都在/不需要"后直接砍掉。 |
| **浮层状态按窗口自持（通知中心）** | `WindowNotifications()` 要基座 `Root`；全局共享一份状态会让多窗口的堆叠与计时互相串。每 Box2 一份 entity，代价是 `close` 时要自己 `NotificationStopAdvancing` + `EntityDrop`（已进 teardown 顺序表）。 |
| **像素验收要"跑到静止 + 两个帧数对拍"** | `SHOT OK`/`notify_count`/`debug_tree` 三样都对也能拍到全透明的一帧。80 帧与 600 帧产物 md5 相同，才敢说拍到的是动画走完的静止帧。 |

## Errors Encountered
| Error | Attempt | Resolution |
|-------|---------|------------|
| `Abort trap: 6`（SIGABRT）跑 `--shot tree=13`（dialog 未关直接 close） | shotdiff 首跑抓到（P0-mem 引入） | `gpui_shim_close` 曾手动 `EntityDrop`+`delete PhpView` 释放 dialog，但 `AppClose`→ShellRoot 拆除已经过 EntityNewRaw deleter 释放 PhpView → double-free/UAF。改为只 `delete dlg`（Box2 wrapper），实体生命周期归 ShellRoot。 |
| `Unsupported file type: project.macos.yml.gen`（tpc 拒） | @ROOT@ 解析后生成 yml 喂 tpc | tpc 只认 `.yml`/`.yaml` 扩展名，生成文件改名 `project.macos.gen.yml`。 |
| `-x objective-c++` 把 `.o` 当源码编（"not valid UTF-8"） | test-shim.sh 单条命令编链 | 拆两步：`-c` 只编 test.cpp（带 -x），链接阶段不带 -x，clang 自动识别 .o。 |
| `Cannot redeclare App::lastError()` | 新增错误回传方法 | 既有 `lastError()` 是 PHP handler 异常属性，新 C 侧通道改名 `registeredError()`。 |
| selftest `exit=139`（SIGSEGV）——phpstan 驱动把 stub `gpui_app_create` 改 `: void` | 提交前漏跑 selftest（只跑了 phpstan/pest） | stub 返回类型须匹配 bridge `php_gpui_app_create()` 的 `Variant`；声明 void 令 AOT 生成的返回值写回错位。stub 回退 `: mixed`（附注释）；phpstan 的 resultUnused 靠 FakeBridge（src，phpstan 扫这个）的 `: void` 消除，两者解耦。**教训：改 stub/C++ 后必须跑原生 selftest，不能只跑 PHP 层门禁。** |
| `Invalid callback ... not found or invalid function name` + SIGABRT（Pest 全局钩子看起来在跑） | 首版 `tests/Pest.php` 用裸 `beforeEach()` / `uses()->beforeEach()` | Pest 5.3 里 `beforeEach()` 按**声明它的文件**名注册，`TestCall` 按**测试文件**名查 → 静默不跑。改 `uses(TestCase::class)->in(__DIR__)->beforeEach(...)`，并用"两个测试互相断言对方写过的全局态"当探针验证钩子真的跑。**这类"绿得没有内容"必须用探针证。** |
| 通知中心 `exit=134`：`component window state is missing; call component::Init before KitOpenWindow` | 直接调 `WindowNotifications(cx)` 拿状态 | 它 → `LayersOf` → `Root::Read(win)`，要的是**基座 Root**，与 ShellRoot 互斥。核对 `ui/notification.cpp` 不读 `Root`/`Layers` 后改为按窗口自持状态 entity（`EnsureNotifyState`）。 |
| `--shot … 32` 报 `notify_count=2` + `SHOT OK`，PNG 里只有宿主标题 | 以为是 `useThemeSettings` 读到零值主题 | 真因是**入场动画**：`Starting` 段 opacity = `elapsedMs/400`，推它的是 50ms 墙钟计时器，只泵 3 帧等于拍在 0。改跑 80 帧，并与 600 帧产物比 md5（相同 ⇒ 落在静止段）。**计数 + 退出码 + debug tree 全对也不构成像素证据。** |
| AppleScript `set the clipboard to {POSIX file ...}` 后 `--clip` 恒报 `paths=0` | 以为是 `NSPasteboardTypeFileURL` 读错了 | AppleScript 写的是 alias list，不是 `public.file-url`。改 `[pb writeObjects:@[NSURL ...]]` → `paths=2`。**夹具本身要双向锚定，否则"绿/红"都在说夹具。** |
| `bin/gpui shot hello x.png 30` 出的是 tree 0 | 直接怀疑 `--shot` 的 tree 解析 | `bin/gpui` 只转发了 path，tree 参数丢了。改成 `$args[2]` 一并转发。 |
| `WatchCtx` "reference is ambiguous"（在 `Box2` 里写 `std::unordered_map<int, struct WatchCtx*>`） | 加目录监听的 watch 上下文表 | `struct` 声明落在 `Box2::WatchCtx` 作用域。在命名空间作用域前向声明后再用。 |
| `gpui::NotificationPush` 等找不到符号 | 按头文件的 `namespace component` 之外的直觉写 | 全套在 `gpui::component::`（4 处）。`Array::exists("key")` 也不存在（只按下标找）→ bridge 里改局部变量、键只 set 一次。 |
| webview 建失败的回滚里 `delete view` | 照抄"drop 完再 delete" | `EntityNewRaw` 的 deleter 已经 `delete` 了 payload ⇒ double-free（dialog 那次 SIGABRT 的同一课）。drop holder 之后**不再** delete payload。 |
| Pest 报 `Call to undefined function toBe()` | 断言里写成 `).toBe(`（少了 `>`，变成字符串拼接） | 与上一轮 `.font(` 同一类 typo；`php -l` 不报（语法合法）。改回 `)->toBe(`。另：Pest 5.3 无 `toBeAround()`（写 `abs(a-b) < eps`），且不吃 `@` 抑制（要先校验输入再调会告警的函数）。 |
| toast 基线显示 `"succe"` 而不是 `"Saved"`（tree13，长期被当成"图标没画"的旁证） | 归因给"端口图标字形未落地" | 真因是 `cppStr(const char*)` 造出的**悬垂 `Str`**：临时 `std::string` 的 SSO 内联缓冲在语句末析构，`{死栈槽, len=5}` 后来被 `"success"` 的临时对象复用 ⇒ 按 5 字节读出 `"succe"`。**一个错归因会把两行修复变成一轮上游改造。** |
| selftest `fs=false svc=false`，但 `fsError()` 回的是**空串** | 先怀疑 shim 没写错误 | 桥侧 `ErrText(buf, cap)` 写的是"先 `buf[0]='\0'` 再 `return std::string(buf)`"——把 shim 刚写进来的原因擦掉了。改成声明处 `char err[N] = {};`、返回处只读。 |
| `grantRoot(dir, writable=true)` 之后 `fsRead` 仍被拒 | 以为写根蕴含读权限 | `Capabilities::ResolvePath` 按 access **只查对应那张表**（capability.cpp:471-472）。现在 `writable=true` 两张表都加，并在注释里写清为什么。 |
| `fsRemove(dir, recursive=true)` 删不掉非空目录 | 以为 gpui 支持递归删 | `FsRun(RemoveDirectory)` 是 `unlinkat(AT_REMOVEDIR)`，`recursive` 只对 `MakeDirectory` 生效（`filesystem_posix.cpp:359-371`）。递归删在 shim 里用"列目录→逐个删→删自己"补，仍只走授权遍历。 |
| tpc 编译报 `non-const lvalue reference ... cannot bind to a temporary` + `no member named 'second'` | 用 `for (auto& kv : array)` 遍历 `php::Array` | phpx 的迭代器给的是 `ArrayKeyValue`（不是 pair）。改用既有索引写法：`for (size_t i=0; i<(size_t)a.count(); i++) strOf(a.get((int)i))`。 |
| Pest 报 `unlink(.../sub): Operation not permitted` 警告 | 测试收尾用 `glob("$dir/*")` 全 unlink | glob 也会给出目录。收尾改成显式 unlink 文件 + rmdir 目录。**测试自己的清理代码也会污染门禁输出。** |
| 一条外部调查（Explore agent）给出带 file:line 的"图标字体从未落地"结论 | 直接采信并按它排波次 | 逐条核对：它引用的 `src/base/icon_font.cpp`、`assets/iconfont.ttf` **在本仓库不存在**（`ls` ENOENT），`WindowLoadIconFont` 也搜不到 ⇒ 报告整体作废。自己查出来的事实相反：111 个图标已编进 `asset_icons.cpp`，mac 走 `SvgDraw`。**agent 的引用必须自己跑一遍命令再信。** |

## Notes
- gpui-kit-cpp 的 component_shell 层已经实现了 QuickJS 脚本绑定（ScriptView/ShellRuntime/RenderSnapshot/SpecNode），这是最接近"用脚本语言描述 UI"的先例。TypePHP 桥接可以参考 SpecNode/ComponentKind 的设计，把 PHP 数组翻译成 SpecNode 再 ShellMaterialize 成 El 树。
- 但更直接的路径是绕过 shell 层，直接在 PHP 侧构造 El 描述（kind + style + children + listener），C++ 侧 materialize。这样不需要嵌入 QuickJS。
- typephp-qt 的 WidgetTree 数组→C++ QWidget diff 模式是经过验证的，可以类比：GpuiElement 数组→C++ El 树重建（gpui 每帧重建 El 树，不需要 diff）。


### Phase 14b: 真实输入路径（用户实测暴露，已完成）
- [x] 修 `gpui_shim_callback` 的 `on_` 前缀失配（真实鼠标/键盘从未挂上监听器）。
- [x] 修 `El::input/textarea` 的 id 落点（`id` 字段而非 `text`），number_input/otp_input 补 retained。
- [x] 新增 `App::realClick/realMouseMove/realKey/inputProbe/focusedId` + 第 9 个补丁标记（CreateRetainedOtp）。
- [x] `showcase --selftest` 加真输入断言：点导航换节 / hover 命中 / 打字回 PHP / probe.n>0。
- [x] `cmd/patch-retained.sh` 重写为逐符号幂等 + 自检（upstream 里已含旧补丁，整块闸门会插错位置）。
- [ ] 遗留：输入框外观无框（主题层）；`focusedId()` 对 retained input 恒 0（只当诊断）。

### Phase 14c：真输入的系统前提（已完成，剩用户手点复核）

- [x] A/B 定位：bundle 身份 ⇒ `active/key/main` 才成立（裸二进制实测 false）
- [x] `bin/gpui`：`run`/`selftest` 走最小 `.app` bundle；`run` 转发 `--trace` 等开关
- [x] 事件缝走真 NSApp 路由（postEvent → 按掩码 nextEventMatchingMask → `-[NSApp sendEvent:]`）
- [x] `windowState()` 新增 `bundled`/`nsappDeliveries`；showcase 非前台时画面顶部自述
- [x] `bundleGreen`/`routeGreen` 两道门 + 负控（钉死分支 → rc=1；按 md5 还原 → 150 条真路由，全绿）
- [x] 门禁重跑：hello+showcase 像素、Pest 74、PHPStan、shim cpp、docs:build、两条 selftest
- [x] 用户手点复核 → **已用真 OS 输入代验**（用户为终端开了辅助功能权限）：
      `open` 起 bundle 后 Quartz HID 真点击/真按键，`/tmp/showcase-events.log` 出现
      `click id=nav-5 value=1.0`、`change id=in-name value='hi'`、`key_down id=in-key value='7'`，
      画面同步换节、HUD 计数跟着走。过程中查出并修掉两个真根因：
      常驻 WKWebView 吃掉上半区 `hitTest`（Phase 14c-12）、
      bundle 启动后不自提升为前台应用 ⇒ 非前台窗口的鼠标键盘全不派发（Phase 14c-17，
      `activateIgnoringOtherApps:` 在 macOS 26 已失效，改用无参 `-[NSApp activate]`）。
      另订正一条我自己下错的结论：`acceptsFirstMouse:` 返回 YES 并不等于非前台能收到第一下。

### Phase 15: 把 showcase 拆成独立示例（进行中，2026-10-14）

> 用户令：「参考 `/Volumes/data/git/c/gpui-kit-cpp` 将 showcase 拆分为独立的 example，这样方便测试」。
> 拆之前先量成本，因为拆分粒度完全由它决定：**一次 showcase 的 tpc nano 编译 = 41 秒**
> （热缓存，`php vendor/bin/tpc.php examples/showcase/project.macos.gen.yml --nano`，rc=0）。
> 之前"每支示例 1.5–2 分钟"的估计是把整条 `bin/gpui build`（sync→13 补丁→amalgam→shim→tpc）
> 都算进去了；那四步是**所有示例共用的**，不随示例数增长。所以多支示例的边际成本只有 tpc 那一段，
> 拆十几支在本地和 CI 上都负担得起。

**为什么现在要拆**（不是审美问题，是测试问题）：
`--selftest` 只在**跑完**时打印，所以任何一处崩都表现为"无声退非零"——上一轮 `focusElement`
的 rc=11 就是这么消失的，二分一次要花一整轮重编。13 节 + 全部断言挤在 1833 行 `main.php` 里，
量一个域要等整个画廊跑完，而且 `$ok` 链尾没有余量（`#73` 的部件级事件探针正因如此被移出 selftest）。

**拆分轴（我的取舍，上游就是这两种并存）**：按**功能域**拆，名字对齐上游
`examples/*.cpp`（`input.cpp` / `dialog_overlay.cpp` / `focus_trap.cpp` / `table_in_scrollable.cpp`
/ `system_monitor.cpp` / `webview.cpp` / `sidebar.cpp` / `motion.cpp`）；
`showcase` **保留为画廊**——上游同样同时有 41 页的 `examples/showcase/` 和这些独立示例。

| 新示例 | 来自 | 迁过去的门 |
|---|---|---|
| `input` | §2 sectionInputs | retained 取值回环、`--livetyping` |
| `overlay` | §8 sectionOverlays | focus trap、Escape、`ui::Cancel` 派发、dialogCount |
| `text` | §1 | registeredError 逐原语、icon 名查表 |
| `choices` | §3 | select/combobox/datePicker 弹层与取值 |
| `layout` | §4 | 容器/滚动 |
| `table` | §5 | row/column 语义、进度 |
| `catalog` | §6 | registered_method 描述符 |
| `sidebar` | §7 | 侧栏/表单/菜单 |
| `system` | §9+§10 | 剪贴板·url·fs·进程·存储·http·watch·inflate·sysInfo |
| `webview` | §11 | WebView/Inspector 挂载 |
| `style` | §12 | 47 个零参样式糖 → `nullary_style` op |
| `mac` | §13 | 托盘/全局快捷键/Dock/OS 通知 |
| `showcase`（留） | 壳 + 13 节像素 | 导航命中扫描、`--liveclick`、通配记录器、生命周期/析构门 |

**单一真源怎么保**：builder 与接线搬进 `examples/_shared/features/<域>.php`
（含各自的状态表 `examples/_shared/state.php`），showcase 的 yml 把 13 份全列进 `sources`，
独立示例的 yml 只列自己那份——**不复制代码**，所以覆盖率门禁不会因为搬家而漏项。
nano 不支持 require/include，全靠 yml 列源文件；全局函数名必须跨文件唯一，
`sectionX()` / `wireX()` 本来就按节名唯一，够用。

- [x] **15-A** 抽公共骨架 + 打通第一支独立示例 `input`，六道门禁全绿。**这一支的目的只是证明管道，不是搬完。**
      实测：完整链 `bin/gpui selftest input` = 351 秒（含被 rsync 清掉后重编的 amalgam），
      加 `--no-native` 的那一段 = 140 秒（tpc 41 秒 + 起 bundle 跑自检）；
      画廊 `SHOWCASE SELFTEST OK`（13/13 可达）且 `shotdiff check showcase` 13 张 gdfrac=0
      ⇒ **搬家没有改变像素**；`tests/baseline/input/tree1.png` 三次截图 md5 相同（确定性）；
      Pest 103 / PHPStan 0 / 上游 parity / shim cpp / c-abi / doctor / docs:build 全绿；
      新第 10 条门做过反向锚点（删掉画廊 yml 那行立刻红）。
      **首轮就跑出三条画廊报不出的结论**（详见 findings.md「Phase 15 拆分第一支独立示例」），
      其中 OTP 取值口那条已修（commit `0278c0f`）——这就是"方便测试"要的东西。
- [x] **15-B** `overlay`：域源搬到 `examples/_shared/features/overlays.php`（builder + `wireOverlays`
      + `overlaysTrees`），新支 `examples/overlay` 自带九条**确定性**门（focusTrap / ui::Cancel /
      closeDialog / 每个 anchor 各开各关 sheet / 每种 type 随推随长 / 清场 / 八锚点 / 拆框后仍干净），
      并带 `--escape`（探针，不是门）。
      当场结了两件事：**#62 的 Escape 结案**（两支都 `closed=true`，旧结论测于 #54 修前台之前），
      以及否掉我自己的预期（`clearNotifications()` 要泵过帧才归零，`在场=12/8` 那条 FAIL 是我没先清场）。
      验证：`OVERLAY SELFTEST OK` rc=0 · `SHOWCASE SELFTEST OK` rc=0 · `shotdiff check showcase`
      13 张 gdfrac=0（dialog 标题字面量改掉不影响像素，因为那棵树只在点开之后存在）·
      `tests/baseline/overlay/tree1.png` 三次截图 md5 相同 · Pest 103 / PHPStan / parity / docs:build 绿。
      **未做的**：画廊 selftest 里那三条旧门（`trapOk`/`cancelClosed`/escape 读数）还留着——
      它们与这支重叠，等 15-C 铺完一起收口（收口要再重编画廊，一轮 40 秒起）。
- [x] **15-C** 其余域按同一模板铺完：**12 支独立示例覆盖 13 节**（§9+§10 合成 system），每支都录了基线。模板 = `examples/_shared/features/<域>.php`
      （builder + `wire<域>` + `<域>Trees()`）→ 域 yml 只列自己那份 → `--selftest` 用 `step()` 边跑边打
      → `bin/gpui build <域> --no-native`（改了 cpp-src/ 则第一支必须走完整链）
      → `shotdiff record <域>` 并做两次截图 md5 对拍 → CI 的 Domain examples 步骤自动发现它。
      十二支 = `input`(§2) `overlay`(§8) `style`(§12) `table`(§5) `catalog`(§6) `choices`(§3)
      `text`(§1) `layout`(§4) `system`(§9+§10) `mac`(§13) `chrome`(§7) `embedded`(§11)。
      每支的门是"连根搬走"而不是复制：
      `$styleSugarOk` / `$tableOk` / `$cbEmitOk` 与它们在画廊 `$ok` 链、报告串里的引用一起删掉。
      **搬完立刻红过一次**：pest 报 2 条——`onLinkClick` 等五个糖与 `specIndex()` 在画廊里唯一的
      调用点就是被搬走的门，覆盖率门当场说"这六个 API 现在没人展示了"。这条门是活的。
      新支另加了画廊没有的门：`iconPathGuard` `sizesEnum`（style）、
      `tableCountOps` `indexGuard`（table）、`dropdownItemOps` `emptyIdIsLoud`（catalog，
      故意失败路径排在最后因为它会弄脏 registeredError）。
      实测四支 rc=0、13 张画廊像素 gdfrac=0、四支新基线各三次截图 md5 相同。
      `choices` 那支首轮 rc=6 崩在我自己的几何假设上（"触发器宽 = 声明的 180"落空成空数组，
      下一行取 `[0]` 就炸），而报告正好停在崩的那一行——这就是把门拆小的全部目的。
      它顺带把 **#73 的位置从"事件投递"挪到"触发器不可点"**：三颗 catalog 触发器在
      `paint.hits` 里没有矩形，点遍 16 条命中矩形没有一个弹层被打开（读数表见任务 #73）。
      文档跟着改：`events.md` 那句"还没接的十个…不给糖"与代码不符（糖 10-13 就加了），
      订正成"糖有、送达未量"；`specIndex` 那条门的归属改指 `selftest table`；
      `upstream-coverage.md` 的 event 两行按 `grep -c "callback('on_" src/Element.php` 量出的 24 改写。
      后四支（system/mac 这两轮）照出两条真 bug 并修掉：§10 的 sysInfo handler 用 C 结构体字段名
      （`memUsedBytes`）而 bridge 给的键是 `memUsed` ⇒ 画廊那一行一直显示 `mem 0 / 0`；
      以及"两棵树拍出同一张图"（树号拿去比节号）——是**两次基线字节数完全相同**这个异常暴露的。
      mac 那支还交了一次 nano 编译期学费：`foreach ($ids as $k => $v)` 之后再赋 int 给 `$v`
      ⇒ `Cannot assign value to variable $id of type php::Int with type php::Var`，rc=255 且没跑到自检。
      待搬：无——13 节已全部拆完；跨域还剩 `a11y`
      （`$roleAllOk`/`$roleThrows`）与 `pointer`（`$mouseOk`，它要画廊的壳，最后判要不要搬）。
- [x] **15-D** 收口：CI native job 的 `Domain examples` 循环（15-A 就加了，按 yml 自动发现）+
      `cmd/all-examples.sh`（composer `examples:all`；模式 selftest/shotdiff/both/first-native）。
      全量本地实测：**14 支 selftest 全 rc=0 · 43 棵像素树全绿 · 86 秒**。
      文档同步：cli.md / testing.md / getting-started.md / README 的示例段；
      清单不再枚举名字（`ls examples/` 即清单），省掉每拆一支改一遍文档。
      画廊自检去重已做（轮 18）：删掉与 overlay 重复的 `$trapOk`/`$cancelClosed`/两格 Escape 读数
      共 73 行，`$ok` 链于是腾出余量给 #73 的部件级事件探针。
      全量收口命令：`bash cmd/all-examples.sh [selftest|shotdiff|both|first-native]`
      （composer 别名 `examples:all`），清单 = `examples/*/project.macos.yml`。`shotdiff` 不进 CI。

**明确不做**：不给每支示例配独立 `.app` bundle 逻辑（`bin/gpui` 的 `appBundle()` 已按名字生成，
天然可用）；不动 `cmd/shotdiff.php` 的 hello 固定表（它自报 `--sections` 的路径已经存在）；
不在这一轮删 showcase 的任何节。


---

## Phase 16：部件 binding 那批回调从 QuickJS 挪进宿主队列（2026-10-14）

拆分带来的直接收益在这里兑现：`examples/choices` 一支独立示例把一条**6 次跑崩 4 次**的
SIGSEGV 报成了它自己那一行，而它在画廊里时只是"某轮 rc=11、一个字没打"。

- [x] **16-A** 定位：崩溃报告 + `nm`/`objdump` 把故障点说成"第 7 段的第一次 `dispatchAction`"，
      并把 `DispatchArgs+100` 的反汇编逐字对上 `CallbackArena::Get()` 的扫表循环。
      旧结论"focusElement 崩过所以不能进 selftest"作废：同一条栈在 07:09 的 showcase 报告里就有。
- [x] **16-B** 机制：`gpui_shim_callback` 交出去的是 **Box2 自己的 callbackId**，
      runtime 回调表里从来没有它（只有 `gpui_shim_new_callback` → `PushShimCallback` 会登记）。
       ⇒ 文档里"这条路不需要新补丁"那句是错的，`src/Element.php` 与 `events.md` 一起订正。
- [x] **16-C** 修法：新增 `cmd/patch-select-view.sh`（第 14 支，已进 `bin/gpui` 的 patchManifest，
      doctor 正反两向都过），把 view.cpp 里 7 处 binding 派发换成宿主弱钩子：
      `open_change`/`bound_bool`/`popover` 走已有的 `gpuiEnqueueChange`（**带 bool**），
      `confirm`/`dismiss`/`dock` 走新增 `gpuiEnqueueSignal`，`step` 走新增 `gpuiEnqueueString`。
- [x] **16-D** 验收：`openChangeDelivered` 从读数升级成硬门，读数
      `focus=-1001 SelectDown=true open_change 到 PHP=true 值=true confirm/dismiss=0/0`；
      同一支连跑 **6/6 rc=0**（修前 4/6 崩）；全量 **14 支 selftest rc=0 · 44 棵像素树全绿**。
      计数订正：像素树一直是 **44** 棵（此前记的 43 少数一棵，按 `tests/baseline/**` 独立数过）。

**这一轮的负向对照是"读到的"而不是"重新制造出来的"**：修前的 `open_change=0 evSeen=（空）`
来自同一支在本次补丁之前的构建，我没有为了对照再把补丁摘一次（那要再付一轮完整链）。
写进 commit 与文档时按这个口径，不冒充"摘掉补丁复现红"。

- [ ] **待办**（已开任务，不在这轮做）：
      ①`confirm`/`dismiss` 还缺 PHP 侧那颗"这颗 select 现在是开着的"糖（`behavior.open` 无人能设）；
      ②`on_resize`（float 数组）与 `on_item_secondary_click` 是同形状未接的两处；
      ③catalog 触发器在 `paint.hits` 里没有矩形（#73 剩下的那一半）；
      ④`Box2::enqueue()` 对未知 callbackId 仍会压一条 type/id 全空的事件进队列——
        现在多了几个"合法但没人监听"的 id 走这条路，`onAny` 通配记录器会看见空事件（本轮实测画廊
        `evSeen` 里没有空条目，所以还没改；要改就是 enqueue 里 `it == end()` 直接返回）。
- [x] **16-E** `El::open(bool)` 补上（16-D 留下的那颗糖）：上游 `materialize.cpp:339-341` 认
      `open` 这个 bool op 并把它放进 `behavior.open`，`Select::New` 再取进 binding，
      而 `SelectActionOf` 完全按这个位解释动作。没有它，`confirm`/`dismiss` 是"补丁通了也发不出"。
      choices 域加 `selectOpen` 状态位（默认 false ⇒ 画廊像素一棵没变），自检渲染第二棵去量它。
      读数：`confirmDismissDelivered — open=true 那棵 focus=-1001 Confirm=true confirm 到 PHP=true
      dismiss 到 PHP=true open_change 收尾=false`。
      **负向对照做过**：只摘 `->open(...)`（补丁留在原地）⇒ 同一条变
      `confirm 到 PHP=false dismiss 到 PHP=false open_change 收尾=true`、rc=1。
      送达名字总数随之从 14 订正成 **17**（`grep -c "callback('on_" src/Element.php` 仍是 24，
      在册与送达是两个数）。全量：14 支 selftest rc=0 · 44 棵像素树全绿。

**待办改口**：#73 现在只剩"catalog 触发器在 `paint.hits` 里没有矩形"这一半；
`resize`/`item_secondary_click` 的同形状派发仍未接（`cmd/patch-select-view.sh` 头注释点名）。
