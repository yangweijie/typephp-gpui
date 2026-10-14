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

## Phase 13：文档站搜索 + 多语言骨架；答"托管后能否去掉防火墙"（2026-10-11）

**搜索**用 VitePress 自带的 `search.provider: 'local'`（MiniSearch，构建期建索引、纯前端查），
不引 Algolia（要 DocSearch 授权）。产物里逐个核过：`@localSearchIndexroot.*.js`、
`@localSearchIndexen.*.js`（每 locale 一份）、`VPLocalSearchBox` chunk。

**多语言**：中文留在根路径——站点已经上线且外链都指向 `/guide/…`，把中文挪到 `/zh/` 等于
打断所有既有链接；英文挂 `/en/`。翻了 `en/index.md` 与 `en/guide/getting-started.md`，
**en 的 nav/sidebar 只列真存在的页**，因为 CI 里 `vitepress build` 本身就是死链门禁
（config 没设 `ignoreDeadLinks`）。本地 build rc=0、12 个 HTML。

**用户的技术问题：本地托管后是否就不需要防火墙、可降 C++17？——不能，且两件事不同轴。**
实测三条：① tpc 硬编码 `-std=c++17`（`NativeSourceProjectBuilder.php:104`，不是我们的选项）；
② 只 `#include "gpui.h"` 的 TU 在 C++17 下 **20 个 error**，真实站点约 6–8 处
（`gpui.h:6960` 的 `concept`、`:7017` 的 `requires`、amalgam `:10773`、`:6480` 的
explicit copy-init）；③ 托管位置只改变源码从哪来。理论上能打第 8 个补丁把 concept 降级成
SFINAE，但代价是每次上游动模板都要重打 + bridge 吞 54K 行头文件 + 还得改编译器的常量 ⇒ 不划算。
顺带纠正一条我自己差点用错的论据：gpui.h 里 `NSWindow` 只出现在注释，所以"需要 -x objective-c++"
**不是**防火墙存在的理由。

**留给用户定的两件事**：其余 7 页英文要不要翻（约 5.6 万字符）；gpui-kit-cpp 用哪种托管形态
（私有仓库 / 本地 bare / vendored）——后者的唯一收益是让 CI 能跑原生链三道门禁。

### 用户两项决定 + 原生链进 CI（run `38142514806`，2026-10-11 13:16Z）

**决定一：英文其余 7 页暂停**——"文档内容还要调整，翻了白翻"。骨架（locales / 语言切换 /
en nav 只列真存在的页）已就位，补页时不用再动结构。

**决定二：gpui-kit-cpp 走 vendored 源码子集**。`upstream/gpui-kit-cpp` = 22M / 1198 文件
（src/assets/cmd/examples/gpui_shell + 根文档；去掉 out/.work/tests/bench/web 与上游留下的
3MB `*.bak`）。它**已含 7 个宿主补丁**，补丁脚本保留作幂等复现与审计。构建改走沙箱
`build/gpui-kit-cpp`（`cmd/sync-upstream.sh`），补丁/amalgam/out 全落沙箱 ⇒ 跟踪树不被弄脏；
所有脚本默认 `GPUI_DIR` 从 `vendor/` 改到 `build/`，显式设 `GPUI_DIR` = 外部源码树模式。
**踩到一个顺序坑**：同步必须排在补丁之前，否则干净 checkout 上沙箱目录还不存在，补丁脚本
sed 找不到文件。

**验证不是只看"构建成功"**：从零删沙箱跑完整链 → `diff -r upstream/src 沙箱/src` **0 行**
（证明跟踪树一个字节都没被写）→ 六道门禁全绿 → 推上去。

**结果**：CI 四 job 全绿，`native`（macos-15）**3m27s**，日志里 `SHIM CPP TESTS OK` +
`SELFTEST OK`。这顺带答了一个我原本没把握的问题：**托管 macOS runner 能开真窗口**。
门禁 5（`--shot`/shotdiff）仍留本地——CI 上硬跑只会得到"能出图但图是空的"这种最坏证据。
testing.md 里"原生链托管 runner 拉不到"那句已订正成四 job 的实测表。

提交：`72a021c`（vendored + 沙箱）、`43695be`（native job）。

## 第 8 个补丁：concept → SFINAE，gpui.h 在 C++17 下 20 error → 0（2026-10-11）

用户选了"打第 8 个补丁"。`cmd/patch-concepts.sh` 打在 `src/gpui/gpui.h` 上共 7 处：
1 个 `concept` → 偏序特化 trait（`EmitsEventV`）、5 个 `requires` → `enable_if`、
1 个 `PaintCtx paint = {}` → default-init（真凶在下一层：成员 `Vec<HitRect> hits;` 而
`Vec` 的默认构造是 `explicit`，`base.h:576`）。

**分阶段实测**：补丁前 20 error → 只降 concept/requires 后还剩 1 → 全降完 **0 error**。
新加门禁 `cmd/check-cxx17.sh`：同一份探针在 **c++17 与 c++20 下都要过**（`-Werror`），
`EmitsEventV` **正反两向**断言 + 5 个 `enable_if` 站点取地址实例化。只验正向的话，
判据被写成恒真也照样绿——这正是这轮反复踩的那类"绿得没有内容"。

**两个诚实的保留意见**：① 0 error 里有 **87 处 clang 扩展**兜着（位域默认成员初始化，
C++17 标准不收），所以"bridge 能直接 include gpui.h"只对 clang 成立；② 防火墙**不拆**，
但它买的东西从"唯一可行"变成"编译增量 + ObjC/ARC 独立 TU + C 契约隔离"。architecture.md
里那段理由已按实测改写，并明确标注我此前那条"需要 -x objective-c++"是错论据
（gpui.h 里 NSWindow 只出现在注释）。

**接线**：`patchManifest()` + 补丁列表 + `composer patch` + `GPUI.lock` marker 表 +
doctor（现在 8 个 marker）+ CI native job 加 `check-cxx17.sh`。文档里写死的"5 个补丁 /
7 个补丁"全部改成"清单以 GPUI.lock 为准"，数字不再漂。

**全链复验**（清沙箱走正规流水线，不是手工打补丁）：8 补丁 → verifyPatches → amalgam →
shim → tpc 出二进制；cxx17 OK · Pest 65/296 · PHPStan 0 · shim_test OK · selftest 21 链路
（`mac=1 fs=1 svc=1`）· shotdiff 18 树 · doctor 8 marker。


---

## 2026-10-11 · Phase 14：examples/showcase（展示全部特性的示例）

**交付**：`examples/showcase/`（13 节 + `--sections`/`--selftest`/`--shot`）、
`tests/ShowcaseTest.php`（9 个测试的覆盖率门禁）、`cmd/shotdiff.php` 支持按示例自报树号、
CI native job 增加 build showcase + selftest、README/getting-started/testing 三处文档。

**验证（本机实测，非推断）**
| 层 | 命令 | 结果 |
|---|---|---|
| wrap-green | `vendor/bin/pest` | 74 passed / 347 assertions（含 showcase 9 项） |
| 静态 | `vendor/bin/phpstan analyse` | level 5 无错 |
| 静态 | `npm --prefix docs run docs:build` | 2.21s，页数与 base 断言过 |
| route-green | `showcase --selftest` | `SHOWCASE SELFTEST OK`，rc=0（13 节零错误节点 + 每个 App 能力真调） |
| pixel-green | `cmd/shotdiff.php check showcase` | 13 张 gdfrac=0.00000 |
| 回归 | doctor / check-cxx17 / test-shim / hello selftest / hello shotdiff | 全绿（8 补丁、双标准、21 链路、18 树） |

**过程中修掉的真缺陷（都是"PHP 绿、产物红"那一类）**
1. `require_once` 在 nano 不支持 → 编译期 fatal，改由 `sources` 组合。
2. 引用式状态两堵墙（`array &$state` 编译期 fatal；去掉类型后 `RefWrap<Array>::commit()` 运行时 abort）→ 静态表 `ShowcaseState::$v`。
3. `->border(1, 色)`、`->flex(1)`：PHP 容忍多余实参，nano 抛 ArgumentCountError → 加 AST 版 arity 门禁。
4. `collapsed`/`align`/`description` 挂错组件（分别属于 Sidebar / Field / Field，Alert 只有 title·banner·visible·size）→ `registeredError()` 逐节逮住。
5. 手写 base64 的 gzip 向量本身非法（本机 `gzdecode` 也拒）→ 换成外部生成的真字节向量并两边对齐。
6. `grantRoot` 授了不存在的目录 → 根必须已存在，改授 `/tmp` 再建子目录。
7. `El::list` 的 children 被上游丢弃（renderer 未接）→ 留空 List 证明 API + 用 vFlex 画真列表。
8. 巨型 `sprintf` 少一个实参 → selftest 在最需要输出时炸；改成数组拼接。
9. 手搓 token 扫描器把链式调用吃漏（159 个名字只认 12 个）→ 换 php-parser 真 AST。

**反向锚点**（每条门禁都做一次"故意破坏→变红→还原→变绿"，md5 校验还原一致）：
工厂覆盖、链式覆盖、枚举取值、arity（border/flex1 两条）、孤儿 handler（in-key-typo）。

**已知不足（未固化成基线的部分）**：左侧导航 210px 下节名被截断；`El::menuBar` 仍报
"component app effects require a root application snapshot"（root-app 那道墙，task #28/#33），
它在控制台响但 `registeredError()` 不记，所以 13 节仍判"无错节点"——这一条要在文档里说明白。


---

## 2026-10-11 晚 · 用户实测暴露"真实输入从未工作"，已修并补门禁

用户跑 `showcase` 交互版反馈三件事：导航点了没反应、hover 无效、输入框打不进字。
查下来是两个框架级缺陷 + 一个验收盲区，全部修掉：

| 缺陷 | 位置 | 修法 |
|---|---|---|
| 回调 op 名被剥前缀 → gpui 从不挂监听器 | `gpui_shim_callback()` 写 `so.name` | 队列用短名、`so.name` 补回 `on_` |
| `El::input('x')` 把 id 放进 `text` → retained 建不出 → 静默变 Div | `src/Element.php` | 改放 `->id`；bridge 缺 id 即 `set_error` |
| `number_input`/`otp_input` 压根没进 retained 清单 | `gpui_bridge.cc` | number 复用 Input；otp 新增 `CreateRetainedOtp` 转发口（补丁自检） |
| 合成事件缝绕过"监听器挂没挂" | `--selftest` | 新增 `realClick/realMouseMove/realKey/inputProbe` 真输入验收 |

**证据（本机实测）**
- `showcase --selftest` → `SHOWCASE SELFTEST OK`，`realInput=true/true/true`、
  `navHits=7`、`evSeen=change{in-num,in-name}`、`probe=4@226,457 746x20`。
- 回归：`hello --selftest` OK（用新 bridge 重编）、hello 18 张基线 gdfrac=0、
  showcase 13 张基线重录后 check 全 0、Pest 74、PHPStan 无错、shim cpp tests OK、
  cxx17 双标准 OK、doctor 9 个补丁标记全落、`vitepress build` 1.55s 过。

**顺手修的流程债**：`cmd/patch-retained.sh` 重写为"每个符号按完整声明文本各自幂等 + 结尾自检"，
因为发现 `upstream/` 的跟踪树里其实已含早先补丁（`72a021c`），整块闸门会在半补丁状态下把新符号
插进旧声明中间。

**仍未解决 / 诚实边界**
- `App::focusedId()` 对 retained input 恒 0（`WindowFocusedId` 读 `win->focusId`，shell 里输入框
  焦点记在 `win->input`），所以它只当诊断，不当断言。
- 输入框的**外观**仍是无框样式（paint 里是真 Input、行为真通，但主题没给字段底色/边框）；
  这是主题层的事，没混进本轮。
- `El::menuBar` 依旧要 root-app snapshot（task #28/#33），只在控制台响。

### 收尾时的两处订正（写下来免得下轮又信错）

1. 仓库根出现过一个是未跟踪的 6.4MB Mach-O `main`（mtime 23:05 本地）。已删除，且重跑
   `cmd/test-shim.sh`、`cmd/check-cxx17.sh` 都不复现。**归因未定**：我一开始怀疑是
   `check-cxx17.sh:73` 缺 `-c`，读过脚本后**这个怀疑是错的**——它的 FLAGS 里本来就有
   `-fsyntax-only`，不产文件。所以这条只是"清掉了一个野文件"，不是"修了一个 bug"。
2. `focusedId()` 对 retained input 恒 0：`WindowFocusedId` 读 `win->focusId`，而 shell 里
   输入框的焦点记在 `win->input` 上。它只能当诊断，不能当断言——已经按这个事实把
   selftest 的门从 `focusedId()>0` 改成"看打字结果本身"。

## Phase 14c：真输入的两道系统前提（2026-10-12）

症状：用户手点仍"左侧点不了、输入框打不进字"，而 selftest/像素基线全绿。

定位方式：同一颗二进制做 A/B，只换"有没有 .app bundle 身份"。

```
裸 build/showcase              → startup active=false key=false visible=true  main=false
build/showcase.app/…/showcase  → startup active=true  key=true  visible=true  main=true
```

结论：macOS 不把裸可执行文件当正规前台应用；`TransformProcessType` +
`activateIgnoringOtherApps:` 也起不来 `isActive`。非 active 应用的窗口第一下点击被当成
"激活这扇窗口"的点击吃掉，`keyDown` 没有 firstResponder —— 现象与"框架事件路由坏了"一模一样。

改动：

1. `bin/gpui`：`appBundle()` 把 `build/$example` 软链进最小 `.app`（Info.plist 带
   CFBundleIdentifier/CFBundleExecutable）；`run` 与 `selftest` 都从 bundle 里起，
   `run` 还负责把 `--trace/--sections/--selftest` 转发给二进制（不转发就静默丢 trace）。
2. `gpui_mac.mm` `Deliver()`：真路由补上"入队后自己按掩码取出并交
   `-[NSApp sendEvent:]`"。gpui 自己的 `GpuiAppRunStep`（window_mac.cpp:2314/2341）本来就用
   `nextEventMatchingMask` + `sendEvent:` 泵队列，所以这条路就是手点走的路。
   同步取完是必须的：`gpui_shim_real_mouse` 断言"调用返回时队列已增长"，只 post 不取会让
   正常链路读成 `realInput=false` 的假阴性（本轮就这么误判过一次 bundle 的正常运行）。
3. `windowState()` 加 `bundled`（`[NSBundle mainBundle].bundleIdentifier`）与
   `nsappDeliveries`（走过真 NSApp 路由的条数，单调不清零）。
4. showcase 非前台时把这条**画在窗口顶部**（`front` 状态每帧由 `windowState()` 量），
   不必靠人猜"是框架坏了还是没被激活"。默认 `front=true`，13 张像素基线不受影响。

门禁与负控：

- 新门 `bundleGreen = !bundled || (active && key)`、`routeGreen = !(bundled && frontmost) || nsappDeliveries > 0`。
- 第一版只写了 `routeGreen = !frontmost || …`，负控（把真路由分支钉死）时 `frontmost` 自己
  塌成 false，门空转 = **虚绿**；改用 bundle 身份这种不受测试副作用影响的独立事实才对。
- 负控实测：分支钉死 → `bundled=true frontmost=true nsappDeliveries=0 routeGreen=false`，
  rc=1 FAIL；按 md5（bd15088b…）还原 → rc=0，`nsappDeliveries=150`，全绿。
- 六道门禁重跑：hello/showcase 像素基线 0.00000、Pest 74/347、PHPStan 无错、
  shim cpp 测试 OK、docs:build OK、`bin/gpui selftest hello|showcase` 均 rc=0（走 bundle）。

本机边界（不装）：外部进程 `CGEventPost(kCGSessionEventTap)` 投鼠标/移动**全部被静默丢弃**
（无注入权限），所以"人手点了一次真的到不到"这一步只能由用户验：
`php bin/gpui run showcase --trace` 后点导航，`/tmp/showcase-events.log` 应出现 `click id=nav-N`。

## Phase 14c-2：前台是竞争来的，门挪回我们能保证的事（2026-10-12）

- 新读数：同一个 bundle 二进制**两次启动**分别量到 `active=true key=true`（含 150 条真路由投递）
  与 `active=false key=false`。`App::activate()` 打满 24 轮重试也争不到——macOS 正当拒绝
  后台进程抢焦点。`SetFrontProcessWithOptions` 实测无效，已撤掉（不留"看着像修复"的死代码）。
- 于是 `bundleGreen` 从 `bundled ⇒ frontmost` 改成 `--expect-bundle ⇒ bundled`：
  断言**产物属性**（我们能保证），`frontmost/activateTries/nsappDeliveries` 只作读数。
  负控：`--selftest --expect-bundle` 配裸二进制 → rc=1 FAIL；配 bundle → rc=0。
- `activateTries` 上限 24（selftest）、交互模式上限 12 帧：超过就不再抢焦点，因为
  "一直抢"比"第一下没反应"更伤用户。
- **nano 不支持 `getenv()`**（编译期 fatal）：期望信号只能走 argv（`--expect-bundle`）。
- **两次踩到陈旧产物**：`build-shim.sh` 报 `kCPSUserGenerated` undeclared（SDK 没导出该常量）后
  `;` 串下去，tpc 链的是**旧 .a**，于是"bundle 也 active=false"的结论根本不成立；
  另外那次 `rc=255` 是 tpc 编译失败，跑 `./build/showcase` 测到的是上一个二进制。
  规则：测量前必须 `构建 && echo OK || exit 1`，失败别用 `;` 串过去。
