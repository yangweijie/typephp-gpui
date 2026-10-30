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
// cmd/patch-select-view.sh 的两个弱符号强实现（部件 binding 里存的那批回调）。
void gpuiEnqueueSignal(ScriptView*, Ctx*, uint64_t);
void gpuiEnqueueString(ScriptView*, Ctx*, uint64_t, const char*);
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

// 活着的 Box2 个数（进程级）。overlay 回收类修复必须能被量到：`dialogs` 那条读的是
// vector 长度，而 pop 在调用方做，回收与否它都是 0——只有"包装还活着几个"
// 才分得开"回收了"与"漏了"。
static int g_boxAlive = 0;

// 一个窗口的全部状态。对 bridge 是 opaque void*。
struct WebViewView;  // 定义在 PhpView 之后；Box2 只存它的指针。
struct WatchCtx;     // 同上：目录监听的一条 watch 上下文。
class Box2;

struct HostDockPanel {
    Box2* box = nullptr;
    std::unique_ptr<gpui::shell::SpecArena> specs;
    std::unique_ptr<gpui::shell::SpecArena> pending;
    gpui::shell::SpecId root = 0;
    std::string name;
    std::string title;
    std::string pendingTitle;
    int index = -1;
};

struct HostDock {
    std::string id;
    uint64_t handle = 0;
    std::unordered_map<std::string, std::unique_ptr<HostDockPanel>> panels;
};

class Box2 {
  public:
    Box2() { g_boxAlive++; }
    ~Box2() { g_boxAlive--; }

  public:
    gpui::Window* window = nullptr;
    void* viewPtr = nullptr;        // PhpView*，g_views 的键
    gpui::EntityId viewEntity = {};
    gpui::EntityId shellRoot = {};  // 窗口根：包 viewEntity，拥有 dialog/sheet/toast overlay
    gpui::shell::SpecArena specs;   // 常驻：本窗口当前描述
    // 正在被描述的那一块 arena。平时就是 specs；虚拟列表在 materialize **途中**向 PHP
    // 要一批行时指向一块临时 arena——往 specs 里追加会让 nodes 扩容，而 materialize
    // 正拿着那些节点的指针（见 gpuiRenderShimItems）。所有"建树"入口都经 arena() 取。
    gpui::shell::SpecArena* building = nullptr;
    gpui::shell::SpecArena& arena() { return building ? *building : specs; }
    std::unordered_map<std::string, std::unique_ptr<HostDock>> docks;
    HostDockPanel* buildingDockPanel = nullptr;
    // 宿主渲染的虚拟列表仪表：向 PHP 要过几批、一共要了几行（"真惰性"只能这样证明）。
    long long listCalls = 0;
    long long listItems = 0;
    gpui::shell::SpecId rootId = 0;
    uint64_t nextCallback = 1;

    // callbackId → (elementId, eventType)
    std::unordered_map<uint64_t, std::pair<std::string, std::string>> callbacks;
    // "elemId\x1ftype" → callbackId：newCallback 幂等复用（每帧重建同结构不增长）
    std::unordered_map<std::string, uint64_t> cbByKey;
    std::string scratchKey;   // newCallback 的复用缓冲（见其注释）
    std::string scratchOpName; // 回调 op 名的复用缓冲
    // "elemId\x1ftype" → runtime CallbackId（catalog 组件回调旁路，自检直接触发用）
    std::unordered_map<std::string, uint64_t> shimCallbacks;
    // "stateKind\x1fkey" → catalog 保留态实体的 handle（gpui_shim_state）。
    // 必须缓存：PHP 每帧重建同一棵树，不缓存就是每帧往 componentStates 里塞一颗新实体。
    std::unordered_map<std::string, uint64_t> stateHandles;
    // 最近一次 registered 构造/方法失败的原因（每帧 render_begin 清空，供 gpui_last_error 回传）
    std::string lastError;
    // 这一帧已经接进 lastError 的"第二条及以后"有几条（见 noteError，上限防撑爆）。
    int extraErrors = 0;
    // elementId → 最近一次上报的值（App::checked()/elementValue() 的读源，见 rememberValue）。
    // 只在事件里带值时写，所以条目数 ≤ 声明过回调的元素数，不随帧数增长。
    struct ElemValue {
        int vkind = GPUI_ARG_NIL;
        double vnum = 0;
        int vbool = 0;
        std::string vstr;
    };
    std::unordered_map<std::string, ElemValue> lastValues;
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
    // 当前 sheet 的内容 Box2（上游只允许一个：OpenSheet 是替换不是叠放）。
    Box2* sheet = nullptr;
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
    // 每个回调 op 每帧都要过这里，所以键不新建 string：拼进复用的 scratchKey
    // （capacity 跨帧保留，稳态零分配）。返回后 callbacks/cbByKey 才各自存自己的副本，
    // 而那两本表按 (id,type) 幂等，只在**第一次**见到这个组合时才写。
    uint64_t newCallback(const char* elementId, const char* type) {
        scratchKey.assign(elementId ? elementId : "");
        const size_t elemLen = scratchKey.size();   // 分隔符之前的长度，别倒推
        scratchKey.push_back('\x1f');
        scratchKey.append(type ? type : "");
        auto it = cbByKey.find(scratchKey);
        if (it != cbByKey.end()) return it->second;
        uint64_t id = nextCallback++;
        callbacks[id] = {scratchKey.substr(0, elemLen), type ? type : ""};
        cbByKey[scratchKey] = id;
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
        // 不认识这个 callbackId 就**不入队**。以前是照入一条 type/id 全空的记录，
        // 于是 `App::onAny()` 的通配 handler 会收到一条"哪个元素都不属于"的事件——
        // 而 cmd/patch-select-view.sh 之后走这条路的多了一批"合法但本窗口没人监听"的 id
        // （popover 开合、bound_bool、dock 布局），空事件只会越来越多，不会自己消失。
        // 值记忆也留在命中分支里：没主的 id 不该污染 elementValue() 的读源。
        if (it == callbacks.end()) return;
        GpuiEvent ev{};
        std::snprintf(ev.type, sizeof(ev.type), "%s", it->second.second.c_str());
        std::snprintf(ev.id, sizeof(ev.id), "%s", it->second.first.c_str());
        rememberValue(it->second.first.c_str(), vkind, vnum, vbool, vstr);
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
        rememberValue(elemId, vkind, vnum, vbool, vstr);
        queue.push_back(ev);
    }

    // App::checked()/elementValue() 的读源：gpui 把 checkbox/switch/滑块的新值只往事件里
    // 放一份，宿主侧没有可查的原生状态，所以这里留一份"最近一次上报的值"。
    // 语义必须是"最近一次 change 报的"，不是"原生此刻的"——从未上报过就是 nil。
    void rememberValue(const char* elemId, int vkind, double vnum, int vbool,
                       const char* vstr) {
        if (!elemId || !*elemId || vkind == GPUI_ARG_NIL) return;
        ElemValue& v = lastValues[elemId];
        v.vkind = vkind;
        v.vnum = vnum;
        v.vbool = vbool;
        v.vstr = vstr ? vstr : "";
    }
};

// self(view void*) → box，供 patched view.cpp 的事件 handler 反查。
std::unordered_map<void*, Box2*> g_views;

