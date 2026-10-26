#!/usr/bin/env bash
# cmd/sync-upstream.sh — 把 vendored 的上游子树复制到构建沙箱。
#
# 为什么要沙箱：gpui-kit-cpp 的源码现在**入库**（upstream/gpui-kit-cpp，22M 源码子集），
# 而它的构建会往自己树里写 .work/（amalgam）与 out/（对象文件），补丁脚本还要就地改
# src/。那些都不该出现在 git 工作树里——否则每次 build 都弄脏跟踪文件。
# 所以：upstream/ 只读，build/gpui-kit-cpp/ 是可牺牲的副本，GPUI_DIR 指它。
#
# 用法：cmd/sync-upstream.sh                 # 闸门命中就跳过
#       cmd/sync-upstream.sh --force-sync    # 强制 rsync（忽略 stamp）
#       GPUI_FORCE_SYNC=1 cmd/sync-upstream.sh   # 同上，环境变量形式（给 bin/gpui 那条链用）
#       GPUI_DIR=/some/where cmd/sync-upstream.sh   # 外部源码树模式：不同步，直接用
#
# 为什么不再无条件 rsync：`--delete` 会把沙箱里的构建缓存 .work/（9.4MB amalgam）
# 与 out/（27MB gpui.o）一起删掉，于是每次 `gpui build` 都触发一次全量 amalgam 重编
# （实测 56.7s，见 task_plan.md 成本模型）。而 build.ts 本身按 mtime 增量判缓存，
# 缓存只要不被删就命中（实测热构建 1.4s）。所以这里加一道"上游没变就跳过"的闸门：
#   · 上游源码树内容哈希 + 本仓 cmd/patch-*.sh 的内容哈希都写进 stamp；
#   · stamp 命中且沙箱在 ⇒ 整个同步跳过，缓存与已打补丁的 src/ 原样保留；
#   · 不命中才 rsync，且 rsync 现在排除 .work/ 与 out/（上游更新后保留缓存，
#     只让 build.ts 重编真正受影响的 TU，而不是全删全编）。
# stamp 是缓存语义、不是正确性边界：补丁是否落位仍由 `bin/gpui doctor` 的 marker
# 清单校验，沙箱半删状态下 build 会当场报缺文件而不会静默用脏缓存。
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$ROOT/upstream/gpui-kit-cpp"

# 逃生阀。闸门命中就跳过同步，代价是"stamp 说没变就不是没变"——怀疑沙箱被手工改坏、
# 或 rsync 上次被打断时，需要一条不删 stamp 文件就能强制重同步的路。环境变量那份是给
# `php bin/gpui build` 那条链用的：它调本脚本不转发参数，只能靠环境传。
force=0
for arg in "$@"; do
    case "$arg" in
        --force-sync) force=1 ;;
        # 变量一律写花括号：本机 /bin/bash 是 3.2.57，在 C.UTF-8 locale + set -u 下，
        # 裸 $arg 紧跟一个全角字符会把参数名解析成一个野名字，症状是
        # "arg?: unbound variable"、rc=1 而不是设计的 rc=2、而且设计的报错根本没打出来。
        # 这条是实测复现过的（见 audit-2026-10-19.md），不是风格问题。
        *) echo "ERROR: 未知参数：${arg}（可用：--force-sync，或 GPUI_FORCE_SYNC=1）" >&2; exit 2 ;;
    esac
done
if [[ -n "${GPUI_FORCE_SYNC:-}" && "${GPUI_FORCE_SYNC}" != "0" ]]; then
    force=1
fi

# 显式给了 GPUI_DIR 且不是默认沙箱 = 用户自备源码树，别去覆盖它。
if [[ -n "${GPUI_DIR:-}" && "${GPUI_DIR}" != "$ROOT/build/gpui-kit-cpp" ]]; then
    echo "GPUI_DIR=${GPUI_DIR}（外部源码树，跳过同步）"
    exit 0
fi

