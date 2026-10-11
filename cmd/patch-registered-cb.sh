#!/usr/bin/env bash
# cmd/patch-registered-cb.sh — 让 catalog 组件（Registered）的 ComponentCallback 能
# 回调进 PHP，而不只 QuickJS。DropdownMenu.item(label, callback) 等把回调存成
# ComponentArgument.callback，点击时走 shell::ComponentCallback::InvokeWith → JS_Call。
# 我们无 JS 闭包，故给回调条目加一个 shimId 旁路：命中时把事件塞进窗口的 GpuiEvent
# 队列（App->on(id,type) 消费），完全跳过 JS_Call。
#
# 背景：ShellRuntimeImpl / CallbackEntry / CallbackArena 只在 runtime.cpp、不在
# amalgam 头里，shim 够不着。本补丁加一层薄公开入口 ShellRuntime::PushShimCallback，
# 并在 InvokeWith 顶部加 shimId 早返回分支（shimId 对任何 JS 条目恒为 0，零回归）。
# 上游更新后重跑即可；补丁后需重跑 bun cmd/build.ts 生成 amalgam，再 build-shim.sh。
#
# 用法：GPUI_DIR=<gpui-kit-cpp> cmd/patch-registered-cb.sh   （默认 build/gpui-kit-cpp（upstream/ 的沙箱副本））

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
HDR="$GPUI_DIR/src/shell/runtime.h"
SRC="$GPUI_DIR/src/shell/runtime.cpp"
MARK="PushShimCallback"

[[ -f "$HDR" && -f "$SRC" ]] || { echo "ERROR: runtime.h/runtime.cpp not found" >&2; exit 1; }

if grep -qF "$MARK" "$SRC"; then
    echo "runtime.cpp: already patched, skip"
    exit 0
fi

echo "patching runtime for shim-registered callbacks (CallbackEntry.shimId + InvokeWith branch + PushShimCallback)..."
cp "$HDR" "$HDR.cb.bak"; cp "$SRC" "$SRC.cb.bak"

# ── runtime.h：在 CreateRetainedFocus 声明后加 PushShimCallback ──
python3 - "$HDR" <<'PYEOF'
import sys
path = sys.argv[1]
src = open(path).read()
anchor = "    shell::EntityHandle CreateRetainedFocus(App* app, EntityId owner, void* application);"
if anchor not in src:
    sys.exit("anchor `CreateRetainedFocus(...)` decl not found in runtime.h")
decl = anchor + '''

    // typephp-gpui: register a synthetic callback entry that carries a shim id
    // (our Box2 callbackId) instead of a QuickJS function. InvokeWith routes it
    // to the window's event queue. Returns the runtime CallbackId to store in a
    // ComponentArgument.callback, or 0 on failure.
    shell::CallbackId PushShimCallback(EntityId view, uint64_t shimId);'''
src = src.replace(anchor, decl, 1)
open(path, "w").write(src)
print("  runtime.h: PushShimCallback declaration added")
PYEOF

# ── runtime.cpp：三处编辑 ──
python3 - "$SRC" <<'PYEOF'
import sys
path = sys.argv[1]
src = open(path).read()

# 1) CallbackEntry 加 shimId 字段。
a1 = "    uint64_t registeredIn = 0;\n    bool committed = false;\n};"
if a1 not in src:
    sys.exit("CallbackEntry struct end anchor not found")
r1 = "    uint64_t registeredIn = 0;\n    bool committed = false;\n    // typephp-gpui: non-zero routes this entry to the PHP shim (Box2 callbackId)\n    // instead of QuickJS. Always 0 for entries the script-binding layer pushed.\n    uint64_t shimId = 0;\n};"
src = src.replace(a1, r1, 1)

# 2) PushShimCallback 定义：插在 CreateRetainedSlider 之后（此处 impl/callbacks 完整可见）。
a2 = '''    SliderValue initial = SliderSingle(min);
    return impl->retained.CreateSlider(min, max, step, SliderScale::Linear,
                                       initial, app, owner, application);
}'''
if a2 not in src:
    sys.exit("CreateRetainedSlider body anchor not found")
r2 = a2 + '''

// typephp-gpui: a committed, generation-immune callback entry whose only job is
// to survive LiveComponentCallback's liveness check (valid, live view) and carry
// a shimId. function/policy/application stay null — InvokeWith returns before
// touching them.
shell::CallbackId ShellRuntime::PushShimCallback(EntityId view, uint64_t shimId) {
    ShellRuntimeImpl* impl = ShellRuntimeAccess::Impl(this);
    if (!impl || shimId == 0 || impl->callbacks.nextCallback == UINT64_MAX)
        return 0;
    CallbackEntry* entry = new CallbackEntry();
    entry->id = impl->callbacks.nextCallback++;
    entry->generation = UINT64_MAX;
    entry->function = JS_UNDEFINED;
    entry->view = view;
    entry->policy = nullptr;
    entry->application = nullptr;
    entry->registeredIn = 0;
    entry->committed = true;
    entry->shimId = shimId;
    VecAppend(impl->callbacks.entries, entry);
    return entry->id;
}'''
src = src.replace(a2, r2, 1)

# 3) InvokeWith：顶部加 shimId 早返回分支（含弱符号旁路声明）。
a3 = '''    if (entry && !ok)
        failure = StrDup(into, StrL("component callback needs a live window"));
    if (ok) {'''
if a3 not in src:
    sys.exit("InvokeWith liveness anchor not found")
r3 = '''    if (entry && !ok)
        failure = StrDup(into, StrL("component callback needs a live window"));
    // typephp-gpui: a shim-registered callback routes to the window's event
    // queue instead of QuickJS. shimId is 0 for every JS entry, so this never
    // diverts the normal script-callback path. The strong symbol lives in the
    // c++20 shim; this weak stub keeps the amalgam linkable on its own.
    if (ok && entry->shimId) {
        gpuiEnqueueShimCallback(app, entry->view, entry->shimId);
        return true;
    }
    if (ok) {'''
src = src.replace(a3, r3, 1)

# 4) 弱符号定义：紧挨 InvokeWith 之前（namespace gpui 内，App/EntityId 在 scope）。
a4 = "bool shell::ComponentCallback::InvokeWith("
if a4 not in src:
    sys.exit("InvokeWith signature anchor not found")
r4 = '''// typephp-gpui: weak seam overridden by the shim's strong symbol (which knows
// Box2/g_views). No-op default so the amalgam links standalone.
__attribute__((weak)) void gpuiEnqueueShimCallback(App* app, EntityId view,
                                                   uint64_t shimId) {
    (void)app;
    (void)view;
    (void)shimId;
}

''' + a4
src = src.replace(a4, r4, 1)

open(path, "w").write(src)
print("  runtime.cpp: shimId field + PushShimCallback + InvokeWith branch + weak stub added")
PYEOF

echo "Patch complete. Regenerate amalgam: (cd $GPUI_DIR && bun cmd/build.ts -rel hello_world) then bash cmd/build-shim.sh"