// 回收一个"视图已经没了"的 overlay 包装（dialog / sheet 的内容 Box2）。
// 依据是上游三条路径都同步 drop：CloseTopDialog 与 OpenSheet 换面时
// `EntityDrop(app, layer)`（root.cpp:330 / 494），而 ~ShellDialogLayer /
// ~ShellSheetLayer 里 `EntityDrop(app, content)`（root.cpp:209 / 398），
// content 的 deleter 就是我们 EntityNewRaw 注册的那个 `delete PhpView`。
// 所以调用方在 close/open 返回之后回收是安全的；不回收才是问题——
// g_views 里那条键指向已释放的 PhpView，地址被新视图复用后 boxForView 会反查到
// 这个没人引用的 Box2。
static void retireOverlay(Box2* dlg);

static void retireOverlay(Box2* dlg) {
    if (!dlg) return;
    if (dlg->viewPtr) g_views.erase(dlg->viewPtr);
    delete dlg;
}

// App 级动作处理器要用的一张反查表：`ActionFn` 只给 Window* 与 ActionEvent*，
// 没有 view 可以走 boxForView，所以窗口指针得自己记下对应的 Box2。
std::unordered_map<gpui::Window*, Box2*> g_windowBoxes;

// 原生菜单行 → PHP 的 `action` 事件。链路：NSMenuItem target/action →
// `gpuiAppMenuItemChosen:` → `AppMenuChosen(tag)` → `AppMenuRowForId` 取回 action id →
// 派发到 active window → 焦点链没人接 → 这里。事件 id 就是 MenuItem 写的那个 action 名
// （`ShellActionScriptId` 反查回来），所以 PHP 侧 `$app->on('cr-m-new', 'action', fn)`
// 与按钮点击是同一套写法。
static void gpuiAppActionHandler(gpui::Window* win, gpui::ActionEvent* ev) {
    if (!win || !ev) return;
    auto it = g_windowBoxes.find(win);
    if (it == g_windowBoxes.end() || !it->second) return;
    gpui::Str name = gpui::shell::ShellActionScriptId(ev->action);
    if (!name.s || name.len <= 0) return;
    std::string id(name.s, name.len);
    it->second->enqueueRaw("action", id.c_str(), GPUI_ARG_NIL, 0, 0, nullptr);
}

// 上游那张 app 动作表是定长的（gpui.cpp `kMaxAppActions`），塞满后 `AppOnAction`
// 静默返回——那一行菜单点了就没有任何回音。这里自己数着去重后的条数，超预算就把原因
// 写进 lastError，让它成为可观测的失败而不是无声丢事件。
static const int kShimMenuActionBudget = 28;
static std::vector<std::string> gMenuActionIds;

static void registerMenuAction(Box2* box, gpui::Str actionName) {
    if (!actionName.s || actionName.len <= 0) return;
    std::string name(actionName.s, actionName.len);
    for (size_t i = 0; i < gMenuActionIds.size(); i++) {
        if (gMenuActionIds[i] == name) return;
    }
    if ((int)gMenuActionIds.size() >= kShimMenuActionBudget) {
        if (box) {
            box->lastError = "菜单动作注册表已满（" +
                             std::to_string(kShimMenuActionBudget) + " 条），'" + name +
                             "' 的动作不会回到 PHP";
        }
        return;
    }
    uint32_t id = gpui::shell::ShellActionOf(actionName);
    if (!id) return;
    gpui::AppOnAction(id, &gpuiAppActionHandler);
    gMenuActionIds.push_back(name);
}

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
static int gpui_mac_send_mouse(double, double, int, int) { return 0; }
static int gpui_mac_send_scroll(double, double, int, int) { return 0; }
static int gpui_mac_send_key(unsigned short, const char*) { return 0; }
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
        // 借用：唯一两个调用点都是往 PushOp 里塞，而 CopyOp 会 StrDup 字符串实参
        // （spec.cpp:324-333）。arena 形参留着是为了签名不变，别以为这里还在拷。
        case GPUI_ARG_STR: return B::String(cppStr(a.str));
        default: return B::Nil();
    }
}

using K = gpui::shell::ComponentKind;   // 表与函数共用这个别名（原先只在函数里）

// kind 名 → 上游枚举。放在函数外面（而不是函数内 static），是为了 shim_test 能拿它
// 做"每个 ComponentKind 都得有名字"的漂移警报；这里也是 gpui_shim_push 唯一的词法表。
// 注意 div 必须在册：它过去只靠兜底分支存在，"最常见的 kind"与"拼错的名字"因此同形。
struct KindName {
    const char* name;
    gpui::shell::ComponentKind kind;
};
static const KindName kKindNames[] = {
    {"div", K::Div},
    {"h_flex", K::HFlex},
    {"v_flex", K::VFlex},
    {"text", K::Text},
    {"text_view", K::TextView},
    {"button", K::Button},
    {"link", K::Link},
    {"checkbox", K::Checkbox},
    {"switch", K::Switch},
    {"input", K::Input},
    {"textarea", K::Textarea},
    {"number_input", K::NumberInput},
    {"otp_input", K::OtpInput},
    {"slider", K::Slider},
    {"progress", K::Progress},
    {"image", K::Image},
    {"table", K::Table},
    {"table_row", K::TableRow},
    {"table_cell", K::TableCell},
    {"list", K::List},
    {"uniform_list", K::UniformList},
    {"tabs", K::Tabs},
    {"tab", K::Tab},
    {"scrollbar", K::Scrollbar},
    {"svg", K::Svg},
    {"radio", K::Radio},
    {"toggle", K::Toggle},
    {"radio_group", K::RadioGroup},
    {"toggle_group", K::ToggleGroup},
    {"collapsible", K::Collapsible},
    {"popover", K::Popover},
    {"hover_card", K::HoverCard},
    {"popup", K::Popup},
    {"avatar", K::Avatar},
    {"accordion_item", K::AccordionItem},
    // 手风琴根（materialize.cpp:1831 的 kind 分支；base/accordion.cpp:5-8 的本体是
    // Div+Id+Role(Group)——价值在把 accordionItem 三段件合法挂树）。没有这条登记名，
    // El::accordion() 的 kind 在 kindFromString 不认、gpui_shim_push 直接拒建节点。
    {"accordion", K::Accordion},
    // 批5：路径绘制（PaintMaterialPath 消费 move_to/line_to/… 六 op 与
    // component.background/strokeWidth——kind 分支 materialize.cpp:2431 挂
    // customPaint）。不登记则 gpui_shim_push 拒建节点。
    {"path_fill", K::PathFill},
    {"path_stroke", K::PathStroke},
    // 手风琴三件（materialize.cpp:2548/2571/2588 的 kind 分支在上游）：没有这三条
    // 登记名，El::accordionItem 的 header/trigger/panel 部件造不出来（kindFromString
    // 不认就静默降级），折叠头永远是一颗空按钮。
    {"accordion_header", K::AccordionHeader},
    {"accordion_trigger", K::AccordionTrigger},
    {"accordion_panel", K::AccordionPanel},
    {"table_header", K::TableHeader},
    {"table_body", K::TableBody},
    {"table_head", K::TableHead},
    {"table_caption", K::TableCaption},
    {"progress_track", K::ProgressTrack},
    {"progress_indicator", K::ProgressIndicator},
    {"h_resizable", K::HResizable},
    {"v_resizable", K::VResizable},
    {"resizable_panel", K::ResizablePanel},
    {"select", K::Select},
    {"combobox", K::Combobox},
    {"date_picker", K::DatePicker},
};

