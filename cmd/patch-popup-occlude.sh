#!/usr/bin/env bash
# cmd/patch-popup-occlude.sh — 弹层遮挡语义：盖住的地方归弹层（bug E 的防错面）。
#
# 为什么需要（两处独立缺陷，同一个 marker）：
#   ① ui/popover.cpp 的 DropdownPlaceContent 没挂 StopMouseDown——base/popup.cpp 的
#      PopupPlaceContent 有（那里注释明说「what a popup covers belongs to the popup」），
#      于是走 dropdown placement 的弹层（DatePicker/Select 全族）盖住的区域还会把
#      点击/hover 穿透给底下的元素。
#   ② ui/time.cpp 的 retained DatePicker 把弹层锚状态挂在**固定 id "pop"** 上——
#      PopupAnchorState 按元素 id 存（base/popup.cpp 的 ElementState），多颗
#      DatePicker 同 id 就共享同一颗锚状态、contentReady 互踩。select.cpp 的
#      `fmt("%s-popup", id)` 是先例；self->id 是 "date-picker-<index>-<gen>"
#      （DatePicker::New 的实体构造），逐实例唯一。
# 注意这只是防错，不主张单独治好 E 的三症状（重画/读数/复开）——那是另一条诊断线。
# facade 版 DatePicker（shell ComponentKind::DatePicker，choices/hello 在用）另有
# 一处 StrL("pop")（time.cpp 的 DatePicker IntoEl）：每支示例只有一颗 facade 实例，
# 无多实例互踩，不在本补丁范围。
#
# 用法：GPUI_DIR=<gpui-kit-cpp> cmd/patch-popup-occlude.sh   （默认 build/gpui-kit-cpp）

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
POPOVER="$GPUI_DIR/src/ui/popover.cpp"
TIME="$GPUI_DIR/src/ui/time.cpp"
MARK="typephp-gpui: popup occludes"

[[ -f "$POPOVER" && -f "$TIME" ]] || { echo "ERROR: popover.cpp / time.cpp 缺失" >&2; exit 1; }

if grep -qF "$MARK" "$POPOVER"; then
    echo "popover.cpp: already patched, skip"
else
    python3 - "$POPOVER" "$MARK" <<'PYEOF'
import sys
path, mark = sys.argv[1], sys.argv[2]
src = open(path).read()
old = """    content->AnchorBelow(gap)->Left(0)->Fixed()->Deferred()->AnchorFlip();"""
new = """    // """ + mark + """: 对齐 base/popup.cpp PopupPlaceContent 的
    // occlude 语义（那里注释明说「弹层盖住的地方归弹层」）——没有 StopMouseDown，
    // DatePicker/Select 全族弹层盖住的区域还会把点击穿透给下面的元素。
    content->AnchorBelow(gap)->Left(0)->Fixed()->StopMouseDown()->Deferred()->AnchorFlip();"""
if src.count(old) != 1:
    sys.exit("popover anchor count=%d" % src.count(old))
open(path, "w").write(src.replace(old, new, 1))
print("  ui/popover.cpp: DropdownPlaceContent -> StopMouseDown()")
PYEOF
fi

if grep -qF "$MARK" "$TIME"; then
    echo "time.cpp: already patched, skip"
else
    python3 - "$TIME" "$MARK" <<'PYEOF'
import sys
path, mark = sys.argv[1], sys.argv[2]
src = open(path).read()
# 锚点带上前四行：`->Child(Popup::New(cx, StrL("pop"), trigger)` 在本文件出现两次
#（retained 版 :1512 与 facade 版 :1653），facade 那处每例只有一颗、不在本补丁范围，
# 不带上下文就分不清两处。
old = """                   ->TrackFocus(state->focus)
                   ->TabStop(!self->disabled)
                   ->FlexNone()
                   ->W(self->width)
                   ->Child(Popup::New(cx, StrL("pop"), trigger)"""
new = """                   ->TrackFocus(state->focus)
                   ->TabStop(!self->disabled)
                   ->FlexNone()
                   ->W(self->width)
                   // """ + mark + """: PopupAnchorState 按元素 id 存
                   // （base/popup.cpp 的 ElementState），固定 id "pop" 会让多颗
                   // DatePicker 共享同一颗锚状态、contentReady 互踩；select.cpp 的
                   // "%s-popup" 是先例。self->id 是 "date-picker-<index>-<gen>"
                   // （DatePicker::New 的实体构造），逐实例唯一。
                   ->Child(Popup::New(cx, StrDup(cx->a, fmt("%s-popup", self->id)), trigger)"""
if src.count(old) != 1:
    sys.exit("time.cpp anchor count=%d" % src.count(old))
open(path, "w").write(src.replace(old, new, 1))
print("  ui/time.cpp: popup id -> fmt(\"%s-popup\", self->id)")
PYEOF
fi

nPop=$(grep -cF "$MARK" "$POPOVER" || true)
nTime=$(grep -cF "$MARK" "$TIME" || true)
if [[ "$nPop" != "1" || "$nTime" != "1" ]]; then
    echo "ERROR: self-check failed (popover=$nPop want 1, time=$nTime want 1)" >&2
    exit 1
fi
echo "Patch complete. Regenerate amalgam: (cd $GPUI_DIR && bun cmd/build.ts -rel hello_world)"
