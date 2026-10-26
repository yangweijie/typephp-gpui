#!/usr/bin/env bash
# cmd/patch-resize-handle.sh — #94 的分辨仪表：分隔条拖拽到底断在哪一步。
#
# 为什么要有它：命中带在表里、mouseDown/onDragMove 监听都在（hitProbe 的 down=1 drag=1），
# 合成拖按下后等三帧再移动也没有 resize ⇒ 只剩两种可能：handle 的 mouseDown 监听根本没被派发，
# 或者派发了但 `ElementStateEntity` 的跨帧状态没留住、`ResizableState::dragging` 从没被设上。
# 三个计数器正好把这条链切成三段：handle 的 OnDown → 组的 OnHandleDown → 组的 OnHandleDrag。
#
# 只在该环境变量存在时输出（C++ 侧 getenv 可用；nano 侧不可用是另一回事），默认零噪声。
# 用法：GPUI_RESIZE_DEBUG=1 ./examples/layout/build/layout --drag-probe-staged 2>&1 | grep GPUI-RESIZE

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
FILE="$GPUI_DIR/src/base/resizable.cpp"
MARK="typephp-gpui: resize probe"

[[ -f "$FILE" ]] || { echo "ERROR: resizable.cpp not found" >&2; exit 1; }

# 幂等按**文件**各自判，不整脚本早退：本脚本管四份文件，resizable.cpp 已补时
# materialize.cpp 的宿主路由 hunks 仍可能缺（2026-10-27 实测：vendored 真源漏了
# materialize 那一格，sandbox 侧的 hunk 又被同步覆盖，doctor 才抓到）。早退会把
# 后面三份的幂等补丁一起跳过，留下半补丁树。
RESIZE_SKIP=0
if grep -qF "$MARK" "$FILE"; then
    echo "resizable.cpp: already patched, skip"
    RESIZE_SKIP=1
fi

if [[ "$RESIZE_SKIP" == "0" ]]; then
grep -qF '#include "base/resizable.h"' "$FILE" \
    || { echo "ERROR: resizable.cpp 的 include 区变了" >&2; exit 1; }

python3 - "$FILE" "$MARK" <<'PYEOF'
import re, sys

path, mark = sys.argv[1], sys.argv[2]
src = open(path).read()

includes = '#include "base/resizable.h"'
assert src.count(includes) == 1
src = src.replace(includes,
                  '#include <cstdio>\n#include <cstdlib>\n' + includes, 1)
src = src.replace('#include <cstdio>', '// %s\n#include <cstdio>' % mark, 1)

# 用正则吃掉换行与缩进：源码里续行对齐的空白数改过一次就对不上了。
targets = [
    (r"void ResizableState::OnHandleDown\(ResizableState\* self, Ctx\* cx,\s*\n\s*const MouseDownEvent\* ev, int64_t ix\) \{", "group-down"),
    (r"void ResizableState::OnHandleDrag\(ResizableState\* self, Ctx\* cx,\s*\n\s*const DragMoveEvent\* ev\) \{", "group-drag"),
    (r"void ResizableState::OnHandleUp\(ResizableState\* self, Ctx\* cx,\s*\n\s*const MouseUpEvent\*\) \{", "group-up"),
    (r"void SharedHandleState::OnDown\(SharedHandleState\* self, Ctx\* cx,\s*\n\s*const MouseDownEvent\* ev\) \{", "handle-down"),
]
for pat, tag in targets:
    line = '\n    if (getenv("GPUI_RESIZE_DEBUG")) std::fprintf(stderr, "GPUI-RESIZE %s\\n");' % tag
    src, n = re.subn(pat, lambda m: m.group(0) + line, src, count=2)
    assert n == 1, "anchor count=%d for %s" % (n, tag)

open(path, "w").write(src)
PYEOF

grep -qF "$MARK" "$FILE" || { echo "ERROR: 标记没落上" >&2; exit 1; }
[[ $(grep -c 'GPUI-RESIZE' "$FILE") -eq 4 ]] \
    || { echo "ERROR: 计数器不是四处（$(grep -c 'GPUI-RESIZE' "$FILE")）" >&2; exit 1; }
fi

# ── 第二处：on_resize 的宿主路由（#94 的正题）──────────────────────────────
# materializer 把组的 resize 绑给 ScriptView::OnResize，而宿主视图是 PhpView{Box2*}。
# 按 ScriptView 读 self->runtime 是越界（与 MenuBar 那次同形），事件于是静默丢。
# 这里改成"有 HostOnResize 就用它，没有就退回原路"——host 钩子由 shim 强定义，
# 上游自己链接 gpui.o 时是 weak 未定义（null），story 行为不变。
MAT="$GPUI_DIR/src/shell/materialize.cpp"
MMARK="typephp-gpui: host resize route"
[[ -f "$MAT" ]] || { echo "ERROR: materialize.cpp not found" >&2; exit 1; }
if ! grep -qF "$MMARK" "$MAT"; then
python3 - "$MAT" "$MMARK" <<'PYEOF'
import sys
path, mark = sys.argv[1], sys.argv[2]
src = open(path).read()

