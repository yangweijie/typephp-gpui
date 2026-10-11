// typephp-gpui — C++20 侧 shim：唯一 include gpui.h 的编译单元。
//
// 实现 cpp-src/gpui_shim.h 声明的 extern "C" 接口，内部持有 gpui App/Window/
// SpecArena/PhpView 生命周期并把事件排成定长 C struct 队列。这里绝不出现 phpx
// 类型（Variant/Array/Box），也不出现 Concept/Requires 之外的 C++17 兼容问题——
// 因为本文件以 -std=c++20 单独编译，与 nano(c++17) 的 bridge 经 C ABI 解耦。

#include "gpui_shim.h"

#include "gpui.h"  // gpui-kit-cpp amalgam（c++20）
#include "gpui_mac.h"

#include <AppKit/AppKit.h>
#include <CoreGraphics/CoreGraphics.h>

// Objective-C 头把 Nil 定义成 nullptr，会污染 gpui::shell::Bridged::Nil()。
// gpui.h 在这些框架头之前已展开，故只需在此处解除，供下方 shim 代码使用。
#undef Nil

#include <cstdlib>
#include <cstring>
#include <deque>
#include <memory>
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

// 系统状态：进程内一份。cpu 与每进程 cpu 都是两次采样的差值，而 mac 的墙钟差本身就是
// 文件静态量（sysinfo_mac.cpp 的 sPrevWall），所以每窗口一份只会互相污染读数。
// 随进程走：SysState 只含两个 Vec，且每次 SysRefresh 都先 VecReset，不增长。
gpui::SysState* g_sys = nullptr;

