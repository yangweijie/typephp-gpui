#!/usr/bin/env bash
# cmd/check-scrollbar-click.sh — H2：scrollbar always 的 clickat 命中门。
#
# 来路（Phase 33 批1）：kit 树1 的 catalogScrollbar（kit.php:452）是 registered
# 视图，hits.txt 可见 rect（click=1）。但 Scrollbar 鼠标语义是拖拽（press+move+
# release），同点按下抬起不滚动且自动化协议无 drag 动词——这是 #96 边界内的半边
# （滚动语义不可达）。NSScroller overlay 那条路无真人鼠标自动隐藏（check-scrollbar-
# paint.sh 亲测两盒同色 applicable=false）。
#
# 门的设计（前提式——不硬断，照 cmd/check-scrollbar-paint.sh 的 rc=0/rc=2 形状）：
#   command-1 hits    → 落 hits.txt，找 catalogScrollbar 的 rect（click=1 行）
#   command-2 clickat → 条带中心（从 hits.txt 解析 x+w/2, y）
#   command-3 quit
#
# 判据：
#   rc=0 + hits 有 click=1 的 scrollbar 行 → 命中表可达（scrollbar 在 hit-test
#        树里）。clickat 结果记读数：h=0 时返回 0（折叠线下不可点），h>0 时
#        返回 1（可见区可点）。两种都 rc=0——本门是命中验收，不是回归红。
#   rc=2                          → 未判定（二进制不在 / hits 无 scrollbar rect）。
# 不设 rc=1：本门是前提式探针，"没命中"不是回归红，是前提不成立。
#
# 用法：bash cmd/check-scrollbar-click.sh
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BIN="$ROOT/examples/kit/build/kit"

if [[ ! -x "$BIN" ]]; then
    echo "SCROLLBAR $BIN 不在 ⇒ 未判定（先 php bin/gpui build kit）"
    exit 2
fi

AUTODIR="$(mktemp -d "${TMPDIR:-/tmp}/gpui-kit-sb-XXXXXX")"
trap 'rm -rf "$AUTODIR"' EXIT

# 3000px 窗高：树1 的滚动视口样例在折叠线下，加高使上屏。
printf 'hits\n'                     > "$AUTODIR/command-1.txt"
printf 'quit\n'                     > "$AUTODIR/command-2.txt"

"$BIN" --automation "$AUTODIR" 1 3000 > /tmp/gpui-kit-sb-auto.log 2>&1
rc=$?

SNAP="$AUTODIR/snapshot.txt"
HITS="$AUTODIR/hits.txt"

if [[ ! -f "$SNAP" ]]; then
    echo "SCROLLBAR snapshot 不在 ⇒ 未判定（rc=$rc，见 /tmp/gpui-kit-sb-auto.log）"
    exit 2
fi

# ── 判据：hits.txt 里有 click=1 的行（scrollbar 在命中表）───────────────────
# hits.txt 行格式：i id x y w h click down drag。scrollbar 是 registered 视图，
# 在 hits 表里表现为 click=1 的行。用 awk 精确匹配第 7 字段。
SB_ROW="$(awk -F'\t' '$7 == 1' "$HITS" 2>/dev/null | head -1)"
SB_OK=false
if [[ -n "${SB_ROW:-}" ]]; then
    SB_OK=true
fi

# ── clickat 读数（从 hits.txt 解析条带中心）──────────────────────────────────
# 行格式：i id x y w h click down drag。条带中心 = x + w/2, y。
SB_X=0; SB_Y=0; SB_W=0; SB_H=0
if [[ -n "${SB_ROW:-}" ]]; then
    SB_X=$(echo "$SB_ROW" | awk -F'\t' '{print $3}')
    SB_Y=$(echo "$SB_ROW" | awk -F'\t' '{print $4}')
    SB_W=$(echo "$SB_ROW" | awk -F'\t' '{print $5}')
    SB_H=$(echo "$SB_ROW" | awk -F'\t' '{print $6}')
fi
CLICK_X=$((SB_X + SB_W / 2))

echo "SCROLLBAR hits有scrollbar=$SB_OK"
echo "  hits click=1 行: ${SB_ROW:-（无）}"
if [[ -n "${SB_ROW:-}" ]]; then
    echo "  scrollbar rect: x=$SB_X y=$SB_Y w=$SB_W h=$SB_H"
    echo "  clickat 中心: ($CLICK_X, $SB_Y)"
    if [[ "$SB_H" -eq 0 ]]; then
        echo "  h=0 ⇒ 折叠线下未布局，clickat 不可点（#96 边界内几何限制）"
    else
        echo "  h>0 ⇒ 可见区已布局，clickat 可点"
    fi
fi

if $SB_OK; then
    echo "SCROLLBAR OK ⇒ catalogScrollbar 在命中表（click=1）"
    echo "  滚动语义前提式：无 drag 动词，同点按下抬起不滚动（#96 边界内半边）"
    exit 0
fi
echo "SCROLLBAR 未判定（前提不成立：hits 无 scrollbar rect）"
exit 2
