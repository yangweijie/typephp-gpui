# 快速开始

TypePHP GPUI 让你用 PHP 写跨平台 GPU 加速桌面应用：声明式 `Element` 树经
[TypePHP](https://github.com/swoole/typephp) AOT 编译为原生二进制，底层复用
[gpui-kit-cpp](https://github.com/longbridge/gpui-kit)（Zed GPUI 的 C++ 移植）。

> 当前仅支持 **macOS arm64**（nano 模式，自包含免 libphp）。

## 前置工具

| 工具 | 用途 | 检查 |
|------|------|------|
| PHP 8.3+ | 语言运行时 + 构建脚本 | `php -v` |
| Composer | 拉 typephp / php-nano / pest / phpstan | `composer -V` |
| bun | 生成 gpui-kit-cpp amalgam | `bun -v` |
| clang++（Xcode CLT） | 编 C++20 shim + C++17 bridge | `clang++ --version` |
| gpui-kit-cpp 源码 | 上游 GUI 库 | 见下 |

## 1. 安装 PHP 依赖

```bash
composer install
```

## 2. 放置 gpui-kit-cpp 源码

上游源码已 **vendored 进本仓库**（`upstream/gpui-kit-cpp`，22M 源码子集，含我们那 7 个宿主补丁）。
构建前 `cmd/sync-upstream.sh` 会把它复制成沙箱 `build/gpui-kit-cpp`，补丁与 amalgam/out 都落在
沙箱里，跟踪的源码树不被弄脏。要用自己的源码树，设 `GPUI_DIR` 指过去（同步会自动跳过）：

```bash
export GPUI_DIR=/path/to/gpui-kit-cpp   # 可选；默认 build/gpui-kit-cpp（upstream/ 的沙箱）
```

`bin/gpui doctor` 会检查源码是否就位、每个补丁的 marker 是否已落（清单见 `GPUI.lock`）。

## 3. 一键构建

```bash
composer bootstrap        # 等价 bin/gpui build hello
# 或
php bin/gpui build hello
```

内部按序：同步沙箱 → 打全部补丁 → `verifyPatches()` 校验 → `bun cmd/build.ts` 生成 amalgam →
`cmd/build-shim.sh` 编 C++20 静态库 → `tpc --nano` 编 PHP+C++17 bridge 并链接。
产物：`examples/hello/build/hello`。

想看**全部**特性不必翻 API 表：`examples/` 下每一支都是一个功能域（基础原语 / 输入 /
选择与开关 / 布局 / 表格列表 / catalog 富组件 / 侧栏表单菜单 / 浮层 / 系统能力 / 服务 /
WebView·Inspector / 样式图标 / mac 集成），`php bin/gpui selftest <域>` 就跑这一域的门。
每一处都是真调用，而"有没有漏掉某个 API"由 `tests/ExampleCoverageTest.php` 反射 `src/Element.php`
与 `src/App.php` 逐名核对——少写一个 CI 就红，所以"展示了所有特性"是可断言的，不是自述。
它还会逐支示例起一个无头进程，把每一棵树在 FakeBridge 上建一遍并核 handler 不是孤儿
（`php cmd/headless-trees.php <域>` 就是那条门本体）。

想只测**一个功能域**（改输入、改浮层这类）就跑按域拆出来的独立示例，比如
`php bin/gpui selftest input`：它的 sources 只列 `examples/_shared/features/inputs.php`
加共用状态表 `examples/_shared/state.php`，所以编译快、报告短，而且自检是**边跑边打**的
（每步一行，崩在哪行就停在哪行）。同一份域源只有一份实现，被各支示例的 yml 各自收录——
拆分的粒度是"编译单元"，不是"代码副本"。

## 4. 运行 / 自检 / 截图

```bash
php bin/gpui run hello            # 跑起来（带窗口）
php bin/gpui selftest hello       # 无交互六链路自检，rc=0 即通过
php bin/gpui shot hello /tmp/x.png 0   # 渲染测试树存 PNG
```

### selftest 固定走 `.app` bundle；`run` 默认裸二进制（`--bundle` 才显式走 `.app`）

`php bin/gpui run <示例>` **默认执行裸二进制** `examples/<示例>/build/<示例>`；
加 `--bundle` 才走 `examples/<示例>/build/<示例>.app/Contents/MacOS/<示例>`
（bundle 里放的是**正规文件**而不是符号链接——符号链接会让 `codesign` 直接拒签整个 bundle，
而每次 `build`/`run` 都会重放并重签一次，所以不会跑到旧产物）。
为什么默认不走 bundle：实测同一份字节在同一秒的读数是
`裸 onScreen=true / bundle onScreen=false`——bundle 那一颗的窗口不被 WindowServer 合成，
人既看不见也按不动，而 `run` 这个交互入口要给的正是能看能按的那扇窗。
（旧说法"裸二进制起不来 `isActive`，所以必须走 bundle"是前台重试那条补丁之前量的，已不成立。）为什么要这一层：macOS 只把"有 bundle 身份"的进程当正规前台
应用。直接跑 `examples/<示例>/build/<示例>` 这颗裸二进制时 `NSApp.isActive` 起不来，
手点的第一下被系统当成"激活这扇窗口"的点击吃掉，`keyDown` 也没有 firstResponder 可去——
画面上就是**点了没反应、输入框打不进字**，而代码里的一切看起来都正常。实测两份读数只差
一个 bundle：

```
裸：      startup active=false key=false visible=true  main=false
bundle：  startup active=true  key=true  visible=true  main=true
```

**但 bundle 身份只买到上面这组"自报"，它不等于窗口真的在屏。** 实测同一颗产物：
`.app` 起的应用可以 `active/key/visible/main` 全真而 `windowState()['onScreen']=false`
——窗口在窗口服务里有号、有画面，却从不上屏，于是真人键鼠零送达；反倒是裸二进制
`onScreen=true`。少了这两件一定按不动：**主可执行文件是正规文件** +
**签名把 `Info.plist` 绑进去**（`codesign -dvv` 出 `Info.plist entries=N`）。
`bin/gpui` 现在就按这两条产 bundle，门 14（`composer check:bundle`）在 CI 里常驻检查它。
详见《测试与验收》里的"bundle 在屏资格"那一行与 `guide/events` 的对应小节。

所以手工验收真鼠标真键盘请用 `php bin/gpui run input --trace`，别直接跑 build 下那颗文件——
`run` 默认执行的正是那颗裸二进制，区别只在它会先构建、并把示例开关原样转发给产物：
`--trace` 会把每条到达 PHP 的事件追加进 `/tmp/input-events.log`，手点一下输入框就该出现
`click id=…` / `change id=…`。`gpui selftest` 仍固定走 bundle，并附 `--expect-bundle` 让产物硬断言
"我确实是从 bundle 里起的"（`bundled` 读数；信号只能走 argv，nano 里 `getenv()` 编译不过）。

**但 `active` 是可以争不到的**：前台是和"当时握着焦点的那一方"竞争出来的状态。实测同一个
bundle 二进制，几次启动分别量到 `active=true key=true` 与 `active=false key=false`——从
后台 shell 起进程时，macOS 会正当拒绝它抢焦点（`activateIgnoringOtherApps:` 和 Carbon 的
`SetFrontProcessWithOptions` 都不管用，后者实测直接否掉）。因此：

- `App::activate()` 是**有界重试**用的（内部会让 runloop 走几拍，回执才落地），不是一次就成的开关；
  交互模式的示例头 12 帧会自动重试，之后就不再抢（一直抢焦点比"第一下没反应"更糟）。
- `selftest` 报 `activateTries/bundled/frontmost/nsappDeliveries` 四个读数，但只把
  `bundled` 当硬门；`frontmost` 照实报告，不当随机红灯用。
- 用户侧真正的判据是"点一下窗口之后事件到不到"：`--trace` 里出现 `click id=nav-N` 才算通。

## 5. 跑测试门禁

```bash
composer test:all         # Pest + PHPStan + C++ shim_test
php bin/gpui shotdiff check hello   # 视觉回归（需已构建）
```

## 下一步

- 理解 [架构与 C-ABI 防火墙](/guide/architecture)
- 浏览 [组件 API](/guide/components)
- 看 [示例 hello](https://github.com/) 的 `examples/hello/src/main.php`