// 一个窗口的全部状态。对 bridge 是 opaque void*。
struct WebViewView;  // 定义在 PhpView 之后；Box2 只存它的指针。
struct WatchCtx;     // 同上：目录监听的一条 watch 上下文。

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
    // "elemId\x1ftype" → callbackId：newCallback 幂等复用（每帧重建同结构不增长）
    std::unordered_map<std::string, uint64_t> cbByKey;
    // "elemId\x1ftype" → runtime CallbackId（catalog 组件回调旁路，自检直接触发用）
    std::unordered_map<std::string, uint64_t> shimCallbacks;
    // 最近一次 registered 构造/方法失败的原因（每帧 render_begin 清空，供 gpui_last_error 回传）
    std::string lastError;
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
    // elementId → WebView 包装 view。holder entity 由本 Box 建、也必须由本 Box 在
    // AppClose **之前**显式 drop：ChildView 每帧只是 EntityRender，不接管生命周期，
    // 而 webview.h:50-51 要求 OS 控件在父窗口销毁前放手。
    struct WebEntry {
        gpui::EntityId holder = {};
        uint64_t token = 0;
        WebViewView* view = nullptr;  // 由 holder 的 deleter 释放，不自己 delete
    };
    std::unordered_map<std::string, WebEntry> webviews;
    // elementId → inspector 面板的 holder。面板本身无跨帧状态（每帧 New→IntoEl），
    // 这里要的只是一个能 EntityRender 的挂载点；按 id 各持一个，避免同一帧里两个
    // inspector 节点把同一个 El* 交给两个父节点。
    struct InspEntry {
        gpui::EntityId holder = {};
        uint64_t token = 0;
    };
    std::unordered_map<std::string, InspEntry> inspectors;
    // 已落地的 http 应答：phpId → 内容。body 自己拷了一份（HttpAsyncResult.response
    // 只在回调期间有效，http.h:95），所以拷贝必须发生在回调里。
    struct HttpResponse {
        bool ok = false;
        int status = 0;
        std::string body;        // 二进制安全，可以含 '\0'
        std::string contentType;
    };
    std::unordered_map<std::string, HttpResponse> httpResponses;
    // 目录监听：watch id → 该 watch 的堆上下文（onChange 靠它反查 phpId 与 box）。
    std::unordered_map<int, WatchCtx*> watchCtx;
    // elementId → 通知列表的挂载 view（ChildView）。列表状态本身在窗口层
    // （WindowNotifications），这里只是"把它画出来"的那个节点。
    struct NotifyEntry {
        gpui::EntityId holder = {};
        uint64_t token = 0;
    };
    std::unordered_map<std::string, NotifyEntry> notifyLists;
    // 通知状态实体。gpui 把它挂在基座 Root 的 WindowLayers 上（WindowLayersOf 走
    // Root::Read(win)），而我们的窗口根是 ShellRoot——那是 Dialog/Toast 的前提，两者互斥。
    // notification.cpp 内部对 Root/Layers 零依赖，所以这份状态由我们自己持有一份，
    // 效果与 WindowNotifications 相同（它做的也就是建实体 + 用主题设置 + 盖 self）。
    gpui::EntityId notifyState = {};

    // 幂等：同 (elementId,type) 复用同一 callbackId，避免每帧无限增长。
    // (id,type) 是 App->on 的路由键，本就一对一映射到一个 handler，复用安全。
    uint64_t newCallback(const std::string& elementId, const std::string& type) {
        std::string key = elementId + "\x1f" + type;
        auto it = cbByKey.find(key);
        if (it != cbByKey.end()) return it->second;
        uint64_t id = nextCallback++;
        callbacks[id] = {elementId, type};
        cbByKey[key] = id;
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

// 通知的 click/close 注册表：listener arg → (box, phpId, 事件类型, 兄弟 arg)。
// 放进程全局而非每窗口一份，是因为回调只拿得到 arg，而反查用的 boxForView 定义在文件
// 末尾的强符号区（此处用不上）；arg 由全局计数器发号，跨窗口天然不撞。
// 一条通知占两个 arg（click 与 close）：close 触发时把两个一起收掉——那时通知已不在
// 列表里，click 不可能再来，所以注册表大小跟着活通知走，不随历史累积。
struct NotifyKey {
    Box2* box = nullptr;
    std::string phpId;
    std::string type;
    int64_t sibling = 0;
};
std::unordered_map<int64_t, NotifyKey> g_notifyKeys;
int64_t g_nextNotifyKey = 1;

// 确保这个窗口有一份通知状态实体，返回它；拿不到窗口就返回 invalid。
gpui::Entity<gpui::component::NotificationListState> EnsureNotifyState(Box2* box) {
    using State = gpui::component::NotificationListState;
    if (!box || !box->window) return {};
    if (!box->notifyState.IsValid()) {
        gpui::Entity<State> st = gpui::EntityNewState<State>(g_app);
        if (!st.id.IsValid()) return {};
        box->notifyState = st.id;
        if (State* s = st.Get(g_app)) {
            s->useThemeSettings = true;  // 与 WindowNotifications 一致：跟主题走
            s->self = st.id;             // 系统通知的应答要往这个 entity 派发
        }
    }
    return gpui::Entity<State>{box->notifyState};
}

#ifdef __APPLE__
// gpui_mac.mm 的事件出口。owner 是注册时交出去的 Box2*；窗口关了就不入队（closed 位）。
static void MacEventSink(void* owner, const char* type, const char* elemId,
                         const char* value) {
    auto* box = static_cast<Box2*>(owner);
    if (!box || box->closed) return;
    box->enqueueRaw(type, elemId, GPUI_ARG_NIL, 0, 0, value);
}
#else
static void gpui_mac_pump(void) {}
static void gpui_mac_release_owner(void*) {}
#endif

gpui::Str cppStr(const std::string& s) {
    return gpui::Str(s.data(), (int)s.size());
}

gpui::Str cstr(const char* s) {
    return gpui::Str(s ? s : "", s ? (int)std::strlen(s) : 0);
}
// 用 const char* 调 cppStr(const std::string&) 会隐式造一个临时 std::string：短串走 SSO、
// 内联在临时对象里，语句结束就析构，返回的 Str 指向死栈槽（tree13 的 toast 把 "Saved" 画成
// "succe" 就是它——长度取的是 "Saved" 的 5，字节是后面那个临时对象复用同一栈槽留下的
// "success" 前缀）。这个重载不造临时对象，把所有 char* 站点一次接住。
gpui::Str cppStr(const char* s) {
    return cstr(s);
}

// gpui::Str → C 缓冲：至多 outlen-1 字节 + '\0'，返回写出的长度（空串返回 0）。
int copyOut(char* out, int outlen, gpui::Str s) {
    if (!out || outlen <= 0) return 0;
    out[0] = '\0';
    int n = s.len;
    if (n > outlen - 1) n = outlen - 1;
    if (n > 0 && s.s) std::memcpy(out, s.s, (size_t)n);
    out[n] = '\0';
    return n;
}

// 有界二进制拷贝：写 min(need, cap) 字节，**返回 need**，也就是"要装下它需要多少"。
// dst 为 NULL 或 cap<=0 时只回长度，即 dry run。不加终止符、不按字符串看：图像流是
// 二进制的，里面出现 0x00 是正常数据。
int copyBounded(char* dst, int cap, const char* src, int need) {
    if (need < 0) need = 0;
    if (dst && cap > 0 && src) {
        int m = need < cap ? need : cap;
        if (m > 0) std::memcpy(dst, src, (size_t)m);
    }
    return need;
}

#ifdef __APPLE__
// gpui 在 mac 上不读粘贴板里的文件 URL：只有 Windows 填 externalPaths
// （window_win.cpp:1636 的 CF_HDROP），mac 的 ClipboardGetItem 只给 text+image。
// "从 Finder 复制文件再粘进应用"是桌面常规动作，所以这一半由我们自己补，返回换行分隔
// 的绝对路径，形状与 gpui 在 Windows 上给的一致，好让 PHP 侧不必分平台。
gpui::Str MacClipboardFilePaths(gpui::Arena* a) {
    NSPasteboard* pb = [NSPasteboard generalPasteboard];
    NSArray<NSURL*>* urls =
        [pb readObjectsForClasses:@[ [NSURL class] ]
                          options:@{NSPasteboardURLReadingFileURLsOnlyKey : @YES}];
    if (!urls || [urls count] == 0) return gpui::Str{};
    std::string joined;
    for (NSURL* url in urls) {
        NSString* path = [url path];
        if (!path) continue;
        const char* utf8 = [path UTF8String];
        if (!utf8) continue;
        if (!joined.empty()) joined += "\n";
        joined += utf8;
    }
    if (joined.empty()) return gpui::Str{};
    return gpui::StrDup(a, gpui::Str(joined.data(), (int)joined.size()));
}
#endif

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
// 同时把原因记进 box->lastError，供 gpui_last_error 回传 PHP 断言。
uint32_t pushErrorNode(Box2* box, gpui::Arena* a, const std::string& msg) {
    box->lastError = msg;
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

// WebView 的包装 view。`gpui::WebView` 自己**不是** view：webview.cpp:95 用
// EntityNewState 建它，注释写明 "a WebView has no Render of its own here, since
// WebViewEl hands the element to whoever wants it"。而 ChildView 走的是
// EntityRender，所以要有这么一层把 WebViewEl 交出去。
struct WebViewView {
    std::string key;
    std::string wantUrl;    // PHP 这一帧要的地址
    std::string wantHtml;   // 或这段本地 HTML
    std::string loadedUrl;  // 已经交给控件的内容，用来判"这一帧要不要再喂一次"
    std::string loadedHtml;
    bool started = false;
    gpui::Entity<gpui::WebView> web = {};

    static gpui::El* Render(void* self, gpui::Ctx* cx) {
        WebViewView* v = static_cast<WebViewView*>(self);
        if (!v || !cx || !cx->a) return nullptr;
        if (!v->started) {
            // 标志必须先落再建：WebViewNew 靠跑窗口消息循环来创建控件，本次调用的那一帧
            // 可能在它返回之前被重建第二次（webview.h:118-123）。
            v->started = true;
            wry::WebViewAttributes attrs;
            // 空 Str 才是"没设"；设了空串是 Some("")，会被当成一个真地址去加载。
            if (!v->wantUrl.empty()) attrs.url = cppStr(v->wantUrl);
            if (!v->wantHtml.empty()) attrs.html = cppStr(v->wantHtml);
            v->web = gpui::WebViewNew(cx, &attrs);
            v->loadedUrl = v->wantUrl;
            v->loadedHtml = v->wantHtml;
        } else if (v->web.id.IsValid()) {
            gpui::WebView* w = v->web.Get(cx->app);
            if (!w) return gpui::Div(cx->a);
            // 换内容走 load，不重建控件——重建要再跑一次嵌套消息循环。url 优先：
            // 同一帧里两个都变时，以地址为准。
            if (!v->wantUrl.empty() && v->wantUrl != v->loadedUrl) {
                gpui::WebViewLoadUrl(w, cppStr(v->wantUrl));
                v->loadedUrl = v->wantUrl;
                v->loadedHtml = v->wantHtml;
            } else if (v->wantUrl.empty() && v->wantHtml != v->loadedHtml) {
                if (wry::WebView* raw = gpui::WebViewRaw(w)) {
                    wry::WebViewLoadHtml(raw, cppStr(v->wantHtml));
                    v->loadedHtml = v->wantHtml;
                }
            }
        }
        if (!v->web.id.IsValid()) return gpui::Div(cx->a);
        return gpui::Div(cx->a)->SizeFull()->Child(gpui::WebViewEl(v->web, cx));
    }
};

// Inspector（DevTools）面板的包装 view。gpui 侧每帧 New→IntoEl 就够了
// （examples/story/story.cpp:1961 的用法），所以这一层没有跨帧状态。
struct InspectorView {
    static gpui::El* Render(void* self, gpui::Ctx* cx) {
        (void)self;
        if (!cx || !cx->a) return nullptr;
        gpui::component::Inspector* panel = gpui::component::Inspector::New(cx);
        if (!panel) return gpui::Div(cx->a);
        gpui::El* el = panel->IntoEl();
        return el ? el : gpui::Div(cx->a);
    }
};

// 通知层的包装 view。gpui 的基座 Root 自己挂它（ui/root.cpp:62），我们的窗口根是
// ShellRoot（只挂 Toast），所以这一层得由 El::notifications() 挂上，状态实体用
// EnsureNotifyState 自己那份（见 Box2::notifyState 为何不用 WindowNotifications）。
struct NotificationListView {
    Box2* box = nullptr;

    static gpui::El* Render(void* self, gpui::Ctx* cx) {
        NotificationListView* v = static_cast<NotificationListView*>(self);
        if (!v || !cx || !cx->a) return nullptr;
        gpui::Entity<gpui::component::NotificationListState> st =
            EnsureNotifyState(v->box);
        if (!st.id.IsValid()) return gpui::Div(cx->a);
        gpui::component::NotificationList* list =
            gpui::component::NotificationList::New(cx, st);
        if (!list) return gpui::Div(cx->a);
        gpui::El* el = list->IntoEl();
        return el ? el : gpui::Div(cx->a);
    }
};

// 一次在飞请求的上下文。done 保证在主线程被调（http.h:100），所以这里入队不需要锁。
struct HttpCtx {
    Box2* box;
    std::string phpId;
};

// 目录监听的一条 watch 上下文。DirWatchStop 之后 onChange 不会再被调
// （dir_watch.h:72 明说，即便已经排过一次），所以停掉就能释放。
struct WatchCtx {
    Box2* box;
    std::string phpId;
};

void OnHttpDone(HttpCtx* ctx, gpui::HttpAsyncResult result) {
    std::unique_ptr<HttpCtx> guard(ctx);  // 回调拥有它，出去就释放
    if (!guard->box || guard->box->closed) return;
    Box2::HttpResponse rec;
    rec.ok = result.ok;
    if (result.response) {
        rec.status = result.response->status;
        const gpui::Vec<uint8_t>& body = result.response->body;
        if (body.els && body.len > 0)
            rec.body.assign(reinterpret_cast<const char*>(body.els),
                            static_cast<size_t>(body.len));
        if (result.response->contentType.s &&
            result.response->contentType.len > 0)
            rec.contentType.assign(result.response->contentType.s,
                                   static_cast<size_t>(
                                       result.response->contentType.len));
    }
    Box2* box = guard->box;
    const std::string id = guard->phpId;
    box->httpResponses[id] = std::move(rec);
    // 事件只报"这条 id 有答案了"，内容留给 gpui_shim_http_response 取——
    // GpuiEvent 的 vstr 只有 256 字节，装不下应答体。
    box->enqueueRaw("http", id.c_str(), GPUI_ARG_NUM,
                    static_cast<double>(box->httpResponses[id].status),
                    box->httpResponses[id].ok ? 1 : 0, nullptr);
}

void OnDirWatch(WatchCtx* ctx) {
    if (!ctx || !ctx->box || ctx->box->closed) return;
    ctx->box->enqueueRaw("dirChange", ctx->phpId.c_str(), GPUI_ARG_NIL, 0, 0,
                         nullptr);
}

// 通知的 click/close：arg 是 g_notifyKeys 的键。close 那条 ev 恒为 nullptr（它是
// "通知已从列表移除"，由退场结束或 dismiss 完成触发，不是点击），所以这里不碰 ev。
void OnNotifyListener(gpui::ScriptView*, gpui::Ctx*, const void*, int64_t arg) {
    auto it = g_notifyKeys.find(arg);
    if (it == g_notifyKeys.end()) return;
    Box2* box = it->second.box;
    const std::string phpId = it->second.phpId;
    const std::string type = it->second.type;
    const int64_t sibling = it->second.sibling;
    if (type == "close") {
        // 通知已经离开列表，click 不可能再来：两个键一起收，注册表只跟活通知成正比。
        g_notifyKeys.erase(arg);
        g_notifyKeys.erase(sibling);
    }
    if (box && !box->closed)
        box->enqueueRaw(type.c_str(), phpId.c_str(), GPUI_ARG_NIL, 0, 0, nullptr);
}

gpui::Listener MakeNotifyListener(int64_t arg, gpui::EntityId view) {
    gpui::Listener l;
    l.SetFn(&OnNotifyListener);
    l.SetHasArg();
    l.view = view;
    l.arg = arg;
    return l;
}

gpui::component::NotificationType NotifyTypeFor(const char* s) {
    using T = gpui::component::NotificationType;
    std::string v = s ? s : "";
    if (v == "success") return T::Success;
    if (v == "warning") return T::Warning;
    if (v == "error") return T::Error;
    return T::Info;  // 空与非法都回落 info，与 toast 的 level 同一套字面
}

gpui::Anchor NotifyAnchorFor(const char* s) {
    using A = gpui::Anchor;
    std::string v = s ? s : "";
    if (v == "top_left") return A::TopLeft;
    if (v == "top_center") return A::TopCenter;
    if (v == "bottom_left") return A::BottomLeft;
    if (v == "bottom_center") return A::BottomCenter;
    if (v == "bottom_right") return A::BottomRight;
    if (v == "left_center") return A::LeftCenter;
    if (v == "right_center") return A::RightCenter;
    return A::TopRight;  // gpui 自己的默认（notification.h:53）
}

void ensureApp() {
    if (g_app) return;
    g_app = gpui::AppNew();
    // mac 集成的事件出口：owner 是注册时传进去的 Box2*，回调时原样带回来，所以多窗口
    // 各回各的队列。只装一次。
    gpui_mac_set_sink(&MacEventSink);
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
    gpui_mac_pump();  // 先排空 Carbon 队列：我们的泵不会替它派发（见 gpui_mac.mm）
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
    if (!box) return;
    // 托盘项/快捷键持有指向这个 Box2 的 owner 指针，窗口一走必须全部撤掉。
    gpui_mac_release_owner(box);
    // WebView 的 OS 控件必须在父窗口销毁之前放手（webview.h:50-51），所以这一段排在
    // AppClose 前面。holder entity 不在 ShellRoot 的拆除路径里（ChildView 每帧只是
    // EntityRender，不接管生命周期），只能自己 drop；先 drop WebView 再 drop holder，
    // 因为 holder 的 deleter 会 delete WebViewView 本体。
    for (auto& kv : box->webviews) {
        Box2::WebEntry& e = kv.second;
        if (e.view && e.view->web.id.IsValid())
            gpui::EntityDrop(g_app, e.view->web.id);
        if (e.holder.IsValid()) gpui::EntityDrop(g_app, e.holder);
        e.holder = {};
        e.view = nullptr;
    }
    box->webviews.clear();
    for (auto& kv : box->inspectors) {
        if (kv.second.holder.IsValid()) gpui::EntityDrop(g_app, kv.second.holder);
    }
    box->inspectors.clear();
    // 目录监听：先 stop 再释放上下文。dir_watch.h:72 保证 stop 之后 onChange 不会再被
    // 调（哪怕已经排过一次），所以这一步之后没人再持有 WatchCtx 指针。
    for (auto& kv : box->watchCtx) gpui::DirWatchStop(kv.first);
    for (auto& kv : box->watchCtx) delete kv.second;
    box->watchCtx.clear();
    // 未读的应答：body 是我们自己拷的 std::string，随 map 一起走。
    box->httpResponses.clear();
    // 通知层的 holder 与 webview 同理：ChildView 不接管生命周期，得自己 drop。
    for (auto& kv : box->notifyLists) {
        if (kv.second.holder.IsValid()) gpui::EntityDrop(g_app, kv.second.holder);
    }
    box->notifyLists.clear();
    // 通知状态：先停它的 50ms 时钟（时钟是挂在窗口上的定时器，窗口没了它没意义），
    // 再 drop 实体。顺序与 webview 一样放在 AppClose 之前。
    if (box->notifyState.IsValid()) {
        auto* st = gpui::Entity<gpui::component::NotificationListState>{
                         box->notifyState}
                         .Get(g_app);
        if (st) gpui::component::NotificationStopAdvancing(st);
        gpui::EntityDrop(g_app, box->notifyState);
        box->notifyState = {};
    }
    // 注册表是进程全局的：把属于这个 box 的键收掉（通知本身随窗口层一起没了）。
    for (auto it = g_notifyKeys.begin(); it != g_notifyKeys.end();) {
        if (it->second.box == box) it = g_notifyKeys.erase(it);
        else ++it;
    }
    if (box->window) {
        gpui::AppClose(box->window);
        box->window = nullptr;  // AppClose 已撤窗口及其定时器；置空防后续误用
    }
    box->closed = true;
    box->timerIds.clear();
    box->timerKeys.clear();
    // dialog 内容 Box2 无 PHP 句柄引用（open_dialog 只回计数），删其 wrapper。
    // 但**不**手动 EntityDrop / delete PhpView：AppClose→ShellRoot 拆除已经过
    // EntityNewRaw 的 deleter 释放各 PhpView（含 dialog 内容视图），我们再删就是
    // double-free/UAF（tree13 未关 dialog 直接 close 曾触发 SIGABRT）。
    for (Box2* dlg : box->dialogs) {
        delete dlg;
    }
    box->dialogs.clear();
    // 主 Box2 保守不 delete：PHP WindowBox->handle 仍指向它，删则 UAF。单窗口应用
    // 退出由 OS 回收；多窗口每窗口一份，量级远小于每帧/每 dialog 泄漏。
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
    if (box) {
        box->specs.Reset();
        box->lastError.clear();  // 每帧只报本帧的 registered 失败
    }
}

int gpui_shim_last_error(void* win, char* out, int outlen) {
    auto* box = static_cast<Box2*>(win);
    if (!box || outlen <= 0) return 0;
    int n = (int)box->lastError.size();
    if (n > outlen - 1) n = outlen - 1;
    if (n > 0) std::memcpy(out, box->lastError.data(), (size_t)n);
    out[n] = '\0';
    return n;
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

void gpui_shim_set_app_menus(void* win, const GpuiMenu* menus, int count) {
    (void)win;
    if (!g_app || count < 0) return;
    // BaseSetAppMenus 深拷进自己的 arena，故 defs/rows 只需在本次调用内有效。
    std::vector<gpui::MenuDef> defs(static_cast<size_t>(count));
    std::vector<std::vector<gpui::MenuRow>> rows(static_cast<size_t>(count));
    for (int i = 0; i < count; ++i) {
        const GpuiMenu& m = menus[i];
        int n = m.count > 0 ? m.count : 0;
        rows[static_cast<size_t>(i)].assign(static_cast<size_t>(n), gpui::MenuRow{});
        for (int j = 0; j < n; ++j) {
            const GpuiMenuItem& it = m.items[j];
            gpui::MenuRow& r = rows[static_cast<size_t>(i)][static_cast<size_t>(j)];
            r.label = gpui::Str(it.label ? it.label : "");
            r.action = (it.action && *it.action)
                           ? gpui::shell::ShellActionOf(gpui::Str(it.action))
                           : 0;
            r.stroke = nullptr;  // 拷指针会悬垂，本版不支持快捷键
            r.separator = it.separator != 0;
            r.disabled = it.disabled != 0;
            r.checked = it.checked != 0;
        }
        gpui::MenuDef& d = defs[static_cast<size_t>(i)];
        d = gpui::MenuDef{};
        d.name = gpui::Str(m.name ? m.name : "");
        d.items = n > 0 ? rows[static_cast<size_t>(i)].data() : nullptr;
        d.n = n;
        d.disabled = m.disabled != 0;
    }
    gpui::BaseSetAppMenus(g_app, count > 0 ? defs.data() : nullptr, count);
}

int gpui_shim_app_menu_count(void* win) {
    (void)win;
    if (!g_app) return 0;
    int n = 0;
    gpui::BaseAppMenus(g_app, &n);
    return n;
}

// ── 系统能力 ────────────────────────────────────────────────────────────
// 每一项都只包 gpui.o 里已有的符号：不新增上游补丁，不引外部库。

int gpui_shim_clipboard_get_text(void* win, char* out, int outlen) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !box->window) return 0;
    return copyOut(out, outlen,
                   gpui::ClipboardGetText(box->specs.Storage(), box->window));
}

void gpui_shim_clipboard_set_text(void* win, const char* text) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !box->window) return;
    // mac 侧 ClipboardSetText 当场把文本转成 NSString 交给粘贴板，借用缓冲安全。
    gpui::ClipboardSetText(box->window, cstr(text));
}

