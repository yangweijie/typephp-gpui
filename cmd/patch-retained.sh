#!/usr/bin/env bash
# cmd/patch-retained.sh — 给 ShellRuntime 暴露公开方法，让 c++20 shim 能创建
# RetainedStore 里的 Input/Slider 实体（materialize 靠 component.handle 取回状态）。
#
# 背景：JS 流程里 component.handle 由快照构建时 impl->retained.CreateInput(...) 赋值；
# 我们绕过 QuickJS 直接建 SpecArena，必须自己创建 retained 实体。但 ShellRuntimeAccess
# / ShellRuntimeImpl 只在 runtime.cpp、不在 amalgam 头里，shim 够不着。本补丁加一层薄
# 公开转发。上游更新后重跑即可；补丁后需重跑 bun cmd/build.ts 生成 amalgam。
#
# 用法：GPUI_DIR=<gpui-kit-cpp> cmd/patch-retained.sh   （默认 vendor/gpui-kit-cpp）

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/vendor/gpui-kit-cpp}"
HDR="$GPUI_DIR/src/shell/runtime.h"
SRC="$GPUI_DIR/src/shell/runtime.cpp"
MARK="CreateRetainedInput"

[[ -f "$HDR" && -f "$SRC" ]] || { echo "ERROR: runtime.h/runtime.cpp not found" >&2; exit 1; }

if grep -qF "$MARK" "$HDR"; then
    echo "runtime.h: already patched, skip"
    exit 0
fi

echo "patching ShellRuntime (public CreateRetainedInput / CreateRetainedSlider)..."
cp "$HDR" "$HDR.bak"; cp "$SRC" "$SRC.bak"

# ── runtime.h：在 Retained(...) 声明后插入两个公开方法 ──
python3 - "$HDR" <<'PYEOF'
import sys
path = sys.argv[1]
src = open(path).read()
anchor = "    shell::RetainedEntry* Retained(shell::EntityHandle handle) const;"
if anchor not in src:
    sys.exit("anchor `Retained(...) const;` not found in runtime.h")
decl = anchor + '''

    // typephp-gpui: thin public forwarders onto the private RetainedStore
    // (impl->retained). The script-binding path creates these during snapshot
    // build; the PHP firewall builds the arena directly, so it must create
    // retained entities itself and store the returned handle in Component::handle.
    shell::EntityHandle CreateRetainedInput(bool textarea, Str placeholder,
                                            Str value, int rows, App* app,
                                            EntityId owner, void* application);
    shell::EntityHandle CreateRetainedSlider(float min, float max, float step,
                                             App* app, EntityId owner,
                                             void* application);'''
src = src.replace(anchor, decl, 1)
open(path, "w").write(src)
print("  runtime.h: declarations added")
PYEOF

# ── runtime.cpp：在 ShellRuntimeAccess 结构后插入两个定义（此处 impl 完整可见）──
python3 - "$SRC" <<'PYEOF'
import sys
path = sys.argv[1]
src = open(path).read()
# 锚定 ShellRuntimeAccess 结构闭合（其 Control 方法返回 runtime->control 的那段）。
anchor = '''    static ShellRuntimeControl* Control(ShellRuntime* runtime) {
        return runtime ? runtime->control : nullptr;
    }
};'''
if anchor not in src:
    sys.exit("ShellRuntimeAccess struct end anchor not found in runtime.cpp")
defs = anchor + r'''

// typephp-gpui: public forwarders onto impl->retained (see runtime.h decl).
shell::EntityHandle ShellRuntime::CreateRetainedInput(bool textarea,
                                                      Str placeholder, Str value,
                                                      int rows, App* app,
                                                      EntityId owner,
                                                      void* application) {
    ShellRuntimeImpl* impl = ShellRuntimeAccess::Impl(this);
    if (!impl) return 0;
    return impl->retained.CreateInput(textarea, placeholder, value, rows, app,
                                      owner, application);
}

shell::EntityHandle ShellRuntime::CreateRetainedSlider(float min, float max,
                                                       float step, App* app,
                                                       EntityId owner,
                                                       void* application) {
    ShellRuntimeImpl* impl = ShellRuntimeAccess::Impl(this);
    if (!impl) return 0;
    SliderValue initial = SliderSingle(min);
    return impl->retained.CreateSlider(min, max, step, SliderScale::Linear,
                                       initial, app, owner, application);
}'''
src = src.replace(anchor, defs, 1)
open(path, "w").write(src)
print("  runtime.cpp: definitions added")
PYEOF

echo "Patch complete. Regenerate amalgam: (cd $GPUI_DIR && bun cmd/build.ts -rel hello_world)"
