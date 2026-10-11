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
