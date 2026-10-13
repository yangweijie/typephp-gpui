<?php

/**
 * TypePHP GPUI — C++ 桥接契约。
 *
 * ⚠ 本文件只声明类型，**函数体必须为空**。C++ 实现在 cpp-src/gpui_bridge.cc 下，
 *   每个 PHP 函数 `gpui_foo()` 对应一个 C++ 符号 `php_gpui_foo()`。
 *
 * 设计约定（与 typephp-qt 的 qt.stub.php 同构）：
 *
 *  1. **元素树走 Element 对象。** App.render() 传一棵 Element 树，
 *     bridge 侧翻译成 SpecArena 的 Push/PushOp/Attach 调用。
 *  2. **不透明句柄走 mixed。** 窗口句柄是 php::Box resource，PHP 只持有不窥探。
 *  3. **事件只入队。** gpui-kit-cpp 的 ScriptView handler（经 sed 补丁）
 *     把 {type, callbackId, value} 入队到 bridge；PHP 主循环 poll_event 取走分派。
 */

/* ─────────────────────── 应用与窗口生命周期 ─────────────────────── */

/** 初始化 gpui App + component::Init + ShellRuntime + 事件泵基础设施。幂等。
 *  返回类型须与 bridge 的 php_gpui_app_create()（返回 Variant）一致，声明 void 会让
 *  AOT 生成的返回值写回错位 → 段错误。副作用调用，调用方忽略返回值即可。 */
function gpui_app_create(): mixed {}

/**
 * 打开一个窗口并挂载 ScriptView（其 Render 从 bridge 拉当前 Element 树 materialize）。
 * $title 标题，$width/$height DIPs。返回窗口 Box resource。
 */
function gpui_window_open(string $title, int $width, int $height): mixed {}

/**
 * 渲染一棵 Element 树到窗口。bridge 把 Element 翻译进 SpecArena，
 * 触发 ScriptView dirty → 下一帧 materialize。幂等，可任意次数调用。
 */
function gpui_render(mixed $window, array $tree): void {}

/** 窗口是否仍打开。 */
function gpui_window_is_open(mixed $window): bool {}

/** 驱动 gpui 事件循环一个时间片（泵 NSEvent + draw frame），然后返回 PHP。 */
function gpui_pump_events(mixed $window): void {}

/** 取一个待处理事件；无事件时返回空数组 []。结构 ['type'=>..,'id'=>..,'value'=>..]。 */
function gpui_poll_event(mixed $window): array {}

/** 关闭窗口。 */
function gpui_window_close(mixed $window): void {}

/* ─────────────────────── 取值 ─────────────────────── */

/** 读取 Input/Textarea 元素的当前文本值。 */
function gpui_input_value(mixed $window, string $id): string {}

/** 程序化设置 Input/Textarea 元素文本（InputSetValue）；成功返回 true。供自检/预填。 */
function gpui_set_input_value(mixed $window, string $id, string $text): bool {}

/** 自检：为已注册回调合成一次事件（走真实入队路径）；成功返回 true。 */
function gpui_selftest_fire(mixed $window, string $id, string $type): bool {}

/** 自检：直接触发一个 catalog 组件回调（InvokeWith → shimId 旁路 → 队列）；成功返回 true。 */
function gpui_selftest_fire_registered(mixed $window, string $id, string $type): bool {}

/** 离屏渲染窗口内容存成 PNG（$w/$h 为逻辑点，内部 2x）；成功返回 true。 */
function gpui_snapshot(mixed $window, string $path, int $w, int $h): bool {}
/** 真输入验收缝：type 0=移动 1=左键单击。返回 1 = PHP 队列里确实多出一条事件。 */
function gpui_real_mouse(mixed $window, int $x, int $y, int $type): int {}
function gpui_real_key(mixed $window, int $keycode, string $chars): int {}
/** 合成一次 ShellAction 派发（按名字）：1 = 被监听接住。 */
function gpui_dispatch_action(mixed $window, string $name): int {}
/** 当前持有焦点的 gpui 实体 id；0 = 没有焦点，-1 = 窗口不可用。 */
function gpui_focused_id(mixed $window): int {}
/** 输入框诊断：['n'=>登记的 input 数, 'x','y','w','h'=>最后一颗的 inputBounds]。 */
function gpui_input_probe(mixed $window, int $index): array {}
/** 命中表探针：['n'=>条数, 'x','y','w','h'=>第 index 条的矩形, 'id','path','click'(0/1),'parent']。 */
function gpui_hit_probe(mixed $window, int $index): array {}

