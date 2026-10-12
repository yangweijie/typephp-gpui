#!/usr/bin/env bash
# cmd/patch-key-view.sh — 让 key_down / key_up 在 view 层就直接进宿主队列，不进 QuickJS。
#
# 为什么改在 view.cpp 而不是 DispatchKey：点击之所以能到 PHP，是因为 cmd/patch-view.sh 把
# ScriptView::OnClick 里的 `runtime->DispatchClick(...)` **整行换成**了 gpuiEnqueueClick 弱钩子
# —— 事件根本没走去 QuickJS 的那条派发路。先前两次试图在 ShellRuntime::DispatchKey 内部塞早
# 返回都失败（任务 #53、progress.md Phase 14c-6/7）：那是已经进了 JS 函数之后再想退出来，位置不对。
# 跟着 OnClick 的同一形状做，才是这个工程里被验证过有效的做法。
#
# 语义与边界：
#   * 弱钩子签名 (ScriptView*, Ctx*, uint64_t callbackId, unsigned rune, int down)：`self` 让
#     宿主能反查回自己的窗口对象（这在 DispatchKey 里做不到），rune 是按下的字符码点。
#   * 弱实现是空函数 ⇒ 内嵌 JS 的原样宿主行为不变；cpp-src/gpui_shim.cpp 的强实现把字符以
#     UTF-8 入窗口队列，PHP 的 key_down handler 因此读得到 value。
#   * 与 OnClick 一致的取舍：不再走 JS 派发，就没有脚本端的 stopPropagation；`event->propagate`
#     保持原值，传播由宿主自己决定。
#
# 用法：GPUI_DIR=<gpui-kit-cpp> cmd/patch-key-view.sh   （默认 build/gpui-kit-cpp）

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
VIEW="$GPUI_DIR/src/shell/view.cpp"
MARK="typephp-gpui: gpuiEnqueueKey"

[[ -f "$VIEW" ]] || { echo "ERROR: view.cpp not found" >&2; exit 1; }

if grep -qF "$MARK" "$VIEW"; then
    echo "view.cpp: already patched, skip"
else
    grep -qF "gpuiEnqueueClick" "$VIEW" \
        || { echo "ERROR: 需先跑 cmd/patch-view.sh（要先有那组弱钩子声明区）" >&2; exit 1; }
    echo "patching OnScriptKey with the host key hook..."
    cp "$VIEW" "$VIEW.key.bak"
    python3 - "$VIEW" "$MARK" <<'PYEOF'
import sys
path, mark = sys.argv[1], sys.argv[2]
src = open(path).read()

decl_anchor = "__attribute__((weak)) void gpuiEnqueueSlider(ScriptView*, Ctx*, uint64_t, float) {}"
if src.count(decl_anchor) != 1:
    sys.exit("hook decl anchor count=%d" % src.count(decl_anchor))
src = src.replace(
    decl_anchor,
    decl_anchor + "\n"
    + "// " + mark + ": key events go straight to the host queue (strong body lives in\n"
    + "// cpp-src/gpui_shim.cpp) instead of ScriptView → ShellRuntime::DispatchKey → QuickJS.\n"
    + "__attribute__((weak)) void gpuiEnqueueKey(ScriptView*, Ctx*, uint64_t, unsigned, const char*, int) {}",
    1)

old_fn = '''void ScriptView::OnScriptKey(ScriptView* self, Ctx* cx, const KeyEvent* event,
                             int64_t callback) {
    if (!self || !self->runtime || !event) return;
    // A handler that leaves `propagate` set passes the keystroke on outwards,
    // which is cx.propagate(); clearing it is cx.stop_propagation().
    bool propagate = true;
    self->runtime->DispatchKey((shell::CallbackId)callback, *event, &propagate,
                               cx->win, cx->app);
    const_cast<KeyEvent*>(event)->propagate = propagate;
}
'''
if src.count(old_fn) != 1:
    sys.exit("OnScriptKey body anchor count=%d" % src.count(old_fn))
new_fn = '''void ScriptView::OnScriptKey(ScriptView* self, Ctx* cx, const KeyEvent* event,
                             int64_t callback) {
    if (!self || !self->runtime || !event) return;
    // ''' + mark + ''' — hand the keystroke to the host queue directly. The replaced
    // body routed through ShellRuntime::DispatchKey, which unconditionally builds a
    // QuickJS payload; on this host (PHP, no script context) a real keyDown on an
    // element with onKeyDown faulted inside JS_NewObject — see task #53. Same shape as
    // the OnClick/OnHover hooks: no script-side stopPropagation contract anymore, the
    // host owns propagation.
    // KeyEvent 的注释写明 vk 与 ch 互斥（"0 for key down/up" / "0 for a typed
    // character"），所以两个都带上：字符事件给 rune，按键事件给 KeyName(vk)——
    // 后者正是被换掉的那条 JS 负载里 `key` 字段的来历。
    char kname[32];
    kname[0] = '\\0';
    if (event->vk) {
        Str n = KeyName(event->vk);
        int m = len(n);
        if (m > (int)sizeof(kname) - 1) m = (int)sizeof(kname) - 1;
        for (int i = 0; i < m; i++) kname[i] = n.s[i];
        kname[m] = '\\0';
    }
    gpuiEnqueueKey(self, cx, (uint64_t)callback, (unsigned)event->ch, kname,
                   event->down ? 1 : 0);
}
'''
src = src.replace(old_fn, new_fn, 1)
open(path, "w").write(src)
print("  view.cpp: OnScriptKey -> gpuiEnqueueKey")
PYEOF
fi

nMark=$(grep -cF "$MARK" "$VIEW" || true)
nHook=$(grep -cF "gpuiEnqueueKey" "$VIEW" || true)
nOld=$(grep -cF "DispatchKey((shell::CallbackId)callback" "$VIEW" || true)
if [[ "$nMark" != "2" || "$nHook" != "4" || "$nOld" != "0" ]]; then
    echo "ERROR: self-check failed (marker=$nMark want 2, hook=$nHook want 4, leftover=$nOld want 0)" >&2
    exit 1
fi
echo "Patch complete."