int gpui_shim_clipboard_item(void* win, char* outText, int textCap, int* outTextLen,
                             char* outImg, int imgCap, int* outImgLen,
                             char* outPaths, int pathsCap, int* outPathsLen) {
    if (outTextLen) *outTextLen = 0;
    if (outImgLen) *outImgLen = 0;
    if (outPathsLen) *outPathsLen = 0;
    auto* box = static_cast<Box2*>(win);
    if (!box || !box->window) return 0;
    // 一次快照读全部表示；返回的视图属于传进去的 arena，所以当场拷走。
    gpui::ClipboardItem item =
        gpui::ClipboardGetItem(box->specs.Storage(), box->window);
    int flags = 0;
    if (item.text.len > 0) {
        if (outTextLen)
            *outTextLen = copyBounded(outText, textCap, item.text.s, item.text.len);
        flags |= 1;
    }
    if (item.HasImage()) {
        if (outImgLen)
            *outImgLen = copyBounded(outImg, imgCap,
                                     reinterpret_cast<const char*>(
                                         item.imageBytes),
                                     item.imageBytesLen);
        flags |= 2;
    }
    gpui::Str paths = item.externalPaths;
#ifdef __APPLE__
    if (paths.len <= 0)
        paths = MacClipboardFilePaths(box->specs.Storage());
#endif
    if (paths.len > 0) {
        if (outPathsLen)
            *outPathsLen = copyBounded(outPaths, pathsCap, paths.s, paths.len);
        flags |= 4;
    }
    return flags;
}

