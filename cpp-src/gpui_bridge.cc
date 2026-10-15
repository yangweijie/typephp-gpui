// typephp-gpui — PHP-facing 桥接（nano/c++17 编译单元）。
//
// 只 include phpx + cpp-src/gpui_shim.h（纯 C ABI），绝不 include gpui.h：
// gpui.h 用了 C++20 concept/requires，nano 锁 c++17 无法解析它。所有碰 gpui 的
// 逻辑都在 c++20 侧 shim（gpui_shim.cpp）里，本文件把 PHP 侧的 Element 数组树
// 递归展开成扁平的 push/style/method/callback/attach C 调用下发，并把 poll 取回
// 的定长 C struct 事件转成 PHP 数组交给 App 主循环分派。
//
// 窗口句柄：shim 返回的 opaque void* 用 php::Box 子类包成 PHP mixed 资源来回传递。

#include "phpx.h"

#include "gpui_shim.h"

#include <cstdio>
#include <cstdlib>
#include <deque>
#include <string>
#include <unordered_map>
#include <vector>

using php::Array;
using php::Bool;
using php::Box;
using php::Float;
using php::Int;
using php::String;
using php::Variant;

static void forgetListRenderers(void* win);

namespace {

// 把 shim 的 opaque 窗口句柄包成 PHP 资源。
class WindowBox : public Box {
  public:
    void* handle = nullptr;
    explicit WindowBox(void* h) : handle(h) {}
    ~WindowBox() override = default;
};

inline void* winOf(Variant v) {
    WindowBox* b = v.toBox<WindowBox>();
    return b ? b->handle : nullptr;
}

inline std::string strOf(Variant v) {
    if (v.isString()) return std::string(v.toCString());
    if (v.isInt()) return std::to_string((long long)v.toInt());
    return std::string();
}

// "on_click" → "click"，与 App->on(id, 'click') 的事件类型对齐。
inline std::string eventType(const std::string& opName) {
    if (opName.rfind("on_", 0) == 0) return opName.substr(3);
    return opName;
}

// 回调实参的编码约定：PHP 把它序列化成数字数组 ['__cb__', type, elemId]
// （elemId 可空→用所在元素 id）。shim 端 ComponentArgument.callback 需要
// 一个 callbackId，这里经 gpui_shim_new_callback 预分配（不建 JS 闭包；
// Stage C 静态渲染下从不触发，Stage D 才接真实回环）。用位置哨兵而非关联键
// 判存在，避开 phpx get() 对缺失键返回 null 的语义歧义。
constexpr const char* kCbSentinel = "__cb__";

bool isCallbackMarker(Array aa) {
    if (aa.count() < 1) return false;
    Variant h = aa.get(0);
    return h.isString() && std::strcmp(h.toCString(), kCbSentinel) == 0;
}

// Element::state() 的哨兵：['__state__', kind, key, [args...]]。
// catalog 的保留态实体只能由宿主经 gpui_shim_state 造（#81），而 PHP 侧建树时
// 窗口可能还不存在——所以树上先挂这个标记，等到这里（emitTree，窗口必然在）才换成 handle。
constexpr const char* kStateSentinel = "__state__";

bool isStateMarker(Array aa) {
    if (aa.count() < 3) return false;
    Variant h = aa.get(0);
    return h.isString() && std::strcmp(h.toCString(), kStateSentinel) == 0;
}

GpuiArg NIL_ARG() {
    GpuiArg g{};
    g.kind = GPUI_ARG_NIL;
    return g;
}

// 递归把一个 phpx 值转成 GpuiArg。字符串后备放 strPool、嵌套数组后备放 subPool，
// 两者都是 std::deque——push_back 不搬移已有元素，故 c_str()/data() 地址直到
// gpui_shim_registered 把值拷进 arena 前都稳定。
GpuiArg toArgOne(Variant av, void* win, const std::string& defId,
                 std::deque<std::string>& strPool,
                 std::deque<std::vector<GpuiArg>>& subPool) {
    if (av.isString()) {
        GpuiArg g{};
        strPool.emplace_back(av.toCString());
        g.kind = GPUI_ARG_STR;
        g.str = strPool.back().c_str();
        return g;
    }
    if (av.isBool()) {
        GpuiArg g{};
        g.kind = GPUI_ARG_BOOL;
        g.b = av.toBool() ? 1 : 0;
        return g;
    }
    if (av.isInt() || av.isFloat()) {
        GpuiArg g{};
        g.kind = GPUI_ARG_NUM;
        g.num = av.toFloat();
        return g;
    }
    if (av.isArray()) {
        Array aa = av.toArray();
        if (isCallbackMarker(aa)) {
            std::string type = aa.count() >= 2 ? strOf(aa.get(1)) : std::string("click");
            std::string elemId = aa.count() >= 3 ? strOf(aa.get(2)) : defId;
            GpuiArg g{};
            g.kind = GPUI_ARG_CB;
            g.num = (double)gpui_shim_new_callback(win, elemId.c_str(),
                                                   type.c_str());
            return g;
        }
        if (isStateMarker(aa)) {
            // 内层实参先按普通规则转好（InputState 的两个 optional 文本就是 STR/NIL），
            // 再交给 gpui_shim_state 造实体；它按 (kind,key) 缓存，所以每帧重建同一棵树
            // 拿回的是同一颗实体。造不出来就下发 NIL：shim 的 Entity 支会拒绝非 ENTITY
            // 实参，于是这颗组件渲染成错误节点、registeredError() 报出原因——而不是
            // 把 0 当 handle 传下去（0 在上游是一颗合法实体，可能属于别的组件）。
            std::string kind = strOf(aa.get(1));
            std::string key = strOf(aa.get(2));
            std::vector<GpuiArg> inner;
            if (aa.count() >= 4 && aa.get(3).isArray()) {
                Array list = aa.get(3).toArray();
                inner.resize(list.count());
                for (size_t j = 0; j < inner.size(); ++j)
                    inner[j] = toArgOne(list.get(j), win, defId, strPool, subPool);
            }
            uint64_t handle = 0;
            if (!gpui_shim_state(win, kind.c_str(), key.c_str(),
                                 inner.empty() ? nullptr : inner.data(),
                                 (int)inner.size(), &handle))
                return NIL_ARG();
            GpuiArg g{};
            g.kind = GPUI_ARG_ENTITY;
            g.num = (double)handle;
            return g;
        }
        // 普通数组 → GPUI_ARG_ARRAY（递归转 items）。
        std::vector<GpuiArg> inner;
        const size_t n = aa.count();
        inner.resize(n);
        for (size_t j = 0; j < n; ++j)
            inner[j] = toArgOne(aa.get(j), win, defId, strPool, subPool);
        subPool.push_back(std::move(inner));
        GpuiArg g{};
        g.kind = GPUI_ARG_ARRAY;
        g.items = subPool.back().data();
        g.count = (int)n;
        return g;
    }
    return NIL_ARG();
}

// argsV（phpx Array）→ 顶层 GpuiArg[]；pool 由调用方持有，存活到 registered 调用返回。
std::vector<GpuiArg> buildArgs(void* win, const std::string& defId,
                               Variant argsV, std::deque<std::string>& strPool,
                               std::deque<std::vector<GpuiArg>>& subPool) {
    std::vector<GpuiArg> out;
    if (!argsV.isArray()) return out;
    Array aa = argsV.toArray();
    const size_t n = aa.count();
    out.resize(n);
    for (size_t j = 0; j < n; ++j)
        out[j] = toArgOne(aa.get(j), win, defId, strPool, subPool);
    return out;
}

// 递归展开一棵 Element 数组，向 shim 发扁平指令；返回该节点的 spec id。
uint32_t emitTree(void* win, Array node) {
    std::string kind = "div";
    {
        Variant kv = node.get("kind");
        if (kv.isString()) kind = std::string(kv.toCString());
    }
    std::string text = strOf(node.get("text"));
    std::string value = strOf(node.get("value"));
    std::string id = strOf(node.get("id"));

    // Input/Textarea/Slider：先建（或复用）retained 实体，push 时按 id 附带 handle。
    // placeholder/初值从 method ops 提取（Element.placeholder()/value() 记的 op）。
    // number_input 也复用 Input 的 retained（materialize 的 NumberInput 分支要的就是
    // RetainedKind::Input，materialize.cpp:1968），所以它和 input 同一条路。
    if (kind == "input" || kind == "textarea" || kind == "slider"
        || kind == "number_input" || kind == "otp_input") {
        std::string ph, val = value;
        int rows = 0;
        Variant opsV0 = node.get("ops");
        if (opsV0.isArray()) {
            Array ops0 = opsV0.toArray();
            for (size_t i = 0; i < ops0.count(); ++i) {
                Variant ov = ops0.get(i);
                if (!ov.isArray()) continue;
                Array op = ov.toArray();
                if (strOf(op.get("kind")) != "method") continue;
                std::string mn = strOf(op.get("name"));
                Variant args = op.get("args");
                std::string a0 = args.isArray() ? strOf(args.toArray().get(0)) : std::string();
                if (mn == "placeholder" && !a0.empty()) ph = a0;
                else if (mn == "value" && !a0.empty()) val = a0;
                else if (mn == "rows" && !a0.empty()) rows = (int)atoll(a0.c_str());
            }
        }
        if (id.empty()) {
            // 没 id 就建不出 retained，materialize 会静默 return Div——宁可报错。
            gpui_shim_set_error(win, (kind + ": 缺 id，retained 实体建不出来（会静默降级成 Div）").c_str());
        }
        if (kind == "otp_input") {
            // 4 格：shell 目前没有把"格子数"传下来的通道（OtpInput 的 ctor 只吃 handle），
            // 所以长度定在 bridge 里；要可配的话得先给 Element/桥加一条 length 通道。
            gpui_shim_retained_otp(win, id.c_str(), 4, val.c_str(), 0);
        } else if (kind == "slider") {
            // slider 的 min/max 未进树（Element.slider 只记 value op）；用默认量程。
            double lo = 0, hi = 100, st = 1;
            gpui_shim_retained_slider(win, id.c_str(), lo, hi, st);
        } else {
            gpui_shim_retained_input(win, id.c_str(), kind == "textarea" ? 1 : 0,
                                     ph.c_str(), val.c_str(), rows);
        }
    }

    // DatePicker：component.handle 需一个 Focus 实体（RetainedFocus 校验 kind==Focus）。
    // Select/Combobox：trigger/content 各需一个 Focus，经 method op
    // track_focus / content_focus_handle 传入（名字必须是这两个——写成 focus/content_focus
    // 时上游查不到，而带参未知 op 是静默丢弃那一格，句柄等于从未交出去）。
    uint64_t selTrigger = 0, selContent = 0;
    if (kind == "date_picker") {
        if (!id.empty()) gpui_shim_retained_focus(win, id.c_str());
    } else if (kind == "select" || kind == "combobox") {
        if (!id.empty()) {
            selTrigger = gpui_shim_retained_focus(win, (id + ":t").c_str());
            selContent = gpui_shim_retained_focus(win, (id + ":c").c_str());
        }
    }

    uint32_t spec = 0;
    bool registered = false;
    {
        Variant rv = node.get("registered");
        if (rv.isBool()) registered = rv.toBool();
    }
    // Element 的 index 槽：TableRow 用它当行号、TableHead/TableCell 当列号。
    unsigned nodeIndex = 0;
    {
        Variant iv = node.get("index");
        if (iv.isInt() || iv.isFloat()) nodeIndex = (unsigned)iv.toFloat();
    }
    if (kind == "webview") {
        // 不是 spec 里的普通 kind：ChildView 节点要一个 host 注册的 nested-view
        // token，那只有 shim 能给。value 是地址、text 是本地 HTML。返回的仍是普通
        // spec id，所以样式、挂父、槽位这套照旧走。
        spec = gpui_shim_webview(win, id.c_str(), value.c_str(), text.c_str());
    } else if (kind == "inspector") {
        // 同一条 ChildView 路：DevTools 面板作为元素挂进树（⌘I 只是拾取开关）。
        spec = gpui_shim_inspector(win, id.c_str());
    } else if (kind == "notifications") {
        // 通知层。gpui 的基座 Root 自己挂它，我们的根是 ShellRoot（只挂 Toast），
        // 所以没这颗节点时 push 依然成功、只是看不见。
        spec = gpui_shim_notification_list(win, id.c_str());
    } else if (kind == "dock_area") {
        spec = gpui_shim_push_dock(win, id.c_str());
    } else if (kind == "list" || kind == "uniform_list") {
        // 行不在树里：它们由 App::listRenderer() 登记的 PHP 闭包在 materialize 途中给
        // （见 renderListRows 与 cmd/patch-host-list.sh）。树上只带条数。
        Variant cv = node.get("listCount");
        int count = (cv.isInt() || cv.isFloat()) ? (int)cv.toFloat() : 0;
        spec = gpui_shim_push_list(win, id.c_str(), kind == "uniform_list" ? 1 : 0, count);
    } else if (registered) {
        // catalog 组件：kind 是导出名，ctorArgs 按声明顺序的扁平实参。
        std::deque<std::string> strPool;
        std::deque<std::vector<GpuiArg>> subPool;
        std::vector<GpuiArg> args =
            buildArgs(win, id, node.get("ctorArgs"), strPool, subPool);
        spec = gpui_shim_registered(win, kind.c_str(), args.data(),
                                    (int)args.size());
    } else {
        spec = gpui_shim_push(win, kind.c_str(), text.c_str(), value.c_str(),
                              id.c_str(), nodeIndex);
    }

    if (selTrigger || selContent) {
        // 名字必须是上游的 `track_focus` / `content_focus_handle`
        // （materialize.cpp:415-417）。写成 "focus"/"content_focus" 时上游查不到，
        // 而带参未知名走的是**静默丢弃**那条路（行为链链尾没有 else），焦点句柄就从未生效过。
        GpuiArg at{GPUI_ARG_NUM, (double)selTrigger, 0, nullptr};
        gpui_shim_method(win, spec, "track_focus", &at, 1);
        GpuiArg ac{GPUI_ARG_NUM, (double)selContent, 0, nullptr};
        gpui_shim_method(win, spec, "content_focus_handle", &ac, 1);
    }

    Variant opsV = node.get("ops");
    if (opsV.isArray()) {
        Array ops = opsV.toArray();
        // 这几个缓冲提到 op 循环外：原来每个 op 都要构造 2 个 std::string（其中
        // opKind 只是拿去和 4 个字面量比），param_style/method 还要再来 2 个 vector。
        // 放在这一层（每个节点一次）既省掉 per-op 分配，又不会跨递归别名——
        // backing 里的 c_str() 只在本节点这次同步调用期间需要活着。
        std::string opKind, name;
        std::vector<GpuiArg> args;
        std::vector<std::string> backing;
        for (size_t i = 0; i < ops.count(); ++i) {
            Variant ov = ops.get(i);
            if (!ov.isArray()) continue;
            Array op = ov.toArray();
            opKind = strOf(op.get("kind"));
            name = strOf(op.get("name"));

            if (opKind == "nullary_style") {
                gpui_shim_nullary_style(win, spec, name.c_str());
            } else if (opKind == "param_style" || opKind == "method") {
                Variant argsV = op.get("args");
                // backing 每参数一个槽位、建好即定长，保证 c_str() 地址稳定，
                // 直到 shim 把值拷进 arena。
                args.clear();
                backing.clear();
                if (argsV.isArray()) {
                    Array aa = argsV.toArray();
                    const size_t n = aa.count();
                    backing.resize(n);
                    args.resize(n);
                    for (size_t j = 0; j < n; ++j) {
                        Variant av = aa.get(j);
                        GpuiArg& ga = args[j];
                        ga.kind = GPUI_ARG_NIL;
                        ga.num = 0;
                        ga.b = 0;
                        ga.str = nullptr;
                        if (av.isString()) {
                            backing[j] = av.toCString();
                            ga.kind = GPUI_ARG_STR;
                            ga.str = backing[j].c_str();
                        } else if (av.isBool()) {
                            ga.kind = GPUI_ARG_BOOL;
                            ga.b = av.toBool() ? 1 : 0;
                        } else if (av.isInt() || av.isFloat()) {
                            ga.kind = GPUI_ARG_NUM;
                            ga.num = av.toFloat();
                        }
                    }
                }
                if (opKind == "param_style")
                    gpui_shim_param_style(win, spec, name.c_str(), args.data(),
                                          (int)args.size());
                else
                    gpui_shim_method(win, spec, name.c_str(), args.data(),
                                     (int)args.size());
            } else if (opKind == "callback") {
                std::string type = eventType(name);
                gpui_shim_callback(win, spec, id.c_str(), type.c_str());
            } else if (opKind == "slot") {
                Variant childV = op.get("child");
                if (childV.isArray()) {
                    uint32_t child = emitTree(win, childV.toArray());
                    gpui_shim_slot(win, spec, child, name.c_str());
                }
            } else if (opKind == "registered_method") {
                std::deque<std::string> strPool;
                std::deque<std::vector<GpuiArg>> subPool;
                std::vector<GpuiArg> args =
                    buildArgs(win, id, op.get("args"), strPool, subPool);
                gpui_shim_registered_method(win, spec, name.c_str(),
                                            args.data(), (int)args.size());
            }
        }
    }

    Variant kids = node.get("children");
    if (kids.isArray()) {
        Array ka = kids.toArray();
        for (size_t i = 0; i < ka.count(); ++i) {
            Variant cv = ka.get(i);
            if (!cv.isArray()) continue;
            uint32_t child = emitTree(win, cv.toArray());
            gpui_shim_attach(win, spec, child);
        }
    }
    return spec;
}

} // namespace

