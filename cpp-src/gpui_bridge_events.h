#ifndef GPUI_BRIDGE_EVENTS_H_
#define GPUI_BRIDGE_EVENTS_H_
/* typephp-gpui — ScriptView 事件入队辅助。
 *
 * cmd/patch-view.sh 把 view.cpp 里的 DispatchClick/DispatchChange/... 替换成
 * 这里的 gpuiEnqueue*()。实现在 cpp-src/gpui_bridge.cc：按 self->self(EntityId)
 * 找到所属 GpuiBox，把 {type, id, value} push 进它的 event queue。
 *
 * 这些函数必须在 namespace gpui 内声明（view.cpp 的调用点在 namespace 里）。 */

#include <cstdint>

namespace gpui {
struct ScriptView;
struct Ctx;

// 由 bridge 实现。self 非空、cx 有效时入队一条事件。
void gpuiEnqueueClick(ScriptView* self, Ctx* cx, uint64_t callbackId, int clickCount);
void gpuiEnqueueChange(ScriptView* self, Ctx* cx, uint64_t callbackId, bool checked);
void gpuiEnqueueHover(ScriptView* self, Ctx* cx, uint64_t callbackId, bool hovered);
// cmd/patch-select-view.sh 的两个入口：部件 binding 里存的那批回调（open_change 走
// gpuiEnqueueChange 带 bool，confirm/dismiss/dock 走无载荷的 Signal，step 走 String）。
void gpuiEnqueueSignal(ScriptView* self, Ctx* cx, uint64_t callbackId);
void gpuiEnqueueString(ScriptView* self, Ctx* cx, uint64_t callbackId,
                       const char* value);
void gpuiEnqueueInput(ScriptView* self, Ctx* cx, uint64_t handle, int eventKind);
void gpuiEnqueueSlider(ScriptView* self, Ctx* cx, uint64_t handle, float value);
// cmd/patch-mouse-view.sh 的三个指针事件入口（强符号在 cpp-src/gpui_shim_events.inc，
// 值的 CSV 字段顺序也在那里定义）。button/pressed 是上游 gpui.h 的 MouseButton 序号，
// mods 是位掩码 control=1 alt=2 shift=4 platform=8 function=16。
void gpuiEnqueueMouse(ScriptView* self, Ctx* cx, uint64_t callbackId, int button,
                      float x, float y, int clicks, int mods);
void gpuiEnqueueScroll(ScriptView* self, Ctx* cx, uint64_t callbackId, float dx,
                       float dy, float x, float y, int mods);
void gpuiEnqueueMove(ScriptView* self, Ctx* cx, uint64_t callbackId, float x,
                     float y, int pressed, int button, int mods);

} // namespace gpui
#endif // GPUI_BRIDGE_EVENTS_H_
