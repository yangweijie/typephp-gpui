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