- 门禁：hello/showcase selftest（bundle 与裸两条路）rc=0、像素基线 gdfrac=0.00000 两套、
  Pest 74/347、PHPStan 无错。

## Phase 14c-3：找到并修掉两个"用户点不动"的真缺陷（2026-10-12）

这轮不再从读数倒推原因，而是直接量：

1. **导航行只有文字宽度可点。** `El::vFlex()->itemsStart()` 让按钮按内容取宽，实测横向命中带
   只有 x=12..132（侧栏 w=210，可用 194），点行的右半截毫无反应。给按钮 `->w(194.0)` 后
   实测 12..196（24/26 采样命中）。新增 `navBand` 断言（>=22 命中、min<=12、max>=196）。
2. **首帧没有命中表。** 新建 `--liveclick` 模式：装 view → 推进 N 帧 → 真点击扫 nav 列。
   读数：`frames=1 事件=0/38`，`frames=2/5/20/60 事件=18/38 且 nav-1..9 到达 PHP`。
   画面画得出、点击进不来，正是"刚启动点了没反应"。修法：`App::view()` 注册时立刻
   materialize 一遍（`run()` 走同一条路）；修后 `frames=1` 也是 18/38。
   `--liveclick` 首帧为 0 就 rc=1，已进 CI。
3. 中间产物：`afterRender=false,false / synthetic=true / hits=18→18` 这组读数把
   "监听器丢了""命中表被清空"两条假设都否掉了（合成缝投得进 = 监听器在；hits 不降 = 表没清）。
   它们只在 selftest 的畸形时序里出现（前面连续 25 次点击把侧栏滚了），解释不了用户路径，
   于是把那两条未被证实的读数从报告里删掉，只留下有断言、有解释的读数。
   教训：**同一现象在测试 harness 里成立，不等于在用户路径上成立**——所以补了 --liveclick。
4. 副作用受控：预热让 `view()` 立刻调一次回调，`--selftest` 里"只停在一帧"的断言把预热
   算进了帧数 → 改成只统计 loop 期间的调用（`stopFrames - primed === 1`）；Pest 的
   `loop runs the view once per frame` 相应更新为 4 次渲染并注明原因。
5. 门禁：selftest（bundle + 裸）rc=0、`--liveclick` rc=0（两条路）、hello selftest rc=0、
   showcase 基线重录后 check OK（满宽导航）、hello 基线 OK、Pest 74/347、PHPStan 无错。

## Phase 14c-4：第三条真缺陷——tracking area 只在钥匙窗口里给 mouseMoved（2026-10-12）

- 上游 `window_mac.cpp:680` 的选项是 `NSTrackingActiveInKeyWindow`：窗口不是 key 时
  AppKit **不投递 mouseMoved**，于是"hover 无效"会跟着焦点状态漂——应用刚起来没被激活、
  或用户切到别的 app 而鼠标还悬在我们窗口上，两种都收不到悬停。PHP 侧接线本来就是通的。
- 处置：新增上游补丁 `cmd/patch-hover-always.sh`，把它换成 `NSTrackingActiveAlways`
  （悬停给反馈与焦点归属无关，是 hover 驱动 UI 的常规做法）。脚本按"恰好一处 Always、
  且不再有 InKeyWindow"自检，幂等（二次执行 skip）；已进 `bin/gpui` 的补丁序列、
  `verifyPatches()` 清单、`GPUI.lock` 表；`bin/gpui doctor` 从 14 项变 15 项。
- 边界（不装）：这条改的是真人鼠标移动的投递条件，而本机无法注入 OS 事件（实测连读光标
  坐标都 SIGTRAP），app 内的合成缝又不受这个门槛约束（frontmost=false 时 hoverRouted 照样真）。
  所以这一条**没有本地读数能证明**，只有用户把手移到未激活窗口上能验。
- 重建后门禁全绿：showcase selftest（裸 + bundle）、`--liveclick`、hello selftest、
  两套像素基线 check、doctor 15 项。

## Phase 14c-5：逐颗输入框验证 + key_down 段错误（2026-10-12）

- `App::inputProbe(int $index = -1)`（shim/bridge/stub/FakeBridge 同步）——原来只报最后一颗，
  无法证明"每个输入框都能打字"。`--livetyping` 因此能逐索引取框、点中心、按真实键。
- 读数 8/8（tree2 3/3、tree7 5/5），退出码进门禁并加进 CI。
- 发现并跟踪缺陷 #53：`onKeyDown` 元素上按真实键段错误（ScriptView/QuickJS 派发路）。
  探针里对该框显式跳过并注释原因；修好后要撤掉跳过。
- 原生链重建后全部门禁重跑（selftest 两条路、liveclick、livetyping、两套像素、Pest、PHPStan、docs）。

## Phase 14c-6：攻 key_down 段错误未果，两条修法都回退（2026-10-12）

- 事实：挂了 `onKeyDown` 的 retained input，点击正常、change 发得出，但真实按键仍段错误；
  崩点**在** `ShellRuntime::DispatchKey` 里、且在我们能放旁路的位置**之前**。
- 试过的两条：
  1. 给 key_down/key_up 登记带 shimId 的 runtime 条目（`PushShimCallback`），配合在
     `DispatchKey` 碰 JS 之前早返回的新补丁 `cmd/patch-dispatch-key.sh`。
     诊断读数否掉了它：`[keydbg]` 一行都没出（说明早返回根本没被走到），崩溃照旧；
     而 `[keyreg]` 暴露第二个问题——`boxCb=16` 不变、`specId` 却 1,2,3,4,5,6 递增，
     即**每帧登记都漏一条 CallbackEntry**（gpui_shim_callback 每帧都调，那条路径没有去重）。
  2. 只加补丁 → 同样无效（entry 查不到 shimId）。
- 处置：两处全部回退（shim 的 specId 分支与 `gpuiEnqueueShimKey` 强弱符号、补丁脚本本体、
  `bin/gpui` 补丁序列与清单、`GPUI.lock` 表行、沙箱里的诊断插入靠 sync-upstream 复原）。
  `cmd/patch-*.sh` 回到 9 个，doctor 回到 15 项标记。
  `--livetyping` 恢复"写明跳过 in-key"，不计为通过。
- 回退后全绿：showcase selftest 裸/bundle rc=0、`--livetyping` 8/8 rc=0、`--liveclick` rc=0、
  像素基线 check、Pest、shim cpp 测试、doctor 15。
- 下一步的线索（都还没验）：崩点早于旁路 ⇒ 要看 `ScriptKeystroke`/`KeyName(event.vk)` 与本
  KeyEvent 的字段来源（崩溃地址解出来是 ASCII 的 `US-ASCII`，像一个编码名字符串被当指针用），
  以及 `ScriptView::OnScriptKey` 里 `self->runtime` 的来历是不是 root runtime。

## Phase 14c-7：第三条 key_down 修法也被否，但把根因收窄到 runtime 身份（2026-10-12）

- 新做法：不依赖 CallbackEntry（spec 交出去的是 box 自己 id，表里查不到），改成在
  `DispatchKey` 碰第一个 JS API 前判 `!impl->context` 并把 keystroke 交给新钩子
  `gpuiDeliverHostKey`（弱定义在 runtime.cpp，强实现在 shim，配进程级唯一 callback id
  与 id→box 归属表）。
- 这次**先把产物验证做实**：`nm` 在 `gpui.o` 与最终二进制里都命中 `gpuiDeliverHostKey`。
  结果仍崩，PHP 侧也没有 `key_down id=in-key`。
- 由此得到的推论比前两轮都硬：guard 没触发 ⇒ `impl->context` 读出来**非空**；而 `JS_NewObject`
  依旧在 `NULL+0x58/0x59` 炸 ⇒ 进 `DispatchKey` 的 `this`（即 `ScriptView::runtime` 指向的对象）
  不是我们的 ShellRuntime，`impl` 是垃圾指针。下一步查 materialize 给 retained input 建
  ScriptView 时 `runtime` 怎么填（view.cpp:392 只判非空、不判身份）。
- 同时更正一条我自己写错的读数：上一轮"[keydbg] 没输出 ⇒ 旁路位置没走到"**无效**——那次只跑了
  `bun cmd/build.ts`，没重跑 `cmd/build-shim.sh`，而 `gpui.o` 要归档进 `libgpui_shim.a` 才参与链接，
  最终二进制里根本没有那个符号，跑的是旧产物。另外"崩溃地址解出 US-ASCII"那份案卷时间戳
  08:42:54 早于 08:45:57 完成的构建，也是在读旧案。规矩加进记忆：改了 gpui.o 必须 build-shim
  重归档；验"改动进没进产物"用 `nm`/`strings` 看最终二进制，不看 mtime；读 .ips 先比时间戳。
- 三处尝试全部回退（补丁脚本、shim 的 specId/归属表/钩子、清单与锁文件）。恢复后全绿：
  doctor 15 标记、showcase selftest 裸/bundle rc=0、`--livetyping` 8/8 rc=0、`--liveclick` rc=0、
  像素 check、hello selftest、Pest 74/347、PHPStan、shim cpp 测试。
- 新增保留物：`--livetyping-all`（连挂 onKeyDown 的那颗一起按）作为**已知崩溃的复现位**，不进 CI；
  默认 `--livetyping` 仍写明跳过那颗。

## Phase 14c-8：把 key_down 崩溃入口关掉，并把"拒绝"做成必须发生的断言（2026-10-12）

- 决定：gpui 的键派发路（ScriptView → ShellRuntime::DispatchKey，进去的 runtime 不是本宿主的）
  一时修不好，那就**不让产品里有这条入口**：`gpui_shim_callback` 对 `on_key_down`/`on_key_up`
  不再 PushOp（不挂 gpui 监听器），并把原因写进 lastError。真实按键因此不再进崩溃路。
  合成缝不受影响（按 cbid 入队），handler 接线的测试照旧。
- 证据不靠错误串：lastError 每帧被 render 清一次（`box->lastError.clear()`），拿它当证据等于
  什么都没证 ⇒ 加单调计数器 `g_keyRefusals`，经 `gpui_shim_window_state` 的 out[6] 出去，
  bridge 暴露成 `windowState()['keyRefusals']`（stub/FakeBridge 同步）。
- selftest 的门：① 第 2 节（挂 onKeyDown 的那节）**必须**报出这条拒绝（`$keyRefusalTree === 2`），
  别的节有错就算红；② `keyRefusals > 0`。读数：`keyRefusals=3@tree2`。
  中途我把"容忍错误串"和"要求错误串"两版都试过，最终形态是**断言它发生**——
  哪天它不报了，说明监听器又挂回去了、崩溃回来了。
- 结果：`--livetyping` 从 8/8（跳过一颗）变成 **9/9、无跳过**：连挂 onKeyDown 的那颗也走同一条
  "点进去、按一个键、看 change"的断言，且进程不再崩。
- 更正两条我自己的错判：① 上一轮说 `err=''` ⇒ 拒绝不可见——错，是我那行放宽把它擦掉了；
  ② `strings` 查中文字符串得出"没进产物"——方法错，`strings` 默认只抽 ASCII，中文串本来就取不出；
  要看符号用 `nm`，要看行为看读数。
- 文档：`Element::onKeyDown()` 加了明确的 docblock（当前不绑定、原因、请用 onChange），
  events 指南同段说明；CI 注释改成"断言必须发生"。
- 门禁全绿：showcase selftest 裸/bundle rc=0、`--livetyping` 9/9 rc=0、`--liveclick` rc=0、
  两套像素基线、hello selftest、Pest 74/347、PHPStan、doctor 15 标记、shim cpp 测试、docs 构建。
- 仍未做：#53 的真修（让键事件走宿主而不奔 QuickJS）；用户手点/手打的最终确认。

## Phase 14c-9：#53 真修完成——键事件在 view 层交给宿主（2026-10-12）

- 转折点是想通了"点击为什么能到 PHP"：`patch-view.sh` 把 `ScriptView::OnClick` 里的
  `runtime->DispatchClick(...)` **整行换成**弱钩子 `gpuiEnqueueClick`。键事件照同一形状做就行，
  而我前三次都试图在已经进了 `ShellRuntime::DispatchKey` 之后再塞旁路 —— 位置从头就不对。
- 新增第 10 个补丁 `cmd/patch-key-view.sh`：`OnScriptKey` 函数体换成
  `gpuiEnqueueKey(self, cx, callbackId, event->ch, event->down)`，弱实现是空函数（内嵌 JS 的
  原样宿主行为不变）；`cpp-src/gpui_shim.cpp` 给强实现，把码点编成 UTF-8 入对应窗口队列。
  `bin/gpui` 补丁序列 + 清单 + `GPUI.lock` 同步，doctor 从 15 项变 16 项。
- 于是上一轮那个"shim 拒绝绑定 onKeyDown"的绕行被撤掉（它砍的是功能不是修 bug）：
  `gpui_shim_callback` 恢复给 key_down/key_up 挂监听器；`g_keyRefusals` 计数器与
  `window_state` 的 out[6]、bridge/FakeBridge 的 `keyRefusals` 一并移除；selftest 的门从
  "断言拒绝发生"翻回"断言真实按键到达 in-key"。
- 诚实的边界：`key_down` 的 `value` 是空 —— gpui 自己从 NSEvent 算 `KeyEvent.ch`，我们合成的
  那颗算出 0。所以门禁只钉"key_down 到了哪颗元素"，不钉字符值；`value=[—]` 继续打印出来供观察，
  文档里写明这个不对称（Element::onKeyDown 的 docblock、events 指南、CI 注释三处同步）。
  第一次把这颗按"字符必须带"来断言时 `--livetyping` rc=1，那个红是对的：它先于结论暴露了我的
  假设未经验证。
- 验收读数：`LIVETYPE 合计=9/9 key_down=[in-key]`（bundle 与裸两条路都 rc=0）、showcase selftest
  裸/bundle rc=0、`--liveclick` rc=0、hello selftest rc=0、两套像素基线 check、Pest 74/347、
  PHPStan 无错、shim C++ 测试 OK、doctor 16 项、docs 构建过。任务 #53 关闭。

## Phase 14c-10：key_down 的 value 补全（键名 vs 码点，别再取错源）

- 上一轮说"value 是空、可能真键盘才有"——那是**取错了源**：`KeyEvent` 的 `vk`/`ch` 互斥
  （`gpui.h:770` 注释写得很清楚），`key_down` 根本不带 `ch`。被换掉的 JS 负载用的是
  `key = event.vk ? KeyName(event.vk) : keystroke`，所以键名才是它的 value。
- 补丁与钩子改成同时带：`gpuiEnqueueKey(self, cx, cbid, rune, KeyName(vk), down)`；
  shim 里"有码点用码点，否则用键名"。`KeyName` 在 `gpui/keymap.h` 是公开的，view.cpp 能用。
- 门禁从"只断言事件到了"升级成"断言值真的回来"：`--livetyping` 读数
  `合计=9/9 key_down=[in-key] value=[—1]`，bundle 与裸两条路 rc 都是 0。
  前后对照本身就是证据：补上前 `value=[—]`、断言字符时 rc=1；补上后 `value=[—1]`。
- 教训一条：合成键事件 `value` 为空当时被我归因成"宿主/合成限制、需用户实测"，
  实际是我读了个语义上必然为 0 的字段。**读不到值时先去看字段定义，不要先宣布这是边界。**
- 全部门禁重跑绿：showcase selftest（CLI 与 bundle 两条）、`--livetyping` 两条、`--liveclick`、
  hello selftest、两套像素基线、Pest 74/347、PHPStan、shim C++ 测试、doctor 16 项、docs 构建。

## Phase 14c-11：窗口内 HUD：让"我这一下到没到"当场可见

- 用户不必为了验证一次点击去开终端翻 `/tmp/showcase-events.log`，所以把事件计数常驻到
  窗口标题下方：`已收到事件 点击 N · 输入 N · 按键 N · 悬停 N · 焦点 前台/非前台`。
  数字直接取自 evSeen（与 selftest 报告同一份数据，不另开计数器），非前台时同一行就写明。
- 门：HUD 不只是"存在"——selftest 先塞一条 `click nav-5`，再要求 `debug_tree` 里同时出现
  `表格、列表与进度`（换节了）和 `点击 1`（计数反映事件）。少了任何一个，`rerender` 门就红。
- `--shot` 走的是同一棵 shell，所以 13 张基线整体位移（gdfrac 0.037~0.073）是**预期变更**，
  已重录；无事件时计数行是稳定文本，基线因此仍然确定。
- 一次误操作记在这里：`showcase --debug`（不存在的开关）会静默落到交互模式并阻塞——
  未知开关应当报错退出，这条待改（不影响本目标，但同类"看起来没反应"的体验值得修）。
- 门禁：showcase selftest rc=0（含 HUD 断言）、`--livetyping` 9/9 rc=0、`--liveclick` rc=0、
  两套像素基线 check、hello selftest、Pest 74/347、PHPStan、shim C++ 测试、doctor 16 项。

## Phase 14c-12：左侧点不动的真身——渲染过 WebView 那节之后，原生子视图吃掉了上半区

- 症状还是"左侧点不了"，但这次的读数是：按 y 扫 40..700 时**上面 6 行一次都不进队列**，
  下面 7 行正常。先立了一条新探针 `App::hitProbe($i)`（C ABI `gpui_shim_hit_probe` →
  bridge → stub → App/FakeBridge），读的是点击真的会查的 `paint.hits`：
  13 条导航矩形**全在**、`click=1` **全在**。所以错不在 gpui 的表里——表外面有东西挡着。
