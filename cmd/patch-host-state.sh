#!/usr/bin/env bash
# cmd/patch-host-state.sh — 给非 JS 宿主开一个"造 catalog 保留态实体"的入口。
#
# 为什么要这一支（#81）：catalog 的带样式表单族（Input / NumberInput / OtpInput / Slider /
# ColorPicker / Calendar / DatePicker / TimeField）构造时都要吃一个 `state` 实参
# （`SchemaEntity("InputState")` 等，见 component_shell/retained_forms/mod.cpp:553-554, 624），
# 而那颗 state 实体的**创建**只存在于 JS 绑定函数里：
#   runtime.cpp:3215 `NativeRegisteredState` —— 它要 `shell::ScopeCurrentHost()`
#   （"retained component state can only be created during a live Window/App call"）
#   和 JSValue 的错误通道，PHP 宿主两样都没有。
# 读侧反倒不用改：`MaterializeRequest::State` → `ShellRuntime::ComponentState(handle, kind, …)`
# 早就是 public（runtime.h:40），而我们 shim 的 `buildComponentArgs` 也已经会把
# `SchemaKind::Entity` 映成 `out->handle = ga.num`（cpp-src/gpui_shim.cpp:632-637）。
# 唯一私有的就是那张表：`impl->componentStates`（runtime.cpp:406）与它的 `Insert`。
#
# 所以这里只补一个方法：把 window / app / application 令牌与**已经建好的实参数组**
# 显式传进来，其余照 JS 那条路的顺序走（descriptor->factory → componentStates.Insert）。
#
# 语义与边界：
#   * 实参数组由调用方（我们的 shim）按 `descriptor->arguments` 的 schema 自己建——
#     上游不校验宿主给的实参。这不是放宽：`NewInputState` 自己就会 `count != 2` 直接
#     `build->Fail(...)`（mod.cpp:195-207），工厂仍然是唯一的守门人。
#   * `application` 是世代 key：JS 那条路用 `ScopeCurrentApplication()`。宿主传它自己
#     注册过的那一枚令牌（我们整个宿主只有一个，见 gpui_shim.cpp 的 registerHostApplication），
#     这样 `ReleaseApplication`（runtime.cpp:11822）与 `Clear()`（:11176）这两条既有回收路
#     仍然罩得住宿主造的实体——绕开它就是"每帧无界增长"那一族的复发。
#   * 成功/失败用返回值说，handle 走出参：上游 ComponentStateStore 的 `nextHandle`
#     从 **0** 起（component_registry.h:522），第一颗实体的 handle 就是 0——把返回值
#     当哨兵等于"每次建第一颗都报失败"（#81 首跑正是这一条）。
#   * 每条 return false 都必须写 `*error`（借用于 `a`），与 `ComponentState` 的约定一致；
#     静默的 false 到了宿主侧只剩一句"state creation failed"，什么也定位不了。
#   * 顺带一个读数 `ComponentStateCount()`：宿主在脚本域外造实体，"每帧复用"与
#     "每帧漏一颗"只有实体表长度能区分。
#
# 前置：cmd/patch-retained.sh（它先把 `ShellRuntimeAccess::Impl` 这条内部路打通）。
# 用法：GPUI_DIR=<gpui-kit-cpp> cmd/patch-host-state.sh   （默认 build/gpui-kit-cpp）

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
SRC="$GPUI_DIR/src/shell/runtime.cpp"
HDR="$GPUI_DIR/src/shell/runtime.h"
MARK="typephp-gpui: host-side retained component state"

[[ -f "$SRC" && -f "$HDR" ]] || { echo "ERROR: runtime.{cpp,h} not found" >&2; exit 1; }

if grep -qF "$MARK" "$SRC" && grep -qF 'bool NewHostState(' "$HDR"; then
    echo "runtime.{cpp,h}: already patched, skip"
    exit 0
fi
# 打过去但签名不同 = 树是旧的（没有 cmd/sync-upstream.sh 重刷）。这里不叠第二份定义：
# 两个重载能过编译，却会把"哪一支被调"变成签名细节，而这一支的存在理由正是签名诚实。
if grep -qF "$MARK" "$SRC"; then
    echo "ERROR: $SRC 里已有旧签名的 NewHostState；先跑 cmd/sync-upstream.sh 重刷上游树" >&2
    exit 1
fi

grep -qF 'CreateRetainedInput' "$SRC" \
    || { echo "ERROR: 需先跑 cmd/patch-retained.sh（ShellRuntimeAccess::Impl 那条路）" >&2; exit 1; }

echo "patching runtime for host-side component state creation..."
cp "$SRC" "$SRC.state.bak"
cp "$HDR" "$HDR.state.bak"

python3 - "$SRC" "$HDR" "$MARK" <<'PYEOF'
import sys

src_path, hdr_path, mark = sys.argv[1], sys.argv[2], sys.argv[3]

# ── 头文件：声明贴在 public 的 ComponentState 后面（一读一写，成对放）──
hdr = open(hdr_path).read()
decl_anchor = "    void* ComponentState(uint64_t handle, const char* kind,"
if hdr.count(decl_anchor) != 1:
    sys.exit("header anchor count=%d" % hdr.count(decl_anchor))
