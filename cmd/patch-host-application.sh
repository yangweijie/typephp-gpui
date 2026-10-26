#!/usr/bin/env bash
# cmd/patch-host-application.sh — 让非 QuickJS 宿主的视图也有"应用身份"，
# 好让带 component app effects 的组件（MenuBar）能 materialize。
#
# 状态：**已接线**（在 bin/gpui 的补丁清单里，见 :67 marker 表与 :162 执行列表），
#   `php bin/gpui doctor` 会校验它的 marker `HostApplyComponentAppEffect` 落在 runtime.cpp。
#
# 这里曾经写着"未接线，启用它会引入偶发段错误（--liveclick 3 次崩 1 / 6 次崩 1，rc=139）"。
# 那句话对现在的树已经不成立：崩溃的根因不是"宿主该不该有应用身份"，而是**延迟安装的
# app effect 在 apply 时按 ScriptView 去读 self->runtime**——宿主实体是 `EntityNewRaw` 建的
# 裸对象（我们的视图是 8 字节 `PhpView{Box2*}`），于是越界读。本补丁同时给了
# `HostApplyComponentAppEffect` 这条不看 ScriptView 的 trampoline，那才是把 rc=139 停掉的东西；
# 另一处配合在 shim：全宿主共用**一个**进程期身份令牌（`cpp-src/gpui_shim.cpp` 的
# `registerHostApplication`），因为按实体发令牌会生出两条世代、各自装卸同一份菜单。
# 接线后的实测与推导过程记在 progress.md「Phase 16」与任务 #28。
#
# 若这条路径再出现 rc=139：先怀疑"每帧 new OwnedMenus + Replace"的延迟安装节奏（上游生命周期），
# 而不是先把补丁摘掉——摘掉的症状是 El::menuBar 又装不出菜单条，且**不会报错**。
#
# 背景：`component_shell/lifecycle/menu.cpp:268 MaterializeBar` 装菜单前要先问
#     request->AppEffects(&effects)
# 而 `shell::MaterializeRequest::AppEffects`（runtime.cpp:13287）只认
#     runtime->ScriptViewApplication(cx->self, cx->app)
# 那个函数的实现（runtime.cpp:13322）要求视图在 `impl->views` 里、且它的
# `ScriptView::object` 属于本 runtime —— 那两个东西只有 QuickJS 的 `cx.new` 会给。
# 我们（PHP 宿主）的视图是 `EntityNewRaw` 建的，没有 ScriptView object，于是
# AppEffects 直接失败，materialize.cpp:2178 打
#     shell: failed to materialize `MenuBar`: component app effects require a root application snapshot
# 并在画面上渲染成 "Failed to render MenuBar"（装菜单条那一域实测每条活帧都刷一遍）。
#
# 菜单安装本身是**纯原生**的（`effect.run = &InstallMenus`、`drop = &DropOwnedMenus`），
# 全程不碰 JS；那个 application 指针只被当**世代 key** 用
# （ScheduleComponentAppEffect / FindAppEffectGeneration / ReleaseApplicationState）。
# 所以补丁只需要给宿主视图一个比它活得久的稳定指针，不必伪造 ScriptView/Policy。
#
# 零回归：不改任何既有判定，只在 `ScriptViewApplication` 开头加一次"宿主登记表"查询
# ——QuickJS 路径查不到就照原路走。
#
# 上游更新后重跑即可；补丁后要重跑 bun cmd/build.ts 生成 amalgam，再 cmd/build-shim.sh。
#
# 用法：GPUI_DIR=<gpui-kit-cpp> cmd/patch-host-application.sh

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
HDR="$GPUI_DIR/src/shell/runtime.h"
SRC="$GPUI_DIR/src/shell/runtime.cpp"
MARK="HostApplyComponentAppEffect"
# 每个文件认自己的落点：$MARK 只进 .cpp（trampoline 是文件内函数，不是类成员），拿它去 .h 里找
# 会把"两处都已完整落好"的沙箱误判成半补丁——实测单独重跑本脚本就 rc=1 中止，只有走
# `bin/gpui build`（先 sync-upstream 重置成未打补丁）才看不出来。
HDR_MARK="void RegisterHostApplication(EntityId view, void* application);"

[[ -f "$HDR" && -f "$SRC" ]] || { echo "ERROR: runtime.h/runtime.cpp not found" >&2; exit 1; }

h=0; s=0
grep -qF "$HDR_MARK" "$HDR" && h=1
grep -qF "$MARK" "$SRC" && s=1
if [[ $h -eq 1 && $s -eq 1 ]]; then
  echo "runtime.h/runtime.cpp: already patched, skip"
  exit 0
fi
if [[ $h -ne $s ]]; then
  echo "ERROR: 半补丁状态（h=$h s=${s}）——两个文件必须一起落，中止" >&2
  exit 1
fi

echo "patching runtime for host-side application identity ($MARK)..."
cp "$HDR" "$HDR.hostapp.bak"; cp "$SRC" "$SRC.hostapp.bak"