int gpui_shim_prompt_for_path(void* win, int directories, const char* title,
                              char* out, int outlen) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !box->window) return 0;
    gpui::PathPrompt opts{};
    // 两个都 false 是"什么都选不了"，故选目录时把 files 关掉。
    opts.directories = directories != 0;
    opts.files = !opts.directories;
    opts.title = cstr(title);
    return copyOut(out, outlen, gpui::PromptForPathTemp(box->window, opts));
}

int gpui_shim_open_url(const char* url) {
    if (!url || !*url) return 0;
    gpui::Str u = cstr(url);
    // 与 Link.href / TextView 同一条规则，不另开一套白名单。
    if (!gpui::IsOpenableUrl(u)) return 0;
    gpui::OpenUrl(u);
    return 1;
}

void gpui_shim_toggle_inspector(void* win) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !box->window) return;
    gpui::WindowToggleInspector(box->window);
}

int gpui_shim_show_fps(void* win, const char* anchor) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !box->window) return 0;
    gpui::FpsHudRequest req{};
    if (anchor && *anchor && !gpui::FpsAnchorFromName(cstr(anchor), &req.anchor))
        return 0;
    gpui::Ctx cx = overlayCtx(box);
    return gpui::ShellRootShowFpsMonitor(&cx, req) ? 1 : 0;
}

int gpui_shim_hide_fps(void* win) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !box->window) return 0;
    gpui::Ctx cx = overlayCtx(box);
    return gpui::ShellRootHideFpsMonitor(&cx) ? 1 : 0;
}

int gpui_shim_fps_visible(void* win) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !box->window) return 0;
    gpui::Ctx cx = overlayCtx(box);
    return gpui::ShellRootFpsMonitorVisible(&cx) ? 1 : 0;
}

int gpui_shim_sheet_show(void* win, void* dlgv, const char* anchor) {
    auto* box = static_cast<Box2*>(win);
    auto* dlg = static_cast<Box2*>(dlgv);
    if (!box || !box->window || !dlg) return 0;
    gpui::Placement p = gpui::Placement::Right;
    std::string a = anchor ? anchor : "";
    if (a == "top") p = gpui::Placement::Top;
    else if (a == "bottom") p = gpui::Placement::Bottom;
    else if (a == "left") p = gpui::Placement::Left;
    gpui::Ctx cx = overlayCtx(box);
    if (!gpui::ShellRootOpenSheetView(&cx, dlg->viewEntity, p)) return 0;
    // 内容 Box2 与 dialog 同一套：保活到窗口析构时统一 delete。
    box->dialogs.push_back(dlg);
    return 1;
}

int gpui_shim_close_sheet(void* win) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !box->window) return 0;
    gpui::Ctx cx = overlayCtx(box);
    return gpui::ShellRootCloseSheet(&cx) ? 1 : 0;
}

int gpui_shim_has_sheet(void* win) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !box->window) return 0;
    gpui::Ctx cx = overlayCtx(box);
    return gpui::ShellRootHasSheet(&cx) ? 1 : 0;
}

uint32_t gpui_shim_webview(void* win, const char* id, const char* url,
                           const char* html) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !box->window || !g_runtime) return 0;
    std::string key = (id && *id) ? id : std::string("__webview");
    Box2::WebEntry& e = box->webviews[key];
    if (!e.holder.IsValid()) {
        auto* view = new WebViewView();
        view->key = key;
        view->wantUrl = url ? url : "";
        view->wantHtml = html ? html : "";
        e.view = view;
        e.holder = gpui::EntityNewRaw(
            g_app, view, (gpui::RenderFn)&WebViewView::Render,
            [](void* p) { delete static_cast<WebViewView*>(p); });
        e.token = g_runtime->RegisterHostView(e.holder, g_app);
        if (e.token == 0) {
            // 注册失败（token 空间耗尽）：把刚建的实体放干净，别留一个没人渲染的 entity。
            // 只 drop holder——它的 deleter 负责 delete WebViewView 本体，再 delete 一次
            // 就是 double-free。
            gpui::EntityDrop(g_app, e.holder);
            e.holder = {};
            e.view = nullptr;
            return 0;
        }
    } else {
        if (url) e.view->wantUrl = url;
        if (html) e.view->wantHtml = html;
    }
    gpui::shell::Component c;
    c.kind = gpui::shell::ComponentKind::ChildView;
    c.handle = e.token;
    return box->specs.Push(c);
}

