#!/usr/bin/env bash
# cmd/patch-time-field-press.sh — 让 catalog 时间字段在"按下"时取焦点（窗口通用路径）。
#
# 为什么需要：时间字段的段（hour/minute…）有部件自己的 OnMouseDown→OnSegmentDown
# 自聚焦（base/time_field.cpp），但段间——冒号那一格——是裸 Div，没有任何监听器；
# 点它什么都不发生（bug D 的现场）。窗口通用的"按下即取焦点"本来是兜底，但它认的
# 只是 FocusIdTakesPress（window_common.cpp）这个旗标：root 已经有 PathId
# （clickFromPath ⇒ 有命中矩形、focusId 挂 st->focus）、TrackFocus、KeyContext、
# OnAction、OnKeyDown，唯独没有 focusOnPress——补上 `->FocusOnPress(!disabled)`
# 这一个旗标，死区点击才把焦点迁到字段实体（键编辑才接得上）。
# disabled 的字段不给（上游语义：按住 disabled 控件焦点不动，见 Rust 的
# when(!disabled) 约定）。
#
# 「root 挂 OnMouseDown 按 x 命中最近 segment」的增强是有意的后手，等真人验收
# 之后再定；本补丁只做这个最小面。
#
# 用法：GPUI_DIR=<gpui-kit-cpp> cmd/patch-time-field-press.sh   （默认 build/gpui-kit-cpp）

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
TF="$GPUI_DIR/src/base/time_field.cpp"
MARK="typephp-gpui: time field takes press"

[[ -f "$TF" ]] || { echo "ERROR: time_field.cpp 缺失" >&2; exit 1; }

if grep -qF "$MARK" "$TF"; then
    echo "time_field.cpp: already patched, skip"
else
    python3 - "$TF" "$MARK" <<'PYEOF'
import sys
path, mark = sys.argv[1], sys.argv[2]
src = open(path).read()
old = """                   ->TrackFocus(st->focus)
                   ->TabStop(!disabled);"""
new = """                   // """ + mark + """: root 有 PathId
                   // （clickFromPath ⇒ 有命中矩形、focusId 挂 st->focus），但窗口通用
                   // 路径的 FocusIdTakesPress 只认 focusOnPress 这一个旗标——段内的
                   // 点击有部件自己的 OnMouseDown→OnSegmentDown 自聚焦，段间（冒号
                   // 死区）没有任何监听器，缺这个旗标按死区不迁移焦点。disabled 的
                   // 字段不给（上游语义：按住 disabled 控件焦点不动）。
                   // TrackFocus 必须留着：PathId 把 focusFromPath 置真，IdCollect 会把
                   // focusId 覆盖成路径哈希，焦点就落到与 OnSegmentDown 聚的那颗
                   // FocusHandle 不同的 id 上了（两 id 不一致正是上游注释警告的坑）。
                   ->TrackFocus(st->focus)
                   ->FocusOnPress(!disabled)
                   ->TabStop(!disabled);"""
if src.count(old) != 1:
    sys.exit("time_field anchor count=%d" % src.count(old))
open(path, "w").write(src.replace(old, new, 1))
print("  base/time_field.cpp: root ->FocusOnPress(!disabled)")
PYEOF
fi

nMark=$(grep -cF "$MARK" "$TF" || true)
if [[ "$nMark" != "1" ]]; then
    echo "ERROR: self-check failed (marker=$nMark want 1)" >&2
    exit 1
fi
echo "Patch complete. Regenerate amalgam: (cd $GPUI_DIR && bun cmd/build.ts -rel hello_world)"