- 二分用的是一次运行内多点重复量（同一套动作在流程各阶段各量一次）：
  窗口刚建好 13/13 → 只换过一次根树 13/13 → **13 节全渲染过 7/13** → 逐节量精确到
  `SECTION 11 clicked=7/13`（第 11 节 = WebView 与 Inspector），之后再也回不来。
- 根因：`ChildView` 背后那颗 WKWebView 是常驻 NSView。元素从树上换掉后它仍挂在窗口上、
  停在最后一次的矩形里，AppKit 的 `hitTest` 先命中它 ⇒ 那一片的 gpui 元素收不到真实鼠标。
  修法在 shim 的每帧 materialize 前后各扫一次（`WebViewFrameVisibility` + `WebViewView::seen`），
  这帧没渲染到的那颗 `WebViewSetVisible(false)`（macOS 侧就是 `hidden=YES`，AppKit 会跳过）。
- 修后读数：`navProbe fresh=13/13 afterAll=13/13 sectionsReachable=13/13`，裸进程与 bundle
  （`bundled=true`）两条路都这样；`--liveclick` 加了同一套逐行断言后 `逐行=13/13 到达节数=13`。
  门的下限按实测写成"矩形数/点动数/到达节数都 ≥ 节数"，不再用 `>= 2` 这种没量过的数。
- 顺带纠正两条我自己下过的错判：
  1. "扫描范围放宽导致 selftest 挂住"——`sample` 出来的栈显示卡在
     `-[NSWindow _handleMouseDownEvent:] → _mouseHysteresisCheck: → nextEventMatchingMask`，
     也就是**点在窗口 resize 边上**（y=700 已在内容区外）时 AppKit 在等下一个鼠标事件。
     是探针采样范围越界，不是 gpui 死循环；改成命中表驱动后这个坑不存在。
  2. "FPS HUD 是凶手"——A2 探针（HUD 开关之后）已经 7/13，但逐节量证明 HUD 之前
     （A1）就死了；HUD 只是排第二的嫌疑，被读数否掉。
- 还记一条探针诚实性问题：`realClick` 返回"队列增长了"，而**hover 事件也会让它增长**
  （y=100 那次"命中"其实是 hover）。所以逐行断言必须看"到达了几个不同节号"，
  不能只看返回值计数。
- 门禁全绿：showcase selftest（CLI + bundle `--expect-bundle`）、`--liveclick`（含逐行）、
  `--livetyping` 9/9、两套像素基线（gdfrac=0，说明收放可见性没动渲染）、hello selftest、
  Pest 74/347、PHPStan、shim C++ 测试、doctor 16 项。
- 一次环境抖动记在这里：`bin/gpui selftest` 有一趟 build.ts 报 `clang++ not found` 而没产出
  gpui.o——`is_file($gpuiO)` 这道检查把它拦住了，没有拿旧产物冒充新产物；重跑即通过。

## Phase 14c-13：把"换节"也改成真点击，并给新门做反向对照（两次都没让它当上回归位）

- `--livetyping` 之前换节是 `ShowcaseState::$v['section'] = N`——等于绕过 hit-test 假装"点过了"。
  新增 `navClickTo($app, $tree)`（从 `hitProbe` 取第 N 条导航矩形、点中心、再看状态真的换节），
  于是这条门同时盯住"每一行导航点得到"与"点完打得进字"；读数不变就是没退化：
  `LIVETYPE 合计=9/9 key_down=[in-key] value=[—1]`，且没有任何一节报"导航点不到"。
- 又加了一圈用户手势：真点进挂原生子视图那节 → 再真点回第 2 节逐颗打字
  （`ROUNDTRIP 经节11回节2 导航=true 打字=4/4`）。
- **反向对照两次都没让这条新门抓到 14c-12 那个 bug**：把 `WebViewSetVisible(raw, v->seen)`
  临时换成恒 `true`（等于修之前的行为）重建后，ROUNDTRIP 依旧全绿；同一条件下
  `--selftest` 的 navProbe 立刻红成 `afterAll=7/13`、`SHOWCASE SELFTEST FAIL`。
  第二次我以为是顺序问题（原生视图停在最后渲染过的那个矩形，于是把裸渲染挪到点进节之后），
  仍然绿——所以差别在窗口尺寸/进入方式，不在这圈手势里。
  结论按实测写进代码注释：**回归位是 `--selftest` 的 navProbe，ROUNDTRIP 只是多一层手势覆盖**，
  不许它冒充抓过这个 bug。（恢复后 shim 与两个示例都重建过，门禁全绿。）
- 全部门禁复跑绿：showcase selftest（CLI + bundle `bundled=true`）、`--liveclick 逐行=13/13`、
  `--livetyping` 9/9 + ROUNDTRIP、hello selftest、两套像素基线 gdfrac=0、Pest 74/347、
  PHPStan、shim C++ 测试、doctor、docs 构建。

## Phase 14c-14：把三条探针挂到交互模式那颗每帧回调上（假设"paint 途中抢前台吃掉点击"被否）

- 还有一条用户现场与门禁的差异没排除：`runInteractive` 的每帧回调里会
  `$app->activate()`（内部泵 AppKit run loop），而它发生在 **paint 途中**——用户的点击正是从
  这条队列上来的；`--liveclick`/`--livetyping` 原来注册的是只画 shell 的简化回调，
  所以这一步从没被任何门走过。
- 做法是把那颗回调抽成 `interactiveFrame(App $app): El`（第一帧装菜单/定时器 +
  每帧量窗口状态、必要时抢前台），三条探针改挂同一颗。不是复制一份逻辑，是同一份。
- 读数：三条门在**同一形状**下全绿——`--liveclick 逐行=13/13 到达节数=13`、
  `--livetyping 合计=9/9 + ROUNDTRIP 导航=true 打字=4/4`、`--selftest`
  `fresh=13/13 afterAll=13/13`（裸进程与 bundle `bundled=true` 两条一致）。
  于是"每帧抢前台把点击吃掉了"这条假设被读数否掉，同时探针与用户路径的形状差被消掉一格。
- 门禁全绿：showcase selftest 两条、liveclick、livetyping、hello selftest、两套像素基线
  gdfrac=0、Pest 74/347、PHPStan、shim C++ 测试、doctor、docs 构建。

## Phase 16：MenuBar 这条路真正打通（两层根因都查到底），并补了一条能红的门

Phase 15 里"修通又回退"的那条，这一轮查到真因并修好了——关键是别再把它当"节奏问题"猜。

- **第 1 层**（materialize 卡点）：`MaterializeRequest::AppEffects` 只认 QuickJS 给的根应用身份。
  补丁 `cmd/patch-host-application.sh` 加 `RegisterHostApplication` + `SetHostApplicationFallback`
  （只对"不在 `impl->views` 里"的视图兜底 ⇒ QuickJS 路径行为不变）。
  实测教训：**全宿主必须共用一个身份令牌**——按实体各发一枚时长出两条 app-effect 世代，
  各自装卸同一份 `OwnedMenus`，崩溃率反而更高（3 次崩 1 → 单一身份 6 次崩 1）。
- **第 2 层**（接上后的偶发 SIGSEGV）：不是"每帧重建的节奏"，是**类型混淆**。
  延迟安装走 `WindowPost(... ListenTo(owner, &ScriptView::OnComponentAppEffect, token))`，
  而该函数读 `self->runtime`；宿主实体是 `EntityNewRaw` 建的 8 字节 `PhpView{Box2*}`，
  按 ScriptView 布局读就是越界，拿到的垃圾指针非 0 时就被当 `ShellRuntime*` 调用。
  补丁给宿主视图换 `HostApplyComponentAppEffect`（经 `g_hostAppEffectRuntime` 直达 runtime）。
  ⇒ `--liveclick` 连跑 **20 次 0 崩**，`--selftest` matfails `1→0`，第 7 节 `Failed to render` 归零。
- **新门**：`App::componentFailure()`（shim `gpui_shim_component_failure` → runtime 的
  `ComponentFailureCount()`/`LastComponentFailure()`）。为什么必须有：registered 组件建不出来时
  gpui 不失败整帧，只把画面渲染成 `Failed to render X`，而 `registeredError()` 照不到
  materialize 阶段——这类错此前只能靠翻 stderr 发现。自检逐节比对计数增量。
  **反向对照实测会红**：故意把 `MenuItem` 直接挂到 `MenuBar` 下 →
  `SHOWCASE SELFTEST FAIL` + `badSections=7:materialize 失败(MenuBar accepts only Menu children)`；
  恢复后回 OK。第一版我用"扫 `gpui_debug_tree` 找占位文本"做门，**负对照没红**——
  debug_tree 打的是 spec 树，占位节点是 materialize 阶段才生成的，那条路子无效，已换掉。
- 第 7 节像素基线因菜单盒真的画出来而位移（gdfrac 0.00334）：重录前后逐文件比 md5，
  确认**只有 tree7.png 变化**，不是全量洗基线。
- 一次操作失误记在这里：负对照脚本里我写了 `git checkout <file> || 手工恢复`，而那次 python
  锚点没匹配上、`git checkout` 就跑了——该文件恰好是未跟踪状态才没丢东西。**带未提交改动的
  文件里不许放 `|| git checkout`**，恢复只用显式反向替换。
- 工具链接线：补丁进 `bin/gpui` 清单 + `patchManifest()`（marker `HostApplyComponentAppEffect`），
  doctor 现报 12 个 marker；`docs/guide/components.md` 里"暂未接入"那段已按事实重写。


## Phase 15：showcase 错误清单化后的处置（修了 2 条、1 条修通又主动回退、1 条是上游缺口）

先把"所有错误"从实际运行里枚举出来（`--selftest` / `--liveclick` / `--livetyping` /
13 节 `--shot` 各跑一遍收集 stderr 并去重），再逐条处置：

| 错误 | 处置 | 证据 |
| --- | --- | --- |
| 未知开关（如 `--debug`）静默起一个阻塞 GUI | **已修**：`--` 前缀的未知项报错 + 打用法 + `exit(2)` | `--debug` → rc=2 并打印用法；`--sections` 仍 rc=0/13 行 |
| 示例把 `MenuItem`/分隔符直接挂 `MenuBar`、还嵌套 `Menu` | **已修**：按上游契约重写（MenuBar→只 Menu；Menu→只 MenuItem/MenuSeparator） | 启用身份注册后 `MenuBar accepts only Menu children` 归零 |
| `failed to materialize MenuBar: component app effects require a root application snapshot` | **修通又回退**（见下） | 启用补丁时 matfails `1→0`、第 7 节 `Failed to render` 归零；回退后回到 1 |
| `registeredError()` / `badSections` / `lastErr` / `any` | 本来就是空的 | 自检报告 `badSections=`、`lastErr=''`、`err=''` |
| 第 5 节 "List 的 children 被上游丢弃（renderer 未接）" | 是**说明文字**（VirtualList 上游缺口，任务 #35），不是错误节点 | `sections.php:241` 是一行 `El::text(...)` |

- MenuBar 这条：卡点是 `MaterializeRequest::AppEffects` 只认 QuickJS 给的"根应用身份"。
  写了 `cmd/patch-host-application.sh`（宿主身份注册 + 单一 fallback，QuickJS 路径行为不变），
  **实测确实解掉卡点**。但接上之后 `--liveclick` 出现**偶发 SIGSEGV**，栈顶
  `ApplyComponentAppEffect ← RunPostedTask ← ExecDrain ← GpuiAppRunStep ← gpui_shim_pump ← App::loop`
  ——延迟安装的 app effect 在 apply 时踩到已释放对象。崩溃率：每实体一个身份 3 次崩 1；
  全宿主单一身份 6 次崩 1；**回退后 6 次 0 崩**。
- 决定：崩溃比一条 materialize 错误严重，所以**回退启用**（shim 侧调用删掉、补丁不接进
  `bin/gpui` 清单），脚本与读数留在仓库里，任务 #28 写清下一步（pending 安装按 key 去重 +
  `OwnedMenus`/arena 跨帧生命周期）。契约修正与文档保留——上游修好后接回即可验证。
- 门禁全绿：`--selftest`（裸 + bundle `bundled=true`）、`--liveclick 逐行=13/13` ×6、
  `--livetyping`、hello selftest、两套像素基线 gdfrac=0、Pest 74/347、PHPStan、
  shim C++ 测试、doctor、docs 构建（含死链门）。


## Phase 14c-18：真输入端到端复测通过（左侧可点、输入框可打字），#55 判为误报

- 用户开了辅助功能权限后，用 `open` 起**全新** bundle 实例（**不做任何外部激活**）走真 HID
  点击/按键，原报的两条症状正面复测通过：
  - 左侧导航：`click id=nav-5 value=1.0`、`click id=nav-2 value=1.0` 到达 PHP，
    画面真的换节（第 5 节表格/列表内容上屏），HUD 计数跟着走。
  - 输入框：点 in-name 敲 `h`,`i` → `change id=in-name value='hi'`，画面显示 `hi`
    且派生文本 `Hello, hi!` 更新；另一次全新实例只点 in-key、只敲 `7`
    → `key_down id=in-key value='7'` + `change id=in-key value='7'`，画面显示 `7`、`keyDown=7`。
    HUD：`点击 1 · 输入 1 · 按键 1 · 焦点 前台`。
- 起法差异记下来（下次别再被绕进去）：本沙箱里**直接 exec** bundle 内二进制常拿不到窗口
  （`visible=false`、System Events `count of windows`=0），走 `open` 才有真窗口；
  而且 `open` 若已有存活实例只会把它唤到前面——先前那次"改后首帧 active=true"其实是
  一个存活 1 小时以上的旧实例。判据：先 `pgrep -x showcase` 看存活数，再核对 pid 与 etime；
  杀进程要用 `pkill -x`（`pkill -f showcase` 会连自己那条 shell 一起杀，命令行里含 showcase）。
- #55（change 值"串字段"）判为**误报**：受控单字段序列下每颗各自正确
  （in-key=`7`、in-name 仍空）。起因是我自己那条多字段序列的时序没固定住。
- 本轮两处真修都在档：常驻 WKWebView 吃 `hitTest`（14c-12，回归位 `--selftest` navProbe，
  反向对照能红）与 `activateIgnoringOtherApps:` 在 macOS 26 上是空操作（14c-17，
  `activateTries=24` 全失败 → 首帧即 `active=true key=true`）。


## Phase 14c-17：#54 修好并量到——`activateIgnoringOtherApps:` 在 macOS 26 上是空操作

- 真输入 A/B（用户开了辅助功能权限，`open` 起 bundle + Quartz HID tap）先钉死了机制：
  **同一坐标 (475,401)=侧栏第 2 行**，app 前台时日志出 `click id=nav-2 value=1.0`；
  app 非前台时 `click id=nav` 计数 0，且那一下点击连激活都没触发。
  ⇒ 用户"左侧点不了"是**应用不在前台**，不是路由坏。
- 顺着这条读到实现：`cpp-src/gpui_mac.mm` 的 `gpui_mac_become_foreground()` 用的是
  `[NSApp activateIgnoringOtherApps:]`——macOS 14 起废弃，26 上实测不再把应用带到前台。
  改成 `respondsToSelector:@selector(activate)` 走无参 `-[NSApp activate]`（老系统才回退旧调用），
  并在 `gpui_mac_activate()` 里补 `[w makeKeyAndOrderFront:nil]`（只 makeKey 不够）。
- **系统自己计数器的对照**（同一处 selftest 读数，改前 vs 改后）：
  - 改前：`atStart=false/false` → 有界 activate 用满 `activateTries=24` → 仍 `active=false/key=false`
  - 改后：`atStart=false/false` → `active=true/key=true`
  即"内部申请前台"这件事从不可能变成可能；配上真输入那条 A/B，闭环成立。
- 没做成门的原因：`active/key` 是 OS 竞争态（本机实测同一个产物两次启动能分别量到
  true 与 false），拿它当硬断言会变成随机红灯——只报读数，不当门。
- 一次读数污染记在这里，免得下次重犯：我一度以为"改后 `open` 起的新实例首帧就
  `active=true`"，实际那个 tick#4236 来自一个**存活 1 小时以上的旧实例**（`open` 只是把它
  唤到前面）。此后凡"起一个 app 量状态"，先 `pgrep -x` 确认存活数、再核对 pid 与 etime。
  另外 `pkill -f showcase` 会把我自己的 shell 一起杀掉（命令行里含 showcase），要用 `pkill -x`。
- 门禁：showcase selftest OK（`navProbe fresh=13/13 afterAll=13/13`）、`--liveclick 逐行=13/13`、
  `--livetyping 合计=9/9`、像素基线 SHOTDIFF OK、Pest 74/347。


## Phase 14c-16：真输入实测（用户开了辅助功能权限）——非前台时同一坐标点了没反应

- 第一次拿到**真 OS 输入**的读数（`open` 起 bundle + Quartz `CGEventPost` HID tap，
  终端已授权辅助功能）。同一坐标 (475,401) = 侧栏第 2 行，做 A/B：
  - app 前台（trace `active=true key=true`）：日志出现 `click id=nav-2 value=1.0` ✅
  - app 非前台（trace `active=false key=false`）：`grep -c 'click id=nav'` = **0**，
    且这一下点击也没把 app 激活 ❌
