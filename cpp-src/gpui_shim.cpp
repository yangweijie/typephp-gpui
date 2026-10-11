// typephp-gpui — C++20 侧 shim：唯一 include gpui.h 的编译单元。
//
// 实现 cpp-src/gpui_shim.h 声明的 extern "C" 接口，内部持有 gpui App/Window/
// SpecArena/PhpView 生命周期并把事件排成定长 C struct 队列。这里绝不出现 phpx
// 类型（Variant/Array/Box），也不出现 Concept/Requires 之外的 C++17 兼容问题——
// 因为本文件以 -std=c++20 单独编译，与 nano(c++17) 的 bridge 经 C ABI 解耦。

#include "gpui_shim.h"

#include "gpui.h"  // gpui-kit-cpp amalgam（c++20）

#include <AppKit/AppKit.h>
#include <CoreGraphics/CoreGraphics.h>

// Objective-C 头把 Nil 定义成 nullptr，会污染 gpui::shell::Bridged::Nil()。
// gpui.h 在这些框架头之前已展开，故只需在此处解除，供下方 shim 代码使用。
#undef Nil

#include <cstdlib>
#include <cstring>
#include <deque>
#include <string>
#include <unordered_map>
#include <vector>

// GpuiAppRunStep 由 cmd/patch-view.sh 追加到 window_mac.cpp 的 namespace gpui 内。
namespace gpui {
int GpuiAppRunStep(App* app, double maxWaitSecs);
// 本文件底部定义的强符号（覆盖 view.cpp 里的 weak 桩）；前向声明供自检合成事件调用，
// 以真实走一遍 boxForView 反查 + 入队路径。
void gpuiEnqueueClick(ScriptView*, Ctx*, uint64_t, int);
void gpuiEnqueueChange(ScriptView*, Ctx*, uint64_t, bool);
void gpuiEnqueueHover(ScriptView*, Ctx*, uint64_t, bool);
// tick 监听强符号：签名须匹配 ListenerArgFn（void(*)(void*,Ctx*,const void*,int64_t)）。
void gpuiEnqueueTimer(ScriptView*, Ctx*, const void*, int64_t);
// catalog 组件回调旁路（cmd/patch-registered-cb.sh 在 runtime.cpp 放的弱符号强实现）：
// 把 ComponentCallback::InvokeWith 命中 shimId 的事件塞进窗口队列。
void gpuiEnqueueShimCallback(App*, EntityId, uint64_t);
}

namespace {

gpui::App* g_app = nullptr;
gpui::ShellRuntime* g_runtime = nullptr;
bool g_runtimeReady = false;

// 一个窗口的全部状态。对 bridge 是 opaque void*。
class Box2 {
  public:
    gpui::Window* window = nullptr;
    void* viewPtr = nullptr;        // PhpView*，g_views 的键
    gpui::EntityId viewEntity = {};
    gpui::EntityId shellRoot = {};  // 窗口根：包 viewEntity，拥有 dialog/sheet/toast overlay
    gpui::shell::SpecArena specs;   // 常驻：本窗口当前描述
    gpui::shell::SpecId rootId = 0;
    uint64_t nextCallback = 1;

    // callbackId → (elementId, eventType)
    std::unordered_map<uint64_t, std::pair<std::string, std::string>> callbacks;
    // "elemId\x1ftype" → runtime CallbackId（catalog 组件回调旁路，自检直接触发用）
    std::unordered_map<std::string, uint64_t> shimCallbacks;
    // elementId → retained EntityHandle（Input/Slider 常驻状态，跨帧稳定）
    std::unordered_map<std::string, uint64_t> inputHandles;
    // RetainedEntry 本地 id → elementId（OnInputEvent/OnSliderEvent 反查）
    std::unordered_map<uint32_t, std::string> retainedIdToElem;
    // 定时器：tick 监听 arg(key) → phpId；phpId → WindowSetInterval 返回的 id（取消用）
    std::unordered_map<int64_t, std::string> timerKeys;
    std::unordered_map<std::string, int> timerIds;
    int64_t nextTimerKey = 1;
    std::deque<GpuiEvent> queue;
    bool closed = false;
    // 打开的 dialog 内容 Box2（保活到窗口析构；demo 规模够用）。
    std::vector<Box2*> dialogs;

    uint64_t newCallback(const std::string& elementId, const std::string& type) {
        uint64_t id = nextCallback++;
        callbacks[id] = {elementId, type};
        return id;
    }

    // 按 (elementId, type) 反查 callbackId；未注册返回 0。自检合成事件用。
    uint64_t findCallback(const std::string& elementId, const std::string& type) const {
        for (const auto& kv : callbacks) {
            if (kv.second.first == elementId && kv.second.second == type)
                return kv.first;
        }
        return 0;
    }

    void enqueue(uint64_t callbackId, int vkind, double vnum, int vbool,
                 const char* vstr) {
        auto it = callbacks.find(callbackId);
        GpuiEvent ev{};
        if (it != callbacks.end()) {
            std::snprintf(ev.type, sizeof(ev.type), "%s", it->second.second.c_str());
            std::snprintf(ev.id, sizeof(ev.id), "%s", it->second.first.c_str());
        }
        ev.vkind = vkind;
        ev.vnum = vnum;
        ev.vbool = vbool;
        if (vstr) std::snprintf(ev.vstr, sizeof(ev.vstr), "%s", vstr);
        queue.push_back(ev);
    }

