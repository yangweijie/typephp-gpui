#!/usr/bin/env bash
# cmd/all-examples.sh — 把 examples/ 下所有示例跑一遍（自检 / 像素基线）。
#
# 为什么要这么一条：拆成按功能域独立示例之后，"全量本地跑一遍"从一句口号变成逐支敲命令的事。
# 示例清单不在这里维护，也不在 CI 里维护——**就是 examples/*/project.macos.yml**，
# 新拆一支不需要改这里任何一处（CI 的 Domain examples 步骤同理）。
#
# 用法：
#   bash cmd/all-examples.sh              # = selftest：每支 build --no-native + --selftest
#   bash cmd/all-examples.sh first-native # 第一支走完整链（同步→补丁→amalgam→shim），其余 --no-native
#   bash cmd/all-examples.sh shotdiff     # 每支 shotdiff check（需已构建）
#   bash cmd/all-examples.sh both         # 先自检再像素
#
# 共享产物（gpui.o / libgpui_shim.a）是所有示例共用的，而 cmd/sync-upstream.sh 的
# `rsync --delete` 每次都会清掉沙箱里的 .work/ 与 out/ ——所以**一轮里只有第一支值得走完整链**
# （几分钟），其余加 --no-native（每支约 40 秒）。**改了 cpp-src/ 之后必须用 first-native**。
#
# 退出码非 0 = 至少一支失败；哪一支失败看它自己那一行（各示例的自检报告是边跑边打的）。
set -uo pipefail
cd "$(dirname "$0")/.."

MODE="${1:-selftest}"
case "$MODE" in
  selftest|shotdiff|both|first-native) ;;
  *) sed -n '9,15p' "$0" >&2; exit 2 ;;
esac

fails=0
first=1
for yml in examples/*/project.macos.yml; do
  ex="$(basename "$(dirname "$yml")")"
  if [[ "$MODE" != "shotdiff" ]]; then
    if [[ "$MODE" == "first-native" && $first -eq 1 ]]; then
      php bin/gpui build "$ex" > "/tmp/all-$ex.log" 2>&1 \
        && "./examples/$ex/build/$ex" --selftest >> "/tmp/all-$ex.log" 2>&1
    else
      php bin/gpui selftest "$ex" --no-native > "/tmp/all-$ex.log" 2>&1
    fi
    rc=$?
    printf '%-12s selftest  rc=%s\n' "$ex" "$rc"
    [[ $rc -ne 0 ]] && fails=$((fails + 1))
    first=0
  fi
  if [[ "$MODE" == "shotdiff" || "$MODE" == "both" ]]; then
    php bin/gpui shotdiff check "$ex" > "/tmp/pix-$ex.log" 2>&1
    rc=$?
    printf '%-12s shotdiff  rc=%s 树=%s\n' "$ex" "$rc" "$(grep -c gdfrac "/tmp/pix-$ex.log")"
    [[ $rc -ne 0 ]] && fails=$((fails + 1))
  fi
done

echo "ALL EXAMPLES: $([[ $fails -eq 0 ]] && echo OK || echo "FAIL($fails)")"
exit $((fails > 0 ? 1 : 0))
