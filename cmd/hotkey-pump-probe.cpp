/* cmd/hotkey-pump-probe.cpp — 生产 pump 的队列级回归门（#99）。
 *
 * 背景：gpui_mac_pump 曾每帧 ReceiveNextEvent(0, NULL, …, true, …) 排空 Carbon 队列，
 * 而 mask=0+types=NULL 在 Carbon 里是"任意事件"，把 AppKit 队列里的 leftMouseDown
 * 一起摘走丢给没人接的派发目标 ⇒ 注册了热键的示例"能拖窗口、什么都点不动"（#98/#99）。
 * 现行修法是 peek 优先：inRemoveFromQueue=false 只看不取，队头是热键才取走派发。
 *
 * **这条探针驱动的是生产 gpui_mac_pump() 本身**（链接 cpp-src/gpui_mac.mm），不是它的
 * 抄本——抄本只能证明"这个设计在队列层成立"，生产代码改回去它照样绿。
 * 三臂，免人手：
 *   ① 正向：注册一颗真热键（pump 在 g_hotkeys 为空时早退，不注册就等于没测到那条路），
 *      塞"热键在队头、鼠标紧跟其后"一对，跑生产泵 ⇒ 鼠标必须还在、热键必须被取走，
 *      且 sink 真收到一条 hotkey（"事件不见了"与"事件被派发了"是两件事）。
 *   ② 空热键：撤掉热键再塞一对，泵必须一个都不碰（empty() 早退那条路）。
 *   ③ 负向对照：同样一对交给修复前那版"任意事件"排空，它必须吃掉鼠标——
 *      否则本自证没有判别力 ⇒ rc=2 未判定，而不是通过。
 * 红臂由 cmd/check-hotkey-pump.sh 提供：把生产源里 peek 的那个 false 翻成 true 编一份
 * 临时副本，本探针必须随之变红（证明门绑在生产参数上）。
 *
 * 需要窗口会话（Carbon 队列要 AppKit 起来才存在），与其余原生门同类。
 * 跑法：bash cmd/check-hotkey-pump.sh   （编译 + 跑 + 判定，rc 0/1/2）
 */
#include "gpui.h"

#include "gpui_mac.h"

#include <Carbon/Carbon.h>
#include <cstdio>
#include <cstring>

using namespace gpui;

/* 必须与 gpui_mac.mm 的 kHotKeySignature 一致（那是匿名命名空间里的内部常量，
 * 头里没有）。check-hotkey-pump.sh 有一条漂移守卫：两侧字面量对不上就 rc=2。 */
static const UInt32 kProbeSignature = 'gpxi';

static int g_sinkHotkeys = 0;

static void ProbeSink(void*, const char* type, const char*, const char*) {
    if (type && 0 == strcmp(type, "hotkey")) {
        g_sinkHotkeys++;
    }
}

static bool post_mouse(EventKind kind) {
    EventRef e = nullptr;
    if (CreateEvent(kCFAllocatorDefault, kEventClassMouse, kind, 0, 0, &e) != noErr) {
        return false;
    }
    if (PostEventToQueue(GetMainEventQueue(), e, kEventPriorityStandard) != noErr) {
        ReleaseEvent(e);
        return false;
    }
    return true;
}

/* 带真 EventHotKeyID 的热键事件：生产的 OnHotKeyPressed 认 signature 才 Emit，
 * 空参事件会被它退回——那样 sink 计数恒 0，正向臂就少了一条判别力。 */
static bool post_hotkey(int id) {
    EventRef e = nullptr;
    if (CreateEvent(kCFAllocatorDefault, kEventClassKeyboard, kEventHotKeyPressed, 0,
                    kEventAttributeUserEvent, &e) != noErr) {
        return false;
    }
    EventHotKeyID hkId;
    hkId.signature = kProbeSignature;
    hkId.id = static_cast<UInt32>(id);
    const OSStatus prc = SetEventParameter(e, kEventParamDirectObject, typeEventHotKeyID,
                                           sizeof(EventHotKeyID), &hkId);
    const OSStatus qrc = prc == noErr
                             ? PostEventToQueue(GetMainEventQueue(), e, kEventPriorityStandard)
                             : paramErr;
    if (qrc != noErr) {
        ReleaseEvent(e);
        return false;
    }
    return true;
}

/* 历史反事实（生产里已无此写法）：修复前的"任意事件"排空，边取边数鼠标。 */
static int drain_old_count_mouse(void) {
    int n = 0;
    for (;;) {
        EventRef e = nullptr;
        if (ReceiveNextEvent(0, NULL, kEventDurationNoWait, true, &e) != noErr || !e) {
            return n;
        }
        if (GetEventClass(e) == kEventClassMouse) {
            n++;
        }
        SendEventToEventTarget(e, GetEventDispatcherTarget());
        ReleaseEvent(e);
    }
}

