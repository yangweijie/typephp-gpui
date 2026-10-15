#!/usr/bin/env bash
# cmd/patch-window-handle.sh — 把 gpui::Window* 背后那扇真实 NSWindow 露给宿主。
#
# 为什么要这条补丁：像素验收的唯一取帧口 gpui_shim_snapshot 拿不到"传进来的那一扇"
# 是哪扇——amalgam 头里 `PlatWindow` 只有前向声明（gpui.h:6464），而 NSWindow* 就住在
# PlatWindow::window（window_mac.cpp:27 的定义里），窗口的 plat 指针本身是公开的
# （gpui.h:6479）。于是取帧只能靠环境态选窗：旧实现用 [NSApp keyWindow]，拿不到再退化成
# [NSApp windows] 的第一个，传进来的 Box2 根本没参与选窗。前台归谁是 OS 在仲裁的竞争态，
# 所以同一条命令会偶尔拍到**另一扇窗的整帧**（hello tree29/31 实测 gdfrac=1.00000）。
#
# 两条被实测否掉的绕路（别再走）：
#   · Box2::viewPtr 是 C++ 视图不是 NSView（同文件按 gpui::ScriptView* 用它），
#     对它发 ObjC 消息即野指针 ⇒ hello 全部 18 棵 --shot rc=139；
#   · 开窗时 diff [NSApp windows] ⇒ OrderFrontOnce 到首次 Redraw 才建 NSWindow，diff 不出东西。
# 所以正解只能在上游侧开个口：定义体必须落在看得见 PlatWindow 完整定义的 TU（window_mac.cpp），
# 声明贴着同族的窗口自由函数（platform.h 的 WindowDispatchInput），这样它会进 amalgam 头。
#
# 用法：GPUI_DIR=<gpui-kit-cpp> cmd/patch-window-handle.sh   （默认 build/gpui-kit-cpp）

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
HEADER="$GPUI_DIR/src/gpui/platform.h"
IMPL="$GPUI_DIR/src/gpui/window_mac.cpp"
MARK="WindowNativeHandle"

[[ -f "$HEADER" ]] || { echo "ERROR: platform.h not found" >&2; exit 1; }
[[ -f "$IMPL" ]] || { echo "ERROR: window_mac.cpp not found" >&2; exit 1; }

# 两个目标各自逐符号幂等：树可能已经是打过补丁的状态，重复落一次就是两份定义。
if grep -qF "$MARK" "$HEADER" && grep -qF "$MARK" "$IMPL"; then
    echo "platform.h + window_mac.cpp: already patched, skip"
    exit 0
fi

if ! grep -qF "$MARK" "$HEADER"; then
    grep -qF "void WindowDispatchInput(Window* win, const PlatformInput* input);" "$HEADER" \
        || { echo "ERROR: platform.h 里找不到 WindowDispatchInput 那行（上游改过声明区）" >&2; exit 1; }
    echo "patching platform.h: declare WindowNativeHandle..."
    cp "$HEADER" "$HEADER.handle.bak"
    python3 - "$HEADER" <<'PYEOF'
import sys
path = sys.argv[1]
src = open(path).read()
anchor = "void WindowDispatchInput(Window* win, const PlatformInput* input);"
add = anchor + """

// typephp-gpui: the OS window this gpui::Window is drawn into, as an opaque
// handle (void* == NSWindow* on mac) so portable callers never need the
// private PlatWindow definition. Returns nullptr before the platform has
// created the window, and on platforms with no such handle.
void* WindowNativeHandle(Window* win);"""
assert src.count(anchor) == 1, "anchor not unique: %d" % src.count(anchor)
open(path, "w").write(src.replace(anchor, add, 1))
PYEOF
fi

if ! grep -qF "$MARK" "$IMPL"; then
    grep -qF "static void OrderFrontOnce(PlatWindow* pw) {" "$IMPL" \
        || { echo "ERROR: window_mac.cpp 里找不到 OrderFrontOnce（上游改过窗口创建路径）" >&2; exit 1; }
    echo "patching window_mac.cpp: define WindowNativeHandle..."
    cp "$IMPL" "$IMPL.handle.bak"
    python3 - "$IMPL" <<'PYEOF'
import sys
path = sys.argv[1]
src = open(path).read()
anchor = "static void OrderFrontOnce(PlatWindow* pw) {"
add = """// typephp-gpui: expose the owning NSWindow so a capture can be bound to the
// window it was asked for instead of to whatever the OS currently favours.
void* WindowNativeHandle(Window* win) {
    if (!win || !win->plat) {
        return nullptr;
    }
    // 这一层也是 -fobjc-arc 编的：ObjC 指针到 void* 必须走 __bridge，
    // 只借用不接管所有权（窗口由 PlatWindow 自己持有，宿主只在本次取帧里用一下）。
    return (__bridge void*)win->plat->window;
}

""" + anchor
assert src.count(anchor) == 1, "anchor not unique: %d" % src.count(anchor)
open(path, "w").write(src.replace(anchor, add, 1))
PYEOF
fi

# 自检：两个目标都要看得见这个符号，且定义只落了一次。
grep -qF "$MARK" "$HEADER" || { echo "ERROR: platform.h 补丁没落上" >&2; exit 1; }
[[ $(grep -c "void\* WindowNativeHandle(Window\* win) {" "$IMPL") -eq 1 ]] \
    || { echo "ERROR: window_mac.cpp 里定义不是一份" >&2; exit 1; }
echo "patch-window-handle.sh OK"
