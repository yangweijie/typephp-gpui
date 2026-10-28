#!/usr/bin/env bash
# cmd/onscreen-probe.sh —— #56 的可重跑判据：同一秒并跑六颗程序，只看 WindowServer 的在屏账本。
#
# 为什么需要它（这条议题上我栽过三次的地方）：进程内自报（NSApp.isActive / keyWindow /
# isVisible）与像素门（--shot 走 cacheDisplayInRect，进程内渲染）在"窗口根本不被合成"时
# 全都绿得漂亮，只有真人手点才撞得出来。而真人手点又慢又受环境干扰，
# 于是任何"跨时刻单次对比"都会把会飘的状态误读成因果（13:33 签名、14:21 shimonly、
# 14:31 app_init 三次都这样翻车）。这条脚本把比较固定成**同一秒、只差一个变量**。
#
# 为什么要六个格子而不是三个（2026-10-15 23:05 的翻案记录）：
# 上一版只跑 A 纯 AppKit / B gpui 零 PHP / C 完整产物，**而 B 是从临时目录裸跑的、
# C 是从 .app 里跑的**——一次比了两个变量（有没有 PHP × 从哪儿启动）。
# 于是"B 在屏、C 不在屏 ⇒ 缺陷在 nano/PHP 运行时"这条结论是配错对照组配出来的，已经作废。
# 现在每颗程序都跑两种启动方式，逐格只切一个变量：
#
#                    裸（从目录直接 exec）      从 .app bundle 里 exec
#   纯 AppKit            裸=1 / bundle=1（四次全如此）
#   gpui + 我们的 shim   裸=1（四次全如此），bundle=1,1,0,0  ← 这一格自己会飘
#   我们的完整产物       裸=1 / bundle=0（四次全如此，自报与账本逐格吻合）
#
# ⇒ 站得住的只有：缺陷配上"从 .app 内启动"（裸跑那一列从不缺），且纯 AppKit 两种启动都免疫；
#   nano/PHP 在场时恒失败、不在场时偶失败 ⇒ 它至多是加重项，**不是**触发条件。
#
# 试过的更大胆一格（普通"伴侣窗"开在 gpui 那颗的同一进程里，用来分"进程被整体拒绝"与
# "只有 gpui 那扇被拒"）已撤：gpui_shim_open_window 交回的是 Box2*（shim 内部类型），
# 要拿它的 windowNumber 得先 `gpui::WindowNativeHandle(box->window)`——那需要为一条诊断
# 新开一条生产 ABI 出口。写进探针后两支 gpui 臂当场段错误（把句柄当 gpui::Window* 用），
# 那轮 B 列的 0/0 是探针坏了的读数，不是测量结果，别引用。
#
# 用法：bash cmd/onscreen-probe.sh [示例名，默认 input]
# 退出码：0 = 六格全在屏（本机此刻物理输入两侧可达，真人那一按两侧都能验）；
#         1 = bundle 那一列缺格（= #56 的已知开放缺陷，可复现；
#             可交付路径是裸二进制，`php bin/gpui run <示例>` 已默认如此）；
#         2 = 连裸跑的那几格都不在屏，或纯 AppKit 的 bundle 格也缺 ⇒ 主机/会话当下不给上屏，先别查代码；
#         3 = 前置条件不满足（没建产物 / 非 macOS / 没有编译器 / bundle 建不起来）。
# 注意：这条**不进 CI**——它要一个能合成窗口的 GUI 会话，托管 runner 上恒不成立。

set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
EX="${1:-input}"
[[ "$(uname -s)" == "Darwin" ]] || { echo "本探针只在 macOS 上有意义"; exit 3; }
command -v clang++ >/dev/null || { echo "缺 clang++"; exit 3; }

SHIM="$ROOT/cpp-src/build/libgpui_shim.a"
GPUI_O="$ROOT/build/gpui-kit-cpp/out/mac/rel/obj/gpui/gpui.o"
QJ_O="$ROOT/build/gpui-kit-cpp/out/mac/rel/obj/quickjs/quickjs.o"
LOG_O="$ROOT/build/gpui-kit-cpp/out/mac/rel/obj/ex/AppLog.o"
BIN="$ROOT/examples/$EX/build/$EX"
APP="$ROOT/examples/$EX/build/$EX.app/Contents/MacOS/$EX"
for need in "$SHIM" "$GPUI_O" "$BIN" "$APP"; do
    [[ -f "$need" ]] || { echo "缺 $need —— 先 php bin/gpui build $EX --no-native"; exit 3; }
