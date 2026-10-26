/* cmd/event-tap-probe.c — 进程外的鼠标事件见证：listen-only CGEventTap。
 *
 * 为什么要有它（task_plan.md Phase 25 / 任务 #98）：物理 leftMouseDown 从没进我们进程的
 * 事件队列，而我所有仪器都在我们进程内——"事件没进来"与"我们的进程不被允许接收"在这种
 * 仪器下永远分不开。这条探针在**会话层**看原始流：事件到底发生了没有、系统把它判给了哪个
 * pid。它不注入、不改写（kCGEventTapOptionListenOnly），只打印。
 *
 * 权限边界要如实报：建不出来（缺辅助功能授权）就 exit 3 并打印 TAP-UNAVAILABLE——
 * 这是"仪器不可用 ⇒ 未判定"，不是"没有事件"。
 *
 * 编译：bash cmd/build-tap-probe.sh   产物 /tmp/gpui-event-tap-probe
 * 用法：/tmp/gpui-event-tap-probe            （stdout 每事件一行，Ctrl-C 结束）
 */
#include <ApplicationServices/ApplicationServices.h>
#include <stdio.h>
#include <stdlib.h>
#include <unistd.h>
#include <signal.h>

static volatile int g_run = 1;

static void on_sigint(int sig) {
    (void)sig;
    g_run = 0;
}

/* CGEventType → 短名。只打我们关心的这几类，其余打数字，别把流刷爆。 */
static const char* type_name(CGEventType t) {
    switch (t) {
        case kCGEventLeftMouseDown:    return "L-down";
        case kCGEventLeftMouseUp:      return "L-up";
        case kCGEventLeftMouseDragged: return "L-drag";
        case kCGEventRightMouseDown:   return "R-down";
        case kCGEventRightMouseUp:     return "R-up";
        case kCGEventMouseMoved:       return "moved";
        case kCGEventScrollWheel:      return "scroll";
        case kCGEventKeyDown:          return "key-down";
        case kCGEventKeyUp:            return "key-up";
        case kCGEventFlagsChanged:     return "flags";
        default:                       return "other";
    }
}

static CGEventRef tap_cb(CGEventTapProxy proxy, CGEventType type, CGEventRef event,
                         void* refcon) {
    (void)proxy;
    (void)refcon;
    if (!event) {
        return NULL;
    }
    /* 被系统禁用的 tap 会收到 tapDisabled/tapDestroyed：必须如实报，否则读数会被当成
     * "这段时间没有事件"，那是假阴性。 */
    if (type == kCGEventTapDisabledByTimeout || type == kCGEventTapDisabledByUserInput) {
        fprintf(stderr, "TAP-DISABLED type=%d（此后读数不可信）\n", (int)type);
        fflush(stderr);
        return event;
    }
    const char* nm = type_name(type);
    if (nm[0] == 'o') {   /* 杂类事件不打，避免刷屏；计数仍然要 */
        return event;
    }
    CGPoint p = CGEventGetLocation(event);
    /* "系统把这一下判给谁"——这个字段由 WindowServer 填，不是我们自己的推断。 */
    long long target = (long long)CGEventGetIntegerValueField(
        event, kCGEventTargetUnixProcessID);
    printf("TAP %s t=%.3f x=%.0f y=%.0f target_pid=%lld\n", nm,
           CFAbsoluteTimeGetCurrent(), p.x, p.y, target);
    fflush(stdout);
    return event;
}

int main(void) {
    signal(SIGINT, on_sigint);
    const CGEventMask mask =
        (1 << kCGEventLeftMouseDown) | (1 << kCGEventLeftMouseUp) |
        (1 << kCGEventLeftMouseDragged) | (1 << kCGEventRightMouseDown) |
        (1 << kCGEventRightMouseUp) | (1 << kCGEventMouseMoved) |
        (1 << kCGEventScrollWheel) | (1 << kCGEventKeyDown) |
        (1 << kCGEventKeyUp) | (1 << kCGEventFlagsChanged);
    CFMachPortRef tap = CGEventTapCreate(kCGSessionEventTap, kCGHeadInsertEventTap,
                                        kCGEventTapOptionListenOnly, mask, tap_cb, NULL);
    if (!tap) {
        printf("TAP-UNAVAILABLE：建不了会话层 tap（需要给运行者"
               "「辅助功能/输入监控」授权）⇒ 本轮投递读数一律未判定\n");
        fflush(stdout);
        return 3;
    }
    CFRunLoopSourceRef src = CFMachPortCreateRunLoopSource(kCFAllocatorDefault, tap, 0);
    CFRunLoopAddSource(CFRunLoopGetCurrent(), src, kCFRunLoopCommonModes);
    CGEventTapEnable(tap, true);
    printf("TAP-READY pid=%d\n", (int)getpid());
    fflush(stdout);
    while (g_run) {
        CFRunLoopRunInMode(kCFRunLoopDefaultMode, 0.25, true);
    }
    CGEventTapEnable(tap, false);
    CFRelease(src);
    CFRelease(tap);
    printf("TAP-END\n");
    fflush(stdout);
    return 0;
}
