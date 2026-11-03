#!/usr/bin/env bash
# cmd/patch-window-layers.sh — component 层的 LayersOf 不再 Panic 打死宿主，改成一次性告警 + 返回 null。
#
# 为什么要这一支（2026-11-02，真人第一轮验收报"点几下就崩"之后扫出来的）：
# `LayersOf()`（src/ui/window_ext.cpp:37）在窗口根没有 component 的 WindowState 插件时直接
# `Panic(...)` ⇒ SIGABRT。而我们的窗口根**就是**没有那层插件的：shim 走
# `WindowOpenView(ShellRoot)`（cpp-src/gpui_shim.cpp:1128，注释写明不能换 KitOpenWindow——
# 多包一层 Base Root 会让 `ShellRootOf` 的 `window->root == state->root` 判定失败）。
# 结果：树 3 里 11 颗可点控件有 6 颗一点就打死进程（Dialog / AlertDialog / Sheet /
# PopupMenu / ContextMenu 那一排），另有 1 颗 SIGSEGV。
#
# 语义与边界：
#   * 改成"告警一次 + 返回 null"**不是**新增兜底：这个文件里 14 个调用点本来就全写了
#     `if (!l) return ...`（WindowOpenDialog / DialogCount / CloseTopDialog / DismissSheet /
#     HasSheet / Notification* 那一族），null 是它们已经处理好的取值。改的只是"要不要把宿主带走"。
#   * 因此这些 kinds 变成**明确不生效**（stderr 有原因），而不是"崩"也不是"静默没反应"。
#     等价能力在本宿主走 shell 层：`App::openDialog()` / `App::openSheet()` / `App::notify()`
#     （selftest 第 5/11/18 步就是它们的 route-green 证据）。
#   * 告警只打一次：每帧都可能命中这条路径，刷屏会把真读数盖掉。
#
# 补丁后须重跑 amalgam 与 shim：php bin/gpui build kit（**不要** --no-native，gpui.o 变了）。
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
SRC="$GPUI_DIR/src/ui/window_ext.cpp"
MARK="typephp-gpui: fail soft"

[[ -f "$SRC" ]] || { echo "ERROR: $SRC not found" >&2; exit 1; }

grep -qF "$MARK" "$SRC" && { echo "window_ext.cpp: already patched"; exit 0; }

echo "window_ext.cpp: LayersOf 改成告警一次 + 返回 null"
python3 - "$SRC" "$MARK" <<'PYEOF'
import sys

path, mark = sys.argv[1], sys.argv[2]
s = open(path, encoding='utf-8').read()

anchor = """static WindowLayers* LayersOf(Ctx* cx) {
    WindowLayers* layers = cx ? WindowLayersOf(cx->win) : nullptr;
    if (!layers) {
        Panic(
            "component window state is missing; call component::Init "
            "before KitOpenWindow");
    }
    return layers;
}"""
if anchor not in s:
    sys.exit("LayersOf anchor missing — 上游这段改了形状，补丁得重写")

body = """static WindowLayers* LayersOf(Ctx* cx) {
    WindowLayers* layers = cx ? WindowLayersOf(cx->win) : nullptr;
    if (!layers) {
        // """ + mark + """ — aborting the host here is never the answer: this
        // file's 14 call sites all already treat null as "do nothing", and a
        // host whose window root is ShellRoot (no component::WindowState
        // plugin) would otherwise die the moment a Dialog / AlertDialog /
        // Sheet / PopupMenu / ContextMenu / Notification kind is clicked.
        // Warn once so the reason is on the record without flooding frames.
        static bool sLayersWarned = false;
        if (!sLayersWarned) {
            sLayersWarned = true;
            fprintf(stderr,
                    "kit: component window state unavailable (this host opens its "
                    "window on ShellRoot, which carries no component::WindowState "
                    "plugin); Dialog/AlertDialog/Sheet/PopupMenu/ContextMenu/"
                    "Notification kinds will not open - use the shell-root APIs\\n");
        }
    }
    return layers;
}"""
s = s.replace(anchor, body, 1)

# fprintf 需要 <cstdio>：这个文件原来只用 Panic/Str，没带过。
if '#include <cstdio>' not in s:
    inc_anchor = '#include "ui/window_ext.h"'
    if inc_anchor not in s:
        sys.exit("include anchor missing")
    s = s.replace(inc_anchor, inc_anchor + "\n#include <cstdio>", 1)

open(path, 'w', encoding='utf-8').write(s)
print("  patched LayersOf + <cstdio>")
PYEOF

echo "done. 重建：php bin/gpui build kit（不带 --no-native）"
