#!/usr/bin/env bash
# cmd/patch-title-bar.sh — TitleBar C 期（批2 A4）：clientTitleBar 窗的客户端标题条
# 出专用 kind 与 descriptor。widget 层已在 vendored 上游（ui/title_bar.h/.cpp 的
# component::TitleBar::New/Child/IntoEl，kTitleBarHeight=34，bar 混色
# default_title_bar_background 55% 进窗口背景）；缺的是三件串联：
#   ① shell 侧 kind：spec.h 枚举（插在 UniformList 之后、Registered 之前——
#     shim_test 的漂移警报循环上界是 K::Registered，新值必须落在它前面）、
#     spec.cpp ComponentName case、runtime.cpp ComponentKindOf 名字表（JS 路）、
#     materialize.cpp 分派（children 逐个挂 TitleBar::Child 的 content 槽）；
#   ② component_shell descriptor：新建 title_bar.cpp（kTitleBar：无参构造 +
#     可选 title 方法 + children 走 content 槽；MaterializeTitleBarNode 给
#     materialize.cpp 的专用 kind 分派复用同一构造核心）+ 注册链
#     RegisterTitleBar → families.h 声明 → lib.cpp 家族表；
#   ③ PHP 面（不在本脚本）：Element.php 的 El::titleBar()（kind='title_bar'）+
#     cpp-src/gpui_shim.cpp kKindNames 登记 + shim_test kExpectedUnnamed 11→10。
#
# 幂等：每个文件 marker 在 = 已打，缺哪个补哪个；自检计数（count!=1 即退出）。
# 用法：GPUI_DIR=<gpui-kit-cpp> cmd/patch-title-bar.sh（默认 build/gpui-kit-cpp）

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
MARK="typephp-gpui: TitleBar kind"

SPEC_H="$GPUI_DIR/src/shell/spec.h"
SPEC_CPP="$GPUI_DIR/src/shell/spec.cpp"
RUNTIME="$GPUI_DIR/src/shell/runtime.cpp"
MATER="$GPUI_DIR/src/shell/materialize.cpp"
TB="$GPUI_DIR/src/component_shell/title_bar.cpp"
FAM="$GPUI_DIR/src/component_shell/families.h"
LIB="$GPUI_DIR/src/component_shell/lib.cpp"

for f in "$SPEC_H" "$SPEC_CPP" "$RUNTIME" "$MATER" "$FAM" "$LIB"; do
    [[ -f "$f" ]] || { echo "ERROR: $f not found" >&2; exit 1; }
done

# ── ① spec.h：ComponentKind 枚举加 TitleBar ──
# 锚点：UniformList 行 + 紧随的 frame 注释首行（枚举尾的 Registered 之前；
# shim_test 的 unnamed 循环以 K::Registered 为上界，新值不能排它后面）。
if grep -qF "$MARK" "$SPEC_H"; then
    echo "spec.h: already patched, skip"
else
    cp "$SPEC_H" "$SPEC_H.titlebar.bak"
    python3 - "$SPEC_H" <<'PYEOF'
import sys
path = sys.argv[1]
src = open(path).read()
old = '''    List,
    UniformList,
    // A shared frame around one Input or Textarea and its addons.'''
if src.count(old) != 1:
    sys.exit("spec.h enum anchor count=%d" % src.count(old))
new = '''    List,
    UniformList,
    // typephp-gpui: TitleBar kind — the client-drawn title bar for a window
    // opened with WinOpts::clientTitleBar (widget layer: ui/title_bar.h).
    TitleBar,
    // A shared frame around one Input or Textarea and its addons.'''
src = src.replace(old, new, 1)
open(path, "w").write(src)
print("  spec.h: ComponentKind::TitleBar added")
PYEOF
fi

# ── ② spec.cpp：ComponentName 加 case（debug tree 名字）──
if grep -qF "$MARK" "$SPEC_CPP"; then
    echo "spec.cpp: already patched, skip"
else
    cp "$SPEC_CPP" "$SPEC_CPP.titlebar.bak"
    python3 - "$SPEC_CPP" <<'PYEOF'
import sys
path = sys.argv[1]
src = open(path).read()
old = '''        case ComponentKind::PathStroke:
            return "path stroke";'''
if src.count(old) != 1:
    sys.exit("spec.cpp ComponentName anchor count=%d" % src.count(old))
