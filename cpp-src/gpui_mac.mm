// gpui_mac.mm — macOS 系统集成：托盘 / 全局快捷键 / Dock 角标 / OS 通知。
//
// 这个 TU 刻意不 include gpui.h：它只需要 Cocoa + Carbon，事件靠一个 C 函数指针交回
// shim。于是它既不受 amalgam 的 C++20 约束，也不会把 AppKit 类型带过防火墙。
//
// 线程：全部只在主线程用。我们的泵（gpui_shim_pump）就跑在主线程，Carbon 事件由
// gpui_mac_pump 在同一个调用栈里派发，所以 sink → 入队不需要锁。

#ifdef __APPLE__

#import <Cocoa/Cocoa.h>
#import <Carbon/Carbon.h>

#include "gpui_mac.h"

#include <string>
#include <vector>

// 托盘点击的 target。@interface/@implementation 只能待在全局作用域，所以这里只声明，
// 实现写在下面那个匿名命名空间之后（它要回调命名空间里的处理函数）。
@interface GpuiTrayTarget : NSObject
- (void)gpuiActivated:(id)sender;
@end

namespace {

GpuiMacSink g_sink = nullptr;

void Emit(void* owner, const char* type, const char* elemId, const char* value) {
    if (g_sink) g_sink(owner, type, elemId, value);
}

// ── 托盘（NSStatusItem）───────────────────────────────────────────────────────
//
// statusItemWithLength: 给的是自动释放池里的对象，必须自己留着，所以存进 g_trays。
struct Tray {
    int id = 0;
    void* owner = nullptr;
    NSStatusItem* item = nil;
    std::string elemId;
};

std::vector<Tray> g_trays;
int g_nextTray = 1;
GpuiTrayTarget* g_trayTarget = nil;

// 两类来源共用一个 action，都按 **trayId** 反查注册项拿 owner 与 elemId：
//   菜单项：tag = trayId，representedObject = 被点的 label 文本
//   按钮（没挂菜单时）：tag = trayId，label 为空
// （NSStatusBarButton 没有 representedObject 这个属性，所以两条路统一走 tag。）
void HandleTraySender(id sender) {
    if (![sender respondsToSelector:@selector(tag)]) return;
    const int trayId = static_cast<int>([sender tag]);
    const Tray* found = nullptr;
    for (const Tray& t : g_trays) {
        if (t.id == trayId) {
            found = &t;
            break;
        }
    }
    if (!found) return;  // 托盘项已撤：事件不该再打回一个已经不存在的窗口
    const char* label = nullptr;
    if ([sender isKindOfClass:[NSMenuItem class]]) {
        id key = static_cast<NSMenuItem*>(sender).representedObject;
        if ([key isKindOfClass:[NSString class]])
            label = static_cast<NSString*>(key).UTF8String;
    }
    Emit(found->owner, "tray", found->elemId.c_str(), label);
}

// ── 全局快捷键（Carbon）───────────────────────────────────────────────────────
struct HotKey {
    int id = 0;
    void* owner = nullptr;
    UInt32 keyCode = 0;
    UInt32 mods = 0;
    EventHotKeyRef ref = nullptr;
    std::string elemId;
};

std::vector<HotKey> g_hotkeys;
int g_nextHotKey = 1;
EventHandlerRef g_hotKeyHandler = nullptr;

// EventHotKeyID.id 存注册 id，signature 存一个常量做归属检查：别的注册者的事件也可能
// 落到同一个 target 上，认 signature 才不会被别人的事件冒领。
constexpr UInt32 kHotKeySignature = 'gpxi';

OSStatus OnHotKeyPressed(EventHandlerCallRef, EventRef event, void*) {
    EventHotKeyID hkId;
    OSStatus rc = GetEventParameter(event, kEventParamDirectObject,
                                    typeEventHotKeyID, NULL,
                                    sizeof(EventHotKeyID), NULL, &hkId);
    if (rc != noErr || hkId.signature != kHotKeySignature) return rc;
    for (HotKey& h : g_hotkeys) {
        if (h.id == static_cast<int>(hkId.id)) {
            Emit(h.owner, "hotkey", h.elemId.c_str(), nullptr);
            return noErr;
        }
    }
    return eventNotHandledErr;
}

void EnsureHotKeyHandlerInstalled(void) {
    if (g_hotKeyHandler) return;
    EventTypeSpec spec;
    spec.eventClass = kEventClassKeyboard;
    spec.eventKind = kEventHotKeyPressed;
    InstallApplicationEventHandler(NewEventHandlerUPP(&OnHotKeyPressed), 1, &spec,
                                   NULL, &g_hotKeyHandler);
}

}  // namespace

