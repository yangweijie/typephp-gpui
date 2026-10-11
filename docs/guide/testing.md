# 测试与门禁

六道门禁，全绿即交付。改代码后按此清单跑；改 stub/C++ 后**必跑原生 selftest**（PHP 层门禁
抓不到 AOT 运行期问题）。

| # | 门禁 | 命令 | 覆盖 | 依赖 gpui？ |
|---|------|------|------|------------|
| 1 | Pest | `composer test` | PHP 声明层：Element 序列化、App 路由、FakeBridge | 否 |
| 2 | PHPStan | `composer analyse` | `src/` 类型/静态（level 5） | 否 |
| 3 | C++ shim_test | `composer test:cpp` | shim 纯函数：kindFromString / AStr arena 拷贝 / toComponentArg schema / buildComponentArgs | 是（gpui.o） |
| 4 | selftest | `php bin/gpui selftest hello` | 21 链路端到端：输入取值 / 点击 / 定时器 / dialog / catalog 回调 / 错误回传 / app 菜单 / 剪贴板 / url 白名单 / HUD / Sheet / WebView / 解压 / dirChange / http / 剪贴板完整一项 / 系统状态 / 通知中心 / mac 集成 / 授权文件系统 / 进程与存储 | 是（二进制） |
| 5 | shotdiff | `php bin/gpui shotdiff check hello` | 视觉回归：逐树像素比对基线（18 棵） | 是（二进制） |
| 6 | doctor | `php bin/gpui doctor` | 工具链 + 7 补丁 marker 校验 | 部分 |

一键：`composer test:all`（Pest + PHPStan + shim_test）。

## Pest（PHP 无头）

`tests/ElementTest.php` / `tests/AppTest.php` / `tests/SystemTest.php`，走 `FakeBridge`
（纯 PHP 替身，不碰 gpui）。
函数式 `test()` / `expect()`。覆盖每个工厂的 kind、children/ops 序列化、registered 标记、
枚举预校验。

## C++ shim_test

`tests/cpp/shim_test.cpp` `#include` 整个 `gpui_shim.cpp`，直接测其匿名命名空间里的纯函数。
headless、不开窗口，故任意环境可跑。`cmd/test-shim.sh` 两步编链（`-x objective-c++` 只作用
`.cpp`，链接 amalgam `.o`，不链 `libgpui_shim.a` 以免重复符号）。

它是 **bug #18（arena 字符串悬垂）** 的回归守卫：`AStr` 测试改写源缓冲后断言 arena 副本不变。

## selftest（原生二进制）

`hello --selftest` 跑真实编译产物，验证跨边界事件链。退出码 0 = 通过。二十一步：
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
12. WebView（`ChildView` + host nested-view 注册真建出 OS 控件）。断言的是
    `webviewProbe()` 里**控件自己报**的数：`wry::WebViewUrlTemp` + `WebViewBounds` 给出
    布局实际应用给它的盒子（400×300），再加 back/forward 两次页内 eval 成功。
    OK 行末把那个盒子打出来，便于看出"存在但零尺寸"这种假通过。
13. 解压（`App::inflate`）：喂**外部真数据**——两串由开发机上 PHP 的 zlib 生成的
    "hello gpui" 容器（zlib 与 gzip 各一），不是 gpui 自己写的 stored 壳。坏数据返回 null。
    同一批向量在 `tests/cpp/shim_test.cpp` 与 `tests/SystemTest.php` 里也各解一遍，三方钉一处。
14. 目录监听（`dirChange`）：走 gpui 自己的测试接缝（`DirWatchAdd` + `DirWatchSignal`，
    `dir_watch.h:86` 明说这就是给测试留的），**完全不碰文件系统**，验的是
    "信号 → 主线程 post → 入队 → poll → dispatch" 整条路。OS 那一半（FSEvents/inotify/
    ReadDirectoryChangesW）是 gpui 自己的代码，不在本项目的验收面里。
15. HTTP（`http` 事件）：打 `http://127.0.0.1:1/` ——loopback 上必然没人监听的端口，
    于是 executor → 主线程 done → 入队 → poll → `httpResponse()` 整条异步路都走通，
    而**不碰外网**。断言的是"事件到了 + 应答是 ok=false/status=0 + 读第二次为 null"，
    不是"能上网"；`data:` 这类 scheme 在发送前就被拒。

第 15 步是**故意只验失败路径**的：无人值守的门禁不该有外网副作用，也不该有超时抖动。
成功路径靠 `HttpUrlIsRemote` 的判据表（C++ 侧）+ 需要时人跑一次。

16. 剪贴板完整一项（`clipboardItem`）：写过文本之后同一快照里应当只有文本——图像与文件
    必须报"没有"。这验的是 flags 判别，不依赖粘贴板里恰好有什么。
17. 系统状态（`App::sysInfo`）：`memPct`/`diskPct`/`memTotal`/`diskTotal`/`ncpu` 是直接
    读数，第一次采样就能断言非零；`cpu` 是差值量，所以先断言"第一次报 0"（这是文档承诺的
    行为，不是巧合），再烧 150ms 单核、泵两帧，第二次采样必须 > 0。烧单核在 8 核机器上约
    12%，离 0 有足够余量——不是靠机器噪声过关。`procs` 验前 5 条非空且首条有 pid 与名字。
18. 通知中心（`App::notify`）：推一条带 id 的通知 → `notificationCount` 1 → `dismiss` →
    泵帧后计数归 0，并且 PHP 侧收到一条 `close` 事件。验的是"推得进、退得出、回调打得回来"
    这条路由；卡片画不画得出来归 tree 32 的像素。
    **`click` 不在自检里**：合成指针打不到浮层里那张卡，所以它只有两条路——静态核对
    listener 接线（`OnItemClick` → `items[at].onClick`）与交互 demo 的 `Notify` 按钮
    （点卡片正文，状态行会报"卡片被点了"）。