done

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
FW=(-framework Cocoa -framework CoreServices -framework CoreText -framework CoreGraphics
    -framework ImageIO -framework IOKit -framework WebKit -framework AudioToolbox
    -framework AVFoundation -framework Speech -framework Metal -framework QuartzCore -framework Carbon)

cat > "$TMP/control.m" <<'EOF'
/* A：纯 AppKit 对照。B：同一支 shim + gpui，零 PHP。用 argv[1] 选。 */
#import <Cocoa/Cocoa.h>
#include <stdio.h>
#include <string.h>
#include <unistd.h>
#ifdef WITH_GPUI
#include "gpui_shim.h"
// 批6 起 shim TU 引用宿主符号 gpui_data_node_free。本探针零 PHP、不链 bridge，
// 于是自己当宿主：include 与 bridge / shim_test 同一份生产切片（不抄副本）。
// 只在 WITH_GPUI 分支里：appkit 臂按 objective-c 编，吃不下 C++ 头。
#include <cstring>
#include <string>
#include <unordered_map>
#include <vector>
#include "gpui_data_node.inc"
#endif
int main(int argc, char **argv) {
    @autoreleasepool {
        const char *tag = (argc > 1 && argv[1][0] == 'g') ? "gpui" : "appkit";
        void *w = NULL;
        NSWindow *pw = nil;
        if (strcmp(tag, "gpui") == 0) {
#ifdef WITH_GPUI
            gpui_shim_app_init();
            w = gpui_shim_open_window("Probe gpui", 420, 300);
            for (int i = 0; i < 40; i++) { gpui_shim_render_begin(w); gpui_shim_render_end(w, 0); gpui_shim_pump(w, 0.05); }
#else
            fprintf(stderr, "本探针需要带 WITH_GPUI 编译\n"); return 3;
#endif
        } else {
            pw = [[NSWindow alloc] initWithContentRect:NSMakeRect(0,0,420,300)
                    styleMask:(NSWindowStyleMaskTitled|NSWindowStyleMaskClosable)
                    backing:NSBackingStoreBuffered defer:NO];
            [pw setTitle:@"Probe appkit"];
            [pw center];
            [pw makeKeyAndOrderFront:nil];
            [NSApp activateIgnoringOtherApps:YES];
        }
        printf("tag=%s\n", tag); fflush(stdout);
        double end = [NSProcessInfo processInfo].systemUptime + 10.0;
        while ([NSProcessInfo processInfo].systemUptime < end) {
            if (w) {
#ifdef WITH_GPUI
                gpui_shim_render_begin(w); gpui_shim_render_end(w, 0); gpui_shim_pump(w, 0.05);
#endif
            }
            else {
                NSEvent *ev = [NSApp nextEventMatchingMask:NSEventMaskAny untilDate:[NSDate distantPast]
                                                   inMode:NSDefaultRunLoopMode dequeue:YES];
                if (ev) [NSApp sendEvent:ev];
                usleep(2000);
            }
        }
    }
    return 0;
}
EOF

# 外部账本探针：按 pid 数"在屏"有几行（kCGWindowListOptionOnScreenOnly）。
cat > "$TMP/ledger.m" <<'EOF'
#import <Foundation/Foundation.h>
#import <CoreGraphics/CoreGraphics.h>
#include <stdlib.h>
#include <string.h>
int main(int argc, char **argv) {
    @autoreleasepool {
        /* `wid <窗口号>`：只看这一扇在不在屏。其它调用按 pid 数行。
         * 每臂只开一扇窗，所以 pid 计数就是那一扇的读数；加臂时必须改走 wid，否则两扇一起数。 */
        if (argc > 2 && strcmp(argv[1], "wid") == 0) {
            CGWindowID wid = (CGWindowID) strtoul(argv[2], NULL, 10);
            CFArrayRef a = CGWindowListCopyWindowInfo(kCGWindowListOptionIncludingWindow, wid);
            printf("%ld\n", a ? (long) CFArrayGetCount(a) : -1);
            if (a) CFRelease(a);
            return 0;
        }
        pid_t want = (pid_t)atoi(argv[1]);
        CFArrayRef a = CGWindowListCopyWindowInfo(kCGWindowListOptionOnScreenOnly, kCGNullWindowID);
        long n = 0;
        if (a) { for (CFIndex i = 0; i < CFArrayGetCount(a); i++) {
                CFDictionaryRef d = CFArrayGetValueAtIndex(a, i);
                pid_t p = 0; CFNumberRef nr = CFDictionaryGetValue(d, kCGWindowOwnerPID);
                if (nr) CFNumberGetValue(nr, kCFNumberSInt32Type, &p);
                if (p == want) n++; } CFRelease(a); }
        printf("%ld\n", n);
    }
    return 0;
}
EOF