- 所以用户报的"左侧还是点不了"现场复现了，而且**根因不是路由**（路由那条 13/13 是真的），
  是 **bundle 起来的 app 启动后不把自己变成前台应用**：`open` 之后 trace 仍是
  `active=false key=false`，直到我用 System Events `set frontmost to true` 才变
  `active=true key=true`。
- **订正 14c-15 里那条推断**：`-[GpuiView acceptsFirstMouse:]` 虽然无条件返回 YES，
  实测**并没有**让非前台窗口收到第一下点击（0 条事件、也没激活）。所以"鼠标不受影响、
  只有键盘要 key window"这句是错的——鼠标同样要 app 在前台。教训：系统策略要以实测为准，
  读到实现返回 YES 不等于那条路通。
- 待修的下一步（#54 已改写）：启动时把自己提升为前台应用（bundle 身份下
  `TransformProcessType(ProcessSerialNumber, kProcessTransformToForegroundApplication)`
  + `[NSApp activateIgnoringOtherApps:YES]` + `makeKeyAndOrderFront:`），
  并在真输入下复测"非前台点击"是否消失。裸跑二进制时这条路此前实测无效（无 bundle 身份），
  但 `bin/gpui run` 走的就是 bundle，且本次已证明该 app **可以被激活**，所以值得做。


## Phase 14c-15：启动时抢不到前台（A/B 实测）——但"第一下被吃掉"这句是错的，已订正

- 最后一条"用户现场 ≠ 门禁"的机制假设：探针绿是因为 `Deliver()` 在窗口不是 key 时替它
  `MakeKey`，而真实用户没有这一步——macOS 会把**第一下点击当成"激活这扇窗口"**吃掉。
  为此在 selftest 开头取了一份"还没碰过任何缝"的读数（`atStart`），并加了 `--startfront`
  做 A/B：启动时立刻 `activate()` + loop(2)，再读一次（`tryFront`）。
- 实测（bundle，`bundled=true`）：`atStart=false/false`、`tryFront=true/false/false`、
  自检那圈有界 activate 用满 `activateTries=24` 之后仍然 `active=false/key=false`。
  同一次运行里 navProbe 依旧 `13/13`。**"从终端起的进程抢不到前台"这条成立。**
- **但由它推的"所以用户第一下点击必然被吃掉"不成立**，是我没查实现就套了通用结论：
  `window_mac.cpp:589-592` 的 `-[GpuiView acceptsFirstMouse:]` 无条件 `return YES`，
  而 `window_mac.cpp:2212-2213` 把这个 view 同时设成 `contentView` 与 `firstResponder`。
  也就是说点击 hit-test 落到的就是 GpuiView，非前台时的第一下**照样会派发**，
  不需要"先点标题栏"。这条记在这里是为了别再被"macOS 都这样"的直觉带偏——
  凡是拿系统策略解释用户现场，先去读那个策略的实现。
- 因此剩下的手点差异只能靠真 OS 输入去量（用户已授权用电脑操控 MCP 走这条路）。
- 走了，但在**这个执行上下文里量不了**，两条读数记在这里：
  1. `osascript` 查 System Events 直接 `-25211 不允许辅助访问`（TCC 权限在用户机上，
     不去改隐私设置），所以注入式真输入依旧无路；截图里也看不到那扇窗口。
  2. app 本身活着：`--trace` 落到 `/tmp/showcase-events.log` 共 197 行，逐秒
  `tick#N active=false key=false`，且**没有任何 click/change/key_down 行**——
     也就是没人点过它，不是点了没到。
- 顺着 `acceptsFirstMouse:` 那条读出来的**关键区分**（下一轮该按这个问用户）：
  鼠标有"非前台也能收第一下"的路（`acceptsFirstMouse` 返回 YES），**键盘没有**——
  keyDown 要求窗口是 key window。所以"点得动但打不进字"这种组合在这条链上是自洽的，
  而 `atStart=false/false` + `activate()` 抢不到前台正是它的前提。HUD 那行显示
  「焦点 非前台」时，就是这个状态。




## Phase 17：菜单行的动作回到 PHP（#33）——合成派发已绿，真点原生菜单卡在锁屏

- 四步都落地了：MenuItem 的动作名在 `gpui_shim_registered` 里被收集 → `AppOnAction` 挂
  app 级处理器 → 处理器把 `ShellActionScriptId(action)` 反查回 PHP 写的那个 id →
  `enqueueRaw("action", id)` 进同一条事件泵。PHP 侧写法与按钮一致：`$app->onAny('action', fn)`。
- **真正的 bug 是登记时机**：`g_windowBoxes[box->window] = box` 那行写在
  `WindowOpenView` **之前**，那时 `box->window` 还是 nullptr，于是表里永远没有条目；
  app 级回调只拿得到 `Window*`，反查落空就静默 return。读数把两种病分得很清楚：
  改之前 `action=false/dispatched=true`（派发返回 true＝处理器被调过，但事件没进队列），
  改之后 `action=true/dispatched=true` 且 `evSeen=… action{cr-m-new}`。这条门自带反向对照
  ——"表没登记"那个状态就是它的红。
- 注册口子合并成一颗 `registerMenuAction()`：`El::menuItem`（registered 构造第二参）与
  `App::setAppMenus`（菜单行 action）走同一条路。去重表从 per-box 的 `actionNames` 换成
  进程级 `gMenuActionIds`，因为上游 `AppOnAction` 那张表本来就是进程全局的
  （`gpui.cpp kMaxAppActions=32`，框架自己占 2~3 格）。超预算不再静默丢事件：写进
  `box->lastError`，`App::lastError()` 与 selftest 的 `lastErr=''` 都看得见。
- 窗口关闭时把 `g_windowBoxes` 的键收掉：`Window*` 地址会被后面的窗口复用，留着就是
  往已关的 Box2 里塞事件。
- 门禁实测：裸 `--selftest` 连跑 3 次 `SELFTEST OK`（`action=true/dispatched=true`、
  `lastErr=''`）；`php bin/gpui selftest showcase --expect-bundle` 同样 OK。
- **顺带挖出 showcase 的一条真错**（属于"修复 showcase 所有错误"）：`App::setAppMenus`
  的键名与 bridge 不一致。bridge 读的是菜单条 `name`、行 `action`
  （`gpui_bridge.cc:570/584`），showcase 三处调用写的却是 `label`/`id`
  （`main.php` 的 mc-menus、interactiveFrame、selftest）。键写错**不报错**：
  `g.action` 落成 nullptr → `r.action=0` → `AppMenuChosen` 第一行 `!action` 直接 return，
  菜单看着装上了（`menus=1`、辅助功能树里 `{showcase=>关于;退出;}` 也在），点了永远没回音。
  还夹带一条：`['label'=>'分隔线以下','items'=>[...]]` 那种嵌套 items bridge 根本不认，静默丢。
  已按契约改成 `name`/`action` + `['separator'=>true]`。
- 为了把"菜单行装的确实是 PHP 声明的 id"变成能红的门，加了一条读表探针：
  `gpui_shim_menu_row_action(row,out,outlen)`（>0 字节数 / 0 该行无动作 / -1 越界）→
  bridge `gpui_menu_rows()` → `App::menuRows()`；selftest 装一颗带动作行 + 一颗分隔符 +
  一颗无动作行，断言 `rows=st-new` 且只有一条。实测绿，**反向对照也量了**：把键改回
  `'id' => 'st-new'` 重新编译 → `rows=/rowsOk=false` + `SELFTEST FAIL` + rc=1，
  而 `action=true/dispatched=true` 那半（El::menuItem 那条路）照旧绿——两条来源各自有位置。
  Pest 也补了一条双向锚点（正确键 → 出表；错误键 → 空表）。
- **真点原生菜单行为什么够不到（订正上一版的因果）**：`gpui_mac_send_mouse`
  （`gpui_mac.mm:367-385`）是 `[NSEvent mouseEventWithType:...]` + `Deliver(w, e)`——
  事件在**本进程内**直接投给自己的窗口，不经过窗口服务器。所以：
  1. `--liveclick` 在屏幕被锁的情况下照样 `逐行=13/13`、`到达节数=13`、rc=0，
     它的绿是"AppKit 层注入"这一类，不是"OS 把输入交给前台窗口"那一类；
  2. 系统菜单栏的 NSMenu 属于**另一个窗口**（菜单条），`Deliver` 只喂自己的窗口，
     所以这条缝从设计上就点不到原生菜单行，与锁屏无关；
  3. 真正被锁屏挡住的是 HID 级注入：`CGWindowListCopyWindowInfo(OnScreenOnly)` 顶层是
     `Display 1 Shield`（layer 2147483646）与 `loginwindow`（layer 2000/2001）盖住整屏，
     `screencapture` 全黑，`python3 + Quartz.CGEventPost(kCGHIDEventTap)` 的点击落在锁屏上，
     app 侧 `tick#N active=false key=false` 一路不变，System Events 报 `windows=0`。
  结论：原生菜单行的真点击要等屏幕解锁，且它验的是上游 `window_mac.cpp:1828`
  `gpui::AppMenuChosen([(NSMenuItem*)sender tag])` 那两行——我们能装、能读表、能派发，
  剩下那一跳不是我们的代码。
- 收口复测（同一颗最终产物，`static` 收敛后重建）：showcase 裸 `--selftest` OK、
  bundle `--selftest --expect-bundle` OK、`--liveclick` rc=0（`逐行=13/13`）、
  `--livetyping` rc=0（`合计=9/9`、`ROUNDTRIP 经节11回节2 导航=true 打字=4/4`）、
  hello bundle OK、`shotdiff check` 两个示例 OK、`cmd/test-shim.sh` OK、
  pest `75 passed (350 assertions)`、phpstan 无错、`docs npm run docs:build` 通过。
  读数：`menus=1 · action=true/dispatched=true/rows=st-new/rowsOk=true · lastErr=''`。


## Phase 18：用 Qoder 电脑操控 MCP 真点真打（屏幕解锁后）——菜单行那一跳补上了，key_down 量不到

用户令"点击走电脑操控 mcp"。屏幕这时已解锁（窗口栈顶层不再有 `Display 1 Shield`/`loginwindow`），
`computer-use` SDK 连上 `top.typephp.gpui.showcase`，读到的 AX 树就是 gpui 自己暴露的那棵
（13 颗导航按钮 + `19 menu bar → 20 showcase`）。全部读数落在 `/tmp/showcase-events.log`
（`--trace`，事件行格式 `$type id=$id value=…`）。

**量到的（都是真 GUI 交互，不是进程内注入）**：
1. **原生菜单行**：AX 点开 `showcase` → 返回的子菜单里两行都带 `ID: gpuiAppMenuItemChosen:`
   （正是 Phase 17 说"够不到"的那一跳），随后 `change`… 不，是 **`action id=menu-about` 落进日志**。
   做法：`click(菜单条行)` → 用**返回状态**里的行号 `performSecondaryAction(..., "Pick")`。
   直接 `click(行)` 会报"菜单已关"——SDK 每次动作后重新抓状态，那一抓就把菜单关掉了；
   `Pick` 这条 AX 动作不受影响。**#33 的最后一跳就此验完。**
2. **导航（AX press）**：`click id=nav-7 value=1.0`，截图确认画面切到「侧栏、表单与菜单」，
   HUD 那行同步显示「已收到事件 点击 1 … 焦点 前台」。
3. **导航（坐标级真鼠标，走 hit-test）**：`click id=nav-2 value=1.0`。
4. **真打字回环**：点进 `in-key` 后 `pressKey a`、`pressKey b` →
   `change id=in-key value='a'` → `value='ab'`。逐字符累积正确，没有串字段。

**量不到的：`key_down` 一条都没有**（同一次会话里 `--livetyping` 的进程内探针报 9/9）。
按过的键：`a`/`b`/`Return`/`Up`/`Tab`/`1`/`c`/`Escape`，全部只出 `change`（`Tab` 甚至把制表符
插了进去：末值 `ab\t1c`）。两条候选成因与已做的排除：
- **候选一：注入的键事件带的是字符而不是真 keyCode**（上游 `WindowMacKeyDown` 里
  `key = KeyForEvent(event, first)`，`if (key)` 才 `WindowKeyDown`；`gpui.h:770` 注明
  "vk 是 0 for a typed character"）。这条与所有读数自洽，但**没证**：想拿 HID 级
  `CGEventCreateKeyboardEvent(NULL, 18, …)` 投一颗真 keyCode 进去判别，结果**一条事件都没到**
  （日志停在 MCP 那批之后）——这个执行上下文里 app 不是 WindowServer 认的钥匙窗口，
  HID 键盘投不进去（与 [[feedback-macos-input-injection]] 同一堵墙）。
- 候选二：我们的 key_down 通道对真按键坏了。目前**没有证据支持**，因为它与"进程内同一条
  `-[GpuiView keyDown:]` 入口能出 key_down"直接矛盾。
所以这一条判为**待真人手按**：在 `in-key` 里手敲几个字符，看日志有没有 `key_down id=in-key`。
在那之前不许把它写成已修或已坏。

**工具层经验（下次少烧两轮）**：`typeText("abc123")` 对 gpui 完全没效果（`pressKey` 逐键才有），
元素 ID 每次动作后失效（要用**动作返回值**里的新序号，别复用上一次的），
菜单一抓状态就关（用 `Pick` 而不是 click）。


## Phase 19：全库审核开工（第 1、2 步落地）

按审核给的顺序走，每步都带能红的对照。

**第 1 步（可复现）**：把 CI/构建点名却未跟踪的输入纳入版本控制——3 支补丁脚本
（key-view / hover-always / host-application）、整个 examples/showcase、
tests/ShowcaseTest.php、13 张 showcase 基线、cmd/real-input-probe.py。提交前逐个扫敏感串。
验收不是"我说提交了"，而是拿 `bin/gpui:162` 的补丁清单与 showcase yml 的 `sources:`
去对 `git ls-files`：**11/11 支脚本、6/6 项 sources、CI 脚本与基线全在树里，剩余未跟踪 0**。

顺带挖出并修掉 `patch-host-application.sh` 的预检错：它拿只进 .cpp 的 trampoline 符号
`HostApplyComponentAppEffect` 去 .h 里找，于是**已完整打好补丁的沙箱被判成"半补丁"、rc=1 中止**。
流水线里看不出来，只因 `bin/gpui` 每次都先 sync-upstream 把沙箱重置。改成每个文件认自己的
落点符号后，三支脚本单独重跑全 rc=0 且不自毁（key-view 的自检要求 `gpuiEnqueueKey` 恰好 4 处、
残留旧体 0，跑两遍仍是 4）。头部那段"⚠️ 未接线、启用会 rc=139"也订正成实况。

**第 2 步（四条静默错）**：
1. **建窗失败路径双删**：`EntityDrop(viewEntity)` 的 deleter 已 `delete PhpView`，紧接着又
   `delete view`。改成只 drop `shellRoot`——`ShellRoot` 的析构自己 `EntityDrop(content)`
   （root.cpp:773），content 的 deleter 再删 PhpView，一条链走完；原先 `shellRoot` 反倒从没放过。
2. **四类元素的 id 走错字段**：`table/list/tabs/textView` 把 `$id` 写进 `text`，而 base kind 的
   回调路由键是 `node['id']`（gpui_bridge.cc:149,294）⇒ 挂 `->onClick()` 注册的是空 id。
   新增反射门 `every factory that takes an $id puts it where its kind routes events`：
   base kind 断言进 `id`，registered 组件断言进 `ctorArgs[0]`（两套约定都钉）。
   **反向对照**：把 `table` 改回 `text` → 门红并点名 `table()`；还原后绿。
   反射还多抓出一处：`alert()` 的 `$id` 是原生构造实参（不是路由键），所以规则必须分两类，
   一条 blanket 断言会误伤——这条已写进测试注释。
3. **`App::checked()` 恒 false**：`php_gpui_element_value` 是 `// Phase 4` 空壳。实现为
   "最近一次随 change 上报的值"：`Box2::rememberValue` 在 `enqueue`/`enqueueRaw` 两处写，
   新口 `gpui_shim_element_value` 按 vkind 读；**null（从没报过）与 false（报过、值为假）分开**。
   新增 `App::elementValue()` 取原始值，`checked()` 明确是它的 bool 投影。
   门禁两层：Pest 钉假侧语义（null→true→false、无值事件不覆盖），showcase selftest 加
   `valuePull`——用**真打字**那条链（enqueue→rememberValue→bridge→PHP）读回打进去的 'g'。
   **反向对照**：把 `rememberValue` 摘成空操作 → `valuePull=false` + `SELFTEST FAIL` + rc=1，
   还原复绿。附带发现 `agree` 这个读数过去只记录、从不进 `$ok`。
4. **`bin/gpui doctor` 永不红**：`verifyPatches()` 返回值被丢、case 无 exit。改成 `bad()` 计数
   + doctor 据此 `exit(1)`。**双向都量**：`GPUI_DIR=/tmp/definitely-not-here` → 12 条 ✗ rc=1；
   正常树 → 17 条 ✓ rc=0。同一条链上揪出 **6 处"变量后紧跟全角标点"**的插值陷阱
   （`"$script：…"`、`"「$marker」"`、shotdiff 的 `"$tree：出图失败"` 等）——PHP 把全角字符吃进
   变量名，于是**失败分支的文案**恰好是最先坏的那条（实测退化成 `Undefined variable` 警告）。
   统一改 `{$...}`，并用"变量名后紧跟任何非 ASCII 字符"的正则全仓复扫到 0 处。

