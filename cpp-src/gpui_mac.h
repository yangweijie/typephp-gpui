#ifndef GPUI_MAC_H_
#define GPUI_MAC_H_
/* macOS 系统集成（托盘 / 全局快捷键 / Dock 角标 / OS 通知）的独立 TU。
 *
 * 这道接口存在的理由是**语言边界之外的另一道墙**：gpui_mac.mm 只用 Cocoa 与 Carbon，
 * 不 include gpui.h，因此它不需要 C++20、也不碰 amalgam。它把事件通过一个 C 函数指针
 * 送回 shim（shim 再把 user 转回它的窗口对象并入队），所以两边都不需要知道对方的类型。
 *
 * 全文件在实现侧 #ifdef __APPLE__；非 mac 链接的桩在 gpui_shim.cpp 里，返回"没有"。
 *
 * 验收的诚实边界：这一层能自动验的只有"注册成功 + 事件打得回来"（existence-green 加
 * 一条 route-green 的合成事件）。菜单栏里看不看得见图标、快捷键在别的 app 有焦点时
 * 是否仍然触发，机器看不见，只能人看。 */

#include <stdint.h>

#ifdef __cplusplus
extern "C" {
#endif

/* 事件出口。owner 是**注册时**传进来的宿主指针（shim 用它找到自己的窗口队列），所以每条
 * 注册各带自己的 owner——一个进程多个窗口时才不会串到别人的队列上。
 * type 是事件名（"tray" / "hotkey"），elem_id 是 PHP 侧的路由键，value 可空
 * （托盘菜单项把被点的 label 带回来）。只在主线程被调。 */
typedef void (*GpuiMacSink)(void* owner, const char* type, const char* elem_id,
                            const char* value);

/* 由 shim 在第一次用之前注册一次；重复调用覆盖。 */
void gpui_mac_set_sink(GpuiMacSink sink);

/* ── 托盘（NSStatusItem）────────────────────────────────────────────────────── */
/* labels 是菜单项文字，可为空（空则点击不弹菜单）。返回 >0 的 id，失败 0。 */
int gpui_mac_tray_add(void* owner, const char* tooltip,
                      const char* const* labels, int label_count,
                      const char* elem_id);
/* 撤掉一个托盘项。未知 id 忽略。 */
void gpui_mac_tray_remove(int tray_id);
int gpui_mac_tray_count(void);

/* ── 全局快捷键（Carbon RegisterEventHotKey）────────────────────────────────── */
/* mods 用 Carbon 的 modifierKey 位（cmdKey=256 shiftKey=512 optionKey=2048
 * controlKey=4096）；keycode 是 kVK_*。返回 >0 的 id，失败 0。 */
int gpui_mac_hotkey_register(void* owner, uint32_t keycode, uint32_t mods,
                             const char* elem_id);
void gpui_mac_hotkey_unregister(int hotkey_id);
int gpui_mac_hotkey_count(void);
/* 窗口析构时把它名下的托盘项与快捷键全部撤掉：owner 是宿主的窗口对象，留着就是野指针。
 * 返回撤掉的数量。 */
int gpui_mac_release_owner(void* owner);
/* 我们的泵是 [NSApp nextEventMatchingMask:]，它不保证替 Carbon Event Manager 派发，
 * 所以每帧自己排空一次 Carbon 队列。没有事件时是零等待。 */
void gpui_mac_pump(void);
/* 测试接缝：合成一条 kEventHotKeyPressed 发给 application target，走完整条
 * "Carbon 队列 → 派发 → handler → sink → 入队 → poll"。它验的是派发链，
 * 不是"系统真的全局捕获了这组键"——后者只能人按。 */
int gpui_mac_hotkey_fire(int index);

/* ── Dock 角标（NSDockTile）────────────────────────────────────────────────── */
/* 空串清除。 */
void gpui_mac_dock_badge(const char* text);

/* ── OS 通知（NSUserNotification）──────────────────────────────────────────── */
/* 返回 1 = 已交给 NSUserNotificationCenter；0 = 这条路在本机不可用。
 * 裸可执行文件（无 .app bundle / 无 bundle id）时系统可能静默丢弃，返回值不代表
 * 屏幕上出现了横幅。 */
int gpui_mac_os_notify(const char* title, const char* body);

/* ── 真输入验收缝（AppKit 正常派发路径）─────────────────────────────────────── */
/* 把一条鼠标/键盘事件交给 -[NSWindow sendEvent:]，于是 AppKit 的 hit-test、gpui 的
 * behavior 解析、focus 迁移全都真的走一遍。这与 gpui_shim_test_event 那条合成缝不同：
 * 后者按 callbackId 直接入队，**绕过"监听器到底挂没挂"**——on_ 前缀那次失配（真实点击
 * 一个都到不了 PHP）就是被它掩盖的。
 * x/y 是 gpui 视图坐标（左上原点、逻辑点），与 snapshot 同一套。
 * type: 0=move 1=left-down 2=left-up 3=right-down 4=right-up。返回 1=已投递。 */
int gpui_mac_send_mouse(double x, double y, int type, int click_count);
int gpui_mac_send_scroll(double x, double y, int delta_y, int delta_x);
/* keycode 是 kVK_*；chars 是 UTF-8 字符（可空串）。 */
int gpui_mac_send_key(unsigned short keycode, const char* chars);

/* 批5：带修饰键的合成按键（flags = NSEventModifierFlag 位或）。 */
int gpui_mac_send_key_flags(unsigned short keycode, const char* chars,
                            unsigned long flags);

/* 投递模式：1 = 交给 -[NSApp postEvent:atStart:]，于是事件要经过程序自己的
 * nextEventMatchingMask → -[NSApp sendEvent:] 才被处理，与真实鼠标/键盘同一条路；
 * 0 = 直接 -[NSWindow sendEvent:]（快，但跳过 NSApplication 的路由与钥匙窗口判定）。
 * 默认 1。真实输入"点了没反应"而缝里是绿的，差别通常就在这两条路之间。 */
void gpui_mac_set_post_mode(int post);

/* 量窗口状态：out[0]=NSApp.isActive out[1]=window.isKeyWindow
 * out[2]=window.isVisible out[3]=mainWindow 是否存在
 * out[4]=真路由（NSApp 队列）已投递的事件数（单调累加，读不清零）
 * out[5]=进程有没有 bundle 身份（[NSBundle mainBundle].bundleIdentifier 非空）。返回 1 = 找到窗口。 */
int gpui_mac_window_state(double* out);

/* 把"裸可执行文件"转成 LaunchServices 眼里的前台应用。
 * 没这一步：非 bundle 的 CLI 二进制调 -[NSApp activateIgnoringOtherApps:] 会被拒，
 * 于是 NSApp.isActive=NO、窗口不是 keyWindow —— NSApplication 的 sendEvent: 把鼠标按下
 * 当成"这次点击只用来激活"而吃掉，键盘更没有钥匙窗口可去。表现就是"代码里全绿、
 * 手点没反应、输入框打不进字"。返回 1 = TransformProcessType 成功。 */
int gpui_mac_become_foreground(void);

/* 再申请一次前台并把窗口升成 key，然后让 runloop 走几拍（只跑 runMode:beforeDate:，
 * 不自己取事件，免得把用户排队的鼠标键盘事件从 gpui 的派发顺序里抢走）。
 * 前台是和"当时谁握着焦点"竞争出来的状态，申请一次未必落地，所以这个函数是给
 * 有界重试用的。返回 1 = 此刻应用 active 且窗口 key。 */
int gpui_mac_activate(void);

#ifdef __cplusplus
}
#endif
#endif  // GPUI_MAC_H_
