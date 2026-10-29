#!/usr/bin/env bash
# cmd/patch-searchable-list.sh — 新建 component_shell/delegate_collections/searchable_list.cpp：
# SearchableList catalog 导出（widget 层已在 vendored 上游——ui/searchable_list.h:19-378：
# SearchableListItem + SearchableListDelegate 函数表 + SearchableList::New/Items/Delegate/IntoEl，
# descriptor 两仓同缺，本脚本补）。
#
# 形状：rows 回调（[{title,value?,section?,disabled?,badge?}]）→ SearchableListItem
# 数组 → SearchableListDelegate::Items 工厂（默认函数表：sectionsCount/itemsCount/
# item/matches/isItemEnabled/isItemChecked 全部由上游默认实现提供，matches 是 title
# 的大小写不敏感子串）。query 传 null（widget 的 IntoEl 安全跳过搜索输入框，空查询
# 匹配全部）。state 走 UseKeyedState 保留（与 List/Select 同款）。
#
# 行渲染不发 render_item 回调：delegate 的 renderItem 留 null，widget 用默认行
# （title + icon + badge）。元素通道（render_cell/renderRow）未实现（批6 只做数据
# 通道），发元素回调会走 BuildComponentElement 的 shimId 分叉 → 非确定性 SIGBUS
# （DataTable render_cell 实证）。如实记录，不假装。
#
# 幂等：新文件 marker 在 + mod.cpp 注册链在 + families.h 声明在 = 已打。
# 用法：GPUI_DIR=<gpui-kit-cpp> cmd/patch-searchable-list.sh（默认 build/gpui-kit-cpp）

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
SLC="$GPUI_DIR/src/component_shell/delegate_collections/searchable_list.cpp"
MOD="$GPUI_DIR/src/component_shell/delegate_collections/mod.cpp"
FAM="$GPUI_DIR/src/component_shell/families.h"
MARK="RegisterSearchableList"

[[ -f "$MOD" ]] || { echo "ERROR: delegate_collections/mod.cpp not found" >&2; exit 1; }
[[ -f "$FAM" ]] || { echo "ERROR: families.h not found" >&2; exit 1; }

# ── 1) 新建 searchable_list.cpp ──
if grep -qF "ComponentDescriptor kSearchableList" "$SLC" 2>/dev/null; then
    echo "searchable_list.cpp: already patched, skip"
else
    echo "creating delegate_collections/searchable_list.cpp..."
    cp "$SLC" "$SLC.searchablelist.bak" 2>/dev/null || true
    cat > "$SLC" <<'SLC_CPP'
// crates/component-shell/src/shell/delegate_collections/searchable_list.cpp
// typephp-gpui: searchable list descriptor
//
// SearchableList: the themed searchable list (ui/searchable_list.h). The widget
// layer is already in the vendored tree (SearchableListItem + SearchableListDelegate
// function table + SearchableList::New/Items/Delegate/IntoEl); this file is the
// catalog surface.

#include "component_shell/families.h"
#include "component_shell/support.h"
#include "ui/searchable_list.h"

