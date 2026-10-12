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
把这一帧翻成原生 `El*` 树；事件反向经定长 `GpuiEvent` 结构入队，PHP 主循环 poll 取走分派。

**"每一帧"不等于"每一圈循环"**：`App::loop()` 只在**脏**的时候才重建 view——首帧、
派发过事件、窗口状态（active/key/visible/main）变了、或 app 自己调过
[`invalidate()`](/guide/events#渲染是脏驱动的)。空闲窗口因此不再每秒 60 次重建整棵
PHP 树；代价是"改了自家状态但没有任何事件"必须显式 `invalidate()`，否则那一帧不会被画。
判据与读数见 `App::renderCount()` 与 `--selftest` 的 `dirty idle=1/40frames`。

## 为什么要有"防火墙"

gpui.h 用了 C++20 的 `concept` / `requires`，而 TypePHP **nano 模式强制 C++17**。
两者无法在同一编译单元共存。解法是按语言标准切三层，只经**纯 C ABI** 通信：

| 单元 | 标准 | 能 include | 职责 |
| --- | --- | --- | --- |
| `gpui_bridge.cc` | C++17 | phpx + `gpui_shim.h` | PHP 数组 ↔ C 调用翻译、事件 struct ↔ PHP 数组 |
| `gpui_shim.cpp` | C++20 | `gpui.h` | App/Window/SpecArena/PhpView 生命周期、事件入队 |
| `gpui_shim.h` | 纯 C | `<stdint.h>` | 跨边界契约：`GpuiArg` / `GpuiEvent` / `extern "C"` 函数 |

`gpui::` / `php::` 类型**绝不出现在边界上**。窗口句柄以 opaque `void*` 往返，bridge 用
`php::Box` 子类 `WindowBox` 包成 PHP mixed 资源。

> **第 8 个补丁之后，这道墙的理由从"必须"变成"划算"。** `cmd/patch-concepts.sh` 把
> gpui.h 里 7 处 C++20 构造降级成 C++17 等价写法（1 个 `concept` → 偏序特化 trait、
> 5 个 `requires` → `enable_if`、1 个 `PaintCtx paint = {}` → default-init），实测
> 一个只 `#include "gpui.h"` 的 TU 在 `-std=c++17` 下从 **20 个 error 变成 0**，
> `bash cmd/check-cxx17.sh` 是这条的门禁（C++17 与 C++20 双向都要过，且 `EmitsEvent`
> 判据正反都验，防止"降级"悄悄放宽了约束）。
>
> 两个保留意见，别把它读成"防火墙可以拆了"：
> ① 那 0 error 里有 **87 处 clang 扩展**在兜着——头里是位域默认成员初始化
> （`uint32_t wrap : 1 = false;`），C++17 标准并不接受，clang 只是警告后放行。
> ② 墙现在买的是别的东西：bridge TU 不必吞 54K 行头文件（改一行 PHP 就重编一次的代价）、
> 我们自己的 ObjC/ARC 代码（`gpui_mac.mm`、mac 粘贴板读文件路径）必须有独立 TU、
> 以及那份 C 契约让上游改动不至于直接打断桥接层。拆墙省下的只是样板，赔进的是编译增量
> 与隔离性。**所以墙留着，但它的存在从此是可测的，不是信条。**

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
- **helper 收 `const std::string&` 就不能用 `const char*` 调**：`cppStr(title ? title : "")`
  会隐式造临时 `std::string`，libc++ 的 SSO 把短串放在临时对象内联缓冲里，语句结束即析构，
  返回的 `gpui::Str` 悬垂。tree13 的 toast 把 `"Saved"` 画成 `"succe"` 就是它（长度取
  `"Saved"` 的 5，字节是后一个临时对象复用同一栈槽留下的 `"success"` 前缀）。现在有
  `cppStr(const char*)` 重载把这类调用全部接住——**加新 helper 时先问"char* 会不会造临时"**。
- **出参缓冲的清零顺序**：桥侧 `ErrText(buf, cap)` 早先写的是"先 `buf[0]='\0'` 再
  `return std::string(buf)`"，等于把 shim 刚写进来的失败原因擦掉再返回空串。声明处初始化
  （`char err[256] = {};`），返回处只读。
- **scratch 只活到下一次调用**：`gpui_shim_out()` 给的是 shim 内部 `std::string` 的指针，
  桥必须在同一个调用栈里拷成 `php::String`。列目录的名字先建完 `vector<string>` 再回
  `c_str()`，边 push 边给指针会在扩容时悬垂。
- **mac 集成的 owner 指针要随窗口撤销**：`gpui_mac.mm` 不 include gpui.h，注册时把
  `Box2*` 作为 owner 交出去、回调时原样带回。所以 `gpui_shim_close` 必须
  `gpui_mac_release_owner(box)`，否则托盘项/快捷键会在窗口销毁后回调进野指针。
- **通知状态归窗口，不归 `WindowNotifications`**：`WindowNotifications(cx)` 读的是基座 `Root`
  的 `WindowLayers`，在 ShellRoot 下直接 SIGABRT。所以 `EnsureNotifyState` 按窗口持有一份
  `NotificationListState` entity，并且**必须**填 `self`（否则 `NotificationStartAdvancing`
  不启动计时器：通知永远停在 `Starting` 的全透明态、也永不退场）与 `useThemeSettings`。
  层本身要 PHP 显式摆 `El::notifications($id)`——ShellRoot 不替我们挂。

## 下一步

- [事件与回调](/guide/events) 看数据如何跨边界往返
- [测试与门禁](/guide/testing) 看这些不变量如何被守
