#!/usr/bin/env bash
# cmd/patch-token-render.sh — token 的渲染回调（'token' op）由宿主（PHP）渲染。
#
# 上游 token 渲染只有一条脚本路：ShellRuntime::RenderInlineToken（runtime.cpp）
# 拿 entry->function 做 JS_Call，产出带 __id 的元素对象再 ElementId → materialize。
# shim 登记的回调（PushShimCallback）entry->function 恒为 JS_UNDEFINED——JS_Call
# 必然抛异常，succeeded=false，物化侧回落 token label chip（materialize.cpp
# ShellRenderToken 的 Div+TextEl）。批6 的宿主数据回路救不了它：那条路只拦
# CallInLayout 的 shimId 分叉，RenderInlineToken 是独立的 JS_Call 点。
#
# 分叉判据与 patch-dispatch-cb.sh / patch-host-list.sh 同一个：entry->shimId 非零
# ⇒ 宿主登记的回调。命中时交给宿主钩子 gpuiRenderShimToken，由它负责"调 PHP 处理器
# → 元素描述进一块临时 arena → ShellMaterializeSpec"；PHP 返回 null / 处理器缺席
# ⇒ out 置空，上游按"渲染失败"回落 label chip（与 JS 路语义一致）。token 事件
# （on_token_click / on_token_hover）不走这里——它们经 Dispatch→DispatchArgs 的
# shimId 早返回自动入队（patch-dispatch-cb.sh），PHP 侧零改动。
#
# 用法：GPUI_DIR=<gpui-kit-cpp> cmd/patch-token-render.sh   （默认 build/gpui-kit-cpp）

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
SRC="$GPUI_DIR/src/shell/runtime.cpp"
MARK="typephp-gpui: host-rendered inline token"

[[ -f "$SRC" ]] || { echo "ERROR: runtime.cpp not found" >&2; exit 1; }

if grep -qF "$MARK" "$SRC"; then
    [[ $(grep -cF 'gpuiRenderShimToken(cx->app, entry->view, entry->shimId, cx, &root, &hostEl)' "$SRC") -eq 1 &&
       $(grep -cF '__attribute__((weak)) bool gpuiRenderShimToken(App*, EntityId, uint64_t,' "$SRC") -eq 1 ]] \
        || { echo "ERROR: token render marker exists but its hook signature is incomplete" >&2; exit 1; }
    echo "runtime.cpp: token render already patched, skip"
    exit 0
fi

echo "patching RenderInlineToken for host-rendered tokens..."
cp "$SRC" "$SRC.tokenrender.bak"

python3 - "$SRC" "$MARK" <<'PYEOF'
import sys
path, mark = sys.argv[1], sys.argv[2]
src = open(path).read()

fn = "El* ShellRuntime::RenderInlineToken(shell::CallbackId render,"
if src.count(fn) != 1:
    sys.exit("RenderInlineToken anchor count=%d" % src.count(fn))
decl = ("// " + mark + ": the host's renderer for a shim-registered 'token' callback.\n"
        "// Weak and false here so the stock amalgam still links; the shim's strong\n"
        "// definition overrides it. True means handled (el may be null → label chip).\n"
        "__attribute__((weak)) bool gpuiRenderShimToken(App*, EntityId, uint64_t,\n"
        "                                               Ctx*, uint32_t*, El**) { return false; }\n\n")
src = src.replace(fn, decl + fn, 1)

body = """    if (entry->view.IsValid() && !EntityGet(cx->app, entry->view))
        return nullptr;
"""
if src.count(body) != 1:
    sys.exit("RenderInlineToken view anchor count=%d" % src.count(body))
src = src.replace(body, body + """    // """ + mark + """ — a shim-registered renderer belongs to the host: it describes
    // the element itself (element_arg bridge's emitTree shape). A null answer and
    // an absent handler both land on the label-chip fallback below.
    if (entry->shimId) {
        uint32_t root = 0;
        El* hostEl = nullptr;
        gpuiRenderShimToken(cx->app, entry->view, entry->shimId, cx, &root, &hostEl);
        return hostEl;
    }
""", 1)
open(path, "w").write(src)
print("runtime.cpp: RenderInlineToken routes shim renderers to the host")
PYEOF

[[ $(grep -c 'gpuiRenderShimToken(cx->app' "$SRC") -eq 1 ]] \
    || { echo "ERROR: 宿主分叉没落上（应恰好一处）" >&2; exit 1; }
[[ $(grep -c '__attribute__((weak)) bool gpuiRenderShimToken' "$SRC") -eq 1 ]] \
    || { echo "ERROR: weak 桩没落上（应恰好一处）" >&2; exit 1; }
echo "runtime.cpp: token render marker verified"
