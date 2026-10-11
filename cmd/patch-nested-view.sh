#!/usr/bin/env bash
# cmd/patch-nested-view.sh — 让非 QuickJS 宿主也能把 entity 当元素挂进树。
#
# 背景：`ComponentKind::ChildView`（materialize.cpp:2045）是 gpui 里唯一"渲染一个任意
# entity"的元素口子：
#     EntityId child = runtime->NestedView(component.handle, cx->app);
#     El* rendered = EntityRender(cx->app, cx->win, cx->a, child);
# 但 nestedViews 的**注册侧**只有 QuickJS 的 `cx.new`（NativeViewNew）走，它登记的必然
# 是一个自建 ScriptView + 一份 Policy。我们（PHP 宿主）手里已经有 entity（PhpView 用的
# EntityNewRaw 路径，以及 WebView 的包装 view），只是没有 token。
#
# 本补丁只加一个薄公开入口 ShellRuntime::RegisterHostView(EntityId, App*)：把已存在的
# entity 追加进 impl->nestedViews 并返回 token。owner/policy/application 留空——
# DropNestedViewAt 因此只做 EntityDrop(view)（policy.cpp:56 的 PolicyRelease 对 null 直接
# 返回），ReleaseOwnedEntities/ReleaseApplicationState 按 owner/application 匹配也永不
# 波及它，生命周期完全由宿主掌握。零回归：不改任何既有路径。
#
# 有了它，WebView（gpui::WebViewNew + WebViewEl 包一层 view）和 Inspector 面板
# （component::Inspector::New(cx)->IntoEl()）都能进 PHP 的元素树。
#
# 上游更新后重跑即可；补丁后需重跑 bun cmd/build.ts 生成 amalgam，再 build-shim.sh。
#
# 用法：GPUI_DIR=<gpui-kit-cpp> cmd/patch-nested-view.sh   （默认 vendor/gpui-kit-cpp）

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/vendor/gpui-kit-cpp}"
HDR="$GPUI_DIR/src/shell/runtime.h"
SRC="$GPUI_DIR/src/shell/runtime.cpp"
MARK="RegisterHostView"

[[ -f "$HDR" && -f "$SRC" ]] || { echo "ERROR: runtime.h/runtime.cpp not found" >&2; exit 1; }

if grep -qF "$MARK" "$SRC"; then
    echo "runtime.cpp: already patched, skip"
    exit 0
fi

echo "patching runtime for host-side nested-view registration (RegisterHostView)..."
cp "$HDR" "$HDR.nested.bak"; cp "$SRC" "$SRC.nested.bak"

# ── runtime.h：在 NestedView 查询声明后加注册声明 ──
python3 - "$HDR" <<'PYEOF'
import sys
path = sys.argv[1]
src = open(path).read()
anchor = "    EntityId NestedView(shell::EntityHandle handle, App* app) const;"
if anchor not in src:
    sys.exit("anchor `NestedView(...) const;` decl not found in runtime.h")
decl = anchor + '''

    // typephp-gpui: register an entity the host already owns (our PhpView, a
    // WebView wrapper view) as a nested view, so a ChildView spec node can render
    // it. QuickJS's cx.new is otherwise the only registrant and it brings a
    // ScriptView plus a Policy; a host entity brings neither, so owner, policy and
    // application stay empty — DropNestedViewAt then only EntityDrops the view, and
    // the owner/application sweeps never reach it. Returns the handle to put in
    // Component::handle, or 0 when the entity is not live.
    shell::EntityHandle RegisterHostView(EntityId view, App* app);'''
src = src.replace(anchor, decl, 1)
open(path, "w").write(src)
print("  runtime.h: RegisterHostView declaration added")
PYEOF

# ── runtime.cpp：定义紧接 NestedView 之后（impl / NestedViewEntry / FindNestedView 均在 scope）──
python3 - "$SRC" <<'PYEOF'
import sys
path = sys.argv[1]
src = open(path).read()
anchor = '''EntityId ShellRuntime::NestedView(shell::EntityHandle handle, App* app) const {
    if (handle == 0 || handle > UINT32_MAX) return {};
    NestedViewEntry* entry = FindNestedView(impl, (uint32_t)handle);
    if (!entry || !app || entry->app != app || !EntityGet(app, entry->view)) {
        return {};
    }
    return entry->view;
}'''
if anchor not in src:
    sys.exit("NestedView definition anchor not found in runtime.cpp")
added = anchor + '''

shell::EntityHandle ShellRuntime::RegisterHostView(EntityId view, App* app) {
    if (!view.IsValid() || !app || !EntityGet(app, view)) return 0;
    uint32_t token = impl->nextNestedView++;
    if (token == 0 || impl->nextNestedView == 0) return 0;
    NestedViewEntry nested = {};
    nested.token = token;
    nested.view = view;
    nested.app = app;
    VecAppend(impl->nestedViews, nested);
    return token;
}'''
src = src.replace(anchor, added, 1)
open(path, "w").write(src)
print("  runtime.cpp: RegisterHostView definition added")
PYEOF

echo "Patch complete. Regenerate amalgam: (cd $GPUI_DIR && bun cmd/build.ts -rel hello_world) then bash cmd/build-shim.sh"
