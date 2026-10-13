# Findings & Decisions

## Requirements
- 用 TypePHP AOT 编译器，参考 gpui-kit-cpp（本地 `/Volumes/data/git/c/gpui-kit-cpp`）重新实现 GUI 框架
- 项目目录：`/Volumes/data/git/php/typephp-gpui`（当前为空）
- 目标：PHP 开发者用声明式 API 构建跨平台 GPU 加速桌面应用
- 使用 context7 MCP 查询了 GPUI 文档（Rust 原版），gpui-kit-cpp 本身不在 context7 库中

## Research Findings

### gpui-kit-cpp 架构概览
- **703 个源文件**，src/ 下分 14 个子目录
- **核心运行时** `src/gpui/gpui.h`（7737 行）：定义 App、Window、Ctx、El（元素）、Style、EntityId、Listener、InputState、SliderState 等全部类型；每帧重建 El 树（frame-rebuilt element tree）
- **基础层** `src/base.h`（1329 行）：Str（char*+len）、Arena（arena allocator）、Vec<T>、Func0/Func1（函数指针+userData）、Mutex、Panic 等 POD 基础设施
- **布局引擎** `src/taffy/`：Taffy（Rust flexbox/grid/block 布局库）的 C++ port
- **绘制后端** `src/gpui/paint.h` + `paint_mac.cpp`/`paint_linux.cpp`/`paint_win.cpp`/`paint_wasm.cpp`：macOS Core Graphics、Linux cairo+Pango、Windows Direct2D/D3D、WASM canvas
- **组件库** `src/ui/`（themed gpui-kit facade）：Button、Input、Textarea、Table、List、Tree、Dialog、Popover、Tooltip、Scroll、Sidebar、Dock 等 80+ 组件头文件
- **Base 无主题层** `src/base/`：accordion、avatar、checkbox、combobox、data_table、dialog、input_core、motion、popup、select、slider、switch、tabs、text、theme_tokens、tree、virtual_list 等无主题基础组件
- **Shell 脚本绑定层** `src/shell/`：QuickJS runtime（`src/quickjs/`）、SpecNode/ComponentKind/RenderSnapshot/ShellMaterialize——把 JS 描述翻译成 El 树；ScriptView 持有 Entity<ScriptView>，render 时调 JS 产出 snapshot 再 materialize
- **component_shell** `src/component_shell/`：gpui-component catalog for shell runtime，注册所有组件到 ComponentRegistry，让 JS 脚本 import 'gpui-component' 使用
- **入口** `GpuiMain(int argc, char** argv)` → `AppNew()` → `component::Init(app)` → `ThemeSet(app, mode)` → `KitRunView(title, w, h, root_entity_id, app, opts)`

### gpui-kit-cpp hello_world.cpp 模式
```cpp
struct Example {
    static void OnGo(Example*, Ctx*, const ClickEvent*) { log(StrL("Clicked!")); }
    static El* Render(Example*, Ctx* cx) {
        const Theme& th = ThemeNow(cx->app);
        return Div(cx->a)->FlexCol()->SizeFull()->Gap(8)->ItemsCenter()->JustifyCenter()
            ->Bg(th.tokens.background)
            ->Child(TextEl(cx->a, StrL("Hello, World!"))->Font(16)->Fg(th.foreground))
            ->Child(ButtonEl(cx->a, 0, StrL("Let's Go!"), BtnKind::Primary)
                ->OnClick(Listen(cx, &Example::OnGo)));
    }
};
int GpuiMain(int argc, char** argv) {
    App* app = AppNew();
    component::Init(app);
    ThemeSet(app, ThemeMode::Light);
    return KitRunView(StrL("Hello World C++"), 800, 600, EntityNew(app).id, app, WinOpts{});
}
```
关键点：
- `Div(cx->a)` 在 frame arena 上分配 El
- `TextEl(cx->a, StrL("..."))` 文本元素
- `ButtonEl(cx->a, clickId, label, kind)` 按钮元素
- `Listen(cx, &Example::OnGo)` 创建 Listener（函数指针+EntityId）
- `EntityNew(app)` 创建实体并返回 handle
- `KitRunView` 打开窗口并运行事件循环

### TypePHP AOT 桥接模式（typephp-qt 参考）
- **stub 契约**：`php-src/qt.stub.php` 声明空函数体，C++ 侧实现 `php_` 前缀符号
- **源码内联**：`.cc` 桥接文件加入 project.yml sources，与 PHP 编译产物一起链接
- **主循环在 PHP**：`QtApp::run()` 泵 Qt 事件、分派 handler、按状态重渲染
- **声明式控件树**：`WidgetTree::vbox([...])` 返回嵌套数组，C++ 按 id diff
- **无头测试**：FakeBridge 纯 PHP 替身，--selftest/--shot/--difftest 三开关

### TypePHP 关键约束（来自 typephp-compiler README）
- `main(int $argc, array $argv): void` 全局函数入口
- 全局作用域只能有声明，可执行语句必须在函数里
- `.stub.php` 声明 C++ 导入 API，函数体必须为空
- `#[Native]` 类不允许出现在 stub 文件中
- 闭包参数需要显式类型标注
- 跨文件调用桥接函数用 `\qt_xxx()` 前缀
- Binary mode 需要 libphp embed SAPI
- `--nano` 模式不链 libphp，产物小一个量级（仅 Apple Silicon 实测）

### Context7 查询结果
- gpui-kit-cpp 本身不在 context7 索引中
- Rust GPUI 文档在 `/websites/rs_gpui`（3457 snippets）、`/longbridge/gpui-kit`（12380 snippets）
- GPUI Kit 是 Rust 桌面框架，基于 Zed 编辑器的 GPUI；gpui-kit-cpp 是其 C++ port
- typephp 不在 context7 索引中

### Shell Materialize 路径（关键发现 — 决定了桥接方案）
`src/shell/materialize.cpp`（2806 行）实现了完整的"脚本描述 → 原生 El 树"通道：
```
ScriptView::Render(cx)
  → runtime->render() 跑 JS 产出 RenderSnapshot
  → ShellMaterializeSpec(cx, runtime, specs, rootId)
    → MaterializeNode 递归每个 SpecNode:
      - ResolveBehavior(node) 解析 ops 里的 on_click/on_change/disabled/selected/id/tooltip 等行为
      - Construct(cx, runtime, component, behavior) 按 ComponentKind 构造对应 base/ui 组件
      - ApplyNullaryStyle / ApplyParamStyle 应用样式 op 到 El->style
      - element->Child(MaterializeNode(child)) 挂子节点
      - element->OnClick(Listen(cx, &ScriptView::OnClick, callbackId)) 绑定回调
    → 返回 El* 根元素
```
**核心洞察**：PHP 侧不需要重新发明 materialize，只要能把 PHP 的 Element 描述转成 SpecArena 的 Push/PushOp/Attach 调用序列，就能复用这套已经覆盖全部组件行为的渲染管线。

### Shell Style 系统（shell/style.cpp 1704 行）
两套样式应用机制，对应 gpui.h 的 El 链式方法：
1. **Nullary style**（无参关键字）：`flex_row`/`flex_col`/`items_center`/`justify_between`/`bg`/`text_xs`/`font_bold`/`cursor_pointer`/`shadow_sm`/`truncate`/`italic`/`underline` 等 ~150 个关键字，查 kKeywords[] 表直接改 El->style 字段
2. **Parametric style**（带参）：家族前缀 `w`/`h`/`gap`/`p`/`m`/`rounded`/`border` + tailwind ramp 后缀文法（`_4`/`_1p5`/`_full`/`_auto`/`_neg_2`/`_1_2`），以及独立参数方法 `bg("#hex")`/`text_color(..)`/`text_size(16)`/`font_family("mono")`/`opacity(0.5)`
3. **State style**：hover/active/focus 三种状态样式，各自是一个 detached SpecNode 的子 ops 集合

这正好映射 TypePHP 链式 builder：每个 `->method(args)` 记录一个 SpecOp{kind, name, args[]}。

### SpecArena 数据模型（shell/spec.cpp 766 行）
- `SpecNode` = {Component{kind,text,value,handle,payload}, ArenaVec<SpecOp> ops, ArenaVec<SpecId> children}
- `SpecOp` = {kind(NullaryStyle|ParamStyle|Method|Callback|ActionCallback|StateStyle|Slot), name, args[Bridged], callbackId, node}
- `Bridged` = 值标签联合 {Nil|Bool|Number|String}——这是跨语言边界的值类型
- `SpecArena` = arena 分配器 + nodes[] + parented[] + claimed[]，Push 返回 SpecId(uint32)
- `StructureFingerprint` = SplitMix64 哈希的形状指纹，用于模板缓存

TypePHP 桥接层要把 PHP 的 Element tree 翻译成这一串 Push/PushOp/Attach 调用。

### macOS 构建验证（2026-10-10）
`bun cmd/build.ts -rel hello_world` 在本机成功：
- clang++ 编译，c++20 标准，4 个翻译单元（AppLog.cpp + gpui.cpp amalgam + quickjs.c + hello_world.cpp）
- 产出 `out/mac/rel/hello_world`（8MB arm64），只链系统 framework（Cocoa/CoreText/CoreGraphics/ImageIO/IOKit/WebKit/AudioToolbox/AVFoundation/Speech）
- amalgam 生成在 `.work/gpui.h`(54K行)+`.work/gpui.cpp`(268K行)，含全部 src/ 代码 + component_shell
- **关键**：component_shell::Components()、ShellInitWithComponents、ScriptView、SpecArena、RenderSnapshot、RetainedStore 全在 amalgam 内可访问

### ScriptView 事件 handler 补丁点（view.cpp）
以下函数把 CallbackId 路由到 QuickJS（DispatchClick→JS_Call），PHP 桥接需 sed 替换为入队：
| 行号 | 函数 | 原调用 | PHP 替代 |
|---|---|---|---|
| 139 | OnClick | runtime->DispatchClick(callback, *event) | gpuiEnqueue("click", callback) |
| 161 | OnChange | runtime->DispatchChange(event->id, value!=0) | gpuiEnqueue("change", event->id) |
| 168 | OnHover | runtime->DispatchChange(callback, hovered) | gpuiEnqueue("hover", callback) |
| 175 | OnScrollPosition | 写 ShellScrollPosition + Notify | 保留（gpui 内部状态，无需改） |
| 346 | OnInputEvent | runtime->DispatchInputEvent(handle, *event) | gpuiEnqueue("input", handle) |
| 353 | OnSliderEvent | runtime->DispatchSliderEvent(handle, *event) | gpuiEnqueue("slider", handle) |
| 360 | OnOtpEvent | runtime->DispatchOtpEvent(handle, *event) | gpuiEnqueue("otp", handle) |
| 386+ | OnScriptKey/MouseDown/Up/ScrollWheel/Action | Dispatch* | 按需入队或透传 |

**设计**：bridge C++ 提供 `gpuiEnqueue(type, id, payload)` 全局函数；patch 后的 view.cpp 调它。PHP poll_event 取走队列里的 {type, handlerId, value} 分派给 on() 注册的 handler。

### friend 注入方案（gpui_shell_patch.h）
ShellRuntimeImpl 的 `retained`/`callbacks`/`scratch` 是 private。用 sed 在 amalgam 生成的 .h 里注入 friend：
```cpp
// 在 class ShellRuntime / RenderSnapshot / SpecArena 的 public: 后插入
friend struct gpui_shell::BridgeAccess;
```
BridgeAccess 提供 inline static 方法转发到私有成员。project.yml 的 sources 先跑 patch 脚本再编译。

### 事件回传 self 反查——已验证成立（2026-10-11）
链路（逐跳核对源码）：`Listen(cx, &ScriptView::OnClick, callbackId)` 把 `l.view = cx->self`（`Ctx.self` 是 `EntityId`，gpui.h:6784）；分派在 entity.cpp:286-297：`void* self = EntityGet(app, l.view); ((ListenerArgFn)l.Fn())(self, &cx, ev, l.arg);`。
- `EntityGet(app, viewEntity)` 返回实体数据指针 = 我们 `EntityNewRaw(g_app, view /*PhpView*/,...)` 传进去的 `view`，即 **PhpView***。
- 打补丁的 OnClick 收到的 `self` 就是 PhpView*，`boxForView((void*)self)` 命中 `g_views[PhpView*]→Box2`。callbackId 经 `l.arg` 完整传入。
→ Phase 3 的点击/变更/悬停事件回传路径**架构正确**（代码级证明；未做合成点击运行时验证）。

### Phase 4a：Retained Input/Slider 的真实约束（2026-10-11，推翻旧 friend 注入假设）
materialize.cpp:1910-1944 证明：Input/Slider/Textarea 靠 `runtime->Retained(component.handle)` 取 RetainedEntry→InputState/SliderState。取文本值用 gpui.h:5463 `Str InputValue(const InputState*)`。OnInputEvent/OnSliderEvent 的 `handle` 实参 = `(uint32_t)retained->id`（**RetainedEntry 的本地 id**，不是 EntityHandle component.handle）→ 事件侧要 elementId 需另存 `retained->id→elementId` 反查。
- **关键约束**：`component.handle` 在 JS 流程里由快照构建时 `impl->retained.CreateInput(...)`（runtime.cpp:4539）赋值。我们绕过 JS 直接建 arena，**必须自己**为带 id 的 Input/Slider 创建一个 retained 实体并把 `EntityHandle` 写进 `component.handle`。
- **旧"sed 注入 friend 到头文件"的方案不适用了**：防火墙下 shim 是独立 c++20 TU，include 的是 amalgam `.work/gpui.h`。而 `ShellRuntimeAccess`（转发 `impl`）与 `ShellRuntimeImpl` 定义在 runtime.cpp，**不在头里**，shim 看不见。ShellRuntime 公开只有 `Retained(EntityHandle)`（读），没有公开的 Create。
- **可行落地**：给 gpui-kit-cpp 打源码补丁，在 `ShellRuntime` 上加一对公开方法（薄转发到 `impl->retained`）：
  ```cpp
  // runtime.h public: 声明 + runtime.cpp 定义（用 ShellRuntimeAccess::Impl(this)->retained）
  shell::EntityHandle CreateRetainedInput(bool textarea, Str ph, Str val, int rows, EntityId owner, void* app);
  shell::EntityHandle CreateRetainedSlider(float min,float max,float step, EntityId owner, void* app);
  ```
  重跑 `bun cmd/build.ts` 生成 amalgam 后，shim 即可调 `g_runtime->CreateRetainedInput(...)`。
- **跨帧稳定性**：`specs.Reset()` 每帧重建 arena，但 retained 实体活在 RetainedStore（runtime 所有），不随 arena 消失。故 Box2 需 `elementId→EntityHandle` 映射：首次 push 某 id 的 Input 才 Create，之后复用 handle 写进 component.handle。销毁在窗口 close 时 `RetainedStore::Release`/`ReleaseOwner`。
- **C ABI 需扩**：`gpui_shim_push` 现只带 kind/text/value；要新增 (a) push 时传 elementId 供 retained 键、(b) 输入初值/placeholder/rows 参数、(c) `gpui_shim_input_value(win,id)->str` 与 slider 值读取、(d) enqueue 的 input/slider 用 retained->id 反查 elementId+取当前值入队。

### 渲染两大坑：arena 字符串拷贝 + Button 标签为子元素（2026-10-11，--shot 首见并修复）
用 `SpecArena::DebugTree` 拿到 arena ground truth，定位 #18 渲染 bug 的两个根因：
1. **字符串悬垂**：`SpecArena::Push`/`PushOp` 只存 `Str` 视图、不拷贝字节（JS 路径靠 `JsString(...,arena,...)` 先分配进 arena）。bridge 传的是 emitTree 返回即销毁的临时 `std::string` 的 `c_str()` → 悬垂。debug tree 实测 `.size("100%")`→乱码、`Button "+1"`→`"in"`、id/variant 乱码 → 无效长度值 → 布局塌缩、子元素"消失"（attach 其实正常，是值坏了）。**修法**：shim 所有进 arena 的字符串用 `gpui::StrDup(box->specs.Storage(), ...)`（新增 `AStr(arena,cstr)` 助手；push text/value、op name、Bridged String 参数全改）。
2. **Button/Link 标签来自子元素**：materialize.cpp:980-981 `for(child: node->children) button->Child(...)`，`Button::New` 不收 text 参数。`El::button(label)` 必须把 label 作为子 text 元素。
- 附带：`sizeFull/wFull/hFull` 之前发 nullary `size_full`（C++ 无）被静默忽略 → 根不撑满 → justify_center 无效。改参数化 `size/w/h`+"100%"（ParseLength 支持 %）。
- 遗留视觉打磨（非阻塞）：slider 需显式尺寸；Button `variant("primary")` 背景色未显。

