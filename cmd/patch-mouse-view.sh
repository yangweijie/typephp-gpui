#!/usr/bin/env bash
# cmd/patch-mouse-view.sh — 让鼠标与滚轮事件在 view 层就直接进宿主队列，不进 QuickJS。
#
# 为什么必须改在这里（而不是只加 PHP 糖）：materialize 给这些行为挂的监听器全是
# `Listen(cx, &ScriptView::OnScriptMouse*, cbid)` / `&ScriptView::OnMouseMove`
# （materialize.cpp:2701-2735），而这几个 handler 的函数体原本调
# ShellRuntime::DispatchMouseMove / DispatchMouseButton / DispatchScrollWheel → QuickJS。
# PHP 宿主没有脚本引擎：#53 就是同一形状的 key_down 一按真实键就段错误，修法是
# cmd/patch-key-view.sh 把 body 换成弱钩子入队（点击更早就是 cmd/patch-view.sh 这个形状）。
# 所以**只发 op 不接通投递 = 一条假糖**（#66 那五个变体的教训），这条补丁是先决条件。
#
# 已知的代价（与 click/key 两条相同，写进文档）：脚本端那对 propagate / stopPropagation
# 语义在这里没有了 —— 入队后不再回写 event->propagate，事件是否继续向外由宿主决定。
#
# 前置：cmd/patch-view.sh（弱钩子声明区）与 cmd/patch-key-view.sh（本脚本以它落的
# gpuiEnqueueKey 声明为插入锚点）。
# 用法：GPUI_DIR=<gpui-kit-cpp> cmd/patch-mouse-view.sh   （默认 build/gpui-kit-cpp）

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
VIEW="$GPUI_DIR/src/shell/view.cpp"
MARK="typephp-gpui: gpuiEnqueueMouse"

[[ -f "$VIEW" ]] || { echo "ERROR: view.cpp not found" >&2; exit 1; }

if grep -qF "$MARK" "$VIEW"; then
    echo "view.cpp: already patched, skip"
else
    grep -qF "gpuiEnqueueClick" "$VIEW" \
        || { echo "ERROR: 需先跑 cmd/patch-view.sh（要先有那组弱钩子声明区）" >&2; exit 1; }
    grep -qF "gpuiEnqueueKey" "$VIEW" \
        || { echo "ERROR: 需先跑 cmd/patch-key-view.sh（本脚本用它的声明行当插入锚点）" >&2; exit 1; }
    echo "patching mouse/scroll/move handlers with the host enqueue hooks..."
    cp "$VIEW" "$VIEW.mouse.bak"
    python3 - "$VIEW" "$MARK" <<'PYEOF'
import sys
path, mark = sys.argv[1], sys.argv[2]
src = open(path).read()

# ── 1) 钩子声明 + 修饰键编码（紧跟 patch-key-view.sh 落下的那行声明）──
decl_anchor = ("__attribute__((weak)) void gpuiEnqueueKey(ScriptView*, Ctx*, uint64_t, "
               "unsigned, const char*, int) {}")
if src.count(decl_anchor) != 1:
    sys.exit("hook decl anchor count=%d" % src.count(decl_anchor))
src = src.replace(
    decl_anchor,
    decl_anchor + "\n"
    + "// " + mark + ": pointer events go straight to the host queue (strong bodies\n"
    + "// live in cpp-src/gpui_shim_events.inc) instead of ScriptView →\n"
    + "// ShellRuntime::Dispatch{MouseMove,MouseButton,ScrollWheel} → QuickJS.\n"
    + "static inline int gpuiModBits(const Modifiers& m) {\n"
    + "    return (m.control ? 1 : 0) | (m.alt ? 2 : 0) | (m.shift ? 4 : 0)\n"
    + "         | (m.platform ? 8 : 0) | (m.function ? 16 : 0);\n"
    + "}\n"
    + "__attribute__((weak)) void gpuiEnqueueMouse(ScriptView*, Ctx*, uint64_t, int, float, float, int, int) {}\n"
    + "__attribute__((weak)) void gpuiEnqueueScroll(ScriptView*, Ctx*, uint64_t, float, float, float, float, int) {}\n"
    + "__attribute__((weak)) void gpuiEnqueueMove(ScriptView*, Ctx*, uint64_t, float, float, int, int, int) {}",
    1)