/** 窗口状态：['found'=>int, 'active'=>bool, 'key'=>bool, 'visible'=>bool, 'main'=>bool]。 */
function gpui_window_state(mixed $window): array {}

/** 有界地再申请一次前台（详见 App::activate）；1 = 此刻应用 active 且窗口 key。 */
function gpui_activate(mixed $window): int {}

/** 诊断：dump 当前 spec arena 树为文本。 */
function gpui_debug_tree(mixed $window): string {}

/** 取本帧最近一次 catalog 组件（registered）构造/方法失败的原因；无错误返回空串。 */
function gpui_last_error(mixed $window): string {}

/** 弹一条 toast：$level=info|success|warning|error，$timeoutMs<=0 用默认 5s。 */
function gpui_toast(mixed $window, string $title, string $desc, string $level, int $timeoutMs): void {}

/** 用一棵 Element 树打开 dialog；返回打开后的 dialog 数（>0 成功）。 */
function gpui_open_dialog(mixed $window, array $tree, bool $escape, bool $backdrop): int {}

/** 当前打开的 dialog 数。 */
function gpui_dialog_count(mixed $window): int {}

/** 关闭最上层 dialog；成功返回 true。 */
function gpui_close_dialog(mixed $window): bool {}

/** 装原生 app 菜单条（macOS 系统菜单栏）。$menus=[[name,items=[[label,action?,separator?,disabled?,checked?]]]]；返回装入的菜单数。 */
function gpui_set_app_menus(mixed $window, array $menus): int {}

/** 读回已装 app 菜单数（验证用）。 */
function gpui_app_menu_count(mixed $window): int {}

/** 原生菜单条各行携带的动作 id（按行号顺序，跳过分隔符等无动作的行）。 */
function gpui_menu_rows(): array {}

/** 各内部容器条数（内存增长仪表）：timerKeys/timerIds/callbacks/cbByKey/shimCallbacks/
 *  inputHandles/lastValues/webviews/inspectors/notifyLists/dialogs/watchCtx/httpResponses。 */
function gpui_state_counts(mixed $window): array {}

/** 读取 checkbox/switch/slider 等元素的当前值。 */
function gpui_element_value(mixed $window, string $id): mixed {}

/**
 * 回读 spec 里该元素 id 的 index 槽：TableRow 用它当行号、TableHead/TableCell 当列号
 * （上游 materialize 拿它喂 AriaRowIndex/AriaColumnIndex）。没有这个 id 时返回 -1，
 * 以便与"有、且 index 是 0"区分。存在的意义是让自检能证明行/列号真的进了 spec。
 */
function gpui_spec_index(mixed $window, string $id): int {}

/* ─────────────────────── 定时器与主题 ─────────────────────── */

/** 注册周期定时器，到点收到 ['type'=>'timer','id'=>$id] 事件。intervalMs<=0 取消。 */
function gpui_set_timer(mixed $window, string $id, int $intervalMs): void {}

/** 切换主题：'light' | 'dark'。 */
function gpui_set_theme(mixed $window, string $mode): void {}

/* ─────────────────────── 系统能力 ─────────────────────── */

/** 读系统粘贴板文本；空剪贴板/内容非文本返回空串（App 读成 null）。 */
function gpui_clipboard_text(mixed $window): string {}

/** 写系统粘贴板文本。 */
function gpui_set_clipboard_text(mixed $window, string $text): void {}