### Stage 7 Dock 设计（未落码，供后续实现）
Dock 是 gpui-kit-cpp 最复杂的 shell 组件（materialize.cpp:1259-1374、2507-2509、293-300）。落码风险极高，故仅出设计：
- **retained**：`RetainedKind::Dock` + `RetainedStore::CreateDock(Str id, bool hasVersion, int version, Ctx*, EntityId owner, void* application)`（retained.h:155）。签名要 `Ctx*`（不像 Input/Focus 只要 App）→ 不能在 bridge 预建期调用（无 Ctx），须在 materialize 期或手构 Ctx（如 dialog 那样）创建。
- **DockArea 实体**：`DockArea::New` 取一个 renderer（ScriptDockSkin），base 不支持事后换 renderer；skin 常驻 RetainedEntry.dockSkin。
- **面板**：不是描述，是 `add_panel` 命令（DockCommand），面板名由 DockLoad 持有。
- **chrome（4 个 callback op）**：tab_bar / empty_group / drop_indicator / dock（materialize.cpp:293-300）——每个要一个 callbackId，回调进脚本渲染 chrome；我们绕开 JS，需为这 4 个提供 PHP 可重入渲染通道（同 List 的 RenderVirtualItems 难题 → 不可达）。
- **7 个 dock command method**（220-228、1293-1374）：MovePanel/ResizePanel/RemovePanel/DockTo…命令式。
- **结论**：Dock 依赖 chrome 的"回调进脚本渲染"，与 List 虚拟化是同一类不可达问题（gpui shell 假定 QuickJS）。**推荐路线**：像 List 那样 PHP 侧自绘 dock chrome（用 Popover/Flex 组合模拟停靠布局），而非驱动 gpui 的 DockArea；若坚持原生 DockArea，需给 materialize 打大补丁把 4 个 chrome callback 改成"从预构建 spec arena 取"。故本轮仅设计，建议单列后续专项。

## Technical Decisions
| Decision | Rationale |
|----------|-----------|
| **sed 补丁 view.cpp 事件 handler 为入队**，不依赖 QuickJS DispatchClick→JS_Call | PHP 没有 JS function 可调；入队模式与 typephp-qt 一致且已验证 |
| **friend 注入暴露 RetainedStore/CallbackArena/SpecArena**，而非 fork 上游 | 改动最小（~10 行 sed），上游更新时重跑 patch 即可；不改 materialize/spec/style 逻辑 |
| **复用 shell spec/materialize 通道做桥接** | materialize.cpp 已完整实现 80+ ComponentKind 行为解析、样式应用、回调绑定；重写代价极大 |
| PHP 侧 Element builder 链式 API，内部累积 SpecOp 列表，最终一次性写入 SpecArena | 保持 gpui 原生风格，arena 操作集中到 bridge C++ 一次完成 |
| 回调路由：PHP handler id ↔ C++ CallbackId(uint64)，bridge 维护 id→callable map | 与 ScriptView 的 OnClick(callbackId) 模式一致 |
| Entity/Window/App handle 用 int64 opaque id（php::Box resource） | TypePHP stub 无法表达 C++ 指针；Box 模式已在 typephp-qt 验证 |
| 第一版只做 macOS arm64 | 开发环境是 Mac，paint_mac.cpp 已有 Core Graphics 后端，构建已验证 |
| gpui-kit-cpp 作为 git submodule | 703 文件涵盖运行时+布局+组件+主题，直接复用源码参与 tpc 编译 |

## Issues Encountered
| Issue | Resolution |
|-------|------------|
| better-harness skill 不存在于可用列表 | 跳过该 skill，继续 planning-with-files 流程 |
| session-catchup.py 路径在 ~/.config/opencode/skills/ 而非 ~/.qoder/skills/ | 脚本存在但输出为空（无历史 session），正常继续 |

## Resources
- gpui-kit-cpp 本地源码：`/Volumes/data/git/c/gpui-kit-cpp`
- TypePHP compiler：`/Volumes/data/git/php/typephp-compiler`
- typephp-qt（桥接参考）：`/Volumes/data/git/php/typephp-qt`
- gpui.h 核心头文件：`/Volumes/data/git/c/gpui-kit-cpp/src/gpui/gpui.h`（7737 行）
- base.h 基础类型：`/Volumes/data/git/c/gpui-kit-cpp/src/base.h`（1329 行）
- ui/lib.h 组件入口：`/Volumes/data/git/c/gpui-kit-cpp/src/ui/lib.h`（99 行）
- ui/button.h：`/Volumes/data/git/c/gpui-kit-cpp/src/ui/button.h`
- ui/input.h：`/Volumes/data/git/c/gpui-kit-cpp/src/ui/input.h`（640 行）
- ui/theme.h：`/Volumes/data/git/c/gpui-kit-cpp/src/ui/theme.h`（905 行）
- ui/sizing.h：`/Volumes/data/git/c/gpui-kit-cpp/src/ui/sizing.h`（477 行）
- base/root.h KitRunView 入口：`/Volumes/data/git/c/gpui-kit-cpp/src/base/root.h`
- examples/hello_world.cpp：`/Volumes/data/git/c/gpui-kit-cpp/examples/hello_world.cpp`
- Rust GPUI docs (context7): `/websites/rs_gpui`
- GPUI Kit docs (context7): `/longbridge/gpui-kit`

## Visual/Browser Findings
- gpui-kit-cpp hello_world 截图显示一个居中白色背景窗口，含 "Hello, World!" 文本和一个蓝色 Primary 按钮 "Let's Go!"
- 窗口标题 "Hello World C++"，尺寸 800x600 DIPs

---
*Update this file after every 2 view/browser/search operations*

## Engineering Hardening Audit (2026-10-11) — 证据

功能（Stage A/B/C/D + 前序）已交付，本审计回答"工程上还能完善什么"。以下均为实测证据：

| 事实 | 证据 | 影响 |
|------|------|------|
| 目录**不是 git 仓库** | `git rev-parse` → 非 work-tree | 无版本控制/diff/回滚 |
| 无 CI | `.github` 不存在 | 无自动门禁 |
| 无静态分析/风格 | phpstan/php-cs-fixer/clang-format/clang-tidy 均缺 | 质量靠人眼 |
| C++ 侧零测试 | `tests/` 仅 Pest；无 `*_test.cc` | arena 生命周期/lookup/回调路由无回归守卫（bug #18 类） |
| 构建不可复现 | `project.macos.yml:33` ld-flags 硬编码 `/Volumes/data/git/...`；`vendor/gpui-kit-cpp` 是**未固定 symlink** 指兄弟目录 | clone 后无法构建；换机即断 |
| 构建链未编排 | `composer patch` 漏 `patch-registered-cb.sh`，且不含 `build.ts`+`build-shim` | 新环境 setup 步骤易漏、易错 |
| 每帧回调累积泄漏 | `gpui_shim_render_begin` 只 `specs.Reset()`；`Box2.callbacks`/`shimCallbacks` 不清；`gpui_shim_new_callback` 每帧 `PushShimCallback` 追加 committed 条目 | live app 无界增长（3 帧 demo 掩盖） |
| 窗口/dialog 不析构 | `gpui_shim_close` 仅 `AppClose`+flag，不 `delete box`、不摘 `g_views`、不放 `box->dialogs`；`g_app`/`g_runtime` 无 `AppFree` | 多窗口/长驻漏 |
| 错误只进 debug_tree | `gpui_shim_registered`/`_method` 失败 Push 错误 Text 节点，无返回/异常通道 | PHP 侧静默失败 |
| MenuBar 不成盒 | `--shot tree=24` 报 `app effects require a root application snapshot` | 需 catalog `ComponentWindowOpener` 的 root-app |

**优先级**：P0=内存/错误回传 + 可复现构建（git/钉库/去绝对路径/一键 bootstrap）；P1=C++ 测试 + --shot 基线 diff + CI + 静态分析；P2=MenuBar root-app、A 类 VirtualList/Dock、kind 覆盖、rm()/cb() 抽象泄漏、文档/示例。详见 task_plan.md「Phase 7」。

## MenuBar / AppEffects 架构调查（2026-10-11，P2-2）

结论：**MenuBar 与 Dock/VirtualList 同属"需要 ScriptView root-application 机制"这一类，与我们为 Dialog/Toast 选的 ShellRoot 根模型冲突**。证据链：

1. `MaterializeRequest::AppEffects`（runtime.cpp:13250）→ `runtime->ScriptViewApplication(cx->self, cx->app)`。
2. `ScriptViewApplication`（13285）遍历 `impl->views[]` 找 `view==cx->self` 的项，再 `EntityGet(app,view)` 取 **ScriptView**，要求 `script->object->runtime==this`，返回 `object->application`（或 object）。→ 我们的 `cx->self=shellRoot` 是 ShellRoot 实体、且 PhpView 从没进 `impl->views`，故返回 null → 报 "require a root application snapshot"。
3. 即便塞个合成 application 也不够：`ScheduleComponentAppEffect`（13338）把延迟效果 `WindowPost(window, ListenTo(owner=ScriptView{view}, &ScriptView::OnComponentAppEffect, token))`——**硬编码把 view 当 ScriptView 解引用**，shellRoot 会崩。
4. catalog 的 `OpenWindowWithRoot`（lib.cpp，经 `registry.WithWindowOpener` 注册；`ShellOpenWindow` host.cpp:65 会用它）用**基座 `Root::New`**（base/root.cpp）作 window->root——这才是 AppEffects 认的 root-application。**但我们早先弃用 KitOpenWindow 正是因为基座 Root 包装会让 `ShellRootOf` 失败 → Dialog/Toast 挂**（见 Stage 5 记录）。

即：Dialog/Toast 要 ShellRoot；MenuBar/AppEffects 要基座 Root（或真 ScriptView 根）。二者当前互斥。

可选路径（都非小改）：
- **A 重做窗口根模型**：走 catalog opener（基座 Root），再想办法让 Dialog/Toast overlay 在基座 Root 下也能定位（可能要把 ShellRoot 挂成 Root 的 inner content，并让 ShellRootOf 能穿透——需验证）。高影响面，威胁现有 6 项绿门禁。
- **D 给 AppEffects 加 shim 旁路**：`RegisterShimRootApp(view,token)` + `ScriptViewApplication` 认它 + 改 `ScheduleComponentAppEffect` 对 shim 根走同步/自有 pump 应用（绕开 ScriptView::OnComponentAppEffect 的 post）。中-高复杂、碰延迟效果调度核心。
- **C 让 MenuBar 用非 AppEffects 的替代**：menu.cpp 是上游编译进 gpui.o 的，改不了其 materialize；除非换用别的可渲染菜单组件（如把 Menu 挂到 DropdownMenu 触发器——但 DropdownMenu 已覆盖交互菜单场景）。

判断：为**一个原生菜单栏组件**去动窗口根/延迟效果调度，blast radius 大、且可能回归 Dialog/Toast/selftest/shotdiff。倾向：要么接受 A/D 的风险明确开工（需你点头），要么把 MenuBar 归入"需 root-app"已知限制、先推进 P2-4（rm()/cb() 抽象，低风险）与 P2-1（VitePress）。

## VirtualList-0 重入探针结论（2026-10-11，架构分析判定，未写探针代码）

**gpui 渲染期 `BuildWith`（需同步返回 `El*`）无法回调进 PHP 闭包。** 两条硬约束：

1. **模型不重入**：本框架所有回调都是「C++ 入队 → PHP 主循环 pull 后 dispatch」——`App::dispatch()` 在 PHP 侧调 `$handler($value)`（handlers 存 `App::$handlers`）。C++ 从不、也无法在 gpui materialize 期间同步调回 PHP 函数栈。
2. **防火墙隔离**：phpx（bridge，c++17）与 gpui（shim，c++20）之间只走纯 C ABI。PHP 闭包是 zval/编译后运行时对象，**无法跨 C ABI 边界**表示或调用。materialize 在 shim 里跑，那里没有 phpx 可调 PHP。

⇒ **VirtualList 真·懒委托（gpui 拉行、行内容由 PHP 现生成）不可行**。可行路径是 **PHP 窗口化**：gpui 发一个带 scrollTop 的滚动事件（数字，可跨 C ABI）→ PHP 每帧只渲可见行窗口 → 下一帧生效。符合 enqueue/pull 模型、可 `--shot` 验证。代价：非 gpui 主动拉行，是 PHP 推窗口；需补一条 scroll-offset→PHP 的事件通道（小机制，非重入）。

MenuBar 侧：catalog MenuBar 仍被 root-app 挡；已用 `BaseSetAppMenus` 走通**安装**（MenuBar-1，selftest appMenuCount 证）。动作→PHP（MenuBar-2）需每帧在根元素绑 `ActionCallback` op（name=action, callback=shim rtCb）→ WindowDispatchAction 冒泡到根 → DispatchArgs 基石旁路。可行但耦合渲染树，ROI 低（系统菜单栏不可视验证）。

### VirtualList 定论：gpui 原生虚拟化 = 渲染委托，防火墙不可达

读 `LazyListElement`（materialize.cpp:1161-1245）：gpui 的 list/uniform_list 虚拟化靠 `spec->renderItems` **渲染委托**——`runtime->RenderVirtualItems(spec->renderItems, spec->getKey, ...)` 在需要可见行时**同步回调**产出行元素。`renderItems` 是 CallbackId（JS）。⇒ 与 build-delegate 同一堵墙：**gpui→脚本同步回调**，纯 C ABI 防火墙（phpx↔gpui 隔离，闭包/zval 不可跨界）无法提供。

**epic 边界（定论）**：本防火墙支持 **gpui→PHP 异步**（事件入队→主循环 pull→dispatch，含 Stage D/基石的 shimId 旁路），但**不支持 gpui→PHP 同步取值**（渲染委托、虚拟列表行生产者、Dock chrome 委托）。凡"gpui 主动拉脚本要返回值"的组件（VirtualList/Dock/依赖 AppEffects 的 catalog MenuBar）都在此墙外，非增量能及——要么放弃异步防火墙模型改同步重入（与 AOT/nano 冲突），要么在 PHP 侧另实现。

**PHP 侧替代（唯一可行方向）**：PHP 自算窗口——只把可见行渲进普通滚动容器。但绝对 scrollTop 反馈到 PHP 也不干净（gpui 把滚动位存 `ShellScrollPosition` keyed state 供 Scrollbar 读，非回调），只能用 `on_scroll_wheel` 的 deltaY 累加**近似**偏移 → 窗口化。可用但不精确、且非真惰性。属"近似功能"而非"复刻 gpui 虚拟化"。

## 能力盘点订正 + ChildView 口子（2026-10-11，Wave 1）

### 1. 上一轮三层地图继承了一个错前提
用户给的 gpui 清单是 **Rust gpui** 的 API 名。逐条查 C++ 移植版：`nm -C out/mac/rel/obj/gpui/gpui.o`
（21324 符号）对 `RecentDocument|JumpList|UrlScheme|ScreenCapture|IdleSleep|Credentials|
ObserveDisplays|ActivationPolicy|WebSocket|TCPSocket|Tray|StatusItem|EventTap|
UserNotificationCenter` **全 0 命中**；源码里只有 `window_mac.cpp:2134` 一处内部
`[NSApp setActivationPolicy:]`（不是对外 API），`typings_data.cpp:22144` 的 `net`/`websocket`
是 TS 声明无实现。⇒ 这些是"端口没有"，不是"有没接"。已写进 `docs/guide/system-capabilities.md`。

### 2. Tier 3 的成本被高估：不需要外部库，也不需要 ui3 的码
`cmd/build-shim.sh` 已用 `-std=c++20 -x objective-c++ -fobjc-arc` 编译；链接表（
`examples/hello/project.macos.yml`）已含 `Cocoa / WebKit / Carbon / CoreServices / IOKit /
AVFoundation / Speech / Metal / QuartzCore`。所以托盘（NSStatusItem）、全局快捷键（Carbon
`RegisterEventHotKey`，免输入监控授权）、Dock 角标（NSDockTile）= 自写一个 `.mm` TU 的事。
ui3 只留配方参考价值，其同步泵自有循环的写法（`gtk4.c:18-19`、`x11.c:675-677`）与
`gpui_shim_pump` 主循环互等死，不可照搬。

### 3. `SysNotifyShow` 三平台不对称（实测源码）
Windows 真实现（`notify_win.cpp:155`，`Shell_NotifyIcon`）；mac（`notify_mac.cpp:17`）与
Linux（`notify_linux.cpp:19`）是 `return false` 桩。⇒ 桥它 = Windows 有 OS 通知、mac 静默无。
另外 `ui/notification.cpp` 的**应用内**通知中心（`NotificationPush/Dismiss/Clear` + 角落堆叠 +
autohide + click/close）是完整实现，只有"系统那一半"缺——它是比 Toast 高一档的可用能力。

