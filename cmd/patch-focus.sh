#!/usr/bin/env bash
# cmd/patch-focus.sh — 给 ShellRuntime 暴露 CreateRetainedFocus，让 c++20 shim 为
# DatePicker（component.handle）/ Select·Combobox（focus/contentFocus 方法 op）创建
# 常驻 Focus 实体。与 patch-retained.sh 同构、独立幂等。补丁后须重跑 bun cmd/build.ts。
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
HDR="$GPUI_DIR/src/shell/runtime.h"
SRC="$GPUI_DIR/src/shell/runtime.cpp"
MARK="CreateRetainedFocus"

[[ -f "$HDR" && -f "$SRC" ]] || { echo "ERROR: runtime.h/runtime.cpp not found" >&2; exit 1; }

# 幂等：声明与定义都在才算已打。缺哪个补哪个。
grep -qF "$MARK" "$HDR" || {
    echo "runtime.h: adding decl"
    python3 - "$HDR" <<'PYEOF'
import sys
p = sys.argv[1]
s = open(p).read()
anchor = "    shell::RetainedEntry* Retained(shell::EntityHandle handle) const;"
if anchor not in s:
    sys.exit("Retained decl anchor missing")
s = s.replace(anchor, anchor + "\n    shell::EntityHandle CreateRetainedFocus(App* app, EntityId owner, void* application);", 1)
open(p, "w").write(s)
PYEOF
}

grep -qF "ShellRuntime::$MARK" "$SRC" || {
    echo "runtime.cpp: adding def"
    python3 - "$SRC" <<'PYEOF'
import sys
p = sys.argv[1]
s = open(p).read()
# 锚点：ShellRuntimeAccess 结构闭合（Control 方法返回 runtime->control 那段之后）。
anchor = "    static ShellRuntimeControl* Control(ShellRuntime* runtime) {\n        return runtime ? runtime->control : nullptr;\n    }\n};"
if anchor not in s:
    sys.exit("ShellRuntimeAccess end anchor missing")
body = anchor + "\n\nshell::EntityHandle ShellRuntime::CreateRetainedFocus(App* app, EntityId owner, void* application) {\n    ShellRuntimeImpl* impl = ShellRuntimeAccess::Impl(this);\n    if (!impl) return 0;\n    return impl->retained.CreateFocus(app, owner, application);\n}"
s = s.replace(anchor, body, 1)
open(p, "w").write(s)
PYEOF
}

echo "done. Regenerate amalgam: (cd $GPUI_DIR && bun cmd/build.ts -rel hello_world)"
