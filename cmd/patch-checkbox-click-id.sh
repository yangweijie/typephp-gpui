#!/usr/bin/env bash
# cmd/patch-checkbox-click-id.sh — 让 theme checkbox/switch 的 click id 对齐 PHP 回调号（bug C 的修复）。
#
# 为什么需要：patch-shell-checkbox.sh 让 checkbox/switch 改走 ui 组件层的 theme 控件，
#   但 component::Checkbox::New(cx, rowId) 用 behavior.key（元素 id 字符串）做 PathId，
#   paint 时 clickId = 路径哈希（IdFold）。真实点击的 event->id = pressedId = hit->id
#   = 这个哈希数，而 gpuiEnqueueChange 拿 event->id 当 callbackId 入队，box->enqueue
#   查不到哈希数就静默丢弃——所以合成 checkboxRoute（走 gpui_selftest_fire 直接用
#   PHP cbid）是绿的，但 checkboxRealClick / switchRealClick / 真人鼠标全死。
#   base 兜底分支用 e->Click((int)behavior.onChange) 把 clickId 直接设成回调号，天然对齐。
#
# 修法（四刀，同一 marker 前缀；materialize 的三块按各自 hunk marker 幂等）：
#   ① materialize.cpp：themed 分支的 cb->IntoEl() / sw->IntoEl() 返回值上补 Click(cbid)，
#      与 base 分支同型；
#   ② view.cpp：ScriptView::OnChange 用 event.id（=cbid）入队；载荷用 value（base 的
#      ListenerFill 绑的激活状态 0/1 不能当回调号，但正是"本帧渲染态的翻转结果"，
#      比 lastValues 翻转新鲜——PHP 侧重置状态重渲染后首点不会翻错向）；
#   ③ window_common.cpp：ClickEvent ev 的 id 从 win->pressedId 改成 upId（当前命中
#      rect 的 clickId，已对齐 cbid；pressedId 是 mouse-down 时缓存的哈希，没跟上）。
#   ④ materialize.cpp：MaterializeNode 的收尾 pass 会 element->PathId(behavior.key)
#      把 clickFromPath 重新置真——paint 前 IdCollect 按 IdFold(key) 重写 clickId，
#      把 ① 对齐的回调号盖掉（实测真点击 event.id=IdFold("in-agree")&0x3fffffff，
#      OnChange 拿它查 Box2::callbacks 落空，事件静默丢）。带显式 Click() 的元素
#      改走 PathFocus：同样命名 + focusFromPath，唯独不碰 clickFromPath。
#
# 用法：GPUI_DIR=<gpui-kit-cpp> cmd/patch-checkbox-click-id.sh   （默认 build/gpui-kit-cpp）

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
MAT="$GPUI_DIR/src/shell/materialize.cpp"
VIEW="$GPUI_DIR/src/shell/view.cpp"
WC="$GPUI_DIR/src/gpui/window_common.cpp"
MARK="typephp-gpui: checkbox click id = callback id"

[[ -f "$MAT" && -f "$VIEW" && -f "$WC" ]] || { echo "ERROR: materialize.cpp/view.cpp/window_common.cpp 缺失" >&2; exit 1; }

# ① materialize.cpp：themed checkbox/switch 两分支，各按自己的 hunk marker 幂等
# （一个 marker 管整文件的旧写法会让后加的 hunk 永远跳过：上游只要落过 ①，
#  marker 已在，④ 就再也打不上）。
if grep -qF "：click id 对齐 PHP 回调号。themed 分支的 PathId" "$MAT"; then
    echo "materialize.cpp ①cb: already patched, skip"
else
    python3 - "$MAT" <<'PYEOF'
import sys
path = sys.argv[1]
src = open(path).read()
mark = "typephp-gpui: checkbox click id = callback id"

# ① Checkbox themed 分支
old_cb = """                if (behavior.accessibilityLabel.s)
                    cb->AccessibilityLabel(behavior.accessibilityLabel);
                return cb->IntoEl();"""
new_cb = """                if (behavior.accessibilityLabel.s)
                    cb->AccessibilityLabel(behavior.accessibilityLabel);
                // """ + mark + """：click id 对齐 PHP 回调号。themed 分支的 PathId
                // 用元素 id → paint 时 clickId = 路径哈希，真实点击的 event->id 也是
                // 这个哈希，gpuiEnqueueChange 拿它当 callbackId 查不到就静默丢。
                // 与 base 兜底分支同型：直接把回调号设成 clickId。
                El* cbRow = cb->IntoEl();
                if (behavior.onChange && behavior.onChange <= INT32_MAX) {
                    cbRow->Click((int)behavior.onChange);
                }
                return cbRow;"""

if src.count(old_cb) != 1:
    sys.exit("checkbox anchor count=%d" % src.count(old_cb))
