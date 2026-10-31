#!/usr/bin/env bash
# cmd/patch-host-action-cb.sh — 批2 A5：元素级 on_action（ActionCallback op 糖）。
#
# 上游 op 面全通（vendored）：spec.h 的 SpecOpKind::ActionCallback、materialize.cpp
# :309-317 收集（actions[].action = ShellActionOf(op.name) + callback）、
# :2850-2862 element->OnAction(bound->action, Listen(OnScriptAction, bound))、
# view.cpp OnScriptAction → runtime DispatchAction → Dispatch → DispatchArgs。
# DispatchArgs 里 patch-dispatch-cb.sh 已落 shim 分叉（entry->shimId →
# gpuiEnqueueShimCallback → shim 强符号 → PHP 事件队列）——**materialize/runtime
# 零改动**。断的两缝都在 shim 侧（本仓 cpp-src/）：
#   ① bridge ops 循环不认 "on_action" op（落 method 分支发到上游 method 表，
#     上游没有这个名字 → 静默丢）；
#   ② 没有 shim 入口把 (elem_id, 动作名) 变成 SpecOp{kind=ActionCallback} +
#     runtime 回调表条目——op.callback 是 JS CallbackId 语义，PHP 路要
#     PushShimCallback 的 rt id（token/link 族同理由，批4 先例）。
# 方案⑴（ask 定案）：新 shim 入口 gpui_shim_on_action(win, spec, actionName, id)
# ——队列短名固定 "action"，PHP 处理器照旧 App::on(elem_id, 'action', fn)；
# 合成验收走 gpui_selftest_fire_registered(handle, elem_id, 'action')
# （查 shimCallbacks["elem_id\x1faction"] 键 → InvokeWith → shimId 分叉）。
#
# 限制（文档同步）：一个元素多次 onAction 会共用同一队列通道
#（(elem_id, "action") 幂等键）——多动作分到不同元素。
#
# 幂等：三文件 marker 在 = 已打；缺哪个补哪个；自检计数。
# 用法：cmd/patch-host-action-cb.sh（补 cpp-src/，走 build-shim.sh 重编
# libgpui_shim.a——bin/gpui build 的链路会自动跑本脚本再重编）。

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BRIDGE="$ROOT/cpp-src/gpui_bridge.cc"
SERVICES="$ROOT/cpp-src/gpui_shim_services.inc"
SHIM_H="$ROOT/cpp-src/gpui_shim.h"
MARK="typephp-gpui: host action callback"

for f in "$BRIDGE" "$SERVICES" "$SHIM_H"; do
    [[ -f "$f" ]] || { echo "ERROR: $f not found" >&2; exit 1; }
done

# ── ① bridge ops 循环：on_action 分支 ──
if grep -qF "$MARK" "$BRIDGE"; then
    echo "gpui_bridge.cc: already patched, skip"
else
    cp "$BRIDGE" "$BRIDGE.actioncb.bak"
    python3 - "$BRIDGE" "$MARK" <<'PYEOF'
import sys
path, mark = sys.argv[1], sys.argv[2]
src = open(path).read()
old = '''            } else if (opKind == "callback") {
                std::string type = eventType(name);
                gpui_shim_callback(win, spec, id.c_str(), type.c_str());
            } else if (opKind == "slot") {'''
if src.count(old) != 1:
    sys.exit("bridge callback-branch anchor count=%d" % src.count(old))
new = '''            } else if (opKind == "callback") {
                std::string type = eventType(name);
                gpui_shim_callback(win, spec, id.c_str(), type.c_str());
            } else if (opKind == "on_action") {
                // typephp-gpui: host action callback. The op's name IS the
                // action's name (materialize interns it via ShellActionOf and
                // binds element->OnAction); the queue keeps the fixed short
                // type "action" so the PHP side reads App::on(id, 'action').
                gpui_shim_on_action(win, spec, name.c_str(), id.c_str());
            } else if (opKind == "slot") {'''