    // Retained 事件（input/slider）：标识是 RetainedEntry 本地 id 而非 callbackId，
    // 故直接按 elementId + 类型入队，不经 callbacks 反查。
    void enqueueRaw(const char* type, const char* elemId, int vkind, double vnum,
                    int vbool, const char* vstr) {
        GpuiEvent ev{};
        std::snprintf(ev.type, sizeof(ev.type), "%s", type ? type : "");
        std::snprintf(ev.id, sizeof(ev.id), "%s", elemId ? elemId : "");
        ev.vkind = vkind;
        ev.vnum = vnum;
        ev.vbool = vbool;
        if (vstr) std::snprintf(ev.vstr, sizeof(ev.vstr), "%s", vstr);
        queue.push_back(ev);
    }
};

// self(view void*) → box，供 patched view.cpp 的事件 handler 反查。
std::unordered_map<void*, Box2*> g_views;

gpui::Str cppStr(const std::string& s) {
    return gpui::Str(s.data(), (int)s.size());
}

// 进 arena 的字符串必须拷进 arena 自身：bridge 传来的 const char* 是 emitTree 返回即
// 销毁的临时 std::string，只存视图会悬垂（曾致 size("100%") 变乱码、布局塌缩）。
gpui::Str AStr(gpui::Arena* a, const char* s) {
    return gpui::StrDup(a, gpui::Str(s ? s : "", s ? (int)std::strlen(s) : 0));
}

gpui::shell::Bridged toBridged(const GpuiArg& a, gpui::Arena* arena) {
    using B = gpui::shell::Bridged;
    switch (a.kind) {
        case GPUI_ARG_BOOL: return B::Bool(a.b != 0);
        case GPUI_ARG_NUM: return B::Number(a.num);
        case GPUI_ARG_STR: return B::String(AStr(arena, a.str));
        default: return B::Nil();
    }
}

gpui::shell::ComponentKind kindFromString(const char* k) {
    using K = gpui::shell::ComponentKind;
    if (!k) return K::Div;
    std::string s(k);
    if (s == "h_flex") return K::HFlex;
    if (s == "v_flex") return K::VFlex;
    if (s == "text") return K::Text;
    if (s == "text_view") return K::TextView;
    if (s == "button") return K::Button;
    if (s == "link") return K::Link;
    if (s == "checkbox") return K::Checkbox;
    if (s == "switch") return K::Switch;
    if (s == "input") return K::Input;
    if (s == "textarea") return K::Textarea;
    if (s == "number_input") return K::NumberInput;
    if (s == "otp_input") return K::OtpInput;
    if (s == "slider") return K::Slider;
    if (s == "progress") return K::Progress;
    if (s == "image") return K::Image;
    if (s == "table") return K::Table;
    if (s == "table_row") return K::TableRow;
    if (s == "table_cell") return K::TableCell;
    if (s == "list") return K::List;
    if (s == "tabs") return K::Tabs;
    if (s == "tab") return K::Tab;
    if (s == "scrollbar") return K::Scrollbar;
    if (s == "svg") return K::Svg;
    if (s == "radio") return K::Radio;
    if (s == "toggle") return K::Toggle;
    if (s == "radio_group") return K::RadioGroup;
    if (s == "toggle_group") return K::ToggleGroup;
    if (s == "collapsible") return K::Collapsible;
    if (s == "popover") return K::Popover;
    if (s == "hover_card") return K::HoverCard;
    if (s == "popup") return K::Popup;
    if (s == "avatar") return K::Avatar;
    if (s == "accordion_item") return K::AccordionItem;
    if (s == "table_header") return K::TableHeader;
    if (s == "table_body") return K::TableBody;
    if (s == "table_head") return K::TableHead;
    if (s == "table_caption") return K::TableCaption;
    if (s == "progress_track") return K::ProgressTrack;
    if (s == "progress_indicator") return K::ProgressIndicator;
    if (s == "h_resizable") return K::HResizable;
    if (s == "v_resizable") return K::VResizable;
    if (s == "resizable_panel") return K::ResizablePanel;
    if (s == "select") return K::Select;
    if (s == "combobox") return K::Combobox;
    if (s == "date_picker") return K::DatePicker;
    return K::Div;
}

// exportName → (descriptor 下标, 构造器下标)。冻结注册表运行期不变，可缓存；
// index 一律运行时查（lib.cpp 里各组件家族的先后顺序不能硬编码）。
struct CtorRef {
    uint32_t compId = 0;
    int ctorIdx = 0;
};

bool lookupCtor(const char* exportName, CtorRef* out,
                const gpui::shell::ComponentDescriptor** descOut) {
    static std::unordered_map<std::string, CtorRef> cache;
    const gpui::shell::FrozenComponentRegistry* comps =
        g_runtime ? g_runtime->Components() : nullptr;
    if (!comps || !exportName || !*exportName) return false;
    std::string key(exportName);
    auto it = cache.find(key);
    if (it == cache.end()) {
        bool hit = false;
        CtorRef found{};
        int n = comps->DescriptorCount();
        for (int d = 0; d < n && !hit; ++d) {
            const gpui::shell::ComponentDescriptor* desc =
                comps->Descriptor((uint32_t)d);
            if (!desc) continue;
            for (int c = 0; c < desc->constructors.count; ++c) {
                if (gpui::StrEq(gpui::Str(desc->constructors[c].exportName),
                                gpui::Str(exportName))) {
                    found.compId = (uint32_t)d;
                    found.ctorIdx = c;
                    hit = true;
                    break;
                }
            }
        }
        if (!hit) return false;
        cache[key] = found;
        it = cache.find(key);
    }
    const gpui::shell::ComponentDescriptor* desc =
        comps->Descriptor(it->second.compId);
    if (!desc) return false;
    *out = it->second;
    if (descOut) *descOut = desc;
    return true;
}

using SchemaKind = gpui::shell::SchemaKind;
using ArgKind = gpui::shell::ComponentArgumentKind;

// 把扁平 GpuiArg 按 descriptor 声明的 schema 递归转成 arena 分配的
// ComponentArgument。所有字符串走 AStr（StrDup 进 arena）——GpuiArg.str 是
// bridge emitTree 帧内的临时量，payload.arguments 要活到 materialize，直接存视图会悬垂。
// 成功返回 true；失败 false，*err 给可读文本（写进错误节点，debug_tree/--shot 可见）。
bool toComponentArg(gpui::Arena* a, const GpuiArg& ga,
                    const gpui::shell::ArgumentSchema& schema,
                    gpui::shell::ComponentArgument* out, std::string* err) {
    *out = {};
    switch (schema.kind) {
        case SchemaKind::String: {
            out->kind = ArgKind::String;
            out->string = AStr(a, ga.str ? ga.str : "");
            return true;
        }
        case SchemaKind::Number: {
            out->kind = ArgKind::Number;
            if (ga.kind == GPUI_ARG_NUM) out->number = ga.num;
            else if (ga.kind == GPUI_ARG_STR && ga.str)
                out->number = std::strtod(ga.str, nullptr);
            return true;
        }
        case SchemaKind::Boolean: {
            out->kind = ArgKind::Boolean;
            out->boolean = ga.b != 0 || (ga.kind == GPUI_ARG_NUM && ga.num != 0);
            return true;
        }
        case SchemaKind::Enum: {
            const char* text = ga.str ? ga.str : "";
            bool ok = false;
            for (const char* lit : schema.values) {
                if (gpui::StrEq(gpui::Str(text), gpui::Str(lit))) {
                    ok = true;
                    break;
                }
            }
            if (!ok) {
                *err = std::string("enum '") + text + "' not in allowed set";
                return false;
            }
            out->kind = ArgKind::Enum;
            out->string = AStr(a, text);
            return true;
        }
        case SchemaKind::Array: {
            if (ga.kind != GPUI_ARG_ARRAY || !schema.item) {
                *err = "expected array argument";
                return false;
            }
            gpui::shell::ComponentArgument* items = ga.count > 0
                ? (gpui::shell::ComponentArgument*)Alloc(
                      a, (int)(sizeof(gpui::shell::ComponentArgument) *
                               (size_t)ga.count))
                : nullptr;
            for (int i = 0; i < ga.count; ++i) {
                if (!toComponentArg(a, ga.items[i], *schema.item, &items[i],
                                    err))
                    return false;
            }
            out->kind = ArgKind::Array;
            out->items = items;
            out->count = ga.count;
            return true;
        }
        case SchemaKind::Optional: {
            out->kind = ArgKind::Optional;
            if (ga.kind == GPUI_ARG_NIL || !schema.item) {
                out->count = 0;
                return true;
            }
            auto* inner =
                gpui::ArenaNew<gpui::shell::ComponentArgument>(a);
            if (!toComponentArg(a, ga, *schema.item, inner, err)) return false;
            out->items = inner;
            out->count = 1;
            return true;
        }
        case SchemaKind::Callback: {
            out->kind = ArgKind::Callback;
            // num 复用为预分配的 callbackId（Stage D 前可为 0：静态渲染无害）。
            out->callback =
                (gpui::shell::CallbackId)(ga.kind == GPUI_ARG_CB ? ga.num : 0);
            return true;
        }
        case SchemaKind::Element: {
            out->kind = ArgKind::Element;
            out->element = (gpui::shell::SpecId)ga.num;
            return true;
        }
        case SchemaKind::Entity: {
            out->kind = ArgKind::Entity;
            out->entityKind = schema.text;
            out->handle = (uint64_t)ga.num;
            return true;
        }
    }
    *err = "unsupported schema kind";
    return false;
}

// 校验 + 构造整条实参列表：arguments 恒按 schema 元数分配（对齐上游
// ValidateComponentArguments——factory 总收到 descriptors.count 个实参，缺者须是
// Optional）。任一环节失败返回 -1 并置 *err。
int buildComponentArgs(gpui::Arena* a,
                       gpui::shell::Slice<gpui::shell::ArgumentDescriptor> decls,
                       const GpuiArg* args, int argc,
                       gpui::shell::ComponentArgument** out, std::string* err) {
    int schemaN = decls.count;
    gpui::shell::ComponentArgument* arguments =
        schemaN > 0 ? (gpui::shell::ComponentArgument*)Alloc(
                          a, (int)(sizeof(gpui::shell::ComponentArgument) *
                                   (size_t)schemaN))
                    : nullptr;
    for (int i = 0; i < schemaN; ++i) {
        const gpui::shell::ArgumentDescriptor& ad = decls[i];
        if (i >= argc) {
            if (ad.schema.kind == SchemaKind::Optional) {
                arguments[i] = {};
                arguments[i].kind = ArgKind::Optional;
                continue;
            }
            *err = std::string("missing argument '") +
                   (ad.name ? ad.name : "") + "'";
            return -1;
        }
        if (!toComponentArg(a, args[i], ad.schema, &arguments[i], err)) {
            *err = std::string("at argument '") + (ad.name ? ad.name : "") +
                   "': " + *err;
            return -1;
        }
    }
    *out = arguments;
    return schemaN;
}

// registered 失败时的可见占位：一个 Text 节点承载错误文本（on-screen + debug_tree 都可见）。
uint32_t pushErrorNode(Box2* box, gpui::Arena* a, const std::string& msg) {
    gpui::shell::Component c;
    c.kind = gpui::shell::ComponentKind::Text;
    c.text = AStr(a, msg.c_str());
    return box->specs.Push(c);
}

// Root content 实体：帧渲染时把 box->specs materialize 成原生 El 树。
struct PhpView {
    Box2* box;