src = src.replace(old_cb, new_cb, 1)
open(path, "w").write(src)
print("  materialize.cpp ①cb: themed checkbox -> Click(callback id)")
PYEOF
fi

if grep -qF "：switch 同型——click id 也要对齐回调号。" "$MAT"; then
    echo "materialize.cpp ①sw: already patched, skip"
else
    python3 - "$MAT" <<'PYEOF'
import sys
path = sys.argv[1]
src = open(path).read()
mark = "typephp-gpui: checkbox click id = callback id"

# ② Switch themed 分支：同型
old_sw = """                if (behavior.accessibilityLabel.s)
                    sw->AccessibilityLabel(behavior.accessibilityLabel);
                return sw->IntoEl();"""
new_sw = """                if (behavior.accessibilityLabel.s)
                    sw->AccessibilityLabel(behavior.accessibilityLabel);
                // """ + mark + """：switch 同型——click id 也要对齐回调号。
                El* swRow = sw->IntoEl();
                if (behavior.onChange && behavior.onChange <= INT32_MAX)
                    swRow->Click((int)behavior.onChange);
                return swRow;"""

if src.count(old_sw) != 1:
    sys.exit("switch anchor count=%d" % src.count(old_sw))
src = src.replace(old_sw, new_sw, 1)
open(path, "w").write(src)
print("  materialize.cpp ①sw: themed switch -> Click(callback id)")
PYEOF
fi

# ④ materialize.cpp 收尾 pass：PathId(key) 会把 clickFromPath 置回真，paint 前
#    IdCollect 按 IdFold(key) 重写 clickId，盖掉 ① 对齐的回调号。
if grep -qF "PathId 会把 clickFromPath 重新置真" "$MAT"; then
    echo "materialize.cpp ④: already patched, skip"
else
    python3 - "$MAT" <<'PYEOF'
import sys
path = sys.argv[1]
src = open(path).read()
mark = "typephp-gpui: checkbox click id = callback id"

old_post = """    ApplyStateStyles(cx, specs, node, element, error);
    if (behavior.key) element->PathId(behavior.key);"""
new_post = """    ApplyStateStyles(cx, specs, node, element, error);
    // """ + mark + """：PathId 会把 clickFromPath 重新置真，paint 前 IdCollect 按
    // IdFold(key) 重写 clickId，把物化分支里 Click(cbid) 对齐的回调号盖掉——真点击
    // 的 event.id 又变回路径哈希（实测 event.id=IdFold("in-agree")&0x3fffffff），
    // OnChange 拿它查 Box2::callbacks 落空，事件静默丢。带显式 Click() 的元素
    // （clickId 非零且不从路径派生）改走 PathFocus：同样命名 + focusFromPath，
    // 唯独不碰 clickFromPath。
    if (behavior.key) {
        if (element->clickId != 0 && !element->clickFromPath)
            element->PathFocus(behavior.key);
        else
            element->PathId(behavior.key);
    }"""

if src.count(old_post) != 1:
    sys.exit("materialize post-pass anchor count=%d" % src.count(old_post))
src = src.replace(old_post, new_post, 1)
open(path, "w").write(src)
print("  materialize.cpp ④: explicit Click survives the PathId naming pass")
PYEOF
fi

# ② view.cpp：ScriptView::OnChange 用 event.id 当 callbackId（声明与函数体各按
# 自己的 hunk marker 幂等，理由同 materialize 三块）。
if grep -qF "gpuiShimToggleValue" "$VIEW"; then
    echo "view.cpp ②a: already patched, skip"
else
    python3 - "$VIEW" <<'PYEOF'
import sys
path = sys.argv[1]
src = open(path).read()
old = """__attribute__((weak)) void gpuiEnqueueChange(ScriptView*, Ctx*, uint64_t, bool) {}"""
new = """__attribute__((weak)) void gpuiEnqueueChange(ScriptView*, Ctx*, uint64_t, bool) {}
__attribute__((weak)) bool gpuiShimToggleValue(ScriptView*, uint64_t) { return true; }"""
if src.count(old) != 1:
    sys.exit("view.cpp enqueue anchor count=%d" % src.count(old))
src = src.replace(old, new, 1)
open(path, "w").write(src)
print("  view.cpp ②a: weak gpuiShimToggleValue 声明")
PYEOF
fi

if grep -qF "bridge 会把渲染态同步进" "$VIEW"; then
    echo "view.cpp ②b: already patched, skip"
else
    python3 - "$VIEW" "$MARK" <<'PYEOF'
