#!/usr/bin/env bash
# cmd/patch-sankey.sh — chart/mod.cpp 补 SankeyChart catalog descriptor。
#
# widget 早已在 vendored 上游（ui/chart.h:1019-1104：SankeyChart::New(cx) +
# Node(Str)/Link(int,int,double)/ShowValues(bool)/IntoEl，布局在 base/sankey.h），
# descriptor 两仓同缺——本脚本补上。
#
# 与五图/Candlestick 不同的数据面：桑基图是**命令式**建图（New → Node×n → Link×n），
# 不是单 rows 回调。descriptor 吃**双回调**：nodes（[{label}…]）+ links
# （[{source,target,value}…]），分别经 SnapshotRowsWith 快照（即批6宿主数据回路的
# 第三个受益者）。新 Op::ShowValues 方法（show_values(bool)）。
#
# 依赖 patch-candlestick.sh 先落（锚点在其 additions 上；patchManifest 顺序保证）。
#
# 幂等：marker 在 = 已打；自检计数。用法：GPUI_DIR=<gpui-kit-cpp> cmd/patch-sankey.sh

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
SRC="$GPUI_DIR/src/component_shell/chart/mod.cpp"
MARK="typephp-gpui: sankey chart"

[[ -f "$SRC" ]] || { echo "ERROR: chart/mod.cpp not found" >&2; exit 1; }

if grep -qF "$MARK" "$SRC"; then
    [[ $(grep -cF 'ComponentDescriptor kSankeyChart' "$SRC") -eq 1 &&
       $(grep -cF 'registry->Register(&chart::kSankeyChart, error)' "$SRC") -eq 1 ]] \
        || { echo "ERROR: sankey marker exists but its descriptor/registration is incomplete" >&2; exit 1; }
    echo "chart/mod.cpp: sankey already patched, skip"
    exit 0
fi

# 前置检查：candlestick 补丁必须先落（本脚本的锚点在其 additions 上）
grep -qF 'typephp-gpui: candlestick chart' "$SRC" \
    || { echo "ERROR: patch-sankey.sh 依赖 patch-candlestick.sh 先落（锚点在 candlestick additions 上）" >&2; exit 1; }

echo "patching chart/mod.cpp for SankeyChart..."
cp "$SRC" "$SRC.sankey.bak"

python3 - "$SRC" "$MARK" <<'PYEOF'
import sys
path, mark = sys.argv[1], sys.argv[2]
src = open(path).read()

# 1) Op 枚举加 ShowValues
anchor_enum = """        Labels,
        GridLevels,
    } kind = Grid;"""
assert src.count(anchor_enum) == 1, "enum anchor count=%d" % src.count(anchor_enum)
src = src.replace(anchor_enum, """        Labels,
        GridLevels,
        ShowValues,
    } kind = Grid;""", 1)

# 2) OpName 加 show_values case（GridLevels 与 InnerRadius 之间）
anchor_opname = """        case Op::GridLevels:
            return "grid_levels";
        case Op::InnerRadius:"""
assert src.count(anchor_opname) == 1, "opname anchor count=%d" % src.count(anchor_opname)
src = src.replace(anchor_opname, """        case Op::GridLevels:
            return "grid_levels";
        case Op::ShowValues:
            return "show_values";
        case Op::InnerRadius:""", 1)

# 3) SankeyPayload 结构体（紧跟 Payload 之后）
anchor_payload = """struct Payload {
    ComponentArgument rows = {};
};"""
assert src.count(anchor_payload) == 1, "payload anchor count=%d" % src.count(anchor_payload)
src = src.replace(anchor_payload, anchor_payload + """

// """ + mark + """ — SankeyChart 吃双回调：nodes（[{label}]）+ links
// （[{source,target,value}]），与五图的单 rows 回调不同。
struct SankeyPayload {
    ComponentArgument nodes = {};
    ComponentArgument links = {};
};""", 1)

