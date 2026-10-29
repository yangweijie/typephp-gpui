#!/usr/bin/env bash
# cmd/patch-candlestick.sh — chart/mod.cpp 补 CandlestickChart catalog descriptor。
#
# widget 层早已在 vendored 上游（ui/chart.h:706-799：CandlestickChart::New(opens,
# highs,lows,closes,n) + Id/TickMargin/Labels/Colors/Tooltip*/IntoEl，up/down 色由
# New 取 theme chartBullish/chartBearish），descriptor 两仓同缺——本脚本按
# chart/mod.cpp 现有五图（BarChart 等）的五件套样式补：Payload/Op/Record/
# Component/Materialize + 注册链。
#
# 数据面：rows 回调（SchemaCallback）——与五图同一条 SnapshotRowsWith 路，即批6
# 宿主数据回路的第二个受益者。每行 {open,high,low,close} 四个 Number；descriptor
# 抽四组数组给 widget 的 New(opens,highs,lows,closes,n)。
#
# 幂等：marker 在 = 已打；自检计数。用法：GPUI_DIR=<gpui-kit-cpp> cmd/patch-candlestick.sh
# （默认 build/gpui-kit-cpp）

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
SRC="$GPUI_DIR/src/component_shell/chart/mod.cpp"
MARK="typephp-gpui: candlestick chart"

[[ -f "$SRC" ]] || { echo "ERROR: chart/mod.cpp not found" >&2; exit 1; }

if grep -qF "$MARK" "$SRC"; then
    [[ $(grep -cF 'ComponentDescriptor kCandlestickChart' "$SRC") -eq 1 &&
       $(grep -cF 'registry->Register(&chart::kCandlestickChart, error)' "$SRC") -eq 1 ]] \
        || { echo "ERROR: candlestick marker exists but its descriptor/registration is incomplete" >&2; exit 1; }
    echo "chart/mod.cpp: candlestick already patched, skip"
    exit 0
fi

echo "patching chart/mod.cpp for CandlestickChart..."
cp "$SRC" "$SRC.candle.bak"

python3 - "$SRC" "$MARK" <<'PYEOF'
import sys
path, mark = sys.argv[1], sys.argv[2]
src = open(path).read()

# 1) CandlestickRows：抽四组数组（紧跟 Rows() 之后）
anchor_rows = """// The charts here hold arrays: the rows' values and their labels as a
// `const char*` array, where Rust's take the rows and closures over them."""
assert src.count(anchor_rows) == 1, "rows anchor count=%d" % src.count(anchor_rows)
candle_rows = """// """ + mark + """ — Candlestick rows: each row {open,high,low,close}, four
// Number fields. The four arrays go straight into CandlestickChart::New.
struct CandlestickRow {
    double open = 0;
    double high = 0;
    double low = 0;
    double close = 0;
};

static bool CandlestickRows(MaterializeRequest* request,
                            shell::ComponentCallback callback,
                            CandlestickRow** out, int* count, Str* error) {
    Ctx* cx = request->cx;
    Arena* a = cx->a;
    const shell::ComponentDataValue* rows = nullptr;
    int n = 0;
    if (!callback.SnapshotRowsWith(request->runtime, nullptr, 0, cx, &rows, &n,
                                   a, error)) {
        return false;
    }
    CandlestickRow* data =
        n > 0 ? (CandlestickRow*)Alloc(a, (int)sizeof(CandlestickRow) * n)
              : nullptr;
    for (int index = 0; index < n; index++) {
        const shell::ComponentDataValue& row = rows[index];
        if (row.kind != shell::DataKind::Object) {
            *error =
                StrDup(a, fmt("chart row %d must be a plain object", index));
            return false;
        }
        const shell::ComponentDataValue* open = row.Get(StrL("open"));
        const shell::ComponentDataValue* high = row.Get(StrL("high"));
        const shell::ComponentDataValue* low = row.Get(StrL("low"));
        const shell::ComponentDataValue* close = row.Get(StrL("close"));
        for (const shell::ComponentDataValue* v : {open, high, low, close}) {
            if (!v || v->kind != shell::DataKind::Number) {
                *error = StrDup(a, fmt("candlestick row %d must have finite "
                                       "number fields open/high/low/close",
                                       index));
                return false;
            }
        }
        data[index].open = open->number;
        data[index].high = high->number;
        data[index].low = low->number;
        data[index].close = close->number;
    }
    *out = data;
    *count = n;
    return true;
}

""" + anchor_rows
src = src.replace(anchor_rows, candle_rows, 1)

# 2) Render 顶部加 CandlestickChart 分支（在通用 Rows() 之前——candlestick
#    行没有 label/value，走自己的 CandlestickRows）
anchor_render = """static El* Render(MaterializeRequest* request, const char* kind,
                  shell::ComponentCallback callback) {
    Ctx* cx = request->cx;
    Arena* a = cx->a;
    Row* data = nullptr;"""
