#!/usr/bin/env bash
# cmd/patch-view.sh — 把 gpui-kit-cpp 接入 TypePHP GPUI：
#   1) view.cpp：ScriptView 事件 handler 从 QuickJS 路由改为 gpuiEnqueue*() 入队
#   2) window_mac.cpp：追加导出 GpuiAppRunStep(app,maxWait)，让 PHP 拥有帧节奏
# 两段各自幂等；上游更新后重跑即可。补丁后需重跑 `bun cmd/build.ts` 生成 amalgam。

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
VIEW="$GPUI_DIR/src/shell/view.cpp"
WIN="$GPUI_DIR/src/gpui/window_mac.cpp"
VIEW_MARK="/* typephp-gpui: patched */"

[[ -f "$VIEW" ]] || { echo "ERROR: $VIEW not found. git submodule update --init?" >&2; exit 1; }
[[ -f "$WIN"  ]] || { echo "ERROR: $WIN not found." >&2; exit 1; }

# ── 1) view.cpp ──
if grep -qF "$VIEW_MARK" "$VIEW"; then
    echo "view.cpp: already patched, skip"
else
    echo "view.cpp: patching..."
    cp "$VIEW" "$VIEW.bak"
    python3 - "$VIEW" <<'PYEOF'
import sys
path = sys.argv[1]
src = open(path).read()
marker = "/* typephp-gpui: patched */\n"
decls = '''/* typephp-gpui — enqueue helpers (bridge provides strong bodies; weak stubs here). */
namespace gpui {
struct ScriptView; struct Ctx;
__attribute__((weak)) void gpuiEnqueueClick(ScriptView*, Ctx*, uint64_t, int) {}
__attribute__((weak)) void gpuiEnqueueChange(ScriptView*, Ctx*, uint64_t, bool) {}
__attribute__((weak)) void gpuiEnqueueHover(ScriptView*, Ctx*, uint64_t, bool) {}
__attribute__((weak)) void gpuiEnqueueInput(ScriptView*, Ctx*, uint64_t, int) {}
__attribute__((weak)) void gpuiEnqueueSlider(ScriptView*, Ctx*, uint64_t, float) {}
} // namespace gpui
'''
idx = src.find("namespace gpui {")
if idx < 0: sys.exit("no 'namespace gpui {' anchor")
src = src[:idx] + marker + decls + "\n" + src[idx:]

reps = [
("""void ScriptView::OnClick(ScriptView* self, Ctx* cx, const ClickEvent* event,
                         int64_t callback) {
    if (!self || !self->runtime || !event) return;
    self->runtime
        ->DispatchClick((shell::CallbackId)callback, *event, cx->win, cx->app);
}""",
 """void ScriptView::OnClick(ScriptView* self, Ctx* cx, const ClickEvent* event,
                         int64_t callback) {
    if (!self || !self->runtime || !event) return;
    gpuiEnqueueClick(self, cx, (uint64_t)callback, event->clickCount);
}"""),
("""void ScriptView::OnChange(ScriptView* self, Ctx* cx, const ClickEvent* event,
                          int64_t value) {
    if (!self || !self->runtime || !event || event->id <= 0) return;
    self->runtime->DispatchChange((shell::CallbackId)(uint32_t)event->id,
                                  value != 0, cx->win, cx->app);
}""",
 """void ScriptView::OnChange(ScriptView* self, Ctx* cx, const ClickEvent* event,
                          int64_t value) {
    if (!self || !self->runtime || !event || event->id <= 0) return;
    gpuiEnqueueChange(self, cx, (uint64_t)(uint32_t)event->id, value != 0);
}"""),
("""void ScriptView::OnHover(ScriptView* self, Ctx* cx, const HoverEvent* event,
                         int64_t callback) {
    if (!self || !self->runtime || !event) return;
    self->runtime->DispatchChange((shell::CallbackId)callback, event->hovered,
                                  cx->win, cx->app);
}""",
 """void ScriptView::OnHover(ScriptView* self, Ctx* cx, const HoverEvent* event,
                         int64_t callback) {
    if (!self || !self->runtime || !event) return;
    gpuiEnqueueHover(self, cx, (uint64_t)callback, event->hovered);
}"""),
("""void ScriptView::OnInputEvent(ScriptView* self, Ctx* cx,
                              const InputEvent* event, int64_t handle) {
    if (!self || !self->runtime || !event) return;
    self->runtime->DispatchInputEvent((shell::EntityHandle)handle, *event,
                                      cx->win, cx->app);
}""",
 """void ScriptView::OnInputEvent(ScriptView* self, Ctx* cx,
                              const InputEvent* event, int64_t handle) {
    if (!self || !self->runtime || !event) return;
    gpuiEnqueueInput(self, cx, (uint64_t)handle, (int)event->kind);
}"""),
("""void ScriptView::OnSliderEvent(ScriptView* self, Ctx* cx,
                               const SliderEvent* event, int64_t handle) {
    if (!self || !self->runtime || !event) return;
    self->runtime->DispatchSliderEvent((shell::EntityHandle)handle, *event,
                                       cx->win, cx->app);
}""",
 """void ScriptView::OnSliderEvent(ScriptView* self, Ctx* cx,
                               const SliderEvent* event, int64_t handle) {
    if (!self || !self->runtime || !event) return;
    gpuiEnqueueSlider(self, cx, (uint64_t)handle, event->value.hi);
}"""),
]
for a,b in reps:
    if a not in src: sys.exit("anchor missing:\n" + a[:60])
    src = src.replace(a,b)
