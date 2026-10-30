#!/usr/bin/env bash
# cmd/patch-host-element-cb.sh — 返回 Element 的 catalog 回调由宿主（PHP）渲染。
#
# 覆盖三族同缝回调：MessageScroller 的 render_item（chat.cpp RenderRow →
# BuildInteractiveWith）、DataTable 的 render_cell、Select/Combobox 的 render_row
# ——它们都汇入 BuildComponentElement（runtime.cpp）：LiveComponentCallback 拿表项
# → CallInLayout JS_Call → ElementId 要求带 __id 的元素对象。shim 登记的回调
# entry->function 恒为 JS_UNDEFINED，CallInLayout 的 shimId 分叉（patch-host-data.sh）
# 走 ComponentDataToJs 产出**普通 JS 值**，ElementId 必失败——上游画各自的错误行
# （"Failed to render message row"）。
#
# 本补丁在 BuildComponentElement 的表项检查之后加 shimId 分叉：命中即调宿主钩子
# gpuiBuildShimElement，由它"调 PHP 处理器 → 元素描述经 emitTree 进宿主 batch →
# 根 SpecId 回来"，然后按 JS 路同款把 batch 包进 RenderSnapshot 挂 frameSnapshots
# （生存期记账不能省——batch 活到画帧结束）。PHP 返回 null ⇒ 根 0、el 空，上游
# 按各自语义回落（错误行/chip）。数据半边（rows/items 快照）不经过这里——那条路
# 是 CallInLayout 的 shimId 分叉（patch-host-data.sh），两条分叉互不重叠。
#
# 用法：GPUI_DIR=<gpui-kit-cpp> cmd/patch-host-element-cb.sh   （默认 build/gpui-kit-cpp）

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
SRC="$GPUI_DIR/src/shell/runtime.cpp"
MARK="typephp-gpui: host element callback"

[[ -f "$SRC" ]] || { echo "ERROR: runtime.cpp not found" >&2; exit 1; }

if grep -qF "$MARK" "$SRC"; then
    [[ $(grep -cF 'gpuiBuildShimElement(cx->app, entry->view, entry->shimId,' "$SRC") -eq 1 &&
       $(grep -cF '__attribute__((weak)) bool gpuiBuildShimElement(App*, EntityId, uint64_t,' "$SRC") -eq 1 ]] \
        || { echo "ERROR: element-cb marker exists but its hook signature is incomplete" >&2; exit 1; }
    echo "runtime.cpp: host element callback already patched, skip"
    exit 0
fi

echo "patching BuildComponentElement for host-rendered callbacks..."
cp "$SRC" "$SRC.elementcb.bak"

python3 - "$SRC" "$MARK" <<'PYEOF'
import sys
path, mark = sys.argv[1], sys.argv[2]
src = open(path).read()

fn = "static El* BuildComponentElement(ShellRuntime* runtime, shell::CallbackId id,"
if src.count(fn) != 1:
    sys.exit("BuildComponentElement anchor count=%d" % src.count(fn))
decl = ("// " + mark + ": the host's renderer for element-returning catalog callbacks\n"
        "// (MessageScroller render_item, DataTable render_cell, Select render_row).\n"
        "// Weak and false here so the stock amalgam still links; the shim's strong\n"
        "// definition overrides it. True means handled (el may be null → upstream\n"
        "// falls back to its own error row / chip).\n"
        "__attribute__((weak)) bool gpuiBuildShimElement(App*, EntityId, uint64_t,\n"
        "        const shell::ComponentDataValue*, int, Ctx*, shell::SpecArena*,\n"
        "        Str*, El**, uint32_t*) { return false; }\n\n")
src = src.replace(fn, decl + fn, 1)

body = """    if (!entry || !cx->win) {
        if (error) *error = failure;
        return nullptr;
    }
"""
if src.count(body) != 1:
    sys.exit("BuildComponentElement entry anchor count=%d" % src.count(body))
src = src.replace(body, body + """    // """ + mark + """ — a shim-registered renderer belongs to the host. The batch and
    // snapshot bookkeeping mirror the JS path below: the arena must live until the
    // frame snapshot is consumed.
    if (entry->shimId) {
        // batch 分配在 cx->a（帧 arena）——与 gpuiRenderShimItems 的行描述同款
        // 帧作用域生命期：这些回调都在 PrePaint/物化帧内被消费，每帧重渲染，
        // 不需要 JS 路的 RenderSnapshot（那是给堆上 batch 跨帧续命用的；且 shim
        // 表项的 entry->view 是 PhpView 实体，frameSnapshots 的 ScriptView 路走
        // 不得——Get 无类型检查，Vec 会拿到垃圾分配器，实测 malloc abort）。
        shell::SpecArena* hostBatch = new shell::SpecArena(cx->a);
        El* hostEl = nullptr;
        uint32_t hostRoot = 0;
        Str hostFailure;
        bool hostOk = gpuiBuildShimElement(cx->app, entry->view, entry->shimId,
                                           arguments, count, cx, hostBatch,
                                           &hostFailure, &hostEl, &hostRoot);
        if (error) *error = hostOk ? hostFailure
                                   : StrDup(cx->a, StrL("host element callback failed"));
        return hostOk ? hostEl : nullptr;
    }
""", 1)
open(path, "w").write(src)
print("runtime.cpp: BuildComponentElement routes shim renderers to the host")
PYEOF

[[ $(grep -c 'gpuiBuildShimElement(cx->app' "$SRC") -eq 1 ]] \
    || { echo "ERROR: 宿主分叉没落上（应恰好一处）" >&2; exit 1; }
[[ $(grep -c '__attribute__((weak)) bool gpuiBuildShimElement' "$SRC") -eq 1 ]] \
    || { echo "ERROR: weak 桩没落上（应恰好一处）" >&2; exit 1; }
echo "runtime.cpp: host element callback marker verified"
