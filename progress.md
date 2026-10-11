# Progress Log

## Session: 2026-10-10

### Phase 1: 架构设计与桥接契约定义
- **Status:** complete
- **Started:** 2026-10-10 22:31
- Actions taken:
  - 初始化 planning-with-files 三件套（task_plan.md / findings.md / progress.md）
  - 检查 session-catchup：无历史 session，空目录起步
  - 用 context7 MCP 查询了 gpui-kit-cpp（不在索引）、typephp（不在索引）、gpui（Rust 原版在索引 `/websites/rs_gpui`）
  - WebFetch 抓取 GitHub 仓库 README、hello_world.cpp、src/ 目录结构
  - 用户指定本地源码路径 `/Volumes/data/git/c/gpui-kit-cpp`，改为直接读本地文件
  - 读取 gpui.h 核心类型定义（App/Window/Ctx/El/Style/EntityId/Listener/InputState/SliderState/Theme/RuntimeStyle 等），覆盖约 7737 行关键结构体
  - 读取 base.h 基础类型（Str/Arena/Vec/Func0/Func1/Mutex/Panic/PointF/SizeF/RectF/LocalDate/LocalTime/StrBuilder/FmtArg/fmt/logf）
  - 读取 ui/button.h、ui/input.h、ui/theme.h、ui/sizing.h、ui/lib.h、base/root.h
  - 读取 shell/spec.h、shell/view.h、shell/materialize.h、shell/runtime.h、shell/snapshot.h、shell/value.h、shell/component_registry.h、shell/host.h、shell/root.h、component_shell/lib.h
  - **深入读取 shell/materialize.cpp**（2806 行完整读取）：确认 ScriptView→RenderSnapshot→SpecArena→MaterializeNode→El* 的完整 materialize 路径，这是决定桥接方案的关键发现
  - **深入读取 shell/style.cpp**（1704 行完整读取）：确认 nullary style（kKeywords[] ~150 关键字）+ parametric style（家族前缀 + tailwind ramp 后缀文法）两套样式应用机制
  - **深入读取 shell/spec.cpp**（766 行完整读取）：确认 SpecNode/SpecOp/Bridged/SpecArena 数据模型和 Push/PushOp/Attach/Graft 操作
  - 读取 typephp-qt README.md、composer.json、examples/hello/project.yml
  - 读取 typephp-compiler README.md、examples/typephp-os/user/nano/hello.php
  - 确定桥接方案：**复用 ShellRuntime 的 spec/materialize 通道**——PHP Element builder 内部累积 SpecOp 列表，bridge C++ 一次性写入 SpecArena，ShellMaterializeSpec 翻译成 El 树渲染
- Files created/modified:
  - task_plan.md (created, updated phase status)
  - findings.md (created, added materialize/style/spec 三大发现)
  - progress.md (created)

### Phase 2: 项目骨架搭建
- **Status:** complete
- **Started:** 2026-10-10 23:15
- Actions taken:
  - 创建目录结构 cmd/ php-src/ cpp-src/ src/ tests/ examples/hello/src/
  - composer.json（yangweijie/typephp-gpui，require swoole/typephp，autoload TypePHP\Gpui）
  - examples/hello/project.macos.yml（tpc 入口：sources 含 main.php+App.php+Element.php+stub+bridge+amalgam gpui.cpp+quickjs.c+AppLog.cpp；macOS framework ld-flags）
  - vendor/gpui-kit-cpp symlink → /Volumes/data/git/c/gpui-kit-cpp
  - src/Element.php：链式 builder，kind/text/value/ops[]/children[]，~40 个样式方法 + 行为方法 + onClick/onChange/onHover 回调记录
  - src/App.php：run/create/createWindow/view/on/onAny/dispatch/render/loop/runFrames/poll 分派（arityOf 反射探测，AOT 精确实参）
  - src/El.php：class_alias Element→El
  - src/FakeBridge.php：纯 PHP 桥接替身（windows/eventQueue/renderLog），autoload-dev.files 注入全局函数
  - php-src/gpui.stub.php：gpui_app_create/window_open/render/pump_events/poll_event/input_value/set_timer/set_theme 契约
  - cpp-src/gpui_bridge.cc：GpuiBox(php::Box) 持有 Window/ScriptView entity/SpecArena/callbacks map/event queue；php_gpui_* 实现；buildSpecTree 留 Phase 3
  - cpp-src/gpui_bridge_events.h：gpuiEnqueue* 声明
  - cmd/patch-view.sh：python3 sed 替换 view.cpp 的 OnClick/OnChange/OnHover/OnInputEvent/OnSliderEvent 为 gpuiEnqueue*()，**内联 weak 桩定义**（避免新头文件污染上游 amalgam include 图）
  - tests/ElementTest.php：PHPUnit 6 tests（div/text/button/style chain/children/callback + App render/click handler/zero-arity/onAny/loop）
  - examples/hello/src/main.php：点击计数示例
- Files created/modified:
  - composer.json, project.macos.yml (created)
  - src/{Element,App,El,FakeBridge}.php (created)
  - php-src/gpui.stub.php (created)
  - cpp-src/{gpui_bridge.cc,gpui_bridge_events.h} (created)
  - cmd/patch-view.sh (created)
  - tests/ElementTest.php (created)
  - examples/hello/src/main.php (created)
  - vendor/gpui-kit-cpp (symlink)

