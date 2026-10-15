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


namespace {

/* 我们的 app 只有一扇 gpui 窗口（dialog/sheet 是同一扇窗口里的层），所以"第一个可见且
 * 有 contentView 的窗口"就是它。 */
NSWindow* GpuiWindow(void) {
    for (NSWindow* w in [NSApp windows]) {
        if (w.isVisible && w.contentView) return w;
    }
    return nil;
}

/* 真实点击会让 WindowServer 把窗口升成 key，键盘事件才有 firstResponder 可去；
 * 我们绕过了 WindowServer，所以这一步要自己补上——否则 keyDown: 落到 nil，
 * 输入框永远打不进字（鼠标不受影响，所以点击一直是通的）。 */
void MakeKey(NSWindow* w) {
    if (!w) return;
    if (![NSApp isActive]) [NSApp activateIgnoringOtherApps:YES];
    if (w != [NSApp keyWindow]) [w makeKeyWindow];
}

/* 1 = 走 NSApp 队列（真实路径），0 = 直接 sendEvent: 到窗口。 */
int g_postMode = 1;

/* 走过真路由（NSApp 队列 → nextEventMatchingMask → -[NSApp sendEvent:]）的事件条数。
 * 后台进程激活不了（macOS 只让前台应用抢焦点），这时 postEvent: 会被 NSApplication
 * 当成"这次点击只用来激活"而吃掉——缝里就得到假阴性。所以只有"应用确实 active 且
 * 窗口确实是 key"时才走真实路由，否则退回直投。
 *
 * 计数单调累加、读后不清零：若只记"最后一次走了哪条路"，读一次就被抹掉，于是
 * "selftest 全绿"没法说明绿来自真路由还是直投兜底——直投同样能让整条链变绿。 */
int g_nsappDeliveries = 0;

/* 事件类型 → nextEventMatchingMask 的掩码。取事件要按掩码取，不然会把队列里别的
 * 类型（比如 1s 计时的 periodic）一起捞出来派发。 */
NSEventMask MaskFor(NSEventType t) {
    switch (t) {
        case NSEventTypeLeftMouseDown:  return NSEventMaskLeftMouseDown;
        case NSEventTypeLeftMouseUp:    return NSEventMaskLeftMouseUp;
        case NSEventTypeRightMouseDown: return NSEventMaskRightMouseDown;
        case NSEventTypeRightMouseUp:   return NSEventMaskRightMouseUp;
        case NSEventTypeMouseMoved:     return NSEventMaskMouseMoved;
        case NSEventTypeKeyDown:        return NSEventMaskKeyDown;
        case NSEventTypeKeyUp:          return NSEventMaskKeyUp;
        case NSEventTypeFlagsChanged:   return NSEventMaskFlagsChanged;
        case NSEventTypeScrollWheel:    return NSEventMaskScrollWheel;
        default:                        return NSEventMaskAny;
    }
}

void Deliver(NSWindow* w, NSEvent* ev) {
    if (g_postMode && [NSApp isActive] && w == [NSApp keyWindow]
        && ev.windowNumber == w.windowNumber) {
        /* 真路由：入队 → 自己 nextEventMatchingMask 取出 → 交 -[NSApplication sendEvent:]。
         * 这三步就是手点事件在 NSApplication 里的完整走法（WindowServer 只负责入队），
         * 所以激活判定、keyWindow/firstResponder 归属、hitTest 全都按真实规则走一遍。
         *
         * 必须同步取完：shim 侧靠"调用返回时 gpui 队列是否增长"判定事件到达，
         * 只 post 不等取出会让真绿的链路读数成假阴性。 */
        [NSApp postEvent:ev atStart:NO];
        const NSEventMask mask = MaskFor(ev.type);
        for (int i = 0; i < 64; i++) {
            NSEvent* got = [NSApp nextEventMatchingMask:mask untilDate:[NSDate distantPast]
                                               inMode:NSDefaultRunLoopMode dequeue:YES];
            if (!got) break;
            [NSApp sendEvent:got];
            if (got == ev) break;
        }
        g_nsappDeliveries++;
        return;
    }
    [w sendEvent:ev];
}

}  // namespace

int gpui_mac_send_mouse(double x, double y, int type, int click_count) {
    NSWindow* w = GpuiWindow();
    if (!w) return 0;
    MakeKey(w);
    // PHP 传的是视图坐标（左上原点）；NSEvent 要窗口坐标，交给 convertPoint:toView:nil
    // 按视图是否 flipped 换算，别自己减高度。
    NSPoint p = [w.contentView convertPoint:NSMakePoint(x, y) toView:nil];
    NSEventType t = type == 0 ? NSEventTypeMouseMoved
                : type == 1 ? NSEventTypeLeftMouseDown
                : type == 2 ? NSEventTypeLeftMouseUp
                : type == 3 ? NSEventTypeRightMouseDown : NSEventTypeRightMouseUp;
    int clicks = t == NSEventTypeMouseMoved ? 0 : (click_count > 0 ? click_count : 1);
    NSEvent* e = [NSEvent mouseEventWithType:t location:p modifierFlags:0
                                   timestamp:[NSProcessInfo processInfo].systemUptime
                                windowNumber:w.windowNumber context:nil
                                 eventNumber:0 clickCount:clicks
                                    pressure:t == NSEventTypeMouseMoved ? 0.0 : 1.0];
    if (!e) return 0;
    Deliver(w, e);
    return 1;
}

