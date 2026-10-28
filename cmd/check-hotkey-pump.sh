#!/usr/bin/env bash
# cmd/check-hotkey-pump.sh — "注册热键后鼠标仍可用"的门（#99），正反两侧都跑。
#
# 探针 cmd/hotkey-pump-probe.cpp 驱动的是**生产 gpui_mac_pump()**（本脚本把
# cpp-src/gpui_mac.mm 编进来一起链），不是它的抄本。三臂读数见探针头部。
#
# 红臂（自证判别力）：把生产源里 peek 的那个 `false` 翻成 `true` 编一份**临时副本**
# （生产文件一个字不动），同一个探针必须随之变红。翻不动/锚点不唯一 ⇒ rc=2 未判定。
# 没有这一臂，"绿"只说明队列层的设计成立，不说明门绑在生产参数上。
#
# 需要窗口会话（Carbon 队列要 AppKit 起来才存在），与其余原生门同类；
# 前置产物缺失同样 rc=2，不冒充通过。
# 用法：bash cmd/check-hotkey-pump.sh [--arm=both|green|red]   （composer check:hotkey-pump）
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
WORK="$GPUI_DIR/.work"
OBJ="$GPUI_DIR/out/mac/rel/obj"
OUT="$ROOT/build/hotkey-pump-probe"
MAC_SRC="$ROOT/cpp-src/gpui_mac.mm"
PROBE_SRC="$ROOT/cmd/hotkey-pump-probe.cpp"

ARM=both
case "${1:-}" in
    "") ;;
    --arm=both) ARM=both ;;
    --arm=green) ARM=green ;;
    --arm=red) ARM=red ;;
    *)
        echo "HOTKEY-PUMP 未知开关：${1}（只认 --arm=both|green|red）⇒ 未判定"
        exit 2
        ;;
esac

for f in "$WORK/gpui.h" "$OBJ/gpui/gpui.o" "$OBJ/quickjs/quickjs.o" "$OBJ/ex/AppLog.o" \
         "$MAC_SRC" "$PROBE_SRC"; do
    if [[ ! -f "$f" ]]; then
        echo "HOTKEY-PUMP 前置产物缺失：$f（先跑一次完整构建）⇒ 未判定"
        exit 2
    fi
done

# 漂移守卫：探针里的热键签名是生产内部常量的副本，两侧字面量必须都在册。
if ! grep -qF "kHotKeySignature = 'gpxi'" "$MAC_SRC" ||
   ! grep -qF "kProbeSignature = 'gpxi'" "$PROBE_SRC"; then
    echo "HOTKEY-PUMP 热键签名漂移（生产 kHotKeySignature / 探针 kProbeSignature）⇒ 未判定"
    exit 2
fi

mkdir -p "$(dirname "$OUT")"
FRAMEWORKS=(-framework Cocoa -framework CoreServices -framework CoreText
            -framework CoreGraphics -framework ImageIO -framework IOKit
            -framework WebKit -framework AudioToolbox -framework AVFoundation
            -framework Speech -framework Metal -framework QuartzCore -framework Carbon)

clang++ -std=c++20 -O1 -I"$WORK" -I"$ROOT/cpp-src" -c "$PROBE_SRC" -o "$OUT.o"

build_mac() { # $1=源 $2=对象
    clang++ -std=c++20 -x objective-c++ -fobjc-arc -Wall -Wno-deprecated-declarations \
        -I"$ROOT/cpp-src" -c "$1" -o "$2"
}
link_probe() { # $1=mac 对象 $2=可执行
    clang++ "$OUT.o" "$1" "$OBJ/ex/AppLog.o" "$OBJ/gpui/gpui.o" "$OBJ/quickjs/quickjs.o" \
        "${FRAMEWORKS[@]}" -o "$2"
}

green_ok=0
red_ok=0

if [[ "$ARM" != "red" ]]; then
    build_mac "$MAC_SRC" "$OUT.mac.o"
    link_probe "$OUT.mac.o" "$OUT"
    if "$OUT" > "$OUT.green.log" 2>&1; then grc=0; else grc=$?; fi
    gline="$(grep -F 'HOTKEY-PUMP ' "$OUT.green.log" | tail -1)"
    echo "$gline"
    if [[ $grc -eq 0 && "$gline" == *"修法成立"* ]]; then
        green_ok=1
    else
        echo "HOTKEY-PUMP 正向臂 FAIL（rc=$grc，判定行须含'修法成立'）"
    fi
fi

if [[ "$ARM" != "green" ]]; then
    if ! python3 - "$MAC_SRC" "$OUT.red.mm" <<'PY'
import sys
src = open(sys.argv[1]).read()
old = "kEventDurationNoWait, false, &peeked"
new = "kEventDurationNoWait, true, &peeked"
if src.count(old) != 1:
    sys.exit("红臂锚点不唯一（count=%d）" % src.count(old))
open(sys.argv[2], "w").write(src.replace(old, new, 1))
PY
    then
        echo "HOTKEY-PUMP 红臂造不出判别输入 ⇒ 未判定"
        exit 2
    fi
    build_mac "$OUT.red.mm" "$OUT.red.o"
    link_probe "$OUT.red.o" "$OUT.red"
    if "$OUT.red" > "$OUT.red.log" 2>&1; then rrc=0; else rrc=$?; fi
    rline="$(grep -F 'HOTKEY-PUMP ' "$OUT.red.log" | tail -1)"
    echo "$rline"
    if [[ $rrc -eq 1 && "$rline" == *"修法不成立"* ]]; then
        red_ok=1
        echo "HOTKEY-PUMP 红臂如预期变红（生产 peek 参数翻一个字 ⇒ 鼠标被吃）"
    else
        echo "HOTKEY-PUMP 红臂 FAIL（rc=$rrc，期望 rc=1 且判定行含'修法不成立'）——门没有判别力"
    fi
fi

case "$ARM" in
    green)
        if [[ $green_ok -eq 1 ]]; then echo "HOTKEY-PUMP GATE OK"; exit 0; fi
        echo "HOTKEY-PUMP GATE FAILED"
        exit 1
        ;;
    red)
        if [[ $red_ok -eq 1 ]]; then echo "HOTKEY-PUMP RED ARM OK"; exit 0; fi
        echo "HOTKEY-PUMP RED ARM FAILED"
        exit 1
        ;;
    both)
        if [[ $green_ok -eq 1 && $red_ok -eq 1 ]]; then
            echo "HOTKEY-PUMP GATE OK（正向绿 + 红臂红）"
            exit 0
        fi
        echo "HOTKEY-PUMP GATE FAILED（正向=$green_ok 红臂=$red_ok）"
        exit 1
        ;;
esac