## Test Results
| Test | Input | Expected | Actual | Status |
|------|-------|----------|--------|--------|
| PHPUnit ElementTest | 6 tests | all pass | 18 assertions OK | ✅ |
| patch-view.sh idempotent | run twice | marker skip | works | ✅ |
| gpui-kit-cpp build w/ patch | bun build hello_world | rc=0 links | 8MB binary built | ✅ |
| bridge syntax-check c++20 | clang++ -fsyntax-only (phpx+gpui.h) | exit 0, -Wall 0 warn | 全套 gpui API 调用类型正确 | ✅ |
| firewall c++17 (bridge only, no gpui.h) | clang++ -std=c++17 syntax | exit 0, 0 warn | C-ABI 防火墙成立 | ✅ |
| nano Core-dep fix | run hello after patch-nano | dep "Core" resolved | 扩展启动通过 | ✅ |
| **e2e binary run** | examples/hello/build/hello (8.9MB) | alive 5s, 0 stderr | lsappinfo 确认带窗口 GUI app | ✅ |
| patch-retained compiles | bun build after patch | gpui.o -Werror ok | CreateRetainedInput/Slider 进 amalgam | ✅ |
| Input/Slider firewall | shim+bridge syntax + nano build + run | alive 4s, 0 stderr | retained InputState 创建+materialize 反复不崩 | ✅ |
| --selftest input round-trip | hello --selftest | rc=0 "SELFTEST OK" | setInput→InputSetValue→InputValue→text 真实回环验证 | ✅ |
| --selftest click event | hello --selftest | rc=0 "...+ click event" | 真实 gpuiEnqueueClick(strong 覆盖)→boxForView 反查→队列→poll→dispatch→handler 全链路 | ✅ |
| **--shot 机制** | hello --shot x.png | rc=0 真实在屏 PNG | 初版 WindowDrawFrame 离屏不跑完整布局(位置错)→改抓真实 NSView cacheDisplayInRect(两步:bitmapImageRepForCachingDisplayInRect+cacheDisplayInRect) | ✅ |
| Rust 上游关键字核对 | 对照 /Volumes/data/git/rust/gpui-kit crates/shell/src/style.rs | 发现 C++ port nullary 表缺 size_full/rounded_*/无参border | Element 改: sizeFull/wFull/hFull→parametric size/w/h+"100%"；roundedMd/Lg/Full→parametric rounded 6/8/9999；border()→parametric border 1.0；删 overflow_x/y_scroll(C++ 无) | ✅ |
| **#18 渲染 bug 修复** | DebugTree ground truth | 根因=字符串悬垂(arena 不拷贝 Str)+Button 标签应为子元素 | shim 加 AStr(StrDup) 拷所有进 arena 字符串；Element button/link 标签改子 text；sizeFull 参数化 | ✅ |
| Stage 2 定时器 | selftest timer | rc=0 timer hits=20 | WindowSetInterval+gpuiEnqueueTimer 强符号+Box2 timerKeys/timerIds，零 gpui 补丁 | ✅ |
| Stage 3 Slot+组件 | --shot gallery tree10 | popover/radio/toggle/table 渲染 | gpui_shim_slot(Claim+Slot op)+Element slot()+toArray 递归+kindFromString 20 新 kind+工厂 | ✅ |
| Stage 4 Markdown/Select/DatePicker | --shot tree11/12 | markdown 富文本+select 选项渲染 | patch-focus.sh 加 CreateRetainedFocus；TextView policy=null；DatePicker/Select focus | ✅ |
| Stage 5 Dialog/Toast | --shot tree13 + selftest | 模态 dialog+遮罩+toast 渲染，dialog 开/关断言 | 窗口根→ShellRoot(WindowOpenView 绕 Base Root)；dialog 独立 Box2 复用 push/attach；ShellRootPushToast | ✅ |
| Stage 6 List | --shot tree14 | 50 项可原生滚动 | overflow_y_scroll 是 method/behavior op（非 nullary），恢复为 method op；gpui 原生滚动 | ✅ |
| Stage 7 Dock | 设计交付 | 见 findings.md | chrome 4 callback 需回调进脚本(不可达，同 List)；推荐 PHP 自绘或 materialize 大改 | 📐 |
| Pest 测试迁移 | vendor/bin/pest | 13 tests / 31 assertions 全绿 | 暴露并修复 FakeBridge 命名空间 bug（旧 PHPUnit 下 AppTest 6 测试一直休眠） | ✅ |

## Error Log
| Timestamp | Error | Attempt | Resolution |
|-----------|-------|---------|------------|
| 2026-10-10 23:47 | better-harness skill not found | 1 | 跳过，继续 planning-with-files |
| 2026-10-10 23:50 | fetch MCP tool rejected by user | 1 | 改用本地文件读取 |
| 2026-10-10 23:55 | PHPUnit gap assert 8 vs 8.0 (float param) | 1 | 改断言为 8.0 |
| 2026-10-11 00:00 | patched view.cpp include 新头文件破坏 amalgam 编译 | 1 | 改为内联 weak 桩定义，不引新头 |
| 2026-10-11 00:4x | nano 要求 c++17 但 gpui.h 用 C++20 concept/requires（硬错误） | — | 定策：C-ABI 防火墙，amalgam+shim 独立 c++20 预编译成 .a，bridge 只走纯 C |
| 2026-10-11 00:5x | tpc 以 yml 目录为相对路径基准，`../src` 解析到 examples/ 落空 | 1 | 框架源码路径改 `../../` |
| 2026-10-11 00:5x | nano 缺 swoole/php-nano 包 | 1 | composer require swoole/php-nano (v1.1.0) |
| 2026-10-11 00:5x | ld-flags 里 libgpui_shim.a 相对路径在链接 cwd 解析不到 | 1 | 改绝对路径 |
| 2026-10-11 00:5x | El.php class_alias 触发 AOT「stray code」；El extends final Element 又冲突 | 2 | Element 去 final + `class El extends Element` |
| 2026-10-11 00:5x | App.php arityOf 复用 $ref 跨 ReflectionMethod/Function，AOT 类型推断拒绝 | 1 | 分离 $methodRef/$functionRef（照搬 qt） |
| 2026-10-11 00:5x | 运行报 "Unable to start PHP Nano extensions" | 插桩定位 | find_available 只扫数组、漏 module_registry 的 "Core" → patch-nano.sh 回落修复 |
| 2026-10-11 05:4x | Pest 迁移后 AppTest 6 测试报 undefined gpui_app_create() | — | FakeBridge 的 `namespace TypePHP\Gpui;` 把 gpui_* 函数也裹进该命名空间，而 App 调全局 `\gpui_*`；旧 PHPUnit 下 AppTest 与 ElementTest 同文件、按文件名约定只跑 ElementTest，故从未暴露。改花括号命名空间（类在 TypePHP\Gpui、函数在全局），并把 AppTest 拆到独立文件 |
| 2026-10-11 06:1x | **开放 BUG：渲染树子元素缺失/布局错** | --shot 首见 | 完整 demo(5 children) 只见 text#1(水平居中)+input 占位符(贴左缘)；text#2/slider/hFlex(按钮) 缺失；垂直未居中(justify_center 需确定高度)。最小树(2 text)两子都在且水平居中 → 怀疑 retained(input/slider) 或嵌套容器(hFlex) 干扰兄弟 attach/materialize，或根高度未撑满。**待查**：emitTree attach 顺序/SpecArena id、retained handle 副作用、根 sizeFull 高度为何不生效 |