# 4) SankeyNodeData/LinkData 结构体（紧跟 CandlestickRow 之后）
anchor_crow = """struct CandlestickRow {
    double open = 0;
    double high = 0;
    double low = 0;
    double close = 0;
};"""
assert src.count(anchor_crow) == 1, "crow anchor count=%d" % src.count(anchor_crow)
src = src.replace(anchor_crow, anchor_crow + """

// Sankey 节点/链接数据（widget 的 Node(Str)/Link(int,int,double) 的命令式输入）。
struct SankeyNodeData {
    Str label = {};
};
struct SankeyLinkData {
    int source = 0;
    int target = 0;
    double value = 0;
};""", 1)

# 5) SankeyNodes/Links 函数（紧跟 CandlestickRows 之后）
anchor_cr_end = """        data[index].open = open->number;
        data[index].high = high->number;
        data[index].low = low->number;
        data[index].close = close->number;
    }
    *out = data;
    *count = n;
    return true;
}"""
assert src.count(anchor_cr_end) == 1, "cr_end anchor count=%d" % src.count(anchor_cr_end)
src = src.replace(anchor_cr_end, anchor_cr_end + """

// SankeyNodes: 快照 nodes 回调，每行 {label} 一个字符串字段。
static bool SankeyNodes(MaterializeRequest* request,
                        shell::ComponentCallback callback,
                        SankeyNodeData** out, int* count, Str* error) {
    Ctx* cx = request->cx;
    Arena* a = cx->a;
    const shell::ComponentDataValue* rows = nullptr;
    int n = 0;
    if (!callback.SnapshotRowsWith(request->runtime, nullptr, 0, cx, &rows, &n,
                                   a, error)) {
        return false;
    }
    SankeyNodeData* data =
        n > 0 ? (SankeyNodeData*)Alloc(a, (int)sizeof(SankeyNodeData) * n)
              : nullptr;
    for (int index = 0; index < n; index++) {
        const shell::ComponentDataValue& row = rows[index];
        if (row.kind != shell::DataKind::Object) {
            *error =
                StrDup(a, fmt("sankey node %d must be a plain object", index));
            return false;
        }
        const shell::ComponentDataValue* label = row.Get(StrL("label"));
        if (!label || label->kind != shell::DataKind::String) {
            *error = StrDup(
                a, fmt("sankey node %d must have string field `label`", index));
            return false;
        }
        data[index].label = StrDup(a, label->string);
    }
    *out = data;
    *count = n;
    return true;
}

// SankeyLinks: 快照 links 回调，每行 {source,target,value} 三个数字字段。
static bool SankeyLinks(MaterializeRequest* request,
                        shell::ComponentCallback callback,
                        SankeyLinkData** out, int* count, Str* error) {
    Ctx* cx = request->cx;
    Arena* a = cx->a;
    const shell::ComponentDataValue* rows = nullptr;
    int n = 0;
    if (!callback.SnapshotRowsWith(request->runtime, nullptr, 0, cx, &rows, &n,
                                   a, error)) {
        return false;
    }
    SankeyLinkData* data =
        n > 0 ? (SankeyLinkData*)Alloc(a, (int)sizeof(SankeyLinkData) * n)
              : nullptr;
    for (int index = 0; index < n; index++) {
        const shell::ComponentDataValue& row = rows[index];
        if (row.kind != shell::DataKind::Object) {
            *error =
                StrDup(a, fmt("sankey link %d must be a plain object", index));
            return false;
        }
        const shell::ComponentDataValue* source = row.Get(StrL("source"));
        const shell::ComponentDataValue* target = row.Get(StrL("target"));
        const shell::ComponentDataValue* value = row.Get(StrL("value"));
        for (const shell::ComponentDataValue* v : {source, target, value}) {
            if (!v || v->kind != shell::DataKind::Number) {
                *error = StrDup(a, fmt("sankey link %d must have finite "
                                       "number fields source/target/value",
                                       index));
                return false;
            }
        }
        data[index].source = (int)source->number;
        data[index].target = (int)target->number;
        data[index].value = value->number;
    }
    *out = data;
    *count = n;
    return true;
}""", 1)

