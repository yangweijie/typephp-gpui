#!/usr/bin/env bash
# cmd/patch-concepts.sh — 第 8 个补丁：把 gpui.h 的 C++20 concept/requires 降到 C++17 可解析。
#
# 为什么打：防火墙存在的唯一硬理由是"gpui.h 在 C++17 下编不过"（实测 20 个 error，真实站点
# 全在 src/gpui/gpui.h 的 6 处：1 个 concept + 5 个 requires 约束）。tpc 给 bridge TU 写死
# -std=c++17（NativeSourceProjectBuilder.php:104），那是编译器的常量、不是我们的选项。降级之后
# "bridge 能不能直接 include gpui.h"就变成可实测的问题，而不是只能相信的前提。
#
# 语义必须等价，不是"能编过就算"：concept EmitsEvent = requires { sizeof(EventEmitter<T,E>); }
# 判的是"存在 EventEmitter<T,E> 的特化"——主模板只有声明 ⇒ 不完整类型 ⇒ sizeof 硬错误。
# C++17 的等价写法是偏序特化 + decltype(sizeof(...), void())：替换失败退回 false_type，
# 有特化命中 true_type。筛的是同一件事，所以 5 个调用点约束换成 enable_if 后不放宽任何东西。
#
# 幂等：marker = "EmitsEventV"。任何一处没换成都整体回滚，不留半补丁头文件。
# 用法：GPUI_DIR=<沙箱> cmd/patch-concepts.sh   （默认 build/gpui-kit-cpp）

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
HDR="$GPUI_DIR/src/gpui/gpui.h"

[[ -f "$HDR" ]] || { echo "ERROR: 找不到 ${HDR}（先 cmd/sync-upstream.sh）" >&2; exit 1; }
if grep -q "EmitsEventV" "$HDR"; then
    echo "patch-concepts: 已落（marker EmitsEventV 在），跳过"
    exit 0
fi

HDR="$HDR" python3 - <<'PY'
import os, re, sys
p = os.environ['HDR']
s = open(p, encoding='utf-8').read()
orig = s

TRAIT = """// [patch-concepts] C++20 的 concept EmitsEvent 降级成 C++17 trait，判据不变：
// 主模板只有声明 ⇒ EventEmitter<T,E> 不完整 ⇒ sizeof 是硬错误 ⇒ 替换失败退回
// false_type；有特化就命中偏序特化取 true_type。
template <typename T, typename E, typename = void>
struct EmitsEventImpl : std::false_type {};
template <typename T, typename E>
struct EmitsEventImpl<T, E,
                      decltype(sizeof(EventEmitter<T, E>), void())> : std::true_type {};
template <typename T, typename E>
inline constexpr bool EmitsEventV = EmitsEventImpl<T, E>::value;"""

CONCEPT = """template <typename T, typename E>
concept EmitsEvent = requires {
    sizeof(EventEmitter<T, E>);
};"""

subs = [(CONCEPT, TRAIT)]

# 5 个 requires 站点：约束换成 enable_if，返回类型搬进第二个模板实参。
# 用正则而不是整串匹配——上游这 5 处的换行/缩进各不相同（有的返回类型与函数名同行、
# 有的分行），整串匹配一改排版就静默失配。
def sub_requires(text):
    pat = re.compile(r'requires EmitsEvent<([^>]+)>\s+(Subscription|void)\s+(\w+\()')
    text, n = pat.subn(
        lambda m: 'typename std::enable_if<EmitsEventV<%s>, %s>::type\n%s' % (m.group(1), m.group(2), m.group(3)),
        text)
    return text, n

for a, b in subs:
    if a not in s:
        sys.exit('MISS（concept 站点形状与预期不符，拒绝半补丁）: %.60s...' % a)
    s = s.replace(a, b, 1)

s, nreq = sub_requires(s)
if nreq != 5:
    sys.exit('requires 站点降级到 %d 处，预期 5 处 —— 拒绝半补丁' % nreq)

# Window::paint 的 `= {}`：C++20 才允许在省略初始器时用 **explicit** 的默认构造做拷贝列表
# 初始化，C++17 判 "chosen constructor is explicit in copy-initialization"。真凶在下一层——
# PaintCtx 的成员 `Vec<HitRect> hits;`，而 Vec 的默认构造是 explicit（src/base.h:576）。
# 写成 `PaintCtx paint;`（default-init）而不是 `paint{}`：省略初始器时成员走**直接**初始化，
# explicit 构造合法；跑的还是同一条构造函数，初值一模一样。
EXPLICIT = ('    PaintCtx paint = {};', '    PaintCtx paint;')
if EXPLICIT[0] not in s:
    sys.exit('MISS（PaintCtx 的 = {} 站点形状与预期不符）')
s = s.replace(EXPLICIT[0], EXPLICIT[1], 1)

# 残留检查：还有 concept/requires 约束就是没换全。
left = len(re.findall(r'^\s*concept |^requires EmitsEvent', s, re.M))
if left:
    sys.exit('仍有 %d 处 C++20 约束未降级' % left)

open(p, 'w', encoding='utf-8').write(s)
print('patch-concepts: 7 处降级完成（1 concept + 5 requires + 1 explicit copy-init）')
PY

# 落不成就回滚，绝不留半补丁头文件。
if ! grep -q "EmitsEventV" "$HDR"; then
    echo "ERROR: 补丁未生效" >&2
    exit 1
fi
echo "重新生成 amalgam: (cd $GPUI_DIR && bun cmd/build.ts -rel hello_world)"