new = '''        case ComponentKind::PathStroke:
            return "path stroke";
        // typephp-gpui: TitleBar kind (patch-title-bar.sh).
        case ComponentKind::TitleBar:
            return "TitleBar";'''
src = src.replace(old, new, 1)
open(path, "w").write(src)
print("  spec.cpp: ComponentName case added")
PYEOF
fi

# ── ③ runtime.cpp：ComponentKindOf 名字表加 title_bar（JS 路按名建节点）──
if grep -qF "$MARK" "$RUNTIME"; then
    echo "runtime.cpp: already patched, skip"
else
    cp "$RUNTIME" "$RUNTIME.titlebar.bak"
    python3 - "$RUNTIME" <<'PYEOF'
import sys
path = sys.argv[1]
src = open(path).read()
old = '''        {"div", shell::ComponentKind::Div},'''
if src.count(old) != 1:
    sys.exit("runtime.cpp kinds-table anchor count=%d" % src.count(old))
new = '''        {"div", shell::ComponentKind::Div},
        // typephp-gpui: TitleBar kind (patch-title-bar.sh).
        {"title_bar", shell::ComponentKind::TitleBar},'''
src = src.replace(old, new, 1)
open(path, "w").write(src)
print("  runtime.cpp: title_bar added to ComponentKindOf")
PYEOF
fi

# ── ④ materialize.cpp：include + 专用 kind 分派（children 挂 content 槽）──
if grep -qF "$MARK" "$MATER"; then
    echo "materialize.cpp: already patched, skip"
else
    cp "$MATER" "$MATER.titlebar.bak"
    python3 - "$MATER" <<'PYEOF'
import sys
path = sys.argv[1]
src = open(path).read()
inc_anchor = '#include "base/accordion.h"'
if src.count(inc_anchor) != 1:
    sys.exit("materialize include anchor count=%d" % src.count(inc_anchor))
src = src.replace(inc_anchor,
                  '#include "component_shell/families.h"\n' + inc_anchor, 1)
old = '''        childrenConsumed = true;
    } else if (node->component.kind == shell::ComponentKind::HResizable ||'''
if src.count(old) != 1:
    sys.exit("materialize dispatch anchor count=%d" % src.count(old))
new = '''        childrenConsumed = true;
    } else if (node->component.kind == shell::ComponentKind::TitleBar) {
        // typephp-gpui: TitleBar kind (patch-title-bar.sh): the client-drawn
        // title bar for a borderless window. Children materialize first and
        // go through TitleBar::Child (the justify-between content slot) —
        // attaching them to the bar root would fight PadL/ItemsCenter.
        int nkids = (int)node->children.len;
        El** kids =
            nkids > 0 ? (El**)Alloc(cx->a, (int)sizeof(El*) * nkids) : nullptr;
        int made = 0;
        for (shell::SpecId child : node->children) {
            El* c = MaterializeNode(cx, runtime, specs, child, error);
            if (c) kids[made++] = c;
        }
        element = component_shell::title_bar_node_materialize(
            cx, kids, made, error);
        childrenConsumed = true;
    } else if (node->component.kind == shell::ComponentKind::HResizable ||'''
src = src.replace(old, new, 1)
open(path, "w").write(src)
print("  materialize.cpp: TitleBar dispatch branch added")
PYEOF
fi

# ── ④b materialize.cpp：Construct 穷尽 switch 的防御 case ──
# MaterializeNode 的 else-if 链先接 TitleBar（children 挂 content 槽），正常流
# 不会落到 Construct；但 -Werror -Wswitch 要穷尽（批5 PathFill 先例同样列了
# "return Div(cx->a)" 占位——不占位就编译不过）。
CON_MARK="typephp-gpui: TitleBar construct case"
if grep -qF "$CON_MARK" "$MATER"; then
    echo "materialize.cpp (construct case): already patched, skip"
else
    python3 - "$MATER" <<'PYEOF'
import sys
path = sys.argv[1]
src = open(path).read()
old = '''        case shell::ComponentKind::PathFill:
        case shell::ComponentKind::PathStroke:
            return Div(cx->a);'''
if src.count(old) != 1:
    sys.exit("materialize Construct anchor count=%d" % src.count(old))