    static gpui::El* Render(void* self, gpui::Ctx* cx) {
        PhpView* view = static_cast<PhpView*>(self);
        if (!view || !view->box) return gpui::Div(cx->a);
        if (view->box->rootId == 0 && view->box->specs.IsEmpty()) {
            return gpui::Div(cx->a);
        }
        return gpui::ShellMaterializeSpec(cx, g_runtime, &view->box->specs,
                                          view->box->rootId);
    }
};

void ensureApp() {
    if (g_app) return;
    g_app = gpui::AppNew();
    gpui::component::Init(g_app);
    const gpui::shell::FrozenComponentRegistry* components =
        gpui::component_shell::Components();
    gpui::ShellInitWithComponents(g_app, components);
    gpui::ThemeSet(g_app, gpui::ThemeMode::Light);
    if (!g_runtimeReady) {
        g_runtime = gpui::ShellRuntime::New(g_app, nullptr, components);
        g_runtimeReady = true;
    }
}

} // namespace

// ────────────────────── C ABI 实现 ──────────────────────

extern "C" {

void* gpui_shim_app_init(void) {
    ensureApp();
    return nullptr;
}

void* gpui_shim_open_window(const char* title, int width, int height) {
    ensureApp();
    auto* box = new Box2();

    auto* view = new PhpView{box};
    box->viewPtr = view;
    box->viewEntity =
        gpui::EntityNewRaw(g_app, view, (gpui::RenderFn)&PhpView::Render,
                           [](void* p) { delete static_cast<PhpView*>(p); });
    g_views[view] = box;

    // 窗口根换成 ShellRoot：它渲染我们的 PhpView content，并托管 dialog/sheet/toast。
    // 用 WindowOpenView 直接把 ShellRoot 作窗口根（KitOpenWindow 会再包一层 Base Root，
    // 令 ShellRootOf 的 window->root==state->root 判定失败）。
    auto root = gpui::ShellRoot::New(g_app, box->viewEntity);
    box->shellRoot = root.id;

    gpui::WinOpts opts = {};
    box->window =
        gpui::WindowOpenView(g_app, cppStr(title ? title : ""), width, height,
                             box->shellRoot, opts);
    if (!box->window) {
        g_views.erase(view);
        gpui::EntityDrop(g_app, box->viewEntity);
        delete view;
        delete box;
        return nullptr;
    }
    return box;
}

int gpui_shim_is_open(void* win) {
    auto* box = static_cast<Box2*>(win);
    if (!box) return 0;
    return (!box->closed && box->window && gpui::AppAnyWindowOpen(g_app)) ? 1 : 0;
}

void gpui_shim_pump(void* win, double max_wait_secs) {
    auto* box = static_cast<Box2*>(win);
    if (!box) return;
    int rc = gpui::GpuiAppRunStep(g_app, max_wait_secs);
    if (rc >= 0) box->closed = true;  // rc == exitCode：无窗口打开
}

int gpui_shim_poll(void* win, GpuiEvent* out) {
    auto* box = static_cast<Box2*>(win);
    if (!box || box->queue.empty()) return 0;
    *out = box->queue.front();
    box->queue.pop_front();
    return 1;
}

void gpui_shim_close(void* win) {
    auto* box = static_cast<Box2*>(win);
    if (box && box->window) {
        gpui::AppClose(box->window);
        box->closed = true;
    }
}

void gpui_shim_set_theme(void* win, const char* mode) {
    (void)win;
    if (!g_app) return;
    std::string m(mode ? mode : "");
    gpui::ThemeSet(g_app, m == "dark" ? gpui::ThemeMode::Dark
                                      : gpui::ThemeMode::Light);
    gpui::AppRefreshWindows(g_app);
}

void gpui_shim_render_begin(void* win) {
    auto* box = static_cast<Box2*>(win);
    if (box) box->specs.Reset();
}

uint32_t gpui_shim_push(void* win, const char* kind, const char* text,
                        const char* value, const char* id) {
    auto* box = static_cast<Box2*>(win);
    gpui::Arena* a = box->specs.Storage();
    gpui::shell::Component c;
    c.kind = kindFromString(kind);
    if (text && *text) c.text = AStr(a, text);
    if (value && *value) c.value = AStr(a, value);
    if (id && *id) {
        auto it = box->inputHandles.find(id);
        if (it != box->inputHandles.end()) c.handle = it->second;
    }
    return box->specs.Push(c);
}

uint64_t gpui_shim_retained_input(void* win, const char* id, int textarea,
                                  const char* placeholder, const char* value,
                                  int rows) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !id || !*id) return 0;
    auto it = box->inputHandles.find(id);
    if (it != box->inputHandles.end()) return it->second;
    uint64_t h = g_runtime->CreateRetainedInput(
        textarea != 0, cppStr(placeholder ? placeholder : ""),
        cppStr(value ? value : ""), rows, g_app, box->viewEntity, box);
    box->inputHandles[id] = h;
    if (auto* e = g_runtime->Retained((gpui::shell::EntityHandle)h))
        box->retainedIdToElem[e->id] = id;
    return h;
}

