# gpui CLI

`bin/gpui` 是打包/开发 CLI。项目根 = 仓库根；示例在 `examples/<name>/`。

```bash
php bin/gpui <command> [args]
# 或经 composer
composer <script>
```

## doctor

检查工具链（bun / clang++ / tpc / php-nano / gpui-kit-cpp 源码）并**逐 marker 校验全部补丁**是否已落（清单以 `GPUI.lock` 为准，`bin/gpui` 里的 `patchManifest()` 与它一一对应）。

```bash
php bin/gpui doctor        # 或 composer doctor
```

## build

一键按序：5 补丁 → `verifyPatches()` → `bun cmd/build.ts`（amalgam+gpui.o）→
`cmd/build-shim.sh`（libgpui_shim.a）→ `tpc --nano`（编 PHP+bridge 并链接）。

```bash
php bin/gpui build hello   # 或 composer bootstrap / composer build
```

产物 `examples/hello/build/hello`。

## run / shot / selftest

编译后运行 / 截图 / 自检：

```bash
php bin/gpui run hello
php bin/gpui shot hello /tmp/x.png      # 内部 --shot，默认 tree 0
php bin/gpui selftest hello             # rc=0 即通过
```

## shotdiff

视觉回归基线（需已构建）：

```bash
php bin/gpui shotdiff record hello      # 生成/刷新 tests/baseline/hello/*.png
php bin/gpui shotdiff check hello       # 逐树像素比对，超容差 rc=1
```

## new

脚手架一个新应用（复制 yml + 生成最小 `main.php`）：

```bash
php bin/gpui new myapp
```

## 环境变量

| 变量 | 默认 | 作用 |
|------|------|------|
| `GPUI_DIR` | `build/gpui-kit-cpp` | 构建沙箱（由 `cmd/sync-upstream.sh` 从 `upstream/gpui-kit-cpp` 复制）；指到别处即"外部源码树"模式 |
| `NANO_DIR` | `vendor/swoole/php-nano` | php-nano 位置（patch-nano 用） |

## composer scripts 一览

| 脚本 | 等价 |
|------|------|
| `composer test` | Pest |
| `composer analyse` | PHPStan level 5 |
| `composer test:cpp` | C++ shim_test |
| `composer test:all` | Pest + PHPStan + shim_test |
| `composer bootstrap` | `bin/gpui build`（全链） |
| `composer patch` | 仅跑全部补丁（不打 amalgam） |
| `composer shot:check` / `shot:record` | shotdiff |
| `composer doctor` | `bin/gpui doctor` |

## 下一步

- [贡献指南](/contributing) 看补丁与上游同步工作流