gpui::shell::ComponentKind kindFromString(const char* k, bool* known = nullptr) {
    using K = gpui::shell::ComponentKind;
    if (known) *known = true;
    if (!k) {
        if (known) *known = false;  // 空 kind 也不算「合法的一种」，别让它静默变 Div
        return K::Div;
    }
    // 表查而不是 `std::string s(k)` + 至多 46 次串比较：这条跑在每个元素每帧上。
    for (const KindName& e : kKindNames) {
        if (std::strcmp(e.name, k) == 0) return e.kind;
    }
    // 认不出的 kind 不再静默兜成 Div：标成 unknown，由 gpui_shim_push 拒绝建节点
    // 并写 lastError。兜底分支把"最常见的值"和"拼错的名字"抹成同一形状，四层都不报错。
    if (known) *known = false;
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
            // 只收 GPUI_ARG_ENTITY：把"一个普通数字"当成 state handle 交给上游，
            // 换来的是 `Failed to render X` 这种要到渲染后才看得见的错。这里先拦住。
            if (ga.kind != GPUI_ARG_ENTITY) {
                *err = "expected a catalog state handle (El::state(...))";
                return false;
            }
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

// 一帧里的多条错：**接上去**而不是盖掉。#84 的现场是"state 工厂拒了 → 那颗组件的实参
// 跟着拒"——两条错同一帧，盖掉之后读数里只剩后果（"Diff: at argument 'state'"），
// 真正的原因（补丁文本没解析过）一个字都看不见。上限是防画廊那种一帧几十错的页面把
// 回传缓冲撑爆：第一条永远在，后面最多再接两条、总长不过 ~600 字节。
void noteError(Box2* box, const std::string& msg) {
    if (msg.empty()) return;
    if (box->lastError.empty()) {
        box->lastError = msg;
        return;
    }
    if (box->extraErrors >= 2 || box->lastError.size() > 600) return;
    box->extraErrors++;
    box->lastError += " | " + msg;
}

// registered 失败时的可见占位：一个 Text 节点承载错误文本（on-screen + debug_tree 都可见）。
// 同时把原因记进 box->lastError，供 gpui_last_error 回传 PHP 断言。
uint32_t pushErrorNode(Box2* box, gpui::Arena* a, const std::string& msg) {
    noteError(box, msg);
    gpui::shell::Component c;
    c.kind = gpui::shell::ComponentKind::Text;
    c.text = AStr(a, msg.c_str());
    return box->arena().Push(c);
}

// 每帧的 webview 可见性收放：materialize 前清零"这帧渲染到了"，materialize 后据此
// 把不在树上的那颗隐藏掉。定义在 WebViewView 之后，这里先给个前向声明。
static void WebViewFrameVisibility(gpui::Ctx* cx, Box2* box, bool beforeMaterialize);

// Root content 实体：帧渲染时把 box->specs materialize 成原生 El 树。
struct PhpView {
    Box2* box;

    static gpui::El* Render(void* self, gpui::Ctx* cx) {
        PhpView* view = static_cast<PhpView*>(self);
        if (!view || !view->box) return gpui::Div(cx->a);
        if (view->box->rootId == 0 && view->box->specs.IsEmpty()) {
            return gpui::Div(cx->a);
        }
        WebViewFrameVisibility(cx, view->box, true);
        gpui::El* el = gpui::ShellMaterializeSpec(cx, g_runtime, &view->box->specs,
                                                  view->box->rootId);
        WebViewFrameVisibility(cx, view->box, false);
        return el;
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
    // 这一帧有没有被渲染到。ChildView 只在元素还在树上时才会走到这里，所以"没轮到"
    // 就意味着那颗原生控件该从鼠标路径上让开——见 WebViewFrameVisibility。
    bool seen = false;
    gpui::Entity<gpui::WebView> web = {};

    static gpui::El* Render(void* self, gpui::Ctx* cx) {
        WebViewView* v = static_cast<WebViewView*>(self);
        if (!v || !cx || !cx->a) return nullptr;
        v->seen = true;
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

// 每帧调两次：materialize 之前把这一帧的"渲染到了"清零，之后据此收放可见性。
//
// 为什么必须收：ChildView 只在元素还在树上时才会被 EntityRender，而它背后那颗
// WKWebView 是**真的 NSView**，建好就一直挂在窗口的父视图上。元素换掉之后它停在最后
// 一次的矩形里，AppKit 的 hitTest 会先命中它——那块区域下面的 gpui 元素从此一个真实
// 鼠标都收不到（实测：渲染过带 WebView 的那一节再切走，侧栏上面 6 行点不动，而 gpui 的
// 命中表里那 6 条矩形、监听器全都在，看起来像"点击丢了"）。hidden=YES 的视图 AppKit 会
// 直接跳过，所以让开鼠标只要隐藏，不用摘下来。
static void WebViewFrameVisibility(gpui::Ctx* cx, Box2* box, bool beforeMaterialize) {
    if (!box || !cx) return;
    for (auto& kv : box->webviews) {
        WebViewView* v = kv.second.view;
        if (!v) continue;
        if (beforeMaterialize) {
            v->seen = false;
            continue;
        }
        if (!v->started || !v->web.id.IsValid()) continue;
        gpui::WebView* w = v->web.Get(cx->app);
        if (!w) continue;
        if (wry::WebView* raw = gpui::WebViewRaw(w)) {
            // 这帧没渲染到就 hidden=YES：AppKit 会跳过隐藏视图，鼠标才轮得到下面的 gpui 元素。
            wry::WebViewSetVisible(raw, v->seen);
        }
    }
}

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

// DataTable 构建探针的进程级读数（见 ensureApp 里的安装注释）：
// 每次成功的 cell 构建计一次 built，每次失败（rows 快照非数组 / cell 渲染失败）计一次
// failed。门读增量，不读绝对值。
static long long g_dtProbeBuilt = 0;
static long long g_dtProbeFailed = 0;

static void DataTableProbeTick(bool built) {
    if (built) g_dtProbeBuilt++;
    else g_dtProbeFailed++;
}

void ensureApp() {
    if (g_app) return;
    // 先转前台应用再建窗口：非 bundle 的二进制不转的话激活会被拒，
    // 真实鼠标/键盘就永远到不到元素（见 gpui_mac.h 的 gpui_mac_become_foreground）。
    gpui_mac_become_foreground();
    g_app = gpui::AppNew();
    // 再转一次：AppNew→PlatInit 里才 [NSApplication sharedApplication]，
    // 上一次的 setActivationPolicy:/activateIgnoringOtherApps: 打给了还不存在的 NSApp。
    gpui_mac_become_foreground();
    // mac 集成的事件出口：owner 是注册时传进去的 Box2*，回调时原样带回来，所以多窗口
    // 各回各的队列。只装一次。
    gpui_mac_set_sink(&MacEventSink);
    // DataTable 的构建探针（上游 families.h 给适配器的测试缝）：DataTable 的失败
    // 不走 request->Fail，而是它自己的 Failure()——返回一颗带错误文本的 Div，
    // component-failure 账本与 registeredError 都收不到。进程级计数经
    // gpui_shim_state_counts 的 dataTableBuilds/dataTableFails 两位读出，
    // examples/kit 的 dataTableRowsCallbackLoud 门据此断"失败看得见"。
    gpui::component_shell::SetDataTableProbe(&DataTableProbeTick);
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

// 这两个 helper 定义在本块后面（overlayCtx 造一个 overlay 调用用的 Ctx；syncOverlays
// 把我们的包装与上游真实状态对齐）。声明必须留在 extern "C" 里面：放在外面的 C++
// 作用域会得到另一个同名不同链接性的实体，Clang 报 "call is ambiguous"。
static gpui::Ctx overlayCtx(Box2* box);
static void syncOverlays(Box2* box);

namespace gpui {
// 定义在文件后半段（HostOnResize 那一段）；app_init 在它们前面，所以先声明。
extern void (*HostResizeHook)(void*, Ctx*, const ResizablePanelEvent*, int64_t);
void HostOnResize(void*, Ctx*, const ResizablePanelEvent*, int64_t);
}

void* gpui_shim_app_init(void) {
    ensureApp();
    // #94：把 materialize.cpp 里的空钩子装上宿主实现（story 不链本 shim，保持原路）。
    gpui::HostResizeHook = &gpui::HostOnResize;
    return nullptr;
}

// 宿主视图的"应用身份"注册：没有它，带 component app effects 的组件（MenuBar）
// 每帧 materialize 失败，画面上渲染成 "Failed to render MenuBar"。
//
// 整个宿主只用**一个**身份令牌：组件 materialize 时的 cx->self 会在 PhpView 与
// ShellRoot 之间换人，两个身份就成了两条 app-effect 世代，各自装卸同一份 OwnedMenus。
//
// 同一枚令牌也是 catalog 保留态实体的 owner（`gpui_shim_state` 把它交给
// `ShellRuntime::NewHostState`）——`ComponentStateStore::ReleaseApplication` 按 owner
// 回收，宿主只有一枚就意味着这些实体会跟着应用一起放掉；再造第二枚等于把
// "每帧无界增长"那一族的复发路径留回来。
static void* hostAppToken() {
    static std::unique_ptr<char[]> token = std::make_unique<char[]>(1);
    return token.get();
}

static void registerHostApplication(gpui::EntityId entity) {
    if (!g_runtime) return;
    g_runtime->SetHostApplicationFallback(hostAppToken());
    if (entity.IsValid()) g_runtime->RegisterHostApplication(entity, hostAppToken());
}

namespace gpui {
// 由 materialize.cpp（我们的补丁）定义的空钩子：没链本 shim 的 story 里它保持 null，
// 自动退回 ScriptView 路径；本 shim 在 app_init 时装上宿主版（弱符号方案在 Darwin 上
// 不产生 weak reference，链接会报 undefined —— #94 实测踩过两次）。
extern void (*HostResizeHook)(void*, Ctx*, const ResizablePanelEvent*, int64_t);
/* #94：宿主视图的 on_resize 出口。`self` 是 `cx->self`，宿主实体注册的是一个
 * `::PhpView*`（8 字节的 Box2 指针）。载荷沿用项目既有形状：type=resize、value=CSV。 */
void HostOnResize(void* self, Ctx*, const ResizablePanelEvent* ev, int64_t cb) {
    auto* view = static_cast<::PhpView*>(self);
    if (std::getenv("GPUI_RESIZE_DEBUG")) {
        std::fprintf(stderr, "GPUI-RESIZE host cb=%lld view=%p count=%d\n",
                     (long long) cb, (void*) view, ev ? ev->count : -1);
    }
    if (!view || !view->box || !ev || !ev->sizes || ev->count <= 0) return;
    auto it = view->box->callbacks.find((uint64_t) cb);
    if (it == view->box->callbacks.end()) {
        if (std::getenv("GPUI_RESIZE_DEBUG")) std::fprintf(stderr, "GPUI-RESIZE host cb-not-found\n");
        return;
    }
    char csv[160];
    int used = 0;
    csv[0] = '\0';
    for (int i = 0; i < ev->count && used < (int) sizeof(csv) - 1; i++) {
        used += std::snprintf(csv + used, (size_t)((int) sizeof(csv) - used),
                              i == 0 ? "%.0f" : ",%.0f", (double) ev->sizes[i]);
        if (used >= (int) sizeof(csv) - 1) break;
    }
    view->box->enqueueRaw("resize", it->second.first.c_str(), GPUI_ARG_STR, 0, 0, csv);
    if (std::getenv("GPUI_RESIZE_DEBUG")) {
        std::fprintf(stderr, "GPUI-RESIZE host id=%s csv=%s\n",
                     it->second.first.c_str(), csv);
    }
}
}  // namespace gpui

void* gpui_shim_open_window(const char* title, int width, int height,
                               int borderless, int client_title_bar) {
    ensureApp();
    auto* box = new Box2();

    auto* view = new PhpView{box};
    box->viewPtr = view;
    box->viewEntity =
        gpui::EntityNewRaw(g_app, view, (gpui::RenderFn)&PhpView::Render,
                           [](void* p) { delete static_cast<PhpView*>(p); });
    g_views[view] = box;
    registerHostApplication(box->viewEntity);

    // 窗口根换成 ShellRoot：它渲染我们的 PhpView content，并托管 dialog/sheet/toast。
    // 用 WindowOpenView 直接把 ShellRoot 作窗口根（KitOpenWindow 会再包一层 Base Root，
    // 令 ShellRootOf 的 window->root==state->root 判定失败）。
    auto root = gpui::ShellRoot::New(g_app, box->viewEntity);
    box->shellRoot = root.id;
    registerHostApplication(box->shellRoot);

    gpui::WinOpts opts = {};
    // 批5（TitleBar B 期）：borderless + clientTitleBar 从 PHP 侧带进来——
    // clientTitleBar 时顶部 34px 是 caption 带（双击最大化，window_common.cpp:2526），
    // macOS 的系统红绿灯仍由 Cocoa 画在透明标题栏上。
    opts.borderless = borderless != 0;
    opts.clientTitleBar = client_title_bar != 0;
    // 建窗口之前再激活一次：OrderFrontOnce 只在首次 Redraw 里跑，而那时激活可能仍被
    // LaunchServices 拒（非前台进程），拒了就没有钥匙窗口，鼠标键盘全丢。
    gpui_mac_become_foreground();
    box->window = gpui::WindowOpenView(g_app, cppStr(title ? title : ""), width, height,
                                       box->shellRoot, opts);
    if (!box->window) {
        g_views.erase(view);
        // 只 drop 根，一条链自己走完：ShellRoot 的析构会 EntityDrop(content)
        // （root.cpp:773），而 content 的 deleter 又 delete PhpView（下面 EntityNewRaw
        // 注册的那个 lambda）。原先这里既 EntityDrop(viewEntity) 又 delete view，
        // 同一块 PhpView 删两次；shellRoot 反倒从没放过。
        if (box->shellRoot.IsValid()) gpui::EntityDrop(g_app, box->shellRoot);
        delete box;
        return nullptr;
    }
    // App 级动作回调只拿得到 Window*，反查 Box2 靠这张表，所以必须在这里登记。
    g_windowBoxes[box->window] = box;
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
    // 先释放 Dock 状态，再释放面板描述；状态的 render/data 仍引用这些描述。
    for (auto& dock : box->docks) g_runtime->ReleaseHostDock(dock.second->handle);
    box->docks.clear();
    if (box->window) {
        g_windowBoxes.erase(box->window);  // Window* 会被后面的窗口复用，留着就是野指针
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
    // 走到这里 AppClose 已经拆完 ShellRoot：所有还开着的 dialog/sheet 层与其内容
    // PhpView 都释放了，只剩我们的包装与 g_views 里的键。
    for (Box2* dlg : box->dialogs) retireOverlay(dlg);
    box->dialogs.clear();
    retireOverlay(box->sheet);
    box->sheet = nullptr;
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
        box->extraErrors = 0;    // 配套 noteError 的"本帧已接了几条"计数
    }
}

void gpui_shim_remember_checked(void* win, const char* elemId, int vbool) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !elemId || !*elemId) return;
    // 语义与 enqueue 里的 rememberValue 一致：记"最近一次已知值"。差异只在更新点
    // ——这里是 render push（specs 落地即更新，不等原生 draw），所以 PHP 侧改状态
    // 重渲染后下一击翻转的基准就是新的。见 gpui_shim.h 的注释。
    box->rememberValue(elemId, GPUI_ARG_BOOL, 0, vbool ? 1 : 0, nullptr);
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

// 读回某元素最近一次上报的值。返回 GpuiArgKind（GPUI_ARG_NIL = 没上报过 / 窗口不可用）；
// 按 kind 只填对应的那个出参，其余保持不动。
// 各容器的条数：内存增长类修复的仪表（没有它，"重挂定时器不涨表"这类话就只能靠读码相信）。
// 顺序必须与 bridge 里的名字表一致，所以这里一次性列全。
int gpui_shim_state_counts(void* win, long long* out, int cap) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !out || cap < 1) return 0;
    // 先对齐再报数：不然仪表会报一个"下一步就被轮询纠正"的假状态，读它的人只会困惑。
    syncOverlays(box);
    const size_t all[] = {
        box->timerKeys.size(),      // 0
        box->timerIds.size(),       // 1
        box->callbacks.size(),      // 2
        box->cbByKey.size(),        // 3
        box->shimCallbacks.size(),  // 4
        box->inputHandles.size(),   // 5
        box->lastValues.size(),     // 6
        box->webviews.size(),       // 7
        box->inspectors.size(),     // 8
        box->notifyLists.size(),    // 9
        box->dialogs.size(),        // 10
        box->watchCtx.size(),       // 11
        box->httpResponses.size(),  // 12
        (size_t)(g_boxAlive < 0 ? 0 : g_boxAlive),  // 13 进程级：活着的 Box2
        g_views.size(),                             // 14 进程级：g_views 键数
        (size_t)box->listCalls,     // 15 宿主渲染虚拟列表：要过几批
        (size_t)box->listItems,     // 16 一共渲染了几行
        // 17 进程级：上游保留态实体表的长度。#81 起宿主也能造实体（脚本域外），
        // "每帧复用同一颗"与"每帧漏一颗"只有这个长度能分开——我方 stateHandles 缓存
        // 的条数分不开，因为漏的时候是按同一个 key 覆盖。
        (size_t)(g_runtime ? g_runtime->ComponentStateCount() : 0),
        box->docks.size(),
        [&]() { size_t n = 0; for (const auto& dock : box->docks) n += dock.second->panels.size(); return n; }(),
        // 20/21：DataTable 构建探针（进程级，见 DataTableProbeTick）。
        (size_t)g_dtProbeBuilt,
        (size_t)g_dtProbeFailed,
    };
    int n = (int)(sizeof(all) / sizeof(all[0]));
    if (n > cap) n = cap;
    for (int i = 0; i < n; i++) out[i] = (long long)all[i];
    return n;
}

/* 批4：给 retained 输入造一颗 token。链路：inputHandles[id] → Retained →
 * e->input（RetainedKind::Input 的 InputState*，与 setInput/text 同一条取路）
 * → InputReplaceWithToken（base/input.cpp:5780，在当前选区上替换成 token）。
 * label 可空。InlineToken 的 id/text 必填（shell/input_tokens.cpp DecodeToken
 * 同款形状），label 是 chip 上显示的可选短文本。 */
int gpui_shim_input_replace_token(void* win, const char* id, const char* token_id,
                                  const char* text, const char* label) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !id || !*id || !token_id || !*token_id || !text) return 0;
    auto it = box->inputHandles.find(id);
    if (it == box->inputHandles.end()) return 0;
    auto* e = g_runtime->Retained((gpui::shell::EntityHandle)it->second);
    if (!e || e->kind != gpui::shell::RetainedKind::Input || !e->input) return 0;
    gpui::InlineToken token = gpui::InlineToken::New(gpui::Str(token_id),
                                                     gpui::Str(text));
    if (label && *label) token = token.WithLabel(gpui::Str(label));
    gpui::InlineTokenError err =
        gpui::InputReplaceWithToken(e->input, g_app, box->window, token);
    return err == gpui::InlineTokenError::Ok ? 1 : 0;
}

