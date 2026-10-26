#!/usr/bin/env bash
# cmd/patch-popup-same-frame.sh — 弹层同帧挂载：砍掉 contentReady 的「死帧」。
#
# 背景（bug E 复验，2026-10-27）：base/popup.cpp 的 Popup::New 用一颗按元素 id 存的
# PopupAnchorState 做「首帧捕获」——首次出现的那一帧 contentReady=false，Content()
# 直接不挂内容，再排一个 rAF 等下一帧。PopupPlaceContent 的注释自己写着：本运行时
# 「can measure the trigger and child in one layout pass」，锚定摆放（PlaceAnchored）
# 在整棵树布局完之后跑，弹层自己的尺寸同一趟就有了——Rust 需要预绘捕获是因为它的
# 弹层单独走一趟布局；这里的等价物已经存在，捕获门是一具化石。
#
# 死帧的实际代价（合成探针 + 真人复验共同指向）：开弹层的那一帧命中表里**没有**弹层
# 的任何矩形（occluding surface、OnMouseUpOut 全都不在），真人此时点向弹层的一击落到
# 底下的页面上——input 示例弹层正下方就是 in-ccal 裸日历，吃掉这一击后它的选中态停在
# 被误点的日子上（真人截图「下方弹层高亮 6」与字段 2026/10/23 对不上）。合成路径
# （realClick 背靠背 + loop 显式泵帧）恰好跨过这一帧，所以 selftest 一直量不到。
# 砍掉死帧 = 弹层可见的那一帧起命中面就在，不再有「看得见之前点得到页面」的窗口。
#
# 行为面：所有 Popup 调用方（select/popover/dialog/color_picker/time 的弹层）内容
# 提前一帧挂载；闭合态 content 仍是 nullptr，Content() 的空判据不动。rAF 不再被
# 无谓排布（原先每棵树上每颗 Popup 首帧都排一次）。
#
# 用法：GPUI_DIR=<gpui-kit-cpp> cmd/patch-popup-same-frame.sh   （默认 build/gpui-kit-cpp）

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
POPUP="$GPUI_DIR/src/base/popup.cpp"
MARK="typephp-gpui: popup mounts same frame"

[[ -f "$POPUP" ]] || { echo "ERROR: popup.cpp 缺失" >&2; exit 1; }

if grep -qF "$MARK" "$POPUP"; then
    echo "popup.cpp: already patched, skip"
else
    python3 - "$POPUP" "$MARK" <<'PYEOF'
import sys
path, mark = sys.argv[1], sys.argv[2]
src = open(path).read()
old = """    PopupAnchorState* state =
        ElementState<PopupAnchorState>(cx, id, StrL("PopupAnchorState"));
    p->contentReady = state && state->captured;
    if (state && !state->captured) {
        state->captured = true;
        WindowRequestAnimationFrame(cx->win);
    }
"""
new = """    // """ + mark + """: contentReady 直通。Rust 的首帧预绘捕获在这里没有对应物——
    // PlaceAnchored 在 trigger 与 child 同一趟布局之后跑（PopupPlaceContent 注释
    // 「the resulting bounds are the same」），弹层尺寸当帧即可知。按元素 id 存的
    // PopupAnchorState 只为把内容推迟一帧，代价是开弹层那一帧命中表里没有弹层的
    // 任何矩形：真人那一击落到底下的页面（input 示例正下方是 in-ccal 裸日历，
    // 真人截图「下方弹层高亮 6」即它吃了误点）。合成 realClick 背靠背 + loop 泵帧
    // 跨过这一帧，所以 selftest 一直量不到。砍掉死帧，弹层可见即命中。
    p->contentReady = true;
"""
if src.count(old) != 1:
    sys.exit("popup.cpp anchor count=%d" % src.count(old))
open(path, "w").write(src.replace(old, new, 1))
print("  base/popup.cpp: contentReady 直通（死帧砍掉）")
PYEOF
fi

# 死代码收敛：PopupAnchorState 结构体已无人读，一并摘掉，防止后人误用复活旧语义。
python3 - "$POPUP" "$MARK" <<'PYEOF'
import sys
path, mark = sys.argv[1], sys.argv[2]
src = open(path).read()
dead = "\nstruct PopupAnchorState {\n    bool captured = false;\n};\n\n"
if dead in src:
    src = src.replace(dead, "", 1)
    open(path, "w").write(src)
    print("  base/popup.cpp: PopupAnchorState 死结构体移除")
else:
    print("  base/popup.cpp: PopupAnchorState 已移除或形制不同，跳过")
PYEOF

n=$(grep -cF "$MARK" "$POPUP" || true)
if [[ "$n" != "1" ]]; then
    echo "ERROR: self-check failed (marker=$n want 1)" >&2
    exit 1
fi
echo "Patch complete. Regenerate amalgam: (cd $GPUI_DIR && bun cmd/build.ts -rel hello_world)"
