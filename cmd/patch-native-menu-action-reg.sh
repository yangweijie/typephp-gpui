#!/usr/bin/env bash
# cmd/patch-native-menu-action-reg.sh — 触发器行的 ShellAction 必须进 app 级动作表。
#
# 为什么要这一支（2026-11-04，#115：真人点菜单行 0 送达）：
# in-window NativeMenuTrigger 的行选中走
#     ShowNative → onSelect → ScriptView::OnDispatchAction
#     → WindowDispatchAction(win, action)
# 而 WindowDispatchActionFrom 只查两处：① win->dispatch[]（元素级 OnAction 监听）——
# 触发器的行是 carried Entry（MaterializeItem 只带数据不挂监听），② gAppActions
# （app 级动作表）——往这张表登记的唯一入口 registerMenuAction 此前只被
# app 菜单条路径（gpui_shim_set_app_menus）调用，触发器路径从不登记
# ⇒ 两处都不中，`return false`，行选中静默丢（actionDeliveryGate 的
# dispatchAction=false 读数同位）。
#
# 修法：MaterializeTrigger 物化时把每行的 action 登记进 app 表——与 app 菜单条
# 同一条 registerMenuAction（去重 + 预算护栏都在 shim 侧）。shell 不知道登记表，
# 走既有的 weak-seam 惯例：shell 侧弱定义，shim 强符号覆盖。
#   * shell 侧弱定义放 shell/runtime.cpp（其余接缝的同居处，gpui 命名空间）；
#   * native_menu.cpp 里带声明 + 调用点（entries 逐行）；
#   * shim 强符号在 gpui_shim.cpp（g_windowBoxes 反查 box 只为 lastError 有处落，
#     真正的登记 AppOnAction 不依赖 box）。
# 预算语义沿用 registerMenuAction：去重后 >28 条时置 lastError 并拒登——那一行点了
# 依旧没回音，但原因可观测（与 app 菜单条路径同一护栏）。
#
# 真人那最后一跳（NSMenu 模态会话里点行）仍归真人 HID；本补丁修的是其后同一条
# 派发链：dispatchAction('kit-nm-copy') 自此应返回 true 且事件入队。
#
# 补丁后须重跑 amalgam：php bin/gpui build kit（不带 --no-native）。
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"

# ── 1) shell/runtime.cpp：弱定义（gpui 命名空间，与其余接缝同居） ──────────────
SRC1="$GPUI_DIR/src/shell/runtime.cpp"
MARK1="typephp-gpui: menu-action registration seam"
if grep -qF "$MARK1" "$SRC1"; then
    echo "runtime.cpp: already patched"
else
    echo "runtime.cpp: 加 gpuiShellRegisterMenuAction 弱定义"
    python3 - "$SRC1" "$MARK1" <<'PYEOF'
import sys
path, mark = sys.argv[1], sys.argv[2]
s = open(path).read()
anchor = "void ShellRuntime::DispatchNumbers(shell::CallbackId callback,"
assert s.count(anchor) == 1, s.count(anchor)
block = """// typephp-gpui: menu-action registration seam (task #115) — the shell's
// NativeMenuTrigger rows carry shell actions that must land in the host's
// app-level action table (the same table the app menu bar registers into);
// without it neither the element OnAction walk nor the app table knows the
// action and a row selection is dropped silently. Weak definition overridden
// by the shim's strong symbol, which owns the registration table and its
// budget. Same shape as gpuiEnqueueShimString.
__attribute__((weak)) void gpuiShellRegisterMenuAction(const char* action,
                                                       void* win) {
    (void)action; (void)win;
}

""" + anchor
s = s.replace(anchor, block)
open(path, 'w').write(s)
print("weak seam inserted")
PYEOF
fi

# ── 2) native_menu.cpp：声明 + MaterializeTrigger 的登记调用 ──────────────────
SRC2="$GPUI_DIR/src/component_shell/command/native_menu.cpp"
MARK2="typephp-gpui: register trigger rows into the app action table"
if grep -qF "$MARK2" "$SRC2"; then
    echo "native_menu.cpp: already patched"
else
    echo "native_menu.cpp: 加声明与逐行登记"
    python3 - "$SRC2" "$MARK2" <<'PYEOF'