assert src.count(anchor_render) == 1, "render anchor count=%d" % src.count(anchor_render)
candle_render = """static El* Render(MaterializeRequest* request, const char* kind,
                  shell::ComponentCallback callback) {
    Ctx* cx = request->cx;
    Arena* a = cx->a;
    // CandlestickChart::new(opens, highs, lows, closes, n). Its rows carry no
    // label, so it reads its own snapshot before the shared Rows() validation.
    if (strcmp(kind, "CandlestickChart") == 0) {
        CandlestickRow* data = nullptr;
        int n = 0;
        Str error;
        if (!CandlestickRows(request, callback, &data, &n, &error)) {
            if (gProbe) gProbe(error);
            return Div(a)->Child(
                TextEl(a, StrDup(a, fmt("Failed to build %s data: %s",
                                        Str(kind), error))));
        }
        float* opens = (float*)Alloc(a, (int)(sizeof(float) * (n > 0 ? n : 1)));
        float* highs = (float*)Alloc(a, (int)(sizeof(float) * (n > 0 ? n : 1)));
        float* lows = (float*)Alloc(a, (int)(sizeof(float) * (n > 0 ? n : 1)));
        float* closes =
            (float*)Alloc(a, (int)(sizeof(float) * (n > 0 ? n : 1)));
        for (int i = 0; i < n; i++) {
            opens[i] = (float)data[i].open;
            highs[i] = (float)data[i].high;
            lows[i] = (float)data[i].low;
            closes[i] = (float)data[i].close;
        }
        component::CandlestickChart* chart = component::CandlestickChart::New(
            cx, opens, highs, lows, closes, n)->Id(request->elementId);
        EachMethod<Op>(request, [&](const Op& op) {
            switch (op.kind) {
                case Op::TickMargin:
                    chart->TickMargin(op.count);
                    break;
                default:
                    break;
            }
        });
        return chart->IntoEl();
    }
    Row* data = nullptr;"""
src = src.replace(anchor_render, candle_render, 1)

# 3) kCandlestickRowsArgs
anchor_args = """static constexpr ArgumentDescriptor kRowsArgs[] = {
    {"rows",
     SchemaCallback(
         "(cx: Context) => readonly { label: string; value: number }[]")}};"""
assert src.count(anchor_args) == 1, "args anchor count=%d" % src.count(anchor_args)
src = src.replace(anchor_args, anchor_args + """
static constexpr ArgumentDescriptor kCandlestickRowsArgs[] = {
    {"rows",
     SchemaCallback(
         "(cx: Context) => readonly { open: number; high: number; "
         "low: number; close: number }[]")}};""", 1)

# 4) kCandlestickMethods
anchor_methods = """static constexpr MethodDescriptor kRadarMethods[] = {
    kGrid,
    {"grid_levels", kGridLevelsArgs, kPositiveDoc,
     &RecordPositive<Op::GridLevels>},
    kDot,
};"""
assert src.count(anchor_methods) == 1, "methods anchor count=%d" % src.count(anchor_methods)
src = src.replace(anchor_methods, anchor_methods + """
static constexpr MethodDescriptor kCandlestickMethods[] = {
    kTickMargin,
};""", 1)

# 5) kCandlestickName
anchor_name = """static constexpr const char* kRadarName = "RadarChart";"""
assert src.count(anchor_name) == 1, "name anchor count=%d" % src.count(anchor_name)
src = src.replace(anchor_name, anchor_name + """
static constexpr const char* kCandlestickName = "CandlestickChart";""", 1)

# 6) kCandlestickConstructors
anchor_ctors = """static constexpr ConstructorDescriptor kRadarConstructors[] = {
    {"RadarChart", kRowsArgs, &Construct}};"""
assert src.count(anchor_ctors) == 1, "ctor anchor count=%d" % src.count(anchor_ctors)
src = src.replace(anchor_ctors, anchor_ctors + """
static constexpr ConstructorDescriptor kCandlestickConstructors[] = {
    {"CandlestickChart", kCandlestickRowsArgs, &Construct}};""", 1)

# 7) kCandlestickChart descriptor
anchor_desc = """static constexpr ComponentDescriptor kRadarChart = {
    "RadarChart", kRadarConstructors, kRadarMethods, kChartDoc,
    &Materialize<&kRadarName>};"""
assert src.count(anchor_desc) == 1, "desc anchor count=%d" % src.count(anchor_desc)
src = src.replace(anchor_desc, anchor_desc + """
static constexpr ComponentDescriptor kCandlestickChart = {
    "CandlestickChart", kCandlestickConstructors, kCandlestickMethods,
    kChartDoc, &Materialize<&kCandlestickName>};""", 1)

# 8) RegisterChart
anchor_reg = """           registry->Register(&chart::kRadarChart, error);"""
assert src.count(anchor_reg) == 1, "reg anchor count=%d" % src.count(anchor_reg)
src = src.replace(anchor_reg, """           registry->Register(&chart::kRadarChart, error) &&
           registry->Register(&chart::kCandlestickChart, error);""", 1)

open(path, "w").write(src)
print("chart/mod.cpp: CandlestickChart descriptor added")
PYEOF

[[ $(grep -cF "$MARK" "$SRC") -eq 1 ]] || { echo "ERROR: marker 没落上" >&2; exit 1; }
[[ $(grep -cF 'ComponentDescriptor kCandlestickChart' "$SRC") -eq 1 ]] \
    || { echo "ERROR: descriptor 没落上" >&2; exit 1; }
[[ $(grep -cF 'registry->Register(&chart::kCandlestickChart, error)' "$SRC") -eq 1 ]] \
    || { echo "ERROR: 注册没落上" >&2; exit 1; }
