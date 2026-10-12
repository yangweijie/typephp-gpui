#!/usr/bin/env bash
# cmd/patch-hover-always.sh — 让 macOS 的 tracking area 在窗口**不是 key** 时也投递
# mouseMoved / mouseEntered-Exited，于是 hover 反馈不再要求应用处于前台。
#
# 为什么要改上游这一行：`NSTrackingActiveInKeyWindow` 的语义是"只在钥匙窗口里才给移动事件"。
# 于是两种情况下用户把鼠标移上去什么都没有：
#   1) 应用刚起来、还没被激活（裸可执行文件甚至永远拿不到 keyWindow）；
#   2) 用户在别的 app 里打字，鼠标却悬在我们窗口上。
# 用户报的"hover 无效"就是这个门槛，而不是 PHP 侧没接 on_hover。
# 换成 `NSTrackingActiveAlways` 是 hover 驱动 UI 的常规做法：悬停本身就该给出反馈，
# 与焦点归属无关。
#
# 独立幂等：目标只有一处标识符，重复执行不会二次替换（grep 到 Always 就跳过）。
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
SRC="$GPUI_DIR/src/gpui/window_mac.cpp"
MARK="NSTrackingActiveAlways"

[[ -f "$SRC" ]] || { echo "ERROR: window_mac.cpp not found at $SRC" >&2; exit 1; }

if grep -qF "$MARK" "$SRC"; then
    echo "window_mac.cpp: already patched, skip"
else
    grep -qF "NSTrackingActiveInKeyWindow" "$SRC" \
        || { echo "ERROR: NSTrackingActiveInKeyWindow anchor missing — 上游结构变了，去看 updateTrackingAreas" >&2; exit 1; }
    python3 - "$SRC" <<'PYEOF'
import sys
p = sys.argv[1]
s = open(p).read()
old = """    NSTrackingAreaOptions opts =
        NSTrackingMouseMoved | NSTrackingMouseEnteredAndExited |
        NSTrackingActiveInKeyWindow | NSTrackingInVisibleRect;"""
new = """    // typephp-gpui: ActiveAlways, not ActiveInKeyWindow —— 非钥匙窗口也要收到 mouseMoved，
    // 否则"鼠标悬上去没反应"会跟着焦点状态一起漂（详见 cmd/patch-hover-always.sh）。
    NSTrackingAreaOptions opts =
        NSTrackingMouseMoved | NSTrackingMouseEnteredAndExited |
        NSTrackingActiveAlways | NSTrackingInVisibleRect;"""
if old not in s:
    sys.exit("anchor block not found verbatim")
n = s.count(old)
if n != 1:
    sys.exit("expected exactly 1 tracking-area option block, got %d" % n)
open(p, "w").write(s.replace(old, new, 1))
PYEOF
    echo "window_mac.cpp: tracking area -> ActiveAlways"
fi

# 自检：必须恰好一处 Always，且不再有 InKeyWindow。
nAlways=$(grep -cF "$MARK" "$SRC" || true)
nKey=$(grep -cF "NSTrackingActiveInKeyWindow" "$SRC" || true)
if [[ "$nAlways" != "1" || "$nKey" != "0" ]]; then
    echo "ERROR: self-check failed (Always=$nAlways, InKeyWindow=$nKey)" >&2
    exit 1
fi
echo "Patch complete."
