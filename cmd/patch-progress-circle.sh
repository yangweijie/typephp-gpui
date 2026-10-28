#!/usr/bin/env bash
# cmd/patch-progress-circle.sh — 给 compound/progress.cpp 补 ProgressCircle 的
# catalog descriptor。
#
# widget 层零工作：component::ProgressCircle（ui/progress.h:40-64，New/Loading/
# Id/AccessibilityLabel/Value/Size/Color/Label/IntoEl）已经在 vendored 上游里；
# 缺的只是 descriptor（同文件 kProgress 的模式，五件套 Payload/Op/Record/
# Component/Materialize）。Value 是 Number 实参（不碰数据回路），Size 是像素
# Number（widget 的 Size(float)，非 kSizeLiterals 枚举），Color 走 ParseColorArgument
# （spinner.cpp:52-61 的同款）。
#
# 幂等：descriptor 块与注册两行都在才算已打；缺哪个补哪个；自检 marker 计数。
# 用法：GPUI_DIR=<gpui-kit-cpp> cmd/patch-progress-circle.sh（默认 build/gpui-kit-cpp）

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
PROG="$GPUI_DIR/src/component_shell/compound/progress.cpp"
MARK="kProgressCircle"

[[ -f "$PROG" ]] || { echo "ERROR: progress.cpp not found" >&2; exit 1; }

if grep -qF "$MARK" "$PROG"; then
    echo "progress.cpp: already patched, skip"
    exit 0
fi

echo "patching compound/progress.cpp with the ProgressCircle descriptor..."
cp "$PROG" "$PROG.progresscircle.bak"

python3 - "$PROG" <<'PYEOF'
import sys
path = sys.argv[1]
src = open(path).read()

# ── descriptor 块：插在 kProgress 之后、namespace 闭合之前 ──
anchor = "} // namespace gpui::component_shell::compound::progress"
if src.count(anchor) != 1:
    sys.exit("namespace-close anchor count=%d" % src.count(anchor))