**一次不可复现的构建故障**（记下来免得下次当代码回归）：`bin/gpui selftest hello` 有一次在
amalgam 步报 `clang++ not found`，而同一条命令手跑、经 PHP `passthru` 跑都正常（gpui.o 时间戳
就是那次 run 的），重跑 hello rc=0。真正的问题是**报错把"编译失败"说成"amalgam 对象未生成"**，
第 3 步要给 build 链加退出码透传。

第 2 步收口读数：showcase 裸/bundle selftest OK（`valuePull=true`、`action=true/rows=st-new/rowsOk=true`）、
`--liveclick 13/13`、`--livetyping 9/9`、hello OK、shotdiff 两示例 OK（元素 id 换字段**没有**改动像素）、
pest 77 passed (397 assertions)、phpstan 无错、test-shim OK、docs build OK。

**第 3 步（漂移警报）**：
- 补丁清单收敛成一份：`patchScripts()` 从 `patchManifest()` 派生，`bin/gpui build` 与新的
  `bin/gpui patch` 子命令都走它，`composer patch` 改成 `@php bin/gpui patch`。以前
  composer 8 支 / bin/gpui 11 支 / GPUI.lock 写 7 个，单跑 composer 得到半补丁树
  （缺的正是防 key_down 段错误那支）。doctor 加"cmd/ 下每支 patch-*.sh 必须在清单里"，
  负对照（丢一支假脚本进去）rc=1 点名。
- `kindFromString` 的静默兜底改成拒收 + 写 `lastError`。**这条改动当场抓出真 bug**：
  `div` 从来就不在表里，全靠兜底存在——第一次跑 tree1 像素差 0.05649（worst=1.000）、
  selftest 直接卡到看门狗（rc=142）。补进表后 shotdiff/selftest 全复绿，且证明
  webview/inspector/notifications 三个伪 kind 确实不走 push。教训：兜底分支把
  "最常见的值"和"拼错的名字"抹成同一个形状，四层都不会报错。
- 新增 `cmd/check-c-abi.sh`（gpui_shim.h 用 `clang -x c` 编一遍 + 文本扫 C++ 记号）与
  `cmd/check-build-inputs.php`（补丁脚本/清单/示例 sources 是否都在版本控制里），
  分别挂进 CI 的 native 与 php job。
- 新增 `tests/ContractTest.php`：stub ↔ FakeBridge ↔ bridge 名字与元数对齐、App.php 调用的
  `gpui_*` 必须已声明、Element 发出的 kind 必须在 shim 表里在册。反向对照：把 stub 里
  `gpui_menu_rows` 改名 → 两条差集立刻红并点名；把表里 `div` 删掉 → kind 门红。
  顺手修了 `gpui.stub.php:230` 两条声明挤同一行（php -l 不报，行向读取会静默漏一个）。
- 自己的测试也翻过一次车：`mb_strpos` 给字符位、`substr` 按字节切，混用后窗口偏进表中间，
  25 个合法 kind 被误报"未登记"。已改回字节位并写明原因。

**第 4 步（shotdiff 的陈旧产物坑）**：`$tmpDir` 里的旧图先删再跑、`--shot` 的退出码必须收
（非 0 即失败）、产物还得"读得开且像一帧图"（`getimagesize` + 尺寸/字节下限）才允许比对或
record——空白帧一旦被 record 洗成基线，之后每轮都拿它当标准答案全绿。`record` 现在把新旧
字节数都打出来供人复核。
反向对照两次都做真：种一张**与基线逐字节相同**的旧 tree1.png，再让二进制以 rc=3 退出 →
门红并报 `--shot 退出码 3（非 0 就不算出图）`（旧代码会拿那张种下的图比出 ✓）；
换成"rc=0 但不写文件" → 报 `出图失败（退出码 0 但没有文件）`，同样红。
顺带：我自己新写的那条消息又中了同一个雷（`$rc（不是` 被解析成变量名，实测 26 条 PHP Warning），
连同 `check-build-inputs.php` 里的 `$rel（` 一起改成 `{$...}`；用"变量名后紧跟非 ASCII 字符"
的宽正则复扫，全仓现在 0 处。

**第 5 步（第一批：只增不减的容器 + 一条常驻仪表）**：
- 新增 `App::stateCounts()`（`gpui_shim_state_counts` → 13 个容器条数）。没有仪表，
  "重挂定时器不涨表"这类话就只能靠读码相信；现在它是 selftest 的一条门。
- `setTimer` 同名重挂时把 `timerKeys` 的旧号一起收掉（修前每挂一次净增一条，永不回收）。
  **量过**：修后 `timerKeys 0->1`；把回收循环摘掉 → `0->50` 且 `SELFTEST FAIL` rc=1；
  还原复绿。这是本步第一条带数字的回归位。
- 窗口析构 `delete dlg` 时把 dialog 的 `g_views` 键一起 erase：那些 `PhpView*` 已被上游
  实体拆除释放，留着悬垂键意味着日后同一地址被新视图复用时 `boxForView` 会反查到已删除的
  Box2（安全审查也点了这条）。
- **没做也不硬做**：dialog/sheet 关闭时立刻回收 Box2。上游 `ShellRoot` 自己持有内容实体，
  代码里就写着"再手动 EntityDrop/delete 就是 double-free/UAF（tree13 曾 SIGABRT）"，
  在没有可靠的关闭期读数之前不猜所有权。这条留在 #61。
- 顺带又中了一次自己刚修的雷：报告串写成单引号（`'timerKeys=$tk0...'` 不插值）；
  以及门的第一版把基线写成 1——取数点在第一次 setTimer 之前，实际是 0，
  是**我的断言错**不是产品错，这条区分清楚才没去"修"一个没坏的东西。

**第 5 步（第二批：每帧字符串开销之一）**：`kindFromString` 从"`std::string s(k)` + 至多
46 次 `operator==`"改成 46 项静态表 + `strcmp`，零堆分配。它跑在**每个元素每帧**上
（showcase 每帧重建整棵树），所以省的是"元素数 × 帧数"。
行为等价的证据：`shotdiff check showcase` 13 棵全 `gdfrac=0.00000`、selftest OK、
`shim_test` 的 kindFromString 双向断言仍过。
连带：`ContractTest` 的 kind 门解析器同时支持老写法 `if (s == "x")` 与新表项 `{"x", K::Y},`
——不放宽的话它会在表一换形状时立刻报"读不到任何 kind 名"，这正是要的行为。

**第 5 步①（每帧无条件重建 → 脏驱动）**：`App::loop()` 的渲染改成四条判据之一才画
（每轮 loop 首帧 / 本轮派发过事件 / `windowState()` 的 active-key-visible-main 变了 /
`invalidate()`）。**量到的**：40 个空闲帧只画 1 次（`dirty idle=1/40frames`），
把脏门短路成"永远画"则 `idle=40/40` 且 `SELFTEST FAIL` rc=1，还原复绿——正反两半都有数。
前置改动：把"抢前台自举"从示例的每帧回调搬进框架（`keepTryingForeground(12)` +
`foregroundTries()`），否则帧被跳过会变成"没画→没尝试→永远不是前台"的死结。
新增 `App::renderCount()` / `invalidate()`。
连带修的两条测试（都是测试逮到语义变更，不是产品坏）：
`view 预热一帧 + loop 每帧一次` 断言的正是被改掉的那条契约，按新契约重写并显式测
"空闲帧被跳过 + 事件/invalidate 各自叫醒一帧"；`showcase 覆盖 App 全部公开 API`
因为新加的 `invalidate()`/`foregroundTries()` 没被调用而红，遂在真正需要它的地方
（直接改 `section` 而不走事件）用上它。
文档：`architecture.md` 里"`ShellMaterializeSpec` 每帧翻成原生树"这句已不成立，改成
"这一帧"+ 脏判据说明；`events.md` 新增「渲染是脏驱动的」一节列出四条判据与新 API。
门禁：pest 81 passed、showcase 裸/bundle selftest OK、`--liveclick 13/13`、
hello OK、shotdiff 两示例 OK、docs build OK。

**第 5 步②（回调路径的每帧分配）**：`newCallback` 原来每次调用要构造 3 个 `std::string`
（两个 `const char*`→`const std::string&` 的隐式形参 + 拼键的 `elementId + "\x1f" + type`），
再加 `opName` 与 `"on_" + opName` 两次——每个回调 op 每帧 5 次分配。改成签名收 `const char*`、
键与 op 名各用 Box2 上的复用缓冲（capacity 跨帧保留，稳态零分配）。
行为等价的证据：`--selftest`、`--liveclick 13/13`、`--livetyping 9/9`、hello、
shotdiff 13 棵全 `gdfrac=0`、`shim_test` OK——回调身份若错，点击/按键路由会当场塌。
（没有做墙钟计时，所以这里只声明"分配次数按调用图从 5 降到 ~1"，不报百分比。）

**第 5 步②（bridge 每 op 的分配）**：`opKind`/`name` 两个 `std::string` 与
`args`/`backing` 两个 vector 从"每 op 构造一次"提到"每节点一次"（循环外声明 + 循环内
`assign`/`clear`，capacity 复用）。放在节点这一层而不是函数静态，是为了不跨递归别名：
`backing` 里的 `c_str()` 只需在本节点这次同步调用期间活着。
证据：两示例 shotdiff 全 `gdfrac=0`（方法实参或 style 传错一定会在像素上露出来）、
selftest/liveclick 13/13/livetyping 9/9/hello/pest 81 全绿。

**第 5 步④（拆 Box2 那座"2628 行的山"）**：先否掉"拆成独立 .cpp"那条路——`gpui.h` 是
32 万行的 amalgam，每多一个包含它的 TU 就多付一次全量编译（gpui.o 本身就 27.5 MB）。
改用**文本切片**：`gpui_shim_services.inc`（HTTP/目录监听/解压，677 行）与
`gpui_shim_caps.inc`（Capabilities，354 行）由主 TU 在原位置 `#include`，语义、符号可见性、
编译次数全不变，主文件从 2729 行降到 1710 行。
中途踩到一次切片边界错：第二刀的"下一个横幅"判定因 Python `or`/`and` 优先级写错，
把第一刀留下的 caps include 一起卷进了 services.inc（文本上等价、组织上 caps 变成了
services 的子块）——发现后挪回主文件，并复跑全套门禁（selftest OK、两示例 shotdiff
`gdfrac=0`、shim_test OK）。

**第 5 步②（示例侧的每帧重复）**：`showcaseShell()` 每帧把整张节表建两遍
（`foreach (showcaseSections())` 之后又 `$sections = showcaseSections()`），
且选中行先建一颗 ghost 按钮再整个丢掉重建 primary。改成表建一次、按钮建一次、
variant 按选中态决定。等价证据：13 棵 shotdiff 全 `gdfrac=0`（选中行的样式就在画面里，
variant 没挂上一定会花）、selftest OK、`--liveclick 13/13`。

**第 5 步②（F2：同一段文本在 arena 里存两遍）**：`gpui_shim_push` 的 text/value、
`toBridged` 的字符串实参、6 处 `so.name` 全部从 `AStr(arena, …)` 改成借用 `cppStr(…)`。
依据是上游自己的深拷：`SpecArena::Push → CopyComponent` 对 text/value 各做
`StrDup(arena, …)`（spec.cpp:296-297），`PushOp → CopyOp` 对 op.name 与每个字符串实参
同样 `StrDup`（spec.cpp:324-333）——先 AStr 就是把同一段文本存两遍。
安全性来自"这次调用是同步的"：bridge 的 std::string 活到 Push 返回。
这是本项目有前科的一类改动（tree13 的 toast 把 "Saved" 画成 "succe" 就是借用超生命周期），
所以证据取最强的三样：两示例 shotdiff 全 `gdfrac=0`（文本像素）、selftest 的
`clip='SHOWCASE-Ω'` 多字节回环、`--livetyping 9/9` + `--liveclick 13/13`。
`toComponentArg`（registered 构造实参）**没**改：它的落点不是 Push/CopyOp，深拷不成立。

**第 5 步④（补刀）**：把事件汇（`boxForView` + 8 个 `gpuiEnqueue*` 强符号，115 行）从
`services.inc` 里分出来成 `gpui_shim_events.inc`——它原本被我第二刀的边界错留在 services 里，
文件名与内容不符，正是拆文件要避免的那种"名字在骗人"。
证据：`--liveclick 13/13 到达 13 节`、`--livetyping 9/9`、selftest OK、shotdiff `gdfrac=0`、
shim_test OK、hello OK（事件汇是点击/按键/定时的必经之路，错了这些一定塌）。
主文件 1716 行 + 三块 .inc（caps 361 / services 567 / events 119）。

**第 5 步③（overlay 回收，之前缓做的那条现在有条件做了）**：先取到"可靠的关闭期读数"再动手——
上游三条路都是**同步** drop：`CloseTopDialog`（root.cpp:330）与 `OpenSheet` 换面（root.cpp:490-494）
都 `EntityDrop(app, layer)`，而 `~ShellDialogLayer` / `~ShellSheetLayer` 里
`EntityDrop(app, content)`（root.cpp:209 / 398），content 的 deleter 就是我们注册的
`delete PhpView`。所以 close/replace 返回之后回收包装是安全的，不回收才是 bug。
做法：`retireOverlay()`（erase g_views 键 + delete 包装）+ `syncOverlays()`（按
`ShellRootDialogCount` / `ShellRootHasSheet` 这两个**权威**读数对齐包装栈），
show/close/count/has_sheet/state_counts 五个口子都过一遍。
为什么必须有 sync：dialog 能被 **Escape 或点背板原生关掉**（`DialogOptions.escapeDismissable` /
`OnBackdrop` → `ShellDialogLayer::Close`），那条路不经过我们的 shim，只在 show/close 里弹栈
一定会脱钩。
门禁：`overlay churn=30 left=0 boxes=1->1 views=1->1`（30 次开关 + 10 次换 sheet 之后
进程级存活数回到基线）；`overlay backdrop nativeClosed=true reclaimed=true`
（点背板原生关掉，下一次轮询把它收掉）。
**第一版门是套套逻辑**：它读的是 `dialogs` 这条 vector 的长度，而 pop 在调用方做，
回收与否都是 0——反向对照（把 retireOverlay 摘成空操作）照样"绿"才发现。
补上 `boxesAlive` / `viewsAlive` 两个进程级计数后，同一个反向对照直接 **SIGSEGV rc=139**：
不回收不是"漏点内存"，是会踩到已释放的视图。
顺带两次踩坑记在这里：① `state_counts` 里调 `syncOverlays` 时把前置声明放在了 C++ 作用域，
而定义在 `extern "C" {}` 块内 ⇒ Clang 报 "call is ambiguous"（两个同名不同链接性的实体）；
② 背板点击最初用 (30,700) 落在窗口 resize 边上，AppKit 的 `_mouseHysteresisCheck:` 等下一个
事件而不返回，selftest 卡到看门狗 rc=142 —— 换内容区内的点即好（这条坑记忆里有过，
还是先踩了一次才想起来）。

**第 5 步⑤（ComponentKind 漂移警报，第 5 步的最后一项）**：`kindFromString` 的词法表原先是
函数内的 `static const struct {...} kTable[]`，`shim_test` 拿不到它 ⇒ "上游加了个 kind、
PHP 侧永远建不出来"这种漂移没人报。把表提到文件作用域（`struct KindName` +
`static const KindName kKindNames[]`，46 项），测试就能遍历它。
警报的**判据选错了会被自己骗**：一开始想写"每个枚举值都得有名字"，实测上游有 18 个值
本来就不可能靠 kind 字符串建（`Registered` 走 `gpui_shim_registered` 的描述符索引、
`ChildView` 是 shim 内部挂原生子视图用的……），那样每条 CI 都红。改成量**未登记值的个数**
（`kExpectedUnnamed = 18`，白名单只放 `Registered`/`ChildView`）：上游加/删 kind 时这个数
必变，红一次提醒去看"要不要给 PHP 暴露它"。
反向对照：删掉 `{"text", K::Text},` ⇒ `✗ 未登记的 ComponentKind 从 18 变成 19` rc=1，还原 rc=0。
**这次搬表把 `tests/ContractTest.php` 的 kind 门弄红了**（它从 `kindFromString(const char* k`
起截 12000 字节，表搬到函数上面后窗口里一个名字都没有 ⇒ "解析器退化了"）。修解析器时顺手
把窗口收紧到表的 `{...}` 之间：原先从函数头截 12000 字节会越过表读到后面的代码，
那边随便一个 `{"名字", ...}` 都会被当成"这个 kind 在册"，门是虚的。
反向对照（PHP 侧）：同样删 `{"text", K::Text},` ⇒ ContractTest 红并点名 `text`，还原
`vendor/bin/pest` = **81 passed (416 assertions)**。
门禁（搬表动了 shim，整条原生链**重建后**复测，不拿昨天的产物判今天的绿）：
`SHOWCASE rc=0`（含 overlay churn=30/left=0/boxes 1→1/views 1→1）、`HELLO rc=0`、
`SHOTDIFF rc=0`（tree0–13 全 `gdfrac=0.00000`）；phpstan / doctor / check-c-abi /
check-build-inputs / check-cxx17 / test-shim 均 rc=0。