/** 读剪贴板完整一项：['flags'=>int,'text'=>string,'image'=>string,'paths'=>array]。
 *  flags 1=文本 2=图片 4=文件路径；image 是编码后的图像流（PNG/TIFF），不是 RGBA。 */
function gpui_clipboard_item(mixed $window): array {}

/**
 * 原生文件/目录选择对话框，返回选中路径；取消或平台无对话框返回空串。
 * ⚠ 只能在事件回调里调：对话框自带嵌套消息循环，跑到用户选完为止。
 */
function gpui_prompt_for_path(mixed $window, bool $directories, string $title): string {}

/** 交给系统浏览器（先过 gpui 的 IsOpenableUrl 白名单）。被拒返回 false。app 级，无窗口参数。 */
function gpui_open_url(string $url): bool {}

/** 开/关窗口 inspector（DevTools），与 ⌘I 同一个。 */
function gpui_toggle_inspector(mixed $window): void {}

/** 弹帧率 HUD，$anchor 见 App::FPS_ANCHORS；非法名或渲染期间调用返回 false。 */
function gpui_show_fps(mixed $window, string $anchor): bool {}

/** 收起帧率 HUD；原本没弹返回 false。 */
function gpui_hide_fps(mixed $window): bool {}

/** 帧率 HUD 当前是否可见。 */
function gpui_fps_visible(mixed $window): bool {}

/** 用一棵 Element 树打开 Sheet；$anchor=top|bottom|left|right，返回 1 表示已打开。 */
function gpui_open_sheet(mixed $window, array $tree, string $anchor): int {}

/** 关闭 Sheet；原本没有返回 false。 */
function gpui_close_sheet(mixed $window): bool {}

/** 当前是否有打开的 Sheet。 */
function gpui_has_sheet(mixed $window): bool {}

/** 内嵌浏览器历史导航：$dir<0 后退、$dir>0 前进。该 id 没有 webview 时返回 false。 */
function gpui_webview_nav(mixed $window, string $id, int $dir): bool {}

/** 内嵌浏览器控件自己报的状态：['ok'=>bool,'url'=>string,'w'=>float,'h'=>float]。 */
function gpui_webview_probe(mixed $window, string $id): array {}

/* ─────────────────────── 缺口服务：HTTP / 目录监听 / 解压 ─────────────────────── */

/** 异步 HTTP GET。应答经 ['type'=>'http','id'=>$id] 事件到达；url 非 http(s) 返回 false。 */
function gpui_http_get(mixed $window, string $id, string $url): bool {}

/** 取回并丢掉一条已落地的应答：['ok','status','body','contentType']；没有这条返回空数组。 */
function gpui_http_response(mixed $window, string $id): array {}

/** 监听目录（非递归），成功返回 watch id>0、失败 0；变更收到 ['type'=>'dirChange','id']。 */
function gpui_watch_dir(mixed $window, string $id, string $dir, bool $create): int {}

/** 停止监听。未知/0 号忽略。 */
function gpui_unwatch_dir(mixed $window, int $watchId): void {}

/** 测试接缝：不碰文件系统走一遍"信号→主线程→入队"（gpui 的 DirWatchAdd）。 */
function gpui_watch_fake(mixed $window, string $id): int {}

/** 解 zlib/gzip：['ok'=>bool,'data'=>string,'error'=>string]。nano 里没有 gz* 函数。 */
function gpui_inflate(string $data, bool $gzip): array {}

/** 系统状态快照：['cpu','memPct','memTotal','memUsed','diskPct','diskTotal',
 *  'diskUsed','ncpu','batteryPresent','batteryCharging','batteryPct','procs']。
 *  cpu 是两次采样之间的差值，第一次报 0。$topProcs>0 才带进程列表。 */
function gpui_sys_info(int $topProcs): array {}

/* ─────────────────────── 应用内通知中心 ─────────────────────── */

/** 推一条通知，返回它的 id（>0）；失败 0。$phpId 非空时点击/消失各回一条事件。
 *  $timeoutMs<=0 = 不自动消失，要自己 dismiss。 */