int gpui_shim_webview_probe(void* win, const char* id, char* outUrl, int outlen,
                            double* outW, double* outH) {
    auto* box = static_cast<Box2*>(win);
    if (outUrl && outlen > 0) outUrl[0] = '\0';
    if (outW) *outW = 0;
    if (outH) *outH = 0;
    if (!box || !id || !g_app) return 0;
    auto it = box->webviews.find(id);
    if (it == box->webviews.end() || !it->second.view) return 0;
    gpui::WebView* w = it->second.view->web.Get(g_app);
    if (!w) return 0;
    wry::WebView* raw = gpui::WebViewRaw(w);
    if (!raw) return 0;
    if (outUrl && outlen > 0) {
        gpui::Str u = wry::WebViewUrlTemp(raw);
        copyOut(outUrl, outlen, u);
    }
    wry::Rect r{};
    if (wry::WebViewBounds(raw, &r)) {
        if (outW) *outW = r.size.width;
        if (outH) *outH = r.size.height;
    }
    return 1;
}

uint32_t gpui_shim_inspector(void* win, const char* id) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !box->window || !g_runtime) return 0;
    std::string key = (id && *id) ? id : std::string("__inspector");
    Box2::InspEntry& e = box->inspectors[key];
    if (!e.holder.IsValid()) {
        e.holder = gpui::EntityNewRaw(
            g_app, new InspectorView(), (gpui::RenderFn)&InspectorView::Render,
            [](void* p) { delete static_cast<InspectorView*>(p); });
        e.token = g_runtime->RegisterHostView(e.holder, g_app);
        if (e.token == 0) {
            // 只 drop holder：deleter 负责 delete InspectorView，再 delete 就是 double-free。
            gpui::EntityDrop(g_app, e.holder);
            e.holder = {};
            return 0;
        }
    }
    gpui::shell::Component c;
    c.kind = gpui::shell::ComponentKind::ChildView;
    c.handle = e.token;
    return box->specs.Push(c);
}

// ── 缺口服务：HTTP / 目录监听 / 解压 ──────────────────────────────────────
// HttpCtx / WatchCtx 与两个回调在上面的匿名 namespace 里：它们要碰 Box2，而 Box2
// 是匿名类型，在外面再声明一次同名 struct 会让查找二义。

int gpui_shim_http_get(void* win, const char* php_id, const char* url) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !box->window || !php_id || !*php_id || !url || !*url) return 0;
    gpui::Str u = cstr(url);
    // 只放行 http/https：data:/file:/ftp: 归别人管（http.h:89）。
    if (!gpui::HttpUrlIsRemote(u)) return 0;
    gpui::HttpReq req{};
    req.url = u;  // HttpSendAsync 返回前把字段拷走，借用的缓冲安全
    auto* ctx = new HttpCtx{box, std::string(php_id)};
    if (!gpui::HttpSendAsync(req, base::MkFunc1(OnHttpDone, ctx))) {
        delete ctx;
        return 0;
    }
    return 1;
}

int gpui_shim_http_response(void* win, const char* php_id, char* out, int outcap,
                            int* outStatus, int* outOk, char* outCt, int ctCap) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !php_id) return -1;
    auto it = box->httpResponses.find(php_id);
    if (it == box->httpResponses.end()) return -1;
    const Box2::HttpResponse& r = it->second;
    if (outStatus) *outStatus = r.status;
    if (outOk) *outOk = r.ok ? 1 : 0;
    if (outCt && ctCap > 0) copyOut(outCt, ctCap, cstr(r.contentType.c_str()));
    int n = static_cast<int>(r.body.size());
    if (!out || outcap <= 0) return n;
    int m = n < outcap ? n : outcap;
    if (m > 0) std::memcpy(out, r.body.data(), static_cast<size_t>(m));
    return m;
}

int gpui_shim_http_free(void* win, const char* php_id) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !php_id) return 0;
    return box->httpResponses.erase(php_id) > 0 ? 1 : 0;
}

int gpui_shim_watch_dir(void* win, const char* php_id, const char* dir,
                        int create) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !php_id || !*php_id || !dir || !*dir) return 0;
    auto* ctx = new WatchCtx{box, std::string(php_id)};
    gpui::DirWatchError err = gpui::DirWatchError::None;
    int id = gpui::DirWatchStart(cstr(dir), base::MkFunc0(OnDirWatch, ctx),
                                 create != 0, &err);
    if (id == 0) {
        delete ctx;
        return 0;
    }
    box->watchCtx[id] = ctx;
    return id;
}

void gpui_shim_unwatch_dir(void* win, int watch_id) {
    auto* box = static_cast<Box2*>(win);
    if (!box || watch_id <= 0) return;
    auto it = box->watchCtx.find(watch_id);
    gpui::DirWatchStop(watch_id);
    if (it != box->watchCtx.end()) {
        delete it->second;
        box->watchCtx.erase(it);
    }
}

int gpui_shim_watch_fake(void* win, const char* php_id) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !php_id || !*php_id) return 0;
    auto* ctx = new WatchCtx{box, std::string(php_id)};
    int id = gpui::DirWatchAdd(base::MkFunc0(OnDirWatch, ctx));
    if (id == 0) {
        delete ctx;
        return 0;
    }
    box->watchCtx[id] = ctx;
    gpui::DirWatchSignal(id);  // 排一次主线程回调；要 pump 一下才落地
    return id;
}

int gpui_shim_inflate(const char* in, int inlen, int gzip, char* out, int outcap,
                      char* err, int errcap) {
    if (err && errcap > 0) err[0] = '\0';
    if (!in || inlen < 0) return -1;
    gpui::Str output{};   // 成功时是解出来的字节，调用方拷走后 StrFree
    gpui::Str message{};  // 失败时是原因
    bool ok = gpui::shell::ZlibInflate(gpui::Str(in, inlen), gzip != 0, &output,
                                       &message);
    if (!ok) {
        copyOut(err, errcap > 0 ? errcap : 1, message);
        if (message.s) gpui::StrFree(message);
        if (output.s) gpui::StrFree(output);
        return -1;
    }
    int n = output.len;
    if (out && outcap > 0) {
        int m = n < outcap ? n : outcap;
        if (m > 0) std::memcpy(out, output.s, static_cast<size_t>(m));
    }
    if (output.s) gpui::StrFree(output);
    if (message.s) gpui::StrFree(message);
    return n;
}

int gpui_shim_sys_refresh(GpuiSysInfo* out, GpuiProcess* procs, int procsCap,
                          int topProcs, int* outProcCount) {
    if (outProcCount) *outProcCount = 0;
    if (!out) return 0;
    if (!g_sys) {
        g_sys = new gpui::SysState();
        gpui::SysStateInit(g_sys);
    }
    gpui::SysRefresh(g_sys);
    if (topProcs > 0 && procs && procsCap > 0) {
        // 排序截断在 gpui 侧做：它一次采样就列出全机几百个进程，让 PHP 挑前 N 条
        // 意味着先把几百条搬过边界。
        gpui::SysSortProcesses(g_sys, gpui::ProcessSort::Cpu, true, topProcs);
    }
    out->cpu = g_sys->cpu;
    out->memPct = g_sys->mem;
    out->memTotalBytes = static_cast<long long>(g_sys->memTotal);
    out->memUsedBytes = static_cast<long long>(g_sys->memUsed);
    out->diskPct = g_sys->disk.usedPct;
    out->diskTotalBytes = static_cast<long long>(g_sys->disk.total);
    out->diskUsedBytes = static_cast<long long>(g_sys->disk.used);
    out->ncpu = g_sys->ncpu;
    out->batteryPresent = g_sys->battery.present ? 1 : 0;
    out->batteryCharging = g_sys->battery.charging ? 1 : 0;
    out->batteryPct = g_sys->battery.pct;
    if (procs && procsCap > 0) {
        int n = g_sys->procs.len < procsCap ? g_sys->procs.len : procsCap;
        for (int i = 0; i < n; i++) {
            const gpui::ProcessInfo& p = g_sys->procs.els[i];
            procs[i].pid = static_cast<int>(p.pid);
            procs[i].name = p.name;  // StrCopyZ 过，NUL 结尾；只活到下一次采样
            procs[i].cpu = p.cpu;
            procs[i].memoryBytes = static_cast<long long>(p.memory);
        }
        if (outProcCount) *outProcCount = n;
    }
    return 1;
}