# ── runtime.h：紧跟 RegisterHostView 声明之后加注册口 ──
python3 - "$HDR" <<'PYEOF'
import sys
path = sys.argv[1]
src = open(path).read()
anchor = "    shell::EntityHandle RegisterHostView(EntityId view, App* app);"
if anchor not in src:
    sys.exit("anchor `RegisterHostView(...)` decl not found in runtime.h "
             "(先跑 cmd/patch-nested-view.sh)")
decl = anchor + '''

    // typephp-gpui: declare an "application" identity for a host-owned view so
    // components with app effects (MenuBar's native menu install) can materialize.
    // ScriptViewApplication() otherwise demands a QuickJS ScriptView object, which a
    // host entity never has — every app-effect component then fails with "component
    // app effects require a root application snapshot" and the shell renders
    // "Failed to render MenuBar". The pointer is used only as a generation key
    // (ScheduleComponentAppEffect / ReleaseApplicationState), so the caller must keep
    // it alive for at least as long as the view: pass a process-lifetime token.
    void RegisterHostApplication(EntityId view, void* application);

    // typephp-gpui: identity for host views we never learn the EntityId of. A
    // component materializes with cx->self set to whichever view owns the pass, and
    // that is not always the one the host created (ShellRoot, keyed state entities,
    // dialog roots...). A QuickJS view is always in impl->views, so falling back for
    // views that are not there cannot change script-host behaviour.
    void SetHostApplicationFallback(void* application);'''
src = src.replace(anchor, decl, 1)
open(path, "w").write(src)
print("  runtime.h: RegisterHostApplication declaration added")
PYEOF

# ── runtime.cpp：定义 + 在 ScriptViewApplication 开头查宿主登记表 ──
python3 - "$SRC" <<'PYEOF'
import sys
path = sys.argv[1]
src = open(path).read()

view_anchor = '''shell::EntityHandle ShellRuntime::RegisterHostView(EntityId view, App* app) {'''
if view_anchor not in src:
    sys.exit("RegisterHostView definition anchor not found in runtime.cpp")

# 1) 宿主登记表 + 注册口：插在 RegisterHostView 的定义之前（同 scope，impl/Vec 均可见）。
table = '''// typephp-gpui: host views that declared an application identity (see
// RegisterHostApplication). Kept here rather than in ShellRuntimeImpl so the patch
// adds no field to the impl struct; entries are append-only and tiny (one per window
// ever opened), and the tokens the host passes outlive the views they name.
struct HostAppEntry {
    EntityId view;
    void* application;
};
static Vec<HostAppEntry>& HostAppTable() {
    // `Vec<T>` 的默认构造是 explicit，`= {}` 那种拷贝列表初始化过不了 -Werror；
    // 直接列表初始化才允许。
    static Vec<HostAppEntry> table{};
    return table;
}

void ShellRuntime::RegisterHostApplication(EntityId view, void* application) {
    if (!view.IsValid() || !application) return;
    for (int i = 0; i < HostAppTable().len; i++) {
        if (HostAppTable()[i].view == view) {
            HostAppTable()[i].application = application;
            return;
        }
    }
    HostAppEntry entry{};
    entry.view = view;
    entry.application = application;
    VecAppend(HostAppTable(), entry);
}

static void* g_hostAppFallback = nullptr;

void ShellRuntime::SetHostApplicationFallback(void* application) {
    g_hostAppFallback = application;
}

''' + view_anchor
src = src.replace(view_anchor, table, 1)

# 2b) 延迟安装的回调不能走 ScriptView 成员函数。
#     原实现 `WindowPost(window, ListenTo(owner, &ScriptView::OnComponentAppEffect, token))`
#     里那个函数干的是 `if (!self || !self->runtime) return; self->runtime->Apply...`。
#     宿主视图是 EntityNewRaw 建的裸对象（我们是 8 字节的 PhpView{Box2*}），把它当
#     ScriptView 读 `->runtime` 就是越界读——拿到的垃圾指针非 0 时就被当 this 调用，
#     表现为 ApplyComponentAppEffect 偶发 SIGSEGV（实测 3 次崩 1 / 6 次崩 1，
#     随分配布局浮动）。这里给宿主视图换一个不看 ScriptView 的 trampoline。
post_anchor = '''    Entity<ScriptView> owner;
    owner.id = view;
    WindowPost(window, ListenTo(owner, &ScriptView::OnComponentAppEffect,
                                (int64_t)deferred.token));'''
if post_anchor not in src:
    sys.exit("WindowPost(OnComponentAppEffect) anchor not found in runtime.cpp")
post = '''    Entity<ScriptView> owner;
    owner.id = view;
    if (IsHostView(view)) {
        // 宿主视图：走不解引用 ScriptView 的 trampoline（见上方注释）。
        WindowPost(window, ListenTo(owner, &HostApplyComponentAppEffect,
                                    (int64_t)deferred.token));
    } else {
        WindowPost(window, ListenTo(owner, &ScriptView::OnComponentAppEffect,
                                    (int64_t)deferred.token));
    }'''
