#!/usr/bin/env python3
"""真·OS 输入验收：用 WindowServer 的 session event tap 发鼠标/键盘事件，
读 input --trace 写的事件日志。

为什么还要这条：`App::realClick` 那条缝是把事件交给 `-[NSWindow sendEvent:]`，
它跳过了 NSApplication 的事件队列与激活/钥匙窗口状态。用户手点的真实路径要经过
WindowServer → [NSApp nextEventMatchingMask:] → sendEvent:，中间任何一环（激活策略、
钥匙窗口、事件被谁吞掉）出问题，缝里都是绿的、手里是点不动的。

权限边界（2026-10-12 实测，别把 null 读成"应用坏了"）：在**没有辅助功能权限**的进程里
（比如从 agent/终端起的 python），`kCGSessionEventTap` 不会崩，但投出去的事件**被静默丢弃**
——连 `MouseMoved` 都到不了目标窗口。所以这份脚本的"零事件"结论只有在一个自身能证明
投递成功的会话里才有效：先确认投一条 move 后 `CGEventGetLocation` / 光标位置真的变了，
再谈目标应用收到没收到。自证不过，就改回用 app 内的 `App::realClick/realKey` 缝
（现已走真 NSApp 路由：postEvent → nextEventMatchingMask → -[NSApp sendEvent:]），
或者让人手点一次 `php bin/gpui run input --trace`。
另注：kCGHIDEventTap 在这类进程里会当场把解释器打成 SIGTRAP（exit 133）。
"""
import json
import os
import subprocess
import sys
import time

import Quartz

LOG = "/tmp/input-events.log"
BIN = "./examples/input/build/input"


def window():
    infos = Quartz.CGWindowListCopyWindowInfo(
        Quartz.kCGWindowListOptionOnScreenOnly | Quartz.kCGWindowListExcludeDesktopElements, 0)
    for w in infos:
        if (w.get("kCGWindowOwnerName") or "").lower() == "input":
            b = w["kCGWindowBounds"]
            return b["X"], b["Y"], b["Width"], b["Height"]
    return None


def frontmost():
    subprocess.run(["osascript", "-e",
                    'tell application "System Events" to set frontmost of process "input" to true'],
                   capture_output=True)


def post(kind, x, y, button=Quartz.kCGMouseButtonLeft, clicks=0, key=None, chars=None):
    if kind in (Quartz.kCGEventLeftMouseDown, Quartz.kCGEventLeftMouseUp):
        e = Quartz.CGEventCreateMouseEvent(None, kind, (x, y), button)
        if clicks:
            Quartz.CGEventSetIntegerValueField(e, Quartz.kCGMouseEventClickState, clicks)
    elif kind == Quartz.kCGEventMouseMoved:
        e = Quartz.CGEventCreateMouseEvent(None, kind, (x, y), button)
    else:
        e = Quartz.CGEventCreateKeyboardEvent(None, key, kind == Quartz.kCGEventKeyDown)
        if chars:
            Quartz.CGEventKeyboardSetUnicodeString(e, len(chars), chars)
    Quartz.CGEventPost(Quartz.kCGSessionEventTap, e)
    Quartz.CFRelease(e)


def cursor():
    """当前光标全局坐标。CGEventGetLocation 要一个 event 参数（pyobjc 不接 0 参），
    所以先 CGEventCreate(None) 拿一个空事件再读。"""
    ev = Quartz.CGEventCreate(None)
    p = Quartz.CGEventGetLocation(ev)
    Quartz.CFRelease(ev)
    return p


_SELFPROOF_SNIPPET = """
import sys, time
sys.path.insert(0, {here!r})
import importlib.util as u
s = u.spec_from_file_location('probe_mod', {me!r})
m = u.module_from_spec(s); s.loader.exec_module(m)
home = m.cursor()
m.Quartz.CGWarpMouseCursorPosition((40.0, 40.0))
time.sleep(0.15)
m.post(m.Quartz.kCGEventMouseMoved, 300.0, 300.0)
time.sleep(0.25)
p = m.cursor()
m.Quartz.CGWarpMouseCursorPosition((home.x, home.y))
print('%r %r' % (p.x, p.y))
"""


