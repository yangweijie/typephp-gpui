/* typephp-gpui — C-ABI 防火墙。
 *
 * bridge（cpp-src/gpui_bridge.cc）以 c++17 编译（nano 模式），只认这个头；
 * shim（cpp-src/gpui_shim.cpp）以 c++20 编译并 include gpui.h。
 * 两侧只经此处声明的 extern "C" 纯 C 符号通信，跨越 C++17↔C++20 与
 * phpx↔gpui 的类型鸿沟：树用扁平 push/style/method/callback/attach 调用序列化下发，
 * 事件用定长 C struct 回传。gpui::/php:: 类型一律不出现在此边界。
 */
#ifndef TYPEPHP_GPUI_SHIM_H_
#define TYPEPHP_GPUI_SHIM_H_

#include <stdint.h>

#ifdef __cplusplus
extern "C" {
#endif

/* 参数化样式/方法的一个桥接实参：tagged union，跨 C ABI 安全。
 * ARRAY 用 items/count 携带嵌套实参（如 Breadcrumb 的路径段数组）；
 * CB 用 num 携带事先经 gpui_shim_new_callback 分配的 callbackId。 */
typedef enum {
    GPUI_ARG_NIL = 0,
    GPUI_ARG_BOOL,
    GPUI_ARG_NUM,
    GPUI_ARG_STR,
    GPUI_ARG_ARRAY = 5,
    GPUI_ARG_CB = 6
} GpuiArgKind;

typedef struct GpuiArg {
    GpuiArgKind kind;
    double num;
    int b;
    const char* str;
    /* ARRAY：子实参数组（长度 count）；其它 kind 为 NULL。 */
    const struct GpuiArg* items;
    int count;
} GpuiArg;

/* 一条出队事件：type/id 固定缓冲，value 用 tagged 原语（nil/bool/num/str）。 */
typedef struct {
    char type[32];
    char id[128];
    int vkind; /* GpuiArgKind：值类型；GPUI_ARG_NIL 表示无值 */
    double vnum;
    int vbool;
    char vstr[256];
} GpuiEvent;

/* 生命周期 / 窗口。句柄对 bridge 不透明（shim 内部是 GpuiBox*）。 */
void* gpui_shim_app_init(void);
void* gpui_shim_open_window(const char* title, int width, int height);
int   gpui_shim_is_open(void* win);
void  gpui_shim_pump(void* win, double max_wait_secs);
/* 取一条待处理事件；无则返回 0，有则填充 *out 返回 1。 */
int   gpui_shim_poll(void* win, GpuiEvent* out);
void  gpui_shim_close(void* win);
void  gpui_shim_set_theme(void* win, const char* mode);

/* Retained 实体：为带 id 的 Input/Textarea/Slider 创建（或复用）一份常驻状态，
 * 返回其 EntityHandle（写入后续 push 的 component.handle）。按 id 幂等缓存。 */
uint64_t gpui_shim_retained_input(void* win, const char* id, int textarea,
                                  const char* placeholder, const char* value,
                                  int rows);
uint64_t gpui_shim_retained_slider(void* win, const char* id, double min,
                                   double max, double step);
/* 为 DatePicker/Select/Combobox 创建常驻 Focus 实体（按 id 幂等）。返回 EntityHandle。 */
uint64_t gpui_shim_retained_focus(void* win, const char* id);
/* 读取某 input 元素当前文本值：写入 out（至多 outlen-1 字节 + '\0'），返回长度。 */
int gpui_shim_input_value(void* win, const char* id, char* out, int outlen);
/* 程序化设置某 input 元素文本（InputSetValue）。成功返回 1。供自检/预填用。 */
int gpui_shim_set_input_value(void* win, const char* id, const char* text);
/* 自检：为某 elementId+type 已注册的回调合成一次事件，走真实的 gpuiEnqueue*
 * 入队路径（验证 strong 符号覆盖 + boxForView 反查 + 队列）。成功返回 1。 */
int gpui_shim_selftest_fire(void* win, const char* elem_id, const char* type);
/* 自检：直接触发一个 catalog 组件回调（经 gpui_shim_new_callback 登记的 runtime
 * CallbackId），走真实 ComponentCallback::InvokeWith → shimId 旁路 → 事件队列。
 * 命中返回 1。（验证 Stage D 的 registered 回调回环。） */
int gpui_shim_selftest_fire_registered(void* win, const char* elem_id,
                                       const char* type);

/* 离屏渲染当前窗口内容并存成 PNG（WindowDrawFrame → CGBitmapContext → ImageIO）。
 * dip_w/dip_h 为逻辑点尺寸，内部按 2x 取像素。成功返回 1。 */
int gpui_shim_snapshot(void* win, const char* path, int dip_w, int dip_h);
/* 诊断：把当前 spec arena 树 dump 成文本写入 out（至多 outlen-1 字节），返回长度。 */
int gpui_shim_debug_tree(void* win, char* out, int outlen);
/* 取本帧最近一次 registered 构造/方法失败的原因，写入 out（至多 outlen-1 字节 +
 * '\0'），返回长度；无错误返回 0。render_begin 每帧清空。 */
int gpui_shim_last_error(void* win, char* out, int outlen);

/* 注册/取消周期定时器：intervalMs>0 用 WindowSetInterval 挂一个 tick 监听，到点入队
 * {type:"timer", id:phpId}；intervalMs<=0 取消同名定时器。 */
void gpui_shim_set_timer(void* win, const char* php_id, int interval_ms);

/* Overlay（窗口根须是 ShellRoot，见 open_window）。 */
/* 弹一条 toast：level="info|success|warning|error"，timeoutMs<=0 用默认 5s。 */
void gpui_shim_toast(void* win, const char* title, const char* desc,
                     const char* level, int timeout_ms);
/* Dialog：begin 建一个独立内容 Box2（自带 spec arena + PhpView 实体，复用
 * render_begin/push/attach/render_end 以该 handle 为参构建内容），show 打开它。 */
void* gpui_shim_dialog_begin(void* win);
int   gpui_shim_dialog_show(void* win, void* dlg, int escape_dismissable,
                            int backdrop_dismissable);
int   gpui_shim_dialog_count(void* win);
int   gpui_shim_dialog_close(void* win);

/* 渲染一帧 = 重放一遍扁平 spec 指令序列。begin 清空 arena。 */
void gpui_shim_render_begin(void* win);
/* 压入一个组件节点，返回其 spec id；kind 是字符串（div/text/button/...）。
 * id 非空时，若该 id 已建 retained handle（见 gpui_shim_retained_input），
 * 则写入 component.handle 供 materialize 取回状态。 */
uint32_t gpui_shim_push(void* win, const char* kind, const char* text,
                        const char* value, const char* id);
void gpui_shim_nullary_style(void* win, uint32_t spec, const char* name);
void gpui_shim_param_style(void* win, uint32_t spec, const char* name,
                           const GpuiArg* args, int argc);
void gpui_shim_method(void* win, uint32_t spec, const char* name,
                      const GpuiArg* args, int argc);
/* 声明一个回调 op；elem_id/type 供事件回传时反查。返回 callbackId。 */
uint64_t gpui_shim_callback(void* win, uint32_t spec, const char* elem_id,
                            const char* type);
void gpui_shim_attach(void* win, uint32_t parent, uint32_t child);
/* 命名槽：把 child 作为 parent 的具名 slot（Popover 的 trigger/content、Avatar 的
 * image/fallback、Accordion 的 header/panel 等）。child 被 Claim，不能再 Attach。 */
void gpui_shim_slot(void* win, uint32_t parent, uint32_t child,
                    const char* slot_name);

/* Registered（component_shell catalog）组件：按【导出名】在冻结注册表里找到
 * descriptor 的构造器，在 spec 构建期跑其 factory 产出 payload，压入一个
 * kind=Registered 节点（index=descriptor 下标，text=descriptor 名）。args/argc
 * 按构造器声明的顺序给出（可少于声明数，缺的须是 Optional）。找不到导出名或
 * factory 失败时，压入一个带错误文本的 Div（debug_tree 可见），仍返回其 spec id。 */
uint32_t gpui_shim_registered(void* win, const char* export_name,
                              const GpuiArg* args, int argc);
/* 对一个已压入的 registered 节点，调用其 descriptor 声明的方法（recorder 产
 * payload），记为 RegisteredMethod op。spec 是 gpui_shim_registered 返回的 id。 */
void gpui_shim_registered_method(void* win, uint32_t spec, const char* name,
                                 const GpuiArg* args, int argc);
/* 只分配一个 callbackId（不压 op，不建 JS 闭包），供 registered 构造/方法把它
 * 塞进 ComponentArgument.callback；元素 id/事件类型记入 box 以便（Stage D）回环。
 * 返回 callbackId。 */
uint64_t gpui_shim_new_callback(void* win, const char* elem_id,
                                const char* type);

/* 指定根 spec id 并请求下一帧重绘。 */
void gpui_shim_render_end(void* win, uint32_t root_id);

#ifdef __cplusplus
} /* extern "C" */
#endif

#endif /* TYPEPHP_GPUI_SHIM_H_ */