# 6) SankeyMaterialize 函数（紧跟 Materialize 模板之后）
anchor_mat = """template <const char* const* Kind>
static El* Materialize(MaterializeRequest* request) {
    return MaterializeKind(request, *Kind);
}"""
assert src.count(anchor_mat) == 1, "mat anchor count=%d" % src.count(anchor_mat)
src = src.replace(anchor_mat, anchor_mat + """

// SankeyMaterialize: 双回调（nodes + links）→ New → Node×n → Link×n →
// ShowValues → IntoEl，包进 Div wrapper（与五图同款 full-size host）。
static El* SankeyMaterialize(MaterializeRequest* request) {
    const SankeyPayload* payload = request->PayloadAs<SankeyPayload>();
    if (!payload)
        return request->Fail(StrL("SankeyChart received an incompatible payload"));
    shell::ComponentCallback nodesCb = request->ResolveCallback(payload->nodes);
    shell::ComponentCallback linksCb = request->ResolveCallback(payload->links);
    if (!nodesCb.IsSet() || !linksCb.IsSet())
        return request->Fail(StrL("component argument is not a callback"));
    if (request->ChildrenLen() != 0)
        return request->Fail(StrL("charts do not accept children"));
    El** children = nullptr;
    int count = 0;
    if (!request->TakeChildren(&children, &count)) return nullptr;
    if (count != 0)
        return request->Fail(StrL("charts do not accept ordinary children"));
    Ctx* cx = request->cx;
    Arena* a = cx->a;
    SankeyNodeData* nodes = nullptr;
    int nNodes = 0;
    SankeyLinkData* links = nullptr;
    int nLinks = 0;
    Str error;
    if (!SankeyNodes(request, nodesCb, &nodes, &nNodes, &error)) {
        if (gProbe) gProbe(error);
        return Div(a)->Child(
            TextEl(a, StrDup(a, fmt("Failed to build SankeyChart nodes: %s",
                                    error))));
    }
    if (!SankeyLinks(request, linksCb, &links, &nLinks, &error)) {
        if (gProbe) gProbe(error);
        return Div(a)->Child(
            TextEl(a, StrDup(a, fmt("Failed to build SankeyChart links: %s",
                                    error))));
    }
    component::SankeyChart* chart =
        component::SankeyChart::New(cx)->Id(request->elementId);
    for (int i = 0; i < nNodes; i++) {
        chart->Node(nodes[i].label);
    }
    for (int i = 0; i < nLinks; i++) {
        chart->Link(links[i].source, links[i].target, links[i].value);
    }
    bool showValues = false;
    EachMethod<Op>(request, [&](const Op& op) {
        switch (op.kind) {
            case Op::ShowValues:
                showValues = op.flag;
                break;
            default:
                break;
        }
    });
    chart->ShowValues(showValues);
    El* wrapper = Div(a)->W(kFill)->H(kFill);
    request->ApplyStyle(wrapper);
    wrapper->Child(chart->IntoEl());
    return wrapper;
}""", 1)

# 7) SankeyConstruct 函数（紧跟 Construct 之后）
anchor_construct = """static bool Construct(PayloadBuild* build, const ComponentArgument* args,
                      int count) {
    if (count != 1 || args[0].kind != shell::ComponentArgumentKind::Callback)
        return build->Fail(StrL("chart expects a row snapshot callback"));
    build->New<Payload>()->rows = args[0];
    return true;
}"""
assert src.count(anchor_construct) == 1, "construct anchor count=%d" % src.count(anchor_construct)
src = src.replace(anchor_construct, anchor_construct + """

// SankeyConstruct: 吃两个回调（nodes + links），与五图的单回调不同。
static bool SankeyConstruct(PayloadBuild* build, const ComponentArgument* args,
                            int count) {
    if (count != 2 || args[0].kind != shell::ComponentArgumentKind::Callback ||
        args[1].kind != shell::ComponentArgumentKind::Callback)
        return build->Fail(
            StrL("SankeyChart expects a nodes callback and a links callback"));
    auto* payload = build->New<SankeyPayload>();
    payload->nodes = args[0];
    payload->links = args[1];
    return true;
}""", 1)

