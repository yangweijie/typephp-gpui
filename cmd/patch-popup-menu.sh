#!/usr/bin/env bash
# cmd/patch-popup-menu.sh — 新建 component_shell/overlays/popup_menu.cpp：
# PopupMenu / ContextMenu 两个 catalog 导出（widget 层已在 vendored 上游——
# ui/menu.h 的 component::PopupMenu / ContextMenu / ContextMenuState +
# base/popup_menu.h 的 PopupMenuState 行模型；descriptor 两仓同缺，本脚本补）。
#
# 形状选择（与 DropdownMenu 的 item 方法相对）：**rows 走普通 children**——每个子
# 元素的 text 是行标签（ui El 的 text 字段，gpui.h:2541）。行**不带点击处理器**：
# 处理器要经子元素的 onClick（Func0）反解回调 id，而 ui 层没有干净出口（Func0 的
# userData 打包格式不暴露）——如实记为限制，处理器通道留回调注册表那条路（后续）。
# ContextMenu 的第一个子元素是右键目标（ContextMenu::Child），其余是菜单行；
# 右键锚定与 overlay 挂载走 ui/menu.cpp 的 ContextMenuState OnMouseDown（Bubble
# 相位、stopPropagation 防祖先菜单连锁——上游已实现）。
#
# 幂等：descriptor 文件 marker 在 + 注册链 marker 在 + families.h 声明在 = 已打；
# 缺哪个补哪个；自检计数。用法：GPUI_DIR=<gpui-kit-cpp> cmd/patch-popup-menu.sh
# （默认 build/gpui-kit-cpp）

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
POP="$GPUI_DIR/src/component_shell/overlays/popup_menu.cpp"
MOD="$GPUI_DIR/src/component_shell/overlays/mod.cpp"
FAM="$GPUI_DIR/src/component_shell/families.h"
MARK="RegisterOverlaysPopupMenu"

[[ -f "$MOD" ]] || { echo "ERROR: overlays/mod.cpp not found" >&2; exit 1; }
[[ -f "$FAM" ]] || { echo "ERROR: families.h not found" >&2; exit 1; }

if grep -qF "$MARK" "$MOD"; then
    echo "overlays/mod.cpp: already patched, skip"
else
    echo "patching overlays/mod.cpp with the popup-menu registration chain..."
    cp "$MOD" "$MOD.popupmenu.bak"
    python3 - "$MOD" <<'PYEOF'
import sys
path = sys.argv[1]
src = open(path).read()
old = '''bool RegisterOverlays(shell::ComponentRegistry* registry,
                      shell::RegistryError* error) {
    return RegisterOverlaysHoverCard(registry, error) &&
           RegisterOverlaysPopover(registry, error) &&
           RegisterOverlaysDropdownMenu(registry, error);
}'''
if src.count(old) != 1:
    sys.exit("register chain anchor count=%d" % src.count(old))
new = '''bool RegisterOverlays(shell::ComponentRegistry* registry,
                      shell::RegistryError* error) {
    return RegisterOverlaysHoverCard(registry, error) &&
           RegisterOverlaysPopover(registry, error) &&
           RegisterOverlaysDropdownMenu(registry, error) &&
           RegisterOverlaysPopupMenu(registry, error);
}'''
src = src.replace(old, new, 1)
open(path, "w").write(src)
print("  overlays/mod.cpp: RegisterOverlaysPopupMenu added to the chain")
PYEOF
fi

# ── families.h：声明 RegisterOverlaysPopupMenu（在 DropdownMenu 声明之后）──
if grep -qF "RegisterOverlaysPopupMenu" "$FAM"; then
    echo "families.h: already patched, skip"
else
    echo "patching families.h with the RegisterOverlaysPopupMenu declaration..."
    cp "$FAM" "$FAM.popupmenu.bak"
    python3 - "$FAM" <<'PYEOF2'
import sys
path = sys.argv[1]
src = open(path).read()
# 插在"// layout/mod.rs"注释之前（DropdownMenu 声明是跨行配对的——插在
# 第一行后面会把续行断开）。锚点唯一性：families.h 里 layout/mod.rs 注释只此一处。
anchor = "// layout/mod.rs"
if src.count(anchor) != 1:
    sys.exit("families.h anchor count=%d" % src.count(anchor))
src = src.replace(
    anchor,
    "bool RegisterOverlaysPopupMenu(shell::ComponentRegistry*,"
    "\n                               shell::RegistryError*);\n\n"
    + anchor, 1)
open(path, "w").write(src)
print("  families.h: RegisterOverlaysPopupMenu declared")
PYEOF2
fi

if grep -qF "ComponentDescriptor kPopupMenu" "$POP" 2>/dev/null; then
    echo "popup_menu.cpp: already patched, skip"
else
    echo "creating overlays/popup_menu.cpp..."
    cp "$POP" "$POP.popupmenu.bak" 2>/dev/null || true
    cat > "$POP" <<'POPMENU_CPP'
// crates/component-shell/src/shell/overlays/popup_menu.cpp
// PopupMenu and ContextMenu: the script's own popup menus. The widget layer
// (ui/menu.h component::PopupMenu/ContextMenu/ContextMenuState + base/popup_menu.h
// PopupMenuState row model) is already in the vendored tree; this file is the
// catalog surface: two descriptors whose rows are ordinary children.

