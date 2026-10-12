#!/usr/bin/env bash
# cmd/check-c-abi.sh — 守住"C ABI 防火墙"这个主张本身。
#
# cpp-src/gpui_shim.h 是全项目唯一跨语言边界的契约：bridge（C++17）与 shim（C++20）之间
# 只能走它。它一旦漏进 C++ 的东西（模板、类、std:: 类型、甚至一个 <string> 的 include），
# 报错会晚到几分钟后的链接期，而不是在改下的那一刻。所以这里用**纯 C 编译器**过一遍：
# C 编得过 = 头里确实只有 C。
#
# 用法：bash cmd/check-c-abi.sh        （无参数；非 0 = 头里漏了 C++）
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
HDR="$ROOT/cpp-src/gpui_shim.h"
[[ -f "$HDR" ]] || { echo "ERROR: 找不到 $HDR" >&2; exit 1; }
command -v clang >/dev/null || { echo "ERROR: clang 未找到" >&2; exit 1; }

# -Wall -Wextra 且把两个"边界味道"警告升成错误：extern "C" 里包了 C++ 才需要的东西、
# 或声明用了 C 没有的链接规范。
if ! clang -x c -std=c11 -fsyntax-only -Wall -Wextra -Werror=extern-c-compat \
     "$HDR" 2>/tmp/check-c-abi.err; then
    echo "ERROR: gpui_shim.h 不是纯 C（防火墙漏了）" >&2
    cat /tmp/check-c-abi.err >&2
    exit 1
fi

# 文本侧兜底：clang 的 C 模式对个别 C++ 记号仍可能宽容，直接扫一遍。
# 尖括号 include 只放行 C 标准头（stdint.h 提供 uint32_t，合法）；其余按 C++ 头处理。
SYMS=/tmp/check-c-abi.symbols
: > "$SYMS"
grep -nE '^[[:space:]]*#include[[:space:]]*<' "$HDR" \
    | grep -vE '<(stdint|stddef|stdbool|limits|inttypes)\.h>' >> "$SYMS" || true
grep -nE '(^|[^[:alnum:]_])(namespace|template|std::|using[[:space:]]+namespace)' "$HDR" >> "$SYMS" || true
if [[ -s "$SYMS" ]]; then
    echo "ERROR: gpui_shim.h 里出现 C++ 记号（防火墙漏了）：" >&2
    cat "$SYMS" >&2
    rm -f "$SYMS"
    exit 1
fi
rm -f "$SYMS"

echo "gpui_shim.h: 纯 C11 编得过，且无 C++ 记号"
