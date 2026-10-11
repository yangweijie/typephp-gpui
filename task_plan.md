# Task Plan: TypePHP GPUI 移植

## Goal
用 TypePHP (AOT) 参考 gpui-kit-cpp（本地源码 `/Volumes/data/git/c/gpui-kit-cpp`）重新实现一套 PHP 原生桌面 GUI 框架，让 PHP 开发者能用声明式 API 构建跨平台 GPU 加速桌面应用。

## Current Phase
Phase 4/5/6 主体完成（渲染 bug 修复、定时器、Slot+组件、Markdown/Select/DatePicker、--shot/--selftest、gpui CLI+文档）。
剩余高风险/大工作量项：Stage 5 Dialog（窗口根换 ShellRoot）、Stage 6 List 虚拟化（scroll→elementId 映射）、Stage 7 Dock。

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
- [ ] --shot 视觉回归基线比对（imgdiff 自动 diff）。
- **Status:** in_progress（核心验收完成，自动 diff 基线待办）

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
- [ ] **C++ 侧测试**：shim/bridge（arena 字符串生命周期、`kindFromString`、`lookupCtor`、`toComponentArg` schema、回调路由）**零自动化测试**——bug #18 类回归无守卫。加独立 c++ 测试目标（或直接测 libgpui_shim.a 的纯函数）。
- [ ] **--shot 基线 diff**：现在靠人眼 Read PNG。加参考图 + 像素/结构 diff 自动判定（plan 早已列为待办）。
- [ ] **CI**：无 `.github`。跑 pest + 语法校验 + bootstrap 构建 + selftest + shot diff。
- [ ] **静态分析/风格**：PHP 无 phpstan/psalm/php-cs-fixer；C++ 无 clang-format/clang-tidy。

**P2 — 覆盖面/DX/文档**
- [ ] **MenuBar**：需 catalog window-opener 装的 root application 才能 `AppEffects`（现直开 ShellRoot 窗口不满足）。接 `ComponentWindowOpener`。
- [ ] **A 类 VirtualList/Dock**：仍缺（依赖"回调进脚本渲染"，与 List A2 同源）。
- [ ] **kind 覆盖**：`kindFromString` 认 ~59 个，仍有 ComponentKind 未映射/未出工厂；富 catalog 组件（Chart/Carousel/Tabs/Tooltip…）按需接入。
- [ ] **rm()/cb() 抽象泄漏**：导出名、枚举字面量、实参顺序全靠用户照抄 descriptor，PHP 侧无校验。考虑薄类型层（枚举常量、参数校验）。
- [ ] **文档**：用 **VitePress** 搭文档站（用户 2026-10-11 定）——架构图、构建/测试/自检、组件 API 参考、示例、CONTRIBUTING、防火墙架构 ADR。

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

## Errors Encountered
| Error | Attempt | Resolution |
|-------|---------|------------|
|       |         |            |

## Notes
- gpui-kit-cpp 的 component_shell 层已经实现了 QuickJS 脚本绑定（ScriptView/ShellRuntime/RenderSnapshot/SpecNode），这是最接近"用脚本语言描述 UI"的先例。TypePHP 桥接可以参考 SpecNode/ComponentKind 的设计，把 PHP 数组翻译成 SpecNode 再 ShellMaterialize 成 El 树。
- 但更直接的路径是绕过 shell 层，直接在 PHP 侧构造 El 描述（kind + style + children + listener），C++ 侧 materialize。这样不需要嵌入 QuickJS。
- typephp-qt 的 WidgetTree 数组→C++ QWidget diff 模式是经过验证的，可以类比：GpuiElement 数组→C++ El 树重建（gpui 每帧重建 El 树，不需要 diff）。
