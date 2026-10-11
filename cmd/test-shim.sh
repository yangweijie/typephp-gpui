#!/usr/bin/env bash
# cmd/test-shim.sh — 编译并跑 shim 纯函数 C++ 回归测试（headless，不开窗口）。
#
# tests/cpp/shim_test.cpp #include 整个 gpui_shim.cpp，直接测其匿名命名空间里的
# 纯函数（kindFromString / AStr / toComponentArg / buildComponentArgs）——bug #18
# 与 Stage B schema 映射的守卫。链接 amalgam 对象（gpui.o/quickjs.o/AppLog.o），
# 不链 libgpui_shim.a（避免重复符号）。前置同 build-shim：补丁 + bun build.ts。
#
# 用法：GPUI_DIR=<gpui-kit-cpp> cmd/test-shim.sh   （默认 build/gpui-kit-cpp（upstream/ 的沙箱副本））

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
WORK="$GPUI_DIR/.work"
OBJ="$GPUI_DIR/out/mac/rel/obj"
OUT_DIR="$ROOT/cpp-src/build"
BIN="$OUT_DIR/shim_test"

GPUI_O="$OBJ/gpui/gpui.o"
QUICKJS_O="$OBJ/quickjs/quickjs.o"
APPLOG_O="$OBJ/ex/AppLog.o"

for o in "$GPUI_O" "$QUICKJS_O" "$APPLOG_O"; do
    [[ -f "$o" ]] || {
        echo "ERROR: missing $o" >&2
        echo "       先跑 cmd/patch-*.sh 然后 (cd $GPUI_DIR && bun cmd/build.ts -rel hello_world)" >&2
        exit 1
    }
done

mkdir -p "$OUT_DIR"

echo "compiling shim_test.cpp (c++20, objective-c++)..."
# 分两步：-x objective-c++ 只作用于 .cpp（否则会把后面的 .o 当源码编），
# 链接阶段不带 -x，clang 自动把 .o 当目标文件。
clang++ -std=c++20 -x objective-c++ -fobjc-arc \
    -Wall -Wno-deprecated-declarations -g \
    -I "$ROOT/cpp-src" \
    -I "$WORK" -I "$WORK/extras" \
    -c "$ROOT/tests/cpp/shim_test.cpp" \
    -o "$OUT_DIR/shim_test.o"

# gpui_shim.cpp 引用 gpui_mac_*（独立 TU），测试链接也要带上它。
echo "compiling gpui_mac.mm for the test link..."
clang++ -std=c++20 -x objective-c++ -fobjc-arc \
    -Wall -Wno-deprecated-declarations -g \
    -I "$ROOT/cpp-src" \
    -c "$ROOT/cpp-src/gpui_mac.mm" \
    -o "$OUT_DIR/gpui_mac.o"

echo "linking shim_test..."
clang++ "$OUT_DIR/shim_test.o" "$OUT_DIR/gpui_mac.o" "$GPUI_O" "$QUICKJS_O" "$APPLOG_O" \
    -framework Cocoa -framework CoreServices -framework CoreText \
    -framework CoreGraphics -framework ImageIO -framework IOKit \
    -framework WebKit -framework AudioToolbox -framework AVFoundation \
    -framework Speech -framework Metal -framework QuartzCore -framework Carbon \
    -o "$BIN"

echo "running: $BIN"
"$BIN"
