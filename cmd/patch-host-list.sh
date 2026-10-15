#!/usr/bin/env bash
# cmd/patch-host-list.sh — 让 list / uniform_list 的行由宿主（PHP）渲染。
#
# 为什么要这一支（#29/#35）：上游虚拟列表的行是 `ShellRuntime::RenderVirtualItems`
# （runtime.cpp）在 **materialize 途中同步** 调脚本要的——`JS_Call(render->function, …)`
# 拿回一组元素描述，再 materialize 成 El。旧结论"C ABI 防火墙不可达"的前提是"宿主
# 没法被同步调用"；其实 bridge 那侧有 `php::call`，缺的只是一个让这条同步调用拐进
# 宿主的分叉点。
#
# 分叉判据与 patch-dispatch-cb.sh 同一个：回调表项的 `shimId` 非零 ⇒ 这是宿主登记的
# 回调（`PushShimCallback`），JS 条目的 shimId 恒 0，所以普通脚本路径一条不改。
# 命中时交给宿主钩子 `gpuiRenderShimItems`，由它负责"要一批行 → 描述进一块临时 arena →
# materialize 进 out[]"，返回后直接 return。行 key 取根元素 id，未指定时取全局行号；
# 宿主钩子同时装上 list 的 item_click / item_secondary_click 监听器。
#
# 用法：GPUI_DIR=<gpui-kit-cpp> cmd/patch-host-list.sh   （默认 build/gpui-kit-cpp）

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
SRC="$GPUI_DIR/src/shell/runtime.cpp"
MARK="typephp-gpui: host-rendered virtual list"

[[ -f "$SRC" ]] || { echo "ERROR: runtime.cpp not found" >&2; exit 1; }

if grep -qF "$MARK" "$SRC"; then
    [[ $(grep -cF 'gpuiRenderShimItems(cx->app, render->view, render->shimId, onItemClick,' "$SRC") -eq 1 &&
       $(grep -cF '__attribute__((weak)) void gpuiRenderShimItems(App*, EntityId, uint64_t, uint64_t,' "$SRC") -eq 1 ]] \
        || { echo "ERROR: host list marker exists but its callback signature is incomplete" >&2; exit 1; }
    echo "runtime.cpp: host list already patched, skip"
    exit 0
fi

echo "patching RenderVirtualItems for host-rendered rows..."
cp "$SRC" "$SRC.list.bak"

python3 - "$SRC" "$MARK" <<'PYEOF'
import sys
path, mark = sys.argv[1], sys.argv[2]
src = open(path).read()

fn = "void ShellRuntime::RenderVirtualItems(shell::CallbackId renderId,"
if src.count(fn) != 1:
    sys.exit("RenderVirtualItems anchor count=%d" % src.count(fn))
decl = ("// " + mark + ": the host's renderer for rows whose callback is a shim entry.\n"
        "// Weak and empty here so the stock amalgam still links; the shim's strong\n"
        "// definition overrides it.\n"
        "__attribute__((weak)) void gpuiRenderShimItems(App*, EntityId, uint64_t, uint64_t,\n"
        "                                               uint64_t, int, int, Ctx*, El**) {}\n\n")
src = src.replace(fn, decl + fn, 1)

body = """    CallbackEntry* render = impl->callbacks.Get(renderId);
    CallbackEntry* key = impl->callbacks.Get(keyId);
"""
if src.count(body) != 1:
    sys.exit("RenderVirtualItems body anchor count=%d" % src.count(body))
src = src.replace(body, body + """    // """ + mark + """ — a shim-registered renderer belongs to the host,
    // which describes the rows itself; the JS path below never sees it.
    if (render && render->shimId) {
        if (!cx->win || !cx->app) return;
        if (render->view.IsValid() && !EntityGet(cx->app, render->view)) return;
        gpuiRenderShimItems(cx->app, render->view, render->shimId, onItemClick,
                            onItemSecondaryClick, first, end, cx, out);
        return;
    }
""", 1)
open(path, "w").write(src)
print("runtime.cpp: RenderVirtualItems routes shim renderers to the host")
PYEOF

[[ $(grep -c 'gpuiRenderShimItems(cx->app' "$SRC") -eq 1 ]] \
    || { echo "ERROR: 宿主分叉没落上（应恰好一处）" >&2; exit 1; }
[[ $(grep -c '__attribute__((weak)) void gpuiRenderShimItems' "$SRC") -eq 1 ]] \
    || { echo "ERROR: weak 桩没落上（应恰好一处）" >&2; exit 1; }