### 4. 【关键】`ComponentKind::ChildView` 就是 Wave 2 缺的那道口子
`materialize.cpp:2045`：
```cpp
case shell::ComponentKind::ChildView: {
    EntityId child = runtime ? runtime->NestedView(component.handle, cx->app) : EntityId{};
    El* rendered = child.IsValid() ? EntityRender(cx->app, cx->win, cx->a, child) : nullptr;
    return rendered ? rendered : Div(cx->a);
}
```
⇒ **任意 entity**（只要其 RenderFn 注册过，正是我们 `PhpView` 用的 `EntityNewRaw` 路径）都能
被当元素挂进树。WebView（`WebViewEl(Entity<WebView>, Ctx*)`）和 Inspector 面板
（`component::Inspector::New(cx)->IntoEl()`）都只需要一个"产 `El*` 的 entity"，不需要 catalog
descriptor。
**唯一缺口**：`nestedViews` 的**注册侧**在 `runtime.cpp` 里只由 QuickJS 的 `__view_new`
（`NativeViewNew`）走，`NestedView(handle, app)` 是只读查询。⇒ 需要一个小补丁给 host 开一个
`ShellRuntime::NewNestedView(EntityId)` 入口，与已有的 `PushShimCallback` 补丁同族（第 7 个 marker）。
这比原计划设想的"shim render-pass 特例"干净：不碰渲染路径，只补注册。

### 5. `WindowToggleInspector` 是拾取模式，不是面板（像素实测）
tree29 出图 = 整窗淡蓝底 + 描边（选中高亮），没有 inspector 面板。gpui 侧没有"面板是否打开"
的查询符号（只有 `WindowToggleInspector` / `WindowInspectorPick`），所以它的验收只能是像素。
面板归第 4 条那道口子。

### 6. Pest 全局钩子静默失效（既存 bug，本轮才暴露）
`tests/Pest.php` 原写 `uses()->beforeEach(...)`：`Configuration::__call` → `uses()->beforeEach()`
→ `UsesCall` 的 **targets 为空** → 钩子挂在一个没有任何文件的组上 ⇒ `FakeBridge::reset()`
从来没跑过，静态状态跨测试泄漏。读源确认（Pest 5.3.1）：`BeforeEachCall::__destruct` 以
**声明处文件名**为键注册，而 `TestCall::__construct` 以**测试自身文件名**查键
（`TestSuite::beforeEach->get($this->filename)`）⇒ 写在 Pest.php 里的裸 `beforeEach()` 也打不中。
可用形式：`uses(TestCase::class)->in(__DIR__)->beforeEach(...)`（实测钩子生效）。
原有 25 个测试恰好不依赖 reset，所以一直没暴露。

## Wave 2：ChildView 打通 + 「要不要桥」交给产物自己回答（2026-10-11）

### ChildView 实测可用，WebView 与 Inspector 面板共用一条路
第 7 个补丁 `cmd/patch-nested-view.sh` 给 `ShellRuntime` 开 `RegisterHostView(EntityId, App*)`：
把宿主已有的 entity 追加进 `impl->nestedViews` 并回 token。安全性逐条核过：
`DropNestedViewAt` 只做 `EntityDrop` + `PolicyRelease`，而 `policy.cpp:56` 的
`PolicyRelease` 对 null 直接返回；entry 的 `owner`/`application` 留空 ⇒
`ReleaseOwnedEntities` / `ReleaseApplicationState` 那两次按 owner/application 的清扫永不到它，
生命周期完全归宿主。零改动既有路径。

**关键坑（实测出来的）**：`gpui::WebView` 自己**不是 view**——`webview.cpp:95` 用
`EntityNewState` 建它，注释写明"a WebView has no Render of its own here"。而 ChildView 走
`EntityRender`，所以必须有一层自己的 wrapper view 返回 `WebViewEl(...)`。Inspector 面板
不需要状态（`story.cpp:1961` 就是每帧 `New(cx)->IntoEl()`），但 `inspector.h:63` 的契约是
**关着的时候 IntoEl 返回 null** ⇒ tree31 必须先 `toggleInspector()` 才拍得到面板，
否则得到一张"面板不存在"的假基线。

**验收分层**：WebView 在离屏 `cacheDisplayInRect` 里**必然空白**（跨进程表面不被合成），
所以它的机器验收换成 `webviewProbe()`——读 `wry::WebViewUrlTemp` + `WebViewBounds`，
即**控件自己报**的当前地址与布局实际应用的盒子（selftest 打出 400×300）。tree30 降级为
"webview 没把布局搞塌"的布局基线，不再冒充能力验收。

### Wave2-D（sha256 / secureRandom / zlib）被 `hello --caps` 砍掉
新加 `--caps` 让编译产物自己报原生能力：
`hash, hash_hmac` ✓ · `random_bytes, random_int` ✓ · zlib ✗ · curl ✗ · socket ✗ · proc  ·
fs(file_get_contents/scandir/touch) ✓ · inotify ✗。
⇒ sha256/secureRandom **不桥**（PHP 侧本来就有，桥过来是净负价值：多一条 C ABI + 一个会漂移
的替身）；`shell::ZlibDeflate` **不桥**（`DeflateStored` 只加壳不压缩，名字会骗人）；
`ZlibInflate` 值得桥（真解 Huffman，且 nano 不带 ext/zlib）；`HttpSendAsync` / `DirWatchStart`
**优先级升高**（curl/socket/inotify 全空，是真缺口）。

**探针方法学的坑**：先用"把函数名放进变量再 `$fn()`"当判据，撞到 nano 行为差异——对不存在的
函数走动态调用报 `Invalid callback ... not found or invalid function name` 并 **SIGABRT**，
不抛可 catch 的 Error。改用 `function_exists`（nano 的扩展集由 Composer 编译前决定，不按源码
用没用裁，所以它是忠实探针）。

## Wave2-E：三个真缺口接完，验收靠"外部数据 + 失败路径"（2026-10-11）

**接了什么**：`HttpSendAsync` → `http` 事件、`DirWatchStart/Stop` → `dirChange` 事件、
`shell::ZlibInflate` → `App::inflate`。事件槽只有 256 字节，所以**事件只报"有结果了"，
内容另取**（`httpResponse($id)` 一次性读走即销毁）。

**三条实测出来的前提**（都不是想当然）：
1. `HttpSendAsync` 的 `done` **保证在主线程**（`http.h:100`），`DirWatch` 的 `onChange`
   经 `ExecPost` 也在主线程（`dir_watch.h:16`）⇒ 入队不需要锁。我们的 pump 里确实有
   `ExecDrain()`（`window_mac.cpp:2326`，GpuiAppRunStep 补丁内），否则回调永远落不了地。
2. `HttpSetEnabled(false)` **不是** `HttpSendAsync` 的测试接缝——`gHttpEnabled` 只在
   `HttpFetch` 表路径上被读（`http.cpp:315`）。所以 http 的无人值守验收换成
   `http://127.0.0.1:1/`：loopback 上必然拒绝，整条异步路走通而不碰外网。
3. `DirWatchAdd(Func0)` + `DirWatchSignal(id)` 是**官方**测试接缝（`dir_watch.h:86`
   原文："A test stands in for the OS by calling DirWatchAdd and DirWatchSignal"）⇒
   dirChange 的验收完全不碰文件系统。

**zlib 的语义钉法（三方锚）**：开发机上用 PHP 的 zlib 生成 "hello gpui" 的两种容器
（zlib 18 字节 / gzip 30 字节），同一批字节写死在三处：`tests/cpp/shim_test.cpp`（gpui 的
解压器，C++ 直调）、`examples/hello` selftest #13（跨整条 bridge）、`tests/SystemTest.php`
（替身用 PHP 自己的 `gzuncompress`/`gzdecode`）。顺带确认 `ZlibDeflate` 的头真是 0x78 /
1f 8b —— 它"只加壳"这件事因此是可证的，不是猜的。

**踩到**：`Box2` 成员里写 `std::unordered_map<int, struct WatchCtx*>` 会**在 Box2 内部**
声明一个 `Box2::WatchCtx`，之后匿名 namespace 里的真定义就"引用二义"。前向声明要写在
`class Box2` 之前。另一个：Pest 的 error handler 不吃 `@`，所以替身里 `@gzuncompress(坏数据)`
仍会变成测试告警——改成先判容器头再解。

## 剪贴板三通道：一处被继承的错前提 + 一处测试装置的坑（2026-10-11）

**gpui 的 `ClipboardItem` 只有 text/image/paths 三个扁平字段**（`.work/gpui.h:3850`），
一次快照给全它能读到的所有表示。但**文件路径只有 Windows 填**：`window_win.cpp:1636` 读
`CF_HDROP`，而 `window_mac.cpp:2066` 的 `ClipboardGetItem` 只取 text + `NSPasteboardTypePNG`
→退 `NSPasteboardTypeTIFF`，Linux 同理。⇒ 上一轮把"剪贴板含 externalPaths"当成 gpui
跨平台能力是错的，mac 上桥过去会永远拿到空。

**做法**：shim 里 `#ifdef __APPLE__` 自己读 `NSPasteboardTypeFileURL`
（`readObjectsForClasses:@[[NSURL class]] options:FileURLsOnly`），输出成与 Windows 同形状的
"换行分隔绝对路径"，PHP 侧不必分平台。这不是上游补丁，是我们自己的 shim 代码——shim 本来就
以 `-x objective-c++ -fobjc-arc` 编译且已链 Cocoa，所以零新依赖。

**图像是编码流不是 RGBA**（PNG 或 TIFF，取决于来源），所以跨边界要二进制安全：
新加 `copyBounded`（写 min(need,cap)，**总是返回 need**，不加终止符），ABI 用
`outTextLen/outImgLen/outPathsLen` 三个显式长度出参，让 bridge **一次读取**就知道有没有
被截断——否则要么反复读粘贴板（两次读之间内容可能已变），要么混用两个快照的字段
（"上一份的文本 + 这一份的图"）。bridge 的策略因此是：无图时一趟搞定；有图时**整笔**重取。

**测试装置的坑**：用 AppleScript `set the clipboard to {POSIX file "/a", POSIX file "/b"}`
验文件通道会得到 `paths=0`，看着像代码坏了。实测 `clipboard info` 显示那是
`dyn.ah62d4rv4gk8024pxsu` / `'list'` flavor，**不是** `public.file-url`。改用
`[pb writeObjects:@[NSURL...]]` 才写出 Finder 复制文件时的真实类型（`public.file-url` +
`NSFilenamesPboardType`），此时 `--clip` 报 `paths=2`。⇒ 验粘贴板要以"目标 App 实际写入的
UTI"为准，不能以"看起来像的脚本"为准。

**四种组合的真数据实测记录**（`hello --clip`）：文本 16B → `text=16 image=-1 paths=0`；
PNG 25110B → `text=-1 image=25110 paths=0`；两个 file-url → `paths=2`；
文本+PNG 同时 → `text=10 image=25110`。

## SysRefresh：差值量与直接量必须分开断言（2026-10-11）

`SysState`（`sys/sysinfo.h:43`）里两类量混在一起，验收时必须区别对待：

- **直接读数**：`mem/memTotal/memUsed`、`disk.usedPct/total/used`、`ncpu`、电池
  → 第一次采样就非零，可以直接断言。
- **差值量**：`cpu` 要 `prevCpu.valid` 才算得出来（`sysinfo_mac.cpp:58`），每进程 cpu 要
  `FindPrevCpu` + `wallDelta` ⇒ **第一次调用恒 0**。这不是 bug 是语义，所以自检里
  先断言"第一次报 0"，再烧 150ms 单核、泵两帧，第二次必须 > 0（实测 46.2%）。
  烧单核在 8 核机上约 12%，离 0 有量级余量，不靠机器噪声过关。

**采样状态放进程内一份，不放每窗口一份**：`RefreshProcesses` 里的墙钟差
`static uint64_t sPrevWall`（`sysinfo_mac.cpp:207`）本来就是文件静态量——多份 `SysState`
交替采样只会互相污染彼此的 cpu 读数。因此 `App::sysInfo()` 是静态方法，无窗口参数。

**截断进程列表是安全的**：`SysSortProcesses(s, Cpu, true, keepTop)` 截 `s->procs`，而
下一次 `RefreshProcesses` 用 `FindPrevCpu(s->prevProcs, pid)` **按 pid** 找前值，
`prevProcs` 是每次重建的完整平行数组 ⇒ 截断不会让下一次读数错位。（若它是按下标找，
这个 API 就得改成"排序但不截断、由 bridge 只搬前 N 条"。）

**disk 是启动卷**：mac 侧 `statfs("/")`，`used = total - f_bavail`。这与 PHP 的
`disk_total_space/disk_free_space` 同算法 ⇒ 替身拿本机文件系统当对照，Pest 那条
`diskPct ≈ used*100/total` 两侧都成立，不是编出来的数。

**踩到**：Pest 5.3 没有 `toBeAround()`（PHPUnit 的 `assertEqualsWithDelta` 系），要写
`expect(abs(a - b))->toBeLessThan(eps)`。

## 应用内通知中心：一面能绕的墙 + 一个"绿得没内容"的像素闸（2026-10-11）

### 墙：通知层挂在基座 `Root` 上，我们的根是 `ShellRoot`

gpui 的 `ui/root.cpp:62` 由基座 `Root` 自己挂 `WindowNotifications(cx)`，而
`WindowNotifications` → `LayersOf` → `WindowLayersOf(win)` → `Root::Read(win)`：它读的是
**基座根**。我们的窗口根是 Stage 5 为 Dialog/Toast/Sheet 换上的 `ShellRoot`（`shell/root.cpp`），
两者互斥 ⇒ 直接调 `WindowNotifications` 就 `SIGABRT`，报
`component window state is missing; call component::Init before KitOpenWindow`。

**为什么这次绕得过去、MenuBar 绕不过去**：`ui/notification.cpp` 全篇不出现 `Root`/`Layers`
（只有 `WindowNotifications` 这个便利函数用），状态与渲染都挂在 `NotificationListState`
entity 上。所以做法是：每窗口自己养一份状态 entity（`EnsureNotifyState`），层由 PHP 显式
摆一颗 `El::notifications($id)`。MenuBar 那条路依赖的是 `AppEffects`（root-application 级），
没有这种"换个 owner"的余地。

**两个必须手工补齐的字段**，缺一个就静默不工作：
- `s->self = st.id` —— `NotificationStartAdvancing` 开头就 `!s->self.IsValid()` 直接 return，
  计时器根本不启动，通知永远停在 `Starting`（全透明）且永不退场。
- `s->useThemeSettings = true` —— 与 `window_ext.cpp:144` 一致；否则 `placement/margins/width`
  取的是 state 的裸默认值。

### 像素闸会"绿得没有内容"：`SHOT OK` 不等于拍到东西

`hello --shot … 32` 一路报 `notify_count=2` → `SHOT OK`，图里却只有宿主标题。原因全在 gpui
侧的动画：`notification.cpp:944-951`，`Starting` 段的不透明度 = `elapsedMs / 400`，而推
`elapsedMs` 的是 50ms 的**墙钟**计时器（`kNotificationTickMs`）。只泵 3 帧 ≈ 几十毫秒，
于是拍到的是 opacity 0 的一帧——**退出码、计数、debug tree 全都对**。

结论入规：凡是"浮层/动画"类像素验收，必须跑到动画走完，并且**用两个不同帧数出图对拍**
证明落在静止段（本次 80 帧与 600 帧产物 md5 相同）。计数与 `SHOT OK` 不能当像素证据。

### 顺带量到的两件事

- **同一角会叠成 collapsed stack**：`kToastCollapsedVisible=3`、旧的只露
  `kToastCollapsedPeek=14px` 一条边。所以"两条通知"的基线要分放两个角才拍得全，
  这本身也是 `placement` 锚点映射的像素证据（边距 16/50、宽 382 = `NotificationSettings`
  默认值 ⇒ 反证 `useThemeSettings` 那条读主题的路是通的）。
- **图标在本端口没画出来**：通知卡片没有类型图标，tree13 的 toast 基线同样把图标渲染成了
  被裁掉的 "succe" 文本。既有现象、与本次改动无关，未深究（要查的是图标字形/图标名解析）。
- **与基座差一档：Sheet 内缩**。基座那层把通知包在一个按打开的 Sheet 让位的绝对层里
  （`ui/root.cpp:64` `RootNotificationInsets`：Sheet 占哪条边，那边就让出它的尺寸）。
  我们挂的是裸的 `NotificationList::IntoEl()`，卡片定位读 `WindowSize(cx->win)` ⇒
  Sheet 开着时通知会压在 Sheet 上。要么 PHP 自己把 `El::notifications()` 摆进让过位的容器里，
  要么以后在 shim 侧补这层 inset（`ShellRoot` 的 sheet 状态读得到）。已知即可，本轮不做。

## 图标不是端口缺口：是我们自己的两处 bug（实测，2026-10-11）

> 起因：一条外部调查结论说"图标字体从未落地、要上游改"。**逐条核对后作废**——它引用的
> `src/base/icon_font.cpp`、`assets/iconfont.ttf` 在这个仓库里根本不存在（`ls` 直接 ENOENT）。
> 下面是自己查出来的。

### 图标链路是完整的，数据也编进去了

- 111 个 lucide 图标预转成 drawops 字节码、编进二进制：`src/gpui/asset_icons.cpp`
  （216KB，`kAssetIconsDataLen = 33038`、`kAssetIconsCount = 111`），读盘只在应用自己的
  `.svg` 才发生。
