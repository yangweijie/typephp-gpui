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

#ifdef __cplusplus
}
#endif
#endif  // GPUI_MAC_H_
