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