// ────────────────────── php_gpui_* 实现 ──────────────────────

Variant php_gpui_app_create() {
    gpui_shim_app_init();
    return Variant();
}

Variant php_gpui_window_open(String title, Int width, Int height) {
    std::string t(title.data(), title.length());
    void* h = gpui_shim_open_window(t.c_str(), (int)width, (int)height);
    if (!h) return Variant();
    return Variant{new WindowBox(h)};
}

void php_gpui_render(Variant boxVar, Array tree) {
    void* win = winOf(boxVar);
    if (!win) return;
    gpui_shim_render_begin(win);
    uint32_t root = emitTree(win, tree);
    gpui_shim_render_end(win, root);
}

Bool php_gpui_window_is_open(Variant boxVar) {
    void* win = winOf(boxVar);
    if (!win) return false;
    bool open = gpui_shim_is_open(win) != 0;
    if (!open) forgetListRenderers(win);
    return open;
}

void php_gpui_pump_events(Variant boxVar) {
    void* win = winOf(boxVar);
    if (win) gpui_shim_pump(win, 0.016);
}

Array php_gpui_poll_event(Variant boxVar) {
    void* win = winOf(boxVar);
    if (!win) return Array();
    GpuiEvent ev{};
    if (!gpui_shim_poll(win, &ev)) return Array();
    Array out;
    out.set("type", Variant(ev.type));
    out.set("id", Variant(ev.id));
    Variant value;
    switch (ev.vkind) {
        case GPUI_ARG_BOOL: value = Variant((bool)ev.vbool); break;
        case GPUI_ARG_NUM: value = Variant(ev.vnum); break;
        case GPUI_ARG_STR: value = Variant(ev.vstr); break;
        default: break;  // NIL
    }
    out.set("value", value);
    if (std::strcmp(ev.type, "item_secondary_click") == 0) {
        Array position;
        position.set("x", Variant(ev.itemPointer[0]));
        position.set("y", Variant(ev.itemPointer[1]));
        Array local;
        local.set("x", Variant(ev.itemPointer[2]));
        local.set("y", Variant(ev.itemPointer[3]));
        Array modifiers;
        int mask = (int)ev.itemPointer[5];
        modifiers.set("control", Variant((mask & 1) != 0));
        modifiers.set("alt", Variant((mask & 2) != 0));
        modifiers.set("shift", Variant((mask & 4) != 0));
        modifiers.set("platform", Variant((mask & 8) != 0));
        modifiers.set("function", Variant((mask & 16) != 0));
        out.set("button", Variant("right"));
        out.set("click_count", Variant((Int)ev.itemPointer[4]));
        out.set("position", Variant(position));
        out.set("local_position", Variant(local));
        out.set("modifiers", Variant(modifiers));
    }
    return out;
}

