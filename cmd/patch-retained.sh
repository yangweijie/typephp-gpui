#!/usr/bin/env bash
# cmd/patch-retained.sh — 给 ShellRuntime 暴露公开方法，让 c++20 shim 能创建
# RetainedStore 里的 Input/Slider/Otp 实体（materialize 靠 component.handle 取回状态）。
#
# 背景：JS 流程里 component.handle 由快照构建时 impl->retained.CreateInput(...) 赋值；
# 我们绕过 QuickJS 直接建 SpecArena，必须自己创建 retained 实体。但 ShellRuntimeAccess
# / ShellRuntimeImpl 只在 runtime.cpp、不在 amalgam 头里，shim 够不着。本补丁加一层薄
# 公开转发。上游更新后重跑即可；补丁后需重跑 bun cmd/build.ts 生成 amalgam。
#
# 幂等做法（踩过坑，别改回"整块判在不在"）：**每个符号各自**按"完整声明文本"查在否，
# 不在才插，锚点是上游原文；最后自检每个符号恰好出现一次。
# 之前用 `grep MARK` 当整块闸门，闸门一开就把整块重复插进去，插到 CreateRetainedSlider
# 的中间，amalgam 直接编译不过——而且"半补丁"还能通过补丁计数。
#
# 用法：GPUI_DIR=<gpui-kit-cpp> cmd/patch-retained.sh   （默认 build/gpui-kit-cpp（upstream/ 的沙箱副本））

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
HDR="$GPUI_DIR/src/shell/runtime.h"
SRC="$GPUI_DIR/src/shell/runtime.cpp"

[[ -f "$HDR" && -f "$SRC" ]] || { echo "ERROR: runtime.h/runtime.cpp not found" >&2; exit 1; }

python3 - "$HDR" "$SRC" <<'PYEOF'
import sys

hdr_path, src_path = sys.argv[1], sys.argv[2]

# 上游原文里的锚点：Retained() const 这一行是公开转发的落点（它后面就是私有成员）。
HDR_ANCHOR = "    shell::RetainedEntry* Retained(shell::EntityHandle handle) const;\n"
# runtime.cpp 里 ShellRuntimeAccess 结构闭合处：impl 在这里完整可见，定义只能落在这之后。
SRC_ANCHOR = ("    static ShellRuntimeControl* Control(ShellRuntime* runtime) {\n"
              "        return runtime ? runtime->control : nullptr;\n"
              "    }\n"
              "};\n")

HEADER_NOTE = ("// typephp-gpui: thin public forwarders onto the private RetainedStore\n"
               "// (impl->retained). The script-binding path creates these during snapshot\n"
               "// build; the PHP firewall builds the arena directly, so it must create\n"
               "// retained entities itself and store the returned handle in Component::handle.\n")

# (符号名, 声明, 定义)。逐个幂等：已存在就跳过，不重复插。
DECLS = [
    ("CreateRetainedInput",
     "    shell::EntityHandle CreateRetainedInput(bool textarea, Str placeholder,\n"
     "                                            Str value, int rows, App* app,\n"
     "                                            EntityId owner, void* application);\n"),
    ("CreateRetainedSlider",
     "    shell::EntityHandle CreateRetainedSlider(float min, float max, float step,\n"
     "                                             App* app, EntityId owner,\n"
     "                                             void* application);\n"),
    ("CreateRetainedOtp",
     "    shell::EntityHandle CreateRetainedOtp(int length, Str value, bool masked,\n"
     "                                          App* app, EntityId owner,\n"
     "                                          void* application);\n"),
]
DEFS = [
    ("CreateRetainedInput",
     "shell::EntityHandle ShellRuntime::CreateRetainedInput(bool textarea,\n"
     "                                                      Str placeholder, Str value,\n"
     "                                                      int rows, App* app,\n"
     "                                                      EntityId owner,\n"
     "                                                      void* application) {\n"
     "    ShellRuntimeImpl* impl = ShellRuntimeAccess::Impl(this);\n"
     "    if (!impl) return 0;\n"
     "    return impl->retained.CreateInput(textarea, placeholder, value, rows, app,\n"
     "                                      owner, application);\n"
     "}\n"),
    ("CreateRetainedSlider",
     "shell::EntityHandle ShellRuntime::CreateRetainedSlider(float min, float max,\n"
     "                                                       float step, App* app,\n"
     "                                                       EntityId owner,\n"
     "                                                       void* application) {\n"
     "    ShellRuntimeImpl* impl = ShellRuntimeAccess::Impl(this);\n"
     "    if (!impl) return 0;\n"
     "    SliderValue initial = SliderSingle(min);\n"
     "    return impl->retained.CreateSlider(min, max, step, SliderScale::Linear,\n"
     "                                       initial, app, owner, application);\n"
     "}\n"),
    # Otp 的 retained：materialize 的 OtpInput 分支要 RetainedKind::Otp + Entity<OtpState>
    # （materialize.cpp:1980）。上游 RetainedStore::CreateOtp 本来就有，只是没公开口。
    ("CreateRetainedOtp",
     "shell::EntityHandle ShellRuntime::CreateRetainedOtp(int length, Str value,\n"
     "                                                    bool masked, App* app,\n"
     "                                                    EntityId owner,\n"
     "                                                    void* application) {\n"
     "    ShellRuntimeImpl* impl = ShellRuntimeAccess::Impl(this);\n"
     "    if (!impl) return 0;\n"
     "    return impl->retained.CreateOtp(length, value, masked, app, owner,\n"
     "                                    application);\n"
     "}\n"),
]

def insert_after(path, anchor, text, note=None):
    s = open(path).read()
    if anchor not in s:
        sys.exit(f"{path}: 锚点丢了（上游改版？），拒绝盲改")
    at = s.index(anchor) + len(anchor)
    if note and note not in s[:at]:
        s = s[:at] + note + text + s[at:]
    else:
        s = s[:at] + text + s[at:]
    open(path, "w").write(s)

hdr = open(hdr_path).read()
added_h = 0
for sym, decl in DECLS:
    if decl in hdr:
        continue
    insert_after(hdr_path, HDR_ANCHOR, decl, HEADER_NOTE)
    hdr = open(hdr_path).read()
    added_h += 1

src = open(src_path).read()
added_c = 0
for sym, body in DEFS:
    if sym + "(bool textarea" in src or (sym + "(") in src.split(SRC_ANCHOR)[-1]:
        pass  # 定义段里已有；下面用精确文本再判一次
    if body in src:
        continue
    insert_after(src_path, SRC_ANCHOR, "\n" + body)
    src = open(src_path).read()
    added_c += 1

# 自检：每个符号在头里声明一次、在 cpp 里定义一次。半补丁或重复插都在这里红。
hdr = open(hdr_path).read()
src = open(src_path).read()
for sym, _ in DECLS:
    if hdr.count("shell::EntityHandle " + sym) != 1:
        sys.exit(f"{sym}: 头文件里出现 {hdr.count('shell::EntityHandle ' + sym)} 次（应为 1）")
    if src.count("ShellRuntime::" + sym) != 1:
        sys.exit(f"{sym}: runtime.cpp 里定义 {src.count('ShellRuntime::' + sym)} 次（应为 1）")

print(f"  runtime.h/cpp: 新增 {added_h} 条声明 / {added_c} 条定义，三个转发口自检通过")
PYEOF

echo "Patch complete. Regenerate amalgam: (cd $GPUI_DIR && bun cmd/build.ts -rel hello_world)"