function gpui_notify(mixed $window, string $phpId, string $title, string $message,
                     string $type, string $placement, int $timeoutMs): int {}

/** 关掉一条通知（开始退场）。命中返回 true。 */
function gpui_dismiss_notification(mixed $window, int $notifyId): bool {}

/** 全部开始退场。 */
function gpui_clear_notifications(mixed $window): void {}

/** 当前在列表里的通知条数（含正在退场的）。 */
function gpui_notification_count(mixed $window): int {}

/* ── macOS 系统集成（托盘 / 全局快捷键 / Dock 角标 / OS 通知）──────────────────
 * 自动化只到"注册成功 + 合成事件打得回来"；菜单栏图标、别的 app 有焦点时的快捷键、
 * 横幅是否真的出现，机器看不见，只能人看。 */

/** 挂一个托盘项，返回 id>0（失败 0）。$labels 是菜单项文字，空数组则点击本身作为事件回来。 */
function gpui_tray_add(mixed $window, string $id, string $tooltip, array $labels): int {}

/** 撤掉一个托盘项。未知 id 忽略。 */
function gpui_tray_remove(mixed $window, int $trayId): void {}

/** 当前托盘项数量（app 级，与窗口无关）。 */
function gpui_tray_count(): int {}

/** 注册全局快捷键：$keycode 是 kVK_*（F13=105），$mods 是 Carbon 位或运算。返回 id>0。 */
function gpui_hotkey_register(mixed $window, string $id, int $keycode, int $mods): int {}

function gpui_hotkey_unregister(int $id): void {}


/** 组件 materialize 失败读数：['count'=>累计次数, 'last'=>最后一次原因]。 */
function gpui_component_failure(): array {}

/** 已注册的全局快捷键条数（只读计数）。 */
function gpui_hotkey_count(): int {}

/** 测试接缝：合成第 $index 个快捷键的事件，走完整条派发链。 */
function gpui_hotkey_fire(int $index): bool {}

/** Dock 角标文字；空串清除。 */
function gpui_dock_badge(string $text): void {}

/** OS 通知。true = 已交给系统（不代表屏幕上出现了横幅）。 */
function gpui_os_notify(string $title, string $body): bool {}

/* ── 授权文件系统 / 进程 / 存储（Capabilities）────────────────────────────────
 * 全部是进程级静态：gpui 的 FsRun 要 (root, relative) 两半，root 必须是事先授予过的
 * 目录，所以授权天然属于 app 而不是窗口。未授予的路径一律失败，原因看 gpui_fs_error()。 */

/** 授予一个根目录。$writable=true 是读写根。返回该类根的数量（0 = 失败）。 */
function gpui_grant_root(string $root, bool $writable): int {}

/** 命令白名单；$unrestricted=true 时全部放行。 */
function gpui_grant_execute(array $commands, bool $unrestricted): int {}

function gpui_grant_storage(bool $allowed): void {}

function gpui_storage_granted(): bool {}

/** 存储落盘位置。['ok','error'] */
function gpui_storage_path(string $path): array {}

/** ['ok','data','error'] —— ok=false 时 data 不存在。 */
function gpui_fs_read(string $path): array {}

/** ['ok','error'] */
function gpui_fs_write(string $path, string $data): array {}

/** ['ok','entries'=>[['name'=>..,'dir'=>bool]],'truncated'=>bool,'error'] */
function gpui_fs_list(string $path): array {}

function gpui_fs_exists(string $path): bool {}

/** ['ok','error'] */
function gpui_fs_remove(string $path, bool $dir, bool $recursive): array {}

/** ['ok','error'] */
function gpui_fs_mkdir(string $path, bool $dir): array {}

/** 阻塞跑一个被授权的可执行文件。['ok','code','out','err','error'] */
function gpui_process_run(string $command, array $args): array {}

/** ['ok','error'] */
function gpui_storage_set(string $key, string $value): array {}

/** ['ok','value'] */
function gpui_storage_get(string $key): array {}

function gpui_storage_remove(string $key): bool {}

function gpui_storage_len(): int {}