import sys
path, mark = sys.argv[1], sys.argv[2]
s = open(path).read()

# 2a) 声明：放 namespace gpui（调用点在嵌套命名空间里，外层声明可见）。
decl_anchor = "namespace gpui::component_shell::command::native_menu {"
assert s.count(decl_anchor) == 1, s.count(decl_anchor)
# 声明必须在这个文件的 namespace 打开**之前**（gpui 真外层）：嵌进去会被
# gpui::component_shell::command::native_menu::gpui 遮蔽，调用点解析不到。
decl = """// typephp-gpui: host seam (task #115) — weak definition in shell/runtime.cpp,
// overridden by the shim. Registers one shell action into the app-level table.
// Declared at gpui scope, BEFORE this file's namespace opens: a declaration
// nested inside gpui::component_shell::command::native_menu would shadow the
// outer gpui namespace and the unqualified call would not resolve to it.
namespace gpui {
void gpuiShellRegisterMenuAction(const char* action, void* win);
} // namespace gpui

""" + decl_anchor
s = s.replace(decl_anchor, decl, 1)

# 2b) 调用点：entries 建好之后、button 组装之前。
call_anchor = """    click->entries = entries;
    click->count = count;
    click->key = StrDup(cx->a, fmt("native-menu:%s", trigger->id));"""
assert s.count(call_anchor) == 1, s.count(call_anchor)
call = """    click->entries = entries;
    click->count = count;
    // """ + mark + """ (task #115): the row dispatch (OnDispatchAction →
    // WindowDispatchAction) walks element OnAction listeners first, then the
    // app-level action table. The trigger's rows are carried data, not live
    // elements, so without this registration neither knows the action and the
    // selection is dropped silently. Same table the app menu bar registers
    // into (gpui_shim_set_app_menus → registerMenuAction).
    for (int i = 0; i < count; i++) {
        if (entries[i].kind == Entry::Item && entries[i].item.action.s) {
            gpuiShellRegisterMenuAction(entries[i].item.action.s,
                                        (void*)cx->win);
        }
    }
    click->key = StrDup(cx->a, fmt("native-menu:%s", trigger->id));"""
s = s.replace(call_anchor, call)
open(path, 'w').write(s)
print("decl + call inserted")
PYEOF
fi

# ── 3) shim 强符号（覆盖弱定义；g_windowBoxes/registerMenuAction 同文件可见） ──
SRC3="$ROOT/cpp-src/gpui_shim.cpp"
MARK3="typephp-gpui: strong override — trigger rows register into the app action table"
if grep -qF "$MARK3" "$SRC3"; then
    echo "gpui_shim.cpp: already patched"
else
    echo "gpui_shim.cpp: 加强符号覆盖"
    python3 - "$SRC3" "$MARK3" <<'PYEOF'
import sys
path, mark = sys.argv[1], sys.argv[2]
s = open(path).read()
anchor = "int gpui_shim_menu_row_action(int row, char* out, int outlen) {"
assert s.count(anchor) == 1, s.count(anchor)
block = """// """ + mark + """ (task #115). Overrides the weak seam in shell/runtime.cpp.
// Same registration path as the app menu bar (registerMenuAction: dedup +
// budget, lastError lands on the box when the table is full). The box lookup
// is only for that error message — AppOnAction itself does not need it.
// MUST be C++-mangled: this file's extern "C" block would give the namespace
// definition C linkage and the weak symbol (gpui::gpuiShellRegisterMenuAction)
// never sees the override — so close and reopen extern "C" around it
// (2026-11-04 首跑实测：_gpuiShellRegisterMenuAction vs
// __ZN4gpui27gpuiShellRegisterMenuActionEPKcPv，弱 no-op 独活)。
} // extern "C"
namespace gpui {
void gpuiShellRegisterMenuAction(const char* action, void* win) {
    if (!action || !*action) return;
    auto it = g_windowBoxes.find((Window*)win);
    registerMenuAction(it != g_windowBoxes.end() ? it->second : nullptr,
                       gpui::Str(action));
}
} // namespace gpui
extern "C" {

""" + anchor
s = s.replace(anchor, block)
open(path, 'w').write(s)
print("strong override inserted")
PYEOF
fi

echo "patch-native-menu-action-reg.sh: done"
