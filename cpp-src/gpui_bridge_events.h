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
void gpuiEnqueueInput(ScriptView* self, Ctx* cx, uint64_t handle, int eventKind);
void gpuiEnqueueSlider(ScriptView* self, Ctx* cx, uint64_t handle, float value);

} // namespace gpui
#endif // GPUI_BRIDGE_EVENTS_H_