- 查找：`AssetIconFind` / `AssetIconForPath`（`src/gpui/svg.cpp:1366-1392`）。
- macOS 画：`src/gpui/gpui.cpp:8152-8166`，`ElKind::Icon` → `SvgDraw(ctx, path, ...)`，
  `path = e->iconPath.s ? e->iconPath : IconNamePath(e->icon)`。
- 通知要用的四个名字**都在**：`circle-check` / `triangle-alert` / `info` / `circle-x`
  （`ls assets/icons`）。

⇒ 端口没有"图标不画"这个缺口。之前那条"已知外观差"记错了方向。

### bug 1：`gpui_shim_notify` 漏了 `hasType`，图标这一支根本没进

`ui/notification.h:46` 是 `bool hasType = false;`，只有 `Notification::WithType()`
（`notification.cpp:46-50`）会置真；渲染侧 `notification.cpp:982` 是 `if (item.hasType)`
才按 type 选 `IconName`。我们的 shim 只写了 `item.type = NotifyTypeFor(type)` ⇒ 门永远不进 ⇒
卡片没图标。**一行**：改 `WithType(...)` 或补 `item.hasType = true`。

### bug 2（P0，比图标严重）：`cppStr(const char*)` 造出一个悬垂 `Str`

```cpp
gpui::Str cppStr(const std::string& s) { return gpui::Str(s.data(), (int)s.size()); }
```
用 `const char*` 调它（`cppStr(title ? title : "")`）会**隐式构造一个临时 std::string**：
libc++ 的 SSO 把短串放在临时对象内联存储里，语句结束临时对象就析构，返回的 `Str` 指向
**已死的栈槽**。这正是我们不变量里"arena 字符串必须拷贝"的同一类，只是这次栽在 helper 上。

在用的站点（`cpp-src/gpui_shim.cpp`）：763 窗口标题、912/913 input placeholder/value、
967 `InputSetValue`、1068/1069 toast 的 title/description。565/566/576/581 传的是成员
`std::string` 左值 ⇒ 安全。1071 在同一句里用掉 ⇒ 安全。

**tree13 的 "succe" 就是它**，机制能算出来：`req.title` 是 `{死栈槽, len=5}`（5 是
"Saved" 的长度），同一条语句序列后面 `cppStr(level)` 的临时对象复用了那个栈槽，于是
按 5 个字节读出 `"success"` 的前 5 个字符。像素与推断一致。

**修法**：加一个 `gpui::Str cppStr(const char* s) { return cstr(s); }` 重载
（`cstr` 已经是 `Str(s, strlen(s))`，不造临时对象）。一处改动覆盖所有站点。

### 待验（写码前先钉）：图标查找在产物里到底通不通

`shim_test.cpp` 直接 `AssetIconFind("circle-check", &len)` / `AssetIconForPath
("icons/circle-check.svg", &len)` 断言非空且 len>0 —— 纯查表，headless 就能跑，不需要画。
渲染那一半只能靠像素：修完 bug 1+2 后重录 tree32（应出现绿色勾/警告三角）与 tree13
（toast 应显示 "Saved" + 描述）。

## 下一波的成本被高估了：Capabilities 不是"要单独设计"

`FsRun(FsOperation, Str root, Str relative, Str input, bool recursive, FsResult*, Str* error)`
（`src/shell/filesystem.h:41`）**自己不看授权表**——`root` 与 `relative` 由调用方给。
`Capabilities`（`src/shell/capability.h:84-135`）只是选根的工具：
`AddReadRoot/AddWriteRoot/SetExecute(ExecuteGrant)/Storage(bool)` +
`ResolvePath(path, access, CapabilityPath* out, CapabilityError* err)`。

⇒ 设计只有这么大：shim 里放**一份进程级 `Capabilities`**，PHP 启动时声明允许的根
（`App::grantRoot(dir, 'rw')`），`fsRead/fsWrite/...` 先 `ResolvePath` 再 `FsRun`。

实测支撑：
- 五个符号在 `gpui.o` 里都是 `T`：`FsRun` / `ProcessRunBounded` / `StoragePersist` /
  `Capabilities::ResolvePath` / `Capabilities::AddReadRoot`（`nm -gU`）。
- POSIX 侧是**真实现**不是桩：`filesystem_posix.cpp` 用 `open(root, O_DIRECTORY)` +
  逐级 `openat(..., O_NOFOLLOW)`（49-66、140、195 行），注释明说"检查与操作是同一次
  保权遍历"，不是字符串前缀判断。`process_posix.cpp` 207 行。
- `kFsMaxReadBytes = 64MB`、`kFsMaxWriteBytes = 8MB`、目录项上限 10000 ⇒ 跨边界要有截断
  语义（`FsResult.bytes` 可能几 MB，得走 `copyBounded` 那套"真实长度与 cap 无关"的契约）。

## Phase 9（mac 集成）两条风险，动手前要先探针

1. **Carbon 全局快捷键的派发**。我们的泵是
   `GpuiAppRunStep` → `[NSApp nextEventMatchingMask:... inMode:NSDefaultRunLoopMode
   dequeue:YES]`（`src/gpui/window_mac.cpp:2309-2320`），**没有** `RunCurrentEventLoop`。
   Carbon `RegisterEventHotKey` + `InstallEventHandler(GetApplicationEventTarget())` 的事件
   走 Carbon Event Manager，这条泵会不会替它派发，是实测问题不是推理问题。
   兜底在我们手里：`gpui_shim_pump` 是我们写的，可以在调 `GpuiAppRunStep` **之前**自己
   `ReceiveNextEvent(0, nullptr, kEventDurationNoWait, true, &ev)` + `DispatchEvent`。
   好消息：handler 与泵同在主线程 ⇒ 入队不需要锁。
2. **OS 通知需要 bundle 身份**。产物是**裸 Mach-O**（`file examples/hello/build/hello` =
   `Mach-O 64-bit executable arm64`，`build/` 下没有 `.app`）。`NSUserNotification` 在没有
   bundle identifier 时通常被静默丢弃。⇒ 这一项要么先做打包（`.app` + Info.plist），要么
   退到 `osascript -e 'display notification'`（能出，但要起进程）。托盘 `NSStatusItem` 与
   Dock 角标 `NSDockTile` 不吃这个约束，可以先做。

### 补正：图标这一路的完整真相（第二次调查核对后）

第一条报告编造了路径、已作废。第二条报告（同一 agent 重跑）引用可核，逐条验后**采纳一半**：

采纳的：
- `AssetIconForPath`（`svg.cpp:1377-1393`）要求**字面形状** `icons/<base>.svg`：前缀 `icons/`
  + 后缀 `.svg` + 中间 base 命中表，三者缺一律 `nullptr`。
- 查不到就**静默什么都不画**（`svg.cpp` 里 `!ops || len <= 0` 直接 bail），没有错误通道。
- `port-status.md:269-270` 承诺"缺 `assets/` 时 `DrawIcon` 的笔画稿兜底覆盖每个 `IconName`"——
  **`DrawIcon` 在整个 `src/` 里不存在**（只在那句文档里出现）。⇒ 上游文档是过期的，坏名字没人接。
- 编译表与磁盘、枚举三方一致（111 个），`home` **不在表里**（`grep -c '"home"' asset_icons.cpp` = 0）。

**⇒ 第三处我们的 bug**：demo 的 tree21 写 `El::icon('icons/home.svg')`——形状对、名字不在表里 ⇒
静默空画，而这张基线已经录成"那里是白的"。改成表里真有的名字（`icons/square-terminal.svg` /
`icons/settings-2.svg`）就能出图，是像素可验的。

不采纳的（两条）：
- 它说 tree13 的 `"succe"` 是"标题被裁切"。裁切保留**前缀**（会显示 `"Sav"`），不会显示成另一个
  串的开头；`"succe"` 是 `"success"` 的前 5 字节、5 又正好是 `"Saved"` 的长度 ⇒ 只有悬垂 `Str`
  这一个机制能同时解释这两个数。它另外说"`Toast::New(cx, id)` 的第二参数只是 a11y id、不渲染"
  ——这条我核过 `toast.cpp:321-325` 与 `root.cpp:691-712`，**是对的**，所以图标位根本不在 shell
  toast 的排版里，与 `"succe"` 无关。
- 它猜通知卡没图标是"`Rems`→`WindowRemSize` 返 0"。实测更前面就断了：`notification.cpp:982` 的门
  是 `if (item.hasType)`，而我们没置位 ⇒ 连选哪个 `IconName` 都没执行到。

**一次误报的代价**：我一度以为 `El::NAV_ICONS = ['home','components','archive','account']` 是假白名单
（磁盘上没有 `home.svg`）。查了 `sidebar.cpp:278-295` 才确认这五个字符串是**上游自己的逻辑名**，
逐个映射到 `SquareTerminal/LayoutDashboard/Settings2/BookOpen/User` 枚举，而这五个都在表里 ⇒ 白名单
是对的，侧栏图标也是好的。**结论：`icons/x.svg` 的 base 与 `navIcon('x')` 的名字是两套命名空间，
不能拿前者去判后者。**

## Phase 10 / 11 实测：两条探针的答案与三处契约意外（2026-10-11）

### 探针 A：我们的泵**不派** Carbon 事件（答案决定实现）

`GpuiAppRunStep`（`window_mac.cpp:2309-2320`）只有
`[NSApp nextEventMatchingMask:... inMode:NSDefaultRunLoopMode dequeue:YES]` + `sendEvent:`，
没有 `RunCurrentEventLoop`，所以 Carbon Event Manager 的队列不会自己排空。
解法在我们自己的调用点：`gpui_shim_pump` 每帧先 `gpui_mac_pump()` ——
`ReceiveNextEvent(0, NULL, kEventDurationNoWait, true, &e)` 取干净再
`SendEventToEventTarget(e, GetEventDispatcherTarget())`。handler 与泵同在主线程 ⇒ 入队不需要锁。

**这条探针是可自动验的**：`gpui_mac_hotkey_fire()` 合成一条 `kEventHotKeyPressed` 发给
application target，PHP 收到 `hotkey` 事件即证明"队列→派发→handler→出口→窗口队列→poll"
整条链通（selftest #19）。它**不**证明系统级全局捕获——那一条留在人工清单。

### 探针 B：OS 通知要 bundle 身份，产物没有

`file examples/hello/build/hello` = `Mach-O 64-bit executable arm64`，`build/` 下没有 `.app`。
`NSUserNotification` 在没有 bundle identifier 时会被静默丢弃 ⇒ `osNotify()` 返回 true 只到
"交给系统"。托盘与 Dock 角标不吃这个前提。⇒ 这一项要等打包器（`.app` + Info.plist）才算真交付。

### 三处契约意外（都进了不变量，都值得下次先查）

1. **读根与写根是两张表**：`Capabilities::ResolvePath` 按 `access` 只查
   `readRoots` 或 `writeRoots`（capability.cpp:471-472）。"能写不能读"的根没有用处，
   所以 `grantRoot(dir, writable=true)` 两张都加。
2. **`FsRun` 的 `recursive` 只对 `MakeDirectory` 生效**：`RemoveDirectory` 就是
   `unlinkat(AT_REMOVEDIR)`，非空目录必失败（`filesystem_posix.cpp:359-371`）。
   递归删我们在 shim 里补了一趟"列目录→逐个删→删自己"，全程只用授权遍历，不碰 ambient 路径。
3. **`Storage` 是实例类不是全局**：`Storage(bool persisted)` + `SetPath/Get/Set/Remove`，
   落盘要 `BeginWrite → StoragePersist → FinishWrite` 三步（`runtime.cpp:1190` 的
   `DriveStorage` 就是这个串）。只 `Set()` 不落盘，重启就没。

### 图标这一路的最终形状（补 Phase 9 的结论）

`IconName` → `IconNamePath` → `icons/<base>.svg` → `AssetIconFind` → drawops 字节码 →
`ExecuteDrawOps`，**没有图标字体**，111 个图标编译期就在 `asset_icons.cpp` 里。
`AssetIconForPath` 要字面形状（前缀 + `.svg` 后缀 + base 命中表），任一条不满足返回空，
渲染侧对空**静默什么都不画**。上游 `port-status.md:269-270` 说的 `DrawIcon` 笔画稿兜底
在 `src/` 里不存在——坏名字没人接。

## CI 从未跑过：一条"写了就等于有"的记录错误（2026-10-11 实测）

被问"最近一次 CI 结果"时查出来的。三条独立证据：

1. `git remote -v` **为空**，`git branch -vv` 显示 `main` 无 upstream，31 个提交全在本地。
2. GitHub 侧没有这个仓库：`gh repo view yangweijie/typephp-gpui` 报
   `Could not resolve to a Repository`，`gh run list` 走 API 得到
   `HTTP 404 ... /actions/runs`。账号下只有 `typephp`、`typephp-qt`、`typephp-tinygui` 等姊妹仓库。
3. `.github/workflows/ci.yml` 的触发是 `push` / `pull_request` / `workflow_dispatch` —— 三者
   都没发生过。**所以没有任何运行记录可看**，不是失败，是未触发。

顺带量清了这个 job 的覆盖面与缺口：它只跑自足的 PHP 层（`php -l` 全源含无扩展名的 `bin/gpui`
+ Pest + PHPStan level 5），原生链（`cmd/test-shim.sh` / `--selftest` / `--shot`）依赖
`GPUI_DIR`，而 gpui-kit-cpp 无 git remote ⇒ 托管 runner 拉不到，这是**设计上的**排除，不是漏配。

本地把 CI 会跑的三步逐个复现：`php -l` 12 个文件全过、Pest 65/296、PHPStan 0 error。
**但这不等于 CI 会绿**——唯一没在本地验的环节是 ubuntu runner 上 `composer install` 能否装齐
`swoole/typephp` 与 `swoole/php-nano`（本机是 macOS，`vendor/` 早就就位，恰好把这个问题掩盖了）。

**记进这里的原因**：Phase 7 的 P1 表里那行 CI 之前是 `[x]`，读起来像"CI 在跑且绿"。文件写进仓库
≠ 流水线执行过。已改成 `[~]` 并补了这段订正。


## CI 首跑失败的两条实测结论（2026-10-11，Phase 12）

### 1. `php-version: 8.3` 与 lock 冲突，CI 一步都没跑到

run `38139715827` 12 秒失败在 `Composer install`：
`phpunit/phpunit 13.4.1 requires php >=8.4.1 -> your php version (8.3.35)`（另有
`phpunit/php-*`、`sebastian/*` 十几条同源）。锁里的 dev 依赖整体要 `>=8.4`。
选 **8.5** 而不是 8.4 的理由是实测出来的：`swoole/typephp v0.9.4` 与 `swoole/phpx v2.9.3`
都写 `"php": ">=8.4 <8.6"`，开发机是 8.5.7 ⇒ 8.5 是唯一同时满足"跑得过 lock"与"和生产端一致"的。

### 2. `composer.lock` 的 dist URL 被烤成了国内镜像（潜在风险，不是已发生的失败）

三个 `swoole/*` 的 `dist.url` 全指向
`https://mirrors.cloud.tencent.com/repository/composer/...`，因为它们是在开发机的全局配置
`repositories.packagist.org.url = https://mirrors.cloud.tencent.com/composer` 下解析的。
lock 里**没有 source 记录**（`source_url = -`），所以 `--prefer-source` 这条路是断的。

CI 的 ubuntu runner 会用 lock 里那个绝对 URL 下载。三个包在真 packagist 上都有
（`repo.packagist.org/p2/swoole/{php-nano,typephp,phpx}.json` 全 HTTP 200），所以真出问题时的
修法是**只重写 dist URL**（换掉全局 mirror 后重装 lock），不需要动任何版本号——记在这儿，
免得下次把它当成"包不存在"去改依赖。

## "本地托管后能不能不要防火墙、降成 C++17？"——实测：不能，而且这两件事不同轴

### 先把两件事分开

托管位置管的是**源码从哪来**（CI 能不能拉到、版本怎么钉）；防火墙管的是**同一个编译单元
里两种语言要求共存不下**。把上游搬进自己的 git 服务，不会改变任何一条语言约束。

### 实测数据

1. **tpc 硬编码 C++17**：`vendor/swoole/typephp/src/Build/NativeSourceProjectBuilder.php:104`
   写的是 `-std=c++17`（C 源码才用 `-std=c11`）。这不是我们的 yml 选项，是编译器自己的常量。
2. **gpui.h 在 C++17 下编不过**。做了决定性实验：一个只 `#include "gpui.h"` 的 TU，
   `clang++ -std=c++17 -fsyntax-only` → **20 个 error**，真实成因只有几处：
   - `gpui.h:6960` `concept`（关键字都不认）与紧随的 `requires`
   - `gpui.h:7017` `requires` 子句 + 由此 cascading 的"模板偏序参数不可推导"
   - `gpui.h:6480` `chosen constructor is explicit in copy-initialization`（C++20 放宽的
     拷贝初始化规则，C++17 不认）
   - amalgam 里另有 `concept EmitsEvent = requires {...}`（`.work/gpui.h:10773`）
   合计约 6–8 个真实站点，其余是解析器连锁报错。

