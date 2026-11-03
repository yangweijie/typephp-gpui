#!/usr/bin/env bash
# cmd/patch-scroll-id-ordinal.sh — 未命名元素的路径身份必须混入兄弟序数。
#
# 为什么要这一支（2026-11-04，#112：hover 盒滚动条常驻）：
# IdCollect 给未命名元素**零路径贡献**（here = parent 原样继承）——两个结构相同的
# 未命名兄弟（kit 树 4 的 hover/always 两盒）坍缩到同一路径 → 同一 scrollId →
# ScrollFadeFor 按 id 取条目 → **两盒共用一条可见性状态**。每帧互踢：
# always 盒 settle 到 1（want=true），hover 盒启动 1→0 的退出——退出永不完结，
# 两盒的 thumb 永远以 α≈1 画出（机器复现：scroll 后 3 秒截图 hover 盒仍挂着；
# 真人轮里树 1/树 4 的"hover 盒滚动条常驻"同一根因）。--shot 两盒同色与此不矛盾：
# 那条路没有任何滚动事件，want 全 0，互踢无从发生。
# 顺带修掉同一来源的潜在点击碰撞：未命名的 clickFromPath/focusFromPath 兄弟
# 同样共享 clickId/focusId（click 闸的 pressedId/upId 匹配会被它污染）。
#
# 修法：IdCollect 增加 ordinal 形参；未命名元素若是路径使用者
# （scrollFromPath/clickFromPath/focusFromPath）就把兄弟序数折进路径。
# 命名元素照旧（IdFold(parent, id)），已命名的路径值一字不变；
# 未命名但非路径使用者的元素 here 也不变（它们的 pathId 只是中间量）。
# Rust 无此问题：它比较完整 id 路径，从不对单段哈希（GPUI_ID_CHECK 注释自述）。
#
# 补丁后须重跑 amalgam：php bin/gpui build kit（不带 --no-native）。
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
SRC="$GPUI_DIR/src/gpui/gpui.cpp"
MARK="typephp-gpui: unnamed path users fold the sibling ordinal"

if grep -qF "$MARK" "$SRC"; then
    echo "gpui.cpp: already patched"
    exit 0
fi

python3 - "$SRC" "$MARK" <<'PYEOF'
import sys
path, mark = sys.argv[1], sys.argv[2]
s = open(path).read()

# 1) IdFoldInt：与 IdFold 同族的数值折叠（确定性，无分配）。
anchor1 = "static int IdToClick(uint32_t h) {"
assert s.count(anchor1) == 1, s.count(anchor1)
helper = """// typephp-gpui: numeric sibling fold — same FNV family as IdFold, for unnamed
// elements whose only path contribution is their position among siblings.
static uint32_t IdFoldInt(uint32_t parent, int v) {
    uint32_t h = parent ? parent : 2166136261u;
    h ^= (uint8_t)'/';
    h *= 16777619u;
    int x = v;
    for (int i = 0; i < 4; i++) {
        h ^= (uint8_t)(x & 0xff);
        h *= 16777619u;
        x >>= 8;
    }
    return h;
}

""" + anchor1
s = s.replace(anchor1, helper)

# 2) IdCollect：加 ordinal 形参；未命名路径使用者折序数。
old_sig = """static void IdCollect(El* e, uint32_t parent) {
    uint32_t here = parent;
    if (e->id.s && len(e->id) > 0) {
        here = IdFold(parent, e->id);
    }
    e->pathId = here;"""
new_sig = """static void IdCollect(El* e, uint32_t parent, int ordinal = -1) {
    uint32_t here = parent;
    if (e->id.s && len(e->id) > 0) {
        here = IdFold(parent, e->id);
    } else if (ordinal >= 0 &&
               (e->scrollFromPath || e->clickFromPath ||
                e->style.focusFromPath)) {
        // """ + mark + """ (task #112): an unnamed element contributes nothing
        // to the path, so two unnamed siblings collapsed to one id — two
        // overflow-scrollbar boxes with different modes then shared one fade
        // entry and fought over it every frame (the always box settled it
        // visible while the hover box started the exit), so neither bar ever
        // hid. Unnamed click/focus users had the same collision on their ids.
        here = IdFoldInt(parent, ordinal);
    }
    e->pathId = here;"""
assert s.count(old_sig) == 1, s.count(old_sig)
s = s.replace(old_sig, new_sig)

# 3) 递归调用带序数。
old_walk = """    for (El* c = e->first; c; c = c->next) {
        IdCollect(c, here);
    }
}"""
new_walk = """    int childOrdinal = 0;
    for (El* c = e->first; c; c = c->next, childOrdinal++) {
        IdCollect(c, here, childOrdinal);
    }
}"""
assert s.count(old_walk) == 1, s.count(old_walk)
s = s.replace(old_walk, new_walk)

# 4) IdsCollectChildren 同步带序数。
old_children = """    for (El* c = e->first; c; c = c->next) {
        IdCollect(c, e->pathId);
    }
}"""
new_children = """    int childOrdinal = 0;
    for (El* c = e->first; c; c = c->next, childOrdinal++) {
        IdCollect(c, e->pathId, childOrdinal);
    }
}"""
assert s.count(old_children) == 1, s.count(old_children)
s = s.replace(old_children, new_children)

open(path, 'w').write(s)
print("ordinal fold inserted")
PYEOF

echo "patch-scroll-id-ordinal.sh: done"
