#!/usr/bin/env bash
# cmd/patch-dispatch-cb.sh — 把 Stage D 的 shimId 旁路从 ComponentCallback::InvokeWith
# 扩到通用回调调用器 DispatchArgs，让【所有】runtime 回调（菜单动作 DispatchAction、
# retained 事件、点击等）都能回 PHP，而不只 catalog 组件回调。
#
# 这是 root-app epic（MenuBar 动作路由 / VirtualList / Dock）的基石：它们都经
# DispatchArgs → JS_Call。给 DispatchArgs 顶部加一个 shimId 早返回分支（命中即入队
# 窗口事件队列，复用 patch-registered-cb.sh 的 gpuiEnqueueShimCallback 弱符号）。
# shimId 对任何 JS 条目恒为 0 → 零回归。
#
# 前置：cmd/patch-registered-cb.sh 已跑（提供 CallbackEntry.shimId + 弱符号）。
# 用法：GPUI_DIR=<gpui-kit-cpp> cmd/patch-dispatch-cb.sh   （默认 vendor/gpui-kit-cpp）

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/vendor/gpui-kit-cpp}"
SRC="$GPUI_DIR/src/shell/runtime.cpp"
MARK="typephp-gpui: shim route (DispatchArgs)"

[[ -f "$SRC" ]] || { echo "ERROR: runtime.cpp not found" >&2; exit 1; }

if grep -qF "$MARK" "$SRC"; then
    echo "runtime.cpp: already patched, skip"
    exit 0
fi

# 依赖 patch-registered-cb.sh 先落（shimId 字段 + gpuiEnqueueShimCallback 弱符号）。
if ! grep -qF "PushShimCallback" "$SRC"; then
    echo "ERROR: 需先跑 cmd/patch-registered-cb.sh（提供 CallbackEntry.shimId + 弱符号）" >&2
    exit 1
fi

echo "patching DispatchArgs with shimId early-return..."
cp "$SRC" "$SRC.dispatch.bak"

python3 - "$SRC" "$MARK" <<'PYEOF'
import sys
path, mark = sys.argv[1], sys.argv[2]
src = open(path).read()

# DispatchArgs 在 InvokeWith 之前，而 gpuiEnqueueShimCallback 的弱定义随
# patch-registered-cb.sh 落在 InvokeWith 前——故此处需先给个前向声明。
decl_anchor = "static void DispatchArgs(ShellRuntime* runtime, shell::CallbackId id,"
if decl_anchor not in src:
    sys.exit("DispatchArgs signature anchor not found")
decl = '''// typephp-gpui: forward-declared here (DispatchArgs uses it before the weak
// definition that patch-registered-cb.sh places above InvokeWith). The shim's
// strong symbol overrides at link.
void gpuiEnqueueShimCallback(App* app, EntityId view, uint64_t shimId);

''' + decl_anchor
src = src.replace(decl_anchor, decl, 1)

anchor = '''    if (!live) {
        for (int i = 0; i < count; i++) JS_FreeValue(impl->context, leading[i]);
        return;
    }
    shell::CallScopeGuard scope ='''
if anchor not in src:
    sys.exit("DispatchArgs liveness anchor not found")
repl = '''    if (!live) {
        for (int i = 0; i < count; i++) JS_FreeValue(impl->context, leading[i]);
        return;
    }
    // ''' + mark + r''': a shim-registered callback routes to the window's event
    // queue instead of QuickJS. shimId is 0 for every JS entry, so this never
    // diverts the normal script path. Reuses gpuiEnqueueShimCallback (declared by
    // patch-registered-cb.sh). Payload is dropped — PHP handlers read state via
    // the element id, not the JS payload object.
    if (entry->shimId) {
        for (int i = 0; i < count; i++) JS_FreeValue(impl->context, leading[i]);
        gpuiEnqueueShimCallback(app, entry->view, entry->shimId);
        return;
    }
    shell::CallScopeGuard scope ='''
src = src.replace(anchor, repl, 1)
open(path, "w").write(src)
print("  runtime.cpp: DispatchArgs forward-decl + shimId branch added")
PYEOF

echo "Patch complete. Regenerate amalgam: (cd $GPUI_DIR && bun cmd/build.ts -rel hello_world) then bash cmd/build-shim.sh"
