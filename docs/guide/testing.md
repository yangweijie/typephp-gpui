# 测试与门禁

六道门禁，全绿即交付。改代码后按此清单跑；改 stub/C++ 后**必跑原生 selftest**（PHP 层门禁
抓不到 AOT 运行期问题）。

| # | 门禁 | 命令 | 覆盖 | 依赖 gpui？ |
|---|------|------|------|------------|
| 1 | Pest | `composer test` | PHP 声明层：Element 序列化、App 路由、FakeBridge | 否 |
| 2 | PHPStan | `composer analyse` | `src/` 类型/静态（level 5） | 否 |
| 3 | C++ shim_test | `composer test:cpp` | shim 纯函数：kindFromString / AStr arena 拷贝 / toComponentArg schema / buildComponentArgs | 是（gpui.o） |
| 4 | selftest | `php bin/gpui selftest hello` | 六链路端到端：输入取值 / 点击 / 定时器 / dialog / catalog 回调 / 错误回传 | 是（二进制） |
| 5 | shotdiff | `php bin/gpui shotdiff check hello` | 视觉回归：逐树像素比对基线 | 是（二进制） |
| 6 | doctor | `php bin/gpui doctor` | 工具链 + 5 补丁 marker 校验 | 部分 |

一键：`composer test:all`（Pest + PHPStan + shim_test）。

## Pest（PHP 无头）

`tests/ElementTest.php` / `tests/AppTest.php`，走 `FakeBridge`（纯 PHP 替身，不碰 gpui）。
函数式 `test()` / `expect()`。覆盖每个工厂的 kind、children/ops 序列化、registered 标记、
枚举预校验。

## C++ shim_test

`tests/cpp/shim_test.cpp` `#include` 整个 `gpui_shim.cpp`，直接测其匿名命名空间里的纯函数。
headless、不开窗口，故任意环境可跑。`cmd/test-shim.sh` 两步编链（`-x objective-c++` 只作用
`.cpp`，链接 amalgam `.o`，不链 `libgpui_shim.a` 以免重复符号）。

它是 **bug #18（arena 字符串悬垂）** 的回归守卫：`AStr` 测试改写源缓冲后断言 arena 副本不变。

## selftest（原生二进制）

`hello --selftest` 跑真实编译产物，验证跨边界事件链。退出码 0 = 通过。十一步：
1. 输入取值回环（setInput → InputSetValue → InputValue）
2. 点击事件全链路（合成 fire → gpuiEnqueueClick → boxForView → 队列 → poll → dispatch）
3. 定时器 tick
4. dialog 开/关计数
5. catalog 回调回环（DropdownMenu.item → InvokeWith → shimId 旁路 → 队列）
6. registered 错误回传（空 id Alert → registeredError 非空）
7. 原生 app 菜单条安装（setAppMenus → BaseSetAppMenus → appMenuCount 读回）
8. 剪贴板回环（写系统粘贴板再读回，走 NSPasteboard 不是我们的缓存）
9. `openUrl` 白名单**被拒的一侧**（`javascript:` → false）。放行的一侧会真拉起浏览器，
   无人值守的自检不该有这种副作用；接受路径由 `tests/cpp/shim_test.cpp` 直接量
   `gpui::IsOpenableUrl` 钉住
10. 帧率 HUD 生命周期（show → visible → 重复 show → hide → 再 hide 为 false）
11. Sheet 生命周期（openSheet → hasSheet → closeSheet → hasSheet 假）

`promptForPath` **不在自检里**：原生对话框自带嵌套消息循环，一直等用户选完，无人值守会挂死。
它的手工路径在交互 demo 里（`Open…` 按钮，从点击回调中调用），见
[系统能力](/guide/system-capabilities) 的验收矩阵。

## shotdiff（视觉回归）

`cmd/shotdiff.php`：`--shot` 抓真实在屏 NSView 出 PNG，与 `tests/baseline/<ex>/tree<N>.png`
逐像素比对（imagick RMSE，回落 gd，容差 0.001）。

- `php bin/gpui shotdiff record hello` —— 生成/刷新基线
- `php bin/gpui shotdiff check hello` —— 比对，超容差 rc=1

同机 `--shot` 逐字节确定。它**首跑就抓到了** dialog double-free（tree13 未关就 close → SIGABRT）。
跳过 tree 24（MenuBar 现报 root-app 错误节点，不纳入基线以免固化坏态）。
tree 27 是**唯一非零残差**的基线（约 0.0002）：帧率 HUD 是活表，数字随帧变化，容差吸收得下；
换台慢机器若超容差，应改判它的像素验收为"存在 HUD"而非"HUD 内容一致"。
tree 29（inspector）拍到的是整窗高亮描边——`WindowToggleInspector` 是拾取模式开关，
不是面板；面板需要 nested-view 挂载，见系统能力页的"未接"表。

## CI

`.github/workflows/ci.yml` 只跑自足的 PHP 层（php -l + Pest + PHPStan）。原生链（3/4/5）依赖
gpui-kit-cpp 源码，托管 runner 拉不到 → 本地或 self-hosted 跑。

## 下一步

- [gpui CLI](/guide/cli) 看这些命令的入口
- [贡献指南](/contributing) 看补丁工作流