int gpui_shim_element_value(void* win, const char* id, double* out_num,
                            int* out_bool, char* out_str, int outlen) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !id || !*id) return GPUI_ARG_NIL;
    auto it = box->lastValues.find(id);
    if (it == box->lastValues.end()) return GPUI_ARG_NIL;
    const Box2::ElemValue& v = it->second;
    if (v.vkind == GPUI_ARG_NUM && out_num) *out_num = v.vnum;
    if (v.vkind == GPUI_ARG_BOOL && out_bool) *out_bool = v.vbool;
    if (v.vkind == GPUI_ARG_STR && out_str && outlen > 0) {
        int n = (int)v.vstr.size();
        if (n > outlen - 1) n = outlen - 1;
        std::memcpy(out_str, v.vstr.data(), (size_t)n);
        out_str[n] = '\0';
    }
    return v.vkind;
}

// 宿主渲染虚拟列表的 renderer（bridge 登记，进程唯一）。见 gpuiRenderShimItems。
static GpuiListRenderFn g_listRender = nullptr;

void gpui_shim_set_list_renderer(GpuiListRenderFn fn) { g_listRender = fn; }

// 批6 宿主数据回路：materialize 途中同步回调 PHP 的处理器（DataTable rows /
// 图表 rows / SearchableList items）。见 gpuiInvokeShimDataCallback。
static GpuiDataCallbackFn g_dataCallback = nullptr;