### 所以三条路各自的代价

- **A. 只托管、防火墙照旧**（推荐）：CI 从此能跑 shim_test / selftest / shotdiff 三道原生门禁。
  零语言层风险。
- **B. 打第 8 个补丁把 concept/requires 降级成 C++17 SFINAE，让 bridge 直接 include gpui.h**：
  技术上可行（就那几处），但换来的是：① 每次上游动一次模板就要重打一次补丁；
  ② bridge TU 要吞 54K 行头文件，改一行 PHP 就重编一次；③ 还要顺手改
  `NativeSourceProjectBuilder.php:104` 那个常量（多 patch 一个编译工具链）。
  **省掉的只是 C ABI 样板，赔进去的是隔离性与构建时间**——不划算。
- **C. 整体升 C++20**：nano 生成的 phpx 胶水是 C++17 形状，动它等于改 TypePHP 本身。

### 一个顺带澄清

gpui.h 里 ObjC/AppKit 只出现在**注释**（`NSWindow` 1 处命中在 46115 行是文字说明），
所以"bridge 需要 `-x objective-c++`"并不是防火墙存在的理由；真正的理由就是上面那三条
语言约束。别把这条当成反对 B 的论据。

## 第 8 个补丁实测：gpui.h 降到 C++17 可解析（20 error → 0），但防火墙的理由要改写

### 做了什么

`cmd/patch-concepts.sh` 打在 `src/gpui/gpui.h` 上，7 处：
1. `concept EmitsEvent = requires { sizeof(EventEmitter<T,E>); }` → 偏序特化 trait
   `EmitsEventImpl<T,E, decltype(sizeof(EventEmitter<T,E>), void())>` + `inline constexpr bool EmitsEventV`。
   判据不变：主模板只有声明 ⇒ 不完整类型 ⇒ `sizeof` 硬错误 ⇒ 替换失败退回 `false_type`。
2. 5 个 `requires EmitsEvent<T,E> <Ret> fn(...)` → `typename std::enable_if<EmitsEventV<T,E>, Ret>::type fn(...)`。
   用**正则**匹配而不是整串：上游这 5 处换行/缩进各不相同，整串匹配一改排版就静默失配。
3. `Window::paint` 的 `PaintCtx paint = {};` → `PaintCtx paint;`。真凶在下一层：
   `PaintCtx` 的成员 `Vec<HitRect> hits;`，而 `Vec` 的默认构造是 `explicit`（`base.h:576`）。
   C++20 允许省略初始器时走 explicit 默认构造，C++17 判 "chosen constructor is explicit in
   copy-initialization"。写成 default-init 后成员走**直接**初始化，同一条构造、同样的初值。

### 实测数字

| 阶段 | 一个只 `#include "gpui.h"` 的 TU，`-std=c++17` |
| --- | --- |
| 补丁前 | **20 error** |
| 只降 concept+requires（6 处） | **1 error**（`gpui.h:6480` 的 explicit copy-init） |
| 再降 PaintCtx（7 处） | **0 error** |

门禁 `cmd/check-cxx17.sh`：同一份探针在 **c++17 与 c++20 下都要过**（`-Werror`），
且 `EmitsEventV` 断言**正反两个方向**（有特化为真、无特化为假）+ 5 个 `enable_if` 站点
取地址能实例化。只验"有特化为真"的话，把判据写成恒真也照样绿——这就是那种能骗过自己的测试。

### 两个必须说清的保留意见

1. **0 error 不等于严格符合 C++17**。头里有 **87 处**位域默认成员初始化
   （`uint32_t hasBg : 1 = false;`），clang 报 `-Wc++20-extensions` 后放行。所以"bridge
   可以直接 include gpui.h"这句话只对 clang 成立，换编译器不成立。
2. **防火墙不该拆，但理由变了**。它以前买的是"唯一可行的解法"；现在买的是：bridge TU
   不必吞 54K 行头文件（否则改一行 PHP 就重编 amalgam 级的大单元）、我们自己的
   ObjC/ARC 代码必须有独立 TU、C 契约让上游改动不至于直接打断桥接层。
   顺带纠正我自己之前的一条错论据：gpui.h 里 `NSWindow` 只出现在注释，所以
   "需要 `-x objective-c++`"**从来不是**防火墙存在的理由。

### 副作用：补丁会随上游漂移，所以配了门禁

补丁打在 vendored 头文件上，上游一刷新站点排版就可能变，perl/python 静默不匹配 →
"打了补丁但其实什么都没做"。因此：脚本里任何一处没匹配到就**整体失败并回滚**，
外加 `cmd/check-cxx17.sh` 进 CI 的 native job。marker `EmitsEventV` 也进了
`GPUI.lock` 与 `patchManifest()`，doctor 现在校验 8 个补丁。

---

## Phase 14 · showcase：nano 下的"引用式状态"是死路（2026-10-11）

写 `examples/showcase/` 时把可变状态做成 `wireShowcase(App $app, array &$state)` +
闭包 `use (&$state)`，两次构建各撞一堵墙，且**第二堵墙要跑起来才看得见**：

| 写法 | 结果 | 时机 |
|---|---|---|
| `array &$state` 形参 + `use (&$state)` | `Cannot create a reference to variable $state of fixed type php::Array` | 编译期 fatal（`TypeConversionTrait.php:325`：只对 Type::VAR/REF 放行） |
| 去掉类型声明 `&$state` | **编译过了**，运行时 `A temporary typed reference cannot escape a dynamic call` → SIGABRT(134) | 运行期，`RefWrap<php::Array>::commit()`（`vendor/swoole/phpx/include/typephp_helper.h:124`） |

定位靠 lldb，不是靠猜：`lldb --batch -o "process handle SIGABRT --stop true --notify false"
-k "thread backtrace" -o run -- ./showcase --shot /tmp/x.png 1`，栈顶是
`php::RefWrap<php::Array>::commit()` ← `php_runshot()`，一眼指到"把局部数组按引用交给函数"。

**为什么 hello 没事**：它的 `$state` 是 `main()` 里的**局部变量**，`use (&$state)` 在同一
函数作用域里，不跨"动态调用"这条边；而事件 handler 是 `$handler($event)` 动态调用的，
引用一旦跨这条边就得走 RefWrap 慢桥。

**结论/做法**：共享可变状态放**静态表**（`ShowcaseState::$v['k'] = ...`），闭包既不捕获引用、
形参也不取引用；builder 每帧读快照（`ShowcaseState::get()`）。这条同时消掉了两个错误，
也让 `--selftest`/`--shot`/交互三模式共享同一份状态而不用传参。

## Phase 14 · 覆盖率门禁得先证明它自己会红

`tests/ShowcaseTest.php` 反射 Element/App 的全部公开成员并要求被真调用。第一版就绿≠有效：
两个反向锚点必须做。

1. **匹配器写错会"全红"**：`token_get_all` 重组时代码在每个 token 后补了空格，正则却写成
   `/(::|->)name\s*\(/`（操作符与名字之间不容空白）→ 61 个工厂全部"没被调用"。
   修成 `/(::|->)\s*name\s*\(/` 后才从 61 缺降到真的 11 缺。
2. **正向锚点（会红吗）**：临时把 `El::breadcrumb(` 与 `->onKeyDown()` 改名，门禁精确点出这两个
   名字并变红；改回来（md5 与备份一致）再绿。没有这一步，"6 passed" 完全可能是恒真。
3. **枚举取值要区分"字面量传过"与"整表遍历过"**：`foreach (App::FPS_ANCHORS as $a)` 是真把 8 个
   锚点都传了，但源码里没有 8 个字面量。所以规则改成：取值以字面量出现 **或** 该常量被
   `foreach (X::NAME …)` 整体遍历；只认 foreach，不认 `X::NAME[$i]` 取样（那不算覆盖全部）。
4. **注释不算数**：匹配前先 `token_get_all` 剥掉 `T_COMMENT`/`T_DOC_COMMENT`，否则在文档里
   写一句 `El::div()` 就能骗过门禁。

## Phase 14 · "PHP 全绿"与"nano 全绿"之间差一个 arity 检查

`El::border()` 是**无参**糖（`paramStyle('border', [1.0])`），我在示例里写成 `->border(1, 0xCCC6BD)`。
- `php -l` 过；
- Pest/FakeBridge 全绿（PHP 允许给函数传**多余**实参，静默忽略）；
- 只有真跑原生二进制才 `ArgumentCountError: Element::border() expects exactly 0 arguments, 2 given`。

同理 `->flex(1)` 也是无参方法被当带参用。这类错**每个都要一次 90 秒的原生构建才发现**，
所以把它下沉成静态门禁：`tests/ShowcaseTest.php` 用 `nikic/php-parser`（typephp 自带依赖）
解析两份示例源码，收每个方法名的实参个数，与反射得到的 `[required, total]` 比对；
同名方法在两个类里都存在时（`text`/`checked`），只有**所有**同名签名都不接受该 arity 才算错。

### 手搓的 token 扫描器差点把门禁做成假的

第一版 arity 门禁是自己数括号逗号写的。反向锚点（把 `->border()->borderColor(...)` 改回
`->border(1, ...)`）**没有变红**——因为扫描器遇到链式调用就漏：159 个方法名只认出 12 个，
`border` 根本不在结果里，于是"没有违规"是"什么都没看见"的别名。换成真 AST 后立刻抓到
`flex(1)`（这条是我修 border 时引入的第二处同类错）。

反向锚点矩阵（每条都要"能红"才算门禁有效）：
| 断言 | 破坏方式 | 结果 |
|---|---|---|
| Element 工厂覆盖 | `El::breadcrumb(` 改名 | 红，精确点名 `El::breadcrumb()` |
| Element 链式覆盖 | `->onKeyDown()` 改名 | 红，精确点名 |
| 枚举取值 | 少写一个 `'xsmall'` 字面量 | 红（首版还误判过 foreach 型覆盖） |
| arity | `->border(1, 0xCCC6BD)`、`->flex1(3)` | 红，两条都点名 |
| 孤儿 handler | `on('in-key')` 写成 `on('in-key-typo')` | 红 |

### 顺带的一条 nano 约束（已进记忆）

示例的"当前节号/计数"最初是 `wireShowcase(App $app, array &$state)` + 闭包 `use (&$state)`：
声明 `array` 引参 → **编译期** fatal；去掉类型声明 → **运行期** `RefWrap<php::Array>::commit()` abort。
最终改成静态表 `ShowcaseState::$v`，形参与捕获都不取引用。lldb 的 `-k "thread backtrace"` 是
这类"只有运行时才炸且不带消息"的唯一定位手段（`-o` 系列命令在 stop 后不执行，要用 `-k`）。


---

## Phase 14 追加 · 用户实测把两条"绿"打回原形（2026-10-11 晚）

用户跑交互版反馈：左侧导航点了没反应、hover 无效、输入框无法聚焦打字。三条都不是示例的错，
是框架的真实输入路径从来没通过——而七道门禁全是绿的。

### 缺陷 1：SpecOp 的回调名被剥了前缀 ⇒ 监听器根本没挂

- bridge `eventType()` 把 `on_click` 削成 `click`（为了对齐 PHP 的 `on(id,'click')`），
  然后 `gpui_shim_callback()` 把这个**短名**直接写进 `so.name`。
- 而 materialize 的 `ResolveBehavior` 逐字比较 `op.name == "on_click"`（materialize.cpp:233-290）。
  名字对不上 → `behavior.onClick/onHover/onKeyDown` 永远是 0 → gpui 从不注册监听器。
- 为什么门禁没抓住：自检的事件走 `gpui_shim_test_event`，它按 callbackId 直接
  `box->enqueue()`，**绕开 behavior 解析**。合成缝能证明"路由与回调能回到 PHP"，
  不能证明"元素挂上了监听器"。
- 修法：两套名字并存——队列里保留短名（路由键），`so.name` 补回 `on_` 前缀。

### 缺陷 2：`El::input('x')` 把 id 写进 text ⇒ 输入框静默降级成 Div

- bridge 按 `node['id']` 建 retained（gpui_bridge.cc:149,177），而 `input()`/`textarea()`
  把参数塞进 `$e->text`。于是 retained 建不出来，materialize `return Div(cx->a)`。
- 现场：不聚焦、不能打字、点它没事件，而 `registeredError()` **是空的**——静默降级。
  hello 一直用 `El::input()->id('name')`，所以从没碰到。
- `number_input`/`otp_input` 同病：bridge 的 retained 清单里根本没有它们。
- 修法：`input()`/`textarea()` 改写 `->id`；bridge 的 retained 清单补 `number_input`
  （复用 Input retained）与 `otp_input`（新增 `CreateRetainedOtp` 转发口，第 9 个补丁标记）；
  缺 id 或建不出 retained 时 `gpui_shim_set_error()` 当场记下，让 registeredError() 能红。

### 新增的验收：只认真实路径

`App::realClick/realMouseMove/realKey` 把事件交给 `-[NSWindow sendEvent:]`（AppKit 自己做
hit-test 与 focus 迁移），返回值 = "PHP 队列确实多了一条事件"；`inputProbe()` 读
`win->paint.inputs`（gpui 在 paint 里给真 Input 登记 inputBounds，gpui.cpp:7815）。
`showcase --selftest` 现在断言：点导航能换节（7 次命中、跨 ≥2 节）、hover 打中 `pr-hover`、
点输入框后 `realKey` 能把字符送回 `in-name`、`inputProbe()['n'] > 0`。

### 两条踩坑记录（流程层面，值得记住）

1. **合成缝的绿 ≠ 真路通**。凡是"我发个事件给它"的测试，都要问一句：这条缝跳过了生产路径的
   哪几步？这里跳过的正好是唯一会暴露 bug 的那步（监听器挂载）。
2. **幂等补丁要按符号各自判**，不能拿"整块标记在不在"当闸门：upstream/ 里其实已含早先的补丁
   （`72a021c` 把补丁后的文件提交了进来），整块闸门一开就把新符号插到旧声明中间，
   amalgam 直接编译不过；而 `grep -q MARK` 这种判据在"半补丁"状态下会静默放行。
   现在改成：每个符号按**完整声明文本**查在否、不在才插、结尾自检每个符号恰好出现一次。

### 反向锚点：新加的三条真输入断言各自能红吗

| 断言 | 破坏方式 | 实测 |
|---|---|---|
| `realClick` 能换节 | 把 `so.name` 退回短名（复现原缺陷） | 红：`navHits=0`、`firstNav=0→0`、`realInput=false/…` |
| `realMouseMove` 打中 hover | 同上 | 红：`hoverMoves=0`、`hoverAt=0,0` |
| 输入框是真 Input | `El::input('x')` 把 id 放进 text（修复前的原状） | 红：`probe=0@0,0 0x0`、`typedIn=false` |
| 打字能回 PHP | 同上 | 红（同一轮自然观察到） |

一个**必须写下来的细节**：退回短名那一轮，`typedIn` 依然是 true。原因是 Input 的 change 事件
走的是 retained 直连路（materialize 里 `state->onChange = Listen(cx, &ScriptView::OnInputEvent,
retained->id)`），不经过 `ResolveBehavior` 的回调名匹配。所以"能打字"证明的是"输入框是真的"，
**不能**当作"监听器挂上了"的证据——那条只有 click/hover 说得清。

## 发现：裸二进制 vs bundle —— macOS 的前台身份决定真输入是否落地（Phase 14c）

- 判据是**系统事实**，不是我们的代码：`[NSBundle mainBundle].bundleIdentifier` 为空 ⇒ 进程不是
  LaunchServices 眼里的正规前台应用 ⇒ `NSApp.isActive` 起不来 ⇒ 窗口不是 keyWindow。
  这时手点的第一下被当作"激活点击"吃掉，`keyDown` 没有 firstResponder 可去。
- A/B 用同一颗二进制（bundle 里是 symlink），只差 bundle 身份：
  裸 `startup active=false key=false main=false`；bundle `active=true key=true main=true`。
- 第二道前提：**有人在 `NSDefaultRunLoopMode` 泵 `nextEventMatchingMask`**，激活请求才落地。
  gpui 的 `GpuiAppRunStep`（build/gpui-kit-cpp/src/gpui/window_mac.cpp:2314、2341）本来就泵，
  但脏窗口的等待时间是 `distantPast`，所以"调了 activate 却永远不 active"在过去出现过。
- 由此得出一条通用验证纪律：**验收缝不能要求同步见效**（真路由 postEvent 后要等泵），
  否则正常链路会被读成假阴性；本轮就先误判过一次"bundle 里 realInput=false"。
- 另一条：**门不能用它要验的状态当条件**。以 `isActive` 为前提的门会被测试缝自己的泵副作用
  撑成虚绿；换成 bundle 身份这种独立事实，负控才真能红。

## 发现：macOS 前台是"竞争态"，不是进程属性（Phase 14c-2）

