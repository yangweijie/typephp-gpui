#!/usr/bin/env bash
# cmd/patch-number-center.sh — kind 版 NumberInput 的数字居中（对齐 catalog 观感）。
#
# 背景（真人复验 2026-10-27）：input 示例同一页并排两颗 NumberInput——kind 版
# （shell 的 ComponentKind::NumberInput，materialize 的 Compose 分支）与 catalog 版
# （component::NumberInput，ui/input.cpp NumberInput::IntoEl）。catalog 的编辑器是
# `Input::New(...)->Align(InputAlign::Center)`（ui/input.cpp:1528-1531 附近，
# editor.align=1），base/input.cpp 拿它把行改成 `row->W(kFill)->JustifyCenter()`——
# 数字居中；kind 版的编辑器走 `Input::New(cx, state)` 默认投影（InputEditorStyle{}，
# align=0），数字贴左。同一页对照时观感分裂，被复验记为「两版样式不一致」。
#
# 修法：宿主没填 "input" 槽（Slot 返回空）时，按 catalog 同一条投影缝造编辑器——
# InputEditorStyle 只设 align=1，Input::New(cx, state, centered)。只动文本对齐：
# Compose 的三段结构（dec | text Flex1 | inc）、± 步进槽（decrement_button/
# increment_button 装饰槽）与它们的点击落点一律不碰，stepDelivered 门不受影响。
# 宿主显式填了 "input" 槽时仍以槽为准（对齐是宿主的事，本补丁不越权）。
#
# 用法：GPUI_DIR=<gpui-kit-cpp> cmd/patch-number-center.sh   （默认 build/gpui-kit-cpp）

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
MAT="$GPUI_DIR/src/shell/materialize.cpp"
MARK="typephp-gpui: number input centers digits"

[[ -f "$MAT" ]] || { echo "ERROR: materialize.cpp 缺失" >&2; exit 1; }

if grep -qF "$MARK" "$MAT"; then
    echo "materialize.cpp: already patched, skip"
else
    python3 - "$MAT" "$MARK" <<'PYEOF'
import sys
path, mark = sys.argv[1], sys.argv[2]
src = open(path).read()
old = """        El* editor = Slot(cx, runtime, specs, node, "input", error);
        if (!editor) editor = Input::New(cx, state);
"""
new = """        El* editor = Slot(cx, runtime, specs, node, "input", error);
        if (!editor) {
            // """ + mark + """: 宿主没填 "input" 槽时，
            // 这里的编辑器走 base Input::New(cx, state) 的默认投影
            // （InputEditorStyle{}，align=0 → 行内文本左对齐）；catalog 的
            // component::NumberInput（ui/input.cpp NumberInput::IntoEl）给自己的
            // 编辑器是 `->Align(InputAlign::Center)`（editor.align=1 →
            // base/input.cpp 的 `row->W(kFill)->JustifyCenter()`）。同一页并排时
            // kind 版数字贴左、catalog 版居中，观感分裂——这里按 catalog 同一条
            // 投影缝补 align=1，只动文本对齐：± 步进槽（decrement/increment）与
            // Compose 的三段结构（dec | text Flex1 | inc）不动，点击落点不变。
            InputEditorStyle centered;
            centered.align = 1;
            editor = Input::New(cx, state, centered);
        }
"""
if src.count(old) != 1:
    sys.exit("materialize.cpp anchor count=%d" % src.count(old))
open(path, "w").write(src.replace(old, new, 1))
print("  shell/materialize.cpp: NumberInput 默认编辑器补 align=1（数字居中）")
PYEOF
fi

n=$(grep -cF "$MARK" "$MAT" || true)
if [[ "$n" != "1" ]]; then
    echo "ERROR: self-check failed (marker=$n want 1)" >&2
    exit 1
fi
echo "Patch complete. Regenerate amalgam: (cd $GPUI_DIR && bun cmd/build.ts -rel hello_world)"
