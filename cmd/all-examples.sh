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
# 共享产物（gpui.o / libgpui_shim.a）是所有示例共用的。cmd/sync-upstream.sh 的上游指纹闸门
# 命中时不会清掉沙箱缓存（2026-10-19 实测完整链 6.5s，其中 amalgam 只 1.41s），所以默认
# 全员 --no-native 仍是最快的：每支只付 tpc 那一步、且不重跑 shim。first-native 用于
# "这棵树还没建过共享产物"或**改了 cpp-src/ 之后**——--no-native 会跳过 build-shim，
# 改完 cpp-src 还走它会静默链接到上一版 libgpui_shim.a，量出的是旧代码。
#
# 退出码非 0 = 至少一支失败；哪一支失败看它自己那一行（各示例的自检报告是边跑边打的）。
set -uo pipefail
cd "$(dirname "$0")/.."

MODE="${1:-selftest}"
case "$MODE" in
  selftest|shotdiff|both|first-native) ;;
  # 用法块用标记定位而不是行号：头部注释改一次，'sed -n N,Mp' 就成了假读数，
  # 打出来的"用法"会缺行或掺进正文——那条红字是给人看的，错行比错字更难发现。
  # 区间 = "# 用法：" 之后到第一条裸露的 "#"（本文件的分节线）为止，头部怎么改都不断。
  *) awk '/^# 用法：$/{f=1;next} /^#$/{f=0} f' "$0" >&2; exit 2 ;;
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
    trees="$(grep -c gdfrac "/tmp/pix-$ex.log")"
    if [[ $rc -eq 0 ]]; then
      printf '%-12s shotdiff  rc=%s 树=%s\n' "$ex" "$rc" "$trees"
    else
      # 失败现场先留档再复跑：日志会被复跑覆盖，而"哪一棵红了"正是事后最想知道的那一行
      # （今天就有两次 FAIL(1) 因为只留了尾巴而数不到行）。复跑只用来**分类**，不用来豁免：
      # 抖动仍计红——把抖动当通过就是回归藏身的地方。
      # 可复述的证据是下面打印出来的 `现场=` 一行；`/tmp` 的副本只是当场还能看时的便利
      # ——实测跑完十几分钟后那些文件已不在，别把文件当记录。
      cp "/tmp/pix-$ex.log" "/tmp/pix-$ex-fail1.log"
      bad="$(grep -F '✗' "/tmp/pix-$ex-fail1.log" | head -3 | tr '\n' ' ')"
      php bin/gpui shotdiff check "$ex" > "/tmp/pix-$ex-retry.log" 2>&1
      rc2=$?
      if [[ $rc2 -eq 0 ]]; then
        printf '%-12s shotdiff  rc=%s 树=%s  复跑绿＝抖动（仍计红，别当通过） 现场=%s\n' \
          "$ex" "$rc" "$trees" "${bad:-无✗行}"
      else
        printf '%-12s shotdiff  rc=%s 树=%s  复跑仍红＝需单跑复核（可能是上下文相关抖动，13:45 overlay 就是这样） 现场=%s\n' \
          "$ex" "$rc" "$trees" "${bad:-无✗行}"
      fi
      fails=$((fails + 1))
    fi
  fi
done

echo "ALL EXAMPLES: $([[ $fails -eq 0 ]] && echo OK || echo "FAIL($fails)")"
exit $((fails > 0 ? 1 : 0))