uint64_t gpui_shim_retained_slider(void* win, const char* id, double min,
                                   double max, double step) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !id || !*id) return 0;
    auto it = box->inputHandles.find(id);
    if (it != box->inputHandles.end()) return it->second;
    uint64_t h = g_runtime->CreateRetainedSlider(
        (float)min, (float)max, (float)step, g_app, box->viewEntity, box);
    box->inputHandles[id] = h;
    if (auto* e = g_runtime->Retained((gpui::shell::EntityHandle)h))
        box->retainedIdToElem[e->id] = id;
    return h;
}

uint64_t gpui_shim_retained_focus(void* win, const char* id) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !id || !*id) return 0;
    auto it = box->inputHandles.find(id);
    if (it != box->inputHandles.end()) return it->second;
    uint64_t h = g_runtime->CreateRetainedFocus(g_app, box->viewEntity, box);
    box->inputHandles[id] = h;
    return h;
}

int gpui_shim_input_value(void* win, const char* id, char* out, int outlen) {
    auto* box = static_cast<Box2*>(win);
    if (outlen <= 0) return 0;
    out[0] = '\0';
    if (!box || !id || !*id) return 0;
    auto it = box->inputHandles.find(id);
    if (it == box->inputHandles.end()) return 0;
    auto* e = g_runtime->Retained((gpui::shell::EntityHandle)it->second);
    if (!e || !e->input) return 0;
    gpui::Str v = gpui::InputValue(e->input);
    int n = v.len < outlen - 1 ? v.len : outlen - 1;
    if (n > 0 && v.s) std::memcpy(out, v.s, n);
    out[n] = '\0';
    return n;
}