// 通知状态 + 一个能喂给 gpui 通知函数的 Ctx（与 toast/dialog/sheet 同一套构造）。
static gpui::component::NotificationListState* NotifyState(gpui::Ctx* cx,
                                                            Box2* box) {
    if (!box || !box->window) return nullptr;
    *cx = overlayCtx(box);
    gpui::Entity<gpui::component::NotificationListState> st =
        EnsureNotifyState(box);
    if (!st.id.IsValid()) return nullptr;
    return st.Get(cx->app);
}

int gpui_shim_notify(void* win, const char* php_id, const char* title,
                     const char* message, const char* type,
                     const char* placement, int timeout_ms) {
    auto* box = static_cast<Box2*>(win);
    gpui::Ctx cx{};
    gpui::component::NotificationListState* st = NotifyState(&cx, box);
    if (!st) return 0;
    gpui::component::Notification item = gpui::component::Notification::New();
    // Notification 的字符串在 Push 之前是借用的，Push 时才克隆；桥传来的缓冲活到
    // 本次调用结束，所以这里借用是安全的。
    item.title = cstr(title);
    item.message = cstr(message);
    item.type = NotifyTypeFor(type);
    item.hasType = true;  // 渲染侧的门是 if (item.hasType)（notification.cpp:982），
                          // 只写 type 的话永远选不到 IconName ⇒ 卡片没有图标。
    item.hasPlacement = true;
    item.placement = NotifyAnchorFor(placement);
    item.autohide = timeout_ms > 0;
    if (php_id && *php_id) {
        const int64_t clickArg = g_nextNotifyKey++;
        const int64_t closeArg = g_nextNotifyKey++;
        g_notifyKeys[clickArg] = {box, php_id, "click", closeArg};
        g_notifyKeys[closeArg] = {box, php_id, "close", clickArg};
        item.onClick = MakeNotifyListener(clickArg, box->viewEntity);
        item.onClose = MakeNotifyListener(closeArg, box->viewEntity);
    }
    return gpui::component::NotificationPush(st, &cx, item, timeout_ms);
}

int gpui_shim_notify_dismiss(void* win, int notify_id) {
    auto* box = static_cast<Box2*>(win);
    gpui::Ctx cx{};
    gpui::component::NotificationListState* st = NotifyState(&cx, box);
    if (!st || notify_id <= 0) return 0;
    if (gpui::component::NotificationIndexOf(st, notify_id) < 0) return 0;
    gpui::component::NotificationDismiss(st, &cx, notify_id);
    return 1;
}

void gpui_shim_notify_clear(void* win) {
    auto* box = static_cast<Box2*>(win);
    gpui::Ctx cx{};
    gpui::component::NotificationListState* st = NotifyState(&cx, box);
    if (!st) return;
    gpui::component::NotificationClear(st, &cx);
}

int gpui_shim_notify_count(void* win) {
    auto* box = static_cast<Box2*>(win);
    gpui::Ctx cx{};
    gpui::component::NotificationListState* st = NotifyState(&cx, box);
    return st ? st->items.len : 0;
}

uint32_t gpui_shim_notification_list(void* win, const char* id) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !box->window || !g_runtime) return 0;
    std::string key = (id && *id) ? id : std::string("__notifications");
    Box2::NotifyEntry& e = box->notifyLists[key];
    if (!e.holder.IsValid()) {
        // payload 带着 box：Render 要拿它找这份窗口自己的通知状态。
        e.holder = gpui::EntityNewRaw(
            g_app, new NotificationListView{box},
            (gpui::RenderFn)&NotificationListView::Render,
            [](void* p) { delete static_cast<NotificationListView*>(p); });
        e.token = g_runtime->RegisterHostView(e.holder, g_app);
        if (e.token == 0) {
            gpui::EntityDrop(g_app, e.holder);
            e.holder = {};
            return 0;
        }
    }
    gpui::shell::Component c;
    c.kind = gpui::shell::ComponentKind::ChildView;
    c.handle = e.token;
    return box->specs.Push(c);
}

int gpui_shim_webview_nav(void* win, const char* id, int dir) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !id || !g_app) return 0;
    auto it = box->webviews.find(id);
    if (it == box->webviews.end() || !it->second.view) return 0;
    gpui::WebView* w = it->second.view->web.Get(g_app);
    if (!w) return 0;
    if (dir < 0) gpui::WebViewBack(w);
    else if (dir > 0) gpui::WebViewForward(w);
    else return 0;
    return 1;
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
    if (!m || !m->recorder) {
        box->lastError = std::string("no such registered method '") +
                         (name ? name : "") + "'";
        return;
    }
    std::string err;
    gpui::shell::ComponentArgument* arguments = nullptr;
    int schemaN = buildComponentArgs(a, m->arguments, args, argc, &arguments,
                                     &err);
    if (schemaN < 0) {
        box->lastError = std::string(name ? name : "") + ": " + err;
        return;
    }
    gpui::shell::PayloadBuild build;
    build.a = a;
    if (!m->recorder(&build, arguments, schemaN)) {
        box->lastError = std::string(name ? name : "") + ": recorder failed";
        return;
    }
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
    std::string key = std::string(elem_id ? elem_id : "") + "\x1f" +
                      (type ? type : "");
    // 每帧重建同结构：命中缓存直接复用 runtime id，不再 PushShimCallback 追加条目。
    auto cached = box->shimCallbacks.find(key);
    if (cached != box->shimCallbacks.end()) return cached->second;
    // box 侧记 (elemId,type)→boxCbId（事件回传时反查）；runtime 侧登记一个带
    // shimId=boxCbId 的合成回调条目，返回其 runtime CallbackId 塞进
    // ComponentArgument.callback。点击时 InvokeWith 命中 shimId → gpuiEnqueueShimCallback
    // → box->enqueue(boxCbId) → 复用同一 (elemId,type)。
    uint64_t boxCb = box->newCallback(elem_id ? elem_id : "", type ? type : "");
    uint64_t res = boxCb;
    if (g_runtime && box->viewEntity.IsValid()) {
        uint64_t rt = g_runtime->PushShimCallback(box->viewEntity, boxCb);
        if (rt) res = rt;
    }
    box->shimCallbacks[key] = res;
    return res;
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
// ── 授权文件系统 / 进程 / 存储（Capabilities）────────────────────────────────
//
// 进程级一份 gpui::Capabilities：gpui 的 FsRun 要 (root, relative) 两半，root 必须是
// 事先授予过的目录，所以"授权"这件事天然是 app 级而不是窗口级。
//
// scratch 的生命周期是**到下一次调用为止**：桥在同一个调用栈里立刻拷走，PHP 侧拿到的
// 是 php::String 的副本。列目录的名字用 vector<string> 先建完再回指针，避免 push_back
// 触发搬移让先前给出去的 c_str() 悬垂。

