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

static void EnsureAppkitDeliveryMonitor(void);   // 定义在下面（计数与路由探针共用）
namespace {
NSWindow* GpuiWindow(void);                      // 同文件后面的匿名 namespace 里定义
}

/* 2026-10-23：物理点击"只能拖不能点"追到这里——按下那一下从没进我们的事件队列
 * （本地监视器 249 条里 type=1 为 0），而空闲时窗口 isKeyWindow=0。也就是说每一按都被
 * AppKit 当成"换 key 的激活点击"吃掉了。谁在摘 key？这组通知把每一次 key/main/active
 * 变更连时间戳与当时前台应用一起打出来，抓现行用。GPUI_KEY_DEBUG=1 才装。 */
static void EnsureKeyTrace(void) {
    static bool installed = false;
    if (installed) {
        return;
    }
    installed = true;
    NSWorkspace* ws = [NSWorkspace sharedWorkspace];
    for (NSString* name in @[ NSWindowDidBecomeKeyNotification, NSWindowDidResignKeyNotification,
                              NSWindowDidBecomeMainNotification, NSWindowDidResignMainNotification,
                              NSApplicationDidBecomeActiveNotification,
                              NSApplicationDidResignActiveNotification ]) {
        [[NSNotificationCenter defaultCenter] addObserverForName:name object:nil queue:nil
            usingBlock:^(NSNotification* note) {
                NSWindow* w = [note.object isKindOfClass:[NSWindow class]]
                    ? (NSWindow*)note.object : GpuiWindow();
                NSRunningApplication* fa = [ws frontmostApplication];
                NSString* front = fa.localizedName ?: @"?";
                fprintf(stderr,
                    "GPUI-KEY %s win=%ld key=%ld main=%ld appActive=%ld front=%s t=%.3f\n",
                    name.UTF8String, w ? (long)w.windowNumber : -1L,
                    (long)(w ? w.isKeyWindow : NO), (long)(w ? w.isMainWindow : NO),
                    (long)[NSApp isActive], front.UTF8String, CFAbsoluteTimeGetCurrent());
            }];
    }
}

