#!/usr/bin/env bash
# cmd/patch-dispatch-string-shimid.sh — 没有 JS 引擎时，Dispatch* 不许先造 JS 值。
#
# 为什么要这一支（2026-11-02，真人第二轮"点 link 崩"查出来的）：
# `ShellRuntime::DispatchString` 把 **JS 值当作实参**在调用点就造好：
#     Dispatch(this, callback, JS_NewStringLen(impl->context, …), window, app);
# 我们这宿主没有 JS 上下文 ⇒ `JS_NewStringLen` 解引用空指针 ⇒ 进程当场没。崩溃栈逐字对上
# （kit-2026-11-03-084951.ips）：
#     JS_NewStringLen ← gpui::ShellRuntime::DispatchString ← gpui::DispatchMouseUp
#     ← -[GpuiView release:button:]     EXC_BAD_ACCESS / KERN_INVALID_ADDRESS at 0x10
# 触发点是真鼠标点 TextView 里的链接（`El::textView('[打开文档](https://…)', id)->onLinkClick()`，
# 走 view.cpp:178 `OnTextLink`）。selftest 的 linkDelivery 门走 `fire` 派发缝，从不经过这里 ⇒ 没被覆盖。
#
# 语义与边界：
#   * 先给 shim 注册的回调留一条早返回（`entry->shimId` 非零 ⇒ 投本窗口队列，带 href 载荷），
#     这条与 patch-dispatch-cb.sh 的 DispatchArgs 路由同形。
#   * 再挡"根本没有 JS 引擎"这一眼：`impl->context` 为空就不再造值，告警一次后返回。
#     这不是新增兜底——上游 `Dispatch()` 本来就在 `!live` 时直接返回，只是值已经先造好了；
#     改的只有"造值的时机"。
#   * **实测到的未解部分**（2026-11-02，别当成已修）：kit 树 3 那条链接的 id 是 29，
#     `impl->callbacks.Get(29)` 返回空、`box->callbacks.count(29)` 也是 0 —— 两张表都不认识它，
#     所以现在既路由不到 PHP、也不该假装路由到了。真鼠标点链接的**送达**是 task #114，
#     本补丁只保证"不崩 + 原因看得见"。
#   * DispatchNumbers / DispatchSignal 是同一个形状（JS 值当实参），一起按同一条规则拦。
#
# 补丁后须重跑 amalgam：php bin/gpui build kit（不带 --no-native）。
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
SRC="$GPUI_DIR/src/shell/runtime.cpp"
MARK="typephp-gpui: no-js dispatch guard"

[[ -f "$SRC" ]] || { echo "ERROR: $SRC not found" >&2; exit 1; }

grep -qF "$MARK" "$SRC" && { echo "runtime.cpp: already patched"; exit 0; }

echo "runtime.cpp: DispatchString/Numbers/Signal 加 no-JS 守卫"
python3 - "$SRC" "$MARK" <<'PYEOF'
import sys

path, mark = sys.argv[1], sys.argv[2]
s = open(path, encoding='utf-8').read()

decl = """// """ + mark + """ — one-time notice, so a host without a JS engine says why a
// script callback was dropped instead of dying inside JS_NewStringLen.
static void WarnNoJsContext(shell::CallbackId callback) {
    static bool warned = false;
    if (warned) return;
    warned = true;
    std::fprintf(stderr,
                 "kit: callback %llu is not in the runtime callback table, so building "
                 "its JS argument would enter QuickJS with nothing to call - dropped "
                 "instead of crashing (TextView links reach PHP only through the "
                 "dispatch seam; see task #114)\\n",
                 (unsigned long long)callback);
}

void gpuiEnqueueShimString(App* app, EntityId view, uint64_t shimId,
                           const char* value);

"""

anchor_string = """void ShellRuntime::DispatchString(shell::CallbackId callback, Str value,
                                  Window* window, App* app) {
    Dispatch(this, callback,
             JS_NewStringLen(impl->context, value.s ? value.s : "",
                             (size_t)len(value)),
             window, app);
}"""
if anchor_string not in s:
    sys.exit("DispatchString anchor missing — 上游这段改了形状，补丁得重写")

body_string = """void ShellRuntime::DispatchString(shell::CallbackId callback, Str value,
                                  Window* window, App* app) {
    // A shim-registered callback routes to the window's queue with href as the
    // payload; JS entries (shimId == 0) and everything else fall through.
    CallbackEntry* shimEntry = impl ? impl->callbacks.Get(callback) : nullptr;
    if (shimEntry && shimEntry->shimId && app) {
        gpuiEnqueueShimString(app, shimEntry->view, shimEntry->shimId,
                              value.s ? value.s : "");
        return;
    }
    if (!shimEntry) {
        // Measured 2026-11-02: impl->context is NOT null here, and the crash still
        // happened inside JS_NewStringLen - what makes this callback undeliverable
        // is that the runtime table does not know the id at all. Dispatch() itself
        // returns early on exactly that condition ("!live"); the only bug was that
        // the argument had already been built by then.
        WarnNoJsContext(callback);
        return;
    }
    if (!impl || !impl->context) {
        WarnNoJsContext(callback);
        return;
    }
    Dispatch(this, callback,
             JS_NewStringLen(impl->context, value.s ? value.s : "",
                             (size_t)len(value)),
             window, app);
}"""
s = s.replace(anchor_string, body_string, 1)

anchor_signal = """void ShellRuntime::DispatchSignal(shell::CallbackId callback, Window* window,
                                  App* app) {
    Dispatch(this, callback, JS_NewObject(impl->context), window, app);
}"""
if anchor_signal not in s:
    sys.exit("DispatchSignal anchor missing")
body_signal = """void ShellRuntime::DispatchSignal(shell::CallbackId callback, Window* window,
                                  App* app) {
    if (!impl || !impl->context || !impl->callbacks.Get(callback)) {
        WarnNoJsContext(callback);
        return;
    }
    Dispatch(this, callback, JS_NewObject(impl->context), window, app);
}"""
s = s.replace(anchor_signal, body_signal, 1)

# 守卫函数定义在**第一个**用它的函数之前（DispatchNumbers 在源码里最早出现）。
anchor_numbers = """void ShellRuntime::DispatchNumbers(shell::CallbackId callback,
                                   const float* values, int count,
                                   Window* window, App* app) {
    JSValue array = JS_NewArray(impl->context);"""
if anchor_numbers not in s:
    sys.exit("DispatchNumbers anchor missing")
body_numbers = decl + """void ShellRuntime::DispatchNumbers(shell::CallbackId callback,
                                   const float* values, int count,
                                   Window* window, App* app) {
    if (!impl || !impl->context || !impl->callbacks.Get(callback)) {
        WarnNoJsContext(callback);
        return;
    }
    JSValue array = JS_NewArray(impl->context);"""
s = s.replace(anchor_numbers, body_numbers, 1)

open(path, 'w', encoding='utf-8').write(s)
print("  patched DispatchString + DispatchSignal + DispatchNumbers")
PYEOF

echo "done. 重建：php bin/gpui build kit（不带 --no-native）"