19. mac 系统集成：托盘挂上→计数 1→撤掉→计数 0；注册一条 `cmd+shift+F13`，然后
    `fireHotkeyForTest(0)` **合成**一条 Carbon 事件，PHP 必须收到 `hotkey`。
    这一步顺手答掉了 Phase 10 的探针 A：我们的泵只有 `nextEventMatchingMask:`，
    所以 `gpui_shim_pump` 里自己排空 Carbon 队列（`ReceiveNextEvent` +
    `SendEventToEventTarget`）——合成事件能走完派发链，说明这条路是通的。
    **它不证明系统级全局捕获**，那一条在手工清单里。
20. 授权文件系统：`grantRoot('/tmp', true)` → `fsMkdir` → `fsWrite` → 用 PHP 自己的
    `file_get_contents` 读回来（gpui 写的 PHP 要看得见），再反向：PHP 写、`fsRead` 读
    （含 `\0` 的二进制要原样穿过）。未授予的 `/etc/hosts` 必须返回 null 且
    `fsError()` 说得出"根"。最后 `fsRemove(dir, recursive)` 把整棵树带走。
21. 进程与存储：白名单里的 `/bin/echo` 跑通并带回 stdout；`/bin/cat` 被拒且原因含
    "白名单"。存储没授权时 `storageSet` 必须 false，授权后 set/get/len 自洽。

## `--clip`：把"能改粘贴板的东西"变成测试装置

图像与文件两条通道要有**真数据**才验得到，而自检不能依赖粘贴板里恰好有什么。
`hello --clip` 因此存在：开一个窗口、读一次粘贴板、打成一行 `text=/image=/paths=`
（`-1` = 该表示没有）。于是：

```
printf 'hello clip text\n' | pbcopy && hello --clip   →  text=16 image=-1 paths=0
（⌘⇧⌃3 截一张图）           && hello --clip   →  text=-1 image=<字节数> paths=0
（Finder 里复制文件）        && hello --clip   →  text=-1 image=-1 paths=1
```

本轮用 AppKit `writeObjects:` 脚本化写入验过全部四种组合（含文本+PNG 同时存在的一例），
结果记在[系统能力](/guide/system-capabilities#剪贴板三通道是这么验的)那张表里。
**别用 AppleScript 的 `set the clipboard to {POSIX file ...}` 验文件通道**——它写的是
alias list，不是 `public.file-url`，读回来永远是 `paths=0`，看着像代码坏了。

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
tree 29（inspector）拍到的是整窗高亮描边——`WindowToggleInspector` 是拾取模式开关，
不是面板；面板由 `El::inspector()` 摆（tree31：右侧 320px 的 Inspector 面板 + 拾取按钮）。
tree 30 是 WebView，它那块在离屏截图里**必然是空白**（`cacheDisplayInRect` 合成不了
跨进程表面），所以这张基线只量"webview 没把布局搞塌"，控件本身归第 12 步。
tree 27 是**唯一非零残差**的基线（约 0.0002）：帧率 HUD 是活表，数字随帧变化，容差吸收得下；
换台慢机器若超容差，应改判它的像素验收为"存在 HUD"而非"HUD 内容一致"。
tree 32（通知层）**必须跑够墙钟才拍得到**：通知入场是动画，`Starting` 段的不透明度
= `elapsedMs / 400`（`ui/notification.cpp:948`），而推它的是 50ms 的墙钟计时器——只泵
3 帧等于拍在 0 上，图上只有宿主标题、两张卡一片透明，`SHOT OK` 却照样成立。现在跑
80 帧（约 1.6s），并且与 600 帧的产物逐字节相同，说明拍到的是动画走完的静止帧。
两条通知故意分放 `top_right` / `bottom_left`：同一角会叠成 collapsed stack（新的在前、
旧的只露一条边），分开才两张都拍全，顺带把 `placement` 的锚点映射钉在像素上。

## CI

`.github/workflows/ci.yml` 四个 job：

| job | 跑什么 | runner | 实测 |
| --- | --- | --- | --- |
| `php` | `php -l` 全源 + Pest + PHPStan | ubuntu-24.04 / PHP 8.5 | ~14s |
| `docs` | `npm ci` + `vitepress build`（顺带是死链门禁）+ 产物页数与 `base` 断言 | ubuntu-24.04 / Node 24 | ~12s |
| `native` | `bin/gpui build hello`（同步沙箱→7 补丁→amalgam→shim 库→tpc nano）+ `cmd/test-shim.sh` + `hello --selftest` | **macos-15** | ~3m27s |
| `deploy` | 把 `docs` 的产物发到 GitHub Pages（只在 main 的 push） | ubuntu-24.04 | ~45s |

**门禁 3/4 现在真的进 CI 了**——前提是 gpui-kit-cpp 已 vendored 进本仓库
（`upstream/gpui-kit-cpp`，构建时由 `cmd/sync-upstream.sh` 复制成沙箱 `build/gpui-kit-cpp`，
补丁与 amalgam/out 都落沙箱，跟踪树不被弄脏）。这一跑同时答了一个没把握的问题：
**托管 macOS runner 能开真窗口**——`--selftest` 的 21 条链路里有窗口、有事件、有 retained
取值，它绿了。

**门禁 5（`--shot` / shotdiff）仍只在本地**：它抓的是真实在屏 `NSView`，CI 上没有可依赖的
窗口会话与显示，硬跑会得到"能出图但图是空的"这种最坏证据。这一刀归人。

## 下一步

- [gpui CLI](/guide/cli) 看这些命令的入口
- [贡献指南](/contributing) 看补丁工作流