int gpui_shim_set_input_value(void* win, const char* id, const char* text) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !id || !*id) return 0;
    auto it = box->inputHandles.find(id);
    if (it == box->inputHandles.end()) return 0;
    auto* e = g_runtime->Retained((gpui::shell::EntityHandle)it->second);
    if (!e || !e->input) return 0;
    gpui::InputSetValue(e->input, cppStr(text ? text : ""));
    gpui::NotifyApp(g_app);
    return 1;
}

int gpui_shim_selftest_fire(void* win, const char* elem_id, const char* type) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !elem_id || !type) return 0;
    uint64_t cbid = box->findCallback(elem_id, type);
    if (cbid == 0) return 0;
    // self 传 PhpView*（即 box->viewPtr）——gpuiEnqueue* 内部 boxForView 反查回 Box2，
    // 与真实点击分派（entity.cpp: EntityGet(l.view) → 实体数据指针）走同一条路。
    auto* self = (gpui::ScriptView*)box->viewPtr;
    std::string t(type);
    if (t == "click") gpui::gpuiEnqueueClick(self, nullptr, cbid, 1);
    else if (t == "change") gpui::gpuiEnqueueChange(self, nullptr, cbid, true);
    else if (t == "hover") gpui::gpuiEnqueueHover(self, nullptr, cbid, true);
    else return 0;
    return 1;
}

int gpui_shim_snapshot(void* win, const char* path, int dip_w, int dip_h) {
    (void)dip_w;
    (void)dip_h;
    auto* box = static_cast<Box2*>(win);
    if (!box || !path) return 0;
    // 抓真实在屏窗口的已布局内容（cacheDisplayInRect 用 view 当前绘制结果），
    // 而非离屏 WindowDrawFrame——后者不跑完整布局，位置会错。
    @autoreleasepool {
        NSWindow* nswin = [NSApp keyWindow];
        if (!nswin) {
            for (NSWindow* w in [NSApp windows]) {
                if (w) { nswin = w; break; }
            }
        }
        if (!nswin) return 0;
        NSView* view = [nswin contentView];
        if (!view) return 0;
        NSRect bounds = [view bounds];
        NSBitmapImageRep* rep =
            [view bitmapImageRepForCachingDisplayInRect:bounds];
        if (!rep) return 0;
        [view cacheDisplayInRect:bounds toBitmapImageRep:rep];
        NSData* png =
            [rep representationUsingType:NSBitmapImageFileTypePNG properties:@{}];
        if (!png) return 0;
        NSString* nsPath = [NSString stringWithUTF8String:path];
        return [png writeToFile:nsPath atomically:YES] ? 1 : 0;
    }
}

