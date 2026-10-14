#!/usr/bin/env bash
# cmd/patch-select-view.sh — 把"存在部件 binding 里的那批回调"从 QuickJS 改走宿主队列。
#
# 为什么要这一支（2026-10-14，一次 6 次跑崩 4 次的 SIGSEGV 查出来的）：
# `ResolveBehavior`（materialize.cpp:230-300）把 `op.callback` **原样**塞进 behavior，
# 而 `gpui_shim_callback` 给的是 **Box2 自己的 callbackId**——它从来不是 runtime 回调表里的
# id（只有 catalog 那条 `gpui_shim_new_callback` → `PushShimCallback` 才会登记 runtime 条目）。
# 于是 on_click/on_hover/on_key_* 这些**经 view.cpp 弱钩子**的名字是通的（钩子直接拿 Box2 id 入队），
# 而存在 binding 里、由部件自己择机调 `runtime->DispatchChange/Signal/String()` 发出来的那一族
# （open_change / confirm / dismiss / step / popover 开合 / bound_bool / dock 布局变化）
# 从来没到过 PHP：`CallbackArena::Get(id)` 扫一张没有该项的表，扫到就静默丢。
# 而这条扫描本身在 `impl` 已不健康时会直接段错误——崩溃报告三份同栈：
# `DispatchArgs+100` ← `ScriptView::OnSelectAction+360` ← `WindowDispatchActionFrom`，
# 反汇编逐字对上 `for (i < len(entries)) { if (entry->committed && …) }`。
#
# 所以这条补丁同时是两件事的修法：**崩溃**（完全不进回调表）与
# **那一族事件的送达**（把 Box2 id 交回它自己的 box 队列）。
#
# 形状跟着已验证的三支走：patch-view.sh（点击）→ patch-key-view.sh（按键）→
# patch-mouse-view.sh（指针）。都在 view 层换投递点，不去动 ShellRuntime::Dispatch*：
# 那是在已经进了 JS 函数之后再想退出来，位置不对（任务 #53 的两条弯路）。
#
# 语义与边界：
#   * `gpuiEnqueueChange(self, cx, cb, bool)` / `gpuiEnqueueString(self, cx, cb, const char*)`
#     是既有钩子与新增钩子，强符号在 cpp-src/gpui_shim_events.inc，值走 box->enqueue 的
#     vbool/vstr ——所以 open_change 的 true/false 与 step 的 increment/decrement **带着载荷到 PHP**，
#     不再是"shim 路丢载荷"那一格。
#   * `gpuiEnqueueSignal` 无载荷（上游 DispatchSignal 本来也不带）。
#   * 与 click/key 同一取舍：不再回写 event->propagate，是否继续向外由宿主决定。
#   * **没覆盖**的两处同形状缺口：`OnResize`（DispatchNumbers，一串 float）与
#     `OnItemSecondaryClick`（DispatchString 带 value）——见 docs/guide/events.md。
#
# 前置：cmd/patch-view.sh（弱钩子声明区）与 cmd/patch-mouse-view.sh
#       （本脚本以它落下的 gpuiEnqueueMove 声明行当插入锚点）。
# 用法：GPUI_DIR=<gpui-kit-cpp> cmd/patch-select-view.sh   （默认 build/gpui-kit-cpp）

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
VIEW="$GPUI_DIR/src/shell/view.cpp"
MARK="typephp-gpui: gpuiEnqueueSignal"

[[ -f "$VIEW" ]] || { echo "ERROR: view.cpp not found" >&2; exit 1; }

if grep -qF "$MARK" "$VIEW"; then
    echo "view.cpp: already patched, skip"
    exit 0
fi

grep -qF "gpuiEnqueueClick" "$VIEW" \
    || { echo "ERROR: 需先跑 cmd/patch-view.sh（要先有那组弱钩子声明区）" >&2; exit 1; }
grep -qF "gpuiEnqueueMove" "$VIEW" \
    || { echo "ERROR: 需先跑 cmd/patch-mouse-view.sh（本脚本用它的声明行当插入锚点）" >&2; exit 1; }

echo "patching binding-stored callbacks onto the host queue..."
cp "$VIEW" "$VIEW.select.bak"

python3 - "$VIEW" "$MARK" <<'PYEOF'
import sys

path, mark = sys.argv[1], sys.argv[2]
src = open(path).read()

# ── 1) 两个新钩子的声明，紧跟 patch-mouse-view.sh 落下的那三行 ──
decl_anchor = "__attribute__((weak)) void gpuiEnqueueMove(ScriptView*, Ctx*, uint64_t, float, float, int, int, int) {}"
if src.count(decl_anchor) != 1:
    sys.exit("hook decl anchor count=%d" % src.count(decl_anchor))