**第 5 步收口（也是审核五步的收口）——明确记下的"不做"及其理由**：
审核后还挂着两条性能/结构项，评估后**主动不做**，不是漏掉：
- `F8`：`std::deque<GpuiEvent>` 每事件约 440 B 的分块分配，以及 webview 每帧重算
  `wantUrl`/`wantHtml` 两串。前者要换成事件池就得动 `poll_event` 的返回形状（C ABI +
  stub + bridge + FakeBridge 四层），后者要加"URL/HTML 未变则不重设"的脏标记。
  两条都是**可测但收益薄**：事件是用户动作级的量（一次点击一条），不是每帧每元素级；
  而 ① 已经把"每帧无条件重建"这个真正的乘数拿掉了。动它们的收益/风险比不划算。
- `④续`：把 `HostedViewRegistry` / `RetainedStore` / `NotifyKey` 再从 `Box2` 里拆出去。
  现主文件 1793 行 + 三块 `.inc`（caps 361 / services 567 / events 119），可读性已经解决；再拆要动
  `Box2` 的**析构顺序**（dialog 内容归上游持有，见本步③那段所有权推理），
  那是把已经验过的生命周期重新变成未验状态。等下一个真需要跨窗口共享的功能出现再拆。
所以别人问"#61 完了怎么还有性能项"，答案在这段：**①②③④⑤ 都带了反向对照的数字**，
剩下的是评估后砍掉的，砍掉的理由写在这里而不是留在对话里。

审核五步到此结束。仍然开着的两项都不在这五步的范围内，且都需要人或上游才能推进：
`#56` key_down 在真 OS 按键下是否触发（要真人手按一次才能判别"没人监听"还是"没人送到"），
`#62` PHP 宿主下 Escape 关不掉 dialog（focus trap 从未拿到焦点，属上游焦点分配）。

## Phase 20：对齐上游 gpui-kit（文档 + 三处"op 走错通道"的修复）

**先量后修**：把上游三张权威词法表做成可复跑的抽表器 `cmd/upstream-vocab.php`
（读 **vendored 在仓库里的** `upstream/gpui-kit-cpp`，CI 上不需要外部树）：
`ComponentKind` 66 值、`materialize.cpp` 的 101 个 op 名、prelude 的 11 个槽名、
`component_shell` 的 126 descriptor / 137 导出名 / 301 方法名。
抽表器第一版只认 `ComponentDescriptor kX = {…}`，漏了家族表 `kX[] = { {…} }`，
少算 28 个组件——是拿文档站的组件名单回查才发现的（教训已进记忆）。

**新门 `cmd/check-upstream-parity.php`**（composer `parity:upstream` + CI + Pest 各一处）：
我们发出的每个按名字查表的符号必须在册，含 **bridge 里硬编码的 op 名**。
它的第一次运行就是反向对照：修之前红，点名 `variant, outline, focus, content_focus`；修完绿。

**修掉的三处静默错**：
1. `El::primary()/secondary()/danger()/ghost()/outline()` 发的是 `method('variant', [...])`，
   上游两个名字都不认 ⇒ 五个糖一个像素都不改变。查 descriptor 才看清：catalog `Button`
   根本没有 `variant` 方法，变体本身就是零参方法（`primary/ghost/outline/success/warning/
   link/compact/loading`），`variant(enum)` 属于 DropdownButton/Tag/Bubble/Marker/GroupBox/TabBar
   ——我上一轮的判定对 Button 是错的，这条以 descriptor 表为准。
2. bridge 里 `"focus"` / `"content_focus"` ⇒ 正确名是 `track_focus` / `content_focus_handle`：
   Select 那两个 retained focus 句柄**从未交出去过**。
3. `rm()` 打在 kind 节点上（示例里 `El::progress(...)->rm('size', …)`）：RegisteredMethod op
   在非 registered 节点上连"未知名"都算不上（`ResolveBehavior` 开头就 continue）。
   现在 catalog 糖统一走 `catalogMethod()`，非 registered 节点直接抛 `InvalidArgumentException`。

**新增公开 API**：`El::catalog(导出名, 构造实参, children)` 与 `El::catalogButton(id, label)`
——上游 137 个导出名从"只有 21 个能用"变成"全部可达（22 个有专用工厂）"。

**像素侧的收获与一次自我纠偏**：变体行现在真的长出五种样式（primary 深色药丸 / secondary 灰 /
danger 粉 / ghost 透明 / outline 描边），kind 版那颗留作对照并标了 `(kind)`。
但把侧栏导航也换成 catalog Button 之后，新图里长标题被**居中裁字**（"服务：http · 目录监听 · 解压 ·
系统"两头掉字）——catalog Button 按盒子裁标签。遂把导航回退成 base Button，选中态改用 `->bold()`
表达（旧基线里选中态本来就看不见，因为 primary/ghost 一直是哑的）。这条坑写进了组件文档。

**门禁**（bridge 动过 ⇒ 整条原生链重建后复测）：`SHOWCASE SELFTEST OK`、
`--liveclick 逐行=13/13 到达节数=13`（导航行此时还是 catalog Button 版本，等于顺手证明了
catalog 组件的 `on_click` 真能路由到 PHP）、`HELLO SELFTEST OK`、
基线重录后 `shotdiff check` 两示例 OK（showcase 13 棵 + hello 5 棵字节数变化，逐条打过新旧字节）、
pest 85 passed (436 assertions)、phpstan / test-shim / doctor / check-c-abi / check-build-inputs /
upstream-parity / docs:build 全 rc=0。

**修复轮 2（#69 表格语义）**：`gpui_shim_push` 加 `unsigned index` 槽 +
`gpui_shim_spec_index_by_id` 回读口（写必须在 Push 时，`SpecArena::Node()` 只有 const）；
PHP 侧新增 `El::tableRow()/tableCell()`、`->rowCount()/columnCount()`（method op）、
`->rowIndex()/columnIndex()`（序号槽）与 `App::specIndex(id)`（-1 = 查无，与 0 分得开）。
hello 与 showcase 的表格改成 Table > Header/Body > Row > Head/Cell。
新自检断言**两半都量**：故意 `rowIndex(0)` → `table rowIndex=0,1,0/ok=false` rc=1；
改回 `rowIndex(2)` → `0,1,2/ok=true` rc=0。
门禁：showcase/hello 的 selftest + 基线重录 + check 六步全 rc=0（表格结构变了，像素必然变），
pest 87 passed (444 assertions)、phpstan / parity / test-shim / check-c-abi /
check-build-inputs / doctor / docs:build 全绿。

**修复轮 3（#62 机制侧收口 + 一条门禁哑路）**：
- 五层装 `focusTrap()` 回读口（shim `gpui::FocusTrapActive` → bridge `php_gpui_focus_trap` →
  stub → `App::focusTrap()`，Fake 侧按 dialogCount 模拟），SystemTest 补一条"框开着陷阱就在"。
- showcase selftest 加 `$trapOk` 硬门（不依赖输入）：`loop(10)` 后 `trap != 0 && focus == trap`。
  连续两次读数一致（`trapOnOpen=0 / trapAfterFrames=focus=178826760`）。
  **负向对照已做**：把 `loop(10)` 挪到两个读数之后 ⇒ `trapAfterFrames=0 focus=-1062 trapOk=false`、
  selftest rc=1；这门因此拿到回归位，不是恒真。
- 新 `--escape` 独立探针（进程里第一个框、没点过任何东西）：`trapAfterOpen=0
  trapAfterFrames=focus=1010534090 closed=false` ⇒ 陷阱链在宿主里是通的，剩下只差真键送达，
  与 #56 同一件事。先前"在 openDialog 里补 `FocusTrapApplyPending`"的候选修法已否并回退。
- **抓到一条门禁哑路**：`composer.json` 里 `test:cpp` 与 `test:all` 用的是 `@bash cmd/test-shim.sh`，
  而 composer 只认 `@php/@composer/@putenv`，`@bash` 被当成"另一个脚本名叫 bash"，
  打一行 `You made a reference to a non-existent script` 后 **rc 仍为 0** ——
  也就是说文档里那句"一键 composer test:all 含 shim_test"一直是假的，本地跑 test:all 从不编 C++。
  CI 有独立的 `bash cmd/test-shim.sh` 步骤（ci.yml:165）所以没漏进产物，但本地门形同虚设。
  改成 `bash cmd/test-shim.sh` 后双向验过：绿向 rc=0 且输出是 shim_test 自己的分节名、
  那条 reference 警告 0 次；红向（`GPUI_DIR=/nonexistent-gpui`）rc=1 且 composer 明报
  `Script bash cmd/test-shim.sh handling the test:cpp event returned with error code 1`。
- 本轮全门禁：test:all rc=0（四道都真跑）、check-build-inputs / check-c-abi / doctor rc=0、
  hello selftest rc=0、shotdiff check rc=0（tree 0..32 全 gdfrac=0.00000，探针改动不触像素，
  这本身也是一条旁证）。
  **订正**：那句"tree 0..32"数错了对象——`bin/gpui shotdiff check` 不带示例时默认跑 **hello**，
  而 hello 的在册集是硬编码的 18 棵（`cmd/shotdiff.php:40`，实测 18 张基线 md5 各不相同）；
  showcase 的 13 棵要显式 `shotdiff check showcase`（下一轮已补跑，13 棵全 gdfrac=0.00000）。

**修复轮 4（#70 无障碍与焦点 op 族）**：
- `El::role()/ariaLabel()/ariaLevel()/ariaSelected()/ariaActiveDescendant()/tabIndex()/
  tabStop()/keyContext()/selectable()` 九个糖，全部走行为链的 method op；
  `role()` 的名字必须落在 `Element::ROLES`（上游 `kRoleNames` 的 snake 形，**181** 个）里，
  否则当场抛 —— 上游那句 `if (role != None) element->Role(role)` 对未知名字完全静默。
- 取数端 `cmd/upstream-vocab.php` 新增 `roles()`（跨行相邻字面量先接回整体再按 `\0` 切，
  `DescriptionLis" "t` 这种不会漏），并加 `--dump-roles` 直接生成 PHP 字面量；
  `cmd/check-upstream-parity.php` 新增 2b 段把 `Element::ROLES` 与上游表**双向**对齐。
  **两个方向都做过负向对照**：删掉 `terminal` → "上游有 1 个没进白名单" rc=1；
  加 `not_a_role` → "上游 kRoleNames 里没有" rc=1；还原 rc=0。
  （第一轮对照两头都"绿"，是 perl 模式没匹配上——`'list_grid', 'terminal',` 实际跨两行——
  所以这轮每次改动都先 `grep -c` 证明补丁落到文件上再跑门。）
- 三层验收：Pest 3 条（op 名与实参形状逐条钉、整表 181 名可用 + 未知名抛、ariaLevel 拒 <1）；
  showcase selftest 新增 `$roleAllOk && $roleThrows` 硬门（在**编出来的产物**里跑整表，
  `roles=181/allOk=true unknownThrows=true`）；shotdiff 两示例在册基线全 0.00000 —— 
  对这一族来说"像素一位没变"就是"只改读屏树"的证据。
- 示例真用法：main.php 侧栏 `navigation` + 标题 `heading/aria_level 1/2` + 内容区 `main`；
  sections.php 数据节的列表 `list` / `list_item` / `aria_selected` / `tab_index` / `tab_stop` /
  `key_context` / `aria_active_descendant` / `selectable`。仓库原有的两条覆盖率门
  （"showcase 覆盖 Element 的全部链式方法"、"每个枚举常量的每个取值都被用过或整体遍历"）
  第一时间把我只接了一半的状态抓红了，这两条门是本轮真正的兜网。
- `track_focus` / `content_focus_handle` **不给 PHP 糖**：实参是 `Bridged` 焦点句柄，
  只有 bridge 造得出，从 PHP 传进去只能是假的 —— 造一条假糖就是再造一条哑路。
- 文档：components.md 新增"读屏与键盘"节；upstream-coverage.md 把该族从"没接的整族"移到已接、
  修好那张被插话打断的 markdown 表、`focus`/`accessibility` 两行 ❌→🟡
  （顺带纠正"dialog 焦点陷阱从未成立"那句 —— 上一轮实测它是通的）。
- 门禁：pest 91 passed (465 assertions)、phpstan / parity / test:all(含真跑的 shim) /
  check-c-abi / check-build-inputs / doctor / docs:build 全 rc=0，
  showcase selftest rc=0、hello selftest rc=0、shotdiff check（hello 18 棵 + showcase 13 棵）rc=0。

**修复轮 5（#71 指针/滚轮/键抬起事件族）**：
- 定性纠正：这一族不是"缺 PHP 糖"。materialize 给它们挂的是
  `Listen(cx, &ScriptView::OnScriptMouse*, cbid)`，而未打补丁的 handler 函数体走
  `DispatchMouseButton` / `DispatchScrollWheel` / `DispatchMouseMove` → QuickJS
  （`on_click` 能用是因为它走 `ClickListener`，压根不碰脚本引擎）。
- 新增 `cmd/patch-mouse-view.sh`（弱钩子 + 强符号在 `cpp-src/gpui_shim_events.inc`），
  五个 body 换成 `gpuiEnqueueMouse/Scroll/Move`；已进 `bin/gpui` 的补丁清单单一来源，
  doctor 认它（`verifyPatches()` 缺位即中止构建），逐符号幂等 + 自检"Dispatch* 三路必须已断"。
- 10 个糖：`onMouseDownLeft/Right/Middle` `onMouseUpLeft/Right/Middle` `onMouseDownOut`
  `onMouseMove` `onScrollWheel` `onKeyUp`；value 是 CSV（GpuiEvent 只有一个 value 槽），
  字段顺序写进 docs/guide/events.md 的 1a 节。
- 验收三层，各量各的：Pest 数据表钉 10 个 op 名（37 passed）；showcase selftest 的
  `$mouseOk` 用**真输入**量送达（`down=13 up=13 move=1`，13 颗导航行各一次按下与抬起），
  并用 `downVal=105.00,226.00,0,1,0` 的形状断言**生产者可指认**（只有那条钩子会编这个格式）；
  反向对照（绕过 bin/gpui 的自动补补丁、手工还原 + 删 `.work/gpui.cpp` 与 `gpui.o` 强制重编、
  并断言 amalgam 里钩子名 0 处）→ 真按下那一刻 **rc=139 SIGSEGV**，证明补丁是承重的。
  两次对照失败都是"量到陈旧产物"（bin/gpui 会先补补丁；build.ts 打印 `compile 0, skip 4`），
  已把有效流程写进 findings。
- 明确不做的：剩下 10 个 `on_*`（confirm/dismiss/step/resize/open_change/item_click/
  item_secondary_click/link_click/token_click/token_hover）的 callbackId 存在各部件自己的
  binding 里、触发方在 base 组件，投递路未验证 —— 未量到送达就不发糖，避免又一条 #66 式假接口。
  `token` 是要返回内容的渲染回调，与 `El::list` renderer 同类（#29/#35）。
- 右/中键那四对只在示例里声明、不当门：selftest 的真点击只发左键。
- 顺手把 evSeen 的叶子从 `true` 改成事件 value 本身（`$e['value'] ?? true`），
  这样同一条真输入缝里既能数"到了几条"也能看到"带的是什么值"。

**修复轮 6（#72 样式词表：取数端 + 47 个糖 + 第 2c 条门）**：
- `cmd/upstream-vocab.php` 新增 `styleKeywords()`：解析 `kKeywords[]`（137 条零参关键字）、
  `kFamilies[]`（47 族）、`kOtherParamNames`（12），并分开返回 `all` / `animated` / `missing`。
  两次误读都记进 findings：第一版把第三字段当 arity ⇒ 静默丢掉 7 条（靠独立枚举
  `grep -c "\[\](El"`=137 vs 解析 130 才发现）；第二版把 `fields==0` 读成"什么都不画"
  ⇒ 差点把现有 25 个样式糖全判成死接口。读消费端才定死：`ApplyNullaryStyle` 无条件 apply，
  `fields` 只是 `StyleFieldsOf()` 给 transition/spring 用的动画掩码（137 里仅 7 条非零，全是字号）。
- `cmd/check-upstream-parity.php` 新增 2c 段：`style()` / `paramStyle()` 发的名字必须在
  相应表里在册，另外 `missing` 非零即红（取数端自己有洞就叫）。拼错名双向对照过：
  `truncte` → rc=1、`gapp` → rc=1、还原 → rc=0。
- 47 个零参样式糖（显示模式 / 弹性方向与收缩 / items_ 与 self_ / content_ / overflow /
  aspect / whitespace+ellipsis+line-through / border-dashed / shadow / cursor 四款 /
  字重五档 / 字号七档）。刻意不做组合糖。
- showcase selftest 新增 `$styleSugarOk`（在编出来的产物上调完 47 个，断言 47 条不重复的
  `nullary_style` op）。回归位量过两次：删掉链上一个 `->invisible()` ⇒ `46/ok=false` rc=1；
  更早 `0/ok=false` 那轮是门抓到我 op 种类写错（真名 `nullary_style`）。
- 元素保持 detached ⇒ 两示例在册基线（hello 18 + showcase 13）逐张 `gdfrac=0.00000`，
  这就是"补样式没偷改像素"的证据；hello/showcase selftest 均 rc=0。
- 门禁：pest 102 passed、phpstan / parity（样式 72+27 全在册）/ test:all 含真跑的 shim /
  check-c-abi / check-build-inputs / doctor / docs:build 全 rc=0。

**修复轮 7（#73 部件级事件：接通性查证，未量到）**：
- 加了 5 个糖（`onOpenChange/onConfirm/onDismiss/onStep/onResize`）与另外 5 个
  （`onLinkClick/onItemClick/onItemSecondaryClick/onTokenClick/onTokenHover`，只发 op 不宣称送达）；
  Select/Combobox/DatePicker 三颗探针元素挂了 open_change/confirm/dismiss，
  numberInput 挂 step，resizablePanel 挂 resize。`cbEmit=5/ok=true` 进 $ok 链。