int gpui_shim_debug_tree(void* win, char* out, int outlen) {
    auto* box = static_cast<Box2*>(win);
    if (!box || outlen <= 0) return 0;
    out[0] = '\0';
    gpui::Str s = box->specs.DebugTree(box->specs.Storage(), box->rootId);
    int n = s.len < outlen - 1 ? s.len : outlen - 1;
    if (n > 0 && s.s) std::memcpy(out, s.s, n);
    out[n] = '\0';
    return n;
}

void gpui_shim_set_timer(void* win, const char* php_id, int interval_ms) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !box->window || !php_id || !*php_id) return;
    std::string id(php_id);
    // 取消：先撤同名旧定时器。
    auto old = box->timerIds.find(id);
    if (old != box->timerIds.end()) {
        gpui::WindowCancelTimer(box->window, old->second);
        box->timerIds.erase(old);
    }
    if (interval_ms <= 0) return;

    int64_t key = box->nextTimerKey++;
    box->timerKeys[key] = id;
    gpui::Listener l;
    l.SetFn(&gpui::gpuiEnqueueTimer);
    l.SetHasArg();
    l.view = box->viewEntity;
    l.arg = key;
    int gid = gpui::WindowSetInterval(box->window, interval_ms, l);
    box->timerIds[id] = gid;
}

// 手构一个 overlay 调用用的 Ctx（PHP 侧无 JS scope，OverlayMutationAllowed 放行）。
static gpui::Ctx overlayCtx(Box2* box) {
    gpui::Ctx cx{};
    cx.app = g_app;
    cx.win = box->window;
    cx.a = box->window ? box->window->frameArena : nullptr;
    cx.self = box->shellRoot;
    return cx;
}

void gpui_shim_toast(void* win, const char* title, const char* desc,
                     const char* level, int timeout_ms) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !box->window) return;
    gpui::Ctx cx = overlayCtx(box);
    gpui::ToastRequest req{};
    req.title = cppStr(title ? title : "");
    req.description = cppStr(desc ? desc : "");
    req.level = gpui::ToastLevel::Info;
    if (level) gpui::ToastLevelFromName(cppStr(level), &req.level);
    req.hasTimeout = true;
    req.timeoutMs = timeout_ms > 0 ? timeout_ms : gpui::kToastDefaultTimeoutMs;
    gpui::ShellRootPushToast(&cx, req);
}

void* gpui_shim_dialog_begin(void* win) {
    auto* box = static_cast<Box2*>(win);
    if (!box) return nullptr;
    auto* dlg = new Box2();
    auto* view = new PhpView{dlg};
    dlg->viewPtr = view;
    dlg->viewEntity = gpui::EntityNewRaw(
        g_app, view, (gpui::RenderFn)&PhpView::Render,
        [](void* p) { delete static_cast<PhpView*>(p); });
    g_views[view] = dlg;
    return dlg;
}

int gpui_shim_dialog_show(void* win, void* dlgv, int escape_dismissable,
                          int backdrop_dismissable) {
    auto* box = static_cast<Box2*>(win);
    auto* dlg = static_cast<Box2*>(dlgv);
    if (!box || !box->window || !dlg) return 0;
    gpui::Ctx cx = overlayCtx(box);
    gpui::DialogOptions opts{};
    opts.escapeDismissable = escape_dismissable != 0;
    opts.backdropDismissable = backdrop_dismissable != 0;
    int n = gpui::ShellRootOpenDialogView(&cx, dlg->viewEntity, opts);
    if (n > 0) box->dialogs.push_back(dlg);
    return n;
}

int gpui_shim_dialog_count(void* win) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !box->window) return 0;
    gpui::Ctx cx = overlayCtx(box);
    return gpui::ShellRootDialogCount(&cx);
}

int gpui_shim_dialog_close(void* win) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !box->window) return 0;
    gpui::Ctx cx = overlayCtx(box);
    return gpui::ShellRootCloseDialog(&cx) ? 1 : 0;
}

void gpui_shim_nullary_style(void* win, uint32_t spec, const char* name) {
    auto* box = static_cast<Box2*>(win);
    gpui::shell::SpecOp so;
    so.kind = gpui::shell::SpecOpKind::NullaryStyle;
    so.name = AStr(box->specs.Storage(), name);
    box->specs.PushOp(spec, so);
}

void gpui_shim_param_style(void* win, uint32_t spec, const char* name,
                           const GpuiArg* args, int argc) {
    auto* box = static_cast<Box2*>(win);
    gpui::Arena* a = box->specs.Storage();
    std::vector<gpui::shell::Bridged> tmp;
    tmp.reserve(argc);
    for (int i = 0; i < argc; ++i) tmp.push_back(toBridged(args[i], a));
    gpui::shell::SpecOp so;
    so.kind = gpui::shell::SpecOpKind::ParamStyle;
    so.name = AStr(a, name);
    so.args = tmp.data();
    so.argCount = argc;
    box->specs.PushOp(spec, so);
}

void gpui_shim_method(void* win, uint32_t spec, const char* name,
                      const GpuiArg* args, int argc) {
    auto* box = static_cast<Box2*>(win);
    gpui::Arena* a = box->specs.Storage();
    std::vector<gpui::shell::Bridged> tmp;
    tmp.reserve(argc);
    for (int i = 0; i < argc; ++i) tmp.push_back(toBridged(args[i], a));
    gpui::shell::SpecOp so;
    so.kind = gpui::shell::SpecOpKind::Method;
    so.name = AStr(a, name);
    so.args = tmp.data();
    so.argCount = argc;
    box->specs.PushOp(spec, so);
}