src = src.replace(
    decl_anchor,
    decl_anchor + "\n"
    + "// " + mark + ": callbacks stored in a component binding (ShellSelectBinding /\n"
    + "// ShellBoolBinding / …) are Box2 callback ids, never runtime CallbackIds, so they\n"
    + "// must not enter CallbackArena::Get() — both because the scan finds nothing (the\n"
    + "// event is dropped) and because it faults when impl is stale (SIGSEGV in DispatchArgs).\n"
    + "// Strong bodies live in cpp-src/gpui_shim_events.inc.\n"
    + "__attribute__((weak)) void gpuiEnqueueSignal(ScriptView*, Ctx*, uint64_t) {}\n"
    + "__attribute__((weak)) void gpuiEnqueueString(ScriptView*, Ctx*, uint64_t, const char*) {}",
    1)

reps = [
    # ── 2) Popover 的开合：bool 载荷交给既有的 gpuiEnqueueChange ──
    ("""    if (!self || !self->runtime || !event) return;
    self->runtime->DispatchChange((shell::CallbackId)callback, event->open,
                                  cx->win, cx->app);
}""",
     """    if (!self || !self->runtime || !event) return;
    // ''' + mark + ''' — 开合的 bool 就是 PHP 侧 elementValue()/checked() 读到的那个值。
    gpuiEnqueueChange(self, cx, (uint64_t)callback, event->open);
}"""),
    # ── 3) bound_bool：值存在 binding 里，照原样带过去 ──
    ("""    if (!self || !self->runtime || !value || !value->callback) return;
    self->runtime
        ->DispatchChange(value->callback, value->value, cx->win, cx->app);
}""",
     """    if (!self || !self->runtime || !value || !value->callback) return;
    gpuiEnqueueChange(self, cx, (uint64_t)value->callback, value->value);
}"""),
    # ── 4) Select 关闭：先 dismiss（无载荷），再 open_change=false ──
    ("""    if (value->onDismiss)
        self->runtime->DispatchSignal(value->onDismiss, cx->win, cx->app);
    if (value->onOpenChange)
        self->runtime
            ->DispatchChange(value->onOpenChange, false, cx->win, cx->app);""",
     """    if (value->onDismiss)
        gpuiEnqueueSignal(self, cx, (uint64_t)value->onDismiss);
    if (value->onOpenChange)
        gpuiEnqueueChange(self, cx, (uint64_t)value->onOpenChange, false);"""),
    # ── 5) Select 打开 ──
    ("""    if (value->onOpenChange)
        self->runtime
            ->DispatchChange(value->onOpenChange, true, cx->win, cx->app);""",
     """    if (value->onOpenChange)
        gpuiEnqueueChange(self, cx, (uint64_t)value->onOpenChange, true);"""),
    # ── 6) Confirm 动作 ──
    ("""        case SelectAction::Confirm:
            if (value->onConfirm)
                self->runtime
                    ->DispatchSignal(value->onConfirm, cx->win, cx->app);
            break;""",
     """        case SelectAction::Confirm:
            if (value->onConfirm)
                gpuiEnqueueSignal(self, cx, (uint64_t)value->onConfirm);
            break;"""),
    # ── 7) NumberInput 的步进：字符串载荷 ──
    ("""    if (!self || !self->runtime || !event || !callback) return;
    self->runtime->DispatchString((shell::CallbackId)callback,
                                  event->action == StepAction::Increment
                                      ? StrL("increment")
                                      : StrL("decrement"),
                                  cx->win, cx->app);
}""",
     """    if (!self || !self->runtime || !event || !callback) return;
    // ''' + mark + ''' — increment/decrement 以字符串入队，PHP 侧 elementValue('step') 读得到。
    gpuiEnqueueString(self, cx, (uint64_t)callback,
                      event->action == StepAction::Increment ? "increment"
                                                             : "decrement");
}"""),
    # ── 8) Dock 布局变化：上游本来就不带载荷 ──
    ("""    // The event carries nothing: what changed is the whole layout, and dump()
    // is how a subscriber reads it.
    self->runtime
        ->DispatchSignal((shell::CallbackId)callback, cx->win, cx->app);""",
     """    // The event carries nothing: what changed is the whole layout, and dump()
    // is how a subscriber reads it.
    gpuiEnqueueSignal(self, cx, (uint64_t)callback);"""),
]

# 反向替换：一处不中就整支失败，免得半补丁树（漂移门只查 marker，查不出半条替换）。
for old, new in sorted(reps, key=lambda p: src.rfind(p[0]), reverse=True):
    n = src.count(old)
    if n != 1:
        sys.exit("replacement count=%d for:\n%s" % (n, old[:120]))
    src = src.replace(old, new, 1)

open(path, "w").write(src)
print("view.cpp: binding-stored callbacks now go to the host queue (%d sites)" % len(reps))
PYEOF
