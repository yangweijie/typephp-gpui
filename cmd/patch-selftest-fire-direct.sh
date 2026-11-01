#!/usr/bin/env bash
# cmd/patch-selftest-fire-direct.sh — Phase 33 批1：修 gpui_shim_selftest_fire
# 的"返回 1 但事件没入队"缺陷。
#
# 来路（2026-11-01 实测）：automation 路径下 `widget-click kit-anim-toggle`
# 返回 `clicked(element) kit-anim-toggle`（findCallback 找到了 callbackId），
# 但下一帧 `poll` 返回 empty——事件没进 box->queue。根因：gpui_shim_selftest_fire
# 用 `box->viewPtr` 当 self 传给 gpuiEnqueue*，后者内部 boxForView(self) 反查
# g_views——反查失败时 gpuiEnqueue* 静默返回（不入队），但 gpui_shim_selftest_fire
# 照样返回 1。selftest 里手动 gpui_poll_event + app->dispatch 能收到是因为
# selftest 不经过 automation 的 loop() 泵序（pump_events 在 drainEvents 之前，
# GpuiAppRunStep 的 ExecDrain 可能消费了 box->queue）。
#
# 修法：gpui_shim_selftest_fire 直接用 box->enqueue(cbid, ...) 入队——box 已经在
# 手上（从 win 转来的），不需要 boxForView 反查。三条事件（click/change/hover）
# 的 vkind/vnum/vbool 与 gpuiEnqueue* 一致。
#
# 幂等：已打过则跳过。
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TARGET="$ROOT/cpp-src/gpui_shim.cpp"

if grep -q 'box->enqueue(cbid, GPUI_ARG_NUM, 1.0' "$TARGET"; then
    echo "patch-selftest-fire-direct: 已打过，跳过"
    exit 0
fi

python3 - "$TARGET" <<'PYEOF'
import sys, re
path = sys.argv[1]
with open(path, 'r') as f:
    src = f.read()

old = '''    auto* self = (gpui::ScriptView*)box->viewPtr;
    std::string t(type);
    if (t == "click") gpui::gpuiEnqueueClick(self, nullptr, cbid, 1);
    else if (t == "change") gpui::gpuiEnqueueChange(self, nullptr, cbid, true);
    else if (t == "hover") gpui::gpuiEnqueueHover(self, nullptr, cbid, true);
    else return 0;
    return 1;'''

new = '''    // Phase 33 批1 修：直接 box->enqueue 入队，不走 gpuiEnqueue* 的 boxForView
    // 反查——反查失败时事件静默丢失但本函数照样返回 1（实测 automation 路径
    // poll 返回 empty）。box 已在手上，无需反查。
    std::string t(type);
    if (t == "click") box->enqueue(cbid, GPUI_ARG_NUM, 1.0, 0, nullptr);
    else if (t == "change") box->enqueue(cbid, GPUI_ARG_BOOL, 0, 1, nullptr);
    else if (t == "hover") box->enqueue(cbid, GPUI_ARG_BOOL, 0, 1, nullptr);
    else return 0;
    return 1;'''

if old not in src:
    print("patch-selftest-fire-direct: 锚点不匹配", file=sys.stderr)
    sys.exit(1)
src = src.replace(old, new, 1)
with open(path, 'w') as f:
    f.write(src)
print("patch-selftest-fire-direct: 已打")
PYEOF
