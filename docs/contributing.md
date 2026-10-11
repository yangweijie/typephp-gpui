# 贡献指南

## 开发环境

见 [快速开始](/guide/getting-started)。核心：`composer install` + 备好 gpui-kit-cpp 源码
（`GPUI_DIR`）+ bun + clang。

## 目录结构

```
src/                PHP 声明层（App / Element / El / FakeBridge）
php-src/gpui.stub.php   C ABI 的 PHP 侧声明（空体）
cpp-src/
  gpui_shim.h       纯 C ABI 契约
  gpui_shim.cpp     C++20，唯一 include gpui.h
  gpui_bridge.cc    C++17，只认 phpx + gpui_shim.h
cmd/                补丁 + 构建 + 测试脚本
examples/hello/     示例 + --selftest + --shot 测试树
tests/              Pest + C++ shim_test + 视觉基线
docs/               本站（VitePress）
GPUI.lock           gpui-kit-cpp 依赖契约 + 补丁 marker 表
```

## 补丁工作流

上游 gpui-kit-cpp 无 git remote，我们以幂等补丁改它（`cmd/patch-*.sh`，python3 heredoc +
marker 判重）。每个补丁：

1. `GPUI_DIR` 默认定位源码；marker 已在则 skip。
2. 改 `runtime.h`/`runtime.cpp`/`view.cpp` 等，加薄公开入口或事件旁路。
3. 打补丁后**必须**重跑 `bun cmd/build.ts` 生成 amalgam，再 `cmd/build-shim.sh`。

补丁清单与 marker 见 `GPUI.lock`；`bin/gpui doctor` 校验全落。加新补丁记得同步：
`bin/gpui` 的 `patchManifest()` + `composer patch` 列表 + `GPUI.lock` 表。

## 改代码的验证纪律

- **只改 PHP**：`composer test:all`（Pest + PHPStan + shim_test）足够。
- **改 stub / bridge / shim / 上游补丁**：必须 `composer bootstrap` 重建，再
  `php bin/gpui selftest hello`（原生六链路）+ `php bin/gpui shotdiff check hello`。
  血泪教训：把 stub `gpui_app_create` 改 `: void` 过 phpstan，PHP 层门禁全绿但原生
  selftest 段错误——**AOT 运行期问题只有跑二进制才暴露**。
- 视觉有改动：确认是预期后 `php bin/gpui shotdiff record hello` 刷新基线。

## 加一个 catalog 组件

1. 读上游 `src/component_shell/<cat>/<comp>.cpp`，抄准导出名、构造器 schema、方法名、
   枚举字面量。
2. `Element.php` 加工厂（`reg(导出名, ctorArgs)`）+ 必要时枚举常量 + sugar（走 `rm()`）。
   枚举 sugar 用 `enumCheck` 预校验。
3. `main.php` 加一棵 `--shot` 测试树，`bin/gpui build hello` 后逐树 Read PNG 核对。
4. Pest 补 `registered=true` / `ctorArgs` / op 序列化断言。

## 提交风格

`type: 摘要`（type ∈ feat/fix/test/build/docs/chore），正文说"为什么"。一个提交一件事。
本仓库已 git 化（见首提交）。

## 文档站

`docs/` 是 VitePress。本地预览：

```bash
cd docs && npm install && npm run docs:dev
npm run docs:build   # 产物 docs/.vitepress/dist
```
