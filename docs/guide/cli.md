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

`<example>` 是 `examples/` 下的目录名，除 hello/showcase 外还有按功能域拆出来的独立示例
（名字就是域，`ls examples/` 即清单）。它们的开关是同一套：`--sections` 自报像素树号、
`--shot <path> [tree]` 出图、`--selftest` 跑本域的门。与 showcase 的差别只在**范围**：
一支只装一域，所以编译只花这一域的源，自检也边跑边打（一步一行，崩在哪行停在哪行）。

```bash
php bin/gpui build input --no-native    # 只跑 tpc，复用已建好的 gpui.o + libgpui_shim.a
```

`gpui build` 默认每次都要重编 amalgam（`cmd/sync-upstream.sh` 的 `rsync --delete` 会连
沙箱里的 `.work/` 与 `out/` 一起清掉），而那份产物是所有示例共用的。所以**一轮里只第一支
走完整链，其余加 `--no-native`**；那两个前置产物不在时它会直接拒绝，不会静默链到陈旧产物上。

showcase 还带几个把"人来做的那一步"变成有结论的命令的开关：

```bash
php bin/gpui run showcase --trace                # 每个事件写 /tmp/showcase-events.log
php bin/gpui run showcase --probe-events         # 部件级事件送达探针（rc=0 才算量到）
php bin/gpui run showcase --escape               # 进程里第一个 dialog 的 Escape 探针
php bin/gpui run showcase --wait-key 150         # 真人手按验收：按到 → rc=0
```

`examples/overlay` 也带 `--escape`，而且是它的**同一条命令、更小的窗口**：

```bash
php bin/gpui run overlay --escape                # 实测 ESCAPE-FIRST opened=1 trapAfterOpen=0
                                                 # trapAfterFrames=993756471 closed=true → rc=0
```

这条读数是 #62 的结案证据：`trapAfterOpen=0` 而泵 10 帧后非零 ⇒ 焦点陷阱是**要几帧才生效**，
不是"永不生效"；`closed=true` ⇒ 真 Escape 到达并把 dialog 关了。之前记的"Escape 不关"是
在"bundle 启动后不自提升为前台应用"那次修好之前测的，之后没人重跑过这条探针——
重跑一次的代价（画廊 3 分钟 vs 隔离 40 秒）就是没人重跑的原因。

`--escape` 是**探针不是门**：它不参与任何 `--selftest` 的 rc，因为它量的是"真按键有没有送达"
（#56 那一格），拿它当硬门会把前台归属这种 OS 竞争态变成随机红灯。

`--wait-key` 是给"窗口不是 key window 就没有 firstResponder"那一格用的：前台归属是跟
IDE/终端竞争来的，自动化拿不到（详见[事件与回调](./events)），所以让命令自己把读数与
结论打出来，而不是让人猜"我这一下到没到"。

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