void gpui_mac_pump(void) {
    // 监视器原来只在 windowState 被读时才装；物理点击这一跳要的是"每一帧都在看"，
    // 所以挪到泵里装（幂等，且 block 原样放行事件，不拦）。
    EnsureAppkitDeliveryMonitor();
    if (getenv("GPUI_KEY_DEBUG")) {
        EnsureKeyTrace();
    }
    /* 历史（#98/#99）：这里原来每帧 `ReceiveNextEvent(0, NULL, …, true, …)` 排空 Carbon
     * 队列——mask=0 且 types=NULL 在 Carbon 里是"任意事件"，于是把 AppKit 队列里的
     * leftMouseDown 一起摘走丢给没人接的派发目标 ⇒ "能拖窗口、什么都点不动"。
     * A/B 实测（build/cpp-input-demo/pump_ab，同一份 gpui.o 只差这一句）：
     * manual 臂 3/3 收到 down、manual-carbon 臂 0/3，会话层 tap 证明系统确实判给了该进程。
     * 带 inTypes 的过滤版会在 ReceiveNextEventCommon 里 SIGBUS
     * （input-2026-10-23-074136.ips），SDK 也没有 GetEventMaskForClass/PeekEvent。
     *
     * 现行做法：**peek 优先**。`inRemoveFromQueue=false` 只看不取；队头是热键事件才
     * 再取一次派发，否则立刻停手、把事件留给 AppKit。这样热键照旧能触发，而鼠标/普通
     * 键盘事件一个都不会被我们摘走。下面那句 empty() 只是省一次 peek 的优化。 */
    if (g_hotkeys.empty()) {
        return;
    }
    for (;;) {
        EventRef peeked = nullptr;
        if (ReceiveNextEvent(0, NULL, kEventDurationNoWait, false, &peeked) != noErr ||
            !peeked) {
            return;
        }
        // 不取走时事件仍归队列所有，不能 ReleaseEvent(peeked)。
        if (GetEventClass(peeked) != kEventClassKeyboard ||
            GetEventKind(peeked) != kEventHotKeyPressed) {
            return;
        }
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
 * 输入框永远打不进字。
 *
 * 这里原来还写着"鼠标不受影响，所以点击一直是通的"——2026-10-23 的读数把它推翻了：
 * 物理 leftMouseDown 从没进我们的事件队列（监视器 249 条里 type=1 为 0），鼠标同样受影响，
 * 而且比键盘更糟（键盘靠 MakeKey 兜住了）。追查看 task_plan.md Phase 25。 */
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

/* 物理 HID 的到达计数。local monitor 只对「AppKit 已经派发到本进程」的事件触发，
 * 所以它能把两件以前分不开的事分开：OS 根本没送到本 app（激活/输入法管线），
 * 与 送到了但我们的 view/派发丢了。
 * 2026-10-17 把 `leftMouseDragged` 也算进来（#94）：拖分隔条时这个计数涨=事件到了本进程，
 * 而 resize 仍不来 ⇒ 断点在 gpui 的拖拽处理，不是鼠标没到。
 * 它和 g_nsappDeliveries 不是一回事：后者只数我们自己经 Deliver() 注入走真路由的条数，
 * 物理事件走上游的 nextEventMatchingMask 泵，不经过那条缝。 */
static unsigned long long g_appkitDeliveries = 0;
static id g_appkitMonitor = nil;

static void EnsureAppkitDeliveryMonitor(void) {
    if (g_appkitMonitor) {
        return;
    }
    g_appkitMonitor = [NSEvent addLocalMonitorForEventsMatchingMask:
                                    (NSEventMaskKeyDown | NSEventMaskLeftMouseDown
                                     | NSEventMaskLeftMouseDragged)
                                                            handler:^NSEvent* _Nullable(NSEvent* ev) {
        g_appkitDeliveries++;
        // 物理点击重开（2026-10-23）：这条打印的是"进了本进程的事件"，与后面的路由无关。
        // 它要回答的是——物理事件到底带哪扇窗的 windowNumber、类型是什么；合成对照臂
        // （automation 的 click 动词）走的是同一条监视器，所以两边的读数直接可比。
        if (getenv("GPUI_MONITOR_DEBUG")) {
            fprintf(stderr, "GPUI-MON type=%ld win=%ld loc=%.0f,%.0f\n",
                    (long)ev.type, (long)ev.windowNumber, ev.locationInWindow.x,
                    ev.locationInWindow.y);
        }
        return ev;                     // 原样放行：这是计数，不是拦截
    }];
}

int gpui_mac_window_state(double* out) {
    NSWindow* w = GpuiWindow();
    if (!w || !out) return 0;
    EnsureAppkitDeliveryMonitor();
    out[0] = [NSApp isActive] ? 1 : 0;
    out[1] = (w == [NSApp keyWindow]) ? 1 : 0;
    out[2] = w.isVisible ? 1 : 0;
    out[3] = [NSApp mainWindow] ? 1 : 0;
    out[4] = g_nsappDeliveries;
    /* bundle 身份是"系统会不会把你当正规前台应用"的判据，且与 runloop 有没有被 pump 无关
     * ——用它当门，才不会像 [NSApp isActive] 那样被自己那条缝的副作用牵着走。 */
    out[5] = ([[NSBundle mainBundle] bundleIdentifier] != nil) ? 1 : 0;
    out[6] = (double) g_appkitDeliveries;
    /* WindowServer 的"在屏"账本：kCGWindowListOptionIncludingWindow 只列在屏窗口，
     * 不在屏就返回 0 条记录。同一 API、同一会话的实测对照：本 app 的窗口 0 条，
     * 一个 60 行的普通 AppKit 程序（终端直起、同样 makeKeyAndOrderFront、同样先 display
     * 后 order、同样泵 nextEventMatchingMask）1 条且 kCGWindowIsOnscreen=1。
     * 为什么要有这一格：上面 0..3 全是**进程内** AppKit 的自报，实测它们可以在
     * active/key/visible/main 全为 true 的同时这一格为 0——而物理键盘鼠标只按
     * WindowServer 的账本派发。于是"真人按键没反应"从前只能靠人报，现在是一条读数。
     * 顺手量过的其它自报值都不判别：alpha=1、level=0、未最小化、onActiveSpace=true，
     * 连 occlusionState=8192 也与那个能收到按键的对照窗口一致。 */
    out[7] = 0;
    /* 自证臂：同一套账本查询"在屏窗口总数"。它非零而 out[7] 为零，才说明 out[7] 的零
     * 是"你这扇不在屏"这个判决，不是查询本身坏了（空清单/权限失败都会伪装成 0）。
     * 这条只查一次并留到进程生命周期——它要证的是"账本查得动"，不是每帧的窗口状态；
     * 全量清单每帧拷一遍不划算（windowState() 是每帧都读的）。 */
    static double s_onScreenPeers = -1.0;
    CGWindowID wid = (CGWindowID) w.windowNumber;
    if (wid != 0) {
        CFArrayRef onScreen =
            CGWindowListCopyWindowInfo(kCGWindowListOptionIncludingWindow, wid);
        if (onScreen) {
            out[7] = CFArrayGetCount(onScreen) > 0 ? 1 : 0;
            CFRelease(onScreen);
        }
        /* 试过的修法（23:31 量掉，别再来一遍）：不在屏时做有界的 orderOut/orderFront 重新排队，
         * 赌窗服务会重新收它。实测到第 3 次重排队为止，bundle 那一列仍是 0、
         * 收尾自报仍 `onScreen=false` ⇒ "交给窗服务的那一次顺序"不是成因，这段已撤。 */
        if (s_onScreenPeers < 0.0) {
            s_onScreenPeers = 0.0;
            CFArrayRef peers = CGWindowListCopyWindowInfo(
                kCGWindowListOptionOnScreenOnly, kCGNullWindowID);
            if (peers) {
                s_onScreenPeers = (double) CFArrayGetCount(peers);
                CFRelease(peers);
            }
        }
        out[8] = s_onScreenPeers;
    }
    /* 外部协变量：本进程所在的 GUI session 此刻**在不在控制台上**。
     * `onScreen` 实测成段地飘（连续三次 true / 连续五次 false），而进程内所有自报
     * （active/key/visible/main/fgTries/trap/focus/peers）两侧完全一样 ⇒ 切换发生在进程外。
     * 会话离开控制台（锁屏、快速用户切换、远程会话断开）正是这种"成段"的外部状态：
     * 那时窗口仍在窗口存储里、AppKit 仍自报 ordered-in，但不会被合成上屏。
     * 记两列：out[9]=onConsole(1/0)，out[10]=session id（0=查不到字典）。 */
    out[9] = 0;
    out[10] = 0;
    CFDictionaryRef sess = CGSessionCopyCurrentDictionary();
    if (sess) {
        // 键名走字面量：这组 CGSession 常量在本 SDK 的 umbrella 头里已不再导出，
        // 而字典本身的键一直是这两个名字（`screencapture`/`loginwindow` 也按名字取）。
        const void *onConsole = CFDictionaryGetValue(sess, CFSTR("kCGSSessionOnConsoleKey"));
        if (onConsole && CFGetTypeID(onConsole) == CFBooleanGetTypeID()) {
            out[9] = CFBooleanGetValue((CFBooleanRef)onConsole) ? 1 : 0;
        }
        const void *sid = CFDictionaryGetValue(sess, CFSTR("kCGSSessionIDKey"));
        int v = 0;
        if (sid && CFGetTypeID(sid) == CFNumberGetTypeID() &&
            CFNumberGetValue((CFNumberRef)sid, kCFNumberIntType, &v)) {
            out[10] = v;
        }
        CFRelease(sess);
    }
    /* out[11]/out[12]：这一行跑在**进程真正的 main thread** 上吗，与 AppKit 认的
     * "main thread" 是同一条吗。两个分开看才有意义：`[NSThread isMainThread]` 只认
     * AppKit 收养为 main 的那条线程。若宿主（nano 运行时）把 GUI 循环放在另一条线程上，
     * AppKit 自报可以全真，而**真正 main thread 的 run loop 从来不被驱动**——
     * 窗口就有记录、有画面，却永远拿不到在屏资格。这是当前唯一还没被排除的结构性差异：
     * 只链 shim 的最小 main（真 main thread 上跑）在屏，我们的产物不在屏。 */
    out[11] = pthread_main_np() ? 1 : 0;
    out[12] = [NSThread isMainThread] ? 1 : 0;
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
