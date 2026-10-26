#!/usr/bin/env bash
# cmd/patch-shell-textarea-frame.sh — shell 物化的 kind 版 textarea 补主题边框。
#
# 背景（bug T 复验，2026-10-27）：materialize.cpp 的 Input/Textarea 共用分支里，
# frame 是 InputBase::New 的裸 Div，多行臂再塞 base Textarea::New 的裸 FlexCol——
# 屏上与普通文本无异。单行输入的边框不在这一层：catalog Input 走 retained_forms
# 的 MaterializeInput → ui/input.cpp 的主题 Input（appearance 分支 Radius/Bg/Border，
# 焦点态 border 取 ring）。参照链查清的结论：
#   · kind 版 input/textarea 的 PHP spec **不发**任何 style op（Element.php 的
#     input()/textarea() 只有 kind+id），所以裸不是样式被盖掉，是根本没人发；
#   · MaterializeNode 收尾的样式 op 应用（NullaryStyle/ParamStyle）在这条 case
#     之后照跑，没有提前 return 跳过它——补样式要补在分支里，不是补在 spec 侧。
# 修法：textarea 臂的 frame 对齐 ui/input.cpp 主题组件（Medium padX=10/padY=8、
# Radius(th.radius)、Bg(inputBg，disabled 取 muted)、Border(1, focused?ring:inputBorder)、
# FocusRing、disabled 半透明）。边框只落 frame 一处，内层 base Textarea 列无框——
# 不双框。shell 的**单行**分支保持裸框：inputs.php 注释里"裸框可看可比"是明说的
# 像素契约（in-name 裸 vs in-cat 带框），本轮不动。
#
# 用法：GPUI_DIR=<gpui-kit-cpp> cmd/patch-shell-textarea-frame.sh （默认 build/gpui-kit-cpp）

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
MAT="$GPUI_DIR/src/shell/materialize.cpp"
MARK="typephp-gpui: shell textarea themed frame"

[[ -f "$MAT" ]] || { echo "ERROR: materialize.cpp 缺失" >&2; exit 1; }

if grep -qF "$MARK" "$MAT"; then
    echo "materialize.cpp: already patched, skip"
else
    python3 - "$MAT" "$MARK" <<'PYEOF'
import sys
path, mark = sys.argv[1], sys.argv[2]
src = open(path).read()

inc_old = """#include "base/checkbox.h"
#include "ui/checkbox.h"
"""
inc_new = """#include "base/checkbox.h"
#include "ui/checkbox.h"
// typephp-gpui: textarea frame patch —— ThemeNow/th.inputBorder/ring 从这来。
#include "ui/theme.h"
"""
if src.count(inc_old) != 1:
    sys.exit("include anchor count=%d" % src.count(inc_old))
src = src.replace(inc_old, inc_new, 1)

old = """            if (textarea) {
                frame->ItemsStart()->Child(Textarea::New(cx, state));
            } else {
                frame->ItemsCenter()->Child(Input::New(cx, state));
            }
            return frame;
"""
new = """            if (textarea) {
                // """ + mark + """: kind 版 textarea 此前是
                // 裸 Div + 裸 FlexCol（InputBase::New 不带样式、base Textarea::New
                // 只建列），屏上与普通文本无异。这里对齐 ui/input.cpp 的主题组件链——
                // 单行输入（catalog Input）的边框就来自那一层（appearance 分支：
                // Radius(th.radius)/Bg(inputBg，disabled 取 muted)/Border(1,
                // focused ? ring : inputBorder)；Medium 的 padX=10/padY=8 是
                // InputSizeMetrics 的 kInputPadX/kInputPadY）。边框只落在 frame 上，
                // 内层 base Textarea 列不带框——不双框。shell 的单行分支保持裸框：
                // "裸框可看可比"是 examples/_shared/features/inputs.php 注释里明说的
                // 像素契约，本轮不动它。
                const Theme& th = ThemeNow(cx->app);
                bool focused = state->HasSelectionFocus(cx->win) ||
                               FocusHandleContainsFocused(cx->win, state->focus);
                frame->ItemsStart()
                    ->PadX(10)
                    ->PadY(8)
                    ->Radius(th.radius)
                    ->Bg(behavior.disabled ? th.muted : th.inputBg)
                    ->Border(1, focused ? th.ring : th.inputBorder)
                    ->FocusRing(true)
                    ->Child(Textarea::New(cx, state));
                if (behavior.disabled) {
                    frame->Opacity(0.5f);
                }
                return frame;
            }
            frame->ItemsCenter()->Child(Input::New(cx, state));
            return frame;
"""
if src.count(old) != 1:
    sys.exit("textarea branch anchor count=%d" % src.count(old))
src = src.replace(old, new, 1)
open(path, "w").write(src)
print("  shell/materialize.cpp: textarea 臂补主题边框（含 ui/theme.h）")
PYEOF
fi

n=$(grep -cF "$MARK" "$MAT" || true)
if [[ "$n" != "1" ]]; then
    echo "ERROR: self-check failed (marker=$n want 1)" >&2
    exit 1
fi
echo "Patch complete. Regenerate amalgam: (cd $GPUI_DIR && bun cmd/build.ts -rel hello_world)"
