#!/usr/bin/env bash
# cmd/patch-host-data.sh — SchemaCallback 数据回路：materialize 途中同步回调 PHP 并回填返回值。
#
# 断点（批6 实证）：DataTable rows / 图表 rows 走 SnapshotRowsWith → SnapshotWith →
# CallInLayout，而 shim 登记的回调（PushShimCallback）entry->function = JS_UNDEFINED——
# JS 路 JS_Call(undefined) 必然失败，rows 快照拿不到数组（此前只能"响亮失败"：探针
# dataTableFails/chartError 记一笔、画一颗错误 Div）。
#
# 形状照 cmd/patch-host-list.sh 的四段式（宿主登记回调 → shim 分叉 → php::call →
# 返回值回填）：在 CallInLayout 顶部加 shimId 分叉——命中时调弱缝合
# gpuiInvokeShimDataCallback（本文件放弱空实现，shim 的强符号覆盖），由 shim 反查
# Box2 的 callbacks[shimId] 拿 (elemId,type)，经 bridge 登记的函数指针 php::call 宿主
# 处理器，返回值经 ComponentDataToJs 转回 JSValue——SnapshotWith 的既有
# ComponentDataFromJs 消费路径一行不改。普通 JS 回调（shimId=0）一条不改。
#
# 幂等：marker 在 = 已打；自检计数。用法：GPUI_DIR=<gpui-kit-cpp> cmd/patch-host-data.sh
# （默认 build/gpui-kit-cpp）

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
SRC="$GPUI_DIR/src/shell/runtime.cpp"
MARK="typephp-gpui: host data loop"

[[ -f "$SRC" ]] || { echo "ERROR: runtime.cpp not found" >&2; exit 1; }

if grep -qF "$MARK" "$SRC"; then
    [[ $(grep -cF 'gpuiInvokeShimDataCallback(cx->app, entry->view, entry->shimId,' "$SRC") -eq 1 &&
       $(grep -cF '__attribute__((weak)) bool gpuiInvokeShimDataCallback(' "$SRC") -eq 1 ]] \
        || { echo "ERROR: host data marker exists but its branch/stub is incomplete" >&2; exit 1; }
    echo "runtime.cpp: host data loop already patched, skip"
    exit 0
fi

echo "patching CallInLayout for the host data loop..."
cp "$SRC" "$SRC.data.bak"

python3 - "$SRC" "$MARK" <<'PYEOF'
import sys
path, mark = sys.argv[1], sys.argv[2]
src = open(path).read()

# 1) 弱缝合声明 + 弱空实现（放在 CallInLayout 之前）
fn = "static JSValue CallInLayout(ShellRuntime* runtime, CallbackEntry* entry,"
if src.count(fn) != 1:
    sys.exit("CallInLayout anchor count=%d" % src.count(fn))
seam = """// %s seam overridden by the shim's strong symbol (which knows Box2/callbacks).
// No-op default: the amalgam links standalone, and a data callback then fails
// loudly at materialize (the shim's absence is a loud error, not a silent one).
__attribute__((weak)) bool gpuiInvokeShimDataCallback(
    App* app, EntityId view, uint64_t shimId,
    const shell::ComponentDataValue* arguments, int count,
    shell::ComponentDataValue* out, Arena* a, Str* error) {
    (void)app; (void)view; (void)shimId; (void)arguments; (void)count; (void)out; (void)a;
    if (error) *error = StrL("host data callback is not installed");
    return false;
}

""" % mark
src = src.replace(fn, seam + fn, 1)

# 2) CallInLayout 顶部的 shimId 分叉
anchor = "    JSContext* ctx = impl->context;\n    shell::SpecArena* outer = impl->scratch;"
if src.count(anchor) != 1:
    sys.exit("CallInLayout body anchor count=%d" % src.count(anchor))
branch = anchor + """
    // %s — a shim-registered callback belongs to the host: call PHP
    // synchronously and convert its return value. Same seam shape as the list
    // renderer (patch-host-list.sh): weak here, strong in the shim. The host
    // call needs no layout scope, so it returns before the scope is entered.
    if (entry->shimId) {
        Arena* scratch = ArenaNew();
        shell::ComponentDataValue value;
        Str error;
        bool ok = gpuiInvokeShimDataCallback(cx->app, entry->view, entry->shimId,
                                             arguments, count, &value, scratch, &error);
        char msg[256];
        std::snprintf(msg, sizeof(msg), "%%s",
                      (error && error.s) ? error.s : "host data callback failed");
        if (!ok) {
            ArenaDelete(scratch);
            return JS_ThrowTypeError(ctx, "%%s", msg);
        }
        // value 的字符串指着 scratch：先经 ComponentDataToJs 复制进 JS（QuickJS
        // 字符串是引用计数副本），再释放 arena——顺序反了就是悬垂。
        JSValue js = ComponentDataToJs(ctx, value);
        ArenaDelete(scratch);
        return js;
    }
""" % mark
src = src.replace(anchor, branch, 1)

open(path, "w").write(src)
print("runtime.cpp: CallInLayout routes shim callbacks to the host")
PYEOF

[[ $(grep -c 'gpuiInvokeShimDataCallback(cx->app' "$SRC") -eq 1 ]] \
    || { echo "ERROR: 宿主分叉没落上（应恰好一处）" >&2; exit 1; }
[[ $(grep -c '__attribute__((weak)) bool gpuiInvokeShimDataCallback(' "$SRC") -eq 1 ]] \
    || { echo "ERROR: 弱缝合没落上（应恰好一处）" >&2; exit 1; }
