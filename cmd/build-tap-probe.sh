#!/usr/bin/env bash
# cmd/build-tap-probe.sh — 编外部见证两件套：会话层事件 tap + stock AppKit 基线窗。
#
# 为什么单独一个脚本：这两枚产物**不链本仓库任何代码**（gpui/PHP/shim 都不碰），
# 它们的存在就是为了在我们自己的仪器说"没收到"时，给出一个不依赖我们代码的对照读数。
# 用法：bash cmd/build-tap-probe.sh
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT_TAP=/tmp/gpui-event-tap-probe
OUT_CTRL=/tmp/gpui-control-appkit

clang -Wall -Wextra -O1 -framework ApplicationServices -framework CoreFoundation \
    "$ROOT/cmd/event-tap-probe.c" -o "$OUT_TAP"
echo "built: $OUT_TAP"

clang -Wall -Wextra -O1 -fobjc-arc -framework Cocoa \
    "$ROOT/cmd/control-appkit.m" -o "$OUT_CTRL"
echo "built: $OUT_CTRL"
