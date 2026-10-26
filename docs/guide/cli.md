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

一键按序：同步上游到沙箱 → 执行 `patchManifest()` 全部补丁 → `verifyPatches()` →
`bun cmd/build.ts`（amalgam+gpui.o）→ `cmd/build-shim.sh`（libgpui_shim.a）→
`tpc --nano`（编 PHP+bridge 并链接）。补丁清单以 doctor 输出为准，不另写固定支数。

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

`<example>` 是 `examples/` 下的目录名：`hello` 是那条完整链路的样例，其余每一支都是一个
**功能域**（名字就是域，`ls examples/` 即清单）。它们的开关是同一套：`--sections` 自报像素树号、
`--shot <path> [tree]` 出图、`--selftest` 跑本域的门。一支只装一域，所以编译只花这一域的源，
自检也边跑边打（一步一行，崩在哪行停在哪行）。

```bash
php bin/gpui build input --no-native    # 只跑 tpc，复用已建好的 gpui.o + libgpui_shim.a
```

`gpui build` 默认每次都要重编 amalgam（`cmd/sync-upstream.sh` 的 `rsync --delete` 会连
沙箱里的 `.work/` 与 `out/` 一起清掉），而那份产物是所有示例共用的。所以**一轮里只第一支
走完整链，其余加 `--no-native`**；那两个前置产物不在时会拒绝，但“存在”不等于“与当前原生源码一致”。
改了 C++ 或补丁必须先完整重编，不能靠 `--no-native` 判断新鲜度。

另有几个把"人来做的那一步"变成有结论的命令的开关，它们各自住在最贴的那一支里：

```bash
php bin/gpui run input --trace         # 每个事件写 /tmp/input-events.log
php bin/gpui run input --wait-key 150  # 真人手按验收：按到 → rc=0，没按到 → rc=1
php bin/gpui run input --automation /tmp/auto  # 外部写命令、真窗口执行、目录里拿回执（单独用即可）
php bin/gpui run overlay --escape      # 进程里第一个 dialog 的 Escape 探针
php bin/gpui build layout --no-native && ./examples/layout/build/layout --dock  # Dock 交互
```

未知开关一律**当场拒绝**（`未知开关: …` + rc=2）。这条不是洁癖：静默忽略会一路落到交互
`run()` 去开一扇窗口，于是"跑一下某个模式"变成永久挂住，什么结论都拿不到。

`examples/overlay` 也带 `--escape`，而且是它的**同一条命令、更小的窗口**：

```bash
php bin/gpui run overlay --escape                # 实测 ESCAPE-FIRST opened=1 trapAfterOpen=0
                                                 # trapAfterFrames=993756471 closed=true → rc=0
```

这条读数是 #62 的结案证据：`trapAfterOpen=0` 而泵 10 帧后非零 ⇒ 焦点陷阱是**要几帧才生效**，
不是"永不生效"；`closed=true` ⇒ 真 Escape 到达并把 dialog 关了。之前记的"Escape 不关"是
在"bundle 启动后不自提升为前台应用"那次修好之前测的，之后没人重跑过这条探针——
重跑一次的代价（整链构建 3 分钟 vs 单支隔离 40 秒）就是没人重跑的原因。

`--escape` 是**探针不是门**：它不参与任何 `--selftest` 的 rc，因为它量的是"真按键有没有送达"
（#56 那一格），拿它当硬门会把前台归属这种 OS 竞争态变成随机红灯。

`--wait-key` 是给"窗口不是 key window 就没有 firstResponder"那一格用的：前台归属是跟
IDE/终端竞争来的，自动化不保证拿到（详见[事件与回调](./events)），所以让命令自己把读数与
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

## 交付身份（project.macos.yml 的四个可选键）

`gpui build` / `run` / `selftest` 打包 `.app` 时，`CFBundleIdentifier` / `CFBundleName` /
`CFBundleShortVersionString` / 图标都从示例自己的 yml 读。**不写就是老行为**
（`top.typephp.gpui.<example>` / `gpui-<example>` / `1.0` / 无图标），所以现有示例一行不用改。

```yaml
name: gpui-hello
bundle-id: com.example.hello      # 只能是 A-Za-z0-9.-，非法值回落缺省并告警
bundle-name: Hello 应用           # 缺省 name: 的值，UTF-8 可以
bundle-version: 1.4.2             # 数字点分，非法值回落 1.0
icon: assets/App.icns             # 相对本 yml 的目录；发布质量用 .icns，.png 开发期够用
```

`icon` 的文件被复制进 `Contents/Resources/`（只取 basename，`../` 逃不出 bundle），
plist 里加一条 `CFBundleIconFile`。把 `icon:` 删掉后 plist 不再引用它，
`Resources/` 里的旧文件会留着——惰性、无害，要干净就自己删。

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
| `composer test:all` | Pest + PHPStan + 上游词表 parity + shim_test |
| `composer bootstrap` | `bin/gpui build`（全链） |
| `composer patch` | 仅跑全部补丁（不打 amalgam） |
| `composer shot:check` / `shot:record` | shotdiff |
| `composer doctor` | `bin/gpui doctor` |

## 下一步

- [贡献指南](/contributing) 看补丁与上游同步工作流
