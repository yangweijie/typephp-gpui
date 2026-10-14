// cpp-src/gpui_shim_services.inc —— 缺口服务：HTTP / 目录监听 / 解压
//
// gpui_shim.cpp 的**文本切片**（理由见 gpui_shim_caps.inc 头部：gpui.h 32 万行，
// 多一个 TU 就多一次全量编译；拆文件只为缩小"要同时装在脑子里"的范围）。
// 只能被 gpui_shim.cpp 在其前文之后 include。

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
    so.name = cppStr(name);
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
    so.name = cppStr(name);
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
    so.name = cppStr(name);
    so.args = tmp.data();
    so.argCount = argc;
    box->specs.PushOp(spec, so);
}

int gpui_shim_window_state(void* win, double* out) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !out) return 0;
    for (int i = 0; i < 6; i++) out[i] = 0;
    return gpui_mac_window_state(out);
}

int gpui_shim_activate(void* win) {
    auto* box = static_cast<Box2*>(win);
    if (!box) return 0;
    return gpui_mac_activate();
}

int gpui_shim_focused_id(void* win) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !box->window) return -1;
    return gpui::WindowFocusedId(box->window);
}

void gpui_shim_set_error(void* win, const char* msg) {
    auto* box = static_cast<Box2*>(win);
    if (box && msg) box->lastError = msg;
}


int gpui_shim_input_probe(void* win, int index, double* out) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !box->window) return -1;
    auto& inputs = box->window->paint.inputs;
    int i = index < 0 ? inputs.len - 1 : index;
    if (out && i >= 0 && i < inputs.len) {
        const gpui::Bounds b = inputs[i]->inputBounds;
        out[0] = b.x;
        out[1] = b.y;
        out[2] = b.w;
        out[3] = b.h;
    }
    /* out[4] = 命中矩形条数。真实点击先过 HitTestRect(ctx->hits) 才轮得到 behavior/回调，
     * 所以"监听器还在（合成缝投得进）但鼠标点不动"这种组合，只有直接读这张表才能分辨：
     * 表空/变短 = 这一帧的绘制没把命中表重建，问题在 paint 侧；表满而仍不响应 = 坐标换算侧。 */
    if (out) out[4] = box->window->paint.hits.len;
    return inputs.len;
}

long long gpui_shim_component_failure(char* out, int outlen) {
    if (out && outlen > 0) out[0] = '\0';
    if (!g_runtime) return 0;
    uint64_t n = g_runtime->ComponentFailureCount();
    gpui::Str msg = g_runtime->LastComponentFailure();
    if (out && outlen > 0 && msg.s) {
        int m = msg.len < outlen - 1 ? msg.len : outlen - 1;
        std::memcpy(out, msg.s, m);
        out[m] = '\0';
    }
    return (long long)n;
}

int gpui_shim_hit_probe(void* win, int index, double* out) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !box->window) return -1;
    auto& hits = box->window->paint.hits;
    int i = index < 0 ? hits.len - 1 : index;
    if (out && i >= 0 && i < hits.len) {
        const gpui::HitRect& h = hits[i];
        out[0] = h.bounds.x;
        out[1] = h.bounds.y;
        out[2] = h.bounds.w;
        out[3] = h.bounds.h;
        out[4] = h.id;
        out[5] = h.pathId;
        out[6] = h.listener.fn != 0 ? 1 : 0;
        out[7] = h.parent;
    }
    return hits.len;
}

int gpui_shim_real_mouse(void* win, int x, int y, int type) {
    auto* box = static_cast<Box2*>(win);
    if (!box) return 0;
    const size_t before = box->queue.size();
    if (type == 1) {
        if (!gpui_mac_send_mouse(x, y, 1, 1)) return 0;
        gpui_mac_send_mouse(x, y, 2, 1);
    } else if (type == 2) {
        // 只按下（不抬起）：拖拽的前半。gpui_mac_send_mouse 的 type 1/2 就是
        // LeftMouseDown/Up，这里只是不再把它们焊成一次单击。
        // 按下与抬起**回的是"事件送进窗口了没有"**，不是"队列多没多一条"——
        // 分隔条上本来就没有 PHP 回调，按下当然不会入队；拿入队当判据会让每次拖都假阴。
        return gpui_mac_send_mouse(x, y, 1, 1);
    } else if (type == 3) {
        return gpui_mac_send_mouse(x, y, 2, 1);
    } else if (!gpui_mac_send_mouse(x, y, 0, 0)) {
        return 0;
    }
    return box->queue.size() > before ? 1 : 0;
}