Bool php_gpui_dock_create(Variant boxVar, String id) {
    void* win = winOf(boxVar);
    std::string dock(id.data(), id.length());
    return win && gpui_shim_dock_create(win, dock.c_str()) == 1;
}

Bool php_gpui_dock_panel(Variant boxVar, String id, String name, String title,
                         Array tree, String placement, Float size) {
    void* win = winOf(boxVar);
    if (!win) return false;
    std::string dock(id.data(), id.length());
    std::string panel(name.data(), name.length());
    std::string label(title.data(), title.length());
    std::string side(placement.data(), placement.length());
    if (!gpui_shim_dock_panel_begin(win, dock.c_str(), panel.c_str(), label.c_str())) return false;
    uint32_t root = emitTree(win, tree);
    return gpui_shim_dock_panel_end(win, dock.c_str(), panel.c_str(), side.c_str(),
                                   (double)size, root) == 1;
}

Bool php_gpui_dock_select(Variant boxVar, String id, String name) {
    std::string dock(id.data(), id.length());
    std::string panel(name.data(), name.length());
    void* win = winOf(boxVar);
    return win && gpui_shim_dock_select(win, dock.c_str(), panel.c_str()) == 1;
}

Bool php_gpui_dock_remove(Variant boxVar, String id, String name) {
    std::string dock(id.data(), id.length());
    std::string panel(name.data(), name.length());
    void* win = winOf(boxVar);
    return win && gpui_shim_dock_remove(win, dock.c_str(), panel.c_str()) == 1;
}

String php_gpui_dock_layout(Variant boxVar, String id) {
    void* win = winOf(boxVar);
    if (!win) return String();
    std::string dock(id.data(), id.length());
    int n = gpui_shim_dock_layout(win, dock.c_str(), nullptr, 0);
    if (n <= 0) return String();
    std::vector<char> buffer((size_t)n);
    gpui_shim_dock_layout(win, dock.c_str(), buffer.data(), n);
    return String(buffer.data(), n);
}

Bool php_gpui_dock_load(Variant boxVar, String id, String json) {
    void* win = winOf(boxVar);
    std::string dock(id.data(), id.length());
    std::string layout(json.data(), json.length());
    return win && gpui_shim_dock_load(win, dock.c_str(), layout.c_str()) == 1;
}

Array php_gpui_dock_probe(Variant boxVar, String id) {
    Array out;
    double data[8] = {};
    std::string dock(id.data(), id.length());
    void* win = winOf(boxVar);
    if (!win || gpui_shim_dock_probe(win, dock.c_str(), data, 8) != 8) return out;
    const char* names[] = {"registered", "open", "active", "nodes", "w", "h", "x", "y"};
    for (int i = 0; i < 8; i++) {
        if (i < 4) out.set(names[i], Variant((Int)data[i]));
        else out.set(names[i], Variant(data[i]));
    }
    return out;
}

