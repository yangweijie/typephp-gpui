#!/usr/bin/env bash
# cmd/check-kit-gates.sh — kit 域四道确定性门的批量入口（可达性收口）。
#
# 为什么要它：这四道门各自能跑，但**没有任何入口点名它们**（composer / CI / tests 全无）——
# 于是"写好的门"等于"没人跑的门"，下一轮漂移不会有人发现。这条给它们一个入口，
# 另两条"要人/前提式"的臂走 cmd/check-kit-arms.sh（rc 语义不同，不并到同一判定里）。
#
# 聚合口径（不合并相反约定）：这里只收**有"红=回归"语义**的硬门（0/1/2）；
# 只有"实证(0)/前提不成立(未判定 2)"两档的前提式门走 cmd/check-kit-arms.sh（不进 CI），
# 否则 CI 会把"这台机器没给条件"读成失败。
#   · 每道门先打它自己的判定行，再打 `GATE <名> rc=<n>`——**包装器 rc 不是门判定**，
#     读结论要读每道门那一行。
#   · 任一 rc=1 ⇒ 总 rc=1（真回归）；否则任一 rc=2 ⇒ 总 rc=2（未判定，不冒充通过）；否则 0。
# 前置：examples/kit/build/kit 已构建（CI 的 Domain examples 循环之后即满足）。
#
# 用法：bash cmd/check-kit-gates.sh   （composer check:kit-gates；CI 原生 job 一步）
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT" || exit 2

case "${1:-}" in
    "") ;;
    *) echo "KIT-GATES 未知开关：${1}（本门不收参数）⇒ 未判定"; exit 2 ;;
esac

if [[ ! -x examples/kit/build/kit ]]; then
    echo "KIT-GATES 前置产物缺：examples/kit/build/kit（先 php bin/gpui build kit）⇒ 未判定"
    exit 2
fi

fails=0
undetermined=0
for g in check-accordion-toggle.sh check-token-link-hits.sh; do
    echo "--- $g"
    bash "cmd/$g"
    rc=$?
    printf 'GATE %s rc=%s\n' "$g" "$rc"
    case "$rc" in
        0) ;;
        1) fails=$((fails + 1)) ;;
        *) undetermined=$((undetermined + 1)) ;;
    esac
done

if [[ $fails -ne 0 ]]; then
    echo "KIT-GATES FAILED（$fails 道红）"
    exit 1
fi
if [[ $undetermined -ne 0 ]]; then
    echo "KIT-GATES 未判定（$undetermined 道没拿到判定，别当通过）"
    exit 2
fi
echo "KIT-GATES OK（2 道全判定通过）"
exit 0