namespace gpui::component_shell::delegate_collections::searchable_list {

struct Payload {
    Str id;
    ComponentArgument rows = {};
};

// One row's fields, converted from the snapshot.
struct Row {
    Str title = {};
    Str value = {};
    int section = 0;
    bool disabled = false;
    Str badge = {};
};

// rows(): the snapshot, each row a plain object with a string `title` and
// optional value/section/disabled/badge. False with `error` set otherwise.
static bool Rows(MaterializeRequest* request, shell::ComponentCallback callback,
                 Row** out, int* count, Str* error) {
    Ctx* cx = request->cx;
    Arena* a = cx->a;
    const shell::ComponentDataValue* rows = nullptr;
    int n = 0;
    if (!callback.SnapshotRowsWith(request->runtime, nullptr, 0, cx, &rows, &n,
                                   a, error)) {
        return false;
    }
    Row* data = n > 0 ? (Row*)Alloc(a, (int)sizeof(Row) * n) : nullptr;
    for (int index = 0; index < n; index++) {
        const shell::ComponentDataValue& row = rows[index];
        if (row.kind != shell::DataKind::Object) {
            *error = StrDup(
                a, fmt("searchable list row %d must be a plain object", index));
            return false;
        }
        const shell::ComponentDataValue* title = row.Get(StrL("title"));
        if (!title || title->kind != shell::DataKind::String) {
            *error = StrDup(
                a, fmt("searchable list row %d must have string field `title`",
                       index));
            return false;
        }
        data[index].title = StrDup(a, title->string);
        // value: defaults to title (the item's identity).
        const shell::ComponentDataValue* value = row.Get(StrL("value"));
        data[index].value = (value && value->kind == shell::DataKind::String)
                                ? StrDup(a, value->string)
                                : data[index].title;
        // section: defaults to 0.
        const shell::ComponentDataValue* section = row.Get(StrL("section"));
        data[index].section = (section && section->kind == shell::DataKind::Number)
                                  ? (int)section->number
                                  : 0;
        // disabled: defaults to false.
        const shell::ComponentDataValue* disabled = row.Get(StrL("disabled"));
        data[index].disabled = (disabled &&
                                disabled->kind == shell::DataKind::Boolean)
                                   ? disabled->boolean
                                   : false;
        // badge: defaults to empty.
        const shell::ComponentDataValue* badge = row.Get(StrL("badge"));
        data[index].badge = (badge && badge->kind == shell::DataKind::String)
                                ? StrDup(a, badge->string)
                                : Str{};
    }
    *out = data;
    *count = n;
    return true;
}

// The retained SearchableListState, owned by the keyed state.
struct Host {
    App* app = nullptr;
    Entity<component::SearchableListState> state = {};
    ~Host() {
        if (app && state.IsValid()) EntityDrop(app, state.id);
    }
};

static El* Failure(Ctx* cx, const char* what, Str error) {
    return Div(cx->a)
        ->Child(TextEl(cx->a, StrDup(cx->a, fmt("%s: %s", Str(what), error))));
}

// Render: snapshot rows → SearchableListItem array → delegate → SearchableList.
static El* Render(MaterializeRequest* request, const Payload* payload,
                  shell::ComponentCallback rows, ElRefiner style) {
    Ctx* cx = request->cx;
    Arena* a = cx->a;
    Row* data = nullptr;
    int n = 0;
    Str error;
    if (!Rows(request, rows, &data, &n, &error)) {
        return Failure(cx, "Failed to snapshot SearchableList rows", error);
    }
    // Convert to SearchableListItem array (the delegate's item source).
    component::SearchableListItem* items =
        n > 0 ? (component::SearchableListItem*)Alloc(a,
                                           (int)sizeof(component::SearchableListItem) * n)
              : nullptr;
    for (int i = 0; i < n; i++) {
        items[i] = component::SearchableListItem{};
        items[i].title = data[i].title;
        items[i].value = data[i].value;
        items[i].section = data[i].section;
        items[i].disabled = data[i].disabled;
        items[i].badge = data[i].badge;
    }
    Host* host = UseKeyedState<Host>(cx, payload->id,
                                     StrL("shell-searchable-list"))
                     .Get(cx);
    if (!host) return Div(cx->a);
    if (!host->state.IsValid()) {
        host->app = cx->app;
        host->state = EntityNewState<component::SearchableListState>(cx->app);
    }
    // The delegate's default function table: sectionsCount(1), itemsCount,
    // item, matches (title substring), isItemEnabled, isItemChecked — all
    // from SearchableListDelegate::Items + the upstream defaults.
    component::SearchableListDelegate delegate =
        component::SearchableListDelegate::Items(items, n);
    component::SearchableList* list =
        component::SearchableList::New(cx, payload->id, host->state, nullptr)
            ->Items(items, n)
            ->Delegate(delegate);
    // Style: read the width from a probe (as List does for height) and apply
    // the full style to the root.
    if (style.IsSet()) {
        El* probe = Div(cx->a);
        style.Apply(probe);
        if (probe->style.width > 0 && probe->style.width != kAuto)
            list->W(probe->style.width);
    }
    El* root = list->IntoEl();
    style.Apply(root);
    return root;
}

static El* Materialize(MaterializeRequest* request) {
    const Payload* payload = request->PayloadAs<Payload>();
    if (!payload)
        return request->Fail(StrL("SearchableList incompatible payload"));
    shell::ComponentCallback rows = request->ResolveCallback(payload->rows);
    if (!rows.IsSet())
        return request->Fail(StrL("component argument is not a callback"));
    if (request->ChildrenLen() != 0)
        return request
            ->Fail(StrL("SearchableList does not accept children"));
    El** children = nullptr;
    int count = 0;
    if (!request->TakeChildren(&children, &count)) return nullptr;
    if (count != 0)
        return request
            ->Fail(StrL("SearchableList does not accept ordinary children"));
    return Render(request, payload, rows, request->TakeStyle());
}

static bool Construct(PayloadBuild* build, const ComponentArgument* args,
                      int count) {
    if (count != 2 ||
        args[0].kind != shell::ComponentArgumentKind::String ||
        args[1].kind != shell::ComponentArgumentKind::Callback ||
        len(StrTrim(args[0].string)) == 0) {
        return build->Fail(StrL("SearchableList expects a non-empty id and a "
                                "rows callback"));
    }
    Payload* payload = build->New<Payload>();
    payload->id = args[0].string;
    payload->rows = args[1];
    return true;
}

static constexpr ArgumentDescriptor kArguments[] = {
    {"id", SchemaString()},
    {"rows",
     SchemaCallback(
         "() => readonly { title: string; value?: string; section?: number; "
         "disabled?: boolean; badge?: string }[]")},
};
static constexpr ConstructorDescriptor kConstructors[] = {
    {"SearchableList", kArguments, &Construct}};
static constexpr ComponentDescriptor kSearchableList = {
    "SearchableList",
    kConstructors,
    {},
    "Native retained SearchableList backed by an immutable rows snapshot. "
    "Each row is { title, value?, section?, disabled?, badge? }; the delegate "
    "filters by title substring (case-insensitive).",
    &Materialize};

} // namespace gpui::component_shell::delegate_collections::searchable_list