int gpui_shim_dispatch_action(void* win, const char* name) {
    auto* box = static_cast<Box2*>(win);
    if (!box || !box->window || !name || !*name) return 0;
    std::string id(name);
    // 两次解析，因为上游有两张名字表：
    //  · shell 那套（菜单项、脚本声明的动作）由 ShellActionOf intern，它会给名字
    //    **加 `shell::` 前缀**；
    //  · 内建动作（`ui::Confirm` / `ui::Cancel` / `ui::SelectUp`…）由 base/actions.cpp 的
    //    GPUI_ACTION 宏用**不带前缀**的名字 intern。
    // 只走第一次的话，'ui::Confirm' 会变成 'shell::ui::Confirm' —— 一个谁都没监听的新 id，
    // 于是 dispatch 返回 false，看起来像"动作派发这条路不通"，实际是查错了表
    // （#73 里就是这么假阴的）。第一次命中就返回，绝不重复投递。
    if (gpui::WindowDispatchAction(box->window,
            gpui::shell::ShellActionOf(cppStr(id)))) {
        return 1;
    }
    return gpui::WindowDispatchAction(box->window, gpui::ActionOf(cppStr(id))) ? 1 : 0;
}

int gpui_shim_real_key(void* win, int keycode, const char* chars) {
    auto* box = static_cast<Box2*>(win);
    if (!box) return 0;
    const size_t before = box->queue.size();
    if (!gpui_mac_send_key((unsigned short) keycode, chars ? chars : "")) return 0;
    return box->queue.size() > before ? 1 : 0;
}

uint64_t gpui_shim_callback(void* win, uint32_t spec, const char* elem_id,
                            const char* type) {
    auto* box = static_cast<Box2*>(win);
    uint64_t cb = box->newCallback(elem_id, type);
    gpui::shell::SpecOp so;
    so.kind = gpui::shell::SpecOpKind::Callback;
    // 两个名字必须分开：队列里的 type 用**短名**（"click"）对齐 PHP 的 on(id,'click')，
    // 但 SpecOp.name 必须是 materialize 认识的**长名**（"on_click"）——ResolveBehavior
    // 逐字比较 op.name 与 "on_click"/"on_hover"/"on_key_down"（materialize.cpp:233-290），
    // 名字对不上就不挂 gpui 的监听器。bridge 传的是短名，所以这里补回前缀。
    // 补前缀之前，真实鼠标点击/hover/按键一个都到不了 PHP：自检走的是 gpui_shim_test_event
    // 那条合成缝（直接按 cbid 入队，绕过 behavior），所以 21 条链路全绿也照不出这个洞。
    // 同样用复用缓冲：原来 `std::string opName(type)` + `"on_" + opName` 每次再多两次分配。
    std::string& opName = box->scratchOpName;
    opName.assign(type ? type : "");
    if (opName.rfind("on_", 0) != 0) opName.insert(0, "on_");
    so.name = cppStr(opName.c_str());
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
    so.name = cppStr(slot_name);
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
    if (desc->name && strcmp(desc->name, "MenuItem") == 0 && schemaN > 1 &&
        arguments[1].kind == gpui::shell::ComponentArgumentKind::String) {
        // 处理器登记在 app 级而不是元素上：菜单动作先走焦点元素的链，没人接才落到
        // AppOnAction。实测挂在根元素上的 El::OnAction 收不到（WindowDispatchAction
        // 直接返回 false），而 app 级这一层是上游给"没有元素要的动作"留的位置。
        registerMenuAction(box, arguments[1].string);
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
    so.name = cppStr(name);
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
    uint64_t boxCb = box->newCallback(elem_id, type);
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