namespace {

gpui::Capabilities g_caps;
gpui::shell::Storage* g_storage = nullptr;
std::string g_scratch;     // 上一次成功调用的字节
std::string g_scratchErr;  // 进程 stderr
std::vector<std::string> g_fsNames;

gpui::shell::Storage* StorageNow() {
    if (!g_storage) g_storage = new gpui::shell::Storage(true);
    return g_storage;
}

// CapabilityError → 人话。不借 CapabilityErrorMessage（它要 Arena，而这里没有帧）。
void SetError(char* err, int errCap, const char* msg) {
    if (err && errCap > 0) copyOut(err, errCap, gpui::Str(msg, (int)std::strlen(msg)));
}

void SetCapError(char* err, int errCap, const gpui::CapabilityError& ce) {
    const char* kind = "被拒";
    switch (ce.kind) {
        case gpui::CapabilityErrorKind::NotGranted: kind = "没有这项授权"; break;
        case gpui::CapabilityErrorKind::OutsideRoots: kind = "路径不在授予的根里"; break;
        case gpui::CapabilityErrorKind::ExecuteDenied: kind = "命令不在白名单里"; break;
        case gpui::CapabilityErrorKind::StorageDenied: kind = "没有存储授权"; break;
        default: break;
    }
    std::string msg = std::string("gpui 授权拒绝（") + kind + "）: " +
                      (ce.subject.s ? std::string(ce.subject.s, (size_t)ce.subject.len) : std::string());
    SetError(err, errCap, msg.c_str());
}

// 选根：把用户给的路径拆成 (root, relative)。false 时已写好 err。
bool Resolve(const char* path, gpui::CapabilityAccess access,
             gpui::CapabilityPath* out, char* err, int errCap) {
    gpui::CapabilityError ce;
    if (!g_caps.ResolvePath(cstr(path), access, out, &ce)) {
        SetCapError(err, errCap, ce);
        out->Free();
        return false;
    }
    return true;
}

void SetFsError(char* err, int errCap, gpui::Str ferr) {
    if (!err || errCap <= 0) return;
    if (ferr.s && ferr.len > 0) {
        copyOut(err, errCap, ferr);
        return;
    }
    SetError(err, errCap, "gpui FsRun 失败");
}

}  // namespace

int gpui_shim_grant_root(const char* root, int writable) {
    if (!root || !*root) return 0;
    if (writable) {
        // gpui 把读根与写根当两张表（ResolvePath 按 access 只查对应那张），但"能写不能读"
        // 的根没有用处，所以 writable=true 两张都加。
        g_caps.AddReadRoot(cstr(root));
        g_caps.AddWriteRoot(cstr(root));
        return g_caps.HasWriteAccess() ? 1 : 0;
    }
    g_caps.AddReadRoot(cstr(root));
    return g_caps.HasReadAccess() ? 1 : 0;
}

int gpui_shim_grant_execute(const char* const* commands, int count,
                            int unrestricted) {
    if (unrestricted) {
        g_caps.SetExecute(gpui::ExecuteGrant::Unrestricted());
        return 1;
    }
    std::vector<gpui::Str> list;
    for (int i = 0; i < count && commands; i++) list.push_back(cstr(commands[i]));
    g_caps.SetExecute(gpui::ExecuteGrant::Allowed(
        list.empty() ? nullptr : list.data(), (int)list.size()));
    return 1;
}

void gpui_shim_grant_storage(int allowed) { g_caps.Storage(allowed != 0); }

int gpui_shim_storage_granted(void) { return g_caps.HasStorage() ? 1 : 0; }

int gpui_shim_storage_path(const char* path, char* err, int err_cap) {
    gpui::Str perr;
    if (!StorageNow()->SetPath(cstr(path), &perr)) {
        SetFsError(err, err_cap, perr);
        return 0;
    }
    return 1;
}

int gpui_shim_fs_read(const char* path, char* err, int err_cap) {
    gpui::CapabilityPath cp;
    if (!Resolve(path, gpui::CapabilityAccess::Read, &cp, err, err_cap)) return 0;
    gpui::shell::FsResult res;
    gpui::Str ferr;
    const bool ok = gpui::shell::FsRun(gpui::shell::FsOperation::Read, cp.root,
                                       cp.relative, gpui::Str{}, false, &res, &ferr);
    cp.Free();
    if (!ok) {
        SetFsError(err, err_cap, ferr);
        res.Free();
        return 0;
    }
    g_scratch.assign(res.bytes.s ? res.bytes.s : "", (size_t)(res.bytes.len > 0 ? res.bytes.len : 0));
    res.Free();
    return 1;
}

int gpui_shim_fs_write(const char* path, const char* data, int len, char* err,
                       int err_cap) {
    gpui::CapabilityPath cp;
    if (!Resolve(path, gpui::CapabilityAccess::Write, &cp, err, err_cap)) return 0;
    gpui::shell::FsResult res;
    gpui::Str ferr;
    const bool ok = gpui::shell::FsRun(gpui::shell::FsOperation::Write, cp.root,
                                       cp.relative,
                                       gpui::Str(data ? data : "", len > 0 ? len : 0),
                                       false, &res, &ferr);
    cp.Free();
    if (!ok) SetFsError(err, err_cap, ferr);
    res.Free();
    return ok ? 1 : 0;
}

int gpui_shim_fs_list(const char* path, GpuiFsEntry* entries, int cap,
                      int* out_count, char* err, int err_cap) {
    gpui::CapabilityPath cp;
    if (!Resolve(path, gpui::CapabilityAccess::Read, &cp, err, err_cap)) return 0;
    gpui::shell::FsResult res;
    gpui::Str ferr;
    const bool ok = gpui::shell::FsRun(gpui::shell::FsOperation::ReadDirectory,
                                       cp.root, cp.relative, gpui::Str{}, false,
                                       &res, &ferr);
    cp.Free();
    if (!ok) {
        SetFsError(err, err_cap, ferr);
        res.Free();
        return 0;
    }
    // 先把名字全建完，再回指针：边 push_back 边给 c_str() 会在扩容时悬垂。
    g_fsNames.clear();
    g_fsNames.reserve((size_t)res.entries.len);
    for (int i = 0; i < res.entries.len; i++) {
        gpui::Str n = res.entries.els[i].name;
        g_fsNames.emplace_back(n.s ? n.s : "", (size_t)(n.len > 0 ? n.len : 0));
    }
    const int n = std::min<int>(cap, (int)g_fsNames.size());
    for (int i = 0; i < n && entries; i++) {
        entries[i].name = g_fsNames[(size_t)i].c_str();
        entries[i].is_dir = res.entries.els[i].isDirectory ? 1 : 0;
    }
    if (out_count) *out_count = (int)g_fsNames.size();
    res.Free();
    return 1;
}

int gpui_shim_fs_exists(const char* path) {
    gpui::CapabilityPath cp;
    if (!Resolve(path, gpui::CapabilityAccess::Read, &cp, nullptr, 0)) return 0;
    gpui::shell::FsResult res;
    gpui::Str ferr;
    const bool ok = gpui::shell::FsRun(gpui::shell::FsOperation::Exists, cp.root,
                                       cp.relative, gpui::Str{}, false, &res, &ferr);
    cp.Free();
    const bool exists = ok && res.exists;
    res.Free();
    return exists ? 1 : 0;
}

// gpui 的 RemoveDirectory 就是 unlinkat(AT_REMOVEDIR)，非空目录会失败：recursive
// 只对 MakeDirectory 生效（filesystem_posix.cpp:359-371）。所以"递归删"要自己搭，
// 而且只能用同一套授权遍历——列目录、逐个删、最后删自己，不碰 ambient 路径。
static bool RemoveTree(const gpui::CapabilityPath& cp, const char* rel, int depth,
                       char* err, int err_cap) {
    if (depth > 32) {
        SetError(err, err_cap, "gpui 删除递归过深（>32 层）");
        return false;
    }
    gpui::shell::FsResult listing;
    gpui::Str lerr;
    if (!gpui::shell::FsRun(gpui::shell::FsOperation::ReadDirectory, cp.root,
                            gpui::Str(rel, (int)std::strlen(rel)), gpui::Str{}, false,
                            &listing, &lerr)) {
        // 空目录/不存在都到这里；把底层原因交回去。
        SetFsError(err, err_cap, lerr);
        listing.Free();
        return false;
    }
    const std::string base = rel;
    bool ok = true;
    for (int i = 0; ok && i < listing.entries.len; i++) {
        gpui::Str n = listing.entries.els[i].name;
        const bool isDir = listing.entries.els[i].isDirectory;
        std::string child = base.empty() ? std::string(n.s, (size_t)n.len)
                                         : base + "/" + std::string(n.s, (size_t)n.len);
        gpui::shell::FsResult drop;
        gpui::Str derr;
        if (isDir) {
            ok = RemoveTree(cp, child.c_str(), depth + 1, err, err_cap);
            continue;
        }
        ok = gpui::shell::FsRun(gpui::shell::FsOperation::RemoveFile, cp.root,
                               gpui::Str(child.data(), (int)child.size()), gpui::Str{},
                               false, &drop, &derr);
        if (!ok) SetFsError(err, err_cap, derr);
        drop.Free();
    }
    listing.Free();
    return ok;
}

