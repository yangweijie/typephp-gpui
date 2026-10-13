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

想看**全部**特性不必翻 API 表：`php bin/gpui run showcase` 起一个 13 节的目录 app
（基础原语 / 输入 / 选择与开关 / 布局 / 表格列表 / catalog 富组件 / 侧栏表单菜单 /
浮层 / 系统能力 / 服务 / WebView·Inspector / 样式图标 / mac 集成）。
每一节都是真调用，而"有没有漏掉某个 API"由 `tests/ShowcaseTest.php` 反射 `src/Element.php`
与 `src/App.php` 逐名核对——少写一个 CI 就红，所以"展示了所有特性"是可断言的，不是自述。

想只测**一个功能域**（改输入、改浮层这类）就跑按域拆出来的独立示例，比如
`php bin/gpui selftest input`：它的 sources 只列 `examples/_shared/features/inputs.php`
加共用状态表 `examples/_shared/state.php`，所以编译快、报告短，而且自检是**边跑边打**的
（每步一行，崩在哪行就停在哪行）。同一份域源也被画廊的 yml 收录，所以不存在两份实现——
拆分的粒度是"编译单元"，不是"代码副本"。

## 4. 运行 / 自检 / 截图

```bash
php bin/gpui run hello            # 跑起来（带窗口）
php bin/gpui selftest hello       # 无交互六链路自检，rc=0 即通过
php bin/gpui shot hello /tmp/x.png 0   # 渲染测试树存 PNG
```

### `run`/`selftest` 会把二进制包进 `.app` 再启动（这不是包装，是前提）

`php bin/gpui run <示例>` 实际执行的是
`examples/<示例>/build/<示例>.app/Contents/MacOS/<示例>`（里面是指向构建产物的 symlink，
重建后 bundle 自动跟着新）。为什么要这一层：macOS 只把"有 bundle 身份"的进程当正规前台
应用。直接跑 `examples/<示例>/build/<示例>` 这颗裸二进制时 `NSApp.isActive` 起不来，
手点的第一下被系统当成"激活这扇窗口"的点击吃掉，`keyDown` 也没有 firstResponder 可去——
画面上就是**点了没反应、输入框打不进字**，而代码里的一切看起来都正常。实测两份读数只差
一个 bundle：

```
裸：      startup active=false key=false visible=true  main=false
bundle：  startup active=true  key=true  visible=true  main=true
```

所以手工验收真鼠标真键盘请用 `php bin/gpui run showcase --trace`，别直接跑 build 下那颗文件；
`--trace` 会把每条到达 PHP 的事件追加进 `/tmp/showcase-events.log`，手点一次导航就该出现
`click id=nav-N`。`gpui selftest` 同样走 bundle，并附 `--expect-bundle` 让产物硬断言
"我确实是从 bundle 里起的"（`bundled` 读数；信号只能走 argv，nano 里 `getenv()` 编译不过）。

**但 `active` 是可以争不到的**：前台是和"当时握着焦点的那一方"竞争出来的状态。实测同一个
bundle 二进制，几次启动分别量到 `active=true key=true` 与 `active=false key=false`——从
后台 shell 起进程时，macOS 会正当拒绝它抢焦点（`activateIgnoringOtherApps:` 和 Carbon 的
`SetFrontProcessWithOptions` 都不管用，后者实测直接否掉）。因此：

- `App::activate()` 是**有界重试**用的（内部会让 runloop 走几拍，回执才落地），不是一次就成的开关；
  showcase 的头 12 帧会自动重试，之后就不再抢（一直抢焦点比"第一下没反应"更糟）。
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