void gpui_shim_set_data_callback(GpuiDataCallbackFn fn) { g_dataCallback = fn; }

uint32_t gpui_shim_push_list(void* win, const char* id, int uniform, int count) {
    auto* box = static_cast<Box2*>(win);
    if (!box) return 0;
    if (!id || !*id) {
        // 行回调按 (id, "render_items") 登记、按 id 找 PHP 侧的 renderer——没 id 等于
        // 这张表永远空着，上游只会画一个 0 行的列表，什么也不报。
        noteError(box, "list: 缺 id，宿主 renderer 无从查起（会画成 0 行）");
        return 0;
    }
    uint64_t cb = gpui_shim_new_callback(win, id, "render_items");
    gpui::shell::ListSpec ls;
    ls.id = cppStr(id);
    ls.itemCount = count < 0 ? 0 : count;
    ls.getKey = cb;
    ls.renderItems = cb;
    gpui::shell::Component c;
    c.kind = uniform ? gpui::shell::ComponentKind::UniformList
                     : gpui::shell::ComponentKind::List;
    c.list = &ls;  // Push → CopyComponent 把它连同 id 拷进 arena（spec.cpp）
    return box->arena().Push(c);
}

uint32_t gpui_shim_push(void* win, const char* kind, const char* text,
                        const char* value, const char* id, unsigned index) {
    auto* box = static_cast<Box2*>(win);
    gpui::shell::Component c;
    bool knownKind = true;
    c.kind = kindFromString(kind, &knownKind);
    if (!knownKind) {
        box->lastError = std::string("unknown element kind '") + (kind ? kind : "") +
                         "'（节点未建：静默降级成 Div 会让整棵子树看不见）";
        return 0;
    }
    // 借用而不是 AStr：specs.Push → CopyComponent 会对 text/value 各做一次
    // StrDup(arena, …)（spec.cpp:296-297），先 AStr 就是把同一段文本在 arena 里存两遍。
    // 安全性来自"这次调用是同步的"：bridge 的 std::string 活到 Push 返回。
    if (text && *text) c.text = cppStr(text);
    if (value && *value) c.value = cppStr(value);
    c.index = index;
    if (id && *id) {
        auto it = box->inputHandles.find(id);
        if (it != box->inputHandles.end()) c.handle = it->second;
    }
    return box->arena().Push(c);
}