#include "component_shell/families.h"
#include "component_shell/support.h"
#include "shell/view.h"
#include "ui/menu.h"

namespace gpui::component_shell::overlays::popup_menu {

struct PopupMenuPayload {
    Str id;
};

static El* MaterializePopupMenu(MaterializeRequest* request) {
    const PopupMenuPayload* payload = request->PayloadAs<PopupMenuPayload>();
    if (!payload) {
        return request
            ->Fail(StrL("PopupMenu received an incompatible payload"));
    }
    Ctx* cx = request->cx;
    // menu(|m| items.fold(..)): the rows in script order — each child element's
    // text is the row label and its onClick is the row's handler. The menu is
    // named inside the popup so two popups keep two menus (IdScope).
    component::PopupMenu* menu = nullptr;
    {
        IdScope scope(cx, payload->id);
        menu = component::PopupMenu::New(cx, StrL("menu"));
    }
    El** children = nullptr;
    int count = 0;
    if (!request->TakeChildren(&children, &count)) return nullptr;
    // Rows are label-only: the child's text is the row label. Wiring the
    // row's click handler from the child's onClick would need the callback
    // id out of the element's Func0 packing, which the ui layer does not
    // expose cleanly (honest limitation — the handler channel needs the
    // callback registry path, a follow-up).
    for (int i = 0; i < count; i++) {
        if (!children[i]) continue;
        Str label = children[i]->text;
        menu->Menu(label);
    }
    return menu->IntoEl();
}

struct ContextMenuPayload {
    Str id;
};

static El* MaterializeContextMenu(MaterializeRequest* request) {
    const ContextMenuPayload* payload =
        request->PayloadAs<ContextMenuPayload>();
    if (!payload) {
        return request
            ->Fail(StrL("ContextMenu received an incompatible payload"));
    }
    Ctx* cx = request->cx;
    // context_menu(child, |menu| rows): the first child is the target element
    // that carries the right-press listener (ContextMenu::Child + IntoEl's
    // PathClick/OnMouseDown wiring), the rest are the menu rows.
    El** children = nullptr;
    int count = 0;
    if (!request->TakeChildren(&children, &count)) return nullptr;
    if (count == 0 || !children[0])
        return request
            ->Fail(StrL("ContextMenu requires a target child"));
    component::ContextMenu* menu =
        component::ContextMenu::New(cx, payload->id);
    menu->Child(children[0]);
    component::PopupMenu* pop = nullptr;
    {
        IdScope scope(cx, payload->id);
        pop = component::PopupMenu::New(cx, StrL("menu"));
    }
    for (int i = 1; i < count; i++) {
        if (!children[i]) continue;
        Str label = children[i]->text;
        pop->Menu(label);
    }
    menu->Menu(pop);
    return menu->IntoEl();
}

static bool ConstructPopupMenu(PayloadBuild* build,
                               const ComponentArgument* args, int) {
    if (len(StrTrim(args[0].string)) == 0)
        return build->Fail(StrL("PopupMenu id must not be empty"));
    build->New<PopupMenuPayload>()->id = args[0].string;
    return true;
}

static bool ConstructContextMenu(PayloadBuild* build,
                                 const ComponentArgument* args, int) {
    if (len(StrTrim(args[0].string)) == 0)
        return build->Fail(StrL("ContextMenu id must not be empty"));
    build->New<ContextMenuPayload>()->id = args[0].string;
    return true;
}

static constexpr ArgumentDescriptor kIdArgs[] = {{"id", SchemaString()}};

static constexpr ConstructorDescriptor kPopupMenuConstructors[] = {
    {"PopupMenu", kIdArgs, &ConstructPopupMenu}};
static constexpr ComponentDescriptor kPopupMenu = {
    "PopupMenu", kPopupMenuConstructors, {},
    "A popup menu whose rows are the script's children.",
    &MaterializePopupMenu};

static constexpr ConstructorDescriptor kContextMenuConstructors[] = {
    {"ContextMenu", kIdArgs, &ConstructContextMenu}};
static constexpr ComponentDescriptor kContextMenu = {
    "ContextMenu", kContextMenuConstructors, {},
    "Wraps a target element; a right press opens the menu at the pointer.",
    &MaterializeContextMenu};

} // namespace gpui::component_shell::overlays::popup_menu

namespace gpui::component_shell {

bool RegisterOverlaysPopupMenu(shell::ComponentRegistry* registry,
                               shell::RegistryError* error) {
    return registry->Register(&overlays::popup_menu::kPopupMenu, error) &&
           registry->Register(&overlays::popup_menu::kContextMenu, error);
}

} // namespace gpui::component_shell
POPMENU_CPP
    echo "  overlays/popup_menu.cpp: PopupMenu/ContextMenu descriptors created"
fi

nMod=$(grep -cF "$MARK" "$MOD" || true)
nFile=$(grep -cF "ComponentDescriptor kPopupMenu" "$POP" || true)
nFam=$(grep -cF "RegisterOverlaysPopupMenu" "$FAM" || true)
if [[ "$nMod" != "1" || "$nFile" != "1" || "$nFam" != "1" ]]; then
    echo "ERROR: self-check failed (mod=$nMod want 1, file=$nFile want 1, fam=$nFam want 1)" >&2
    exit 1
fi
echo "Patch complete."
echo "Regenerate amalgam: (cd $GPUI_DIR && bun cmd/build.ts -rel hello_world)"
