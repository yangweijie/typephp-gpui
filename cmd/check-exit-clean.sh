#!/usr/bin/env bash
# cmd/check-exit-clean.sh — "正常退出不得段错误"的门（#101）。
#
# 来路：交互 quit / 关窗走的是"main 正常返回 → exit → __cxa_finalize_ranges → 静态析构"，
# 而 bridge 的登记表里存着 PHP callable（php::Variant）。Zend 引擎先关、析构后跑 ⇒
# ~Variant → zval_ptr_dtor 摸已释放内存，SIGSEGV（hello-2026-10-28-235342.ips、
# hello-2026-10-29-001350.ips）。--selftest 走 PHP exit()，不跑这些析构，所以十几支示例的
# 自检全绿也照不出这条路——真人一关窗就崩（用户报过"两个窗口都崩溃了"）。
#
# 免人手复现：--automation <dir> 里放一条 quit。**目录每轮必须新建**：命令文件吃过就
# unlink，复用旧目录会让它永远等下一号（实测挂住，那是夹具错不是门红）。
#
# 判定：rc=0 且回执里 commands=1 与 `#1 quit -> bye` 都在（"没跑起来"不许冒充通过）。
# 挂住超过看门狗 ⇒ rc=2 未判定（挂住≠红）；产物建不出来同样 rc=2。
# 红侧读数（修前实测）：hello 139、input 139；修后两支都 0。
#
# 用法：bash cmd/check-exit-clean.sh [示例名…]   （默认 hello input；composer check:exit-clean）
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT" || exit 9

EXAMPLES=()
for a in "$@"; do
    case "$a" in
        --*) echo "EXIT-CLEAN 未知开关：$a（本门只收示例名）⇒ 未判定"; exit 2 ;;
        *) EXAMPLES+=("$a") ;;
    esac
done
if [[ ${#EXAMPLES[@]} -eq 0 ]]; then
    EXAMPLES=(hello input)
fi

fails=0
for ex in "${EXAMPLES[@]}"; do
    bin="$ROOT/examples/$ex/build/$ex"
    log="/tmp/exit-clean-$ex.log"
    if [[ ! -f "$ROOT/examples/$ex/project.macos.yml" ]]; then
        echo "EXIT-CLEAN $ex 没有 project.macos.yml ⇒ 未判定"
        exit 2
    fi
    # 先建再量：失败的建设会留下上一版二进制，不建就量等于量陈旧产物。
    if ! php bin/gpui build "$ex" --no-native > "$log" 2>&1; then
        echo "EXIT-CLEAN $ex 构建失败（见 $log）⇒ 未判定"
        exit 2
    fi
    dir="$(mktemp -d "/tmp/exit-clean-$ex.XXXXXX")"
    printf 'quit\n' > "$dir/command-1.txt"
    "$bin" --automation "$dir" >> "$log" 2>&1 &
    pid=$!
    waited=0
    hung=0
    while kill -0 "$pid" 2>/dev/null; do
        sleep 1
        waited=$((waited + 1))
        if [[ $waited -ge 40 ]]; then
            kill "$pid" 2>/dev/null
            wait "$pid" 2>/dev/null
            hung=1
            break
        fi
    done
    if [[ $hung -eq 1 ]]; then
        echo "EXIT-CLEAN $ex 挂住 ${waited}s（看门狗杀掉）⇒ 未判定"
        rm -rf "$dir"
        exit 2
    fi
    if wait "$pid"; then rc=0; else rc=$?; fi
    snap="$dir/snapshot.txt"
    consumed=0
    if [[ -f "$snap" ]] && grep -qF 'commands=1' "$snap" && grep -qF '#1 quit -> bye' "$snap"; then
        consumed=1
    fi
    if [[ $rc -eq 0 && $consumed -eq 1 ]]; then
        echo "EXIT-CLEAN OK $ex（quit 真被吃过，退出 rc=0）"
    else
        echo "EXIT-CLEAN FAIL $ex（rc=$rc 回执确认=$consumed；期望 rc=0 且 commands=1）"
        fails=$((fails + 1))
    fi
    rm -rf "$dir"
done

if [[ $fails -ne 0 ]]; then
    echo "EXIT-CLEAN GATE FAILED（$fails 支）"
    exit 1
fi
echo "EXIT-CLEAN GATE OK（${#EXAMPLES[@]} 支）"
exit 0