/* 批5：路径绘制节点。与 gpui_shim_push 的差别在 background/strokeWidth 两个
 * Component 字段（PaintMaterialPath 消费，materialize.cpp:705/769）——push 的
 * 五槽 ABI 带不出来，这里在 Push **前**把 Component 填全（arena 的 Push 会
 * CopyComponent 拷字符串，借用安全）。bgKind：0=Solid（color），1=LinearGradient
 * （fromColor→toColor，angle）。命令（move_to/…）由桥按普通 method op 追加到
 * 返回的 spec 上——PaintMaterialPath 逐个读 node->ops。 */
uint32_t gpui_shim_push_path(void* win, const char* kind, const char* id,
                             double stroke_width, int bg_kind, const char* color,
                             const char* from_color, const char* to_color,
                             double angle) {
    auto* box = static_cast<Box2*>(win);
    bool knownKind = true;
    gpui::shell::Component c;
    c.kind = kindFromString(kind, &knownKind);
    if (!knownKind || (c.kind != gpui::shell::ComponentKind::PathFill &&
                       c.kind != gpui::shell::ComponentKind::PathStroke)) {
        box->lastError = std::string("gpui_shim_push_path: kind '") +
                         (kind ? kind : "") + "' 不是路径绘制 kind";
        return 0;
    }
    // Component 没有独立 id 字段——元素身份在 text（behavior.key，与
    // gpui_shim_push 的五槽同规）。
    if (id && *id) c.text = cppStr(id);
    if (bg_kind == 1) {
        c.background.kind = gpui::shell::BackgroundKind::LinearGradient;
        if (from_color && *from_color) c.background.fromColor = cppStr(from_color);
        if (to_color && *to_color) c.background.toColor = cppStr(to_color);
        c.background.angle = (float)angle;
    } else {
        c.background.kind = gpui::shell::BackgroundKind::Solid;
        if (color && *color) c.background.color = cppStr(color);
    }
    c.strokeWidth = (float)stroke_width;
    return box->arena().Push(c);
}

/* 批5：窗口动作动词（TitleBar B 期的最小面）。zoom = AppToggleMaximize
 * （window_common.cpp:2526 的双击通道同函数）。未知动作写 lastError。 */
int gpui_shim_window_action(void* win, const char* action) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !box->window || !action || !*action) return 0;
    if (std::strcmp(action, "minimize") == 0) {
        gpui::AppMinimize(box->window);
        return 1;
    }
    if (std::strcmp(action, "zoom") == 0) {
        gpui::AppToggleMaximize(box->window);
        return 1;
    }
    if (std::strcmp(action, "close") == 0) {
        gpui::AppClose(box->window);
        return 1;
    }
    box->lastError = std::string("unknown window action '") + action + "'";
    return 0;
}

int gpui_shim_spec_index_by_id(void* win, const char* id, unsigned* out_index) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !id || !*id || !out_index) return 0;
    const gpui::shell::SpecArena& specs = box->specs;
    const gpui::Str want = cppStr(id);
    for (gpui::shell::SpecId s = 0, n = (gpui::shell::SpecId)specs.Len(); s < n; ++s) {
        const gpui::shell::SpecNode* node = specs.Node(s);
        if (!node) continue;
        for (const gpui::shell::SpecOp& op : node->ops) {
            // ->id('x') 的落地形状是一个 Method op，名字 id、一个字符串实参。
            if (op.kind != gpui::shell::SpecOpKind::Method || !op.args || op.argCount < 1) continue;
            if (!gpui::StrEq(op.name, gpui::Str("id"))) continue;
            const gpui::shell::Bridged& a0 = op.args[0];
            if (a0.kind != gpui::shell::BridgedKind::String) continue;
            if (!gpui::StrEq(a0.string, want)) continue;
            *out_index = node->component.index;
            return 1;
        }
    }
    return 0;
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
                                   double max, double step, double value) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !id || !*id) return 0;
    auto it = box->inputHandles.find(id);
    if (it != box->inputHandles.end()) return it->second;
    uint64_t h = g_runtime->CreateRetainedSlider(
        (float)min, (float)max, (float)step, g_app, box->viewEntity, box);
    // CreateRetainedSlider 的初值恒为 min（runtime.cpp:489 SliderSingle(min)），
    // 没有初值形参——用公开的 SliderSetValue（gpui.h:6015）把脚本要的初值补上，
    // 量程裁剪交给 SliderValueClamp 同款语义。
    if (auto* e = g_runtime->Retained((gpui::shell::EntityHandle)h)) {
        if (e->kind == gpui::shell::RetainedKind::Slider && e->slider) {
            gpui::SliderValue initial =
                gpui::SliderValueClamp(gpui::SliderSingle((float)value),
                                       (float)min, (float)max);
            gpui::SliderSetValue(e->slider, initial);
        }
        box->retainedIdToElem[e->id] = id;
    }
    box->inputHandles[id] = h;
    return h;
}

uint64_t gpui_shim_retained_otp(void* win, const char* id, int length,
                                const char* value, int masked) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !id || !*id) return 0;
    auto it = box->inputHandles.find(id);
    if (it != box->inputHandles.end()) return it->second;
    uint64_t h = g_runtime->CreateRetainedOtp(length, cppStr(value ? value : ""),
                                              masked != 0, g_app,
                                              box->viewEntity, box);
    box->inputHandles[id] = h;
    if (auto* e = g_runtime->Retained((gpui::shell::EntityHandle)h))
        box->retainedIdToElem[e->id] = id;
    return h;
}


// 批3：OTP 格数回读（RetainedKind::Otp 的 state->length）。Element::otpInput 的
// otp_length 通道建 retained 时消费，运行期没有其他读口——otpLengthSix 门用它判
// "格数真的配到 6 而不是 4"（OtpSetValue 不裁剪，塞 9 字符存 9 个，值回读分不出）。
int gpui_shim_otp_length(void* win, const char* id) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !id || !*id) return 0;
    auto it = box->inputHandles.find(id);
    if (it == box->inputHandles.end()) return 0;
    auto* e = g_runtime->Retained((gpui::shell::EntityHandle)it->second);
    if (!e || e->kind != gpui::shell::RetainedKind::Otp) return 0;
    auto* st = e->otp.Get(e->app);
    return st ? st->length : 0;
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