clang -x objective-c -fobjc-arc -framework Foundation -framework CoreGraphics \
      "$TMP/ledger.m" -o "$TMP/ledger" 2>"$TMP/err" || { cat "$TMP/err"; exit 3; }
# 分两步：`-x objective-c++` 只作用于源码——把它留在链接行上会让 clang 把 .a/.o 当源码编
# （cmd/test-shim.sh 里同一句话已经写过一次）。
clang++ -x objective-c -fobjc-arc -c "$TMP/control.m" -o "$TMP/appkit.o" 2>"$TMP/err" \
      || { cat "$TMP/err"; exit 3; }
clang++ "$TMP/appkit.o" -o "$TMP/appkit" "${FW[@]}" 2>"$TMP/err" \
      || { cat "$TMP/err"; exit 3; }
clang++ -std=c++20 -x objective-c++ -fobjc-arc -DWITH_GPUI -I "$ROOT/cpp-src" \
      -I "$ROOT/build/gpui-kit-cpp/.work" -c "$TMP/control.m" -o "$TMP/gpui.o" 2>"$TMP/err" \
      || { echo "gpui 对照编译失败："; head -5 "$TMP/err"; exit 3; }
clang++ "$TMP/gpui.o" "$SHIM" "$GPUI_O" ${QJ_O:+"$QJ_O"} ${LOG_O:+"$LOG_O"} \
      -o "$TMP/gpui" "${FW[@]}" 2>"$TMP/err" \
      || { echo "gpui 对照链接失败："; head -5 "$TMP/err"; exit 3; }

# 每颗程序包一支最小 .app：正规文件主程序 + ad-hoc 签名绑 Info.plist，键位与 bin/gpui 的
# appBundle() 保持一致（含 NSPrincipalClass）——否则 bundle 那一列量的不是"从 .app 启动"这个变量，
# 而是"签名没绑 plist / 缺主类"那些早已排除的原因。
mk_bundle() {  # $1=可执行文件 $2=bundle 目录 $3=名字
    local exe="$1" dir="$2" name="$3"
    mkdir -p "$dir/Contents/MacOS"
    cp "$exe" "$dir/Contents/MacOS/$name" || return 1
    cat > "$dir/Contents/Info.plist" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
<key>CFBundleExecutable</key><string>$name</string>
<key>CFBundleIdentifier</key><string>probes.$name</string>
<key>CFBundleName</key><string>$name</string>
<key>CFBundlePackageType</key><string>APPL</string>
<key>CFBundleShortVersionString</key><string>1.0</string>
<key>LSMinimumSystemVersion</key><string>11.0</string>
<key>NSHighResolutionCapable</key><true/>
<key>NSPrincipalClass</key><string>NSApplication</string>
</dict></plist>
PLIST
    codesign -s - --force "$dir" > /dev/null 2>&1 || return 1
    return 0
}
for spec in "appkit:probe_a" "gpui:probe_b"; do
    src="${spec%%:*}"; name="${spec##*:}"
    mk_bundle "$TMP/$src" "$TMP/$name.app" "$src" \
        || { echo "建 $name.app 失败（cp/Info.plist/codesign 之一）"; exit 3; }
done

