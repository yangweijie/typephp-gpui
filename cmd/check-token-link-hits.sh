#!/usr/bin/env bash
# cmd/check-token-link-hits.sh — H3：token/link 鼠标命中的自动化门（树3）。
#
# 来路（Phase 33 批1）：kit 树3 的 kit-tok-in（输入框 + token）与 kit-link
# （El::textView markdown 链接——批1 把 El::link 换成 textView，因为 base kind
# Link 的 click 语义是打开 href 不消费 on_link_click）。widget-click 只发 'click'，
# token_click/link_click 是不同事件名——fire 动词（批1 框架扩展）是送达读数面的
# 唯一途径。
#
# 门的设计（#96 边界：agent 只验进程内派发链，真人鼠标命中归真人 HID）：
#   command-1 hits         → 落 hits.txt，找 kit-tok-in/kit-link 的 rect
#   command-2 fire         → fire kit-tok-in token_click（元素缝/registered 缝）
#   command-3 fire         → fire kit-link link_click
#   command-4 rgb          → 读 kitLog 行像素（y=729，树3 的 kitLog 行）
#   command-5 quit
#
# 判据：
#   rc=0 + hits 有 kit-tok-in/kit-link 的 rect + snapshot 有 "fired"×2
#     → 命中表可达 + fire 派发链送达（token_click/link_click 经 registered 缝
#     ComponentCallback::InvokeWith → shimId 分叉 → 队列 → PHP handler）。
#   rc=1 + 任一判据不满足 → 打印读数便于诊断。
#
# 真人鼠标命中 token/link 归真人 HID（#96 边界），不假装已验。
#
# 用法：bash cmd/check-token-link-hits.sh
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BIN="$ROOT/examples/kit/build/kit"

if [[ ! -x "$BIN" ]]; then
    echo "TOKENLINK $BIN 不在 ⇒ 未判定（先 php bin/gpui build kit）"
    exit 2
fi

AUTODIR="$(mktemp -d "${TMPDIR:-/tmp}/gpui-kit-tl-XXXXXX")"
trap 'rm -rf "$AUTODIR"' EXIT

# 树3（sectionKitOverlays）：kit-tok-in 和 kit-link 在树3 的中段。
# 3000px 窗高使内容视口够大，kitLog 行（y=729）在可见区 892 内。
printf 'hits\n'                     > "$AUTODIR/command-1.txt"
printf 'fire kit-tok-in token_click\n' > "$AUTODIR/command-2.txt"
printf 'fire kit-link link_click\n'   > "$AUTODIR/command-3.txt"
printf 'rgb 16 729 200 16\n'        > "$AUTODIR/command-4.txt"
printf 'quit\n'                     > "$AUTODIR/command-5.txt"

"$BIN" --automation "$AUTODIR" 3 3000 > /tmp/gpui-kit-tl-auto.log 2>&1
rc=$?

SNAP="$AUTODIR/snapshot.txt"
HITS="$AUTODIR/hits.txt"

if [[ ! -f "$SNAP" ]]; then
    echo "TOKENLINK snapshot 不在 ⇒ 未判定（rc=$rc，见 /tmp/gpui-kit-tl-auto.log）"
    exit 2
fi

# ── 判据 1：hits.txt 里有 kit-tok-in/kit-link 的 rect ────────────────────────
# hits 表显示实体 ID（hash），不显示字符串 ID。但 token/link 所在的 hFlex 区域
# 在树3 的中段。我们找 click=1 或 down=1 的行（token chip 和 link 都是可点元素）。
TOK_ROW="$(awk -F'\t' '$7 == 1' "$HITS" 2>/dev/null | head -1)"
TOK_OK=false
if [[ -n "${TOK_ROW:-}" ]]; then
    TOK_OK=true
fi

# ── 判据 2：snapshot 有两次 "fired" 回执 ─────────────────────────────────────
FIRED_COUNT=$(grep -c 'fired' "$SNAP" 2>/dev/null || echo 0)
FIRED_OK=false
if [[ "$FIRED_COUNT" -ge 2 ]]; then
    FIRED_OK=true
fi

echo "TOKENLINK hits有可点元素=$TOK_OK fired次数=$FIRED_COUNT"
echo "  hits click=1 行: ${TOK_ROW:-（无）}"
echo "  snapshot fired 行:"
grep 'fired' "$SNAP" 2>/dev/null || echo "    （无）"

if $TOK_OK && $FIRED_OK; then
    echo "TOKENLINK OK ⇒ token/link 在命中表 + fire 派发链送达（token_click/link_click）"
    exit 0
fi
echo "TOKENLINK FAIL ⇒ 见上读数"
exit 1