## 5-Question Reboot Check
| Question | Answer |
|----------|--------|
| Where am I? | Phase 3 完成：nano 端到端跑通，hello 二进制带窗口存活 |
| Where am I going? | Phase 4 Retained 输入/滑块/定时器取值 + Table/List/Tabs/Scroll/浮层；Phase 5 --selftest/--shot 验收；Phase 6 交付 |
| What's the goal? | 用 TypePHP 参考 gpui-kit-cpp 重新实现 PHP 原生桌面 GUI 框架 |
| What have I learned? | nano 锁 c++17 与 gpui c++20 冲突 → C-ABI 防火墙是正道；php-nano v1.1.0 有 "Core" 依赖解析缺口（已 patch）；AOT 禁 class_alias/跨类型复用变量 |
| What have I done? | 拆 shim/bridge 防火墙 + build-shim 静态库 + patch-nano 修复 + nano 编出可运行 GUI 二进制 |

---
*Update after completing each phase or encountering errors*

## Session 2026-10-11（工程加固 P0→P1）

用户选定序列：P0 可复现构建 → P0 内存/错误回传 → P1 C++ 测试+CI。文档站定用 VitePress（留 P2）。

**P0 可复现构建**（commit 4c7af65 + 基线 d3eeac1）
- git init（此前非仓库）+ 基线提交 29 源/配置文件。
- gpui-kit-cpp 无 git 历史 → 无法 submodule/SHA，改 `GPUI.lock` 契约 + `bin/gpui verifyPatches()` 逐 marker 校验（doctor 展示、build 缺则中止）。
- `project.macos.yml` shim .a 绝对路径 → `@ROOT@` 占位，编译期解析为 `project.macos.gen.yml`（tpc 只认 .yml 扩展名，初版误用 `.gen` 后缀被拒，已改）。
- `bin/gpui build` 补丁列表补 `patch-registered-cb.sh`（原漏 Stage D）；`composer patch` 同步、加 `bootstrap`。`bin/gpui build hello` rc=0 端到端。

**P0 内存/错误回传**（commit 6dd3718）
- 回调按 `(elemId,type)` 幂等复用（`cbByKey` + `shimCallbacks` 缓存跳过重复 PushShimCallback）→ 消每帧无界增长。
- `gpui_shim_close` 释放 dialog Box2（无 PHP 句柄引用，安全）+ 清 timer 映射；主 Box2 保守不删（防 UAF）。
- registered 失败原因落 `Box2.lastError`（render_begin 每帧清）+ `gpui_shim_last_error`/`php_gpui_last_error`/`App::registeredError()`（避开既有 `lastError()` handler 异常属性——命名冲突已修）。
- selftest 加 step6（空 id Alert → 非空错误）；六链路 `SELFTEST OK`。

**P1 C++ 测试 + CI**（commit 45e2886）
- `tests/cpp/shim_test.cpp`（#include shim TU）headless 测 kindFromString/AStr(bug#18)/toComponentArg/buildComponentArgs；`cmd/test-shim.sh` 两步编链（-x 只作用 .cpp）。`SHIM CPP TESTS OK`。
- `.github/workflows/ci.yml`：php -l 全源 + Pest（自足层，本地模拟 lint_fail=0 / 22 passed）。原生链依赖拉不到的 gpui-kit-cpp → 暂不进 CI，注明。

**验证汇总**：Pest 22/84；C++ shim_test OK；selftest 六链路 OK；demo/catalog 树出图；bin/gpui build 一键 rc=0；doctor 五补丁全绿。

**遗留（P1/P2）**：--shot 基线 diff、静态分析(phpstan/clang-tidy)、MenuBar root-app、A 类 VirtualList/Dock、kind 覆盖、rm()/cb() 抽象泄漏、VitePress 文档站。

## Session 2026-10-11（P2，续）

用户定序：先 4+1（低风险），2+3（MenuBar/Dock/VirtualList）归 root-app epic。

**P2-2/2-3 调查 → 归 epic**（未实现，findings.md 有完整证据链）：MenuBar 与 Dock/VirtualList 同属"需 gpui ScriptView root-application"类——`AppEffects`→`ScriptViewApplication(cx->self)` 要真 ScriptView 根，`ScheduleComponentAppEffect` 把延迟效果 post 到 `ScriptView::OnComponentAppEffect`，catalog `OpenWindowWithRoot` 用基座 `Root` 才给 root-app，而基座 Root 破 `ShellRootOf`→Dialog/Toast。与 ShellRoot 根模型互斥，强做威胁 6 项绿门禁 → 需专门设计。