// ── 宿主渲染的虚拟列表（#29/#35）────────────────────────────────────────────
// (窗口, 列表 id) → PHP 闭包。闭包签名 fn(int $start, int $end): array，返回值是一组
// Element::toArray() 结果（App::listRenderer 包了这一层）。持有 Variant 即持有一份引用，
// 闭包活到被替换或窗口关闭。
static std::unordered_map<std::string, Variant> g_listFns;

static std::string listKey(void* win, const char* id) {
    char p[32];
    std::snprintf(p, sizeof(p), "%p|", win);
    return std::string(p) + (id ? id : "");
}

// 由 shim 在 materialize 途中同步调用（gpuiRenderShimItems）。这里就是那条"C++ 同步
// 回调 PHP"的路：php::call 在本线程、本 zend 执行栈上跑闭包——外层 PHP 此刻正阻塞在
// pump/snapshot 的调用里，所以是普通的重入调用（同 usort 回调），不是并发。
static int renderListRows(void* win, const char* id, int first, int end,
                          uint32_t* out, int cap) {
    auto it = g_listFns.find(listKey(win, id));
    if (it == g_listFns.end()) return 0;
    Variant renderer = it->second;
    Array args;
    args.append(Variant((Int)first));
    args.append(Variant((Int)end));
    Variant r = php::call(renderer, args);
    if (!r.isArray()) return 0;
    Array rows = r.toArray();
    if (rows.count() != (size_t)(end - first)) return 0;
    int n = 0;
    for (size_t i = 0; i < rows.count() && n < cap; ++i) {
        Variant row = rows.get(i);
        if (!row.isArray()) break;
        out[n++] = emitTree(win, row.toArray());
    }
    return n;
}

void php_gpui_set_list_renderer(Variant boxVar, String id, Variant fn) {
    void* win = winOf(boxVar);
    if (!win) return;
    static bool hooked = false;
    if (!hooked) {
        gpui_shim_set_list_renderer(&renderListRows);
        hooked = true;
    }
    std::string i(id.data(), id.length());
    g_listFns[listKey(win, i.c_str())] = fn;
}

static void forgetListRenderers(void* win) {
    const std::string prefix = listKey(win, "");
    for (auto it = g_listFns.begin(); it != g_listFns.end();) {
        if (it->first.compare(0, prefix.size(), prefix) == 0) it = g_listFns.erase(it);
        else ++it;
    }
}

void php_gpui_window_close(Variant boxVar) {
    void* win = winOf(boxVar);
    if (!win) return;
    forgetListRenderers(win);
    gpui_shim_close(win);
}

String php_gpui_input_value(Variant boxVar, String id) {
    void* win = winOf(boxVar);
    std::string i(id.data(), id.length());
    char buf[1024];
    buf[0] = '\0';
    if (win && gpui_shim_input_value(win, i.c_str(), buf, (int)sizeof(buf)) > 0)
        return String(buf);
    return String();
}

Bool php_gpui_set_input_value(Variant boxVar, String id, String text) {
    void* win = winOf(boxVar);
    std::string i(id.data(), id.length());
    std::string t(text.data(), text.length());
    if (!win) return false;
    return gpui_shim_set_input_value(win, i.c_str(), t.c_str()) != 0;
}

Bool php_gpui_selftest_fire(Variant boxVar, String id, String type) {
    void* win = winOf(boxVar);
    std::string i(id.data(), id.length());
    std::string t(type.data(), type.length());
    if (!win) return false;
    return gpui_shim_selftest_fire(win, i.c_str(), t.c_str()) != 0;
}

Bool php_gpui_selftest_fire_registered(Variant boxVar, String id, String type) {
    void* win = winOf(boxVar);
    std::string i(id.data(), id.length());
    std::string t(type.data(), type.length());
    if (!win) return false;
    return gpui_shim_selftest_fire_registered(win, i.c_str(), t.c_str()) != 0;
}

Bool php_gpui_snapshot(Variant boxVar, String path, Int w, Int h) {
    void* win = winOf(boxVar);
    std::string p(path.data(), path.length());
    if (!win) return false;
    return gpui_shim_snapshot(win, p.c_str(), (int)w, (int)h) != 0;
}

Array php_gpui_input_probe(Variant boxVar, Int index) {
    Array out;
    void* win = winOf(boxVar);
    double b[5] = {0, 0, 0, 0, 0};
    int n = win ? gpui_shim_input_probe(win, (int) index, b) : -1;
    out["n"] = n;
    out["x"] = (Int) b[0];
    out["y"] = (Int) b[1];
    out["w"] = (Int) b[2];
    out["h"] = (Int) b[3];
    out["hits"] = (Int) b[4];
    return out;
}

Array php_gpui_hit_probe(Variant boxVar, Int index) {
    Array out;
    void* win = winOf(boxVar);
    double b[8] = {0, 0, 0, 0, 0, 0, 0, 0};
    int n = win ? gpui_shim_hit_probe(win, (int) index, b) : -1;
    out["n"] = n;
    out["x"] = (Int) b[0];
    out["y"] = (Int) b[1];
    out["w"] = (Int) b[2];
    out["h"] = (Int) b[3];
    out["id"] = (Int) b[4];
    out["path"] = (Int) b[5];
    out["click"] = (Int) b[6];
    out["parent"] = (Int) b[7];
    return out;
}

Array php_gpui_window_state(Variant boxVar) {
    Array out;
    void* win = winOf(boxVar);
    double st[11] = {0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0};
    int found = win ? gpui_shim_window_state(win, st) : 0;
    out["found"] = found;
    out["active"] = (Bool)(st[0] != 0);
    out["key"] = (Bool)(st[1] != 0);
    out["visible"] = (Bool)(st[2] != 0);
    out["main"] = (Bool)(st[3] != 0);
    out["nsappDeliveries"] = (Int) st[4];
    out["bundled"] = (Bool)(st[5] != 0);
    out["appkitDeliveries"] = (Int) st[6];
    // WindowServer 的在屏账本：物理键鼠只按它派发，0 时真人输入不可能到位。
    out["onScreen"] = (Bool)(st[7] != 0);
    // onScreen 的自证臂：账本里在屏窗口的总条数（非零 = 查询本身查得动）。
    out["onScreenPeers"] = (Int) st[8];
    // 外部协变量：本进程所在 GUI session 此刻在不在控制台上（onScreen 飘的候选解释）。
    out["onConsole"] = (Bool)(st[9] != 0);
    out["sessionId"] = (Int) st[10];
    return out;
}

Int php_gpui_focused_id(Variant boxVar) {
    void* win = winOf(boxVar);
    if (!win) return -1;
    return gpui_shim_focused_id(win);
}

// 把焦点放进一颗已有焦点身份的部件；返回设完后读到的 focused id（0 = 没有身份）。
Int php_gpui_focus_named(Variant boxVar, String id) {
    void* win = winOf(boxVar);
    if (!win) return 0;
    return gpui_shim_focus_named(win, std::string(id.data(), id.length()).c_str());
}

Int php_gpui_activate(Variant boxVar) {
    void* win = winOf(boxVar);
    if (!win) return 0;
    return gpui_shim_activate(win);
}

Array php_gpui_component_failure() {
    Array out;
    char msg[256] = {0};
    long long n = gpui_shim_component_failure(msg, (int) sizeof(msg));
    out["count"] = (Int) n;
    out["last"] = String(msg, (size_t) strlen(msg));
    return out;
}

Int php_gpui_real_mouse(Variant boxVar, Int x, Int y, Int type) {
    void* win = winOf(boxVar);
    if (!win) return 0;
    return gpui_shim_real_mouse(win, (int) x, (int) y, (int) type);
}

