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

上游无 git remote，需自备源码树，放到 `vendor/gpui-kit-cpp`（或设 `GPUI_DIR` 指到任意路径）：

```bash
export GPUI_DIR=/path/to/gpui-kit-cpp   # 可选；默认 vendor/gpui-kit-cpp
```

`bin/gpui doctor` 会检查源码是否就位、5 个补丁是否已落。

## 3. 一键构建

```bash
composer bootstrap        # 等价 bin/gpui build hello
# 或
php bin/gpui build hello
```

内部按序：打 5 个补丁 → `verifyPatches()` 校验 → `bun cmd/build.ts` 生成 amalgam →
`cmd/build-shim.sh` 编 C++20 静态库 → `tpc --nano` 编 PHP+C++17 bridge 并链接。
产物：`examples/hello/build/hello`。

## 4. 运行 / 自检 / 截图

```bash
php bin/gpui run hello            # 跑起来（带窗口）
php bin/gpui selftest hello       # 无交互六链路自检，rc=0 即通过
php bin/gpui shot hello /tmp/x.png 0   # 渲染测试树存 PNG
```

## 5. 跑测试门禁

```bash
composer test:all         # Pest + PHPStan + C++ shim_test
php bin/gpui shotdiff check hello   # 视觉回归（需已构建）
```

## 下一步

- 理解 [架构与 C-ABI 防火墙](/guide/architecture)
- 浏览 [组件 API](/guide/components)
- 看 [示例 hello](https://github.com/) 的 `examples/hello/src/main.php`