def inject_selfproof():
    """先证明"探针自己投得出事件"，再谈应用收没收到。

    两个理由让这一步必须在**子进程**里做：
    1. 没有辅助功能/输入监控权限时，CoreGraphics 这几步可能被静默丢弃，甚至当场把解释器
       打成 SIGTRAP（本机实测 rc=133，连 `CGEventCreate`+`CGEventGetLocation` 读光标都过不去）。
       在主进程里做的话，探针会和它要诊断的东西一起没命，什么读数都留不下。
    2. 静默丢弃 + 零事件 ≠ 应用坏了。所以先投一个 MouseMoved 看光标有没有真的跟着走：
       走不动（或子进程被打死）就说明本会话没有注入权限，后面的 null 全部作废。
    """
    import os
    code = _SELFPROOF_SNIPPET.format(here=os.path.dirname(os.path.abspath(__file__)),
                                     me=os.path.abspath(__file__))
    r = subprocess.run([sys.executable, "-c", code], capture_output=True, text=True)
    if r.returncode != 0:
        sig = -r.returncode if r.returncode < 0 else r.returncode
        return False, "子进程 rc=%d（非零＝本机没有事件注入权限，CoreGraphics 调用被打死；" \
                      "133 是 SIGTRAP）%s" % (
            sig, (r.stderr.strip().splitlines() or [''])[-1][:80])
    try:
        x, y = eval(r.stdout.strip())
    except Exception:
        return False, r.stdout.strip()
    return abs(x - 300.0) < 30 and abs(y - 300.0) < 30, (x, y)


def move(x, y):
    post(Quartz.kCGEventMouseMoved, x, y)


def click(x, y):
    post(Quartz.kCGEventLeftMouseDown, x, y, clicks=1)
    time.sleep(0.04)
    post(Quartz.kCGEventLeftMouseUp, x, y, clicks=1)


def type_text(s):
    for ch in s:
        post(Quartz.kCGEventKeyDown, 0, 0, key=0, chars=ch)
        time.sleep(0.02)
        post(Quartz.kCGEventKeyUp, 0, 0, key=0, chars=ch)


def seen():
    try:
        with open(LOG) as f:
            return [ln.strip() for ln in f if ln.strip()]
    except FileNotFoundError:
        return []


def main():
    ok, where = inject_selfproof()
    if not ok:
        print("SELFPROOF FAILED：投出的 MouseMoved 没让光标移动（读数 %r）——"
              "本会话没有事件注入权限，后面的「零事件」不能当成应用的结论。" % (where,))
        return 2
    print("SELFPROOF OK：光标跟着投出去的 move 走了")
    subprocess.run(["pkill", "-f", "input --trace"], check=False)
    if os.path.exists(LOG):
        os.remove(LOG)
    proc = subprocess.Popen([BIN, "--trace"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        w = None
        for _ in range(30):
            time.sleep(0.5)
            w = window()
            if w:
                break
        if not w:
            print("FAIL: 窗口没出现")
            return 1
        x0, y0, ww, hh = w
        print(f"window {ww:.0f}x{hh:.0f} @ {x0:.0f},{y0:.0f}")
        frontmost()
        time.sleep(0.6)
        print(f"frontmost 后 isActive 事件数：{len(seen())}")

        print("阶段 1：真鼠标扫导航列（x=100）")
        for y in range(60, min(430, int(hh) - 20), 14):
            click(x0 + 100, y0 + y)
            time.sleep(0.09)
        clicks = [ln for ln in seen() if ln.startswith("click")]
        print(f"  click 到达 PHP：{len(clicks)} 条  {clicks[:4]}")

        print("阶段 2：真鼠标扫内容区找 hover")
        for y in range(200, min(690, int(hh) - 20), 12):
            move(x0 + 300, y0 + y)
            time.sleep(0.03)
        hovers = [ln for ln in seen() if ln.startswith("hover")]
        print(f"  hover 到达 PHP：{len(hovers)} 条  {hovers[:3]}")

        print("阶段 3：切到第 2 节，真点击输入框并打字")
        # 先点 nav-2（第 2 个按钮），再用真键盘打 gpui
        for y in range(60, min(430, int(hh) - 20), 14):
            click(x0 + 100, y0 + y)
            time.sleep(0.06)
            if any("nav-2 " in ln for ln in seen()):
                break
        before = len(seen())
        for y in range(120, min(660, int(hh) - 20), 12):
            click(x0 + 500, y0 + y)
            time.sleep(0.06)
        type_text("gpui")
        time.sleep(1.2)
        after = seen()[before:]
        changes = [ln for ln in after if ln.startswith("change")]
        keys = [ln for ln in after if ln.startswith("key_down")]
        print(f"  打字后新增事件 {len(after)} 条：change={changes[:3]} key_down={keys[:3]}")

        ok = bool(clicks) and bool(hovers) and bool(changes or keys)
        print("REAL INPUT OK" if ok else "REAL INPUT FAIL")
        print(json.dumps({"clicks": len(clicks), "hovers": len(hovers),
                          "typing": len(changes) + len(keys)}, ensure_ascii=False))
        return 0 if ok else 1
    finally:
        subprocess.run(["pkill", "-f", "input --trace"], check=False)


if __name__ == "__main__":
    sys.exit(main())