- 新增独立模式 `--probe-events`（`runEventProbe`）：干净前台 + 只干这一件事。
  读数：`posted=20 open=0 confirm=0 dismiss=0 step=0 resize=0`、`click{btn-*}` 证明点击确实落地，
  合成动作派发 `ui::Confirm/ui::Cancel` 都 `Posted=false`。
- 排查换来三条方法学事实（都写进 findings）：探针放在 selftest 中途会点开弹层、
  把"空转 40 帧只画 1 次"的 `$dirtyGate` 弄红（红与被量的事无关）；放到所有门之后又只剩
  1 次投递 = **没有余量的读数**；按 `hitProbe(i)` 逐个点矩形中心会整轮挂死 rc=124
  （命中表含窗口 resize 边，AppKit `_mouseHysteresisCheck:` 等下一个事件不返回）。
- 诚实结论：这一族**在册、发得出、宿主侧未量到送达**，文档 events.md 新增 1b 节按这个说法写，
  并承认本轮探针自己的洞（step 在第 2 节、resize 在第 4 节，那两个 0 是"没测"不是"测到零"）。
- 门禁：pest 102 passed、showcase selftest rc=0、shotdiff showcase 13 棵全绿。

**修复轮 8（#74 动作派发查错表 + dialog 的无输入关闭路）**：
- 根因：`gpui_shim_dispatch_action` 只走 `shell::ShellActionOf()`，而它会**按需登记**并且
  给名字加 `shell::` 前缀；内建动作（`GPUI_ACTION(Confirm, "ui::Confirm")`）是**不带前缀**
  intern 的。于是 `dispatchAction('ui::Cancel')` 拿到的是一个刚创建、无人监听的新 id，
  返回 false——症状像"路不通"，实际查错表（#73 就是这么假阴的）。
  改成两次解析：先 shell 那套（菜单动作行为不变，命中即返回不重复投递），再按原名 `ActionOf`。
- 效果是**可测的功能**：开框 → 焦点陷阱生效 → `dispatchAction('ui::Cancel')` → 框关掉，
  全程不需要键盘。`cancelGate` 进 selftest 的 `$ok` 链（`dispatched=true closed=true`，
  连跑两轮读数一致，不依赖前台状态）。
- 负向对照：把第二次解析换成 `return 0;` 重编 ⇒ `dispatched=false closed=false`、selftest rc=1；
  还原后重编重跑 ⇒ rc=0。过程中被一次打断的还原命令提醒了一件事：**文件已经是对的、
  产物还是坏的那版**——所以"grep 到了"不等于"跑的是这份"，还原后必须重新 build 再 measure。
- #62 因此更精确：dialog 的"取消"这件事本身在宿主里是通的（同一条 `OnAction(Cancel)`），
  只剩"真按键送达"那一环，与 #56 同一件事。
- 文档同步：`App::dispatchAction` 的注释现在写明两类名字都认（含 `ui::Confirm/Cancel/SelectUp`
  这组内建动作与"返回 false 意味着这一帧没人接"）；events.md 1b 节把"动作派发连名字都没解析上"
  那句**过期结论**改成本轮量到的事实。

**电脑操控量 #56（这一格仍未答，但前提查清了）**：
- 用 Qoder computer use（AX 层，不是自家 CGEvent 注入路）对 `showcase --trace` 的窗口做了一轮：
  `click(elementIndex)` **能进 PHP**（trace 里 `click id=nav-2`，HUD 点击 1，画面切节），
  但 `pressKey('g'/'p')` 与 `typeText('gp7')` 全无私迹（HUD 一直"按键 0 · 输入 0"，
  `key_down` 计数 0，`in-key` 的 `keyDown=—` 没变）。
- 原因不在我们的代码：应用自己把那行前提画在界面上——"当前不是前台应用…键盘也没有
  firstResponder"，HUD 焦点位是"非前台"。AX 元素点击能产生 click 事件却**不改变系统激活归属**；
  补点标题栏那一步被 SDK 连拒三次（要求截图与窗口几何一致）。
- 所以 #56/#62 的键盘那一半只剩真人手按这一条路。流程教训进 findings：用电脑操控量输入类事件，
  第一步先读应用自己打的 active/key 状态，前提不成立就别按键盘——否则拿到的"不通"是假阴
  （与 #74 查错表那次同类）。
- 收尾：rc=124 那次的 selftest 留下一个仍带窗口的孤儿进程（pid 74337，跑了 45 分钟），已 `kill`；
  看门狗掐的是 perl 包装而不是 GUI 子进程，这条也记进 findings。

## 修复轮 9：showcase 按功能域拆出第一支独立示例 `input`（Phase 15-A，2026-10-14）

**拆之前先量成本**（粒度取舍取决于它，不靠估）：`php vendor/bin/tpc.php examples/showcase/project.macos.gen.yml --nano`
= **41 秒**、rc=0。之前"每支示例 1.5–2 分钟"是把整条 `bin/gpui build` 算进去了，而那四步是共用的——
但共用那四步**每次 build 都重跑**（`rsync --delete` 清掉了 `.work/` 与 `out/`），所以给 `bin/gpui build`
加了 `--no-native`（只跑 tpc，并先验 `gpui.o` + `libgpui_shim.a` 在不在，不在就拒绝）。

**搬家（单一真源，不复制）**：`examples/_shared/state.php`（状态表，类名 `ShowcaseState`→`ExampleState`、
函数 `showcaseState`→`exampleState`，源码里 135+1+10+1 处一次改齐并 grep -c 验过落点）；
`examples/_shared/features/inputs.php`（`sectionInputs` + `wireInputs` + `inputsTrees`）。
showcase 的 yml 把这两份列进 `sources`，`wireShowcase` 里那 6 个 `in-*` handler 换成一句 `wireInputs($app)`。

**新建 `examples/input`**：自带 `--sections` / `--shot` / `--selftest`，自检是**边跑边打**的
（`step()` 一步一行）。这条设计第一次跑就兑现了：140 秒内报出三条画廊报不出的结论——
`retainedRoundtrip` FAIL（`in-otp=''/set=false`）、`changeRoute` FAIL（`vol:30→1`）、
`keyDownRoute` FAIL（`fired=false`）。三条分别对应上面 findings 的 OTP / 合成缝值 / 合成缝类型。

**顺手修掉一个真 bug**：OTP 的取值口。`CreateRetainedOtp` 填 `entry->otp`（`OtpState`），
而 `gpui_shim_input_value` / `gpui_shim_set_input_value` 都写死 `if (!e || !e->input) return 0;`
⇒ OTP 的 `setInput` 恒 false、`text` 恒空，而 `registeredError()` 空、`componentFailure` 0，三层门禁全不报。
改成按 `e->kind == RetainedKind::Otp` 走 `gpui::OtpValue/OtpSetValue`。

**门禁泛化**：`tests/ShowcaseTest.php` 的扫描集从"showcase 两份文件"改成 `examples/**/*.php`
（`exampleSources()` 递归遍历，排除 `/build/`；只 require `_shared` + 画廊声明，不 require 各示例
`main.php`——nano 入口约定每支都定义 `main()`，一起加载就 redeclare fatal），
并加第 10 条测试"拆出去的域不会从画廊的编译清单里悄悄消失"（画廊节表 builder 必须定义在画廊 yml
列出的某份源里 + `_shared/features/*.php` 每份都被画廊收录）。
**反向锚点已做**：把 `- ../_shared/features/inputs.php` 从画廊 yml 删掉 → 该测试立刻红
（`Failed at tests/ShowcaseTest.php:443`），还原后绿。

**改完 shim 之后的完整链（351 秒，含被 rsync 清掉后重编的 amalgam）**：
```
INPUT SELFTEST
  ok   registeredError — 空
  ok   componentFailure — count=0 last=
  ok   inputBounds — n=4 有效框=4 越界读数零=true
  ok   retainedRoundtrip — 四颗各自回环一致          ← OTP 修好了（改前这行是 in-otp=''/set=false）
  ok   changeRoute — fired=true vol:30→1             ← 合成 change 的 value 就是 true，不是控件值
  ok   realTyping — index→keyPressed=0:true 1:true 2:true 3:false 打进字的索引=0 name='甲g'
  ok   valuePull — elementValue(in-name)='甲g'
  ok   keyDownRoute — key_down 落点索引=1 key='g' focus=-1004
INPUT SELFTEST OK                                    rc=0
```
读数里两条值得记下：`inputProbe()['n']=4` 而本域挂 retained 的有 6 颗，**探针说不出少的是哪两颗**
（只给矩形不给 id），所以这条门只声明"表里每颗都有非零矩形且互不重叠"；索引 3 的 `realKey` 回 false
（那一颗点了但不吃键），因为门只要求"某个索引打进字"，它没被伪装成"每颗都打得进"。

**其余门禁**：`shotdiff check showcase` 13 张 `gdfrac=0.00000` ⇒ 搬家没改像素；
`tests/baseline/input/tree1.png` 三次截图 md5 全同（`4dd48935e02fae6b44966662e30f6dd4`）；
`composer test:all`（Pest 103 + PHPStan 0 + parity + SHIM CPP TESTS OK）、`check-c-abi`、`doctor`、
`docs:build` 全绿。`check-build-inputs` 只剩"新文件未跟踪"这 5 条，提交即消。

## 修复轮 10：第二支独立示例 `overlay`（Phase 15-B，2026-10-14）

§8 的 builder + 接线搬到 `examples/_shared/features/overlays.php`，画廊 yml 收录它、
`wireShowcase` 换成一句 `wireOverlays($app)`；新支 `examples/overlay` 九条门全部确定性。
首轮 8/9 绿，那条 FAIL 教会我一件事（见下），改完 9/9。

```
OVERLAY SELFTEST
  ok   registeredError — 空
  ok   componentFailure — count=0 last=
  ok   focusTrap — opened=1 dialogCount=1 trap=993756471 focusedId=993756471
  ok   cancelClosesDialog — dispatched=true dialogCount=0
  ok   closeDialog — opened=1 closed=true dialogCount=0
  ok   sheetPerAnchor — 仍在场的 sheet=0 top=1/true bottom=1/true left=1/true right=1/true
  ok   notifyPerType — 在场=4 类型数=4
  ok   clearNotifications — 立刻读=4 泵 40 帧后=0        ← 首轮这里报的是"在场=12/8"
  ok   placements — 在场=8/8
  ok   teardownClean — err=空
OVERLAY SELFTEST OK                                       rc=0（--no-native，37 秒）
```

**#62 结案**（两支各跑一次 `--escape`，都 rc=0）：
`ESCAPE-FIRST opened=1 trapAfterOpen=0 trapAfterFrames=993756471 closed=true`（overlay）
`ESCAPE-FIRST opened=1 trapAfterOpen=0 trapAfterFrames=1010534090 closed=true`（showcase）
⇒ 焦点陷阱是"要几帧才生效"，真 Escape 能把框关掉。旧那条"Escape 不关"测于 #54（bundle 不自提升
前台）修好之前，之后没人重跑——而重跑的成本是画廊 3 分钟 / 隔离 40 秒。
任务表里 #62 与 #56 都已按这次读数订正：#56 只剩**真人 HID 那一按**。

**我自己写错的一条预期**：`clearNotifications()` 之后立刻读 `notificationCount()` 仍是 4，
泵 40 帧才归零；首轮的"在场=12/8"也不是 bug（12 = 4 条 5 秒超时的还没退 + 8 条新推）。
同一个错法两处：**把有动画的状态当同步返回值读**。现在门同时留两个读数、只断言泵过帧那个。

**其余门禁**：`SHOWCASE SELFTEST OK`（rc=0，搬家后）· `shotdiff check showcase` 13 张 gdfrac=0
· `tests/baseline/overlay/tree1.png` 三次截图 md5 相同（`f8c975d1…`）且 `check overlay` OK
· Pest 103 / PHPStan 0 / parity / docs:build 绿。

## 修复轮 11：第三支独立示例 `style`（Phase 15-C 起头，2026-10-14）

§12 的 builder + 那四条 `st-size-*` 接线搬到 `examples/_shared/features/styling.php`，
画廊 yml 收录；**同时把"47 个零参样式糖在产物里逐个调一遍"那条门从画廊 selftest 里连根搬走**
（`$styleSugarOk`、报告里那一段、`$ok` 链上的那一项一起删），新支 `examples/style` 接手它，
外加两条它本来没有的门：`iconPathGuard`（`El::icon` 的形状守卫，坏路径抛 + 正例也建得出，
两头都在）、`sizesEnum`（`El::SIZES` 遍历出来的 `registered_method('size')` 条数 = 4）。

```
STYLE SELFTEST
  ok   registeredError — 空
  ok   componentFailure — count=0 last=
  ok   nullaryStyles — 发出=47/47 去重后=47
  ok   iconPathGuard — 坏路径抛=true 正例建成=true
  ok   sizesEnum — size op 条数=4/4
STYLE SELFTEST OK          rc=0（--no-native 32 秒）
```

`SHOWCASE SELFTEST OK`（rc=0，删门之后）· `shotdiff check showcase` 13 张 gdfrac=0 ·
`tests/baseline/style/tree1.png` 三次截图 md5 相同（`ba427963…`）· Pest 103 / PHPStan 0 绿。

## 修复轮 12：`table` 与 `catalog` 两支（Phase 15-C 批量，2026-10-14）

一次搬两域（§5 data / §6 catalog），共用一轮画廊重编与一次像素核对。
**这一轮第一次出现"搬完立刻红"**：`pest` 报 2 条失败——
`->onLinkClick()` 等五个糖与 `specIndex()` 在画廊里唯一的调用点就是那两条被搬走的门，
所以覆盖率门当场指出"这六个 API 现在没人展示了"。新支把六处都接上之后回到 103 全绿。
这条门是活的，不是摆设。

```
TABLE SELFTEST            CATALOG SELFTEST
  ok registeredError        ok registeredError
  ok componentFailure        ok componentFailure
  ok specIndex 0,1,2/-1      ok componentCallbackOps 发出=5 去重后=5
  ok tableCountOps           ok dropdownItemOps item=2/2
  ok indexGuard              ok emptyIdIsLoud 原因=InfoAlert: Alert id must not be empty
```

- `indexGuard` / `tableCountOps` / `dropdownItemOps` / `emptyIdIsLoud` 是这一轮**新加**的门，
  画廊里没有对应物：前三条数 detached 元素发出来的 op 名与条数，
  最后一条是故意的失败路径（空 id 的 registered 组件必须把原因回给 PHP，
  而不是只在 debug_tree 里画一句占位文本），所以它排在这支自检的最末尾——它会弄脏 `registeredError()`。
- `docs/guide/events.md` 里那句"还没接的十个…没量到送达之前不给糖"与代码不符（糖 2026-10-13
  就加了），订正成"糖有、送达未量"，并把这条门的位置指到 `selftest catalog`。
- `upstream-coverage.md` / `components.md` 里"showcase 的 --selftest 用它盯这件事"两句改指 `selftest table`。

验证：showcase/table/catalog 三支 selftest 全 rc=0 · `shotdiff check showcase` 13 张 gdfrac=0 ·
两支新基线各三次截图 md5 相同 · Pest 103 · PHPStan 0 · parity · shim cpp · docs:build。

## 修复轮 13：`table` / `catalog` / `choices` 三支（Phase 15-C 批量，2026-10-14）

一次搬三域（§5 已在轮 12、本轮 §3 choices + §6 收尾），共用一轮画廊重编与一次像素核对。
现在 `examples/` 下有 **六支按域独立示例**：input / overlay / style / table / catalog / choices，
CI 的 `Domain examples` 步骤按 `examples/*/project.macos.yml` 自动发现，加一支不改 CI。

`choices` 这一支是本轮最有价值的一支：它把 #73 从"事件没送达"收窄成"触发器根本不可点"
（三颗 catalog 触发器在 paint.hits 里没有矩形；点遍 16 条矩形无一让弹层进入持续重画）。
过程里它先 rc=6 崩在我自己的几何假设上，而报告正好停在 `triggerHits` 那一行——
这就是把门拆小的全部目的。三条 #73 相关的线现在标着 `(读数)` 不参与 rc，
量到送达之后再升级成硬门。

验证：`CHOICES SELFTEST OK` rc=0 · `SHOWCASE SELFTEST OK`（§3 搬家 + 九条新 handler 之后） ·
`shotdiff check showcase` 13 张 gdfrac=0 · `tests/baseline/choices/tree1.png` 三次截图 md5 相同 ·
Pest 103 · PHPStan 0 · parity · shim cpp · doctor · docs:build。

## 修复轮 14：`text` 与 `layout` 两支（Phase 15-C，2026-10-14）

§1 与 §4 搬进 `examples/_shared/features/{primitives,layout}.php`（`pr-hover` 的 hover handler
一起搬过去，画廊与 `examples/text` 共用同一条线）。这两域**没有门可搬**（画廊里那两条
`$roleAllOk`/`$mouseOk` 不属于它们），所以新支写的是"通道门"——
名字对而通道错正是 #66 的形状，而那种错在五层门禁里全绿：