**P2-4 ✅**（commit c34409e）：Element 加枚举常量（SIZES/SIDES/COLLAPSE_MODES/FIELD_ALIGN/NAV_ICONS/ALERT_VARIANTS，对齐 descriptor）+ `enumCheck`；sugar 方法 side/collapsibleMode/navIcon/align + 新 sizeEnum render 前预校验抛 InvalidArgumentException；alert 未知变体从静默回落改抛异常。行为等价（sizeEnum 产同 op，shotdiff 基线不变即证）。Pest +3 → 25/100。

**P2-1 ✅**（commit 6f0c896）：VitePress 文档站 `docs/`——index + guide{getting-started, architecture, components, events, testing, cli} + contributing。`npm install`(125 包)+`docs:build` rc=0（8 页渲染）。node_modules/dist 由 docs/.gitignore 排除，package-lock 入库。

**门禁全绿**：Pest 25/100 · PHPStan 0 · shim_test OK · selftest 六链路 OK · shotdiff OK（12 树）· doctor 5 补丁 ✓。

**本轮全部提交**：d3eeac1 基线 → 4c7af65 P0 构建 → 6dd3718 P0 内存/错误 → 45e2886 P1 C++测试+CI → 5e4c4d9 progress → 3ce13fa P1 shotdiff+修double-free → 38af807 P1 phpstan → 769cd06 修段错误 → c34409e P2-4 枚举校验 → 6f0c896 P2-1 文档站。

**剩余（唯一）**：root-application 集成 epic（MenuBar + VirtualList + Dock）——需先定窗口根模型方案（重做 root / 给 AppEffects 加 shim 旁路 / 换渲染路径），风险面大，留待专门排期。


## Wave 1：系统能力桥接（2026-10-11，计划 hollow-pool-lark）

**做了什么**：把 `gpui.o` 里已验证存在、但没接出的 6 项桌面能力包成 PHP API，零上游补丁、零新依赖。

- `cpp-src/gpui_shim.h/.cpp`：+11 个 C ABI 函数（剪贴板读写、`PromptForPathTemp`、`OpenUrl`+
  `IsOpenableUrl`、`WindowToggleInspector`、FPS HUD 三件套、Sheet 三件套）。新增 `cstr()` /
  `copyOut()` 两个小工具（Str↔char* 的第三条路，之前每处手抄 memcpy）。Sheet 复用
  `gpui_shim_dialog_begin` 建的内容 Box2，不新建盒子。
- `cpp-src/gpui_bridge.cc`：+11 个 `php_gpui_*`。剪贴板缓冲开在堆上 64 KiB（粘贴板无长度上限）。
- `php-src/gpui.stub.php` +11 声明（返回类型与 bridge 实际返回严格对齐：`String`↔`string`、
  `Bool`↔`bool`、`Int`↔`int`——上一轮 `gpui_app_create` 的段错误就是这条错位换来的）；
  `src/App.php` 新增「系统能力」段 + `SHEET_ANCHORS`/`FPS_ANCHORS` 常量 + `oneOf()` 校验；
  `src/FakeBridge.php` 镜像全部 11 个函数 + `isOpenableUrl()`（按 `capability.cpp:5` 逐条重写，
  含端口与 IPv6 字面量）。
- **`openSheet`/`showFpsHud` 走"越界即抛"**，与 P2-4 的 `rm()/cb()` 收口同一条风格。

**测试**：
- 新 `tests/SystemTest.php` 12 例（wrap-green）。C++ 侧 `testCapabilityGates()`：20 条 URL
  表 + 8 个 FPS 锚点名 + `copyOut` 截断/空串/null 缓冲（native-green）。**URL 表两侧同内容**：
  C++ 量真实 `gpui::IsOpenableUrl`，PHP 量替身，把"替身像不像"这件事钉成可证的。
- `examples/hello/src/main.php`：selftest 6 步 → **11 步**（+剪贴板回环、`openUrl` 拒绝侧、
  FPS 生命周期、Sheet 生命周期）；`--shot` 新增 tree 27/28/29；交互 demo 加 `Open…` 按钮，
  作为原生对话框的**手工**验收路径（它要等人，进不了无人值守自检）。
- **`promptForPath` 的嵌套消息循环已确认安全**：PHP 阻塞在桥接调用内时 `gpui_shim_pump` 不被
  驱动，gpui 的模态循环只入队不重入 VM。约束写进 shim 头、bridge 注释、App docblock、文档四处。

**顺手修的两个真 bug**：
1. `tests/Pest.php` 的 `uses()->beforeEach()` **从来没跑过**（`UsesCall` targets 为空）⇒
   `FakeBridge::reset()` 失效、静态状态跨测试泄漏。改 `uses(TestCase::class)->in(__DIR__)
   ->beforeEach(...)`，并读 Pest 5.3.1 源码确认裸 `beforeEach()` 在 Pest.php 里同样打不中
   （注册键是声明处文件名，查询键是测试自身文件名）。原有 25 例恰好不依赖 reset 才一直没暴露。
2. `FakeBridge::reset()` 漏了 `$appMenuCount`（上一轮加的状态没进 reset）。

**门禁全绿**：Pest 37/155 · PHPStan 0 · shim_test OK · **selftest 11 链路 OK** ·
shotdiff OK（**15 树**，新增 3 张已逐张肉眼确认非空帧：HUD 有数、Sheet 有侧栏+遮罩、
inspector 是整窗拾取高亮）· doctor 6 marker ✓。

**已知非确定项**：tree 27 是唯一非零残差（0.00018~0.00019，HUD 是活表），在容差内。

**文档**：新 `docs/guide/system-capabilities.md`（已接 / 端口里有但没接 / 端口里根本没有 三张表 +
三条硬约束 + 手工验收清单），订正被继承的 Rust-only 前提；`components.md` 与 `testing.md` 同步。