Bool php_gpui_real_scroll(Variant boxVar, Int x, Int y, Int deltaY, Int deltaX) {
    void* win = winOf(boxVar);
    return win && gpui_shim_real_scroll(win, (int)x, (int)y, (int)deltaY, (int)deltaX) == 1;
}

Int php_gpui_dispatch_action(Variant boxVar, String name) {
    void* win = winOf(boxVar);
    if (!win) return 0;
    return gpui_shim_dispatch_action(win, std::string(name.data(), name.length()).c_str());
}

Int php_gpui_real_key(Variant boxVar, Int keycode, String chars) {
    void* win = winOf(boxVar);
    if (!win) return 0;
    return gpui_shim_real_key(win, (int) keycode, chars.data());
}

String php_gpui_debug_tree(Variant boxVar) {
    void* win = winOf(boxVar);
    char buf[8192];
    buf[0] = '\0';
    if (win) gpui_shim_debug_tree(win, buf, (int)sizeof(buf));
    return String(buf);
}

String php_gpui_last_error(Variant boxVar) {
    void* win = winOf(boxVar);
    char buf[1024];
    buf[0] = '\0';
    if (win) gpui_shim_last_error(win, buf, (int)sizeof(buf));
    return String(buf);
}

void php_gpui_toast(Variant boxVar, String title, String desc, String level,
                    Int timeoutMs) {
    void* win = winOf(boxVar);
    std::string t(title.data(), title.length());
    std::string d(desc.data(), desc.length());
    std::string l(level.data(), level.length());
    if (win) gpui_shim_toast(win, t.c_str(), d.c_str(), l.c_str(), (int)timeoutMs);
}

Int php_gpui_open_dialog(Variant boxVar, Array tree, Bool escape, Bool backdrop) {
    void* win = winOf(boxVar);
    if (!win) return 0;
    void* dlg = gpui_shim_dialog_begin(win);
    if (!dlg) return 0;
    gpui_shim_render_begin(dlg);
    uint32_t root = emitTree(dlg, tree);
    gpui_shim_render_end(dlg, root);
    return gpui_shim_dialog_show(win, dlg, escape ? 1 : 0, backdrop ? 1 : 0);
}

Int php_gpui_dialog_count(Variant boxVar) {
    void* win = winOf(boxVar);
    return win ? gpui_shim_dialog_count(win) : 0;
}

Int php_gpui_focus_trap(Variant boxVar) {
    void* win = winOf(boxVar);
    return win ? gpui_shim_focus_trap(win) : 0;
}

Bool php_gpui_close_dialog(Variant boxVar) {
    void* win = winOf(boxVar);
    return win && gpui_shim_dialog_close(win) != 0;
}

// PHP 菜单定义 [{name, items:[{label, action?, separator?, disabled?, checked?}]}]
// → GpuiMenu[]。字符串后备用 deque（元素地址跨 push_back 稳定），存活到 shim 调用返回。
Int php_gpui_set_app_menus(Variant boxVar, Array menus) {
    void* win = winOf(boxVar);
    if (!win) return 0;
    std::deque<std::string> strPool;
    const size_t n = menus.count();
    std::vector<GpuiMenu> defs(n);
    std::vector<std::vector<GpuiMenuItem>> items(n);
    for (size_t i = 0; i < n; ++i) {
        Variant mv = menus.get(i);
        if (!mv.isArray()) continue;
        Array m = mv.toArray();
        Variant nameV = m.get("name");
        strPool.push_back(nameV.isString() ? std::string(nameV.toCString()) : std::string());
        const char* namePtr = strPool.back().c_str();
        Variant itemsV = m.get("items");
        if (itemsV.isArray()) {
            Array ia = itemsV.toArray();
            const size_t k = ia.count();
            items[i].assign(k, GpuiMenuItem{});
            for (size_t j = 0; j < k; ++j) {
                Variant iv = ia.get(j);
                if (!iv.isArray()) continue;
                Array it = iv.toArray();
                GpuiMenuItem& g = items[i][j];
                Variant lv = it.get("label");
                strPool.push_back(lv.isString() ? std::string(lv.toCString()) : std::string());
                g.label = strPool.back().c_str();
                Variant av = it.get("action");
                if (av.isString() && av.toCString()[0] != '\0') {
                    strPool.push_back(std::string(av.toCString()));
                    g.action = strPool.back().c_str();
                } else {
                    g.action = nullptr;
                }
                Variant sv = it.get("separator");
                g.separator = (sv.isBool() && sv.toBool()) ? 1 : 0;
                Variant dv = it.get("disabled");
                g.disabled = (dv.isBool() && dv.toBool()) ? 1 : 0;
                Variant cv = it.get("checked");
                g.checked = (cv.isBool() && cv.toBool()) ? 1 : 0;
            }
        }
        GpuiMenu& d = defs[i];
        d.name = namePtr;
        d.items = items[i].empty() ? nullptr : items[i].data();
        d.count = (int)items[i].size();
        Variant disV = m.get("disabled");
        d.disabled = (disV.isBool() && disV.toBool()) ? 1 : 0;
    }
    gpui_shim_set_app_menus(win, n > 0 ? defs.data() : nullptr, (int)n);
    return (Int)(int)n;
}

Int php_gpui_app_menu_count(Variant boxVar) {
    void* win = winOf(boxVar);
    return (Int)(win ? gpui_shim_app_menu_count(win) : 0);
}

Array php_gpui_state_counts(Variant boxVar) {
    static const char* kNames[] = {
        "timerKeys", "timerIds", "callbacks", "cbByKey", "shimCallbacks",
        "inputHandles", "lastValues", "webviews", "inspectors", "notifyLists",
        "dialogs", "watchCtx", "httpResponses", "boxesAlive", "viewsAlive",
        "listCalls", "listItems", "componentStates", "dockStates", "dockPanels",
    };
    Array out;
    long long c[32] = {0};
    void* win = winOf(boxVar);
    int n = win ? gpui_shim_state_counts(win, c, 32) : 0;
    for (int i = 0; i < n && i < (int)(sizeof(kNames) / sizeof(kNames[0])); i++) {
        out[kNames[i]] = (Int)c[i];
    }
    return out;
}

Array php_gpui_menu_rows() {
    Array out;
    char name[128] = {0};
    for (int row = 1; row <= 256; row++) {
        int n = gpui_shim_menu_row_action(row, name, (int) sizeof(name));
        if (n < 0) break;  // 行号越界：表读完了
        if (n == 0) continue;  // 分隔符一类没有动作的行
        out.append(String(name, (size_t) n));
    }
    return out;
}

Variant php_gpui_element_value(Variant boxVar, String id) {
    void* win = winOf(boxVar);
    if (!win) return Variant();
    std::string key(id.data(), id.length());
    double num = 0;
    int boolean = 0;
    char str[512] = {0};
    int kind = gpui_shim_element_value(win, key.c_str(), &num, &boolean, str,
                                       (int)sizeof(str));
    switch (kind) {
        case GPUI_ARG_BOOL:
            return Variant((bool)(boolean != 0));
        case GPUI_ARG_NUM:
            return Variant(num);
        case GPUI_ARG_STR:
            return Variant(String(str, strlen(str)));
        default:
            return Variant();  // 从未上报过：null，不是 false
    }
}

// 回读 spec 里某个元素 id 的 index 槽（TableRow 的行号 / TableHead·TableCell 的列号）。
// 找不到那个 id 返回 -1 —— 与"找到了且 index 为 0"必须能区分开。
Int php_gpui_spec_index(Variant boxVar, String id) {
    void* win = winOf(boxVar);
    if (!win) return (Int)-1;
    std::string key(id.data(), id.length());
    unsigned idx = 0;
    if (!gpui_shim_spec_index_by_id(win, key.c_str(), &idx)) return (Int)-1;
    return (Int)idx;
}