namespace gpui::component_shell {

bool RegisterSearchableList(shell::ComponentRegistry* registry,
                            shell::RegistryError* error) {
    return registry->Register(
        &delegate_collections::searchable_list::kSearchableList, error);
}

} // namespace gpui::component_shell
SLC_CPP
fi

# ── 2) delegate_collections/mod.cpp：注册链加 RegisterSearchableList ──
if grep -qF "$MARK" "$MOD"; then
    echo "delegate_collections/mod.cpp: already patched, skip"
else
    echo "patching delegate_collections/mod.cpp with the registration chain..."
    cp "$MOD" "$MOD.searchablelist.bak"
    python3 - "$MOD" <<'PYEOF'
import sys
path = sys.argv[1]
src = open(path).read()
old = """bool RegisterDelegateCollections(shell::ComponentRegistry* registry,
                                 shell::RegistryError* error) {
    return RegisterDelegateCollectionsList(registry, error);
}"""
if src.count(old) != 1:
    sys.exit("register chain anchor count=%d" % src.count(old))
new = """bool RegisterDelegateCollections(shell::ComponentRegistry* registry,
                                 shell::RegistryError* error) {
    return RegisterDelegateCollectionsList(registry, error) &&
           RegisterSearchableList(registry, error);
}"""
src = src.replace(old, new, 1)
open(path, "w").write(src)
print("  mod.cpp: RegisterSearchableList added to the chain")
PYEOF
fi

# ── 3) families.h：声明 RegisterSearchableList ──
if grep -qF "bool RegisterSearchableList" "$FAM"; then
    echo "families.h: already patched, skip"
else
    echo "patching families.h with the RegisterSearchableList declaration..."
    cp "$FAM" "$FAM.searchablelist.bak"
    python3 - "$FAM" <<'PYEOF2'
import sys
path = sys.argv[1]
src = open(path).read()
anchor = """// delegate_collections/mod.rs
bool RegisterDelegateCollectionsList(shell::ComponentRegistry*,
                                     shell::RegistryError*);"""
if src.count(anchor) != 1:
    sys.exit("families.h anchor count=%d" % src.count(anchor))
src = src.replace(anchor, anchor + """

// searchable_list.cpp (typephp-gpui)
bool RegisterSearchableList(shell::ComponentRegistry*,
                            shell::RegistryError*);""", 1)
open(path, "w").write(src)
print("  families.h: RegisterSearchableList declared")
PYEOF2
fi

# ── 自检 ──
[[ $(grep -cF 'ComponentDescriptor kSearchableList' "$SLC") -eq 1 ]] \
    || { echo "ERROR: descriptor 没落上" >&2; exit 1; }
[[ $(grep -cF 'RegisterSearchableList(registry, error)' "$MOD") -eq 1 ]] \
    || { echo "ERROR: 注册链没落上" >&2; exit 1; }
[[ $(grep -cF 'bool RegisterSearchableList' "$FAM") -eq 1 ]] \
    || { echo "ERROR: families.h 声明没落上" >&2; exit 1; }
