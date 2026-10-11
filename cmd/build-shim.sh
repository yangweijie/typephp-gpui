#!/usr/bin/env bash
# cmd/build-shim.sh — 产出 C++20 侧静态库 libgpui_shim.a，供 nano(c++17) 的 tpc 链接。
#
# 前置：先跑 cmd/patch-view.sh 打补丁，再 `bun cmd/build.ts -rel hello_world`
# 在 gpui-kit-cpp 里生成 .work/ 并编译出 gpui.o / quickjs.o / AppLog.o（这三个
# 直接复用，省掉每次重编 32 万行 amalgam）。本脚本只编译 gpui_shim.cpp 并归档。
#
# 用法：GPUI_DIR=<gpui-kit-cpp> cmd/build-shim.sh   （默认 build/gpui-kit-cpp（upstream/ 的沙箱副本））

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
WORK="$GPUI_DIR/.work"
OBJ="$GPUI_DIR/out/mac/rel/obj"
OUT_DIR="$ROOT/cpp-src/build"
LIB="$OUT_DIR/libgpui_shim.a"

GPUI_O="$OBJ/gpui/gpui.o"
QUICKJS_O="$OBJ/quickjs/quickjs.o"
APPLOG_O="$OBJ/ex/AppLog.o"

# 校验前置产物存在。
for o in "$GPUI_O" "$QUICKJS_O" "$APPLOG_O"; do
    [[ -f "$o" ]] || {
        echo "ERROR: missing $o" >&2
        echo "       先跑 cmd/patch-view.sh 然后 (cd $GPUI_DIR && bun cmd/build.ts -rel hello_world)" >&2
        exit 1
    }
done

mkdir -p "$OUT_DIR"

# 编译 shim：镜像 amalgam 的 c++20 + objective-c++ 标志（gpui.h 引用 AppKit 类型）。
# 不加 -Werror/-fno-exceptions：shim 独立 TU，异常/RTTI 由最终链接的 libc++abi 提供。
echo "compiling gpui_shim.cpp (c++20, objective-c++)..."
clang++ -std=c++20 -x objective-c++ -fobjc-arc \
    -Wall -Wno-deprecated-declarations -O2 -DNDEBUG \
    -I "$ROOT/cpp-src" \
    -I "$WORK" -I "$WORK/extras" \
    -c "$ROOT/cpp-src/gpui_shim.cpp" \
    -o "$OUT_DIR/gpui_shim.o"

# macOS 系统集成（托盘/快捷键/Dock/OS 通知）：独立 TU，不 include gpui.h，
# 所以它既不受 amalgam 的 C++20 约束，也不把 AppKit 类型带过防火墙。
echo "compiling gpui_mac.mm (c++20, objective-c++)..."
clang++ -std=c++20 -x objective-c++ -fobjc-arc \
    -Wall -Wno-deprecated-declarations -O2 -DNDEBUG \
    -I "$ROOT/cpp-src" \
    -c "$ROOT/cpp-src/gpui_mac.mm" \
    -o "$OUT_DIR/gpui_mac.o"

echo "archiving libgpui_shim.a..."
libtool -static -o "$LIB" \
    "$OUT_DIR/gpui_shim.o" "$OUT_DIR/gpui_mac.o" "$GPUI_O" "$QUICKJS_O" "$APPLOG_O"

echo "built: $LIB ($(stat -f %z "$LIB") bytes)"