new = '''        case shell::ComponentKind::PathFill:
        case shell::ComponentKind::PathStroke:
            return Div(cx->a);
        // typephp-gpui: TitleBar construct case (patch-title-bar.sh): the
        // kind is dispatched in MaterializeNode's else-if chain (children go
        // through TitleBar::Child); reaching Construct means that chain
        // missed — build the bare bar rather than an empty box.
        case shell::ComponentKind::TitleBar:
            return component_shell::title_bar_node_materialize(
                cx, nullptr, 0, nullptr);'''
src = src.replace(old, new, 1)
open(path, "w").write(src)
print("  materialize.cpp: Construct exhaustiveness case added")
PYEOF
fi

# ── ⑤ component_shell/title_bar.cpp：descriptor + 专用 kind 材料化 ──
if grep -qF "RegisterTitleBar" "$TB" 2>/dev/null; then
    echo "title_bar.cpp: already present, skip"
else
    cat > "$TB" <<'TITLEBAR_CPP'
// crates/component-shell/src/shell/title_bar.cpp (typephp-gpui: TitleBar kind)
// The client-drawn title bar for a window opened with WinOpts::clientTitleBar.
// The widget layer is the vendored ui/title_bar.{h,cpp}: bar height is
// kTitleBarHeight (34), the surface mixes default_title_bar_background 55%
// into the window background, the unclaimed surface is the drag region
// (ClickWinCaption), and on macOS the traffic lights are Cocoa's so
// WindowControls renders nothing. This file is the shell surface:
//   · title_bar_node_materialize — the dedicated-kind dispatch target that
//     materialize.cpp calls (children already materialized, handed in);
//   · kTitleBar — the registry descriptor (no-arg constructor + optional
//     `title` method; rows are ordinary children through the content slot)
//     so the JS catalog route can build the same bar by name.

#include "component_shell/families.h"
#include "component_shell/support.h"
#include "shell/view.h"
#include "ui/title_bar.h"

namespace gpui::component_shell {

// Shared core: build the bar and hand children through the content slot.
// `error` stays unused — the widget layer cannot fail here, and the PHP
// route reports shape problems before it ever reaches this point.
static El* BuildTitleBar(Ctx* cx, El** children, int count, ShellError* error) {
    (void)error;
    component::TitleBar* bar = component::TitleBar::New(cx);
    for (int i = 0; i < count; i++) {
        if (children[i]) bar->Child(children[i]);
    }
    return bar->IntoEl();
}

// Called from materialize.cpp's kind dispatch. The caller owns child
// materialization (MaterializeNode recursion is static to that TU).
El* title_bar_node_materialize(Ctx* cx, El** children, int count,
                               ShellError* error) {
    return BuildTitleBar(cx, children, count, error);
}

namespace title_bar {

struct TitleBarPayload {
    Str id;
};

// A `title` method call: its own payload type, visited via EachMethod
// (last call wins, the same fold window_effects runs over its Ops).
struct TitleBarText {
    Str text;
};

static El* MaterializeTitleBar(MaterializeRequest* request) {
    const TitleBarPayload* payload =
        request->PayloadAs<TitleBarPayload>();
    if (!payload) {
        return request
            ->Fail(StrL("TitleBar received an incompatible payload"));
    }
    Ctx* cx = request->cx;
    Str title;
    EachMethod<TitleBarText>(request,
                             [&](const TitleBarText& op) { title = op.text; });
    component::TitleBar* bar = nullptr;
    {
        IdScope scope(cx, payload->id);
        bar = component::TitleBar::New(cx);
    }
    if (len(title) > 0) {
        bar->Child(TextEl(cx->a, title));
    }
    El** children = nullptr;
    int count = 0;
    if (!request->TakeChildren(&children, &count)) return nullptr;
    for (int i = 0; i < count; i++) {
        if (!children[i]) continue;
        bar->Child(children[i]);
    }
    return bar->IntoEl();
}

static bool ConstructTitleBar(PayloadBuild* build,
                              const ComponentArgument*, int) {
    build->New<TitleBarPayload>()->id = StrL("title_bar");
    return true;
}

static bool RecordTitle(PayloadBuild* build, const ComponentArgument* args,
                        int count) {
    if (count != 1 || len(args[0].string) == 0)
        return build->Fail(StrL("title expects a non-empty string"));
    build->New<TitleBarText>()->text = build->Dup(args[0].string);
    return true;
}

static constexpr ArgumentDescriptor kTitleArgs[] = {
    {"title", SchemaString()}};
static constexpr const char* kTitleBarDocumentation =
    "The client-drawn title bar for a borderless window: 34px tall, theme-"
    "mixed surface, drag region where no child claimed space.";

static constexpr ConstructorDescriptor kTitleBarConstructors[] = {
    {"TitleBar", {}, &ConstructTitleBar}};
static constexpr MethodDescriptor kTitleBarMethods[] = {
    {"title", kTitleArgs, kTitleBarDocumentation, &RecordTitle}};
static constexpr ComponentDescriptor kTitleBar = {
    "TitleBar", kTitleBarConstructors, kTitleBarMethods,
    kTitleBarDocumentation, &MaterializeTitleBar};

} // namespace title_bar

bool RegisterTitleBar(shell::ComponentRegistry* registry,
                      shell::RegistryError* error) {
    return registry->Register(&title_bar::kTitleBar, error);
}

} // namespace gpui::component_shell
TITLEBAR_CPP
    echo "  title_bar.cpp: descriptor + node materializer created"