# 8) kSankeyArgs（双回调：nodes + links）
anchor_cargs = """static constexpr ArgumentDescriptor kCandlestickRowsArgs[] = {
    {"rows",
     SchemaCallback(
         "(cx: Context) => readonly { open: number; high: number; "
         "low: number; close: number }[]")}};"""
assert src.count(anchor_cargs) == 1, "cargs anchor count=%d" % src.count(anchor_cargs)
src = src.replace(anchor_cargs, anchor_cargs + """
static constexpr ArgumentDescriptor kSankeyArgs[] = {
    {"nodes",
     SchemaCallback(
         "(cx: Context) => readonly { label: string }[]")},
    {"links",
     SchemaCallback(
         "(cx: Context) => readonly { source: number; target: number; "
         "value: number }[]")}};
static constexpr ArgumentDescriptor kShowValuesArgs[] = {
    {"show_values", SchemaBoolean()}};""", 1)

# 9) kShowValues + kSankeyMethods
anchor_cmethods = """static constexpr MethodDescriptor kCandlestickMethods[] = {
    kTickMargin,
};"""
assert src.count(anchor_cmethods) == 1, "cmethods anchor count=%d" % src.count(anchor_cmethods)
src = src.replace(anchor_cmethods, anchor_cmethods + """
static constexpr MethodDescriptor kShowValues = {
    "show_values", kShowValuesArgs, kOptionDoc, &RecordBool<Op::ShowValues>};
static constexpr MethodDescriptor kSankeyMethods[] = {
    kShowValues,
};""", 1)

# 10) kSankeyName — 不需要：SankeyMaterialize 是直接函数指针，不走
# Materialize<&kName> 模板（模板要 name 查 kind 字符串，SankeyMaterialize 硬编码）。

# 11) kSankeyConstructors
anchor_cctor = """static constexpr ConstructorDescriptor kCandlestickConstructors[] = {
    {"CandlestickChart", kCandlestickRowsArgs, &Construct}};"""
assert src.count(anchor_cctor) == 1, "cctor anchor count=%d" % src.count(anchor_cctor)
src = src.replace(anchor_cctor, anchor_cctor + """
static constexpr ConstructorDescriptor kSankeyConstructors[] = {
    {"SankeyChart", kSankeyArgs, &SankeyConstruct}};""", 1)

# 12) kSankeyChart descriptor
anchor_cdesc = """static constexpr ComponentDescriptor kCandlestickChart = {
    "CandlestickChart", kCandlestickConstructors, kCandlestickMethods,
    kChartDoc, &Materialize<&kCandlestickName>};"""
assert src.count(anchor_cdesc) == 1, "cdesc anchor count=%d" % src.count(anchor_cdesc)
src = src.replace(anchor_cdesc, anchor_cdesc + """
static constexpr ComponentDescriptor kSankeyChart = {
    "SankeyChart", kSankeyConstructors, kSankeyMethods, kChartDoc,
    &SankeyMaterialize};""", 1)

# 13) RegisterChart
anchor_creg = """           registry->Register(&chart::kCandlestickChart, error);"""
assert src.count(anchor_creg) == 1, "creg anchor count=%d" % src.count(anchor_creg)
src = src.replace(anchor_creg, """           registry->Register(&chart::kCandlestickChart, error) &&
           registry->Register(&chart::kSankeyChart, error);""", 1)

open(path, "w").write(src)
print("chart/mod.cpp: SankeyChart descriptor added")
PYEOF

[[ $(grep -cF "$MARK" "$SRC") -eq 1 ]] || { echo "ERROR: marker 没落上" >&2; exit 1; }
[[ $(grep -cF 'ComponentDescriptor kSankeyChart' "$SRC") -eq 1 ]] \
    || { echo "ERROR: descriptor 没落上" >&2; exit 1; }
[[ $(grep -cF 'registry->Register(&chart::kSankeyChart, error)' "$SRC") -eq 1 ]] \
    || { echo "ERROR: 注册没落上" >&2; exit 1; }