@implementation GpuiTrayTarget
- (void)gpuiActivated:(id)sender {
    HandleTraySender(sender);
}
@end

extern "C" {

void gpui_mac_set_sink(GpuiMacSink sink) { g_sink = sink; }

int gpui_mac_tray_add(void* owner, const char* tooltip, const char* const* labels,
                      int label_count, const char* elem_id) {
    if (!g_trayTarget) g_trayTarget = [[GpuiTrayTarget alloc] init];

    NSStatusItem* item = [[NSStatusBar systemStatusBar]
        statusItemWithLength:NSVariableStatusItemLength];
    if (!item) return 0;
    // id 先分配：没挂菜单时按钮的 tag 就是它，HandleTraySender 拿它反查。
    const int id = g_nextTray++;
    if (item.button) {
        item.button.title = (tooltip && *tooltip) ? @(tooltip) : @"●";
        if (tooltip && *tooltip) item.button.toolTip = @(tooltip);
    }
    if (label_count > 0 && labels) {
        NSMenu* menu = [[NSMenu alloc] initWithTitle:@""];
        for (int i = 0; i < label_count; i++) {
            NSString* label = @(labels[i] ? labels[i] : "");
            NSMenuItem* mi = [[NSMenuItem alloc] initWithTitle:label
                                                        action:@selector(gpuiActivated:)
                                                 keyEquivalent:@""];
            mi.target = g_trayTarget;
            mi.tag = id;
            mi.representedObject = label;
            [menu addItem:mi];
        }
        item.menu = menu;
    } else if (item.button) {
        // 挂了菜单时点击被 NSMenu 吃掉，只有没挂菜单才把点击报回来。
        item.button.target = g_trayTarget;
        item.button.action = @selector(gpuiActivated:);
        item.button.tag = id;
    }

    Tray t;
    t.id = id;
    t.owner = owner;
    t.item = item;
    t.elemId = elem_id ? elem_id : "";
    g_trays.push_back(t);
    return id;
}

void gpui_mac_tray_remove(int tray_id) {
    for (size_t i = 0; i < g_trays.size(); i++) {
        if (g_trays[i].id != tray_id) continue;
        if (g_trays[i].item.button) {
            g_trays[i].item.button.target = nil;
            g_trays[i].item.button.action = NULL;
        }
        g_trays[i].item.menu = nil;
        [[NSStatusBar systemStatusBar] removeStatusItem:g_trays[i].item];
        g_trays.erase(g_trays.begin() + static_cast<long>(i));
        return;
    }
}

int gpui_mac_tray_count(void) { return static_cast<int>(g_trays.size()); }

int gpui_mac_hotkey_register(void* owner, uint32_t keycode, uint32_t mods,
                             const char* elem_id) {
    EnsureHotKeyHandlerInstalled();
    HotKey h;
    h.id = g_nextHotKey++;
    h.owner = owner;
    h.keyCode = keycode;
    h.mods = mods;
    h.elemId = elem_id ? elem_id : "";
    EventHotKeyID hkId;
    hkId.signature = kHotKeySignature;
    hkId.id = static_cast<UInt32>(h.id);
    if (RegisterEventHotKey(keycode, mods, hkId, GetApplicationEventTarget(), 0,
                            &h.ref) != noErr) {
        return 0;
    }
    g_hotkeys.push_back(h);
    return h.id;
}

void gpui_mac_hotkey_unregister(int hotkey_id) {
    for (size_t i = 0; i < g_hotkeys.size(); i++) {
        if (g_hotkeys[i].id != hotkey_id) continue;
        if (g_hotkeys[i].ref) UnregisterEventHotKey(g_hotkeys[i].ref);
        g_hotkeys.erase(g_hotkeys.begin() + static_cast<long>(i));
        return;
    }
}

int gpui_mac_hotkey_count(void) { return static_cast<int>(g_hotkeys.size()); }

int gpui_mac_release_owner(void* owner) {
    int freed = 0;
    for (size_t i = 0; i < g_trays.size();) {
        if (g_trays[i].owner != owner) {
            ++i;
            continue;
        }
        gpui_mac_tray_remove(g_trays[i].id);
        ++freed;
    }
    for (size_t i = 0; i < g_hotkeys.size();) {
        if (g_hotkeys[i].owner != owner) {
            ++i;
            continue;
        }
        gpui_mac_hotkey_unregister(g_hotkeys[i].id);
        ++freed;
    }
    return freed;
}

void gpui_mac_pump(void) {
    // 我们的泵只有 [NSApp nextEventMatchingMask:]，Carbon 队列不会自己排空，所以每帧
    // 在这里零等待地取一次。SendEventToEventTarget(dispatcher) 会走到 OnHotKeyPressed。
    for (;;) {
        EventRef event = nullptr;
        if (ReceiveNextEvent(0, NULL, kEventDurationNoWait, true, &event) != noErr ||
            !event) {
            return;
        }
        SendEventToEventTarget(event, GetEventDispatcherTarget());
        ReleaseEvent(event);
    }
}

int gpui_mac_hotkey_fire(int index) {
    if (index < 0 || index >= static_cast<int>(g_hotkeys.size())) return 0;
    const HotKey& h = g_hotkeys[static_cast<size_t>(index)];
    EventRef event = nullptr;
    if (CreateEvent(kCFAllocatorDefault, kEventClassKeyboard, kEventHotKeyPressed, 0,
                    kEventAttributeUserEvent, &event) != noErr) {
        return 0;
    }
    EventHotKeyID hkId;
    hkId.signature = kHotKeySignature;
    hkId.id = static_cast<UInt32>(h.id);
    // 签名是 (event, name, type, ByteCount, ptr) 五参——Carbon 的 setter 没有 ItemCount，
    // 记成"第 4 参是 ItemCount"会编不过（CarbonEventsCore.h:833）。
    OSStatus prc = SetEventParameter(event, kEventParamDirectObject, typeEventHotKeyID,
                                     sizeof(EventHotKeyID), &hkId);
    const OSStatus src = prc == noErr
                             ? SendEventToEventTarget(event, GetApplicationEventTarget())
                             : paramErr;
    ReleaseEvent(event);
    return src == noErr ? 1 : 0;
}

void gpui_mac_dock_badge(const char* text) {
    NSDockTile* tile = [NSApp dockTile];
    if (!tile) return;
    tile.badgeLabel = (text && *text) ? @(text) : nil;
    [tile display];
}

int gpui_mac_os_notify(const char* title, const char* body) {
    // NSUserNotification 在 11+ 被弃用，但对裸可执行文件它仍是唯一免打包的路
    // （UNUserNotificationCenter 要 .app bundle + Info.plist + 签名）。
    // 正文属性叫 informativeText；没有 initWithTitle:subtitle:body: 这个初始化器。
    NSUserNotificationCenter* center =
        [NSUserNotificationCenter defaultUserNotificationCenter];
    if (!center) return 0;
    NSUserNotification* n = [[NSUserNotification alloc] init];
    if (!n) return 0;
    n.title = @(title ? title : "");
    n.informativeText = @(body ? body : "");
    [center deliverNotification:n];
    return 1;
}

}  // extern "C"

#endif  // __APPLE__