void php_gpui_set_timer(Variant boxVar, String id, Int intervalMs) {    void* win = winOf(boxVar);
    std::string i(id.data(), id.length());
    if (win) gpui_shim_set_timer(win, i.c_str(), (int)intervalMs);
}

// ── 缺口服务：HTTP / 目录监听 / 解压 ─────────────────────────────────────

Bool php_gpui_http_get(Variant boxVar, String id, String url) {
    void* win = winOf(boxVar);
    std::string i(id.data(), id.length());
    std::string u(url.data(), url.length());
    return win && gpui_shim_http_get(win, i.c_str(), u.c_str()) != 0;
}

// 读一条已落地的应答并把它丢掉（一次性语义：读完就 free，省得 PHP 侧记着收尸）。
// body 是二进制的，所以走 String(ptr,len)，不能靠 '\0' 定界。
Array php_gpui_http_response(Variant boxVar, String id) {
    void* win = winOf(boxVar);
    Array out;
    std::string i(id.data(), id.length());
    if (!win) return out;
    int status = 0, ok = 0;
    char ct[128];
    ct[0] = '\0';
    int need = gpui_shim_http_response(win, i.c_str(), nullptr, 0, &status, &ok,
                                       ct, (int)sizeof(ct));
    if (need < 0) return out;  // 没有这条
    std::vector<char> body(static_cast<size_t>(need) + 1, '\0');
    int got = gpui_shim_http_response(win, i.c_str(), body.data(),
                                      (int)body.size(), &status, &ok, ct,
                                      (int)sizeof(ct));
    gpui_shim_http_free(win, i.c_str());
    out.set("ok", Variant(ok != 0));
    out.set("status", Variant(status));
    out.set("contentType", Variant(ct));
    out.set("body",
            Variant(String(body.data(), static_cast<size_t>(got > 0 ? got : 0))));
    return out;
}

Int php_gpui_watch_dir(Variant boxVar, String id, String dir, Bool create) {
    void* win = winOf(boxVar);
    std::string i(id.data(), id.length());
    std::string d(dir.data(), dir.length());
    return win ? gpui_shim_watch_dir(win, i.c_str(), d.c_str(), create ? 1 : 0)
               : 0;
}

void php_gpui_unwatch_dir(Variant boxVar, Int watchId) {
    void* win = winOf(boxVar);
    if (win) gpui_shim_unwatch_dir(win, (int)watchId);
}

Int php_gpui_watch_fake(Variant boxVar, String id) {
    void* win = winOf(boxVar);
    std::string i(id.data(), id.length());
    return win ? gpui_shim_watch_fake(win, i.c_str()) : 0;
}

// ['ok'=>bool,'data'=>string,'error'=>string]；data 二进制安全，同上。
Array php_gpui_inflate(String data, Bool gzip) {
    std::string in(data.data(), data.length());
    Array out;
    char err[256] = {};
    err[0] = '\0';
    int need = gpui_shim_inflate(in.data(), (int)in.size(), gzip ? 1 : 0, nullptr,
                                 0, err, (int)sizeof(err));
    if (need < 0) {
        out.set("ok", Variant(false));
        out.set("data", Variant(String("", (size_t)0)));
        out.set("error", Variant(err));
        return out;
    }
    std::vector<char> buf(static_cast<size_t>(need) + 1, '\0');
    int got = gpui_shim_inflate(in.data(), (int)in.size(), gzip ? 1 : 0,
                                buf.data(), (int)buf.size(), err,
                                (int)sizeof(err));
    out.set("ok", Variant(true));
    out.set("data",
            Variant(String(buf.data(), static_cast<size_t>(got > 0 ? got : 0))));
    out.set("error", Variant(String("", (size_t)0)));
    return out;
}

// 系统状态快照。topProcs>0 时才带进程列表（gpui 一次采样列出全机几百个进程，
// 全搬过边界没有意义，所以 top N 交给 gpui 自己排）。上限 256 条。
// 无窗口参数：这是机器级的读数，而且采样状态在 shim 里是进程内一份。
Array php_gpui_sys_info(Int topProcs) {
    Array out;
    int top = (int)topProcs;
    if (top < 0) top = 0;
    if (top > 256) top = 256;
    GpuiSysInfo si{};
    std::vector<GpuiProcess> procs;
    if (top > 0) procs.assign(static_cast<size_t>(top), GpuiProcess{});
    int n = 0;
    if (!gpui_shim_sys_refresh(&si, procs.empty() ? nullptr : procs.data(),
                               (int)procs.size(), top, &n)) {
        return out;
    }
    out.set("cpu", Variant(si.cpu));
    out.set("memPct", Variant(si.memPct));
    out.set("memTotal", Variant((long long)si.memTotalBytes));
    out.set("memUsed", Variant((long long)si.memUsedBytes));
    out.set("diskPct", Variant(si.diskPct));
    out.set("diskTotal", Variant((long long)si.diskTotalBytes));
    out.set("diskUsed", Variant((long long)si.diskUsedBytes));
    out.set("ncpu", Variant(si.ncpu));
    out.set("batteryPresent", Variant(si.batteryPresent != 0));
    out.set("batteryCharging", Variant(si.batteryCharging != 0));
    out.set("batteryPct", Variant(si.batteryPct));
    Array list;
    for (int i = 0; i < n; i++) {
        Array p;
        p.set("pid", Variant(procs[i].pid));
        // name 指向 gpui 自己的数组，只活到下一次采样：这里立刻拷成 PHP 字符串。
        p.set("name", Variant(String(procs[i].name ? procs[i].name : "")));
        p.set("cpu", Variant(procs[i].cpu));
        p.set("memory", Variant((long long)procs[i].memoryBytes));
        list.append(Variant(p));
    }
    out.set("procs", Variant(list));
    return out;
}

void php_gpui_set_theme(Variant boxVar, String mode) {
    void* win = winOf(boxVar);
    std::string m(mode.data(), mode.length());
    gpui_shim_set_theme(win, m.c_str());
}

// ── 系统能力（见 cpp-src/gpui_shim.h 同名段落）──────────────────────────
// 空值语义统一在 PHP 层翻译：shim/bridge 只回 ""，App 把 "" 读成 null。

String php_gpui_clipboard_text(Variant boxVar) {
    void* win = winOf(boxVar);
    // 粘贴板内容没有长度上限，缓冲开在堆上（64 KiB 之外截断）。
    std::vector<char> buf(65536);
    buf[0] = '\0';
    if (win) gpui_shim_clipboard_get_text(win, buf.data(), (int)buf.size());
    return String(buf.data());
}

void php_gpui_set_clipboard_text(Variant boxVar, String text) {
    void* win = winOf(boxVar);
    std::string t(text.data(), text.length());
    if (win) gpui_shim_clipboard_set_text(win, t.c_str());
}