/* 一次排空同时数两类。**不能分两趟数**：第一趟（数鼠标）用的是"任意事件"排空，
 * 会把热键也取走释放掉，于是第二趟恒读 0——"留热键=0"就成了构造出来的恒真式，
 * 红臂也一样读 0，这条读数便没有判别力（第一版实测踩到）。 */
struct Left {
    int mouse;
    int hotkey;
};

static Left drain_left(EventKind mouseKind) {
    Left n = {0, 0};
    for (;;) {
        EventRef e = nullptr;
        if (ReceiveNextEvent(0, NULL, kEventDurationNoWait, true, &e) != noErr || !e) {
            return n;
        }
        if (GetEventClass(e) == kEventClassMouse && GetEventKind(e) == mouseKind) {
            n.mouse++;
        } else if (GetEventClass(e) == kEventClassKeyboard &&
                   GetEventKind(e) == kEventHotKeyPressed) {
            n.hotkey++;
        }
        ReleaseEvent(e);
    }
}

struct Probe {
    static El* Render(Probe*, Ctx* cx) {
        return Div(cx->a)->SizeFull()->Child(TextEl(cx->a, StrL("hotkey pump probe")));
    }
};

int GpuiMain(int, char**) {
    App* app = AppNew();
    component::Init(app);
    ThemeSet(app, ThemeMode::Light);
    Entity<Probe> view = EntityNew<Probe>(app);
    if (!KitOpenWindow(app, StrL("hotkey-pump-probe"), 320, 200, view.id, WinOpts{})) {
        fprintf(stderr, "HOTKEY-PUMP 开窗失败 ⇒ 未判定\n");
        return 2;
    }
    gpui_mac_set_sink(&ProbeSink);
    static int ownerTag = 0;
    /* 四修饰键 + F19：尽量避开系统/别的 app 已占的组合；注册不上就未判定，不降级成
     * "不注册热键也测一遍"——那测的是 empty() 早退，不是 #99 的那条路。 */
    const int hk = gpui_mac_hotkey_register(&ownerTag, kVK_F19,
                                            cmdKey | shiftKey | controlKey | optionKey,
                                            "probe-hotkey");
    if (hk <= 0 || gpui_mac_hotkey_count() != 1) {
        fprintf(stderr, "HOTKEY-PUMP 热键注册失败 id=%d count=%d ⇒ 未判定\n", hk,
                gpui_mac_hotkey_count());
        return 2;
    }
    const EventKind mouseKind = (EventKind)2;

    /* ① 正向：生产泵 */
    if (!post_hotkey(hk) || !post_mouse(mouseKind)) {
        fprintf(stderr, "HOTKEY-PUMP 入队失败 ⇒ 未判定\n");
        return 2;
    }
    gpui_mac_pump();
    const Left a = drain_left(mouseKind);
    const int mouse_left = a.mouse;
    const int hotkey_left = a.hotkey;
    const int sink_hits = g_sinkHotkeys;

    /* ② 空热键：撤掉后泵必须一个都不碰 */
    gpui_mac_hotkey_unregister(hk);
    if (gpui_mac_hotkey_count() != 0) {
        fprintf(stderr, "HOTKEY-PUMP 撤热键后 count=%d ⇒ 未判定\n", gpui_mac_hotkey_count());
        return 2;
    }
    if (!post_hotkey(hk) || !post_mouse(mouseKind)) {
        fprintf(stderr, "HOTKEY-PUMP 二次入队失败 ⇒ 未判定\n");
        return 2;
    }
    gpui_mac_pump();
    const Left b = drain_left(mouseKind);
    const int idle_mouse = b.mouse;
    const int idle_hotkey = b.hotkey;

    /* ③ 负向对照：旧排空必须吃掉鼠标 */
    if (!post_hotkey(hk) || !post_mouse(mouseKind)) {
        fprintf(stderr, "HOTKEY-PUMP 三次入队失败 ⇒ 未判定\n");
        return 2;
    }
    const int eaten_old = drain_old_count_mouse();

    const bool ok = (eaten_old == 1) && (mouse_left == 1) && (hotkey_left == 0) &&
                    (sink_hits == 1) && (idle_mouse == 1) && (idle_hotkey == 1);
    fprintf(stderr,
            "HOTKEY-PUMP 旧排空吃鼠标=%d 生产泵留鼠标=%d 留热键=%d 热键送达=%d "
            "无热键时留鼠标=%d 留热键=%d ⇒ %s\n",
            eaten_old, mouse_left, hotkey_left, sink_hits, idle_mouse, idle_hotkey,
            (eaten_old != 1) ? "负向对照不红 ⇒ 未判定"
            : ok                   ? "修法成立：热键照取照派发、鼠标不碰"
                                   : "修法不成立：换方案");
    fflush(stderr);
    if (eaten_old != 1) {
        return 2;
    }
    return ok ? 0 : 1;
}

int main(int argc, char** argv) {
    return GpuiMain(argc, argv);
}