// 把焦点放进一颗**已经有焦点身份**的部件，返回设完之后读到的 WindowFocusedId
// （0 = 这颗部件没有身份，那本身就是有用的信息，而不是"设失败了"）。
//
// 为什么需要它：内建动作派发（gpui_shim_dispatch_action）是从焦点节点出发的，
// 所以 Select / DatePicker 那族的 open_change 要能到 PHP，有两个各自成立的前提：
// 焦点得真的在这颗部件里（这个函数管），且派发点不能走 QuickJS 那条回调表
// （cmd/patch-select-view.sh 管——见 docs/guide/events.md 1b）。
// 上游自己也是这么排的：dialog 的 popup 用 FocusId(HashClickId(name))，
// 而 Select/DatePicker 的触发器与内容用 TrackFocus(RetainedFocus(handle))，
// handle 由 bridge 以 `id+":t"` / `id+":c"` 的名字造出来（见 gpui_bridge.cc 的
// selTrigger/selContent）。
// 所以这里按同一个命名规矩找 handle，而不是自己另算一个 id——
// WindowSetFocusId(win, HashClickId(元素 id)) 那种"看起来等价"的写法只对 dialog 成立。
int gpui_shim_focus_named(void* win, const char* id) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !box->window || !g_runtime || !id || !*id) return 0;
    static const char* kSuffixes[] = {":t", ":c", ""};
    for (const char* sfx : kSuffixes) {
        std::string key(id);
        key += sfx;
        auto it = box->inputHandles.find(key);
        if (it == box->inputHandles.end()) continue;
        auto* e = g_runtime->Retained((gpui::shell::EntityHandle)it->second);
        if (!e) continue;
        // 焦点 handle 存在两处：Select/DatePicker/dialog popup 用自己的 `e->focus`
        // （CreateRetainedFocus 造的），而 **retained 输入/文本域的 handle 在 InputState 里**
        // （CreateInput 里 `entry->input->focus = FocusHandleNew(app)`）。
        // 只看 `e->focus` 会把文档承诺支持的 "retained 输入" 一律判成"没有焦点身份"——
        // `--wait-key` 的 focusElement('in-name') 恒 0 就是这么来的（2026-10-17 实测）。
        auto fh = e->focus;
        if (e->kind == gpui::shell::RetainedKind::Input
            || e->kind == gpui::shell::RetainedKind::Textarea) {
            if (e->input) fh = e->input->focus;
        } else if (e->kind != gpui::shell::RetainedKind::Focus) {
            continue;
        }
        if (!fh.IsValid()) continue;
        gpui::WindowSetFocusId(box->window, fh.id);
        int got = gpui::WindowFocusedId(box->window);
        if (got) return got;
    }
    return 0;
}

int gpui_shim_input_value(void* win, const char* id, char* out, int outlen) {
    auto* box = static_cast<Box2*>(win);
    if (outlen <= 0) return 0;
    out[0] = '\0';
    if (!box || !id || !*id) return 0;
    auto it = box->inputHandles.find(id);
    if (it == box->inputHandles.end()) return 0;
    auto* e = g_runtime->Retained((gpui::shell::EntityHandle)it->second);
    if (!e) return 0;
    gpui::Str v;
    if (e->input) {
        v = gpui::InputValue(e->input);
    } else if (e->kind == gpui::shell::RetainedKind::Otp) {
        // OTP 的值不在 InputState 上：CreateRetainedOtp 填的是 entry->otp（OtpState），
        // 所以只看 e->input 的旧写法把 otp 当成"没有值可读"——setInput/text 双双静默失败
        // （examples/input 的 --selftest 实测 set=false、text=''，而 registeredError 是空的）。
        if (auto* st = e->otp.Get(e->app)) {
            v = gpui::OtpValue(st);
        } else {
            return 0;
        }
    } else if (e->kind == gpui::shell::RetainedKind::Slider && e->slider) {
        // 批3：slider 的值回读。单值滑块的值在 SliderValue::hi（gpui.h 注释：
        // "A single-value slider only uses hi"），按 F64DisplayTemp 的整数口径吐出，
        // App::text('slider-id') 就能读——量程/初值门（sliderRangeInitial）靠它。
        char buf[32];
        std::snprintf(buf, sizeof(buf), "%.0f",
                      (double)e->slider->value.Start());
        v = gpui::Str(buf);
    } else {
        return 0;
    }
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
    if (!e) return 0;
    if (e->input) {
        gpui::InputSetValue(e->input, cppStr(text ? text : ""));
    } else if (e->kind == gpui::shell::RetainedKind::Otp) {
        auto* st = e->otp.Get(e->app);
        if (!st) return 0;
        gpui::OtpSetValue(st, cppStr(text ? text : ""));
    } else {
        return 0;
    }
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
        // 选窗只认传进来的那一扇：Box2::window → 它自己的 NSWindow（由
        // cmd/patch-window-handle.sh 在上游侧露的口）。解析不到就**失败**，不再退化成
        // [NSApp keyWindow]、拿不到再取 [NSApp windows] 的第一个——那两条都由 OS 的前台
        // 仲裁决定，于是同一条命令会偶尔拍到另一扇窗的整帧（hello tree29/31 实测
        // gdfrac=1.00000）。拍不到正确窗口宁可红，也不能拍错当标准答案。
        // 判别臂的实测（hello --selftest 的 snaparm）：旧选窗下两扇各自取帧回同一个宽度
        // A420px/B420px 且 SELFTEST FAIL rc=1；本实现 A400px/B420px 且 rc=0。
        void* nat = box->window ? gpui::WindowNativeHandle(box->window) : nullptr;
        NSWindow* nswin = nat ? (__bridge NSWindow*)nat : nullptr;
        if (!nswin) return 0;
        // 指针归位：拍的是在屏窗口的当前绘制结果，而悬停态与 Inspector 拾取高亮读的是
        // 窗口记下的指针坐标——不归位，基线就取决于物理鼠标那一刻在哪（实测：embedded 整片
        // 蓝/白翻转、hello tree31 整张不同、Inspector 头偶尔被高亮）。顺序有讲究：
        //   ① 先让窗口忽略真实鼠标——窗口常常就开在物理光标底下，OS 的 mouseMoved/entered
        //      会在我们停好之后把坐标改回去（只停不挡时 4 次里仍有 1 次被盖）；
        //      Deliver 走 sendEvent，不经窗口服务器命中测试，所以合成事件不受这一步影响；
        //   ② 排空已经入队的真实事件；③ 停到窗口外固定点；④ 泵几帧让它真的画上屏。
        //   ③ 直接走 WindowDispatchInput（同步写 win->mouseX，那是它唯一的写入点，
        //      window_common.cpp:1920），不经 NSEvent 路由——经路由的版本实测 6 次出 3 种图。
        BOOL wasIgnoring = nswin.ignoresMouseEvents;
        nswin.ignoresMouseEvents = YES;
        gpui::GpuiAppRunStep(g_app, 0);
        if (box->window) {
            gpui::PlatformInput park = gpui::InputMouseMove(
                -40.f, -40.f, false, gpui::MouseButton::Left, gpui::Modifiers{});
            gpui::WindowDispatchInput(box->window, &park);
        }
        for (int i = 0; i < 4; i++) gpui::GpuiAppRunStep(g_app, 0.03);
        nswin.ignoresMouseEvents = wasIgnoring;
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

/* 区域平均色（0-255）：给"泵到画出来为止"的内容判据用。hello tree30 的 WebView
 * 首帧未进 cacheDisplay 背板时整块是页面底色，而帧数预算猜不准（120 与 600 帧都曾
 * 全空白，2026-10-29 读数）⇒ 判"画没画"只能读内容本身。坐标是 gpui 视图系（y 向下）；
 * 只_capture_，不停指针不泵帧（泵由调用方负责）。失败返回 0，out 不动。 */
int gpui_shim_region_rgb(void* win, double x, double y, double w, double h, double* out3) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !out3 || w <= 0 || h <= 0) return 0;
    @autoreleasepool {
        void* nat = box->window ? gpui::WindowNativeHandle(box->window) : nullptr;
        NSWindow* nswin = nat ? (__bridge NSWindow*)nat : nullptr;
        if (!nswin) return 0;
        NSView* view = [nswin contentView];
        if (!view) return 0;
        NSRect bounds = [view bounds];
        NSBitmapImageRep* rep = [view bitmapImageRepForCachingDisplayInRect:bounds];
        if (!rep) return 0;
        [view cacheDisplayInRect:bounds toBitmapImageRep:rep];
        const double sx = rep.pixelsWide / bounds.size.width;
        const double sy = rep.pixelsHigh / bounds.size.height;
        NSInteger px = (NSInteger)(x * sx);
        NSInteger py = (NSInteger)(y * sy);
        NSInteger pw = (NSInteger)(w * sx);
        NSInteger ph = (NSInteger)(h * sy);
        if (px < 0 || py < 0 || pw <= 0 || ph <= 0 ||
            px + pw > rep.pixelsWide || py + ph > rep.pixelsHigh) {
            return 0;
        }
        double acc[3] = {0, 0, 0};
        NSInteger n = 0;
        for (NSInteger ry = py; ry < py + ph; ry += 2) {
            for (NSInteger rx = px; rx < px + pw; rx += 2) {
                NSColor* c = [rep colorAtX:rx y:ry];
                if (!c) continue;
                acc[0] += c.redComponent * 255.0;
                acc[1] += c.greenComponent * 255.0;
                acc[2] += c.blueComponent * 255.0;
                n++;
            }
        }
        if (n == 0) return 0;
        out3[0] = acc[0] / n;
        out3[1] = acc[1] / n;
        out3[2] = acc[2] / n;
        return 1;
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
    // timerKeys 的键是"本次重挂发的号"，旧号没人收就是每次 setTimer 净增一条：
    // 每帧重挂的 app（滑杆驱动 tick）会一直长。按 phpId 反查删掉同名旧键。
    for (auto it = box->timerKeys.begin(); it != box->timerKeys.end();) {
        if (it->second == id) it = box->timerKeys.erase(it);
        else ++it;
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

// 把我们的 overlay 包装与上游的真实状态对齐。
// 关键：dialog/sheet 可以被**原生**关掉——DialogOptions 的 escapeDismissable /
// backdropDismissable 走的是 ShellDialogLayer::Close，sheet 的关闭按钮同理，
// 那些路都不经过本 shim。只在 show/close 里弹栈就会脱钩，而多出来的那份包装对应的
// PhpView 已经被上游层析构释放（root.cpp:209/398），留着就是悬垂键，下次同地址复用
// 时 boxForView 会把已死的 Box2 交给回调。
static void syncOverlays(Box2* box) {
    if (!box || !box->window) return;
    gpui::Ctx cx = overlayCtx(box);
    int live = (int)gpui::ShellRootDialogCount(&cx);
    if (live < 0) live = 0;
    while ((int)box->dialogs.size() > live) {
        retireOverlay(box->dialogs.back());
        box->dialogs.pop_back();
    }
    if (box->sheet && !gpui::ShellRootHasSheet(&cx)) {
        retireOverlay(box->sheet);
        box->sheet = nullptr;
    }
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
    registerHostApplication(dlg->viewEntity);
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
    syncOverlays(box);
    return n;
}

int gpui_shim_dialog_count(void* win) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !box->window) return 0;
    // PHP 轮询这个口子，顺手对齐：原生关掉的那些 dialog 在这里被回收掉。
    syncOverlays(box);
    gpui::Ctx cx = overlayCtx(box);
    return gpui::ShellRootDialogCount(&cx);
}