src = src.replace(old, new, 1)
open(path, "w").write(src)
print("  gpui_bridge.cc: on_action op branch added")
PYEOF
fi

# ── ② shim 入口 gpui_shim_on_action（services.inc，紧随 gpui_shim_callback）──
if grep -qF "$MARK" "$SERVICES"; then
    echo "gpui_shim_services.inc: already patched, skip"
else
    cp "$SERVICES" "$SERVICES.actioncb.bak"
    python3 - "$SERVICES" <<'PYEOF'
import sys
path = sys.argv[1]
src = open(path).read()
anchor = '''void gpui_shim_attach(void* win, uint32_t parent, uint32_t child) {'''
if src.count(anchor) != 1:
    sys.exit("services.inc anchor count=%d" % src.count(anchor))
new = '''// typephp-gpui: host action callback (patch-host-action-cb.sh) — the element
// level on_action(action_name) op. Shape mirrors gpui_shim_callback: a shim
// event entry under the fixed short type "action" plus a runtime-side table
// entry (PushShimCallback — the dispatch point ScriptView::OnScriptAction →
// DispatchAction → DispatchArgs only fires shim-registered ids; a bare shim
// id would drop silently), then the SpecOp itself with kind=ActionCallback
// and name=the action's name (materialize interns it via ShellActionOf).
uint64_t gpui_shim_on_action(void* win, uint32_t spec,
                             const char* action_name, const char* elem_id) {
    auto* box = static_cast<Box2*>(win);
    uint64_t cb = box->newCallback(elem_id, "action");
    uint64_t cbId = cb;
    if (g_runtime && box->viewEntity.IsValid()) {
        uint64_t rt = g_runtime->PushShimCallback(box->viewEntity, cb);
        if (rt) cbId = rt;
    }
    gpui::shell::SpecOp so;
    so.kind = gpui::shell::SpecOpKind::ActionCallback;
    so.name = cppStr(action_name ? action_name : "");
    so.callback = cbId;
    if (cbId != cb) {
        std::string key = std::string(elem_id ? elem_id : "") + "\\x1f" + "action";
        box->shimCallbacks[key] = cbId;
    }
    box->arena().PushOp(spec, so);
    return cbId;
}

''' + anchor
src = src.replace(anchor, new, 1)
open(path, "w").write(src)
print("  gpui_shim_services.inc: gpui_shim_on_action added")
PYEOF
fi

# ── ③ shim.h 声明 ──
if grep -qF "$MARK" "$SHIM_H"; then
    echo "gpui_shim.h: already patched, skip"
else
    cp "$SHIM_H" "$SHIM_H.actioncb.bak"
    python3 - "$SHIM_H" <<'PYEOF'
import sys
path = sys.argv[1]
src = open(path).read()
anchor = '''uint64_t gpui_shim_callback(void* win, uint32_t spec, const char* elem_id,'''
if src.count(anchor) != 1:
    sys.exit("shim.h anchor count=%d" % src.count(anchor))
new = '''// typephp-gpui: host action callback (patch-host-action-cb.sh).
uint64_t gpui_shim_on_action(void* win, uint32_t spec,
                             const char* action_name, const char* elem_id);
''' + anchor
src = src.replace(anchor, new, 1)
open(path, "w").write(src)
print("  gpui_shim.h: gpui_shim_on_action declared")
PYEOF
fi

# ── 自检 ──
n_b=$(grep -cF "$MARK" "$BRIDGE" || true)
n_s=$(grep -cF "$MARK" "$SERVICES" || true)
n_h=$(grep -cF "$MARK" "$SHIM_H" || true)
if [[ "$n_b" != "1" || "$n_s" != "1" || "$n_h" != "1" ]]; then
    echo "ERROR: self-check failed (bridge=$n_b services=$n_s shim.h=$n_h; all want 1)" >&2
    exit 1
fi
echo "Patch complete."