open(path,"w").write(src)
print("  OnClick/OnChange/OnHover/OnInputEvent/OnSliderEvent → enqueue")
PYEOF
fi

# ── 2) window_mac.cpp ──
WIN_MARK="/* typephp-gpui: patched */"
if grep -qF "$WIN_MARK" "$WIN"; then
    echo "window_mac.cpp: already patched, skip"
else
    echo "window_mac.cpp: patching (GpuiAppRunStep + disable entry main + weak GpuiMain)..."
    cp "$WIN" "$WIN.bak"
    python3 - "$WIN" <<'PYEOF'
import sys
path = sys.argv[1]
src = open(path).read()
marker = "/* typephp-gpui: patched */\n"

# 2a. 导出单帧泵 GpuiAppRunStep（复刻 AppRun 一次循环体）。
anchor = "    gRunningApp = nullptr;\n    return app->exitCode;\n}\n"
i = src.find(anchor)
if i < 0: sys.exit("AppRun end anchor not found")
step = r'''
/* typephp-gpui: one-iteration pump so PHP owns the frame cadence. Mirrors
   AppRun's loop body once. Returns app->exitCode (>=0) when no window is open,
   else -1 to keep looping. */
int GpuiAppRunStep(App* app, double maxWaitSecs) {
    if (!app) return 1;
    gRunningApp = app;
    @autoreleasepool {
        for (;;) {
            NSEvent* ev = [NSApp nextEventMatchingMask:NSEventMaskAny
                                             untilDate:nil
                                                inMode:NSDefaultRunLoopMode
                                               dequeue:YES];
            if (!ev) break;
            [NSApp sendEvent:ev];
        }
        if (!AppAnyWindowOpen(app)) { gRunningApp = nullptr; return app->exitCode; }
        for (int j = 0; j < app->windows.len; j++) {
            Window* w = app->windows[j];
            if (w->plat && w->plat->dirty) Redraw(w);
        }
        ExecDrain();
        double now = TimeNow();
        for (int j = 0; j < app->windows.len; j++) {
            Window* w = app->windows[j];
            if (w->plat && w->plat->nextTick > 0 && now >= w->plat->nextTick)
                WindowTimerTick(w);
        }
        double waitS = maxWaitSecs > 0 ? maxWaitSecs : 1.0;
        for (int j = 0; j < app->windows.len; j++) {
            Window* w = app->windows[j];
            if (w->plat && w->plat->dirty) { waitS = 0; break; }
        }
        NSDate* deadline = waitS <= 0
            ? [NSDate distantPast]
            : [NSDate dateWithTimeIntervalSinceNow:waitS];
        NSEvent* ev = [NSApp nextEventMatchingMask:NSEventMaskAny
                                         untilDate:deadline
                                            inMode:NSDefaultRunLoopMode
                                           dequeue:YES];
        if (ev) [NSApp sendEvent:ev];
    }
    gRunningApp = nullptr;
    return AppAnyWindowOpen(app) ? -1 : app->exitCode;
}
'''
src = src[:i+len(anchor)] + step + src[i+len(anchor):]

# 2b. 改名进程入口 main —— TypePHP/nano 自带 main，amalgam 的 main 会重复符号。
main_old = "int main(int argc, char** argv) {"
main_new = "int gpui_kit_main_disabled(int argc, char** argv) {"
if main_old not in src: sys.exit("entry main anchor not found")
src = src.replace(main_old, main_new, 1)

# 2c. weak GpuiMain 桩 —— 被改名的 main 仍引用它；全局命名空间。
gpui_main = r'''
/* typephp-gpui: weak GpuiMain so gpui_kit_main_disabled links; the real entry
   point is TypePHP's generated main, never this one. */
__attribute__((weak)) int GpuiMain(int argc, char** argv) {
    (void)argc; (void)argv;
    return 0;
}
'''
src += gpui_main

# marker 置顶。
src = marker + src
open(path,"w").write(src)
print("  GpuiAppRunStep exported; entry main disabled; weak GpuiMain added")
PYEOF
fi

echo "Patch complete."