```
TEXT SELFTEST                                    LAYOUT SELFTEST
  ok registeredError / componentFailure            ok registeredError / componentFailure
  ok nullaryChannel  hidden/truncate/font_bold/cursor_pointer   ok nullaryChannel flex/flex_col/flex_row/flex_1/flex_none
  ok behaviorChannel overflow_y_scroll/disabled    ok paramChannel flex_grow/flex_shrink/gap_x/gap_y/p/px
  ok urlWhitelistReject javascript:=false          ok behaviorChannel overflow_y/x/scroll 三个
  ok hoverProbeHasRect 命中表 5 条里 4 条像样       ok sizeIsMethodOp size=30%（method 而非 registered_method）
```

`flex_1`（关键字）与 `flex_grow`（带参族）是同一类概念的两条通道，这条门把两者的区别钉住了；
`size` 在 kind 节点上必须是 method——§12 那句"rm('size') 打在 kind 节点上是没用的"从此有可执行形式。

现在 `examples/` 下有 **八支按域独立示例**。验证：text/layout/showcase 三支 selftest 全 rc=0 ·
`shotdiff check showcase` 13 张 gdfrac=0 · 两支新基线各三次截图 md5 相同 ·
Pest 103 · PHPStan · parity · shim cpp · docs:build · README/cli/testing 的示例清单同步。

## 修复轮 15：`mac` 与 `system` 两支 + 两条被拆出来的真 bug（2026-10-14）

§9+§10 合成一支 `examples/system`（同一张 Capabilities 授权表管着它们，双向门必须同进程按顺序量），
§13 拆成 `examples/mac`。这两支的搬家改用一支一次性 python 脚本按行范围搬（150+70 行手写必错），
搬完立刻 `php -l` 三份文件 + `pest`——脚本失败时（第一次 `'\n'.join` 漏了）没有写坏任何文件。

**十门全绿的 system**：`fsTwoWay`（gpui 写的 PHP 读得到、PHP 写的二进制 gpui 原样读回、
列目录 2 条不截断、越界读 NULL **且留下 62 字节的原因**、递归删后不存在）、
`processTwoWay`、`storageTwoWay`（未授权 set=false → 授权后成环）、`inflate`（两种容器 + 坏流 null）、
`httpFailurePath`（127.0.0.1:1，排队 true、回来 ok=false，不碰外网）、`watchDir`、`sysInfo`…

**拆出来照到的两条真 bug**：
1. §10 那个 sysInfo handler 用的是 **C 结构体字段名**（`memUsedBytes`/`memTotalBytes`），
   而 bridge 给的 PHP 键是 `memUsed`/`memTotal` ⇒ `(int) null = 0`，画廊那一行一直是 `mem 0 / 0`。
   修后 `sysInfo — mem=11254202368/17179869184`。（hello 的 selftest 与 tests/SystemTest.php
   一直用的是正确键，所以只有这一处 handler 错——正是"同一条链在别处有门、在这里没门"的样子。）
2. 两支的"两棵树"拍出**同一张图**（49984 = 49984）：`systemTree()` 拿树号 2 去比节号 10。
   是"两次基线字节数完全相同"这个异常暴露的，不是眼睛看出来的。修后 tree1≠tree2 且各自三次截图 md5 稳定。

**mac 那支还交了一次 nano 编译期学费**：`foreach ($ids as $mod => $id)` 之后再把 int 赋给 `$id`
⇒ `Cannot assign value to variable $id of type php::Int with type php::Var`，rc=255 且**一个字都没跑**，
`php -l` 完全不报。已按"一个作用域一个名字一种类型"改掉（$modName/$newId/$dropId），
并把这条教训写进注释。门：`trayLifecycle 0→1→0`、`hotkeyEveryMod 4 个组合 0→4→0`、
`hotkeyDispatch`（fireHotkeyForTest 打进 handler，命中 1）、`appMenus 2 条 / rows 含 mac-about`。

验证：MAC / SYSTEM / SHOWCASE 三支 rc=0 · `shotdiff check showcase` 13 张 gdfrac=0 ·
`check system` 两张 gdfrac=0 · mac 基线三次 md5 相同 · Pest 103 · PHPStan · parity · docs:build。

## 修复轮 16：`chrome` 与 `embedded` —— 13 节全部拆完（Phase 15-C 收口，2026-10-14）

§7 与 §11 用同一支 python 脚本搬家。**这支脚本第一次跑把 main.php 弄坏了**：
我按 (c11,c13) → (lo,hi) → (c7,c8) 的顺序做替换，而这三段在文件里不是从后往前的，
第一段替换之后后两段的行号全漂，于是删错区间（`Unmatched ')' on line 633`）。
两份文件都已提交，`git checkout --` 直接还原，改完排序（`sorted(..., reverse=True)`）重跑一次成功。
教训：**按行号批量编辑必须从文件末尾往前**，而"改完立刻 `php -l`"是唯一的看门人。

`CHROME SELFTEST` 首轮两条 FAIL，都是我在断言里夹带了未验证的假设：
1. `namedSlots — children=1`：`El::sidebar($id, $menus)` 的第二个实参本来就是往 children 放的，
   我拿带 menus 的那颗去断言"children 为空"是断言过头。改成传空 menus 再断言 0。
2. `menuActionToPhp — dispatched=true 而 lastBtn 不动`：**菜单动作回来的事件类型是 `action`，不是 `click`**。
   搬过来的 §7 接线只注册了 click（那是 DropdownMenu.item 那条路），画廊里这条一直是靠
   `onAny('action')` 通配记录器才被看见的——我第一轮拿 click handler 等它，自然一动不动。
   现在示例内自挂一颗 action handler，读数 `回来的 type=action lastBtn:—→menu:cr-m-new`。

`EMBEDDED SELFTEST` 一次过 6/6：`webViewAttached — ok=true box=356x178`（nested-view 真挂上了，
"节点在树上但表面没挂"那类假绿只有盒子能照）、`probeUnknownId` 回 `ok=false` 而不是零盒子
（否则 2) 里的零分不清是哪种）。

## 拆分总账（Phase 15）

`examples/` 现在是：`hello` + `showcase`（画廊：壳/导航/节表 + 跨域门）+ **12 支按域独立示例**
（input overlay style table catalog choices text layout system mac chrome embedded），
13 节 → 12 域（§9+§10 合成 system）。`examples/showcase/src/sections.php` 从 628 行降到 82 行，
只剩节表与指路注释；`main.php` 从 1833 降到 1468。全部 UI 源在 `examples/_shared/features/`，
一份文件同时被画廊与它自己的示例编译，**没有第二份实现**。
每支都有：自己的 yml、`--sections`/`--shot`/`--selftest`（边跑边打）、像素基线（三次截图 md5 稳定）、
CI 自动发现（`Domain examples` 步骤按 yml 循环）。
拆的过程里另修掉 3 条真 bug + 结 1 条旧案（OTP 取值口 / sysInfo 键名 / 两棵树同一张图 / #62 Escape 结案），
并把 #73 收窄成"三颗 catalog 触发器在 paint.hits 里没有矩形"。

## 拆分收口：`cmd/all-examples.sh` + 全量跑总账（2026-10-14）

`bash cmd/all-examples.sh`（composer 别名 `examples:all`）= 逐支 `build --no-native` + `--selftest`，
`shotdiff` / `both` / `first-native`（改了 cpp-src/ 时第一支走完整链）各一个模式。
清单不在脚本里维护，就是 `examples/*/project.macos.yml`——新拆一支两边都不用改。

**总账（一次跑完）**：14 支 selftest 全 rc=0（hello、showcase + 12 支域示例）·
43 棵像素树全绿（hello 18 + showcase 13 + system 2 + 其余各 1）· **全量用时 86 秒**。
对照拆分前：验一个域也要跑整条画廊一轮（≥140 秒）外加"崩了不告诉你崩在哪"。

## 修复轮 17：`cmd/all-examples.sh` 与一次自我破坏（2026-10-14）

脚本自己跑通了全量：**14 支 selftest rc=0、ALL EXAMPLES OK**；`shotdiff` 模式 43 棵树全绿。
`composer.json` 加了对应别名 `examples:all`（脚本=14 条，name 完好）。

这一轮我把 `composer.json` **写坏过一次**：python 里我序列化的是 `d['scripts']` 那张子表而不是
整个文档，于是清单文件被自己的 scripts 覆盖（diffstat 18+/76−）。它安静了很久，直到
`composer test:all` 报 "There are no commands defined in the test namespace" 才露出来——
而我上一条命令打印的 `rc=0` 是 **grep 的**退出码，不是 composer 的（`cmd | grep; echo rc=$?` 的坑）。
两条教训都记进了 findings 与用户记忆：程序化改写已跟踪文件之后**立刻**读 `git diff --stat`；
报告的退出码必须来自被测那一步。修法本身是 `git checkout --` 一行，因为它在基线里。

## 修复轮 18：画廊自检去重（Phase 15 那条"未做的收口"，2026-10-14）

删掉画廊 selftest 里与 `examples/overlay` 重复的三条：`$trapOk`（焦点陷阱==焦点）、
`$cancelClosed`（ui::Cancel 关框）、以及两格 Escape 读数（withControl / plain 那一整段，
含 `--invalidate` 与"点一下再按"的补救尝试）。共 73 行，`main.php` 1468 → 1395。

留着的：画廊自己的 `--escape` 模式（`runEscapeProbe`）——它量的是"整套壳在场时"的形状，
与隔离窗口那次是两条不同证据，#62 结案时两支都跑过。

删它们的理由不只是重复：上一轮 `#73` 的部件级事件探针之所以被挪出 selftest，
就是因为 `$ok` 链尾挤不出投递余量。现在腾出来了。

验证：`bash cmd/all-examples.sh both` → 14 支 selftest 全 rc=0、43 棵像素树全绿、88 秒。

## 修复轮 19：focusElement 落地 + 一次"报告一个字没打"的账算清了（2026-10-14）

先按规矩走完整链（改了 `cpp-src/` ⇒ 第一支必须 full chain）：`bash cmd/all-examples.sh first-native`
→ **14 支 selftest 全 rc=0**；`shotdiff` → 全绿。八道门（pest 104 / phpstan / parity /
test-shim / check-c-abi / check-build-inputs / doctor / selftest 全量）都过。

`focusElement` 的读数是 `ch-select→-1001 focusedId=-1001 ch-date→-1005 ch-combo→-1003 不存在的 id→0`，
`dispatchAction('ui::SelectDown')` 返回 true 而 `open_change` 仍是 0。
上一轮我给每条探针加"进入"行想让崩溃自己报位置——这一轮证明**它挡不住硬信号**：
stdout 重定向到文件是全缓冲的，SIGSEGV 时缓冲区整个丢，`lines=0`。
位置最后是从 `DiagnosticReports/*.ips` + `nm`/`objdump` 里拿到的（过程记在 findings 轮 19）。

顺带把两处文档里已经被推翻的话改了：`events.md` 那句"自动缝没法把焦点放进 Select"，
和 `src/Element.php`/`events.md` 那句"这条路不需要新补丁"。

## 修复轮 20：第 14 支补丁把 binding 那批回调从 QuickJS 挪进宿主队列（2026-10-14）

查清机制后一支补丁解决两件事：**崩**（`CallbackArena::Get()` 扫表在 impl 不健康时 fault 在 0x1）
和**这一族事件从来没到过 PHP**（Box2 id 不在 runtime 表里，扫不到就静默丢）。

`cmd/patch-select-view.sh` 换掉 view.cpp 里 7 处 binding 派发，`open_change` 复用已有的
`gpuiEnqueueChange` 所以**载荷第一次跟着到 PHP**；新增 `gpuiEnqueueSignal`（无载荷）与
`gpuiEnqueueString`（step 的 increment/decrement）。

实测：`openChangeDelivered — focus=-1001 SelectDown=true open_change 到 PHP=true 值=true`，
同一支连跑 **6/6 rc=0**（修前 6 次里崩 4 次）。全量 **14 支 rc=0 · 44 棵像素树全绿**
（像素树数从"43"订正成 44：按 `tests/baseline/**` 独立数过一遍，之前那个数是手数的）。
`doctor` 正反两向（清单→落位、`cmd/patch-*.sh`→清单）都认这支；
`check-build-inputs` 一开始红在"清单点名但未跟踪：cmd/patch-select-view.sh"——这正是它该有的行为。

没做的事写进计划了：`confirm`/`dismiss` 缺 `open` 糖、`resize`/`item_secondary_click` 同形状未接、
catalog 触发器没有 `paint.hits` 矩形。

## 修复轮 21：`El::open()` —— 把"通了也发不出"的那两个名字补全（2026-10-14）

上一轮的补丁只解决投递。`confirm`/`dismiss` 仍然必然是 0，原因在更上游一格：
`SelectActionOf(id, open, disabled)` 按 binding 里的 `open` 位解释动作，
`open=false` 时 Confirm 读成"再开一次"、Cancel 读成"不归我管"，于是那两个 handler 永远轮不到。
而那个位来自 `behavior.open`（`materialize.cpp:339-341` 认 `open` 这个 bool op），
我们 PHP 侧从来没发过它。

补 `Element::open(bool $v = true)`，choices 域加 `selectOpen` 状态位（默认 false，
所以画廊一棵像素没变——shotdiff 44 棵树证的就是这个），自检渲染第二棵声明成开着：

```
  ok   confirmDismissDelivered — open=true 那棵 focus=-1001 Confirm=true confirm 到 PHP=true dismiss 到 PHP=true open_change 收尾=false
```

这条是本轮唯一**当场做过负向对照**的门：只把 `->open(...)` 摘掉、补丁留在原地，
同一条立刻变 `confirm 到 PHP=false dismiss 到 PHP=false open_change 收尾=true` 且 rc=1，
`收尾=true` 正是"Confirm 被读成再开一次"的形状。

文档同步：`events.md` 的送达名数从 14 订正成 **17**（在册仍是 24，两个数别再混），
`upstream-coverage.md` 的 event 行、`components.md` 的糖串、`src/Element.php` 那段族注释。
门禁：pest 104 · phpstan 0 · parity OK · 全量 14 支 selftest rc=0 · 44 棵像素树全绿。

## 修复轮 22：去量 `step`，量到的却是一格新缺陷（2026-10-14）

上一轮留下的 `gpuiEnqueueString`（step 的 increment/decrement）一直没测过——那正是 #66
"糖发了、送达没量"的形状，所以给 `in-num` 补了 `step` handler，在 `examples/input` 里
按 `hitProbe` 逐条真点击去找那两颗 ± 按钮。

结果不是投递问题：命中表 10 条里三条是 528x20 的输入框，其余全是 0x0（8/9 还标着可点），
`tests/baseline/input/tree1.png` 亲眼看也只有 `42` 一行字——**那两颗按钮既不落字也没有矩形**，
而上游侧它们确实在树里（`NumberStepButton` 造 Button 挂 `OnClick(step)`，
`NumberInput::Compose` 无条件做成 FlexRow 的子元素）。

所以 #73 那一格不是 catalog 专属，而是"部件 compose 出来的子元素拿不到矩形"这一族，
开了 #79 单独跟。`stepDelivered` 因此**只报数不判红**：判红的会是"点不到"而不是"没接通"，
那是拿别人的失败当自己的红灯。

门禁：pest 104 · phpstan 0 · 全量 14 支 selftest rc=0 · 44 棵像素树全绿。

## 修复轮 23：#79 结案——± 是装饰槽，不是缺陷；顺手给 shotdiff 加了"别量旧产物"的守卫

顺着轮 22 那条读数往下查，`Button::New(cx, id, …)` 的第二个参数是 **id 不是标签**，
`NumberStepButton` 的样式与内容一律从 `decrement_button`/`increment_button` 两个装饰槽取
（`component_registry.cpp:IsRegisteredCommonSlot` 在册的公共槽就那十个名字）。
我们的示例从来没填过 ⇒ 一颗空按钮，0x0、不落字。填上 `－`/`＋` 之后：

```
  ok   stepDelivered — 命中表=10 条 [...] 落点索引=4 lastStep='decrement' elementValue(in-num)='decrement'
```

那条命中从 `16,319x0x0` 变成 `16,311x15x16`，`step` 的字符串载荷第一次量到 ⇒ 升级成硬门。
像素亲眼看：`－ 42 … ＋`（`NumberInput::Compose` 本来就是左右各一颗）。

**同一把钥匙也开了 #73**：shell 层 `Select::New` 只返回一个 `role=combobox` 的裸 `Div`，
trigger 与弹层归宿主组合，而上游自己的 `js_story` 里 Select/Combobox 两条 story 至今标着
`availability: "pending"`。所以"点不开弹层"不是我们漏了什么，是那一层上游还没做——
这一族的事件验收改走 `focusElement` + `dispatchAction`，已经量到。#73/#79 双双结案。

**这一轮最值得记的是那次假绿**：`shotdiff` 不构建，我改完共享 feature 源直接
`shotdiff record showcase`，它报"13 棵树的字节数与基线完全相同"——那句话看起来正好像
"这次改动不影响像素"，而真实情况是 showcase 的二进制还是上一次构建的。
`git status tests/baseline/` 只有 input 一张变了、showcase 一张没变，这个不一致就是露馅的地方。
重编后再录，tree2 才从 101078 变 101129。守卫已加进 `cmd/shotdiff.php`：
二进制比 yml `sources:` 里任何一份输入旧 ⇒ rc=2 拒绝出数（实测摘下来能挡住、重编后放行）。

送达名 17 → **18**。门禁：pest 104 · phpstan 0 · build-inputs OK ·
全量 14 支 selftest rc=0 · 44 棵像素树全绿。
