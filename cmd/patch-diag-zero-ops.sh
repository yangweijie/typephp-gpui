#!/usr/bin/env bash
# cmd/patch-diag-zero-ops.sh — Phase 32 A2 配套诊断：宿主 batch 零 op 可观测性加固。
#
# 在 materialize.cpp 的 ops 循环（ApplyStyleNode 函数内）之后加一条 logf 告警：
# 当 Div/HFlex/VFlex 裸节点的 ops 数为 0 时输出诊断信息。这是 PushOp 静默失败
# （gpui_shim_services.inc 的 param_style/method 返回值检查）的下游症状——
# 如果 PHP 处理器创建了 spec 但 style ops 没落进宿主 batch（CheckLive 失败），
# 这里就能看到"零 op"的 Div 节点，配合上游 noteError 定位断链点。
#
# 幂等：marker 在 = 已打；count!=1 即退出。
# 用法：GPUI_DIR=<gpui-kit-cpp> cmd/patch-diag-zero-ops.sh（默认 build/gpui-kit-cpp）

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
FILE="$GPUI_DIR/src/shell/materialize.cpp"
MARK="typephp-gpui: zero-op diagnostic"

[[ -f "$FILE" ]] || { echo "ERROR: materialize.cpp not found" >&2; exit 1; }

if grep -qF "$MARK" "$FILE"; then
    [[ $(grep -cF 'Div/HFlex/VFlex node has zero ops' "$FILE") -eq 1 ]] \
        || { echo "ERROR: zero-op diagnostic marker exists but logf text is incomplete" >&2; exit 1; }
    echo "materialize.cpp: zero-op diagnostic already patched, skip"
    exit 0
fi

echo "patching materialize.cpp for zero-op diagnostic..."
cp "$FILE" "$FILE.diagzeroops.bak"

python3 - "$FILE" "$MARK" <<'PYEOF'
import sys
path, mark = sys.argv[1], sys.argv[2]
src = open(path).read()

# 锚点：ops 循环的收尾 + border color 注释的开头。
# 这段文本在 materialize.cpp 中唯一（ApplyStyleNode 函数内）。
anchor = (
    "            if (!ApplyParam(element, op, error) && error && !error->IsSet())\n"
    "                ShellErrorSet(error, fmt(\"invalid style call `%s`\", op.name));\n"
    "        }\n"
    "    }\n"
    "    // GPUI draws an unset border colour as transparent: `div().border(1)`\n"
)
if src.count(anchor) != 1:
    sys.exit("ApplyStyleNode ops-loop anchor count=%d" % src.count(anchor))

inject = (
    "            if (!ApplyParam(element, op, error) && error && !error->IsSet())\n"
    "                ShellErrorSet(error, fmt(\"invalid style call `%s`\", op.name));\n"
    "        }\n"
    "    }\n"
    "    // " + mark + " — Div/HFlex/VFlex bare node with zero ops:\n"
    "    // style ops may not have landed in the host batch (PushOp CheckLive\n"
    "    // failure in gpui_shim_services.inc). Diagnostic only; does not\n"
    "    // affect rendering.\n"
    "    if (node->ops.len == 0 &&\n"
    "        (node->component.kind == shell::ComponentKind::Div ||\n"
    "         node->component.kind == shell::ComponentKind::HFlex ||\n"
    "         node->component.kind == shell::ComponentKind::VFlex)) {\n"
    "        logf(\"shell: Div/HFlex/VFlex node has zero ops — \"\n"
    "             \"style ops may not have landed in host batch\\n\");\n"
    "    }\n"
    "    // GPUI draws an unset border colour as transparent: `div().border(1)`\n"
)
src = src.replace(anchor, inject, 1)
open(path, "w").write(src)
print("materialize.cpp: zero-op diagnostic added after ops loop")
PYEOF

[[ $(grep -c 'Div/HFlex/VFlex node has zero ops' "$FILE") -eq 1 ]] \
    || { echo "ERROR: zero-op diagnostic logf not found (应恰好一处）" >&2; exit 1; }
echo "materialize.cpp: zero-op diagnostic marker verified"
