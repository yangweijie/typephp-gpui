#!/usr/bin/env bash
# cmd/check-kit-arms.sh — kit 域两道"前提式/判别臂"门的批量入口（不进 CI）。
#
# 为什么不并进 check-kit-gates：这两条的 rc 语义与硬门**不同**——它们没有"红=回归"这一档，
# 只有"拿到实证(0)"与"前提不成立⇒未判定(2)"。把相反约定并到一个 rc 里，读出来就是假结论。
# 它们要的是"当场能跑、有人复盘"，所以入口放 composer，不放 CI。
#
# 用法：bash cmd/check-kit-arms.sh   （composer check:kit-arms）
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT" || exit 2

case "${1:-}" in
    "") ;;
    *) echo "KIT-ARMS 未知开关：${1}（本门不收参数）⇒ 未判定"; exit 2 ;;
esac

if [[ ! -x examples/kit/build/kit ]]; then
    echo "KIT-ARMS 前置产物缺：examples/kit/build/kit（先 php bin/gpui build kit）⇒ 未判定"
    exit 2
fi

proven=0
pending=0
for g in check-scrollbar-click.sh check-scrollbar-paint.sh check-token-paint-png.sh; do
    echo "--- $g"
    bash "cmd/$g"
    rc=$?
    printf 'ARM %s rc=%s\n' "$g" "$rc"
    if [[ $rc -eq 0 ]]; then proven=$((proven + 1)); else pending=$((pending + 1)); fi
done

echo "KIT-ARMS 实证=$proven 未判定=$pending（未判定=前提不成立，不是回归）"
exit 0