fi

# ── ⑥ families.h：声明 RegisterTitleBar + 分派函数 ──
if grep -qF "RegisterTitleBar" "$FAM"; then
    echo "families.h: already patched, skip"
else
    cp "$FAM" "$FAM.titlebar.bak"
    python3 - "$FAM" <<'PYEOF'
import sys
path = sys.argv[1]
src = open(path).read()
anchor = "bool RegisterCommand(shell::ComponentRegistry*, shell::RegistryError*);"
if src.count(anchor) != 1:
    sys.exit("families.h anchor count=%d" % src.count(anchor))
src = src.replace(
    anchor,
    anchor + "\n"
    "bool RegisterTitleBar(shell::ComponentRegistry*, shell::RegistryError*);\n"
    "// typephp-gpui: TitleBar kind — materialize.cpp's dispatch target.\n"
    "El* title_bar_node_materialize(Ctx* cx, El** children, int count,\n"
    "                               ShellError* error);",
    1)
open(path, "w").write(src)
print("  families.h: RegisterTitleBar + node materializer declared")
PYEOF
fi

# ── ⑦ lib.cpp：家族注册链 ──
if grep -qF "RegisterTitleBar" "$LIB"; then
    echo "lib.cpp: already patched, skip"
else
    cp "$LIB" "$LIB.titlebar.bak"
    python3 - "$LIB" <<'PYEOF'
import sys
path = sys.argv[1]
src = open(path).read()
anchor = "    &RegisterWindowEffects,"
if src.count(anchor) != 1:
    sys.exit("lib.cpp anchor count=%d" % src.count(anchor))
src = src.replace(anchor,
                  anchor + "\n    &RegisterTitleBar,  // typephp-gpui: TitleBar kind", 1)
open(path, "w").write(src)
print("  lib.cpp: RegisterTitleBar added to the family chain")
PYEOF
fi

# ── 自检 ──
n_h=$(grep -cF "$MARK" "$SPEC_H" || true)
n_cpp=$(grep -cF "$MARK" "$SPEC_CPP" || true)
n_rt=$(grep -cF "$MARK" "$RUNTIME" || true)
n_ma=$(grep -cF "$MARK" "$MATER" || true)
n_mc=$(grep -cF "$CON_MARK" "$MATER" || true)
n_tb=$(grep -cF "RegisterTitleBar" "$TB" || true)
n_fam=$(grep -cF "RegisterTitleBar" "$FAM" || true)
n_lib=$(grep -cF "RegisterTitleBar" "$LIB" || true)
if [[ "$n_h" != "1" || "$n_cpp" != "1" || "$n_rt" != "1" || "$n_ma" != "1" ||
      "$n_mc" != "1" || "$n_tb" != "1" || "$n_fam" != "1" || "$n_lib" != "1" ]]; then
    echo "ERROR: self-check failed (spec.h=$n_h spec.cpp=$n_cpp runtime=$n_rt" \
         "materialize=$n_ma construct=$n_mc tb=$n_tb fam=$n_fam lib=$n_lib; all want 1)" >&2
    exit 1
fi
echo "Patch complete."
