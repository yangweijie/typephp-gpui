#!/usr/bin/env bash
# cmd/patch-element-arg.sh — 给桥加"元素实参"通道：registered_method 的 SchemaElement
# 实参（如 EmptyHeader 的 media/title/description——上游 empty.cpp 的
# RecordSlot 方法表吃 SchemaElement，MaterializeHeader 用 ResolveElement 解析）
# 今天在 shim 侧断：toComponentArg 的 Element 分支只会把一个数字当 SpecId
# （gpui_shim.cpp），而 PHP 侧给不出合法实参（发字符串 = SpecId 0 = native 段错误）。
#
# 机制（顺序是关键）：emitTree 的 op 循环里，registered_method 在 children 之前
# 转换——而 slot 通道在转换前先把槽子元素 emitTree 出来（gpui_bridge.cc 的 slot
# 分支）。本补丁按 slot 同形状加一条 element_arg op：**先 emitTree 子元素拿 SpecId，
# 再以该 SpecId 当实参发 registered_method**。这样"子元素先建"成立，SchemaElement
# 分支的 (SpecId)ga.num 拿到的是真 spec——EmptyHeader 的 media/title/description
# 由此可喂，StatusBar 的 content 类实参（若上游改成 SchemaElement）同享。
#
# 幂等：marker 在 + opKind 分支在 = 已打；自检计数。用法：cmd/patch-element-arg.sh
# （补丁 cpp-src/gpui_bridge.cc，走 build-shim.sh 重编 libgpui_shim.a）。

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BRIDGE="$ROOT/cpp-src/gpui_bridge.cc"
MARK="typephp-gpui: element arg bridge"

[[ -f "$BRIDGE" ]] || { echo "ERROR: gpui_bridge.cc not found" >&2; exit 1; }

if grep -qF "$MARK" "$BRIDGE"; then
    echo "gpui_bridge.cc: already patched, skip"
    exit 0
fi

echo "patching gpui_bridge.cc with the element-arg op..."
cp "$BRIDGE" "$BRIDGE.elementarg.bak"

python3 - "$BRIDGE" <<'PYEOF'
import sys
path = sys.argv[1]
src = open(path).read()

anchor = '''            } else if (opKind == "registered_method") {'''
if src.count(anchor) != 1:
    sys.exit("registered_method anchor count=%d" % src.count(anchor))

new = '''            } else if (opKind == "element_arg") {
                // ''' + "typephp-gpui: element arg bridge" + '''. A registered_method
                // whose SchemaElement argument is another element of the tree:
                // emit it first (the slot branch's shape — children before the
                // parent's ops), then pass its SpecId as the element argument.
                // The shim's Element branch casts ga.num to SpecId; the child's
                // spec is valid because emitTree just created it.
                Variant argV = op.get("arg");
                if (argV.isArray()) {
                    uint32_t child = emitTree(win, argV.toArray());
                    std::vector<GpuiArg> eargs(1);
                    eargs[0].kind = GPUI_ARG_NUM;
                    eargs[0].num = (double)child;
                    std::string ename = strOf(op.get("name"));
                    gpui_shim_registered_method(win, spec, ename.c_str(),
                                                eargs.data(), (int)eargs.size());
                }
''' + anchor
src = src.replace(anchor, new, 1)
open(path, "w").write(src)
print("  gpui_bridge.cc: element_arg op branch added")
PYEOF

nMark=$(grep -cF "$MARK" "$BRIDGE" || true)
nBranch=$(grep -cF 'opKind == "element_arg"' "$BRIDGE" || true)
if [[ "$nMark" != "1" || "$nBranch" != "1" ]]; then
    echo "ERROR: self-check failed (marker=$nMark want 1, branch=$nBranch want 1)" >&2
    exit 1
fi
echo "Patch complete."
echo "Rebuild the shim: bash cmd/build-shim.sh"