`NSApp.isActive` 取决于启动那一刻谁握着焦点，以及 OS 是否允许这个进程抢：从后台 shell 起的
GUI 进程会被正当拒绝，即使有 bundle 身份、即使 `TransformProcessType` +
`activateIgnoringOtherApps:` + `SetFrontProcessWithOptions` 全用上（实测 24 轮重试仍
active=false）。所以任何"以 frontmost 为硬断言"的门都会随机红。可保证的只有产物属性
（bundle 身份）与"焦点可得时走的是不是真路由"（nsappDeliveries）。
另：`acceptsFirstMouse:` 在 vendored 的 view 里已经返回 YES（window_mac.cpp:589），
所以"非 key 窗口第一下点击被吃掉"这个假设作废——不需要加补丁，鼠标本来就能落地；
真正需要 keyWindow 的是 **keyDown**（打不进字那半边）。

## 发现：gpui 首帧的 materialize 建不出命中表（Phase 14c-3）

- 证据（`examples/showcase --liveclick`，view 每帧重建、然后真点击扫导航列 38 个采样点）：
  只推进 1 帧 → **0** 个事件；推进到第 2 帧起 → 稳定 18 个事件、`nav-1..nav-9` 真的到达 PHP。
- 同期否掉的解释：`paint.hits` 条数在重渲染前后都是 18（不是"清了没重建"）；按 callbackId 的
  合成缝能命中（监听器在）。缺的只是**第一帧的命中矩形**，画面却是画好的——这正是
  "界面看着正常、点了没反应"的机制。
- 处置：`App::view()` 注册时立即 materialize 一遍（`run()` 同路），第一帧就把表备齐。
  回归位是 `--liveclick` 的退出码（首帧 0 事件即 rc=1），已进 CI。
- 方法论：同一异常在 selftest 的畸形时序里出现（连续 25 次点击把侧栏滚动）不能推断用户路径，
  所以要为"用户实际处境"单独造一条 harness，再谈结论。

## 发现：悬停事件被钥匙窗口门槛拦住（补丁 cmd/patch-hover-always.sh）

`window_mac.cpp` 的 tracking area 用 `NSTrackingActiveInKeyWindow` —— AppKit 只在窗口是
keyWindow 时才投递 `mouseMoved`。含义是"hover 反馈"会随焦点状态消失：未激活的应用、或者用户
在别的 app 里打字而鼠标悬在我们窗口上，`on_hover` 一条都收不到。改成 `NSTrackingActiveAlways`
（`cmd/patch-hover-always.sh`）后，悬停不再要求焦点归属。
注意这条**无法用本地门禁证明**：app 内的合成缝（`-[NSWindow sendEvent:]`）不受该门槛约束，
`frontmost=false` 时 `hoverRouted` 依然为真；能证的只有真人把鼠标移到未激活窗口上。

## 发现：逐颗输入框真打字 = 8/8 打得进；顺带炸出一个 key_down 段错误（Phase 14c-5）

- 新读数（`examples/showcase --livetyping`，用 `App::inputProbe($index)` 逐索引取框，点中心、
  按一个真实 '1'，看那颗有没有发 change）：`tree2:3/3 tree7:5/5`，合计 **8/8**。覆盖
  input / textarea / number_input / catalog 字段。这正面回答了"输入框也无法输入"：
  在 PHP 宿主这条链上，每颗被点开的框都收下了键。
- 同一条探针当场炸出一个真缺陷：第 2 节 `in-key`（唯一挂 `onKeyDown` 的框）点击正常、change 也发，
  但**一按真实键 SIGSEGV**。崩溃报告栈：
  `JS_NewObject ← ShellRuntime::DispatchKey(runtime.cpp:12194) ← ScriptView::OnScriptKey ←
   DispatchKeyChain ← WindowKeyDown ← -[GpuiView keyDown:]`，KERN_INVALID_ADDRESS 0x100000058。
  `impl->context` 在鼠标路径上同样的用法不出事，说明 key 这条路拿到的 runtime/ctx 不是 root 那个。
  为什么以前没暴露：hello 没有元素级 key_down，21 条合成链路走 `gpui_shim_test_event` 绕过
  DispatchKey——和 `on_` 前缀那次是同一类盲区（合成缝照不到真派发链）。已建任务 #53。
- 探针自己的两次错：正序跑在崩点之后什么都测不到（改倒序）；`$allOk` 忘了初始化，
  未定义变量被当 false → 8/8 却 rc=1。
- 门禁：`--livetyping` 已进 CI；跳过 `in-key` 是**写明跳过**，不计为通过。

## 真实鼠标：gpui 命中表全绿，仍然可以点不动（2026-10-12）

- 新探针 `App::hitProbe($i)`（`gpui_shim_hit_probe` → bridge → stub → App/FakeBridge）读的是
  点击真的会查的那张表 `paint.hits`：每条 `x/y/w/h`、`id`、`pathId`、`click`(有没有挂监听)、
  `parent`(派发链走它)。加它的理由：按 y 扫屏幕猜行位置，会把"表没登记 / 坐标换算错 /
  监听没挂 / 表外有东西挡着"四种错伪装成同一个症状"那一行点不动"。
- 实测第 11 节（WebView 与 Inspector）渲染过一次之后，导航只剩下面 7 行点得动，而**表里 13 条
  矩形、`click=1` 全在**——错在表外：`ChildView` 背后那颗 WKWebView 是常驻 NSView，元素换掉后
  它仍挂在窗口上、停在最后一次的矩形里，AppKit 的 `hitTest` 先命中它。
  `WebViewSetVisible(false)`（macOS 侧 `hidden=YES`）就能让开鼠标；已按帧收放
  （`WebViewFrameVisibility`，materialize 前清 `seen`、后据此收）。修后 13/13。
- 一次运行内多点重复量（同一套动作在流程各阶段各量一次）是这次定位的主力手段：
  13/13(建窗) → 13/13(换一次根树) → 7/13(13 节全渲染) → 逐节量精确到第 11 节。
  比猜"是 HUD 还是剪贴板"便宜，而且读数不留歧义。
- 两条会把人带偏的读数，记下来：
  1. `realClick` 返回"队列增长了"**不等于点中了**——同一坐标的 hover 事件也满足它
     （y=100 那次"命中"其实是 hover，节号没变就是线索）。断言要看"到达几个不同节号"。
  2. 点在窗口 resize 边（内容区之外）时，`-[NSWindow _handleMouseDownEvent:]` 会走到
     `_mouseHysteresisCheck: → nextEventMatchingMask` **等下一个鼠标事件而不返回**；
     注入式探针一次只投一对 down/up，于是像死循环。`sample <pid>` 抓一次栈就能定性——
     这次就是靠它把"扫描放宽导致挂住"这个错误归因翻掉的（真因是探针采样越界）。


---

# 全库审核（2026-10-12，五路并行审查 + 主线逐条复核）

方法：安全 / 性能 / 质量 / 架构 / 门禁完整性五路只读审查并行跑，回来的每条 P0/P1 由主线
自己读码或实跑复核过才记在这里；没复核的一律标「未复核」。审查范围不含 upstream
（vendored 1184 文件），但引用它作证据。

## P0（复核过）

| # | 位置 | 事实 | 复核方式 |
|---|---|---|---|
| 1 | `git ls-files` + `bin/gpui:162` + `ci.yml:165-183` | **当前跟踪的树克隆出来构建不了**：`cmd/patch-{key-view,hover-always,host-application}.sh`、整个 `examples/showcase/`、`tests/ShowcaseTest.php`、`tests/baseline/showcase/*.png` 全是未跟踪，而构建按名调那三个脚本、CI 跑 showcase 四条门禁 | 逐文件 `git ls-files --error-unmatch` → 8 个全 tracked=N exists=Y |
| 2 | `cpp-src/gpui_shim.cpp:897-900` | 建窗失败路径**双删**：`EntityDrop` 的 deleter 已 `delete PhpView`（`EntityNewRaw` 注册的 lambda），紧接着又 `delete view`；`box->shellRoot` 在这条路上也从不 drop | 读 :853-857 的 deleter 与 :897-900 对照 |
| 3 | `src/Element.php:261,269,277,294` ↔ `cpp-src/gpui_bridge.cc:149,294` | `table/list/tabs/textView($id)` 把 id 写进 **`text`**，而回调路由取的是 **`node['id']`** ⇒ 这四类元素挂 `->onClick()` 注册的是空 id，事件到不了 PHP。与上一轮修的 `setAppMenus`（`label`/`id` vs `name`/`action`）同一 bug 家族，**还没修完** | 读四个工厂 + bridge 两处取键 |
| 4 | `cpp-src/gpui_bridge.cc:627-631` | `php_gpui_element_value` 是带 `// Phase 4` 标记的空壳返回 nil ⇒ `App::checked()` **恒 false**；FakeBridge:506 照抄，四层一致地坏。消费者：`showcase/main.php:405,408`（`agree` 永不为真、`in-dark` 恒 `light`） | 读函数体 |
| 5 | `bin/gpui:134-146` | **`doctor` 永远不会红**：`verifyPatches()` 返回值被丢、case 里没有 `exit`。实测 `GPUI_DIR=/tmp/definitely-not-here php bin/gpui doctor` 打 12 条 ✗ 而 **rc=0**。附带 `bin/gpui:78` 的全角冒号使 `$script：目标文件缺失` 被解析成一个变量名 → PHP Warning，缺文件的诊断丢了脚本名 | 实跑，看 rc |

## P1（复核过）

| # | 位置 | 事实 |
|---|---|---|
| 6 | `cpp-src/gpui_shim.cpp:402` | `kindFromString` 兜底 `return K::Div`：新 kind / 拼错 → 静默装成 div，**没有一层报错**；`tests/cpp/shim_test.cpp` 还断言这个兜底，等于给上游漂移上保险 |
| 7 | `composer.json` ↔ `bin/gpui:162` ↔ `GPUI.lock` | 补丁清单三处不一致：composer **8** 支、bin/gpui **11** 支、lock 文案 **7** 支。`composer patch` 单独跑 = 半补丁树，缺的正是 `patch-key-view.sh`（防 key_down 段错误那支） |
| 8 | `cmd/patch-host-application.sh:5` ↔ `bin/gpui:67,162` | 脚本头部写着「⚠️ 当前**未接线**…启用它会偶发段错误 rc=139」，而它**正在被跑**。构建路径上的注释在说谎 |
| 9 | `cmd/shotdiff.php:58-60,116-119` | 视觉门自投「陈旧产物」坑：`$tmpDir` 永不清理、`exec()` 不收退出码、只判 `is_file` ⇒ `--shot` 崩在半路时拿**上一次的 PNG** 判 ✓；`record` 盲拷，能把坏帧洗成基线 |
| 10 | `src/FakeBridge.php:1029,873-882,742,642,387-434` | 假桥与真桥语义漂移 5 处：`process_run` 恒 `code=0`、授权按**词法前缀**（`/tmp/../etc/passwd` 在假侧放行，真侧是逐层 `openat`）、`notify` 恒成功且不夹枚举、`http_response` 恒 `ok=true/200`、`window_state`/`component_failure`/`hit_probe`/`input_probe` 恒 0 ⇒ 真侧安全门与全部读数门在 Pest 里 0 覆盖 |
| 11 | `cpp-src/gpui_shim.cpp:2323-2330` | 存储路径**不受根约束**：`gpui_shim_storage_path` 不查 `HasStorage()`/授予根，而同族 `fs_read:2334` 走 `Resolve(...Read...)`。授权一旦给到，存储文件可落在任意路径（未复核上游 `Storage::SetPath` 是否自带门） |
| 12 | `src/App.php:917-922` | 每帧**无条件**重建：空转窗口 ~60 次/秒跑完整条 PHP 树 + `toArray()` + materialize。这是其余每帧开销的乘数（未复核：改事件驱动的可行性） |
| 13 | `cpp-src/gpui_shim.cpp:1033,353-403,132-139` + `gpui_bridge.cc:250-265` | 每元素每帧：字符串**两遍** arena 拷贝（`AStr` 之后 `CopyComponent` 再 `StrDup`）、`kindFromString` 至多 46 次串比较、回调 ~5 次串分配 + 2 次哈希查表、bridge 每 op 2 串 + 每参数 op 2 个 vector |
| 14 | `cpp-src/gpui_shim.cpp:1231-1243,1266,1458,992,1188-1203` | 生命周期只增不减：dialog/sheet 的 `Box2` 关闭时不回收、`g_views` 键不 erase（`boxForView:2122` 可能把已释放的 Box2 交给回调）；`timerKeys` 每次 `setTimer` 追加一条永不删 |
| 15 | `cpp-src/gpui_shim.h:42-48` | 事件缓冲上限没写进契约：`id[128]`/`vstr[256]` ⇒ 长 id 静默不路由、>255B 的输入值被截在 UTF-8 中间。stub 与 `App::on` 都没提 |
| 16 | `.github/workflows/ci.yml` | CI 缺：像素基线比对、`doctor`、bundle 起产物的那条路、真输入路径。`bundleGreen`/`routeGreen` 在 CI 里是空蕴含（`main.php:1243-1244`） |

## P2（挑要紧的记）

- `tests/ShowcaseTest.php:231` 调**不存在**的 `treeIds()`：某一节真渲染成空树时，门是以「Call to undefined function」的形式炸，报不出是哪一节；平时短路过去，静默。
- `App::setAppMenus` 返回值是入参的回声（`gpui_bridge.cc:607` 返回 `menus.count()`，即使 `gpui_shim.cpp:1275` 早退）；`hello:249` 与 `SystemTest:543` 都拿它当断言 ⇒ 装失败也绿。
- 假参数：`Element.php:249 slider($min,$max,$value)` 只用 `$value`（bridge 硬编码 0/100）、`:233 image($src,$alt)` 丢 `$alt`。
- 错误通道四套并存且语义交叉：`lastError`（每窗口）/`registeredError`/`componentFailure`（全局）/`fsError`（**静态**，多窗口互串）。
- `php-src/gpui.stub.php:230` 两条声明挤在同一行（`…{}function gpui_hotkey_count…`）——机器读的契约里格式已经坏了一处，也证明没有 stub↔fake↔bridge 的 parity 门。
- 文档计数陈旧：`docs/guide/testing.md:3`「6 道门禁」、`GPUI.lock:14`「7 个宿主补丁」、`gpui_bridge.cc:58` 还挂着 Stage C/D 的旧计划标记。
- `task_plan.md`/`findings.md`/`progress.md` 已 224 KB（README 9 KB），且 `findings.md` 这份审核还在往里加。
- 注释与代码对不上若干处：`App.php:133-142` 记 7 个 windowState 键、stub 记 5 个；`showcase/main.php:654` 说 keycode 105 是 F7（其余三处都写 F13，F13 才对）。

## 判为「真门」（能红，且红过或机制上必红）

`cmd/check-cxx17.sh`（`static_assert` 双向、无跳过路径）· `cmd/test-shim.sh:24-29`（缺 `.o` 硬 `exit 1`）·
`--selftest` 的 `navProbe afterAll`（撤修复实测 7/13 红）· `menuRowsOk`（键名改错即 `rows=` 空 + FAIL）·
`--liveclick firstFrameSent`（修前 0/38）· `shim_test.cpp:53-62`（AStr 真的覆写源缓冲再断言副本）。

## 门禁的绿色类别名（按项目自己的规矩）

`--liveclick` / `--livetyping` / `--selftest` 的真点击真打字是 **route-green**（进程内 `NSEvent` +
`Deliver` 给自己的窗口），**不是** real-OS-input-green；后者目前只有 `progress.md` Phase 18 那一次
电脑操控 MCP 会话（且 `cmd/real-input-probe.py` 未跟踪、未进门禁）。`navBandWide` 用 `realClick`
返回值当"这一行点得动"的证据不成立——那个返回值字面就是"队列涨了"（`gpui_shim.cpp:1918-1937`），
hover/定时器也算。

---

# 对齐 gpui-kit.com/docs 与 gpui-kit-cpp（2026-10-13）

参照物两处：**上游文档站**（38 个概念页 + 组件画廊 80 个名字）与本机
`/Volumes/data/git/c/gpui-kit-cpp`（我们真正链接的那棵树）。
判定口径：一个能力只有当 PHP 能发出**上游认得的符号**时才算实现；上游对不认的名字
分两种下场（见第 5 条），所以"没发"与"发了但没生效"必须分开列。
所有数字来自脚本抽表（`/tmp/upstream-catalog.php`、`/tmp/gap-report.php`、
`/tmp/parity-check.php` + 两侧 `sort/comm`），不是人肉数出来的。

## 1. catalog（Registered）面：137 个导出名，PHP 只开了 21 个门

上游 `src/component_shell/**/*.cpp` 里的 descriptor 有**两种形状**：一文件一个
`constexpr ComponentDescriptor kX = {…}`，以及家族表 `constexpr ComponentDescriptor kX[] = { {…}, {…} }`。
第一版脚本只认前者，**少算了 28 个组件**（Carousel / Calendar / Questionnaire / TimeField /
ColorPicker / Empty 整批都不在结果里）——是拿文档站的组件名单回查才发现抽漏了。
补上家族表后的权威数：**126 个组件 / 137 个构造器导出名 / 301 个方法名**。