**Wave 2 的设计已换**：原设想"shim render-pass 特例"，实测 `ComponentKind::ChildView`
（`materialize.cpp:2045`）就是"把任意 entity 当元素挂进树"的现成机制，WebView 与 Inspector
面板共用它，只差一个 host 侧 `nestedViews` 注册入口（第 7 个补丁 marker）。见 findings.md。

## Wave 2（部分）：ChildView 打通 → WebView + Inspector 面板（2026-10-11）

**Wave2-A ✅** 第 7 个补丁 `cmd/patch-nested-view.sh`：`ShellRuntime::RegisterHostView(EntityId, App*)`。
已进 `bin/gpui` 的 patchManifest 与 build 序列、`composer patch`、`GPUI.lock` 表；`doctor` 现校验 7 个 marker。

**Wave2-B ✅** WebView：`El::webview($url,$id)` / `El::webviewHtml($html,$id)` → shim 侧
`WebViewView` 包装 entity（`gpui::WebView` 自己没有 Render）→ `RegisterHostView` 拿 token →
压 `ComponentKind::ChildView`。同 id 跨帧复用同一控件，换内容走 `WebViewLoadUrl` /
`wry::WebViewLoadHtml`（不重建，避免再跑一次嵌套消息循环）。`gpui_shim_webview_probe` 读
`wry::WebViewUrlTemp` + `WebViewBounds`。`gpui_shim_close` 在 `AppClose` **之前** drop，
满足"OS 控件要在父窗口销毁前放手"。

**Wave2-C ✅** Inspector 面板：`El::inspector($id)` 走同一条 ChildView 路。像素实测画出了
右侧 320px 面板（拾取按钮 + 标题 + 关闭 + "Pick an element to inspect it."）。前提是先
`toggleInspector()`——`IntoEl()` 关着返回 null 是 inspector.h:63 的契约。

**Wave2-D ❌ 取消**（不是没做完，是不该做）：`hello --caps` 实测产物里 `hash()`/`random_bytes()`
都在 ⇒ 桥 sha256/secureRandom 是净负价值；`shell::ZlibDeflate` 写 stored 块（不压缩）⇒ 不桥。
详见 findings.md。**Wave2-E 范围据此改**：`HttpSendAsync` + `DirWatchStart` + `ZlibInflate`
三个才是真缺口（curl/socket/inotify/zlib 在产物里全空）。

**顺手**：`bin/gpui shot` 之前只转发 path、吞掉 tree 参数（`shot hello x.png 30` 静默出 tree 0）；
已转发第三参。

**门禁全绿**：Pest 41/177 · PHPStan 0 · shim_test OK · **selftest 12 链路**（webview 报回
400×300 的应用盒子）· shotdiff OK **17 树**（tree30/31 新增，已肉眼核过）· doctor **7 marker** ✓。

## Wave 2-E：真缺口服务（http / dirChange / inflate）（2026-10-11）

按 `--caps` 重定范围后做完：`gpui_shim.h/.cpp` +5 个 C ABI（http_get / http_response /
http_free / watch_dir / unwatch_dir / watch_fake / inflate）、`gpui_bridge.cc` +6 个
`php_gpui_*`（二进制一律走 `String(ptr,len)`，两趟式取长度再填缓冲）、stub +6、`App` 新增
`httpGet/httpResponse/watchDir/unwatchDir/inflate`、`FakeBridge` 镜像 + 脚本化应答表。

**验收**：selftest 12 步 → **15 步**（解压读外部真数据 / dirChange 走官方测试接缝不碰
文件系统 / http 打 loopback 关闭端口，只验失败路径与一次性读取）。`shim_test.cpp` 加
`testGapServices()`：外部向量两解 + 坏数据不崩 + Deflate→Inflate 回环 + 容器头断言 +
`HttpUrlIsRemote` 判据表。Pest +6 例。

**门禁全绿**：Pest **46/196** · PHPStan 0 · shim_test OK（6 组）· **selftest 15 链路** ·
shotdiff OK 17 树 · doctor 7 marker ✓。

**Wave 2 收口**：A（补丁）/B（WebView）/C（Inspector 面板）/E（三个缺口服务）做完；
D 取消（证据见 findings.md）。剩下的是 剪贴板图片、SysRefresh、FsRun/Process/Storage
（要先解 Capabilities 授权根）、应用内通知中心、以及 mac 系统集成（托盘/快捷键/通知/Dock，
自写 .mm）。

## 剪贴板完整一项（三通道）（2026-10-11）

`gpui_shim_clipboard_item` 一次快照读 text/image/paths，flags 位标志 + 三个显式长度出参；
mac 的**文件路径由我们自己读** `NSPasteboardTypeFileURL`（gpui 只有 Windows 填这个）。
图像是编码流（PNG→TIFF），所以新加 `copyBounded` 走二进制安全通道，bridge 采"无图一趟、
有图整笔重取"以避免混用两个快照。PHP：`App::clipboardItem(): {text,image,paths}`。

**验收**：selftest 15 → **16 步**（第 16 步验文本通道 + flags 判别）；shim_test 加
`testCopyBounded()`（dry run / 截断仍报真实长度 / 含 NUL / 负数与空指针）；Pest +2 例
（三表示并存、纯图像粘贴板）。**另加 `hello --clip`** 把"能改粘贴板的东西"变成测试装置，
并用 AppKit `writeObjects:` 真数据实测四种组合，结果记进文档表格。

**门禁全绿**：Pest 48/204 · PHPStan 0 · shim_test OK（7 组）· selftest 16 链路 ·
shotdiff OK 17 树 · doctor 7 marker。

## 系统状态 `App::sysInfo()`（2026-10-11）

`gpui_shim_sys_refresh` 包 `SysStateInit/SysRefresh/SysSortProcesses`：概览 + 可选前 N 进程
（原生侧按 CPU 降序截，上限 256）。采样状态进程内一份（墙钟差本身是文件静态量），所以
`App::sysInfo()` 是静态方法、无窗口参数。差值量与直接读数分开断言，语义写进 ABI 头、
App docblock 与文档三处。