import sys
path, mark = sys.argv[1], sys.argv[2]
src = open(path).read()
# ②b 的三个历史形态都能转入最终形态：v0（git-HEAD 原始 OnChange——重新 vendor 上游
# 时的起点）、v1（toggle 载荷，注释还是"不用 value"的旧说法）与 v2（载荷直接用
# 本帧 value——draw 惰性下第二击拿旧帧 arg，已废）。文件应恰好处于其中一种；
# 都不在就报错，不静默。
old_forms = [
"""    if (!self || !self->runtime || !event || event->id <= 0) return;
    gpuiEnqueueChange(self, cx, (uint64_t)(uint32_t)event->id, value != 0);""",
"""    // typephp-gpui: checkbox click id = callback id：themed checkbox 的外层 click id
    // 已对齐为 PHP cbid，event.id 就是 callbackId——用它入队。不用 value：base 层的
    // ListenerFill 把 arg 填成了激活状态值（0/1），不是回调号，会落进 0 号丢事件。
    (void)value;
    if (!self || !self->runtime || !event || event->id <= 0) return;
    bool checked = gpuiShimToggleValue(self, (uint64_t)event->id);
    gpuiEnqueueChange(self, cx, (uint64_t)event->id, checked);""",
"""    // typephp-gpui: checkbox click id = callback id：themed checkbox 的外层 click id 已对齐为 PHP cbid，
    // event.id 就是 callbackId——用它入队。value 不能当回调号（base 的 ListenerFill
    // 绑的是激活状态 0/1，落进 0 号丢事件），但载荷恰恰该用它：那是本帧渲染态的
    // 翻转结果（checkbox=CheckboxActivated、switch=!checked、accordion=!open），
    // 比 gpuiShimToggleValue 的 lastValues 翻转新鲜——PHP 侧改过状态重渲染后
    // lastValues 还是旧值，首点会翻错向（实测 switchRealClick light→light→dark，
    // 滞后一拍）。
    if (!self || !self->runtime || !event || event->id <= 0) return;
    gpuiEnqueueChange(self, cx, (uint64_t)event->id, value != 0);""",
]
new_onchange = """    // """ + mark + """：themed checkbox 的外层 click id 已对齐为 PHP cbid，
    // event.id 就是 callbackId——用它入队。value 不能当回调号（base 的 ListenerFill
    // 绑的是激活状态 0/1，落进 0 号丢事件）；载荷也不能直接用它：本环境的原生
    // draw 惰性，事件驱动的分派可能跑在下一帧重画之前，合成双击的第二击会拿
    // 旧帧的 arg 翻错向（实测 checked true→false→false）。载荷从 shim 侧
    // gpuiShimToggleValue 翻转 lastValues——render 推送时 bridge 会把渲染态同步进
    // lastValues（见 gpui_bridge.cc emitTree 的 renderedChecked），PHP 侧改状态
    // 重渲染后它也是新的，翻转基准不再陈旧。
    (void)value;
    if (!self || !self->runtime || !event || event->id <= 0) return;
    bool checked = gpuiShimToggleValue(self, (uint64_t)event->id);
    gpuiEnqueueChange(self, cx, (uint64_t)event->id, checked);"""
hits = [f for f in old_forms if src.count(f) == 1]
if len(hits) != 1:
    sys.exit("view.cpp OnChange anchor matched %d historical forms" % len(hits))
src = src.replace(hits[0], new_onchange, 1)
open(path, "w").write(src)
print("  view.cpp ②b: OnChange 载荷 = gpuiShimToggleValue 翻转（基准由 render push 同步）")
PYEOF
fi

# ③ window_common.cpp：ClickEvent ev 的 id 用 upId
if grep -qF "$MARK" "$WC"; then
    echo "window_common.cpp: already patched, skip"
else
    python3 - "$WC" "$MARK" <<'PYEOF'
import sys
path, mark = sys.argv[1], sys.argv[2]
src = open(path).read()
old = """        ClickEvent ev = {in.x, in.y, in.button, win->pressedId};"""
new = """        // """ + mark + """：click 事件的 id 用当前 hit id（clickId 已对齐 cbid），
        // 不是 pressedId（mouse-down 时缓存的路径哈希，没跟上 clickId 对齐）。
        ClickEvent ev = {in.x, in.y, in.button, upId};"""
if src.count(old) != 1:
    sys.exit("window_common ClickEvent anchor count=%d" % src.count(old))
open(path, "w").write(src.replace(old, new, 1))
print("  window_common.cpp: ClickEvent ev id -> upId")
PYEOF
fi

nMat=$(grep -cF "$MARK" "$MAT" || true)
nView=$(grep -cF "$MARK" "$VIEW" || true)
nWC=$(grep -cF "$MARK" "$WC" || true)
if [[ "$nMat" != "3" || "$nView" != "1" || "$nWC" != "1" ]]; then
    echo "ERROR: self-check failed (mat=$nMat want 3, view=$nView want 1, wc=$nWC want 1)" >&2
    exit 1
fi
echo "Patch complete. Regenerate amalgam: (cd $GPUI_DIR && bun cmd/build.ts -rel hello_world)"
