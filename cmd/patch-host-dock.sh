#!/usr/bin/env bash
# Host-built specs need Dock handles; RetainedStore owns their full cleanup.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
HDR="$GPUI_DIR/src/shell/runtime.h"
SRC="$GPUI_DIR/src/shell/runtime.cpp"

python3 - "$HDR" "$SRC" <<'PYEOF'
import re
import sys
from pathlib import Path

paths = [Path(p) for p in sys.argv[1:]]
if not all(p.is_file() for p in paths):
    sys.exit("ERROR: runtime.h/runtime.cpp not found")
texts = [p.read_text() for p in paths]
anchors = [
    "    shell::RetainedEntry* Retained(shell::EntityHandle handle) const;\n",
    "shell::RetainedEntry* ShellRuntime::Retained(shell::EntityHandle handle) const {\n"
    "    return impl->retained.Find(handle);\n"
    "}\n",
]
patches = [
    ("CreateHostDock", [
        "    shell::EntityHandle CreateHostDock(Str id, Ctx* cx, EntityId owner, void* application);\n",
        "shell::EntityHandle ShellRuntime::CreateHostDock(Str id, Ctx* cx, EntityId owner, void* application) {\n"
        "    ShellRuntimeImpl* impl = ShellRuntimeAccess::Impl(this);\n"
        "    if (!impl) return 0;\n"
        "    return impl->retained.CreateDock(id, false, 0, cx, owner, application);\n"
        "}\n",
    ]),
    ("ReleaseHostDock", [
        "    bool ReleaseHostDock(shell::EntityHandle handle);\n",
        "bool ShellRuntime::ReleaseHostDock(shell::EntityHandle handle) {\n"
        "    ShellRuntimeImpl* impl = ShellRuntimeAccess::Impl(this);\n"
        "    if (!impl) return false;\n"
        "    shell::RetainedEntry* entry = impl->retained.Find(handle);\n"
        "    if (!entry || entry->kind != shell::RetainedKind::Dock) return false;\n"
        "    return impl->retained.Release(handle);\n"
        "}\n",
    ]),
]


def check(text, index, symbol, block, required=False):
    prefix = r"ShellRuntime\s*::\s*" if index else ""
    count = len(re.findall(r"\b" + prefix + symbol + r"\s*\(", text))
    if count > 1 or (count == 1 and text.count(block) != 1):
        sys.exit(f"ERROR: {paths[index]}: {symbol} duplicate or old/incomplete signature/body")
    if required and count != 1:
        sys.exit(f"ERROR: {paths[index]}: {symbol} missing")
    return count


# Validate both files before writing either; an old signature must not hide behind a marker.
updated = list(texts)
added = [0, 0]
for index, (text, anchor) in enumerate(zip(texts, anchors)):
    if text.count(anchor) != 1:
        sys.exit(f"ERROR: {paths[index]}: Retained anchor missing or duplicated")
    missing = []
    for symbol, blocks in patches:
        if not check(text, index, symbol, blocks[index]):
            missing.append(blocks[index])
    added[index] = len(missing)
    if missing:
        separator = "\n" if index else ""
        updated[index] = text.replace(anchor, anchor + "\n" + separator.join(missing), 1)

for index, text in enumerate(updated):
    for symbol, blocks in patches:
        check(text, index, symbol, blocks[index], required=True)
for path, before, after in zip(paths, texts, updated):
    if before != after:
        path.write_text(after)
for index, path in enumerate(paths):
    text = path.read_text()
    for symbol, blocks in patches:
        check(text, index, symbol, blocks[index], required=True)
print(f"runtime.h/cpp: added {added[0]} declarations / {added[1]} definitions; host Dock checks passed")
PYEOF

python3 - "$GPUI_DIR/src/base/dock_state.cpp" <<'PYEOF'
from pathlib import Path
import sys
path = Path(sys.argv[1])
text = path.read_text()
old = """    for (int i = 0; i < s->nodes.len; i++) {
        s->nodes[i] = DockNode{};
    }"""
new = """    // typephp-gpui: release Dock layout node arrays before replacing the tree.
    for (int i = 0; i < s->nodes.len; i++) {
        VecReset(s->nodes[i].child);
        VecReset(s->nodes[i].size);
        VecReset(s->nodes[i].panel);
        s->nodes[i] = DockNode{};
    }"""
if text.count(new) == 0:
    if text.count(old) != 1:
        sys.exit("ERROR: DockLoad node reset anchor missing or duplicated")
    text = text.replace(old, new, 1)
    path.write_text(text)
if text.count(new) != 1:
    sys.exit("ERROR: DockLoad array cleanup is incomplete")
print("dock_state.cpp: node arrays released before layout replacement")
PYEOF

python3 - "$GPUI_DIR/src/shell/dock.h" "$GPUI_DIR/src/shell/dock.cpp" <<'PYEOF'
from pathlib import Path
import sys
header, source = [Path(p) for p in sys.argv[1:]]
h, s = header.read_text(), source.read_text()
old_h = "    ShellDockChrome chrome = {};\n    DockRenderer renderer = {};"
new_h = "    ShellDockChrome chrome = {};\n    const DockRenderer* hostRenderer = nullptr;\n    DockRenderer renderer = {};"
old_s = """const DockRenderer* ScriptDockSkin::Renderer() {
    renderer.data = this;
    return &renderer;
}"""
new_s = """const DockRenderer* ScriptDockSkin::Renderer() {
    // typephp-gpui: delegate the complete native Dock renderer.
    if (hostRenderer) return hostRenderer;
    renderer.data = this;
    return &renderer;
}"""
for text, old, new in [(h, old_h, new_h), (s, old_s, new_s)]:
    if text.count(new) == 0 and text.count(old) != 1:
        sys.exit("ERROR: native Dock renderer anchor missing or duplicated")
h = h if h.count(new_h) == 1 else h.replace(old_h, new_h, 1)
s = s if s.count(new_s) == 1 else s.replace(old_s, new_s, 1)
if h.count(new_h) != 1 or s.count(new_s) != 1:
    sys.exit("ERROR: native Dock renderer delegation is incomplete")
header.write_text(h)
source.write_text(s)
print("dock.h/cpp: complete native renderer delegation verified")
PYEOF
