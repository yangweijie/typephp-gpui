# 架构与 C-ABI 防火墙

## 全景

```
PHP 声明式 API (src/)          C++17 bridge            C++20 shim              gpui amalgam
─────────────────────         ──────────────         ─────────────           ───────────────
App / Element / El   ──array──▶ gpui_bridge.cc ──C ABI──▶ gpui_shim.cpp ──gpui.h──▶ SpecArena
                                  (phpx + shim.h)          (唯一 include gpui.h)      ShellMaterialize
       ▲                                                     │  事件入队 (定长 struct)   El* 树 → GPU
       └──────────────── gpui_poll_event ◀── GpuiEvent ◀──────┘
```

PHP 侧把 `Element` 树序列化成扁平数组；bridge 递归展开成 `push/style/method/callback/
attach/slot/registered` 的 C 调用下发；shim 落到 gpui 的 `SpecArena`，`ShellMaterializeSpec`
每帧翻成原生 `El*` 树；事件反向经定长 `GpuiEvent` 结构入队，PHP 主循环 poll 取走分派。

## 为什么要有"防火墙"

gpui.h 用了 C++20 的 `concept` / `requires`，而 TypePHP **nano 模式强制 C++17**。
两者无法在同一编译单元共存。解法是按语言标准切三层，只经**纯 C ABI** 通信：

| 单元 | 标准 | 能 include | 职责 |
|------|------|-----------|------|
| `gpui_bridge.cc` | C++17 | phpx + `gpui_shim.h` | PHP 数组 ↔ C 调用翻译、事件 struct ↔ PHP 数组 |
| `gpui_shim.cpp` | C++20 | `gpui.h` | App/Window/SpecArena/PhpView 生命周期、事件入队 |
| `gpui_shim.h` | 纯 C | `<stdint.h>` | 跨边界契约：`GpuiArg` / `GpuiEvent` / `extern "C"` 函数 |

`gpui::` / `php::` 类型**绝不出现在边界上**。窗口句柄以 opaque `void*` 往返，bridge 用
`php::Box` 子类 `WindowBox` 包成 PHP mixed 资源。

## 构建产物链

1. `cmd/patch-*.sh`（7 个，幂等）给上游源码打补丁：事件入队、nano 依赖解析、retained
   实体、focus、registered 回调旁路、DispatchArgs 回调路由、host 侧 nested-view 注册。
2. `bun cmd/build.ts` 生成 amalgam（`.work/gpui.{h,cpp}`）+ 编 `gpui.o`。
3. `cmd/build-shim.sh` 编 `gpui_shim.cpp`（C++20）并与 `gpui.o/quickjs.o/AppLog.o` 归档成
   `cpp-src/build/libgpui_shim.a`。
4. `tpc --nano` 只编 PHP + C++17 bridge，链接该静态库 + 系统 frameworks。

补丁 marker 清单与契约见仓库根 `GPUI.lock`；`bin/gpui doctor` 逐 marker 校验。

## 渲染根模型：为什么是 ShellRoot

窗口根用 `WindowOpenView(ShellRoot)` 直接开，而非 `KitOpenWindow`——后者会把内容包进
基座 `Root`，令 `ShellRootOf(window)` 判定失败，Dialog/Toast 的 overlay 就找不到宿主。
代价：基座 `Root` 才提供的 **root-application** 上下文（`AppEffects`）在 ShellRoot 下不可用，
因此依赖它的 `MenuBar` 暂不能成盒（见 [组件 API](/guide/components) 的已知限制）。

## 关键不变量（改代码前必读）

- **arena 字符串必须拷贝**：bridge 传来的 `const char*` 是 `emitTree` 帧内临时量，shim 侧
  一律 `AStr`/`StrDup` 进 arena，否则悬垂（曾致 `size("100%")` 变乱码、布局塌缩 = bug #18）。
- **payload.arguments 活到 materialize**：catalog 组件的 `ComponentArgument` 及其字符串/数组
  必须 arena 分配（Registered 机制）。
- **stub 返回类型须匹配 bridge C++**：`gpui_app_create` 声明 `void` 会让 AOT 生成的返回值
  写回错位 → 段错误。改 stub/C++ 后必跑原生 `--selftest`。
- **Button/Link 标签是子元素**：gpui 的可见标签来自 `children`，不是 `component.text`。
- **`ChildView` 的 entity 生命周期在宿主手里**：`gpui_shim_webview` / `gpui_shim_inspector`
  用 `EntityNewRaw` 建包装 view、经 `RegisterHostView`（第 7 个补丁）拿 token、压一个
  `ComponentKind::ChildView` 节点。gpui 侧 `ChildView` 每帧只是 `EntityRender`，**不接管
  生命周期**，`ReleaseOwnedEntities` 也按不到它（我们注册的 entry 里 `owner`/`policy` 留空）。
  所以 `gpui_shim_close` 必须在 `AppClose` **之前**显式 `EntityDrop`——WebView 的 OS 控件
  要求"在父窗口销毁前放手"（`webview.h:50-51`）。
- **drop holder 之后不要再 `delete` 它的 payload**：`EntityNewRaw` 的 deleter 已经负责
  `delete`，再删就是 double-free（dialog 那次 SIGABRT 的同一课）。
- **`gpui::WebView` 自己不是 view**：`webview.cpp:95` 用 `EntityNewState` 建它，没有 Render。
  要挂进树必须有一层自己的 wrapper view 去返回 `WebViewEl(...)`。
- **通知状态归窗口，不归 `WindowNotifications`**：`WindowNotifications(cx)` 读的是基座 `Root`
  的 `WindowLayers`，在 ShellRoot 下直接 SIGABRT。所以 `EnsureNotifyState` 按窗口持有一份
  `NotificationListState` entity，并且**必须**填 `self`（否则 `NotificationStartAdvancing`
  不启动计时器：通知永远停在 `Starting` 的全透明态、也永不退场）与 `useThemeSettings`。
  层本身要 PHP 显式摆 `El::notifications($id)`——ShellRoot 不替我们挂。

## 下一步

- [事件与回调](/guide/events) 看数据如何跨边界往返
- [测试与门禁](/guide/testing) 看这些不变量如何被守