uint64_t gpui_shim_callback(void* win, uint32_t spec, const char* elem_id,
                            const char* type) {
    auto* box = static_cast<Box2*>(win);
    uint64_t cb = box->newCallback(elem_id ? elem_id : "", type ? type : "");
    gpui::shell::SpecOp so;
    so.kind = gpui::shell::SpecOpKind::Callback;
    so.name = AStr(box->specs.Storage(), type);
    so.callback = cb;
    box->specs.PushOp(spec, so);
    return cb;
}

void gpui_shim_attach(void* win, uint32_t parent, uint32_t child) {
    auto* box = static_cast<Box2*>(win);
    box->specs.Attach(parent, child);
}

void gpui_shim_slot(void* win, uint32_t parent, uint32_t child,
                    const char* slot_name) {
    auto* box = static_cast<Box2*>(win);
    // 命名槽：先 Claim 子节点（单次使用，之后不能再被 Attach），再以 op.node 引用它。
    box->specs.Claim(child);
    gpui::shell::SpecOp so;
    so.kind = gpui::shell::SpecOpKind::Slot;
    so.name = AStr(box->specs.Storage(), slot_name);
    so.node = child;
    box->specs.PushOp(parent, so);
}

uint32_t gpui_shim_registered(void* win, const char* export_name,
                              const GpuiArg* args, int argc) {
    auto* box = static_cast<Box2*>(win);
    gpui::Arena* a = box->specs.Storage();
    CtorRef ref{};
    const gpui::shell::ComponentDescriptor* desc = nullptr;
    std::string err;
    if (!lookupCtor(export_name, &ref, &desc)) {
        err = std::string("unknown export '") +
              (export_name ? export_name : "") + "'";
        return pushErrorNode(box, a, err);
    }
    const gpui::shell::ConstructorDescriptor& ctor = desc->constructors[ref.ctorIdx];
    gpui::shell::ComponentArgument* arguments = nullptr;
    int schemaN = buildComponentArgs(a, ctor.arguments, args, argc, &arguments,
                                     &err);
    if (schemaN < 0) {
        err = std::string(export_name) + ": " + err;
        return pushErrorNode(box, a, err);
    }
    gpui::shell::PayloadBuild build;
    build.a = a;
    if (!ctor.factory || !ctor.factory(&build, arguments, schemaN)) {
        std::string msg = build.error.s
                              ? std::string(build.error.s, build.error.len)
                              : std::string("constructor factory failed");
        err = std::string(export_name) + ": " + msg;
        return pushErrorNode(box, a, err);
    }
    gpui::shell::Component c;
    c.kind = gpui::shell::ComponentKind::Registered;
    c.index = ref.compId;
    c.text = gpui::Str(desc->name);  // descriptor 名（非导出名）
    c.payload = build.out;
    c.payload.factory = ctor.factory;
    c.payload.arguments = arguments;
    c.payload.argumentCount = schemaN;
    return box->specs.Push(c);
}

void gpui_shim_registered_method(void* win, uint32_t spec, const char* name,
                                 const GpuiArg* args, int argc) {
    auto* box = static_cast<Box2*>(win);
    gpui::Arena* a = box->specs.Storage();
    const gpui::shell::SpecNode* node = box->specs.Node(spec);
    if (!node) return;
    const gpui::shell::FrozenComponentRegistry* comps =
        g_runtime ? g_runtime->Components() : nullptr;
    const gpui::shell::MethodDescriptor* m =
        comps ? comps->Method(node->component.index, gpui::Str(name)) : nullptr;
    if (!m || !m->recorder) return;
    std::string err;
    gpui::shell::ComponentArgument* arguments = nullptr;
    int schemaN = buildComponentArgs(a, m->arguments, args, argc, &arguments,
                                     &err);
    if (schemaN < 0) return;
    gpui::shell::PayloadBuild build;
    build.a = a;
    if (!m->recorder(&build, arguments, schemaN)) return;
    gpui::shell::SpecOp so;
    so.kind = gpui::shell::SpecOpKind::RegisteredMethod;
    so.name = AStr(a, name);
    so.payload = build.out;
    so.payload.factory = m->recorder;
    so.payload.arguments = arguments;
    so.payload.argumentCount = schemaN;
    box->specs.PushOp(spec, so);
}

uint64_t gpui_shim_new_callback(void* win, const char* elem_id,
                                const char* type) {
    auto* box = static_cast<Box2*>(win);
    if (!box) return 0;
    // box 侧记 (elemId,type)→boxCbId（事件回传时反查）；runtime 侧登记一个带
    // shimId=boxCbId 的合成回调条目，返回其 runtime CallbackId 塞进
    // ComponentArgument.callback。点击时 InvokeWith 命中 shimId → gpuiEnqueueShimCallback
    // → box->enqueue(boxCbId) → 复用同一 (elemId,type)。
    uint64_t boxCb = box->newCallback(elem_id ? elem_id : "", type ? type : "");
    if (!g_runtime || !box->viewEntity.IsValid()) return boxCb;
    uint64_t rt = g_runtime->PushShimCallback(box->viewEntity, boxCb);
    if (rt) {
        box->shimCallbacks[std::string(elem_id ? elem_id : "") + "\x1f" +
                          (type ? type : "")] = rt;
        return rt;
    }
    return boxCb;
}

