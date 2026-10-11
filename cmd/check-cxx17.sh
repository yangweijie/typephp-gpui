#!/usr/bin/env bash
# cmd/check-cxx17.sh — 第 8 个补丁的门禁：gpui.h 必须能在 C++17 下被解析，且 concept 降级
# 是**语义等价**而不是"能编过就算"。
#
# 为什么需要它：补丁打在 vendored 头文件上，上游一刷新就可能静默失配（站点排版变了 →
# 正则匹配不到 → 补丁什么也没做）。这个检查把"降级确实生效且判据没变"钉成一条命令。
#
# 用法：cmd/check-cxx17.sh        （GPUI_DIR 可指到别的源码树）
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
PROBE="$(mktemp -t gpui-cxx17-XXXX).cpp"

cat > "$PROBE" <<'CPP'
// 只 include gpui.h：验的就是"bridge 那一层能不能直接吃这颗头"。
#include "gpui/gpui.h"

namespace gpui {
struct ProbeState {};
struct ProbeEvent {};
struct OtherEvent {};
// 有特化 ⇒ 这个事件通道存在（EmitsEvent 判的正是这件事）。
template <> struct EventEmitter<ProbeState, ProbeEvent> {};
}

// 1) trait 的两个方向都要对：只验"有特化为真"会漏掉把判据写宽的情况（比如把
//    sizeof 换成恒真表达式，两边都"编译通过"）。
static_assert(gpui::EmitsEventV<gpui::ProbeState, gpui::ProbeEvent>,
              "有 EventEmitter 特化时必须为真");
static_assert(!gpui::EmitsEventV<gpui::ProbeState, gpui::OtherEvent>,
              "没有特化时必须为假——判据被写宽了");
static_assert(!gpui::EmitsEventV<int, int>, "任意类型对不能算事件通道");

// 2) 五个 enable_if 站点要真的能实例化（SFINAE 没把重载关系弄坏）。SubscribeTo 有两条
//    重载，取地址必须靠**带类型的指针**消歧——这顺带也证明约束没有把重载集弄脏。
using Ptr2 = gpui::Subscription (*)(
    gpui::Ctx*, gpui::Entity<gpui::ProbeState>,
    void (*)(gpui::ProbeState*, gpui::Ctx*, const gpui::ProbeEvent*));
using Ptr3 = gpui::Subscription (*)(
    gpui::App*, gpui::Entity<gpui::ProbeState>, gpui::Entity<gpui::ProbeState>,
    void (*)(gpui::ProbeState*, gpui::Ctx*, const gpui::ProbeEvent*));
using PtrArg = gpui::Subscription (*)(
    gpui::App*, gpui::Entity<gpui::ProbeState>, gpui::Entity<gpui::ProbeState>,
    void (*)(gpui::ProbeState*, gpui::Ctx*, const gpui::ProbeEvent*, int64_t),
    int64_t);
using PtrEmit = void (*)(gpui::Ctx*, gpui::Entity<gpui::ProbeState>,
                        const gpui::ProbeEvent*);
using PtrEntityEmit = void (*)(gpui::App*, gpui::Window*,
                              gpui::Entity<gpui::ProbeState>, const gpui::ProbeEvent*);

int main() {
    Ptr2 subscribe = &gpui::Subscribe<gpui::ProbeState, gpui::ProbeState, gpui::ProbeEvent>;
    Ptr3 subscribeTo =
        &gpui::SubscribeTo<gpui::ProbeState, gpui::ProbeState, gpui::ProbeEvent>;
    PtrEmit emit = &gpui::Emit<gpui::ProbeState, gpui::ProbeEvent>;
    PtrEntityEmit entityEmit =
        &gpui::EntityEmit<gpui::ProbeState, gpui::ProbeEvent>;
    // 带 int64_t 实参的那条重载：模板头是三个类型参数、实参表多一个 arg，
    // 用 PtrArg 显式标注才能选中它（不标注就是上面那种歧义）。
    PtrArg withArg =
        &gpui::SubscribeTo<gpui::ProbeState, gpui::ProbeState, gpui::ProbeEvent>;
    return (subscribe && subscribeTo && emit && entityEmit && withArg) ? 0 : 1;
}

CPP

# -Wno-c++20-extensions：头里有 87 处位域默认成员初始化（`uint32_t wrap : 1 = false;`），
# 那是 C++20 的东西，clang 在 C++17 下按**扩展**接受并逐个告警。不静默它，日志会被 87 行
# 噪声盖住真正的失败；记在这儿是因为它是"能编过"的代价之一。
FLAGS="-fsyntax-only -I $GPUI_DIR/src -Wno-c++20-extensions -Werror"
for std in c++17 c++20; do
    printf '  %-6s ' "$std"
    if clang++ -std="$std" $FLAGS "$PROBE" 2>/tmp/cxx17-$std.err; then
        echo "OK"
    else
        echo "FAIL"
        sed -n '1,12p' "/tmp/cxx17-$std.err"
        rm -f "$PROBE"
        exit 1
    fi
done
rm -f "$PROBE"
echo "CXX17 GATE OK（gpui.h 在 C++17/C++20 下都可解析，且 EmitsEvent 判据双向正确）"
