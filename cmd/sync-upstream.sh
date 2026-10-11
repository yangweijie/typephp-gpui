#!/usr/bin/env bash
# cmd/sync-upstream.sh — 把 vendored 的上游子树复制到构建沙箱。
#
# 为什么要沙箱：gpui-kit-cpp 的源码现在**入库**（upstream/gpui-kit-cpp，22M 源码子集），
# 而它的构建会往自己树里写 .work/（amalgam）与 out/（对象文件），补丁脚本还要就地改
# src/。那些都不该出现在 git 工作树里——否则每次 build 都弄脏跟踪文件。
# 所以：upstream/ 只读，build/gpui-kit-cpp/ 是可牺牲的副本，GPUI_DIR 指它。
#
# 用法：cmd/sync-upstream.sh            # 同步（增量，--delete 保持与 upstream 一致）
#       GPUI_DIR=/some/where cmd/sync-upstream.sh   # 外部源码树模式：不同步，直接用
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$ROOT/upstream/gpui-kit-cpp"

# 显式给了 GPUI_DIR 且不是默认沙箱 = 用户自备源码树，别去覆盖它。
if [[ -n "${GPUI_DIR:-}" && "${GPUI_DIR}" != "$ROOT/build/gpui-kit-cpp" ]]; then
    echo "GPUI_DIR=$GPUI_DIR（外部源码树，跳过同步）"
    exit 0
fi

[[ -d "$SRC" ]] || { echo "ERROR: 找不到 vendored 源码：$SRC" >&2; exit 1; }
DST="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
mkdir -p "$DST"
# -a 保时间戳（build.ts 按内容哈希判缓存）、--delete 让沙箱里被撤掉的补丁文件不会残留。
rsync -a --delete "$SRC/" "$DST/"
echo "synced: $SRC → $DST ($(find "$DST" -type f | wc -l | tr -d ' ') 个文件)"