// ['flags'=>int,'text'=>string,'image'=>string,'paths'=>array]
// flags：1=有文本 2=有图片 4=有文件路径。
//
// 读取次数：没有图片时一次搞定（文本/路径给 64 KiB 的宽松缓冲，只探图片长度）。
// 有图片或被截断时必须再来一趟——而那一趟是**整笔重取**（文本/路径一起按真实长度重读），
// 不是只补图片：混用两个快照会给出"上一份的文本 + 这一份的图"。
Array php_gpui_clipboard_item(Variant boxVar) {
    void* win = winOf(boxVar);
    Array out;
    if (!win) return out;
    constexpr int kWide = 65536;
    std::vector<char> text(kWide), paths(kWide), image(1, '\0');
    int tLen = 0, iLen = 0, pLen = 0;
    int flags = gpui_shim_clipboard_item(win, text.data(), kWide, &tLen, nullptr, 0,
                                         &iLen, paths.data(), kWide, &pLen);
    if (iLen > 0 || tLen >= kWide || pLen >= kWide) {
        int capT = tLen > 0 ? tLen : 1;
        int capP = pLen > 0 ? pLen : 1;
        text.resize(capT);
        paths.resize(capP);
        image.resize(iLen > 0 ? iLen : 1);
        flags = gpui_shim_clipboard_item(win, text.data(), capT, &tLen,
                                         image.data(), iLen, &iLen, paths.data(),
                                         capP, &pLen);
    }
    out.set("flags", Variant(flags));
    out.set("text", Variant(String(text.data(), (size_t)(tLen > 0 ? tLen : 0))));
    out.set("image", Variant(String(image.data(), (size_t)(iLen > 0 ? iLen : 0))));
    // 换行分隔 → 数组；空段不算一条路径。
    Array list;
    std::string joined(paths.data(), pLen > 0 ? (size_t)pLen : 0);
    size_t at = 0;
    while (at < joined.size()) {
        size_t nl = joined.find('\n', at);
        if (nl == std::string::npos) nl = joined.size();
        if (nl > at) list.append(Variant(String(joined.data() + at, nl - at)));
        at = nl + 1;
    }
    out.set("paths", Variant(list));
    return out;
}

// ⚠ 自带嵌套消息循环：只能在事件回调里调，render 期间调用会重入平台模态循环。
String php_gpui_prompt_for_path(Variant boxVar, Bool directories, String title) {
    void* win = winOf(boxVar);
    char buf[4096];
    buf[0] = '\0';
    std::string t(title.data(), title.length());
    if (win)
        gpui_shim_prompt_for_path(win, directories ? 1 : 0, t.c_str(), buf,
                                  (int)sizeof(buf));
    return String(buf);
}

// 无窗口参数：OpenUrl 是 app 级动作，gpui 自己也不看窗口。
Bool php_gpui_open_url(String url) {
    std::string u(url.data(), url.length());
    return gpui_shim_open_url(u.c_str()) != 0;
}

void php_gpui_toggle_inspector(Variant boxVar) {
    void* win = winOf(boxVar);
    if (win) gpui_shim_toggle_inspector(win);
}

Bool php_gpui_show_fps(Variant boxVar, String anchor) {
    void* win = winOf(boxVar);
    std::string a(anchor.data(), anchor.length());
    return win && gpui_shim_show_fps(win, a.c_str()) != 0;
}

Bool php_gpui_hide_fps(Variant boxVar) {
    void* win = winOf(boxVar);
    return win && gpui_shim_hide_fps(win) != 0;
}

Bool php_gpui_fps_visible(Variant boxVar) {
    void* win = winOf(boxVar);
    return win && gpui_shim_fps_visible(win) != 0;
}

// Sheet 与 Dialog 同族：内容 Box2 由 dialog_begin 建，sheet_show 打开它。
Int php_gpui_open_sheet(Variant boxVar, Array tree, String anchor) {
    void* win = winOf(boxVar);
    if (!win) return 0;
    void* dlg = gpui_shim_dialog_begin(win);
    if (!dlg) return 0;
    gpui_shim_render_begin(dlg);
    uint32_t root = emitTree(dlg, tree);
    gpui_shim_render_end(dlg, root);
    std::string a(anchor.data(), anchor.length());
    return gpui_shim_sheet_show(win, dlg, a.c_str());
}

Bool php_gpui_close_sheet(Variant boxVar) {
    void* win = winOf(boxVar);
    return win && gpui_shim_close_sheet(win) != 0;
}

Bool php_gpui_has_sheet(Variant boxVar) {
    void* win = winOf(boxVar);
    return win && gpui_shim_has_sheet(win) != 0;
}

// 历史导航：dir<0 后退、dir>0 前进。该 id 还没有 webview 时返回 false。
Bool php_gpui_webview_nav(Variant boxVar, String id, Int dir) {
    void* win = winOf(boxVar);
    std::string i(id.data(), id.length());
    return win && gpui_shim_webview_nav(win, i.c_str(), (int)dir) != 0;
}

// 控件自己报的状态：['ok'=>bool,'url'=>string,'w'=>float,'h'=>float]。
Array php_gpui_webview_probe(Variant boxVar, String id) {
    void* win = winOf(boxVar);
    std::string i(id.data(), id.length());
    char url[1024];
    double w = 0, h = 0;
    bool ok = win && gpui_shim_webview_probe(win, i.c_str(), url, (int)sizeof(url),
                                             &w, &h) != 0;
    Array out;
    out.set("ok", Variant(ok));
    out.set("url", Variant(ok ? url : ""));
    out.set("w", Variant(w));
    out.set("h", Variant(h));
    return out;
}

// ── 应用内通知中心 ───────────────────────────────────────────────────────
// ── 授权文件系统 / 进程 / 存储 ────────────────────────────────────────────────
//
// 大块字节走 shim 的进程级 scratch，这里在同一个调用栈里立刻拷成 php::String，
// 所以 PHP 拿到的是副本。错误文本统一回传成第二个返回值之外的 err 串。

namespace {

// scratch → php::String
php::String ScratchToString() {
    const char* d = nullptr;
    int len = 0;
    gpui_shim_out(&d, &len);
    if (!d || len <= 0) return php::String();
    return php::String(d, (size_t)len);
}

// 注意顺序：**不能**先清 buf 再读它。早先的版本写的是 buf[0]='\0' 之后
// return std::string(buf)，等于把 shim 刚写进来的失败原因擦掉再返回空串。
std::string ErrText(char* buf, int cap) {
    (void)cap;
    return std::string(buf);
}

}  // namespace

// ['ok'=>bool,'data'=>string,'error'=>string]
Array php_gpui_fs_read(String path) {
    std::string p(path.data(), path.length());
    char err[256] = {};
    Array out;
    if (gpui_shim_fs_read(p.c_str(), err, (int)sizeof(err))) {
        out["ok"] = true;
        out["data"] = ScratchToString();
    } else {
        out["ok"] = false;
    }
    out["error"] = ErrText(err, (int)sizeof(err));
    return out;
}

Array php_gpui_fs_write(String path, String data) {
    std::string p(path.data(), path.length());
    std::string d(data.data(), data.length());
    char err[256] = {};
    Array out;
    out["ok"] = gpui_shim_fs_write(p.c_str(), d.data(), (int)d.size(), err,
                                   (int)sizeof(err))
                     ? true
                     : false;
    out["error"] = ErrText(err, (int)sizeof(err));
    return out;
}

Array php_gpui_fs_list(String path) {
    std::string p(path.data(), path.length());
    char err[256] = {};
    Array out;
    std::vector<GpuiFsEntry> buf(2048);
    int total = 0;
    if (!gpui_shim_fs_list(p.c_str(), buf.data(), (int)buf.size(), &total, err,
                           (int)sizeof(err))) {
        out["ok"] = false;
        out["error"] = ErrText(err, (int)sizeof(err));
        out["entries"] = Array();
        return out;
    }
    Array entries;
    const int n = std::min<int>(total, (int)buf.size());
    for (int i = 0; i < n; i++) {
        Array e;
        e["name"] = php::String(buf[(size_t)i].name ? buf[(size_t)i].name : "");
        e["dir"] = buf[(size_t)i].is_dir ? true : false;
        entries.append(e);
    }
    out["ok"] = true;
    out["entries"] = entries;
    out["truncated"] = total > n ? true : false;
    out["error"] = ErrText(err, (int)sizeof(err));
    return out;
}

Bool php_gpui_fs_exists(String path) {
    std::string p(path.data(), path.length());
    return gpui_shim_fs_exists(p.c_str()) ? true : false;
}