line_end = hdr.index(";", hdr.index(decl_anchor)) + 1
hdr = hdr[:line_end] + """

    // """ + mark + r""". The JS creator (NativeRegisteredState) needs a live script
    // scope for both the application identity and the JS error channel; a host
    // adapter has neither, so it passes the window/app/application token and the
    // argument array it built itself. `arguments` must match the state descriptor's
    // schema — the descriptor's own factory is what rejects a wrong count. Returns
    // false with *error set (borrowed from `a`) if nothing was created; on success
    // *handle carries the new entity. The handle is an out parameter because the
    // store's nextHandle starts at 0, so 0 is a legitimate entity, not a failure
    // sentinel.
    bool NewHostState(const char* kind, Window* window, App* app,
                      void* application,
                      const shell::ComponentArgument* arguments, int count,
                      uint64_t* handle, Str* error, Arena* a);

    // How many retained component states the store holds. The host creates them
    // outside the script scope, so this is the only instrument that tells a
    // per-frame reuse from a per-frame leak.
    int ComponentStateCount() const;""" + hdr[line_end:]
open(hdr_path, "w").write(hdr)

# ── 实现：贴在 ComponentState 定义后面 ──
src = open(src_path).read()
impl_anchor = """void* ShellRuntime::ComponentState(uint64_t handle, const char* kind,
                                   Str* error, Arena* a) const {
    if (!impl) return nullptr;
    return impl->componentStates.Get(handle, kind, error, a);
}"""
if src.count(impl_anchor) != 1:
    sys.exit("impl anchor count=%d" % src.count(impl_anchor))
src = src.replace(
    impl_anchor,
    impl_anchor + """

// """ + mark + """ — the host-side twin of NativeRegisteredState's body, minus
// the script scope: the caller names the window/app/application and hands over the
// arguments it validated against the descriptor's schema itself. Order is the JS
// path's: look the descriptor up, run its factory, then insert into the store, and
// destroy the half-built value if either step refuses.
bool ShellRuntime::NewHostState(const char* kind, Window* window, App* app,
                                void* application,
                                const shell::ComponentArgument* arguments,
                                int count, uint64_t* handle, Str* error,
                                Arena* a) {
    if (handle) *handle = 0;
    ShellRuntimeImpl* impl = ShellRuntimeAccess::Impl(this);
    if (!impl || !impl->components || !kind || !handle) {
        // 每条 return false 都得留下名字：调用方只看得到 *error，静默的 false 在
        // 宿主侧读出来就是一句"state creation failed"，什么也定位不了。
        if (error) *error = StrL("no runtime to create component state in");
        return false;
    }
    const shell::StateDescriptor* descriptor =
        impl->components->StateOfKind(Str(kind, (int)std::strlen(kind)));
    if (!descriptor) {
        // 不带 kind 名字：上游的 fmt 明确拒绝 const char*（base.h 里
        // `FmtArg(const char*) = delete`，第一次就是栽在这一句上）。调用方（我们的 shim）
        // 本来就先查过一次描述符、报错时带上 kind；这里只兜住直接经 C++ 调用的路。
        if (error) *error = StrL("unknown component state");
        return false;
    }
    shell::StateBuild build;
    build.window = window;
    build.app = app;
    build.a = a;
    if (!descriptor->factory || !descriptor->factory(&build, arguments, count)) {
        if (error) *error = build.error;
        if (build.value && build.destroy) build.destroy(build.value);
        return false;
    }
    if (!impl->componentStates.Insert(descriptor->kind, application, build.value,
                                      build.destroy, handle, error, a)) {
        *handle = 0;
        return false;
    }
    return true;
}

// Same store, read side: how many retained states are live. Const member, so it
// reads `impl` directly (ShellRuntimeAccess::Impl takes a non-const receiver).
int ShellRuntime::ComponentStateCount() const {
    return impl ? impl->componentStates.Len() : 0;
}""",
    1)
open(src_path, "w").write(src)
print("runtime.{cpp,h}: ShellRuntime::NewHostState added")
PYEOF

# 补丁自检：声明与实现都得在，且只落一次。签名必须是"bool + uint64_t* handle"：
# 返回 handle 本身会让第一颗实体（handle==0）被调用方误判为失败。
grep -qF 'bool NewHostState(' "$HDR" \
    || { echo "ERROR: 头文件声明没落上" >&2; exit 1; }
[[ $(grep -cF 'uint64_t* handle, Str* error,' "$HDR") -eq 1 ]] \
    || { echo "ERROR: 头文件 handle 出参不对" >&2; exit 1; }
[[ $(grep -cF 'int ComponentStateCount() const;' "$HDR") -eq 1 ]] \
    || { echo "ERROR: 实体数读数没落上（头文件应恰好一处）" >&2; exit 1; }
[[ $(grep -cF 'int ShellRuntime::ComponentStateCount() const' "$SRC") -eq 1 ]] \
    || { echo "ERROR: 实体数读数的实现没落上" >&2; exit 1; }
[[ $(grep -c 'bool ShellRuntime::NewHostState' "$SRC") -eq 1 ]] \
    || { echo "ERROR: 实现落重了（应恰好一处）" >&2; exit 1; }
[[ $(sed -n '/bool ShellRuntime::NewHostState/,/^}/p' "$SRC" | { grep -c 'return 0' || true; }) -eq 0 ]] \
    || { echo "ERROR: 实现里还留着 return 0（旧签名残留）" >&2; exit 1; }