src = src.replace(post_anchor, post, 1)

tramp_anchor = '''bool ShellRuntime::ScheduleComponentAppEffect('''
if tramp_anchor not in src:
    sys.exit("ScheduleComponentAppEffect anchor not found in runtime.cpp")
tramp = '''// typephp-gpui: the runtime that last scheduled a host app effect, so the posted
// apply can reach it without going through a ScriptView the host view is not.
static ShellRuntime* g_hostAppEffectRuntime = nullptr;

static bool IsHostView(EntityId view) {
    for (int i = 0; i < HostAppTable().len; i++) {
        if (HostAppTable()[i].view == view) return true;
    }
    return false;
}

static void HostApplyComponentAppEffect(ScriptView*, Ctx* cx, const void*,
                                        int64_t token) {
    if (!g_hostAppEffectRuntime || !cx) return;
    g_hostAppEffectRuntime->ApplyComponentAppEffect((uint64_t)token, cx->app);
}

''' + tramp_anchor
src = src.replace(tramp_anchor, tramp, 1)

# 记下当前 runtime（调度宿主 effect 的那次调用里 this 就是它）。
set_anchor = '''    ComponentAppEffectGeneration* generation =
        FindAppEffectGeneration(impl, application);
    if (!generation) {
        generation = new ComponentAppEffectGeneration();'''
if set_anchor not in src:
    sys.exit("generation lookup anchor not found in runtime.cpp")
src = src.replace(set_anchor, '''    g_hostAppEffectRuntime = this;
''' + set_anchor, 1)

# 3) ScriptViewApplication：先问宿主登记表，再给"非 QuickJS 视图"兜底，最后走原路径。
lookup_anchor = '''void* ShellRuntime::ScriptViewApplication(EntityId view, App* app) const {
    if (!view.IsValid() || !app) return nullptr;'''
if lookup_anchor not in src:
    sys.exit("ScriptViewApplication anchor not found in runtime.cpp")
lookup = lookup_anchor + '''
    for (int i = 0; i < HostAppTable().len; i++) {
        if (HostAppTable()[i].view == view) return HostAppTable()[i].application;
    }
    // impl->views 只有 QuickJS 的 cx.new 会登记，所以不在里面的视图一定不是 ScriptView
    // ——这类视图来自宿主（PhpView / ShellRoot / keyed 状态实体 / dialog 根），
    // 它们拿不到 ScriptView::object，原先就在这里判空失败。QuickJS 路径照原样往下走。
    bool isScriptView = false;
    for (int i = 0; i < impl->views.len; i++) {
        if (impl->views[i].view == view) {
            isScriptView = true;
            break;
        }
    }
    if (!isScriptView && g_hostAppFallback) return g_hostAppFallback;'''
src = src.replace(lookup_anchor, lookup, 1)

open(path, "w").write(src)
print("  runtime.cpp: RegisterHostApplication definition + ScriptViewApplication lookup added")
PYEOF

# ── 自检：两处都必须恰好落一次 ──
decl_n=$(grep -c 'void RegisterHostApplication(EntityId view, void\* application);' "$HDR")
def_n=$(grep -c 'void ShellRuntime::RegisterHostApplication' "$SRC")
look_n=$(grep -c 'if (HostAppTable()\[i\].view == view) return HostAppTable()\[i\].application;' "$SRC")
fb_h=$(grep -c 'void SetHostApplicationFallback(void\* application);' "$HDR")
fb_s=$(grep -c 'void ShellRuntime::SetHostApplicationFallback' "$SRC")
fb_l=$(grep -c 'if (!isScriptView && g_hostAppFallback) return g_hostAppFallback;' "$SRC")
host_n=$(grep -c 'static bool IsHostView(EntityId view)' "$SRC")
tram_n=$(grep -c 'static void HostApplyComponentAppEffect' "$SRC")
br_n=$(grep -c 'if (IsHostView(view)) {' "$SRC")
set_n=$(grep -c 'g_hostAppEffectRuntime = this;' "$SRC")
if [[ "$decl_n" != "1" || "$def_n" != "1" || "$look_n" != "1" || "$fb_h" != "1" || "$fb_s" != "1" || "$fb_l" != "1" || "$host_n" != "1" || "$tram_n" != "1" || "$br_n" != "1" || "$set_n" != "1" ]]; then
  echo "ERROR: 自检失败 decl=$decl_n def=$def_n lookup=$look_n fallback(decl=$fb_h def=$fb_s use=$fb_l) host=$host_n tram=$tram_n br=$br_n set=${set_n}（应各为 1）" >&2
  mv "$HDR.hostapp.bak" "$HDR"; mv "$SRC.hostapp.bak" "$SRC"
  exit 1
fi
rm -f "$HDR.hostapp.bak" "$SRC.hostapp.bak"
echo "Patch complete. Regenerate amalgam: (cd $GPUI_DIR && bun cmd/build.ts -rel hello_world) then bash cmd/build-shim.sh"