**验收**：selftest 16 → **17 步**（第一次 cpu 必须 0 → 烧 150ms 单核 → 第二次必须 >0，
实测 cpu=46.2% mem=80% procs=5）；Pest +3 例（字段全形、disk 拿本机文件系统对照、
脚本化覆盖 + topProcs 截断）。

**门禁全绿**：Pest **51/217** · PHPStan 0 · shim_test OK · **selftest 17 链路** ·
shotdiff OK 17 树 · doctor 7 marker。

## 应用内通知中心（2026-10-11）

`App::notify()/dismissNotification()/clearNotifications()/notificationCount()` +
`El::notifications($id)`，底下是 `component::NotificationPush/Dismiss/Clear` 与
`NotificationList::IntoEl()`。带 `$id` 的推送会接上 `click`/`close` 两条 listener，
`close` 时把这一对键一起摘掉（注册表只跟活通知成正比）。

**撞到的墙**：`WindowNotifications(cx)` → `WindowLayersOf` → `Root::Read(win)`，要的是
**基座 `Root`**，而我们的窗口根是为 Dialog/Toast/Sheet 换上的 `ShellRoot`——两者互斥，
直接调就 SIGABRT（"component window state is missing"）。核对 `notification.cpp` 全篇不读
`Root`/`Layers` 之后改成：状态 entity 由每个窗口自己持有一份（`EnsureNotifyState`，
`self`/`useThemeSettings` 手工补齐，否则 `NotificationStartAdvancing` 会因为 `!self.IsValid()`
直接不启动计时器），层由 PHP 显式摆一颗 `El::notifications()`。这与 MenuBar 那面墙同源，
但通知层这边**没有** root-app 依赖，所以绕得过去。

**像素这一关差点被骗过去**：`--shot` 报 `notify_count=2` + `SHOT OK`，图里却只有宿主标题。
原因是入场动画——`Starting` 段不透明度 = `elapsedMs/400`（`notification.cpp:948`），推它的是
50ms 墙钟计时器，只泵 3 帧等于拍在 0 上。改成 80 帧后出图，且与 600 帧的产物**逐字节相同**，
说明拍到的是动画走完的静止帧。两条通知故意分放 `top_right`/`bottom_left`：同一角会叠成
collapsed stack（新的在前、旧的只露 14px 一条边），分开才两张都拍全，顺带把 `placement`
的锚点映射钉在像素上（边距 16/50、宽 382 即主题默认值，反证 `useThemeSettings` 那条路通）。

**已知外观差**：卡片上没有类型图标。同一现象在 tree13 的 toast 基线里也有（那里显示成被裁掉的
"succe"）。属本端口图标字形未落地，与本次改动无关，未深究。

**验收**：selftest 17 → **18 步**（推→计数 1→dismiss→泵帧后计数 0 且 `close` 事件打回 PHP）；
`click` 那一侧不进自检（合成指针打不到浮层），改为静态核对 `OnItemClick → items[at].onClick`
接线 + 交互 demo 新增 `Notify` 按钮做人工路径。Pest +5 例（枚举校验、id 才接线、close 摘一对、
无 id 不产事件、层是树里的显式节点）；shotdiff 17 → **18 树**（新增 tree32 基线，摆法用
推荐的 `El::notifications('notes')->sizeFull()->absolute()`——绝对定位不参与外层排布，
demo 与基线同一形状，像素验的是同一个写法）。

**门禁全绿**：Pest **56/237** · PHPStan 0 · shim_test OK · **selftest 18 链路** ·
shotdiff OK 18 树（tree32 gdfrac=0，tree27 0.00018 为 HUD 活表）· doctor 7 marker。
交互 demo 另跑了一次启动冒烟（后台 3s 存活、无崩溃输出）。

## 会话收尾（2026-10-11，通知中心之后）

**工作树**（当时）：19 个文件改动 + 1 张新基线（`tests/baseline/hello/tree32.png`）。
**后来已提交**：`5b4e6bd` feat（12 文件 / +604）+ `d2cf825` docs（8 文件 / +219）。

**本轮改到的面**：`cpp-src/gpui_shim.{h,cpp}`、`cpp-src/gpui_bridge.cc`、`php-src/gpui.stub.php`、
`src/{App,Element,FakeBridge}.php`、`tests/SystemTest.php`、`tests/cpp/shim_test.cpp`、
`examples/hello/src/main.php`（selftest 第 18 步 + tree32 + demo 的 `Notify` 按钮）、
`cmd/shotdiff.php`（基线树集 +32）、文档 5 篇、`findings.md`、`progress.md`、`task_plan.md`。

**门禁数字（同一份产物上跑的）**：Pest 56/237 · PHPStan 0 error · shim_test OK（8 组，含
`notify type/anchor mapping`）· selftest 18 链路 · shotdiff 18 树（tree32 gdfrac=0，
tree27 0.00018 = HUD 活表）· doctor 7 marker ✓ · demo 启动冒烟 3s 存活。

**下一步候选**（按 ROI）：
1. **Phase 9 mac 系统集成**（`cpp-src/gpui_mac.mm`：托盘 / Carbon 全局快捷键 /
   `NSUserNotification` / Dock 角标）——不需要外部库，但验收只能手工 + existence-green。
2. `FsRun` / `ProcessRunBounded` / `Storage`——先设计 `Capabilities` 读写根，不是包装活。
3. 图标字形不画这件事（通知卡与 toast 基线都受影响）——是端口的既有缺口，范围不明。
4. root-app epic 三面墙（catalog MenuBar / VirtualList / Dock 同步取值）维持"防火墙不可达"
   结论，除非改防火墙契约。

## 下一波取证：图标归因被推翻，两个真 bug 浮出（2026-10-11）