int gpui_shim_fs_remove(const char* path, int dir, int recursive, char* err,
                        int err_cap) {
    gpui::CapabilityPath cp;
    if (!Resolve(path, gpui::CapabilityAccess::Write, &cp, err, err_cap)) return 0;
    std::string rel = cp.relative.s ? std::string(cp.relative.s, (size_t)cp.relative.len)
                                    : std::string();
    bool ok = true;
    if (dir && recursive) ok = RemoveTree(cp, rel.c_str(), 0, err, err_cap);
    gpui::shell::FsResult res;
    gpui::Str ferr;
    if (ok) {
        ok = gpui::shell::FsRun(
            dir ? gpui::shell::FsOperation::RemoveDirectory
                : gpui::shell::FsOperation::RemoveFile,
            cp.root, cp.relative, gpui::Str{}, false, &res, &ferr);
        if (!ok) SetFsError(err, err_cap, ferr);
    }
    res.Free();
    cp.Free();
    return ok ? 1 : 0;
}

int gpui_shim_fs_mkdir(const char* path, int dir, char* err, int err_cap) {
    gpui::CapabilityPath cp;
    if (!Resolve(path, gpui::CapabilityAccess::Write, &cp, err, err_cap)) return 0;
    gpui::shell::FsResult res;
    gpui::Str ferr;
    const bool ok = gpui::shell::FsRun(gpui::shell::FsOperation::MakeDirectory,
                                       cp.root, cp.relative, gpui::Str{},
                                       dir != 0, &res, &ferr);
    cp.Free();
    if (!ok) SetFsError(err, err_cap, ferr);
    res.Free();
    return ok ? 1 : 0;
}

void gpui_shim_out(const char** data, int* len) {
    if (data) *data = g_scratch.empty() ? nullptr : g_scratch.data();
    if (len) *len = (int)g_scratch.size();
}

int gpui_shim_process_run(const char* command, const char* const* args,
                          int count, int* out_code, char* err, int err_cap) {
    gpui::Str cmd = cstr(command);
    if (!g_caps.MayRun(cmd)) {
        gpui::CapabilityError ce;
        ce.kind = gpui::CapabilityErrorKind::ExecuteDenied;
        ce.subject = cmd;
        SetCapError(err, err_cap, ce);
        return 0;
    }
    std::vector<gpui::Str> list;
    for (int i = 0; i < count && args; i++) list.push_back(cstr(args[i]));
    gpui::shell::ProcessCancellation cancel;
    gpui::shell::ProcessOutput po;
    gpui::Str perr;
    const bool ok = gpui::shell::ProcessRunBounded(
        cmd, list.empty() ? nullptr : list.data(), (int)list.size(), &cancel, &po,
        &perr);
    if (!ok) {
        SetFsError(err, err_cap, perr);
        po.Free();
        return 0;
    }
    g_scratch.assign(po.out.s ? po.out.s : "", (size_t)(po.out.len > 0 ? po.out.len : 0));
    g_scratchErr.assign(po.err.s ? po.err.s : "", (size_t)(po.err.len > 0 ? po.err.len : 0));
    if (out_code) *out_code = po.code;
    po.Free();
    return 1;
}

void gpui_shim_process_err(const char** data, int* len) {
    if (data) *data = g_scratchErr.empty() ? nullptr : g_scratchErr.data();
    if (len) *len = (int)g_scratchErr.size();
}

int gpui_shim_storage_set(const char* key, const char* data, int len, char* err,
                          int err_cap) {
    if (!g_caps.HasStorage()) {
        gpui::CapabilityError ce;
        ce.kind = gpui::CapabilityErrorKind::StorageDenied;
        ce.subject = cstr(key);
        SetCapError(err, err_cap, ce);
        return 0;
    }
    gpui::Str serr;
    gpui::shell::Storage* st = StorageNow();
    if (!st->Set(cstr(key), gpui::Str(data ? data : "", len > 0 ? len : 0), &serr)) {
        SetFsError(err, err_cap, serr);
        return 0;
    }
    // 落盘是 BeginWrite → StoragePersist → FinishWrite 三步（runtime.cpp:1190 的
    // DriveStorage 就是这一串）。不 persist 的话 Set 只改内存。
    gpui::shell::StorageWrite w;
    gpui::Str berr;
    if (st->BeginWrite(&w, &berr) && w.revision) {
        gpui::Str perr;
        const bool ok = gpui::shell::StoragePersist(w, &perr);
        // 我们从不注册 waiter，所以 ready 列表传空即可。
        st->FinishWrite(w.revision, ok, nullptr);
        w.Free();
        if (!ok) SetFsError(err, err_cap, perr);
    } else {
        SetFsError(err, err_cap, berr);
        w.Free();
    }
    return 1;
}

int gpui_shim_storage_get(const char* key) {
    if (!g_caps.HasStorage()) return 0;
    gpui::Str v = StorageNow()->Get(cstr(key));
    if (!v.s) return 0;
    g_scratch.assign(v.s, (size_t)(v.len > 0 ? v.len : 0));
    return 1;
}

int gpui_shim_storage_remove(const char* key, char* err, int err_cap) {
    if (!g_caps.HasStorage()) return 0;
    gpui::Str serr;
    const bool ok = StorageNow()->Remove(cstr(key), &serr);
    if (!ok) SetFsError(err, err_cap, serr);
    return ok ? 1 : 0;
}

int gpui_shim_storage_len(void) {
    return g_caps.HasStorage() ? StorageNow()->Len() : 0;
}


// ── macOS 系统集成：转发到 gpui_mac.mm ────────────────────────────────────────
//
// 这一层只做两件事：把 win 变成 owner 指针交出去，以及把 labels 的 C 数组转成
// NSString 数组要的形状。非 mac 上 .mm 整个 #ifdef 掉了，所以这里给空实现，
// 桥那边照样能链。

#ifdef __APPLE__
int gpui_shim_tray_add(void* win, const char* elem_id, const char* tooltip,
                       const char* const* labels, int label_count) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !elem_id || !*elem_id) return 0;
    return gpui_mac_tray_add(box, tooltip, labels, label_count, elem_id);
}
void gpui_shim_tray_remove(void* win, int tray_id) {
    (void)win;
    gpui_mac_tray_remove(tray_id);
}
int gpui_shim_tray_count(void) { return gpui_mac_tray_count(); }
int gpui_shim_hotkey_register(void* win, const char* elem_id, unsigned keycode,
                              unsigned mods) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !elem_id || !*elem_id) return 0;
    return gpui_mac_hotkey_register(box, static_cast<uint32_t>(keycode),
                                    static_cast<uint32_t>(mods), elem_id);
}
void gpui_shim_hotkey_unregister(int hotkey_id) {
    gpui_mac_hotkey_unregister(hotkey_id);
}
int gpui_shim_hotkey_count(void) { return gpui_mac_hotkey_count(); }
int gpui_shim_hotkey_fire(int index) { return gpui_mac_hotkey_fire(index); }
void gpui_shim_dock_badge(const char* text) { gpui_mac_dock_badge(text); }
int gpui_shim_os_notify(const char* title, const char* body) {
    return gpui_mac_os_notify(title, body);
}
#else
int gpui_shim_tray_add(void*, const char*, const char*, const char* const*, int) { return 0; }
void gpui_shim_tray_remove(void*, int) {}
int gpui_shim_tray_count(void) { return 0; }
int gpui_shim_hotkey_register(void*, const char*, unsigned, unsigned) { return 0; }
void gpui_shim_hotkey_unregister(int) {}
int gpui_shim_hotkey_count(void) { return 0; }
int gpui_shim_hotkey_fire(int) { return 0; }
void gpui_shim_dock_badge(const char*) {}
int gpui_shim_os_notify(const char*, const char*) { return 0; }
#endif