descriptor = '''
// ProgressCircle — the circular progress indicator (ui/progress.h:40-64). Same
// shape as kProgress above: a payload, a five-op method table, and a materializer
// that rejects children (the widget owns the arc; there is no content slot).

struct ProgressCirclePayload {
    Str id;
};

struct ProgressCircleOp {
    enum Kind : uint8_t {
        Value,
        Loading,
        Label,
        Size,
        Color,
    } kind = Value;
    float value = 0;
    bool loading = false;
    bool showLabel = false;
    float size = 48;
    Rgba color = {};
};

static bool ConstructCircle(PayloadBuild* build, const ComponentArgument* args,
                            int) {
    Str error;
    if (!common::NonemptyId(args[0].string, "ProgressCircle", &error))
        return build->Fail(error);
    build->New<ProgressCirclePayload>()->id = args[0].string;
    return true;
}

static bool RecordCircleValue(PayloadBuild* build,
                              const ComponentArgument* args, int) {
    float value = 0;
    Str error;
    if (!common::FiniteF32(args[0].number,
                           StrL("ProgressCircle.value(value)"), &value, &error))
        return build->Fail(error);
    ProgressCircleOp* op = build->New<ProgressCircleOp>();
    op->kind = ProgressCircleOp::Value;
    op->value = value;
    return true;
}

static bool RecordCircleLoading(PayloadBuild* build,
                                const ComponentArgument* args, int) {
    ProgressCircleOp* op = build->New<ProgressCircleOp>();
    op->kind = ProgressCircleOp::Loading;
    op->loading = args[0].boolean;
    return true;
}

static bool RecordCircleLabel(PayloadBuild* build,
                              const ComponentArgument* args, int) {
    ProgressCircleOp* op = build->New<ProgressCircleOp>();
    op->kind = ProgressCircleOp::Label;
    op->showLabel = args[0].boolean;
    return true;
}

static bool RecordCircleSize(PayloadBuild* build,
                             const ComponentArgument* args, int) {
    float size = 0;
    Str error;
    if (!common::FiniteF32(args[0].number,
                           StrL("ProgressCircle.size(size)"), &size, &error))
        return build->Fail(error);
    ProgressCircleOp* op = build->New<ProgressCircleOp>();
    op->kind = ProgressCircleOp::Size;
    op->size = size;
    return true;
}

static bool RecordCircleColor(PayloadBuild* build,
                              const ComponentArgument* args, int) {
    Rgba color = {};
    if (!ParseColorArgument(build, "ProgressCircle", args[0].string, &color))
        return false;
    ProgressCircleOp* op = build->New<ProgressCircleOp>();
    op->kind = ProgressCircleOp::Color;
    op->color = color;
    return true;
}

// ProgressCircleMaterializer::component: the payload check, then every recorded
// operation folded into the builder in script order.
static component::ProgressCircle* CircleComponent(MaterializeRequest* request) {
    const ProgressCirclePayload* payload =
        request->PayloadAs<ProgressCirclePayload>();
    if (!payload) {
        request->Fail(StrL("ProgressCircle received an incompatible payload"));
        return nullptr;
    }
    component::ProgressCircle* circle =
        component::ProgressCircle::New(request->cx)->Id(payload->id);
    EachMethod<ProgressCircleOp>(request, [&](const ProgressCircleOp& op) {
        switch (op.kind) {
            case ProgressCircleOp::Value:
                circle->Value(op.value);
                break;
            case ProgressCircleOp::Loading:
                circle->Loading(op.loading);
                break;
            case ProgressCircleOp::Label:
                circle->Label(op.showLabel);
                break;
            case ProgressCircleOp::Size:
                circle->Size(op.size);
                break;
            case ProgressCircleOp::Color:
                circle->Color(op.color);
                break;
        }
    });
    return circle;
}

static El* MaterializeCircle(MaterializeRequest* request) {
    if (request->ChildrenLen() != 0)
        return request->Fail(StrL("ProgressCircle does not accept children"));
    component::ProgressCircle* circle = CircleComponent(request);
    if (!circle) return nullptr;
    El* wrapper = Div(request->cx->a)->Child(circle->IntoEl());
    return request->ApplyStyle(wrapper);
}

static constexpr ArgumentDescriptor kCircleIdArgs[] = {{"id", SchemaString()}};
static constexpr ArgumentDescriptor kCircleValueArgs[] = {
    {"value", SchemaNumber()}};
static constexpr ArgumentDescriptor kCircleLoadingArgs[] = {
    {"loading", SchemaBoolean()}};
static constexpr ArgumentDescriptor kCircleLabelArgs[] = {
    {"label", SchemaBoolean()}};
static constexpr ArgumentDescriptor kCircleSizeArgs[] = {
    {"size", SchemaNumber()}};
static constexpr ArgumentDescriptor kCircleColorArgs[] = {
    {"color", SchemaString()}};

static constexpr ConstructorDescriptor kCircleConstructors[] = {
    {"ProgressCircle", kCircleIdArgs, &ConstructCircle}};
static constexpr MethodDescriptor kCircleMethods[] = {
    {"value", kCircleValueArgs,
     "Sets percentage progress; the component clamps it to 0–100.",
     &RecordCircleValue},
    {"loading", kCircleLoadingArgs, "Enables indeterminate rotation animation.",
     &RecordCircleLoading},
    {"label", kCircleLabelArgs, "Shows the current value inside the ring.",
     &RecordCircleLabel},
    {"size", kCircleSizeArgs, "Sets the ring diameter in pixels.",
     &RecordCircleSize},
    {"color", kCircleColorArgs, "Sets the ring stroke color.",
     &RecordCircleColor},
};
static constexpr ComponentDescriptor kProgressCircle = {
    "ProgressCircle", kCircleConstructors, kCircleMethods,
    "A circular progress indicator.", &MaterializeCircle};

'''
src = src.replace(anchor, descriptor + anchor, 1)

# ── 注册链：kProgress && kProgressCircle ──
reg_old = '''bool RegisterCompoundProgress(shell::ComponentRegistry* registry,
                              shell::RegistryError* error) {
    return registry->Register(&compound::progress::kProgress, error);
}'''
if src.count(reg_old) != 1:
    sys.exit("register anchor count=%d" % src.count(reg_old))
reg_new = '''bool RegisterCompoundProgress(shell::ComponentRegistry* registry,
                              shell::RegistryError* error) {
    return registry->Register(&compound::progress::kProgress, error) &&
           registry->Register(&compound::progress::kProgressCircle, error);
}'''
src = src.replace(reg_old, reg_new, 1)

open(path, "w").write(src)
print("  progress.cpp: ProgressCircle descriptor + register")
PYEOF

nDesc=$(grep -cF "kProgressCircle" "$PROG" || true)
nReg=$(grep -cF "registry->Register(&compound::progress::kProgressCircle" "$PROG" || true)
nOld=$(grep -cF "return registry->Register(&compound::progress::kProgress, error);" "$PROG" || true)
if [[ "$nDesc" != "2" || "$nReg" != "1" || "$nOld" != "0" ]]; then
    echo "ERROR: self-check failed (descriptor=$nDesc want 2, register=$nReg want 1, oldreg=$nOld want 0)" >&2
    exit 1
fi
echo "Patch complete."
echo "Regenerate amalgam: (cd $GPUI_DIR && bun cmd/build.ts -rel hello_world)"