int gpui_shim_selftest_fire_registered(void* win, const char* elem_id,
                                       const char* type) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !elem_id || !type) return 0;
    std::string key = std::string(elem_id) + "\x1f" + type;
    auto it = box->shimCallbacks.find(key);
    if (it == box->shimCallbacks.end() || it->second == 0) return 0;
    // 直接走真实 ComponentCallback::InvokeWith → 命中 shimId 分支 →
    // gpuiEnqueueShimCallback → box->enqueue → 队列 → PHP poll。
    gpui::shell::ComponentCallback cb;
    cb.id = it->second;
    return cb.InvokeWith(g_runtime, nullptr, 0, box->window, g_app) ? 1 : 0;
}

void gpui_shim_render_end(void* win, uint32_t root_id) {
    auto* box = static_cast<Box2*>(win);
    box->rootId = root_id;
    gpui::NotifyApp(g_app);
}

} // extern "C"

// ────────────────────── patched view.cpp 的事件入队（强符号）──────────────────────
//
// cmd/patch-view.sh 在 view.cpp 放了同签名的 __attribute__((weak)) 空实现，这里
// 的强符号覆盖它们。self 是渲染中实体的指针（实为 PhpView*），经 g_views 反查 Box2。

namespace gpui {

static Box2* boxForView(void* self) {
    auto it = g_views.find(self);
    return it == g_views.end() ? nullptr : it->second;
}

void gpuiEnqueueClick(ScriptView* self, Ctx*, uint64_t callbackId, int clickCount) {
    if (Box2* box = boxForView((void*)self))
        box->enqueue(callbackId, GPUI_ARG_NUM, (double)clickCount, 0, nullptr);
}

void gpuiEnqueueChange(ScriptView* self, Ctx*, uint64_t callbackId, bool checked) {
    if (Box2* box = boxForView((void*)self))
        box->enqueue(callbackId, GPUI_ARG_BOOL, 0, checked ? 1 : 0, nullptr);
}

void gpuiEnqueueHover(ScriptView* self, Ctx*, uint64_t callbackId, bool hovered) {
    if (Box2* box = boxForView((void*)self))
        box->enqueue(callbackId, GPUI_ARG_BOOL, 0, hovered ? 1 : 0, nullptr);
}

void gpuiEnqueueInput(ScriptView* self, Ctx*, uint64_t handle, int eventKind) {
    (void)eventKind;
    Box2* box = boxForView((void*)self);
    if (!box) return;
    auto it = box->retainedIdToElem.find((uint32_t)handle);
    if (it == box->retainedIdToElem.end()) return;
    const std::string& elemId = it->second;
    char text[256];
    text[0] = '\0';
    auto hIt = box->inputHandles.find(elemId);
    if (hIt != box->inputHandles.end()) {
        shell::RetainedEntry* e =
            g_runtime->Retained((shell::EntityHandle)hIt->second);
        if (e && e->input) {
            Str v = InputValue(e->input);
            int n = v.len < (int)sizeof(text) - 1 ? v.len : (int)sizeof(text) - 1;
            if (n > 0 && v.s) std::memcpy(text, v.s, n);
            text[n] = '\0';
        }
    }
    box->enqueueRaw("change", elemId.c_str(), GPUI_ARG_STR, 0, 0, text);
}

void gpuiEnqueueSlider(ScriptView* self, Ctx*, uint64_t handle, float value) {
    Box2* box = boxForView((void*)self);
    if (!box) return;
    auto it = box->retainedIdToElem.find((uint32_t)handle);
    if (it == box->retainedIdToElem.end()) return;
    box->enqueueRaw("change", it->second.c_str(), GPUI_ARG_NUM, (double)value, 0,
                    nullptr);
}

// tick 监听强符号：arg 是 gpui_shim_set_timer 分配的 key，反查 phpId 入队 timer 事件。
void gpuiEnqueueTimer(ScriptView* self, Ctx*, const void* /*ev*/, int64_t arg) {
    Box2* box = boxForView((void*)self);
    if (!box) return;
    auto it = box->timerKeys.find(arg);
    if (it == box->timerKeys.end()) return;
    box->enqueueRaw("timer", it->second.c_str(), GPUI_ARG_NIL, 0, 0, nullptr);
}

// catalog 组件回调旁路：runtime 的 InvokeWith 命中 shimId 时调此（覆盖 runtime.cpp
// 的弱符号）。view 是注册回调时的 EntityId，EntityGet 取回 PhpView* 再经 g_views 反查
// Box2；shimId 即 box 侧 callbackId，enqueue 复用其 (elemId,type) 组一条事件。
void gpuiEnqueueShimCallback(App* app, EntityId view, uint64_t shimId) {
    auto* pv = (PhpView*)EntityGet(app, view);
    if (!pv) return;
    Box2* box = boxForView((void*)pv);
    if (!box) return;
    box->enqueue(shimId, GPUI_ARG_NIL, 0, 0, nullptr);
    NotifyApp(app);
}

} // namespace gpui