# 钩子用**函数指针变量**而不是弱符号：Darwin 的 clang/ld 对"块作用域 weak 声明"和
# 文件作用域 weak 引用都不产生可取空的 weak reference（#94 实测两次 undefined），
# 而一个定义为 nullptr 的变量天然可空，shim 启动时把它装上即可。
ns = "namespace gpui {"
assert src.count(ns) == 1, "namespace anchor count=%d" % src.count(ns)
decl = ns + """
// %s
extern "C" void (*HostResizeHook)(void*, Ctx*, const ResizablePanelEvent*, int64_t) = nullptr;""" % mark
src = src.replace(ns, decl, 1)

anchor = """        if (behavior.onResize) {
            group->OnResize(
                Listen(cx, &ScriptView::OnResize, (int64_t)behavior.onResize));
        }"""
assert src.count(anchor) == 1, "materialize anchor count=%d" % src.count(anchor)
new = """        // %s
        if (behavior.onResize) {
            if (HostResizeHook) {
                group->OnResize(
                    Listen(cx, HostResizeHook, (int64_t)behavior.onResize));
            } else {
                group->OnResize(
                    Listen(cx, &ScriptView::OnResize, (int64_t)behavior.onResize));
            }
        }""" % mark
open(path, "w").write(src.replace(anchor, new, 1))
PYEOF
fi
grep -qF "$MMARK" "$MAT" || { echo "ERROR: materialize 标记没落上" >&2; exit 1; }

# ── 第三处：把分隔条的**抓取带**加宽（只改命中区，画线仍是 1px）──────────────
# 9pt 的带（1px 线 + 两侧各 4pt）真人不友好：实测三次物理拖都按不到 handle
#（appkitDeliveries 50/151 有交互，handle-down 仍 0）。24pt 两侧 ⇒ 49pt 的带。
HDR="$GPUI_DIR/src/base/resizable.h"
HMARK="typephp-gpui: wide grab band"
[[ -f "$HDR" ]] || { echo "ERROR: resizable.h not found" >&2; exit 1; }
if ! grep -qF "$HMARK" "$HDR"; then
python3 - "$HDR" "$HMARK" <<'PYEOF'
import sys
path, mark = sys.argv[1], sys.argv[2]
src = open(path).read()
anchor = "const float kResizeHandlePadding = 4.f;"
assert src.count(anchor) == 1, "padding anchor count=%d" % src.count(anchor)
new = "// %s\nconst float kResizeHandlePadding = 24.f;" % mark
open(path, "w").write(src.replace(anchor, new, 1))
PYEOF
fi
grep -qF "$HMARK" "$HDR" || { echo "ERROR: resizable.h 标记没落上" >&2; exit 1; }

# ── 第四处：物理按下的坐标（#94 的最后一格）────────────────────────────
# 376 条 AppKit 投递 + `handle-down=0` ⇒ 事件到了但没命中 handle；打印按下点即可分辨
# "手没按到带"与"坐标换算错"。只在 GPUI_RESIZE_DEBUG 下输出。
WIN="$GPUI_DIR/src/gpui/window_mac.cpp"
WMARK="typephp-gpui: resize down probe"
[[ -f "$WIN" ]] || { echo "ERROR: window_mac.cpp not found" >&2; exit 1; }
if ! grep -qF "$WMARK" "$WIN"; then
python3 - "$WIN" "$WMARK" <<'PYEOF'
import sys
path, mark = sys.argv[1], sys.argv[2]
src = open(path).read()
anchor = """- (void)mouseDown:(NSEvent*)event {
    NSPoint p = [self gpuiPoint:event];
    float x = (float)p.x;
    float y = (float)p.y;"""
assert src.count(anchor) == 1, "mouseDown anchor count=%d" % src.count(anchor)
new = anchor + """
    // %s
    if (getenv("GPUI_RESIZE_DEBUG")) fprintf(stderr, "GPUI-RESIZE down %%.0f,%%.0f\\n", x, y);""" % mark
open(path, "w").write(src.replace(anchor, new, 1))
PYEOF
fi
grep -qF "$WMARK" "$WIN" || { echo "ERROR: window_mac.cpp 标记没落上" >&2; exit 1; }
echo "patch-resize-handle.sh OK"