reps = [
# ── 2) 三个按下/抬起 handler：按钮 → callback 的选择留在原地，只换投递 ──
("""void ScriptView::OnScriptMouseDown(ScriptView* self, Ctx* cx,
                                   const MouseDownEvent* event,
                                   int64_t binding) {
    auto* buttons = (const ShellMouseButtonBinding*)binding;
    shell::CallbackId callback =
        event ? MouseButtonCallback(buttons, event->button) : 0;
    if (!self || !self->runtime || !callback) return;
    self->runtime->DispatchMouseButton(
        callback, event->button, event->x, event->y, event->clickCount,
        event->modifiers, event->el, true, cx->win, cx->app);
}""",
 """void ScriptView::OnScriptMouseDown(ScriptView* self, Ctx* cx,
                                   const MouseDownEvent* event,
                                   int64_t binding) {
    auto* buttons = (const ShellMouseButtonBinding*)binding;
    shell::CallbackId callback =
        event ? MouseButtonCallback(buttons, event->button) : 0;
    if (!self || !self->runtime || !callback) return;
    // ''' + mark + ''' — 按钮位、坐标、连击数与修饰键一起交给宿主。
    gpuiEnqueueMouse(self, cx, (uint64_t)callback, (int)event->button, event->x,
                     event->y, event->clickCount, gpuiModBits(event->modifiers));
}"""),
("""void ScriptView::OnScriptMouseUp(ScriptView* self, Ctx* cx,
                                 const MouseUpEvent* event, int64_t binding) {
    auto* buttons = (const ShellMouseButtonBinding*)binding;
    shell::CallbackId callback =
        event ? MouseButtonCallback(buttons, event->button) : 0;
    if (!self || !self->runtime || !callback) return;
    self->runtime->DispatchMouseButton(callback, event->button, event->x,
                                       event->y, 1, event->modifiers, event->el,
                                       true, cx->win, cx->app);
}""",
 """void ScriptView::OnScriptMouseUp(ScriptView* self, Ctx* cx,
                                 const MouseUpEvent* event, int64_t binding) {
    auto* buttons = (const ShellMouseButtonBinding*)binding;
    shell::CallbackId callback =
        event ? MouseButtonCallback(buttons, event->button) : 0;
    if (!self || !self->runtime || !callback) return;
    gpuiEnqueueMouse(self, cx, (uint64_t)callback, (int)event->button, event->x,
                     event->y, 1, gpuiModBits(event->modifiers));
}"""),
("""void ScriptView::OnScriptMouseDownOut(ScriptView* self, Ctx* cx,
                                      const MouseDownEvent* event,
                                      int64_t callback) {
    if (!self || !self->runtime || !event) return;
    self->runtime->DispatchMouseButton(
        (shell::CallbackId)callback, event->button, event->x, event->y,
        event->clickCount, event->modifiers, event->el, true, cx->win, cx->app);
}""",
 """void ScriptView::OnScriptMouseDownOut(ScriptView* self, Ctx* cx,
                                      const MouseDownEvent* event,
                                      int64_t callback) {
    if (!self || !self->runtime || !event) return;
    gpuiEnqueueMouse(self, cx, (uint64_t)callback, (int)event->button, event->x,
                     event->y, event->clickCount, gpuiModBits(event->modifiers));
}"""),
# ── 3) 滚轮：deltaX/deltaY 是 DIP（一格 48），propagate 回写按 click/key 的既例丢掉 ──
("""    if (!self || !self->runtime || !event) return;
    bool propagate = true;
    self->runtime
        ->DispatchScrollWheel((shell::CallbackId)callback, *event, Bounds{},
                              false, &propagate, cx->win, cx->app);
    const_cast<ScrollWheelEvent*>(event)->propagate = propagate;
}""",
 """    if (!self || !self->runtime || !event) return;
    gpuiEnqueueScroll(self, cx, (uint64_t)callback, event->deltaX, event->deltaY,
                      event->x, event->y, gpuiModBits(event->modifiers));
}"""),
# ── 4) 元素级 mouse_move（窗口级那份是另一个 handler，没在这里）──
("""void ScriptView::OnMouseMove(ScriptView* self, Ctx* cx,
                             const MouseMoveEvent* event, int64_t callback) {
    if (!self || !self->runtime || !event) return;
    self->runtime->DispatchMouseMove((shell::CallbackId)callback, *event,
                                     cx->win, cx->app);
}""",
 """void ScriptView::OnMouseMove(ScriptView* self, Ctx* cx,
                             const MouseMoveEvent* event, int64_t callback) {
    if (!self || !self->runtime || !event) return;
    gpuiEnqueueMove(self, cx, (uint64_t)callback, event->x, event->y,
                    event->pressed ? 1 : 0, (int)event->pressedButton,
                    gpuiModBits(event->modifiers));
}"""),
]
for a, b in reps:
    if src.count(a) != 1:
        sys.exit("anchor count=%d for:\n%s" % (src.count(a), a[:70]))
    src = src.replace(a, b, 1)
open(path, "w").write(src)
print("  OnScriptMouseDown/Up/DownOut + ScrollWheel + OnMouseMove → gpuiEnqueue*")
PYEOF
fi

# ── 自检：三个钩子都得有调用点，QuickJS 那三条派发路都得断掉 ──
n_hooks=$(grep -cF "gpuiEnqueueMouse(self" "$VIEW" || true)
n_scroll=$(grep -cF "gpuiEnqueueScroll(self" "$VIEW" || true)
n_move=$(grep -cF "gpuiEnqueueMove(self" "$VIEW" || true)
[[ "$n_hooks" == "3" ]] || { echo "ERROR: gpuiEnqueueMouse 调用点=$n_hooks（应为 3）" >&2; exit 1; }
[[ "$n_scroll" == "1" ]] || { echo "ERROR: gpuiEnqueueScroll 调用点=$n_scroll（应为 1）" >&2; exit 1; }
[[ "$n_move" == "1" ]] || { echo "ERROR: gpuiEnqueueMove 调用点=$n_move（应为 1）" >&2; exit 1; }
for gone in DispatchMouseButton DispatchScrollWheel DispatchMouseMove; do
    if grep -qF "$gone" "$VIEW"; then
        echo "ERROR: view.cpp 里还留着 $gone（QuickJS 那条路没断干净）" >&2
        exit 1
    fi
done
echo "  ✓ mouse/scroll/move 五个 handler 全走宿主入队，Dispatch* 三路已断"