int gpui_mac_send_scroll(double x, double y, int delta_y, int delta_x) {
    NSWindow* w = GpuiWindow();
    if (!w) return 0;
    MakeKey(w);
    NSPoint local = [w.contentView convertPoint:NSMakePoint(x, y) toView:nil];
    CGEventRef cg = CGEventCreateScrollWheelEvent(nullptr, kCGScrollEventUnitPixel,
                                                 2, delta_y, delta_x);
    if (!cg) return 0;
    // eventWithCGEvent 没有窗口身份，交给目标 NSWindow 时 locationInWindow 必须是本地坐标。
    CGEventSetLocation(cg, CGPointMake(local.x,
        NSMaxY([NSScreen screens][0].frame) - local.y));
    NSEvent* event = [NSEvent eventWithCGEvent:cg];
    CFRelease(cg);
    if (!event) return 0;
    Deliver(w, event);
    return 1;
}

void gpui_mac_set_post_mode(int post) { g_postMode = post ? 1 : 0; }

int gpui_mac_become_foreground(void) {
    ProcessSerialNumber psn = {0, kCurrentProcess};
    OSStatus rc = TransformProcessType(&psn, kProcessTransformToForegroundApplication);
    [NSApp setActivationPolicy:NSApplicationActivationPolicyRegular];
    /* `activateIgnoringOtherApps:` 自 macOS 14 起废弃，实测在 26 上不再把应用带到前台
     * （bundle 起、`open` 起都停在 active=false key=false，真点击于是全部不落——而非前台
     * 窗口的鼠标键盘都不派发）。14+ 的正解是无参 `activate`；保留旧调用只为了给老系统
     * 一条路，用 respondsToSelector 探，不当它存在就一定可用。 */
    if ([NSApp respondsToSelector:@selector(activate)]) {
        [NSApp activate];
    } else {
        [NSApp activateIgnoringOtherApps:YES];
    }
    /* 实测（本机，跨帧读 active/key）：这两步在**没有 bundle 身份**的进程上都不生效——
     * 补上 SetFrontProcessWithOptions(&psn, 0) 同样 active=false key=false。所以真前台
     * 只能靠 .app bundle 起（见 bin/gpui 的 appBundle），这里保留调用是为了让 bundle
     * 里已经前台的应用顺带把窗口提到最前，不是"裸二进制的救药"。 */
    return rc == noErr ? 1 : 0;
}

int gpui_mac_activate(void) {
    /* 前台不是"申请一次就有"的状态，而是和当时谁握着焦点在竞争：实测同一个 bundle
     * 二进制，一次 startup 量到 active=true key=true，另一次量到 false/false。
     * 所以这里做的是"再申请一次 + 让 runloop 走一拍"：
     *   - TransformProcessType/activateIgnoringOtherApps 的申请回执要靠 runloop source
     *     处理才落地，光调不调度就等于没调；
     *   - 只跑 `runMode:beforeDate:`（不自己 nextEventMatchingMask 取事件），是为了不把
     *     用户正在排队的鼠标键盘事件从队列里抢走——那会打乱 gpui 自己的派发顺序。
     * 返回 1 = 申请完且此刻窗口是 key。调用方应当有界重试，不要死等。 */
    gpui_mac_become_foreground();
    NSWindow* w = GpuiWindow();
    if (w) {
        /* 只 makeKeyWindow 不够：应用刚被带到前台时窗口可能还没 orderFront，
         * key 状态也就落不下来——实测正是这一步缺，activate 之后仍 key=false。 */
        [w makeKeyAndOrderFront:nil];
        MakeKey(w);
    }
    for (int i = 0; i < 3; i++) {
        [[NSRunLoop currentRunLoop] runMode:NSDefaultRunLoopMode
                                 beforeDate:[NSDate dateWithTimeIntervalSinceNow:0.02]];
    }
    return (w && [NSApp isActive] && w == [NSApp keyWindow]) ? 1 : 0;
}

int gpui_mac_window_state(double* out) {
    NSWindow* w = GpuiWindow();
    if (!w || !out) return 0;
    out[0] = [NSApp isActive] ? 1 : 0;
    out[1] = (w == [NSApp keyWindow]) ? 1 : 0;
    out[2] = w.isVisible ? 1 : 0;
    out[3] = [NSApp mainWindow] ? 1 : 0;
    out[4] = g_nsappDeliveries;
    /* bundle 身份是"系统会不会把你当正规前台应用"的判据，且与 runloop 有没有被 pump 无关
     * ——用它当门，才不会像 [NSApp isActive] 那样被自己那条缝的副作用牵着走。 */
    out[5] = ([[NSBundle mainBundle] bundleIdentifier] != nil) ? 1 : 0;
    return 1;
}

int gpui_mac_send_key(unsigned short keycode, const char* chars) {
    NSWindow* w = GpuiWindow();
    if (!w) return 0;
    MakeKey(w);
    NSString* s = @(chars ? chars : "");
    for (int i = 0; i < 2; i++) {
        NSEventType t = i == 0 ? NSEventTypeKeyDown : NSEventTypeKeyUp;
        NSEvent* e = [NSEvent keyEventWithType:t location:NSMakePoint(0, 0)
                                 modifierFlags:0
                                     timestamp:[NSProcessInfo processInfo].systemUptime
                                  windowNumber:w.windowNumber
                                       context:nil
                                    characters:s
                  charactersIgnoringModifiers:s
                                     isARepeat:NO
                                       keyCode:keycode];
        if (!e) return 0;
        Deliver(w, e);
    }
    return 1;
}

}  // extern "C"

#endif  // __APPLE__
