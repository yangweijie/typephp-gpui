# TypePHP GPUI

基于 [TypePHP](https://github.com/swoole/typephp) (AOT) + [gpui-kit-cpp](https://github.com/kjk/gpui-kit-cpp) 的原生桌面 GUI 应用快速开发框架。

用 PHP 描述界面，编译成原生二进制，GPU 加速渲染，跨平台运行。

## 这是什么

[gpui-kit-cpp](https://github.com/kjk/gpui-kit-cpp) 是 Zed 编辑器 GPUI 框架的 C++ port（703 个源文件），自带一套脚本绑定层（`src/shell/`）让 JavaScript 能描述界面。本项目把这条"脚本描述 → 原生渲染"通道接到 **PHP** 上：

```
PHP（大脑，可单测）        C++ bridge c++17（极薄）   C++ shim c++20（唯一碰 gpui.h）      gpui-kit-cpp（脸）
────────────────────   ──────────────────       ─────────────────────   ──────────────────────
TypePHP\Gpui\App      ─►  gpui_bridge.cc        ─►  gpui_shim.cpp        ─►  SpecArena
TypePHP\Gpui\Element      （仅 phpx + C ABI）        gpui_shim_push/...          ShellMaterialize → El* 树
（声明式元素树+状态）       gpui_shim_pump/poll         gpuiShim 泵+事件入队        taffy 布局 + Metal 渲染
                          ◄── GpuiEvent struct ◄─────  ◄── 点击/变更回调
```

- **声明式 UI** — 用链式 builder 描述元素树（`El::div()->flexCol()->gap(8)->child(...)`），与 gpui 原生 API 风格一致
- **状态驱动** — 事件处理器只改状态，`view()` 注册的构建函数每帧按新状态重新描述界面
- **复用 shell materialize** — 不重新发明渲染管线，直接走 gpui-kit-cpp 已有的 `SpecNode → El*` 翻译器，覆盖 80+ 组件行为
- **PHP 主循环** — gpui 事件泵由 PHP 驱动，业务逻辑全部留在 PHP 里，可单测
- **nano AOT + C-ABI 防火墙** — TypePHP nano 模式（免 libphp、自包含二进制）锁定 c++17，而 gpui.h 用了 C++20 `concept`/`requires`。故 gpui-kit-cpp amalgam 与唯一 include 它的 shim 以 c++20 预编译成 `libgpui_shim.a`，c++17 的 bridge 仅经纯 C ABI 与之通信
- **无头测试** — 纯 PHP 桥接替身，不需要 gpui 或编译器

## 架构核心：复用 spec/materialize 通道

gpui-kit-cpp 的 `src/shell/` 已经实现了完整的"脚本语言描述 UI"管线：

| 层 | 文件 | 作用 |
|---|---|---|
| 描述模型 | `shell/spec.cpp` | `SpecNode{Component, ops[], children[]}`、`SpecOp{kind,name,args}`、`Bridged{Nil,Bool,Number,String}` |
| 样式表 | `shell/style.cpp` | nullary style（`flex_row`/`items_center`/`bg`…150 关键字）+ parametric style（`w_4`/`gap_2`/`rounded_lg` tailwind ramp） |
| 翻译器 | `shell/materialize.cpp` | `ShellMaterializeSpec(cx, specs, rootId)` → 递归构造 `El*` 树，绑定 Listener |
| 回调路由 | `shell/runtime.cpp` | `CallbackId`(uint64) → JS function；本项目替换为 → PHP callable |

本项目的桥接层做一件事：**把 PHP 的 Element 描述翻译成 SpecArena 的 Push/PushOp/Attach 调用序列**，然后让 materialize 管线照常工作。回调不再进 QuickJS，而是入队到 bridge，PHP 主循环取走分派给 handler。

## 构建与运行（macOS arm64，已验证）

完整流程（上游源码已 vendored 在 `upstream/gpui-kit-cpp`，构建时自动同步到沙箱）。推荐用打包 CLI 一条命令：

```bash
composer install                       # 含 swoole/typephp + swoole/php-nano
php bin/gpui doctor                    # 检查工具链（bun/clang++/tpc/php-nano/gpui-kit-cpp）
php bin/gpui build hello               # 补丁→amalgam→shim 库→tpc nano，一条命令
php bin/gpui run hello                 # 编译并运行
php bin/gpui shot hello                # 编译并截图出 /tmp/hello.png
php bin/gpui selftest hello            # 编译并跑 --selftest（输入回环+点击+定时器），rc=0=通过
php bin/gpui run showcase              # 另一个示例：13 节把 PHP 面全部特性走一遍
                                       # （Element 全部工厂/链式方法 + App 全部能力，
                                       #  coverage 由 tests/ShowcaseTest.php 反射核对）
php bin/gpui selftest input --no-native # 按功能域拆出的独立示例（ls examples/）：
                                       #  一支只装一域，编译与报告都只那一域的范围，
                                       #  自检边跑边打（一步一行，崩在哪行停在哪行）
                                       # run/selftest 会把二进制包进 .app 再启动：没有
                                       # bundle 身份的进程在 macOS 不是前台应用，手点第一下
                                       # 被当成激活点击吃掉、keyDown 也没有 firstResponder，
                                       # 症状就是"点了没反应、输入框打不进字"
```

<details><summary>底层手动步骤（gpui build 内部即按此序）</summary>

```bash
bash cmd/patch-nano.sh                 # 修 php-nano 的 "Core" 依赖解析缺口
bash cmd/patch-view.sh                 # view.cpp 事件入队 + window_mac 单帧泵/入口改名
bash cmd/patch-retained.sh             # ShellRuntime 暴露 CreateRetainedInput/Slider
bash cmd/patch-focus.sh                # ShellRuntime 暴露 CreateRetainedFocus（DatePicker/Select）
( cd build/gpui-kit-cpp && bun cmd/build.ts -rel hello_world )   # 生成 .work/ 并编译 amalgam（须在所有补丁之后）
bash cmd/build-shim.sh                 # c++20 编 shim + 归档成 cpp-src/build/libgpui_shim.a
php vendor/bin/tpc.php examples/hello/project.macos.yml --nano -f # AOT 产出 build/hello
```

</details>

> 注：`bun cmd/build.ts` 会重编 32 万行 amalgam（约 40s）。改 PHP 后只需重跑最后一步 `tpc`，
> shim 库不变就不必再 build-shim。

## 测试与自检

```bash
composer test                    # Pest：13 个无头单测（Element builder + App 事件/取值/循环）
./examples/hello/build/hello --selftest   # 真实二进制自检：输入取值回环 + 点击事件链路，rc=0=通过
```

- **Pest 无头测试**走 `src/FakeBridge.php`（纯 PHP 桥接替身），不需要 gpui 或编译器。
- **--selftest** 跑在真编译产物上，验证 C-ABI 防火墙 + gpui RetainedStore 取值链路，以及
  `gpuiEnqueueClick`(strong 覆盖 weak)→`boxForView` 反查→队列→`poll_event`→PHP `dispatch` 的点击事件全链路。

## 示例（API）

```php
<?php
use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;

function main(int $argc, array $argv): void {
    $state = ['count' => 0];
    $app = new App();
    $app->create();
    $app->createWindow('Hello GPUI', 800, 600);

    // 每帧按 $state 重新描述界面（gpui frame-rebuilt 模型）
    $app->view(function () use (&$state): El {
        return El::vFlex([
            El::text("Clicked: {$state['count']}")->font(16.0)->semibold(),
            El::button('+1')->id('inc')->onClick()->primary(),
        ])->sizeFull()->gap(8.0)->justifyCenter();
    });

    // onClick() 只声明"此元素有 click 事件"；handler 用 id 注册在 App
    $app->on('inc', 'click', function () use (&$state): void {
        $state['count']++;
    });

    $app->loop();
}
```

## 当前状态

✅ **Phase 1–3 + Stage 1–4 + 交付 CLI 完成** — 原生二进制可编译、开窗、渲染、驱动事件、像素截图、自检；`gpui` CLI 一条命令构建。

详见 [`task_plan.md`](task_plan.md)、[`findings.md`](findings.md)、[`progress.md`](progress.md)。

### 已支持组件（经 --shot 渲染验证）
Text / Button(label 为子元素) / Link / Flex(vFlex/hFlex) / Input / Textarea / Slider / Checkbox / Switch /
Progress / Image / Table(+Header/Body) / Radio / Toggle / Collapsible / Popover / HoverCard / Accordion /
Avatar / Svg / DatePicker / Select / Combobox / Markdown TextView；样式：flex/align/justify/gap/padding/margin/
size/w/h/rounded/border/shadow/font/text-color/bg/opacity/cursor/…（映射到 gpui-kit-cpp shell 的 nullary + 参数化文法）。

### 验证手段
- `composer test` — Pest 无头单测（FakeBridge 替身）。
- `gpui selftest` — 真实二进制自检：输入取值回环 + 点击事件链路 + 定时器 tick。
- `gpui shot` — 真实在屏窗口抓 PNG，像素级视觉验收。

### Phase 进度
- [x] Phase 1：架构设计与桥接契约定义
- [x] Phase 2：项目骨架搭建
- [x] Phase 3：核心组件端到端跑通（nano 二进制可运行）
- [x] Phase 4：Retained Input/Slider/Timer + Slot 基建 + 低/中复杂度组件 + Markdown/Select/DatePicker
- [x] Phase 5：Pest 无头测试 + --selftest（输入/点击/定时器）+ --shot 像素验收
- [ ] Phase 5+：--shot 视觉回归基线比对（imgdiff）
- [~] Phase 6：交付 CLI（`gpui` build/run/shot/selftest/doctor/new）+ 文档；示例集（hello + gallery 树）
- [ ] 待办（高复杂度，见计划 Stage 5/6/7）：Dialog/Sheet/Toast（窗口根换 ShellRoot）、List 虚拟化（PHP 窗口化）、Dock

## 依赖

- [TypePHP compiler](https://github.com/swoole/typephp) (`swoole/typephp`)
- [gpui-kit-cpp](https://github.com/kjk/gpui-kit-cpp)（submodule，macOS arm64 优先）
- macOS: Xcode command line tools
- Linux: g++, libcairo, libx11
- Windows: MSVC

## License

MIT