Array php_gpui_fs_remove(String path, Bool dir, Bool recursive) {
    std::string p(path.data(), path.length());
    char err[256] = {};
    Array out;
    out["ok"] = gpui_shim_fs_remove(p.c_str(), dir ? 1 : 0, recursive ? 1 : 0, err,
                                    (int)sizeof(err))
                     ? true
                     : false;
    out["error"] = ErrText(err, (int)sizeof(err));
    return out;
}

Array php_gpui_fs_mkdir(String path, Bool dir) {
    std::string p(path.data(), path.length());
    char err[256] = {};
    Array out;
    out["ok"] = gpui_shim_fs_mkdir(p.c_str(), dir ? 1 : 0, err, (int)sizeof(err))
                     ? true
                     : false;
    out["error"] = ErrText(err, (int)sizeof(err));
    return out;
}

Int php_gpui_grant_root(String root, Bool writable) {
    std::string r(root.data(), root.length());
    return gpui_shim_grant_root(r.c_str(), writable ? 1 : 0);
}

Int php_gpui_grant_execute(Array commands, Bool unrestricted) {
    std::vector<std::string> store;
    store.reserve((size_t)commands.count());
    for (size_t i = 0; i < (size_t)commands.count(); i++) {
        store.push_back(strOf(commands.get((int)i)));
    }
    std::vector<const char*> ptrs;
    ptrs.reserve(store.size());
    for (const std::string& v : store) ptrs.push_back(v.c_str());
    return gpui_shim_grant_execute(ptrs.empty() ? nullptr : ptrs.data(),
                                   (int)ptrs.size(), unrestricted ? 1 : 0);
}

void php_gpui_grant_storage(Bool allowed) { gpui_shim_grant_storage(allowed ? 1 : 0); }

Array php_gpui_process_run(String command, Array args) {
    std::string c(command.data(), command.length());
    std::vector<std::string> store;
    store.reserve((size_t)args.count());
    for (size_t i = 0; i < (size_t)args.count(); i++) {
        store.push_back(strOf(args.get((int)i)));
    }
    std::vector<const char*> ptrs;
    ptrs.reserve(store.size());
    for (const std::string& v : store) ptrs.push_back(v.c_str());
    char err[256] = {};
    int code = -1;
    Array out;
    if (gpui_shim_process_run(c.c_str(), ptrs.empty() ? nullptr : ptrs.data(),
                              (int)ptrs.size(), &code, err, (int)sizeof(err))) {
        out["ok"] = true;
        out["code"] = code;
        out["out"] = ScratchToString();
        const char* d = nullptr;
        int len = 0;
        gpui_shim_process_err(&d, &len);
        out["err"] = (d && len > 0) ? php::String(d, (size_t)len) : php::String();
    } else {
        out["ok"] = false;
        out["code"] = -1;
    }
    out["error"] = ErrText(err, (int)sizeof(err));
    return out;
}

Array php_gpui_storage_path(String path) {
    std::string p(path.data(), path.length());
    char err[256] = {};
    Array out;
    out["ok"] = gpui_shim_storage_path(p.c_str(), err, (int)sizeof(err)) ? true : false;
    out["error"] = ErrText(err, (int)sizeof(err));
    return out;
}

Array php_gpui_storage_set(String key, String value) {
    std::string k(key.data(), key.length());
    std::string v(value.data(), value.length());
    char err[256] = {};
    Array out;
    out["ok"] = gpui_shim_storage_set(k.c_str(), v.data(), (int)v.size(), err,
                                      (int)sizeof(err))
                     ? true
                     : false;
    out["error"] = ErrText(err, (int)sizeof(err));
    return out;
}

Array php_gpui_storage_get(String key) {
    std::string k(key.data(), key.length());
    Array out;
    if (gpui_shim_storage_get(k.c_str())) {
        out["ok"] = true;
        out["value"] = ScratchToString();
    } else {
        out["ok"] = false;
    }
    return out;
}

Bool php_gpui_storage_remove(String key) {
    std::string k(key.data(), key.length());
    char err[128] = {};
    return gpui_shim_storage_remove(k.c_str(), err, (int)sizeof(err)) ? true : false;
}

Int php_gpui_storage_len() { return gpui_shim_storage_len(); }

Bool php_gpui_storage_granted() { return gpui_shim_storage_granted() ? true : false; }

// ── macOS 系统集成（托盘 / 全局快捷键 / Dock 角标 / OS 通知）─────────────────
//
// labels 的 C 数组只在本次调用内被读（.mm 立刻拷成 NSString），所以栈上的
// std::string 缓冲活到返回是够的。

Int php_gpui_tray_add(Variant boxVar, String elemId, String tooltip, Array labels) {
    void* win = winOf(boxVar);
    if (!win) return 0;
    std::string e(elemId.data(), elemId.length());
    std::string t(tooltip.data(), tooltip.length());
    std::vector<std::string> store;
    store.reserve((size_t)labels.count());
    for (size_t i = 0; i < (size_t)labels.count(); i++) {
        store.push_back(strOf(labels.get((int)i)));
    }
    std::vector<const char*> ptrs;
    ptrs.reserve(store.size());
    for (const std::string& v : store) ptrs.push_back(v.c_str());
    return gpui_shim_tray_add(win, e.c_str(), t.c_str(),
                              ptrs.empty() ? nullptr : ptrs.data(),
                              static_cast<int>(ptrs.size()));
}

void php_gpui_tray_remove(Variant boxVar, Int trayId) {
    void* win = winOf(boxVar);
    if (win) gpui_shim_tray_remove(win, (int)trayId);
}

Int php_gpui_tray_count() { return gpui_shim_tray_count(); }

Int php_gpui_hotkey_register(Variant boxVar, String elemId, Int keycode, Int mods) {
    void* win = winOf(boxVar);
    if (!win) return 0;
    std::string e(elemId.data(), elemId.length());
    return gpui_shim_hotkey_register(win, e.c_str(), (unsigned)keycode, (unsigned)mods);
}

void php_gpui_hotkey_unregister(Int id) { gpui_shim_hotkey_unregister((int)id); }

Int php_gpui_hotkey_count() { return gpui_shim_hotkey_count(); }

// 测试接缝：合成一条 hotkey 事件，验整条派发链（不验系统级全局捕获）。
Bool php_gpui_hotkey_fire(Int index) { return gpui_shim_hotkey_fire((int)index) ? true : false; }

void php_gpui_dock_badge(String text) {
    std::string t(text.data(), text.length());
    gpui_shim_dock_badge(t.c_str());
}

Bool php_gpui_os_notify(String title, String body) {
    std::string t(title.data(), title.length());
    std::string b(body.data(), body.length());
    return gpui_shim_os_notify(t.c_str(), b.c_str()) ? true : false;
}

Int php_gpui_notify(Variant boxVar, String id, String title, String message,
                    String type, String placement, Int timeoutMs) {
    void* win = winOf(boxVar);
    if (!win) return 0;
    std::string i(id.data(), id.length());
    std::string t(title.data(), title.length());
    std::string m(message.data(), message.length());
    std::string k(type.data(), type.length());
    std::string p(placement.data(), placement.length());
    return gpui_shim_notify(win, i.c_str(), t.c_str(), m.c_str(), k.c_str(),
                            p.c_str(), (int)timeoutMs);
}

Bool php_gpui_dismiss_notification(Variant boxVar, Int notifyId) {
    void* win = winOf(boxVar);
    return win && gpui_shim_notify_dismiss(win, (int)notifyId) != 0;
}

void php_gpui_clear_notifications(Variant boxVar) {
    void* win = winOf(boxVar);
    if (win) gpui_shim_notify_clear(win);
}

Int php_gpui_notification_count(Variant boxVar) {
    void* win = winOf(boxVar);
    return win ? gpui_shim_notify_count(win) : 0;
}