我们侧 `Element.php` 实际发出的导出名 **21 个**，而且 `Element::reg()` 是 **private**
（`src/Element.php:517`）、没有任何公开的通用入口 ⇒ 用户连"手动发一个没写工厂的组件"都做不到。

差的 116 个导出名分四桶：
- **35 个**有同名 kind（Button/Checkbox/Table/Tabs/Select/Image/Popover/…）——见第 3 条，
  这**不是**"同一组件的两张脸都齐了"，两张脸的能力不等价。
- **65 个**既没 catalog 也没有同名 kind，且实参不需要上游 entity 句柄 ⇒
  **加一个通用工厂就能通**（C ABI `gpui_shim_registered(win, export_name, …)` 本来就按导出名通用，
  **不用动 shim**）。名单：Accordion 家族、Pagination、Rating、Stepper/StepperItem、TabBar、
  Settings 四件套、Tree/TreeItem、Separator 三件套、Badge、Tag、Label、Kbd、Toolbar、StatusBar、
  Skeleton、Spinner、ShimmerText、Chat 家族（Bubble/Marker/Message/Attachment）、Empty 六件套、
  InputGroupText、五种图表（Area/Bar/Line/Pie/Radar）、DropdownButton、GroupBox、
  DescriptionList/Item、CommandGroup/Item/Separator、NativeMenu 三件套、Resizable、Clipboard、
  Questionnaire/QuestionnaireItem/Choice、TableFooter、VForm、Dialog/AlertDialog/Sheet/Notification/Tooltip。
- **16 个**还需要上游 **entity 状态句柄**（`SchemaEntity("CommandState"/"DataTableState"/"DiffState"/
  "EditorState"/"MessageScrollerState"/"CarouselState"/"CalendarState"/"TimeFieldState"/
  "ColorPickerState"/"ScrollbarHandle"/…)`），我们没有任何 API 造得出这类句柄：
  Command、DataTable、Diff、Editor、MessageScroller、Scroll、Carousel 六件套、
  QuestionnaireInput、ColorPicker、Calendar、TimeField。
- **8 个**能力我们用**非组件**的路子做了，别误报成缺口：Dialog/AlertDialog→`App::openDialog()`、
  Sheet→`App::openSheet()`、Notification→`App::notify()`、Tooltip→元素上的 `tooltip` op、
  剪贴板读写→`App::clipboardText()/setClipboardText()`、FpsMonitor→`App::showFpsHud()`。

## 2. kind 面：ComponentKind 66 值，PHP 能表达 46 个

`src/shell/spec.h:71` 的枚举 66 个值（`Registered` 是最后一个 ⇒ shim_test 那条漂移警报
扫 0..Registered 的范围是对的）。`kKindNames` 登记 46 个，`Registered`/`ChildView` 走专用 ABI，
剩下 **18 个值 PHP 表达不了**：Accordion、AccordionHeader、AccordionPanel、AccordionTrigger、
AvatarImage、AvatarFallback、DockArea、DockContent、FpsMonitor、HVirtualList、VVirtualList、
UniformList、Pagination、PathFill、PathStroke、SliderTrack、SliderIndicator、SliderThumb。
最扎心的是 Slider 与 Accordion 两族：**父建得出来、子建不出来**（我们有 `slider` 和
`accordion_item` 两个 kind，却没有 track/indicator/thumb 与 accordion/header/panel/trigger），
写出去的树在上游是残缺的，比"整个组件没有"更容易踩。
另外词法表里 `scrollbar`/`table_row`/`table_cell` 三项**全仓无工厂、无示例、无测试**
（`grep -rl` 只命中 `gpui_shim.cpp:441-446` 自己）——登记了但没人能发。

## 3. 「两张脸」不等价：kind 路是 gpui-base 裸原语，catalog 路才带组件库样式

这条是**看图看出来的**，不是读代码读出来的：
`tests/baseline/showcase/tree3.png` 里六颗按钮（默认 / primary / secondary / danger / ghost /
outline）**长得一模一样，全是裸文本**；radio/toggle/select 同样是裸文本。
同一时刻 `tree6.png` 的 catalog Alert 有彩色边框与底色、图标画得出来、DropdownMenu 有盒子。
⇒ 主题没坏，坏的是我们把 `El::button()` 映射到了 **kind（gpui-base 原语，组件库不给它样式）**，
而带 variant/尺寸/圆角的那套是 **catalog `Button`（13 个方法）**——我们从未包过。
`El::popover()`/`El::hoverCard()` 同理（kind 版要靠 `trigger`/`content` 槽才成形状）。

## 4. 由此暴露的死糖（真 bug，五个方法）

`src/Element.php:750-772` 的 `primary()/secondary()/danger()/ghost()` 发的是
`method('variant', [...])`，`outline()` 发的是 `method('outline', [])`；
而 `variant` / `outline` **既不在上游 materialize 的 101 个 op 名表里，也不该出现在 method 通道上**
（`variant` 是 catalog descriptor 的 RegisteredMethod 名）。bridge 把 `method` 与 `param_style`
走同一条 `gpui_shim_push_op`（`cpp-src/gpui_bridge.cc:262`）⇒ 这五个糖**一个像素都不改变**。
`showcase/src/sections.php:168-172` 正在用它们，`ElementTest` 里那条
"button records label as child and variant op" 断言的是**我们自己序列化的数组**，
不是上游生效与否——典型的"测试跟着实现走"，绿色类别该叫 wrap，不该被当成效果。
（第三个可疑名 `placeholder` 查清楚了：**不是 bug**，我们的 bridge 在
`gpui_bridge.cc:152-170` 自己把 `placeholder`/`value` 两个 method op 截走喂给 retained 输入，
从不交给上游。但它在别的元素上仍会落到静默丢弃那条路。）

## 5. 静默 vs 响亮的确切分界（决定前面所有条目的严重度）

上游在**记账时**就决定了这个 op 会不会响：
`src/shell/runtime.cpp:3700-3703` —— `op.kind = IsParamStyle(name) ? ParamStyle : (!IsBehavior(name) && argCount == 0 ? NullaryStyle : Method)`。
- 未知**零参**名 ⇒ NullaryStyle ⇒ `materialize.cpp:2576-2577` 会 `ShellErrorSet(UnknownElementMethodTemp(name))`，**响**；
- 未知**带参**名 ⇒ Method ⇒ `ResolveBehavior` 的 if/else 链在 `materialize.cpp:451-454` **以无 else 收尾**，**静默丢**；
- 带参但拼错的样式名 ⇒ `materialize.cpp:2580` 记 `invalid style call`，响。
我们的 `variant`/`outline` 恰好落在"带参 + 非行为名"这一格，所以连错误通道都没有。
（对照：kind 名错我们已改成拒收 + `lastError`；catalog 名/实参错走 `request->Fail`，
`registeredError()` 读得到。三条路现在只有 op 名这一条还是哑的。）

## 6. op 面：上游认 101 个名字，我们只发得出 17 个

`method()`/`callback()`/`style()`/`paramStyle()` 全是 **private**（`src/Element.php:868-890`），
所以 PHP 能发出的 op 名 = 糖的集合，实测 **17 个**：
`id disabled selected checked value size tooltip variant outline placeholder href
overflow_scroll overflow_x_scroll overflow_y_scroll on_click on_change on_hover on_key_down`。
其中 `href` 已接（Link 跳转 + `IsOpenableUrl` 授权），三个 overflow 已接。
**没接的整族**（按上游文档页归组，全部 `file:line` 在 `materialize.cpp:322-454`）：
- **焦点与无障碍**（对应 docs 的 focus / accessibility 两页）：`track_focus`、
  `content_focus_handle`、`tab_stop`、`tab_index`、`role`、`aria_label`/`accessibility_label`、
  `aria_selected`、`aria_level`、`aria_active_descendant`、`key_context`。
  ⇒ **#62「Escape 关不掉 dialog」的根因就在这**：上游的焦点陷阱靠 `track_focus`/
  `content_focus_handle` 把句柄交给元素，我们一个都没发过，dialog 里的元素从未进过焦点链。
- **浮层行为**：`open`、`default_open`、`overlay_closable`、`open_delay`、`close_delay`、
  `on_open_change`（Popover/HoverCard/Popup 的开合与延迟）。
- **事件面只接了 4/24**：缺 `on_key_up`、`on_mouse_down_left|right|middle`、
  `on_mouse_up_left|right|middle`、`on_mouse_down_out`、`on_mouse_move`、`on_scroll_wheel`、
  `on_open_change`、`on_confirm`、`on_dismiss`、`on_step`、`on_resize`、`on_item_click`、
  `on_item_secondary_click`、`on_link_click`、`token`/`on_token_click`/`on_token_hover`。
- **动画**（docs 的 animation 页）：`transition(prop, ms, delay, easing)` 与
  `spring(prop, response, damping, epsilon)`，可动画属性只有 `opacity/w/h/left/top`
  （`materialize.cpp:895-896`）——我们整族没接，所以 PHP 侧没有任何"会动的东西"。
- **自定义绘制**（docs 的 paint 页）：`move_to`/`line_to`/`curve_to`/`cubic_bezier_to`/
  `arc_to`/`close` 配 `PathFill`/`PathStroke` 两个 kind——两边都没有 ⇒ PHP 画不了任何路径。
- **表格结构**：`row_count`/`column_count`（`materialize.cpp:352-354`）我们从不发，
  且 `TableRow`/`TableHead`/`TableCell` 的构造读 `component.index`
  （`materialize.cpp:1838-1842`）而**我们的 C ABI 根本没有 index 通道**
  （`gpui_shim_push(win, kind, text, value, id)` 五个槽，全仓无 `component.index` 赋值）。
  ⇒ hello/showcase 里的"表格"其实是 Table 节点塞 text 子元素，行/列语义为零；
  像素基线是自录的，所以这条永远不会红。
- **滚动控制**：`mode`（scrollbar 的 scrolling|hover|always）、`scroll_size`、
  `viewport_from_layout`、`overflow_scrollbar`/`overflow_x_scrollbar`/`overflow_y_scrollbar`、
  `track_scroll`、`with_item_to_measure_index`。
- **杂项**：`pressed`、`indeterminate`、`start`、`controls_right`、`panel_visible`、
  `panel_size`、`size_range`、`set_position`、`keep_mounted`、`selectable`、`scrollable`、
  `mouse_button`、`frame_budget`、`axis`。

## 7. 样式词表：上游 137 个 nullary 关键字，我们只发了 25 个

`src/shell/style.cpp:920-1277` 的 `kKeywords` 有 **137** 项；我们糖里出现的 nullary 样式名
**25** 项（`flex flex_1 flex_col flex_row flex_wrap flex_none items_* justify_* text_center
text_right truncate italic underline font_bold font_semibold hidden absolute relative
cursor_pointer shadow_sm shadow_md`），**全部在册**（0 个拼错）。
但 `grid`、`self_*`、`content_*`、`whitespace_nowrap`、`text_ellipsis(_start|_middle)`、
`line_through`、`aspect_square`、`border_dashed`、`overflow_hidden*`、`text_xs…3xl`、
`font_thin…black`、`cursor_*`（25 种）、`debug*` 等 **112 项没有糖，也没有公开逃生口**。
带参样式（`w_/h_/gap_/p_/m_/rounded/border` 家族 + 数字坡道）我们覆盖得比较全，
且这类名字写错是**响**的（`invalid style call`）。

## 8. 上游文档 38 页里，我们文档没开口的概念

grep 过 `docs/guide/*` 与 `README.md`：`keybinding`、`SharedString`、`view cache`、`animation`、
`a11y/accessibility`、`i18n/locale`、`auto-update`、`packaging`、`wasm`、`mobile`、`element id`、
`multi-window`、`Global` 全部 **0 命中**。
其中三类要分开写清：
- **代码里有、文档没写**（补文档就行）：多窗口 `App::createWindow()`、全局快捷键
  `onHotkey()/removeHotkey()`、主题 `setTheme()`、动作 `dispatchAction()`/`menuRows()`。
- **上游有、我们没做**（要写进"与上游差异"）：焦点链与无障碍整族、动画、自定义绘制、
  VirtualList/Dock、内联 token（附件式输入）、i18n、字体装载、图片加载策略、auto-update、
  打包/wasm/mobile。
- **概念不对应**（要解释为什么）：`Entity`/`Context`/`Render`/`RenderOnce`/`Task`/`Global`/
  `ElementId`/`ViewCache` 是 Rust 侧写视图的抽象，我们的对应物是"PHP 数组 + 每帧重算 +
  脏驱动"，不该照抄目录，但要在架构页点明这层替换关系。

## 9. 可行性上顺手纠正的一条

Dock 与 VirtualList 我们一直记成"要宿主先造 entity 才能做"（#29/#35 因此挂着）。
上游实际把入口做在**脚本侧**：`RetainedStore::CreateDock/CreateVirtualScroll`
（`src/shell/retained.h:141-159`）由 `__dock_area_new` / `__virtual_scroll_new` /
`__v_virtual_list` / `__h_virtual_list` / `list` / `uniform_list` 调用，
chrome 回调（`tab_bar`/`empty_group`/`drop_indicator`/`dock`）与面板注册
（`DockArea.register_panel` → `__dock_register_panel`）也都在脚本可达的范围内。
我们已经有同类补丁的先例（`runtime.h:129-135` 的 `CreateRetainedInput/Slider/Focus`
就是标着 `typephp-gpui` 的转发口）⇒ 这两项的门槛比记录里低一档。

## 修复轮 1（2026-10-13）：op 走错通道这一类，一次修三处 + 装哨兵

上面第 4、5 条点到的"静默丢"不是理论问题，实测有三处正在哑着：

1. **`El::primary()/secondary()/danger()/ghost()/outline()`**（`src/Element.php`）。
   以前发 `method('variant', ['primary'])`。查上游 descriptor 才发现 catalog `Button` 的
   13 个方法里**没有 `variant`**——变体本身就是零参方法 `primary/secondary/danger/ghost/
   outline/success/warning/link/compact`，而 `variant(enum)` 属于 DropdownButton/Tag/Bubble/
   Marker/GroupBox/TabBar。也就是说我上一轮的判定（"`variant` 是 catalog 的 RegisteredMethod 名"）
   对 Button 是**错的**，只对那六个组件成立。改成发方法名本身。
2. **bridge 里硬编码的 `"focus"` / `"content_focus"`**（`cpp-src/gpui_bridge.cc:236-241`）。
   上游认的是 `track_focus` / `content_focus_handle`（`materialize.cpp:415-417`）。
   ⇒ Select 那两个 `gpui_shim_retained_focus()` 造出来的句柄**从来没交出去过**。
   这条是抽表脚本顺手抓到的：我第一版门只扫 PHP 糖，得到 0 条问题，加上
   "扫 bridge 里的 `gpui_shim_method(..., \"name\"`" 之后立刻红了这俩。
3. **`rm()` 打在 kind 节点上**（`El::progress(...)->rm('size', ['large'])` 这类示例）。
   上游 `ResolveBehavior` 开头 `if (op.kind != Method) continue;`（`materialize.cpp:314`），
   RegisteredMethod op 在非 registered 节点上连"未知名"都算不上。现在 catalog 糖统一走
   `catalogMethod()`：非 registered 节点直接抛 `InvalidArgumentException`。

哨兵：`cmd/upstream-vocab.php`（从 **vendored 在仓库里的** `upstream/gpui-kit-cpp` 抽
kind / op / 槽名 / catalog 四张表，CI 上无需外部树）+ `cmd/check-upstream-parity.php`
（我们发出的每个名字必须在册；Pest 与 CI 各挂一处，`composer parity:upstream`）。
它的**第一次运行就是反向对照**：修之前红，点名 `variant, outline, focus, content_focus` 四个名字；
修完绿。这也说明门只在该红的地方红——它不查样式名（样式那格是响的，混进来只会稀释红信号）。

配套改动：`El::catalog(导出名, 构造实参, children)` 与 `El::catalogButton(id, label)` 公开
（`Element::reg()` 仍是 private）；示例里 65 处 `El::button(X)->id(Y)->…->变体()` 机械改写成
`El::catalogButton(Y, X)->…->变体()`，`El::button('默认(kind)')` 留一颗做两种脸的对照；
`->size('xsmall')` 在 catalog 节点上改成 `sizeEnum('xsmall')`（op 通道与 registered_method 通道不通用）。

**新增的公开 API 让两条覆盖门变红**（`showcase 覆盖 Element 的全部公开工厂/链式方法`），
按项目规矩这不是测试坏，是新 API 没被演示：遂在「样式、图标与弹层原语」节补了
`El::catalog('Button', […])->label(...)->success()/warning()/compact()/loading(true)` 一行。

测量到的：`SHOWCASE SELFTEST OK`（含 overlay churn=30/left=0/boxes 1→1/views 1→1）、
`--liveclick 逐行=13/13 到达节数=13`——**这条是"catalog Button 的 onClick 真能路由到 PHP"的
端到端证据**（导航行整排换成了 catalog Button）。`vendor/bin/pest` 85 passed (436 assertions)、
phpstan OK、parity 门 OK。像素侧：变体行现在应当真的长出五种样式，基线待复看后重录。