[[ -d "$SRC" ]] || { echo "ERROR: 找不到 vendored 源码：$SRC" >&2; exit 1; }
DST="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
STAMP="$ROOT/build/.upstream-stamp"
mkdir -p "$DST"

# 上游树 + 补丁集的内容指纹。为什么把 patch-*.sh 也算进去：补丁变了而 stamp 没变，
# 沙箱里会留着旧补丁的 src/，症状是"改了补丁却没生效"，比缓存失效更难查。
# 整个管道跑在 $SRC 的子 shell 里：上游用相对路径、补丁用绝对路径，混着也能读；
# 若在仓库根跑 xargs，shasum 会因为 cwd 不对而把每个文件都报成 "No such file"。
upstream_hash() {
    (
        cd "$SRC" || exit 1
        { find . -type f -print0
          find "$ROOT/cmd" -maxdepth 1 -name 'patch-*.sh' -print0; } \
            | sort -z | xargs -0 shasum -a 256 | shasum -a 256 | awk '{print $1}'
    )
}
# 指纹流水线自己失败（shasum/xargs 报错、文件读一半消失）与指纹长得不像指纹，是两种失败，
# 都要有可读的报错。放在 if 里取是必须的：set -e + pipefail 会让赋值那一行就中止脚本，
# 于是"算不出来"的时候只看到 xargs 的原始报错——不知道下一步该干什么。
if ! want="$(upstream_hash)"; then
    echo "ERROR: 上游指纹流水线失败（见上面的报错）。不同步、不写 stamp。" >&2
    echo "       可能是 $SRC 或 cmd/patch-*.sh 在读的过程中变了/不可读；" >&2
    echo "       确认完好后可强制同步：cmd/sync-upstream.sh --force-sync。" >&2
    exit 1
fi
# 指纹必须是 64 位十六进制，否则一律拒同步。管道中间的 shasum 若静默失败
# （文件读一半消失、权限变化），尾部 awk 照样会吐出一个"合法长度"的短哈希——
# 那种哈希一旦写进 stamp，下次还会命中同一个坏值，于是"上游没变"的结论就建立在
# 一份没读全的内容上。宁可这里红一次，也别让错误缓存自己续期。
if [[ ! "$want" =~ ^[0-9a-f]{64}$ ]]; then
    echo "ERROR: 上游指纹算不出来（得到 '$want'，期望 64 位十六进制）" >&2
    echo "       不同步、不写 stamp。先查 $SRC 是否完整可读，或先跑 cmd/sync-upstream.sh --force-sync。" >&2
    exit 1
fi

if [[ $force -eq 0 && -f "$STAMP" && "$want" == "$(cat "$STAMP")" && -d "$DST/src" ]]; then
    echo "upstream 未变（stamp 命中），跳过同步，保留构建缓存：$DST"
    exit 0
fi
[[ $force -eq 1 ]] && echo "强制同步（--force-sync / GPUI_FORCE_SYNC，忽略 stamp）"

# -a 保时间戳（build.ts 按 mtime 判缓存）、--delete 让沙箱里被撤掉的补丁文件不会残留；
# .work/ 与 out/ 是编译缓存，build.ts 每次运行会自行重生，删掉只会制造全量重编。
rsync -a --delete \
    --exclude='.work/' --exclude='out/' \
    "$SRC/" "$DST/"
# 同步已经完成，stamp 只是下一次的缓存凭据：写不进来也只该变慢，不该把已成功的同步
# 报成失败（那种失败的误导性在于，读日志的人会以为沙箱还是旧的）。
if ! printf '%s\n' "$want" > "$STAMP"; then
    echo "WARN: stamp 写入失败（${STAMP}）。同步已完成，下次仍会走 rsync（只慢 0.03s）。" >&2
fi
# 只数源文件：.work/ 与 out/ 现在是保留的缓存，混进来这个数就从"上游带下来多少文件"
# 变成"缓存多大"，而那件事 `du -sh` 说得更准。
echo "synced: $SRC → $DST ($(find "$DST" -type f -not -path '*/.work/*' -not -path '*/out/*' | wc -l | tr -d ' ') 个源文件)"