launch() {  # $1=路径 $2=标签 $3=传给程序的参数（对照程序用 argv[1] 选分支，两列都得传）
    "$1" ${3:+"$3"} > "$TMP/$2.out" 2>&1 &
    eval "$2=\$!"
}
launch "$TMP/appkit"                            A_BARE   appkit
launch "$TMP/probe_a.app/Contents/MacOS/appkit" A_BUNDLE appkit
launch "$TMP/gpui"                              B_BARE   gpui
launch "$TMP/probe_b.app/Contents/MacOS/gpui"   B_BUNDLE gpui
launch "$BIN"                                   C_BARE   --bare-window
launch "$APP"                                   C_BUNDLE --bare-window
sleep 3
# 自证臂：六颗都得还活着才有"在不在屏"可谈。少一颗（比如 23:24 那轮 gpui 臂段错误）时，
# 账本对它返回 0，那条 0 长得和"被账本拒绝"一模一样——必须先把它挡在结论外面。
for nm in A_BARE A_BUNDLE B_BARE B_BUNDLE C_BARE C_BUNDLE; do
    pid="$(eval "echo \$$nm")"
    if ! kill -0 "$pid" 2>/dev/null; then
        echo "臂 ${nm}（pid ${pid}）在取读数前就退出了 —— 那是探针坏了，不是「不在屏」，本轮不作数"
        kill $A_BARE $A_BUNDLE $B_BARE $B_BUNDLE $C_BARE $C_BUNDLE 2>/dev/null
        exit 3
    fi
done
LA=$("$TMP/ledger" "$A_BARE"); LB=$("$TMP/ledger" "$A_BUNDLE")
LC=$("$TMP/ledger" "$B_BARE"); LD=$("$TMP/ledger" "$B_BUNDLE")
LE=$("$TMP/ledger" "$C_BARE"); LF=$("$TMP/ledger" "$C_BUNDLE")
kill $A_BARE $A_BUNDLE $B_BARE $B_BUNDLE 2>/dev/null
# C 那两支自己跑完（约 6 秒）才在退出前打印自报——杀了就丢掉这行读数。
wait $C_BARE $C_BUNDLE 2>/dev/null
SELF=$(grep -oE 'onScreen[^A-Za-z0-9]+(true|false|0|1)' "$TMP/C_BUNDLE.out" | tail -1)
SELF_BARE=$(grep -oE 'onScreen[^A-Za-z0-9]+(true|false|0|1)' "$TMP/C_BARE.out" | tail -1)

echo "同一秒 2×3 矩阵（只看 WindowServer 在屏账本，1=在屏）："
printf '  %-22s 裸=%s  bundle=%s\n' "纯 AppKit"              "$LA" "$LB"
printf '  %-22s 裸=%s  bundle=%s\n' "gpui+shim（零 PHP）"     "$LC" "$LD"
printf '  %-22s 裸=%s  bundle=%s   自报 裸 %s / bundle %s\n' "我们的完整产物" \
       "$LE" "$LF" "${SELF_BARE:-?}" "${SELF:-?}"

# 判读按"哪几格缺"分派，而不是按单格读数下结论。
BARE_BAD=0
for r in "$LA" "$LC" "$LE"; do [ "$r" -lt 1 ] && BARE_BAD=1; done
if [ "$BARE_BAD" -eq 1 ]; then
    echo "判读：裸跑也有格不在屏 ⇒ 主机/会话当下不允许新窗口上屏，先修环境，别查代码。"
    exit 2
fi
if [ "$LF" -ge 1 ]; then
    echo "判读：六格全在屏 ⇒ 本机此刻物理输入两侧可达，17-E 那一按在 bundle 路径也能验。"
    exit 0
fi
# 到这儿只可能是 bundle 那一列缺格。
if [ "$LB" -lt 1 ]; then
    echo "判读：连纯 AppKit 从 bundle 启动都不在屏 ⇒ 与 gpui/PHP 无关，是 bundle 启动这条路径本身。"
    exit 2
fi
if [ "$LD" -lt 1 ]; then
    echo "判读：纯 AppKit 两格都在屏，而凡走 gpui 建窗路径且从 .app 内启动就不在屏（零 PHP 也一样）"
    echo "       ⇒ #56 的缺陷 = gpui 的窗 × bundle 启动，与 nano/PHP 在不在场无关；可复现。"
    echo "       可交付路径 = 裸二进制（php bin/gpui run <示例> 已默认如此）。"
    exit 1
fi
echo "判读：只有我们那一颗从 bundle 里跑不在屏（同一支 shim 的零 PHP 版在屏）"
echo "       ⇒ 这一轮的形状是"nano/PHP 在场 + bundle 启动"这一对；与上一轮（零 PHP 也缺）不同，"
echo "       说明 bundle 那一列是概率性被拒，别把单轮的形状当定论。"
echo "       可交付路径 = 裸二进制（php bin/gpui run <示例> 已默认如此）。"
exit 1