## 修复轮 2（2026-10-13）：#69 表格语义 + index 槽位

上游 `base/table.cpp:12-56` 说清了这件事的两半：每个表格部件都只是"一个带 aria role 的 div"
（**布局一概不管**），而 `Table::New(id, rowCount, columnCount, label)` 与
`TableRow::New(id, rowIndex)` / `TableHead/TableCell::New(id, columnIndex)` 的号只喂 aria
（`AriaRowCount/AriaRowIndex/AriaColumnIndex`）。所以缺它们不会花屏，只会让读屏的人拿到
"这表没有行列、每格都在第 0 列"——正是自录像素基线**看不见**的那类错。

补的东西：
- ABI：`gpui_shim_push(...)` 多一个 `unsigned index` 槽；新增
  `gpui_shim_spec_index_by_id(win, id, &out)` 从 arena 里按 `id` op 回读
  （`SpecArena::Node()` 只有 const，所以写必须在 Push 时做、读可以事后做）。
  PHP 侧对应 `App::specIndex(id)`，**查无此 id 返回 -1**，否则"没编号"会被读成"第 0 行"。
- PHP：`El::tableRow()` / `El::tableCell()`（词法表里那两项不再是死条目）、
  `->rowCount()/->columnCount()`（两个 method op）、`->rowIndex()/->columnIndex()`（写 index 槽）。
- 示例：hello 与 showcase 的表格改成 Table > Header/Body > Row > Head/Cell 的真结构，
  行自己 `flexRow()`（布局归样式管这件事得写进文档，不然有人以为换了结构就自动变网格）。

**新自检断言的两半都量了**：故意把第三行写成 `rowIndex(0)` →
`table rowIndex=0,1,0 miss=-1/ok=false`、`SHOWCASE SELFTEST FAIL` rc=1；改回
`rowIndex(2)` → `0,1,2/ok=true` rc=0。这条因此是回归位，不是"看着对"。

一次踩坑记在这：`gpui::StrL("id")` 编译不过（`expected unqualified-id`）——`StrL` 是
上游 shell 内部的宏，不在我们这一侧；本仓的写法是 `gpui::Str("id")`（`gpui_shim.cpp:567`
就是这么用的）。第一次跑"反向对照"其实红在编译期，那不算测到断言，修好编译才重跑。

另一条**不是我们的错**、但每次看日志都会吓一跳：构建链里
`Building hello_world (rel, clang++)` 那步固定报
`Undefined symbols: _main / linker command failed`——那是上游树自带的示例没有 main，
我们的流水线不依赖它（`cmd/build-shim.sh` 与 tpc 在其后照常跑完）。历史日志
（t-scene/t2/rec）里同样有这几行，而对应的那些轮门禁全绿。别再把它当成本轮回归。

## #62 的排查记录（2026-10-13）：三个假设被读数否掉

现象：dialog 开着按 Escape 不关。上一轮我写的根因（"我们从未发 track_focus /
content_focus_handle"）**是错的**，这里把量过的东西留下，别再从头猜。

showcase `--selftest` 里加了一段只报不判的读数（`escape esc=… invalidate=… countAfterPress=… focusWas=…`），
实测：

| 试过的 | 读数 | 结论 |
|---|---|---|
| 开框 + 10 帧 + Escape | `esc=false` | 不是"帧数不够"（Deferred 挂载已生效） |
| 再 `invalidate()` + 6 帧 + Escape | `invalidate=false` | 不是"脏驱动没重画"（#62 与 ① 无关） |
| 开框时 shim 清焦点（InputBlur + WindowSetFocusId(0)） | `focusWas=0` 且 `esc=false` | **焦点清掉也不关** ⇒ 我上一轮的根因判断被否 |
| 点窗口中心 (600,380) 后再看 | `countAfterPress=0` | 那一下是**背板把框点掉的**；先前"点了就能关"的结论是我自己把两件事混在了一次读数里 |

已经排除的还有：注入路不通（同一个派发口上 Tab 会换焦点：`-1061 → 212381998`）、
`escape/backdrop` 四层参数顺序错（PHP→bridge→shim→DialogOptions 一致）、
`topmost` 没置（root.cpp:840-847 每次渲染重算，且背板可关说明这条是真的）。

下一步该查的是**绑定这一侧**：`base/dialog.cpp:127` 给 popup 挂
`KeyContext(DialogContext())->FocusId(HashClickId(name))->TabStop(false)->OnAction(Cancel)`，
而按键解析要"焦点元素所在的上下文链"命中 DialogContext。焦点为 0 时显然没有链，
焦点为旧内容树元素时链里没有 DialogContext —— 两种都不关，符合观测。
所以问题收敛成一句：**在这个 PHP 宿主里，谁该把焦点放进刚开的 dialog**。
上游 `Dialog::Popup()` 只 `FocusTrapArm(win,id,id)`（arm，不抢焦点），
脚本宿主里是"框里第一个可聚焦控件"被 Tab/点击带进去；我们的 dialog 内容常常是纯文本，
没有可聚焦的东西，而 popup 自己 `TabStop(false)`。

## #62 收口：答案在上游那一侧——链是通的，缺的是"把焦点放进去"这一步由谁做

装了 `focusTrap()` 回读口（shim `gpui::FocusTrapActive(window)` → bridge → stub → `App::focusTrap()`，
Fake 侧按 dialogCount 模拟），第一次拿到不依赖输入的机制读数：

| 读数时机 | trap | focus | 结论 |
|---|---|---|---|
| `openDialog()` 当场 | `0` | — | `FocusTrapArm` 只写 `pendingTrap`，Arm 发生在 DrawFrame 的 `EntityRender` 里，比 open 晚 |
| 再给 10 帧 | `178826760` | `178826760` | **陷阱生效，且焦点就落在陷阱宿主（popup）上** ⇒ 上游"开框把焦点带进陷阱"的链在我们宿主里是通的 |

`--escape`（进程里第一个框、没点过任何东西）同一形状：`trapAfterOpen=0 trapAfterFrames=1010534090
focus=1010534090 closed=false`。机制全绿、框仍不关 ⇒ 剩下的唯一变量是**真键送达**：
selftest 的 `evSeen` 里至今没有任何 `key{...}`（只有 change/click/action），`keyHits=1` 量的是
`realKey()` 注入被接受，不是 PHP 收到了 key_down。这与 #56 是同一件事，不是 dialog 专属缺陷。
（先前"在 openDialog 里补调 `FocusTrapApplyPending`"的候选修法已否：负向对照显示 selftest 照绿、
`--escape` 读数一字不差，因为那时 Arm 还没跑，ApplyPending 拿不到 pendingTrap。）

新硬门 `$trapOk`（`trapAfterFrames != 0 && focusOnTrap === trapAfterFrames`）进了 `$ok` 链，
**拿到回归位的资格已验证**：把 `loop(10)` 挪到两个读数之后 ⇒ `trapAfterFrames=0 focus=-1062
trapOk=false`，selftest rc=1。改回原位两次连续运行读数一致。

## 第四条哑路：composer 的 `@bash` 前缀不存在，警告照打、rc 仍为 0

`composer.json` 里 `test:cpp` 与 `test:all` 的最后一步写成 `"@bash cmd/test-shim.sh"`。
composer 的脚本引用只认 `@php` / `@composer` / `@putenv`；`@bash` 被当成"另一个叫 bash 的脚本名"，
于是输出一行 `You made a reference to a non-existent script @bash cmd/test-shim.sh`
**然后照常返回 0**。后果：文档里"一键 composer test:all 含 shim_test"是假的，本地从这条门跑过去时
根本没编过 C++（本轮 `focusTrap()` 动了 shim，却靠这条门"验过"）。CI 在 ci.yml:165 另有
独立的 `bash cmd/test-shim.sh` 步骤，所以没漏进产物——也就是说这是"本地门形同虚设"，
不是"整条链没测"。

与前两条哑路（`materialize.cpp:451-454` 带参未知名静默丢弃、`materialize.cpp:314` 对 kind 节点
跳过 RegisteredMethod）同一形状：**报告走 stdout、结论走 rc，而 rc 不参与**。
判据落在观察上而不是 rc 上：这类门的绿灯必须是"看到了被门量者自己的输出"，
不是"没看到错误"。改法 `@bash` → `bash`，双向验过（绿向出现 shim_test 分节名且警告 0 次；
红向 `GPUI_DIR=/nonexistent-gpui` rc=1）。

## #70：角色名只认 snake_case，而未知名字是第五条哑路（比 #66 更安静）

`shell/a11y.cpp` 的 `kRoleNames` 有 **181** 个 PascalCase 项（表里跨行拼接，
`"DescriptionLis" "t\0"` 这种，取数时必须先按相邻字面量整体接回来再按 `\0` 切），
`RoleNameMatches` 把每一项**按下划线拆开小写**后与传入名逐字符比 —— 也就是说上游只收
`heading` / `list_item` / `doc_biblio_entry`，`'Heading'`、`'descriptionList'` 一律不认。
而消费端是 `materialize.cpp:2619-2622`：

```cpp
if (behavior.role) {
    AccessibilityRole role = shell::AccessibilityRoleFromName(behavior.role);
    if (role != AccessibilityRole::None) element->Role(role);
}
```

没有 else、没有错误、也没有像素差别 —— 比 #66 那五个糖还安静（那几个至少在 catalog 路径上
有"查无此方法"的痕迹，这一条连痕迹都没有）。所以 `El::role()` 必须自己拒。

**取数端与白名单不能各写一套**：`Element::ROLES`（181 项）由 `cmd/upstream-vocab.php --dump-roles`
从上游表生成，`cmd/check-upstream-parity.php` 再**双向**对齐 —— 只查"我们多写的"会留一个洞：
把表删瘦（少 40 个名字）表现为"上游有而我们没有"，单向门就永远绿着让糖覆盖不到新角色。
双向各做过一次负向对照，且每次改动都用 `grep -c` 证明补丁真落到了文件上
（第一轮对照因为 perl 没匹配上而两头都"绿"，那次无效： `'list_grid', 'terminal',` 实际跨在两行）。

这一族的验收只能量到"发对了"：a11y 不画像素，所以两示例的在册基线（showcase 13 棵 +
hello 18 棵）`gdfrac=0.00000` **本身就是证据**
（"只改读屏树"= 像素一位没变 + op 断言齐全），而 VoiceOver 实际读出什么仍需人工确认，
这一格在文档里写成了缺口而不是通过。

## #71：指针事件不是"缺糖"，是"没补丁会崩"

上游 24 个 `on_*` 回调名里我们只发得出 4 个。先按"缺糖"理解就错了：materialize 给
`onMouseMove` / `on_mouse_down_*` / `on_mouse_up_*` / `on_scroll_wheel` 挂的全是
`Listen(cx, &ScriptView::OnScript*, cbid)`（`materialize.cpp:2701-2735`），而这些 handler
的函数体原本调 `DispatchMouseMove` / `DispatchMouseButton` / `DispatchScrollWheel` → QuickJS。
`on_click` 之所以一直能用，是因为它走的是另一个 helper（`ClickListener(cx, cbid)`，
`materialize.cpp:2696`），根本不碰脚本引擎 —— 这就是"点击能到 PHP、按键曾经段错误"（#53）
的同一处分岔。所以正确顺序是**先补投递路，再发糖**；反过来做会得到一条比 #66 更糟的假接口。

`cmd/patch-mouse-view.sh` 按 patch-view / patch-key-view 的既有形状把五个 body 换成
`gpuiEnqueueMouse/Scroll/Move` 弱钩子（强符号在 `cpp-src/gpui_shim_events.inc`）。
GpuiEvent 只有一个 value 槽，而指针事件一次要给坐标 + 按钮 + 连击 + 修饰键，
所以定的是 CSV **字段顺序**而不是类型：mouse `"x,y,button,clicks,mods"`、
move `"x,y,pressed,button,mods"`、scroll `"dx,dy,x,y,mods"`。

三个测量结论：

| 量什么 | 读数 | 结论 |
|---|---|---|
| 真点击后宿主队列里的指针事件 | `down=13 up=13 move=1`（13 颗导航行各一次按下与抬起） | 投递是真的，不是合成缝 |
| 值的形状 | `downVal=105.00,226.00,0,1,0` | 这个格式只有 `gpuiEnqueueMouse` 会产出 ⇒ 生产者可指认 |
| 反向对照：把补丁从树里摘掉、amalgam 钩子名 0 处、重编 gpui.o | 真按下那一刻 `rc=139`（SIGSEGV） | 补丁是承重的；没补丁时不是"不生效"而是崩 |

对照过程本身踩了两次"量到陈旧产物"：
第一次 `php bin/gpui selftest` 在跑测试前会**先执行补丁流水线**，于是我刚 `cp` 回来的
未打补丁 view.cpp 被当场又打上了；第二次 `bun cmd/build.ts` 打印了 `compile 0, skip 4`，
即 amalgam 与 gpui.o 都没重生成。有效的做法是：绕过 `bin/gpui`（它一定会补），
手工 `cp <pre-patch .bak> view.cpp` → **立刻 `grep -c` 确认标记为 0** →
`rm .work/gpui.cpp out/mac/rel/obj/gpui/gpui.o` → `bun cmd/build.ts` → 断言
`strings .work/gpui.cpp | grep -c 钩子名` 也是 0，再编译示例。这一串里任何一步没落，
读到的就是上一轮的结论（这次两处都是靠重新 grep 才发现的）。

另外两条本轮的副产品：

1. **文档教的 handler 签名是错的**：`docs/guide/events.md` 第 1 节写的是
   `function (mixed $value)`，而 `App::safely()`（App.php:1127-1140）在 arity===1 时传的是
   **整个事件数组** `{type, id, value}`，只有 arity===0 才不传参。照文档抄的人会拿到数组当值用。
   新加的替身级路由测试（`FakeBridge::pushEvent(..., 'mouse_down_left', ...)`）第一次跑就把它抓红 ——
   这条测试的价值不在覆盖率，而在它是一面能反过来说话的镜子。
2. **一次不可复现的 shotdiff 红**：紧接在 `selftest hello` 之后的那轮 `shotdiff check hello`
   报 tree 29/31 `gdfrac=1.00000`；重跑整轮 18 棵全绿，再对这两棵树各连拍两次，
   两次互为同一张且与基线 md5 相同 ⇒ 不是"这两棵基线带活数字"（HUD / Inspector），
   是那一次捕获的一次性异常。记在这里，免得下次当成回归追。

## #72：`Keyword::fields` 不是"生效位"——差点把它读成第 6 条哑路

给样式补糖之前先取 style.cpp 的关键字表。第一版解析器把第三个字段当 arity，于是
`text_xs…3xl` 这 7 条**被静默丢掉**（它们的第三字段是 `StyleFieldFontSize` 而不是数字）；
用独立枚举（`grep -c "\[\](El"` = 137）对了一下才发现解析法只有 130。
修法是取数端把"表里有多少条"与"我解析出多少条"分开报（`missing` 数组非零即红），
而不是只信一个数字。

紧接着第二次误读更值得记：看到 `struct Keyword { name; apply; uint32_t fields; }`
再配上表上方 "Where this tree's Style has no field for what the method sets, the entry
says so and changes nothing"，我判定 `fields == 0` = 这条关键字什么都不画 ——
照这个结论，我们现有的 25 个样式糖（`flex_row`、`items_center`、`hidden`…）**全部是死接口**，
而这些样式在截图里明明在起作用。差一点就把这个结论写进文档并据此重做一批 API。

去读消费端才纠正过来：`ApplyNullaryStyle()`（style.cpp:1642）拿到条目就无条件
`keyword->apply(element)`，从不看 `fields`；`fields` 全树只被 `StyleFieldsOf()`
（style.cpp:1694）读一次，是给 `transition()`/`spring()` 判"这个名字动了哪些被追踪字段"用的
动画掩码。空 apply 那条注释指的是另一种条目，实测全表空 lambda 0 个。
于是这批数据被重新命名成 `animated`（137 里只有 7 条非零，恰好是字号一族），
文档也按这个说法写，并留了一句"精确的可动画清单要读 `FamilyFields()`，本轮没读它"——
不把自己没验的部分写成事实。

这轮的产物：47 个零参样式糖（全部经 parity 门 2c 静态核名字），parity 门新增 2c 段
（`style()` / `paramStyle()` 发的名字必须在 kKeywords / kFamilies / kOtherParamNames 里），
拼错名双向对照过（`truncte` → rc=1、`gapp` → rc=1、还原 rc=0）。
showcase selftest 里加 `$styleSugarOk`：在**编出来的产物**上把 47 个糖各调一遍，
断言发出 47 条不重复的 `nullary_style` op；元素是 detached 的，所以 13+18 张基线
逐张 `gdfrac=0.00000` 就是"这批糖没偷改像素"的证据。
门的回归位也是量过的：删掉链上一个 `->invisible()` → `styleSugar=46/ok=false` rc=1；
更早一次 `styleSugar=0/ok=false` 则是门自己抓到我 op 种类写错（真名 `nullary_style`）。