/* 当前生效的焦点陷阱 id（0 = 没有）。#62 的机制级读数：
 * dialog 的 Escape 绑定挂在 popup 的 KeyContext 上，而按键按"焦点所在的上下文链"解析，
 * 所以"陷阱有没有生效"比"按 Escape 关没关"更早说明问题。 */
int gpui_shim_focus_trap(void* win) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !box->window) return 0;
    return gpui::FocusTrapActive(box->window);
}

int gpui_shim_dialog_close(void* win) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !box->window) return 0;
    gpui::Ctx cx = overlayCtx(box);
    if (!gpui::ShellRootCloseDialog(&cx)) return 0;
    syncOverlays(box);   // 按上游真实条数弹栈（原生 Escape/背板可能已经关过别的）
    return 1;
}

void gpui_shim_set_app_menus(void* win, const GpuiMenu* menus, int count) {
    auto* box = static_cast<Box2*>(win);
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
            // 菜单行的动作与 El::menuItem 走同一条 App 级回调：AppMenuChosen 只把 id
            // 派给钥匙窗口，PHP 宿主的内容树没有根应用焦点链可冒泡。
            if (r.action) registerMenuAction(box, gpui::Str(it.action));
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

// 系统菜单条第 row 行（1 起）带的动作名——即 PHP 当初声明的那个 id。
// 返回：>0 写入字节数 / 0 该行存在但没有动作（分隔符、禁用项）/ -1 行号越界。
// 为什么单独量这一跳：真点原生菜单行的最后一跳在系统菜单条那个窗口里，进程内注入的
// NSEvent 到不了（`gpui_mac_send_mouse` 只 Deliver 给自己的窗口），所以"行里装的确实是
// 那个 id"必须单独取证，不能靠 dispatchAction 顺带代表。
int gpui_shim_menu_row_action(int row, char* out, int outlen) {
    if (row < 1 || !out || outlen <= 1) return -1;
    uint32_t action = 0;
    int64_t arg = 0;
    if (!gpui::AppMenuRowForId(row, &action, &arg)) return -1;
    if (!action) return 0;
    gpui::Str name = gpui::shell::ShellActionScriptId(action);
    if (!name.s || name.len <= 0) return 0;
    int n = (int)name.len;
    if (n > outlen - 1) n = outlen - 1;
    std::memcpy(out, name.s, (size_t)n);
    out[n] = '\0';
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
    // OpenSheet 是**替换**：旧 sheet 的层在这一步就被上游同步 drop 了（root.cpp:490-494），
    // 所以先把上一个包装回收掉，再记新的。sheet 不进 dialogs 栈——它只有一个槽。
    retireOverlay(box->sheet);
    box->sheet = dlg;
    return 1;
}

int gpui_shim_close_sheet(void* win) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !box->window) return 0;
    gpui::Ctx cx = overlayCtx(box);
    if (!gpui::ShellRootCloseSheet(&cx)) return 0;
    syncOverlays(box);
    return 1;
}

int gpui_shim_has_sheet(void* win) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !box->window) return 0;
    gpui::Ctx cx = overlayCtx(box);
    syncOverlays(box);
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
    return box->arena().Push(c);
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
    return box->arena().Push(c);
}

#include "gpui_shim_services.inc"  // HTTP / 目录监听 / 解压（文本切片）
#include "gpui_shim_caps.inc"  // 授权文件系统 / 进程 / 存储（文本切片，见该文件头）
#include "gpui_shim_events.inc"  // 事件入队强符号（文本切片）
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