派了一个 Explore agent 去查"图标字形为何不画"，它交回一份带 file:line 的结论（字体从未落地、
要上游改）。**逐条核对后作废**：它引用的 `src/base/icon_font.cpp`、`assets/iconfont.ttf` 在
这个仓库里不存在（`ls` 直接 ENOENT），`WindowLoadIconFont` 也搜不到。自己查的结果相反：

- 图标链路是**完整**的：111 个 lucide 图标预转 drawops 字节码编进 `src/gpui/asset_icons.cpp`
  （`kAssetIconsDataLen=33038`），查找在 `svg.cpp:1366-1392`，mac 画在 `gpui.cpp:8152-8166`
  的 `SvgDraw`。通知要用的 `circle-check`/`triangle-alert`/`info`/`circle-x` 四个名字都在表里。
- **bug 1（一行）**：`gpui_shim_notify` 只写 `item.type`，没写 `item.hasType`，而
  `notification.cpp:982` 的门是 `if (item.hasType)` ⇒ 卡片永远没图标。
- **bug 2（P0，内存安全级）**：`cppStr` 只收 `const std::string&`，用 `const char*` 调它会造
  临时 `std::string`，SSO 短串随语句结束析构 ⇒ 返回的 `Str` 悬垂。命中窗口标题、input
  placeholder/value、`InputSetValue`、toast title/description 五处。tree13 那个诡异的
  `"succe"` 就是它（`{死栈槽, len=5}` 被后面的 `"success"` 临时对象复用），不是字体问题。

顺带把两件"成本被高估/低估"的事量清了：`Capabilities` 不是要重新设计的墙（`FsRun` 只要
root+relative，`ResolvePath` 是选根工具，五个符号 `nm` 实测 `T`，POSIX 侧 `openat`+`O_NOFOLLOW`
是真实现）；而 Phase 9 的 mac 集成有两条**必须先探针**的风险（我们的泵没有
`RunCurrentEventLoop`，Carbon 快捷键派不派发未知；产物是裸 Mach-O 无 bundle id，
`NSUserNotification` 可能被静默丢弃）。

计划已重排：Phase 9 = 两个真 bug（先做）／Phase 10 = mac 集成（含两条探针）／
Phase 11 = Capabilities 三服务。findings 记了三节，Errors 表补两条（错归因、agent 编造引用）。
**代码未动**，本轮只取证与写计划。

同一 agent 重跑后交回第二份**引用可核**的报告（`AssetIconForPath` 的字面形状要求、查不到就静默
不画、上游 `DrawIcon` 兜底只存在于文档里、`home` 不在编译表内）——逐条 `sed`/`grep` 验后采纳，
据此多出**第三处我们的 bug**：demo tree21 的 `El::icon('icons/home.svg')` 形状对、名不在表里，
基线已把那里录成白的。不采纳它的两条：`"succe"` 是"标题裁切"（裁切会留前缀 `"Sav"`，解释不了
另一个串的开头）与"通知没图标是 `Rems` 返 0"（`hasType` 那道门更前面就断了）。它说对的旁证：
`Toast::New` 的第二参数确实只是 a11y id（`toast.cpp:321-325`），shell toast 压根没有图标位。
另：我一度误报 `El::NAV_ICONS` 是假白名单，查 `sidebar.cpp:278-295` 后确认那是上游另一套逻辑名 ⇒
**`icons/x.svg` 的 base 与 `navIcon('x')` 的名字不是一个命名空间**，已写进 findings 防下次再混。
Phase 9 因此从 3 项变 4 项（探针 #47 → 修 #48/#49/#50）。

## Phase 9 / 10 / 11 一次做完（2026-10-11）

**Phase 9（三个真 bug）**：`cppStr(const char*)` 悬垂（加 `const char*` 重载转调 `cstr`，
一处覆盖 5 个站点）、`gpui_shim_notify` 补 `hasType`、`El::icon()` 挡形状 + tree21 换成表里
真有的名字。像素三张各自对上：tree21 出图标、tree32 出绿勾/黄三角、tree13 出 "Saved" + 描述。
`shim_test` 加查表探针（`AssetIconFind`/`AssetIconForPath` 正例 + 三种坏形状反例）。

**Phase 10（mac 集成）**：新增 `cpp-src/gpui_mac.{h,mm}`（只 Cocoa+Carbon，不 include gpui.h，
独立 TU 进 `libgpui_shim.a`）——托盘 `NSStatusItem`+`NSMenu`、Carbon `RegisterEventHotKey`、
`NSDockTile.badgeLabel`、`NSUserNotification`。事件按注册时交出的 `owner` 回到对应窗口队列，
`gpui_shim_close` 里 `gpui_mac_release_owner()` 撤销。两条探针都有答案（见 findings）。

**Phase 11（Capabilities）**：进程级 `gpui::Capabilities` + `grantRoot/grantExecute/grantStorage`，
`fsRead/fsWrite/fsList/fsExists/fsMkdir/fsRemove`（递归删是 shim 补的）、`processRun`、
`storagePath/Set/Get/Remove/Len`。大块字节走进程级 scratch，桥同一栈内拷成 `php::String`。

**验收**：selftest 18 → **21 步**（#19 mac 派发链、#20 授权文件系统双向对照、#21 白名单与存储门）；
shim_test 8 → **10 组**（图标查表 + `testCapabilityServices` 真跑 FsRun/ProcessRunBounded/Storage）；
Pest 57 → **65 例 / 296 断言**；shotdiff 18 树全绿（只有预期的 3 张基线变了）。

**门禁全绿**：Pest **65/296** · PHPStan 0 · shim_test OK（10 组）· **selftest 21 链路**
（`mac=1 fs=1 svc=1`）· shotdiff OK 18 树 · doctor 7 marker。

