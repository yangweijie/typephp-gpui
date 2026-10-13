// cpp-src/gpui_shim_events.inc —— patched view.cpp 的事件入队（强符号）
//
// gpui_shim.cpp 的**文本切片**（理由见 gpui_shim_caps.inc 头部）。必须在主 TU 的
// g_views / g_windowBoxes / Box2 之后 include：这里就是拿这些做反查的。

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

// cmd/patch-key-view.sh 在 ScriptView::OnScriptKey 里调过来：键事件不再去碰 QuickJS，
// 字符码点就地编成 UTF-8 入队。key_down 与 key_up 各自有自己的 callbackId，所以类型
// 由 id 反查决定，down 这里只作记录（不额外造第二种事件）。
void gpuiEnqueueKey(ScriptView* self, Ctx*, uint64_t callbackId, unsigned rune,
                    const char* keyName, int down) {
    (void)down;
    Box2* box = boxForView((void*)self);
    if (!box) return;
    // 有字符就报字符（输入法/字符事件），否则报键名（key_down/key_up 携带 vk）。
    if (!rune) {
        box->enqueue(callbackId, GPUI_ARG_STR, 0, 0, keyName ? keyName : "");
        return;
    }
    char buf[5] = {0, 0, 0, 0, 0};
    int n = 0;
    if (rune < 0x80) {
        buf[n++] = (char)rune;
    } else if (rune < 0x800) {
        buf[n++] = (char)(0xC0 | (rune >> 6));
        buf[n++] = (char)(0x80 | (rune & 0x3F));
    } else if (rune < 0x10000) {
        buf[n++] = (char)(0xE0 | (rune >> 12));
        buf[n++] = (char)(0x80 | ((rune >> 6) & 0x3F));
        buf[n++] = (char)(0x80 | (rune & 0x3F));
    } else {
        buf[n++] = (char)(0xF0 | (rune >> 18));
        buf[n++] = (char)(0x80 | ((rune >> 12) & 0x3F));
        buf[n++] = (char)(0x80 | ((rune >> 6) & 0x3F));
        buf[n++] = (char)(0x80 | (rune & 0x3F));
    }
    box->enqueue(callbackId, GPUI_ARG_STR, 0, 0, buf);
}

// cmd/patch-mouse-view.sh 的三个宿主入口。值统一编成一串 CSV 交给 PHP：
// GpuiEvent 只有一个 value 槽（NUM/BOOL/STR 三选一），而指针事件天然要一次给
// 坐标 + 按钮 + 连击 + 修饰键，所以这里定的是**字段顺序**而不是类型：
//   mouse → "x,y,button,clicks,mods"   move → "x,y,pressed,button,mods"
//   scroll → "dx,dy,x,y,mods"
// mods 是位掩码：control=1 alt=2 shift=4 platform(Command)=8 function(Fn)=16，
// button 是 gpui MouseButton 的序号（Left=0 Right=1 Middle=2 …），
// 这两个枚举值都在上游 gpui.h 里，不在这个仓库里，所以文档必须写清含义。
void gpuiEnqueueMouse(ScriptView* self, Ctx*, uint64_t callbackId, int button,
                      float x, float y, int clicks, int mods) {
    char buf[64];
    std::snprintf(buf, sizeof(buf), "%.2f,%.2f,%d,%d,%d", x, y, button, clicks, mods);
    if (Box2* box = boxForView((void*)self))
        box->enqueue(callbackId, GPUI_ARG_STR, 0, 0, buf);
}

void gpuiEnqueueScroll(ScriptView* self, Ctx*, uint64_t callbackId, float dx,
                       float dy, float x, float y, int mods) {
    char buf[64];
    std::snprintf(buf, sizeof(buf), "%.2f,%.2f,%.2f,%.2f,%d", dx, dy, x, y, mods);
    if (Box2* box = boxForView((void*)self))
        box->enqueue(callbackId, GPUI_ARG_STR, 0, 0, buf);
}

void gpuiEnqueueMove(ScriptView* self, Ctx*, uint64_t callbackId, float x, float y,
                     int pressed, int button, int mods) {
    char buf[64];
    std::snprintf(buf, sizeof(buf), "%.2f,%.2f,%d,%d,%d", x, y, pressed, button, mods);
    if (Box2* box = boxForView((void*)self))
        box->enqueue(callbackId, GPUI_ARG_STR, 0, 0, buf);
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
