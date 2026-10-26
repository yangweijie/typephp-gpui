#!/usr/bin/env bash
# cmd/patch-input-focus.sh — 让输入框在"按下"时取焦点 + 回车解析成动作（窗口通用路径）。
#
# 为什么需要：gpui 有两条"点击置焦点"的路——
#   ① 窗口通用路径：window_common.cpp 的 DispatchMouseDown 沿命中链找 focusId，
#      命中 FocusIdIsFocusable + FocusIdTakesPress 就 WindowSetFocusId（上游注释
#      承诺的正是这条："A press on the field focuses it and places the caret"）；
#   ② 部件自己：输入框的 frame 挂 OnMouseDown → InputFocus()。
# 在 PHP 宿主里 ② 走不通：shell 的 retained 输入把鼠标监听绑在 ScriptView::OnScriptMouseDown 上，
# 而 cmd/patch-mouse-view.sh 已把它整行换成 gpuiEnqueueMouse（进 PHP 队列，不再派发给部件）。
# 于是 ① 是唯一可用的路，但两处把它关着：
#   · shell/materialize.cpp 的 retained 输入分支用的是 **BindInputText**——它只挂 state、
#     不带 KeyContext/TrackFocus/FocusOnPress：焦点拿不到，回车也解析不成 input::Enter
#     （动作解析的前提是 frame 条目自己在 dispatch 栈里带着 "Input" context，
#     WindowResolveKeyAction 的 [0,ix-1] 扫描才收得到）⇒ 点得到焦点、textarea 回车不换行；
#   · ui/input.cpp 的 InputTrackFrameFocus 显式 FocusOnPress(false)（它依赖 ② 自聚焦）。
# 修法：materialize 侧改用 **BindInput**（gpui.cpp 的 El::BindInput 自带
# KeyContext("Input")/TrackFocus/FocusOnPress，disabled 传 nullptr 时 if (s) 整段跳过、
# 与旧行为等价），input.cpp 侧把 FocusOnPress(false) 翻成 true。
# 实测（2026-10-20，真机点击 + 插桩）：修前 FOCUSGATE target=0、无光标；修后 target=-1001。
# 2026-10-25 追加回车语义：修前 realKey(36,"\r") 在 textarea 上 7→7（不换行）、
# realKey 队列零增长；修后 note +1（见 examples/input selftest 的 enterResolves 门）。
#
# 用法：GPUI_DIR=<gpui-kit-cpp> cmd/patch-input-focus.sh   （默认 build/gpui-kit-cpp）

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
MAT="$GPUI_DIR/src/shell/materialize.cpp"
INP="$GPUI_DIR/src/ui/input.cpp"
MARK="typephp-gpui: input takes press"

[[ -f "$MAT" && -f "$INP" ]] || { echo "ERROR: materialize.cpp / input.cpp 缺失" >&2; exit 1; }

if grep -qF "$MARK" "$MAT"; then
    echo "materialize.cpp: already patched, skip"
else
    python3 - "$MAT" "$MARK" <<'PYEOF'
import sys
path, mark = sys.argv[1], sys.argv[2]
src = open(path).read()
old = """                    ->BindInputText(behavior.disabled ? nullptr : state)
                    ->Flex()
                    ->W(kFill);"""
new = """                    // """ + mark + """: 改用 BindInput——它除了挂 state
                    // 还自带 KeyContext("Input")/TrackFocus/FocusOnPress（gpui.cpp 的
                    // El::BindInput）。宿主没有脚本自聚焦那条路（patch-mouse-view.sh 把
                    // OnScriptMouseDown 改道进 PHP 队列），焦点靠窗口通用"按下即取焦点"；
                    // 而回车要解析成 input::Enter 动作，前提是 frame 条目自己带着
                    // KeyContext——DispatchAnchor 落 frame marker、其前一格就是 context
                    // 节点，WindowResolveKeyAction 的 [0,ix-1] 扫描才收得到。
                    // BindInputText 那版不挂这些，点得到焦点、回车仍是裸字符。
                    ->BindInput(behavior.disabled ? nullptr : state)
                    ->Flex()
                    ->W(kFill);"""
if src.count(old) != 1:
    sys.exit("materialize anchor count=%d" % src.count(old))
open(path, "w").write(src.replace(old, new, 1))
print("  materialize.cpp: retained input -> BindInput（自带 context/焦点）")
PYEOF
fi

if grep -qF "$MARK" "$INP"; then
    echo "input.cpp: already patched, skip"
else
    python3 - "$INP" "$MARK" <<'PYEOF'
import sys
path, mark = sys.argv[1], sys.argv[2]
src = open(path).read()
old = """    frame->TrackFocus(InputFrameFocus(cx, state))
        ->TabStop(false)
        ->FocusOnPress(false);"""
new = """    frame->TrackFocus(InputFrameFocus(cx, state))
        ->TabStop(false)
        // """ + mark + """: 同 materialize 那条——catalog 输入框原先靠自己的
        // OnMouseDown 自聚焦，而宿主那条路被改道了；这里改走窗口通用路径。
        ->FocusOnPress(true);"""
if src.count(old) != 1:
    sys.exit("input.cpp anchor count=%d" % src.count(old))
open(path, "w").write(src.replace(old, new, 1))
print("  ui/input.cpp: InputTrackFrameFocus -> FocusOnPress(true)")
PYEOF
fi

nMark=$(grep -cF "$MARK" "$MAT" || true)
nInp=$(grep -cF "$MARK" "$INP" || true)
nOff=$(grep -cF "FocusOnPress(false);" "$INP" || true)
if [[ "$nMark" != "1" || "$nInp" != "1" || "$nOff" != "0" ]]; then
    echo "ERROR: self-check failed (materialize=$nMark want 1, input=$nInp want 1, leftover-off=$nOff want 0)" >&2
    exit 1
fi
echo "Patch complete. Regenerate amalgam: (cd $GPUI_DIR && bun cmd/build.ts -rel hello_world)"