**踩到并修**：桥侧 `ErrText()` 擦掉错误原因；读根/写根两张表；`RemoveDirectory` 不递归；
`php::Array` 不能用 `for (auto& kv : a)`（迭代器给 `ArrayKeyValue`）；测试收尾 `glob()+unlink`
打到目录出警告。全部进 task_plan 的 Errors 表。

**已提交**：`f00614a` feat（19 文件 / +2261，含新 TU 与 3 张重录基线）、`159e885` docs
（8 文件 / +384）。工作树干净。mac 那四条人工清单**必须人看**：菜单栏图标、别的 app 有焦点时的
快捷键、OS 横幅（要先打包）、Dock 数字。

## 查 CI 顺带订正一条记录（2026-10-11）

被问"最近 CI 结果"，查出来**根本没有 CI 运行记录**：本仓库无 git remote、GitHub 侧
`yangweijie/typephp-gpui` 不存在（GraphQL 解析失败 + `/actions/runs` 404），触发条件
`push`/`pull_request`/`workflow_dispatch` 三者都没发生过。

于是把 Phase 7 P1 里那行 `[x] CI` 改成 `[~]`：文件写进仓库 ≠ 流水线执行过。CI 跑的三步
（`php -l` 12 个文件含 `bin/gpui`、Pest、PHPStan）在本地逐个复现且全绿，但这只是推断——
唯一没验过的环节是 ubuntu runner 上 `composer install` 能否装齐 `swoole/typephp` +
`php-nano`（本机 macOS、`vendor/` 已就位，正好掩盖它）。

新增 **Phase 12：建远端并首跑 CI**，需要用户确认可见性与目标账号后才动（不擅自改远端状态）。


## Phase 12 开工：CI 首跑失败 → 修 php-version + 补 VitePress job（2026-10-11）

远端由用户建好（`origin = github.com:yangweijie/typephp-gpui`，PUBLIC，main 已同步）。
首跑 run `38139715827` **12 秒失败**在 `composer install`：workflow 钉 `php-version: 8.3`，
而 lock 里的 dev 依赖要 `>=8.4`（phpunit 13.4.1 甚至要 `>=8.4.1`）。改成 **8.5**——依据是实测
`composer.lock`：`swoole/typephp`/`swoole/phpx` 写 `>=8.4 <8.6`，开发机 8.5.7。

按用户要求把 **VitePress 也放进 workflow**：新增 `docs` job（node 24 + `npm ci` +
`docs:build` + 产物 HTML 页数断言）。**先在本地跑过再提交**：`npm ci` 成功、build rc=0、
10 个 HTML；`config.mts` 没设 `ignoreDeadLinks` ⇒ 这个 job 顺带就是死链门禁。

顺手量到一个还没咬人的风险：lock 里三个 `swoole/*` 的 `dist.url` 是**腾讯镜像**域名
（开发机全局 composer 配置换过 packagist），且 lock 没有 source 记录 ⇒ `--prefer-source`
不通。三包在真 packagist 上都在（HTTP 200），所以真失败时只需重写 dist URL，不必动版本。

**本轮改动**：`.github/workflows/ci.yml`（php 8.5 + docs job + 头注释与理由）、
`task_plan.md`（Phase 12 三项、P1 的 CI 行改口）、`findings.md`（两条实测结论）。

### 第二跑就绿了（run `38140545523`，2026-10-11 12:46Z）

两个 job 全过，且**逐条取到真实输出**而不是只看绿色：`composer install` 从 lock 装齐
（那个腾讯镜像 dist URL 的风险没咬人，runner 在 southcentralus 照样拉得动）、
**Pest 65 passed / 296 assertions**（与本地逐字一致）、PHPStan `[OK] No errors`、
VitePress `build complete in 3.25s` + `built html pages: 10`。

P1 的 CI 行因此从 `[~]` 转正为 `[x]`。剩两行 runner 侧待办（各一行改动，等确认）：
`checkout@v4`/`setup-node@v4` 的 Node 20 deprecation 警告，与 `ubuntu-latest` 在
2026-10-19 迁 Ubuntu 26（钉 `ubuntu-24.04` 或跟着迁，二选一）。

**原生链仍然只在本地这台 mac 上验过**——六道门禁里的 3/4/5（shim_test / selftest / shotdiff）
依赖 `GPUI_DIR`，托管 runner 拉不到 gpui-kit-cpp，这是设计上的排除，不是漏配。

### 文档站 404 → 上线（run `38140955210`，2026-10-11 12:52Z）

用户报"CI build 成功但 https://yangweijie.github.io/typephp-gpui 404"。两条根因都是**实测**出来的：

1. Pages 配置已是 `build_type=workflow`，但 `status: null` —— **没有 job 上传过产物**，
   CI 只 build 不 deploy。build 成功与 404 不矛盾。
2. `config.mts` 没设 `base`。项目页挂在 `/typephp-gpui/` 下，base 留默认 `/` 会得到
   "页面在、资源 404" 的站；本地 dev/build 在 localhost 根路径，**看不出这个错**。

补：`upload-pages-artifact` + `deploy` job（`environment: github-pages`、`pages:write` +
`id-token:write`、只在 main 的 push 上跑）；`base: '/typephp-gpui/'`；再加一步断言
"产物 index.html 里必须出现 `/typephp-gpui/`"，让 base 忘设这种静默错配在 CI 里直接红。
runner 两行也收了：五个 action 升 `@v5`（v4 还在 Node 20 上）、`runs-on` 钉 `ubuntu-24.04`
（latest 十天后迁 26，那该是一次独立决策）；加 `concurrency` 让 Pages 一次只跑一个部署。

**线上验证**（不看 CI 颜色，直接打 HTTP）：根 `200`、`/guide/testing` `200`（cleanUrls 生效）、
`/typephp-gpui/assets/app.*.js` `200`。提交 `ebbc3cc`。Phase 12 全部打勾。
