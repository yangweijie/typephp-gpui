#!/usr/bin/env bash
# cmd/patch-shell-checkbox.sh — shell 的 checkbox/switch 改走 ui 组件层（主题勾选框/开关）
#
# 为什么需要：
#   shell/materialize.cpp 的 ComponentKind::Checkbox / Switch 分支当前走 base::Checkbox::New /
#   base::Switch::New——base 层是**无主题的裸 Div+PathClick**（见 base/checkbox.cpp:46-70 与
#   base/switch.cpp:46-72），只挂回调、不画勾选框/开关轨道。于是：
#     · checkbox：用户看到一颗裸白框（无边框/无勾）、文字标签「同意」在旁边，点了变色全靠 CSS
#       （没有勾选框图形）；
#     · switch：用户看到一颗裸白框 + 标签「深色开关」，点了无轨道/滑块动画。
#   base 层的 PathId 用的是 **component.text**（label「同意」）而非元素 id（"in-agree"），
#   pathId/focusId 因此派生自 label 字符串，命中几何与语义都错位。
#
# 修法：改走 gpui::component::Checkbox / component::Switch（ui/checkbox.h:10-57 与
#   ui/switch.h:10-56 已核），它们是主题化的（有 CheckboxIndicator/Track/Thumb 三层绘制），
#   且构造时用 behavior.key（元素 id）作为 PathId，命中链正确。
#
# 2026-10-25 实证（php bin/gpui build input 后跑 selftest input）：
#   修前 hitProbe 全表 3 条 0×0 click=1 死面（#9 x=16,y=378 path==id=checkbox行、
#   #10 x=28,y=378 开关行、#7 x=544,y=319 slider 行）；
#   修后 #9/#10 归零（组件层无 PathId 副产物）、base 层死面消失，仅留 #7（另立坑记录）。
#
# 2026-10-25 修复落地错误（第二轮）：
#   首版补丁在两个分支里写了 `!node->children.len && component.text.s`，但 Construct()
#   的签名是 (cx, runtime, component, behavior)——**没有 node 参数**。编译报
#   "node was not declared"，amalgam 编不过。修法：去掉 `!node->children.len &&`
#   前缀，只留 `component.text.s`。理由：PHP 不给 checkbox/switch 发 children
#   （El::checkbox/El::switch 不携带子节点），行为等价。若未来 PHP 要发 children，
#   通用挂载段（materialize.cpp:2800 的 `!childrenConsumed` 分支）会在 Construct
#   返回后把 children 挂到组件层 IntoEl() 产出的 FlexRow 上——构造期不需要预判。
#
# 用法：GPUI_DIR=<gpui-kit-cpp> cmd/patch-shell-checkbox.sh   （默认 build/gpui-kit-cpp）

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
MAT="$GPUI_DIR/src/shell/materialize.cpp"
MARK="typephp-gpui: checkbox/switch use themed component"

[[ -f "$MAT" ]] || { echo "ERROR: materialize.cpp 缺失" >&2; exit 1; }

if grep -qF "$MARK" "$MAT"; then
    # 已打过补丁——检测是否有旧版的 node->children.len 引用并替换掉。
    # 2026-10-25 第二轮：首版补丁写了 `!node->children.len && component.text.s`，
    # 但 Construct() 没有 node 参数 → 编译失败。这里把旧前缀替换为新版。
    if grep -qF "node->children.len && component.text.s" "$MAT"; then
        python3 - "$MAT" "$MARK" <<'PYEOF'
import sys
path, mark = sys.argv[1], sys.argv[2]
src = open(path).read()

# 旧版（首版补丁，编译不过）：`!node->children.len && component.text.s`
# 新版（第二轮）：去掉 node->children.len，只留 component.text.s
#   理由：Construct() 签名不含 node；PHP 不发 children；通用挂载段
#   (materialize.cpp:2800) 在 Construct 返回后处理 children。
old_cb_guard = "if (!behavior.indeterminate && !node->children.len && component.text.s) {"
new_cb_guard = "if (!behavior.indeterminate && component.text.s) {"
n_cb = src.count(old_cb_guard)
if n_cb != 1:
    sys.exit("checkbox guard replace count=%d (want 1)" % n_cb)
src = src.replace(old_cb_guard, new_cb_guard, 1)

old_sw_guard = "if (!node->children.len && component.text.s) {"
new_sw_guard = "if (component.text.s) {"
n_sw = src.count(old_sw_guard)
if n_sw != 1:
    sys.exit("switch guard replace count=%d (want 1)" % n_sw)
src = src.replace(old_sw_guard, new_sw_guard, 1)

open(path, "w").write(src)
print("  materialize.cpp: replaced old node->children.len guards → component.text.s only")
PYEOF
    else
        echo "materialize.cpp: already patched (v2), skip"
    fi
else
    python3 - "$MAT" "$MARK" <<'PYEOF'
import sys
path, mark = sys.argv[1], sys.argv[2]
src = open(path).read()

# 第 1 刀：头部补 include（base/checkbox.h 已经有了，补 ui 层）
old_inc = '#include "base/checkbox.h"\n'
new_inc = '''#include "base/checkbox.h"
#include "ui/checkbox.h"
'''
if src.count(old_inc) != 1:
    sys.exit("include anchor count=%d" % src.count(old_inc))
src = src.replace(old_inc, new_inc, 1)

# 第 2 刀：Checkbox 分支改走 component::Checkbox
old_cb = '''        case shell::ComponentKind::Checkbox: {
            CheckboxState state =
                behavior.indeterminate
                    ? CheckboxState::Indeterminate
                    : (behavior.checked ? CheckboxState::Checked
                                        : CheckboxState::Unchecked);
            El* e = Checkbox::New(
                cx, id, state, behavior.disabled,
                behavior.onChange ? ChangeListener(cx) : Listener{}, nullptr,
                nullptr, behavior.accessibilityLabel, behavior.tabIndex,
                behavior.tabStop);
            if (behavior.onChange && behavior.onChange <= INT32_MAX)
                e->Click((int)behavior.onChange);
            return e;
        }'''

new_cb = (
    "        case shell::ComponentKind::Checkbox: {\n"
    "            // " + mark + ": 改走 ui 组件层的 theme checkbox（有勾选框图形）。\n"
    "            // 行 id 必须用 behavior.key（元素 id \"in-agree\"）而非 component.text（label \"同意\"），\n"
    "            // 否则 PathId 派生自 label，命中链与语义都错位。indeterminate 保留 base 兜底\n"
    "            // （组件层无该参数，PHP 现用面也不发它）。\n"
    "            if (!behavior.indeterminate && component.text.s) {\n"
    "                Str rowId = behavior.key.s ? behavior.key : component.text;\n"
    "                gpui::component::Checkbox* cb =\n"
    "                    gpui::component::Checkbox::New(cx, rowId)\n"
    "                        ->Label(component.text)\n"
    "                        ->Checked(behavior.checked)\n"
    "                        ->Disabled(behavior.disabled);\n"
    "                if (behavior.onChange)\n"
    "                    cb->OnChange(ChangeListener(cx));\n"
    "                if (behavior.accessibilityLabel.s)\n"
    "                    cb->AccessibilityLabel(behavior.accessibilityLabel);\n"
    "                return cb->IntoEl();\n"
    "            }\n"
    "            CheckboxState state =\n"
    "                behavior.indeterminate\n"
    "                    ? CheckboxState::Indeterminate\n"
    "                    : (behavior.checked ? CheckboxState::Checked\n"
    "                                        : CheckboxState::Unchecked);\n"
    "            El* e = Checkbox::New(\n"
    "                cx, id, state, behavior.disabled,\n"
    "                behavior.onChange ? ChangeListener(cx) : Listener{}, nullptr,\n"
    "                nullptr, behavior.accessibilityLabel, behavior.tabIndex,\n"
    "                behavior.tabStop);\n"
    "            if (behavior.onChange && behavior.onChange <= INT32_MAX)\n"
    "                e->Click((int)behavior.onChange);\n"
    "            return e;\n"
    "        }"
)

if src.count(old_cb) != 1:
    sys.exit("checkbox branch anchor count=%d" % src.count(old_cb))
src = src.replace(old_cb, new_cb, 1)

# 第 3 刀：Switch 分支同型
old_sw = '''        case shell::ComponentKind::Switch: {
            El* e =
                Switch::New(cx, id, behavior.checked, behavior.disabled,
                            behavior.onChange ? ChangeListener(cx) : Listener{},
                            nullptr, nullptr, behavior.accessibilityLabel,
                            behavior.tabIndex, behavior.tabStop);
            if (behavior.onChange && behavior.onChange <= INT32_MAX)
                e->Click((int)behavior.onChange);
            return e;
        }'''

new_sw = (
    "        case shell::ComponentKind::Switch: {\n"
    "            // " + mark + ": 同 checkbox——改走 ui 组件层的 theme switch（有轨道/滑块）。\n"
    "            // 行 id 用 behavior.key（\"in-dark\"）而非 component.text（\"深色开关\"）。\n"
    "            if (component.text.s) {\n"
    "                Str rowId = behavior.key.s ? behavior.key : component.text;\n"
    "                gpui::component::Switch* sw =\n"
    "                    gpui::component::Switch::New(cx, rowId)\n"
    "                        ->Label(component.text)\n"
    "                        ->Checked(behavior.checked)\n"
    "                        ->Disabled(behavior.disabled);\n"
    "                if (behavior.onChange)\n"
    "                    sw->OnChange(ChangeListener(cx));\n"
    "                if (behavior.accessibilityLabel.s)\n"
    "                    sw->AccessibilityLabel(behavior.accessibilityLabel);\n"
    "                return sw->IntoEl();\n"
    "            }\n"
    "            El* e =\n"
    "                Switch::New(cx, id, behavior.checked, behavior.disabled,\n"
    "                            behavior.onChange ? ChangeListener(cx) : Listener{},\n"
    "                            nullptr, nullptr, behavior.accessibilityLabel,\n"
    "                            behavior.tabIndex, behavior.tabStop);\n"
    "            if (behavior.onChange && behavior.onChange <= INT32_MAX)\n"
    "                e->Click((int)behavior.onChange);\n"
    "            return e;\n"
    "        }"
)

if src.count(old_sw) != 1:
    sys.exit("switch branch anchor count=%d" % src.count(old_sw))
src = src.replace(old_sw, new_sw, 1)

open(path, "w").write(src)
print("  materialize.cpp: checkbox/switch -> component layer（theme 控件 + 元素 id）")
PYEOF
fi

nMark=$(grep -cF "$MARK" "$MAT" || true)
if [[ "$nMark" != "2" ]]; then
    echo "ERROR: self-check failed (marker=$nMark want 2)" >&2
    exit 1
fi
# 追加自检：确认没有残留的 node->children.len 引用（首版补丁的编译陷阱）
nNodeRef=$(grep -cF "node->children.len && component.text.s" "$MAT" || true)
if [[ "$nNodeRef" != "0" ]]; then
    echo "ERROR: node->children.len guard still present ($nNodeRef)" >&2
    exit 1
fi
echo "Patch complete. Regenerate amalgam: (cd $GPUI_DIR && bun cmd/build.ts -rel hello_world)"
