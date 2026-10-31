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
 * CB 用 num 携带事先经 gpui_shim_new_callback 分配的 callbackId；
 * ENTITY 用 num 携带 gpui_shim_state 造的 catalog 保留态 handle（#81）。
 * ENTITY 与 NUM 分家是有意的：schema 要 Entity 的地方给一个普通数字，
 * 上游会当成合法 handle 去查表，错了也只是"渲染出 Failed to render X"；
 * 分成两个 kind 才能在我们这侧当场拒掉。 */
typedef enum {
    GPUI_ARG_NIL = 0,
    GPUI_ARG_BOOL,
    GPUI_ARG_NUM,
    GPUI_ARG_STR,
    GPUI_ARG_ARRAY = 5,
    GPUI_ARG_CB = 6,
    GPUI_ARG_ENTITY = 7
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
    /* item_secondary_click：x/y、行内 x/y、连击数、修饰键位掩码；value 仍为行 key。 */
    double itemPointer[6];
} GpuiEvent;

/* 生命周期 / 窗口。句柄对 bridge 不透明（shim 内部是 GpuiBox*）。 */
void* gpui_shim_app_init(void);
void* gpui_shim_open_window(const char* title, int width, int height,
                          int borderless, int client_title_bar);
int   gpui_shim_is_open(void* win);
void  gpui_shim_pump(void* win, double max_wait_secs);
/* 取一条待处理事件；无则返回 0，有则填充 *out 返回 1。 */
int   gpui_shim_poll(void* win, GpuiEvent* out);

/* 真输入验收缝（见 gpui_mac.h 的 gpui_mac_send_mouse）。
 * type: 0=移动 1=左键单击（按下+抬起） 2=只按下 3=只抬起 4=右键单击。
 * 2/3 分开是为了能拖：可伸缩面板的分隔条只在"按住 + 移动"时才改尺寸，
 * 一次同点单击不会动它（宿主侧的组合口是 App::realDrag）。
 * 返回 1 = 这一趟在 PHP 队列里确实多出一条事件，即"元素真的挂上了监听器"；
 * 0 = 没有（窗口找不到，或 hit-test 上没有回调的元素）。
 * x/y 是视图坐标（左上原点、整数逻辑点），与 snapshot 同一套。 */
int   gpui_shim_real_mouse(void* win, int x, int y, int type);
int   gpui_shim_real_key(void* win, int keycode, const char* chars);
/* 通过 AppKit 派发滚轮；返回已投递，不以 PHP 队列增长判断，delta 单位为像素。 */
int   gpui_shim_real_scroll(void* win, int x, int y, int delta_y, int delta_x);
/* 合成一次 ShellAction 派发（按名字）：原生菜单条在窗口之外，真点击缝够不到，
 * 而菜单动作回到宿主的整条路（AppOnAction → shim 钩子 → PHP 的 `action` 事件）
 * 需要能被门禁覆盖。返回 1 = 派发被某个监听接住。 */
int   gpui_shim_dispatch_action(void* win, const char* name);
/* 焦点探针：gpui 认为当前持有焦点的实体 id（0 = 没有焦点，-1 = 窗口不可用）。
 * 真键盘只发给有焦点的元素，所以"打不进字"要先分清是没聚焦还是按键没到。 */
int   gpui_shim_focused_id(void* win);
/* 输入框聚焦诊断：返回 paint 登记过的 input 个数（<0 表示窗口不可用），并把第 `index` 颗
 * 的 inputBounds 写进 out[0..3]，out[4]=命中矩形条数。index<0 取最后一颗，越界则四值保持 0。
 * 点击能不能聚焦取决于这张框，所以"打不进字"
 * 要先分清：根本没登记（渲染侧）、框不在那儿（坐标换算）、还是框在但 press 没走到。
 * 做成按索引，是为了能证明"屏上每一个输入框都能打字"，而不是只证明最后一颗能。 */
int   gpui_shim_input_probe(void* win, int index, double* out);
/* 命中表探针：真实点击先过 paint.hits 这张表（HitTestRect），命中了才轮得到 behavior/回调，
 * 所以"看着在那儿却点不动"只有对着这张表量才能分辨是**没登记**、**矩形不在那个位置**，
 * 还是**矩形在但没挂上监听**。返回条数，out[0..3]=bounds，out[4]=id，out[5]=pathId，
 * out[6]=有没有 click 监听，out[7]=父命中条索引（派发链走的是它）。index<0 取最后一条。 */
int   gpui_shim_hit_probe(void* win, int index, double* out);
/* 组件 materialize 失败的读数：返回自启动以来失败次数，并把最后一次的原因写进 out。
 * registered 工厂建不出来时 gpui 不失败整帧，而是渲染成 "Failed to render X"，
 * 所以宿主只能从这里知道出过错——registeredError() 是另一条通道，照不到它。 */
long long gpui_shim_component_failure(char* out, int outlen);

/* 让 bridge 能把"看起来能渲染、其实是静默降级"的情况记进 last_error，
 * 于是 registeredError() 抓得到（否则只是画面上少一个控件，没人知道）。 */

/* 窗口/应用状态：out[0]=NSApp.isActive out[1]=isKeyWindow out[2]=isVisible
 * out[3]=有 mainWindow out[4]=真路由（NSApp 队列）已投递的事件数。真实输入要能落地，
 * 窗口得是 key、应用得是 active；这几个数就是"用户手点为什么没反应"的直接读数，
 * 而事件数用来说明 selftest 的绿是从真路由来的、还是从直投兜底来的。
 * out[5]=进程有没有 bundle 身份：没有 bundle 身份的进程在 macOS 眼里不是正规前台应用，
 * 手点的第一下会被当成"激活这扇窗口"的点击吃掉，keyDown 也没有 firstResponder 可去。
 * out[6]=appkitDeliveries：AppKit 已经**派发到本进程**的 keyDown/leftMouseDown 条数
 * （NSEvent local monitor 计数）。它与 out[4] 的分工：out[4] 只数我们自己经 Deliver()
 * 注入的那条路，out[6] 数"到没过这个 app"——真人按键而 out[6] 仍为 0，说明事件根本没
 * 送到本进程（激活/输入法管线），不为 0 而 PHP 侧计数为 0，才轮到我们的 view/派发。
 * out[7]=WindowServer 说这扇窗**在不在屏**（按窗口号查在屏账本，0 条记录 = 不在屏）。
 * 0..3 都是进程内 AppKit 的自报，实测可以在全为 true 时 out[7] 仍为 0，而物理键鼠
 * 只按 WindowServer 的账本派发 —— 所以"真人按键没反应"要看 out[7]，不看 0..3。
 * out[8]=账本里在屏窗口的总条数（out[7] 的自证臂：非零才说明 out[7]=0 是判决而非查询失效）。
 * out[9]/out[10]=外部协变量：本进程所在 GUI session 的 onConsole 与 session id。
 * 记这两列是因为 out[7] 实测成段地飘（连三次 true / 连五次 false），而 0..8 两侧读数一模一样，
 * ⇒ 切换不在进程内。会话离开控制台（锁屏/切换用户/远程断开）是那种成段的外部状态。
 * out[11]=`pthread_main_np()`、out[12]=`[NSThread isMainThread]`：分开读才能看出
 * "真 main thread"与"AppKit 收养为 main 的线程"是否分家（实测两个都 1，该候选已排除）。
 * 返回 1 = 找到窗口。 */
int   gpui_shim_window_state(void* win, double* out);

/* 有界地再申请一次前台：TransformProcessType/activateIgnoringOtherApps 的回执要靠
 * runloop 走几拍才落地，所以这里顺带跑几拍（只 runMode:beforeDate:，不取事件）。
 * 返回 1 = 此刻应用 active 且窗口 key。前台是和当时焦点持有者在竞争的状态，
 * 调用方应当有界重试，别把它当"一次就成"的开关。 */
int   gpui_shim_activate(void* win);

void  gpui_shim_set_error(void* win, const char* msg);
void  gpui_shim_close(void* win);
void  gpui_shim_set_theme(void* win, const char* mode);

/* Retained 实体：为带 id 的 Input/Textarea/Slider 创建（或复用）一份常驻状态，
 * 返回其 EntityHandle（写入后续 push 的 component.handle）。按 id 幂等缓存。 */
uint64_t gpui_shim_retained_input(void* win, const char* id, int textarea,
                                  const char* placeholder, const char* value,
                                  int rows);
uint64_t gpui_shim_retained_slider(void* win, const char* id, double min,
                                   double max, double step, double value);
/* 为 DatePicker/Select/Combobox 创建常驻 Focus 实体（按 id 幂等）。返回 EntityHandle。 */
uint64_t gpui_shim_retained_focus(void* win, const char* id);
/* 把焦点放进一颗已有焦点身份的部件（bridge 以 "id:t"/"id:c" 造过 handle 的那些），
 * 返回设完后读到的 focused id；0 = 这颗部件没有焦点身份。见 gpui_shim.cpp 的注释。 */
int gpui_shim_focus_named(void* win, const char* id);

/* Otp 的 retained（RetainedKind::Otp）。materialize 的 OtpInput 分支要的就是这个，
 * 没有它整颗 otp_input 静默降级成 Div。length 目前由 bridge 定（4 格）。 */
uint64_t gpui_shim_retained_otp(void* win, const char* id, int length,
                                const char* value, int masked);
/* 批3：OTP 格数回读（state->length）。无此 id / 非 Otp 时返回 0。 */
int gpui_shim_otp_length(void* win, const char* id);
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
/* 区域平均色（0-255，gpui 视图系坐标 y 向下）：cacheDisplay 背板上采样，只读不泵。
 * 给"泵到 WebView 真画出来"的内容判据用（帧数预算不可靠）。成功 1，失败 0。 */
int gpui_shim_region_rgb(void* win, double x, double y, double w, double h, double* out3);
/* 语义 token 整表（批3 A6）：colors 17 键 #rrggbb + spacing/radius 全档 + typography
 * sans/mono，一次拼成 JSON 写入 out（至多 outlen-1 字节 + '\0'），返回长度；
 * 无窗口/无 app/截断返回 0。上游每 palette 构建一次（ThemeTokensSync），PHP 侧
 * json_decode。JSON 形状照 runtime.cpp 的 JS 路快照（AppendThemeColor/"%g"）。 */
int gpui_shim_theme_tokens(void* win, char* out, int outlen);
/* 诊断：把当前 spec arena 树 dump 成文本写入 out（至多 outlen-1 字节），返回长度。 */
int gpui_shim_debug_tree(void* win, char* out, int outlen);
/* 取本帧最近一次 registered 构造/方法失败的原因，写入 out（至多 outlen-1 字节 +
 * '\0'），返回长度；无错误返回 0。render_begin 每帧清空。 */
int gpui_shim_last_error(void* win, char* out, int outlen);
/* 读回某元素最近一次上报的值（App::checked()/elementValue() 的读源）。
 * 返回 GpuiArgKind；GPUI_ARG_NIL = 该 id 从未上报过值（不是"值为 false"）。
 * 按 kind 只填对应出参：NUM→*out_num，BOOL→*out_bool，STR→out_str（NUL 结尾）。 */
/* 各容器条数（内存增长仪表）。顺序与 gpui_shim.cpp 里一致：
 * timerKeys/timerIds/callbacks/cbByKey/shimCallbacks/inputHandles/lastValues/
 * webviews/inspectors/notifyLists/dialogs/watchCtx/httpResponses，然后 boxesAlive（进程级，
 * 活着的 Box2 数）、viewsAlive（进程级，g_views 键数）、listCalls/listItems（宿主渲染
 * 虚拟列表向 PHP 要过几批、几行）、componentStates（进程级，上游保留态实体表的长度，
 * #81 起宿主也会往里造）。返回写入条数。 */
int gpui_shim_state_counts(void* win, long long* out, int cap);

int gpui_shim_element_value(void* win, const char* id, double* out_num,
                            int* out_bool, char* out_str, int outlen);

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
/* 当前生效的焦点陷阱 id（0 = 没有）。#62 的机制级读数。 */
int   gpui_shim_focus_trap(void* win);
int   gpui_shim_dialog_close(void* win);

/* 原生 app 菜单条（macOS 系统菜单栏，非窗口内 → --shot 抓不到，用 count 验证）。
 * 绕开 catalog MenuBar 的 AppEffects/root-app 依赖，直接 BaseSetAppMenus。
 * BaseSetAppMenus 深拷 name/label/submenu，故调用后 menus 可立即释放；但 stroke 只拷
 * 指针（会悬垂），本版恒传 NULL（不支持快捷键）。 */
typedef struct {
    const char* label;
    const char* action;   /* NULL = 无动作（纯标签/分隔符）；非空经 ShellActionOf 映射 */
    int separator;
    int disabled;
    int checked;
} GpuiMenuItem;

typedef struct {
    const char* name;
    const GpuiMenuItem* items;
    int count;
    int disabled;
} GpuiMenu;

void gpui_shim_set_app_menus(void* win, const GpuiMenu* menus, int count);
/* 读回已装菜单数（BaseAppMenus），验证安装。 */
int  gpui_shim_app_menu_count(void* win);
/* 系统菜单条第 row 行（1 起）带的动作名，写进 out。
 * 返回 >0 = 写入字节数；0 = 该行存在但没有动作（分隔符等）；-1 = 行号越界。
 * 用来取证"装进原生菜单条的行确实带着 PHP 声明的 id"——原生菜单行的真点击够不到，
 * 这一跳只能读表。 */
int  gpui_shim_menu_row_action(int row, char* out, int outlen);

/* ── 系统能力 ──────────────────────────────────────────────────────────
 * 每一项都只包 gpui.o 里已存在的符号：不新增上游补丁，不引外部库。 */

/* 剪贴板文本。gpui::ClipboardGetText 要的 Arena 用窗口 spec arena 同一块。
 * 空剪贴板/非文本 → 返回 0（PHP 侧读作 null）；否则写 out 并返回长度。 */
int  gpui_shim_clipboard_get_text(void* win, char* out, int outlen);
void gpui_shim_clipboard_set_text(void* win, const char* text);

/* 剪贴板完整一项（gpui 一次快照给全它能读到的所有表示）。返回位标志：
 *   1 = 有文本，2 = 有图片，4 = 有文件路径；0 = 空剪贴板/读不到。
 * outTextLen / outImgLen / outPathsLen 总是写**真实**字节数（与 cap 无关），所以调用方
 * 一次读取就知道有没有被截断、要重试多大——不必为了探长度反复读粘贴板（那之间内容可能
 * 已经变了）。缓冲允许传 NULL；cap 给 0 即只报长度。
 * 图片是**已编码的图像流**（mac 上先取 PNG、没有再取 TIFF，所以一张截图拿到的是 TIFF
 * 字节），不是 RGBA——要位图请在 PHP 侧交给 GD/Imagick。路径是换行分隔的绝对路径。
 *
 * 文件路径那一半在 mac 上是我们自己读的：gpui 只有 Windows 填 externalPaths
 * （window_win.cpp:1636），mac/linux 的 ClipboardGetItem 只给 text+image。 */
int gpui_shim_clipboard_item(void* win, char* outText, int textCap, int* outTextLen,
                             char* outImg, int imgCap, int* outImgLen,
                             char* outPaths, int pathsCap, int* outPathsLen);

/* 原生文件/目录选择对话框。directories!=0 选目录，title 可为空串。
 * ⚠ PromptForPathTemp 自带嵌套消息循环、跑到用户选完为止，所以只能在事件回调
 * 里调，绝不能在 render 期间调：那时 PHP 正阻塞在本次调用内、gpui_shim_pump 没有
 * 被驱动，gpui 的 modal 循环只会把事件入队，不会重入脚本 VM。
 * 取消 / wasm / Linux 无 zenity+kdialog → 返回 0。 */
int gpui_shim_prompt_for_path(void* win, int directories, const char* title,
                              char* out, int outlen);

/* 交给系统浏览器。先过 gpui 自己的 IsOpenableUrl 白名单（一条规则管住
 * Link.href / cx.open_url / TextView 的链接处理），被拒时不打开、返回 0。 */
int gpui_shim_open_url(const char* url);

/* DevTools：⌘I 那个 inspector 的程序化入口（KeymapDefaults 已自动绑按键）。 */
void gpui_shim_toggle_inspector(void* win);

/* 帧率 HUD。anchor 是 FpsAnchorNames() 里的名字（top_left / top_right /
 * bottom_left / bottom_right / top_center / bottom_center / left_center /
 * right_center）；名字非法返回 0 且不弹。HUD 由窗口根持有：重复调用只是移动或
 * 重配置已存在的那个，隐藏再显示保留历史。render 期间调用被拒（返回 0）。 */
int gpui_shim_show_fps(void* win, const char* anchor);
int gpui_shim_hide_fps(void* win);
int gpui_shim_fps_visible(void* win);

/* Sheet：从窗口边缘滑出的面板，同一时刻只有一个，后开的替换先开的。内容 Box2
 * 复用 gpui_shim_dialog_begin 建的那个。anchor=top|bottom|left|right（空或非法
 * 按 right，对应 gpui::Placement）。 */
int gpui_shim_sheet_show(void* win, void* dlg, const char* anchor);
int gpui_shim_close_sheet(void* win);
int gpui_shim_has_sheet(void* win);

/* WebView：一个 OS 控件盖在窗口上（不是 gpui 画的），按 elementId 常驻。
 * 返回 ChildView 节点的 spec id，样式/挂父与普通节点一样走 push/attach 那套。
 * 首次渲染时才真建（gpui::WebViewNew 靠跑窗口消息循环来建，所以"已建"标志必须先落，
 * 见 webview.h:118-123）；之后换内容走 load（url 优先于 html），不重建控件。
 * url 与 html 都为空 = 建一个空白控件不加载任何东西。 */
uint32_t gpui_shim_webview(void* win, const char* id, const char* url,
                           const char* html);
/* 历史导航：dir=-1 后退、+1 前进（在页内 eval history.back()/forward()）。
 * 该 id 还没有 webview 时返回 0。 */
int gpui_shim_webview_nav(void* win, const char* id, int dir);
/* 探针：读**控件自己**报的状态，不是 shim 记的数。
 * 有 webview 时返回 1，并填 outUrl（当前地址，至多 outlen-1 字节）、
 * outW/outH（WebViewEl 上一帧真正应用给 OS 控件的盒子，逻辑点）。
 * 用途是把"像素是空白"和"控件根本没建成"区分开——离屏 cacheDisplayInRect 抓不到
 * WKWebView 这种跨进程表面，所以它的验收只能靠这里 + 在屏人看。 */
int gpui_shim_webview_probe(void* win, const char* id, char* outUrl, int outlen,
                            double* outW, double* outH);

/* 窗口 inspector（DevTools）面板，作为元素挂进树——与 ⌘I 那个是同一套组件，
 * ⌘I 是"拾取模式开关"、看不到面板本体（gpui 也没有面板开合的查询接口）。
 * 按 id 各持一个 nested view：同一帧里放两个 inspector 节点会渲染两次，若共用一个
 * entity 就会把同一个 El* 交给两个父节点。 */
uint32_t gpui_shim_inspector(void* win, const char* id);

/* ── 缺口服务：HTTP / 目录监听 / 解压 ─────────────────────────────────────
 * 这三样在编译产物里 PHP 侧没有（`hello --caps` 实测 curl/socket/inotify/zlib 全空），
 * 所以桥它们是真补位；sha256/random 反过来不桥，因为 hash()/random_bytes() 本来就在。 */

/* 异步 HTTP GET。起得来返回 1，应答稍后经 {type:'http', id:phpId} 事件到达。
 * url 必须是 http(s)（gpui 自己的 HttpUrlIsRemote 判），否则返回 0 且不发。
 * 走 gpui::HttpSendAsync：HttpSend 阻塞、绝不能在 UI 线程调（sys/http.h:18），
 * 而 done 保证在主线程被调（http.h:100），所以入队无需加锁。 */
int gpui_shim_http_get(void* win, const char* php_id, const char* url);
/* 取已落地的应答。返回 body 字节数；out 为 NULL 时是 dry run，只回所需长度，
 * 用来定缓冲。没有这个 id 返回 -1。
 * outStatus 写 HTTP 状态（0 = 没拿到），outOk 写传输是否完成（真 = 服务器答了）。
 * 记录不在此处销毁（要销毁得等调用方读完），由 gpui_shim_http_free 收走；
 * 未读的应答会攒着，所以 PHP 侧收到 http 事件就该读掉。 */
int gpui_shim_http_response(void* win, const char* php_id, char* out, int outcap,
                            int* outStatus, int* outOk, char* outCt, int ctCap);
/* 丢掉某条应答。返回 1 = 确实删掉了一条。 */
int gpui_shim_http_free(void* win, const char* php_id);

/* 起一个目录监听。成功返回 watch id（>0），失败返回 0。变更经
 * {type:'dirChange', id:phpId} 通知；突发由 gpui 合并成一次调用，主线程派发。
 * 不报告是哪个文件、怎么变的——上游唯一的调用方（主题热重载）就是整目录重扫。 */
int gpui_shim_watch_dir(void* win, const char* php_id, const char* dir,
                        int create);
/* 停监听。onChange 不会再被调，即便已经排了一次。未知/0 号忽略。 */
void gpui_shim_unwatch_dir(void* win, int watch_id);
/* 测试接缝：建一个没有 OS 半的 watch（gpui::DirWatchAdd）并立刻打一次信号
 * （DirWatchSignal），于是"信号 → 主线程 post → 入队 → poll"整条路走通而完全不碰
 * 文件系统。dir_watch.h:86 写明这正是它存在的理由。 */
int gpui_shim_watch_fake(void* win, const char* php_id);

/* 解 zlib / gzip（gpui::shell::ZlibInflate，真解 Huffman）。
 * 返回所需输出字节数；out 为 NULL 时是 dry run。失败返回 -1 并把原因写进 err。
 * 为什么只接解压不接压缩：shell::ZlibDeflate 写的是 stored 块（DeflateStored），
 * 只加壳不压缩，接过来会是个名字骗人的 API。 */
int gpui_shim_inflate(const char* in, int inlen, int gzip, char* out, int outcap,
                      char* err, int errcap);

/* 系统状态。一次采样的概览：cpu / memPct / diskPct 都是 0..100 的百分比
 * （cpu 是全机跨核归一后的占用，不是"单核 0..100×n"）；字节数走 *Bytes 字段。
 * disk 是**启动卷**（gpui 在 mac 上 statfs("/")）。
 *
 * ⚠ cpu 与每个进程的 cpu 都是**两次采样之间**的差值：第一次调用报 0，要真数字就
 * 采两次（中间泵一下循环）或者用定时器周期采。
 * 状态是**进程内一份**，不是每窗口一份：mac 的墙钟差本身就是文件静态量
 * （sysinfo_mac.cpp:207 sPrevWall），多份交替采样只会互相污染读数。
 *
 * procs 给 NULL 就只填概览；给了缓冲则按 procsCap 填，*outProcCount 回实际写入条数
 * （gpui 一次采样列出全机进程，几百条，所以要 top N 时靠 topProcs 让 gpui 自己排）。
 * topProcs>0 时先按 CPU 降序排再截到前 N 条——截断不会污染下一次读数：下一次采样按
 * pid 找前值（FindPrevCpu），而 prevProcs 是另一份完整数组。
 * GpuiProcess.name 指向 gpui 自己的数组，**只活到下一次采样**，bridge 要当场拷走。 */
typedef struct {
    double cpu;
    double memPct;
    long long memTotalBytes;
    long long memUsedBytes;
    double diskPct;
    long long diskTotalBytes;
    long long diskUsedBytes;
    int ncpu;
    int batteryPresent;
    int batteryCharging;
    double batteryPct;
} GpuiSysInfo;

typedef struct {
    int pid;
    const char* name;
    double cpu;
    long long memoryBytes;
} GpuiProcess;

int gpui_shim_sys_refresh(GpuiSysInfo* out, GpuiProcess* procs, int procsCap,
                          int topProcs, int* outProcCount);

/* ── 应用内通知中心 ──────────────────────────────────────────────────────
 * gpui 的基座 Root 自己挂这一层（ui/root.cpp:62），但我们的窗口根是 ShellRoot
 * （它只挂 Toast），所以列表要由 El::notifications() 经 ChildView 挂上——没挂上时
 * push 依然成功（状态在窗口层持有），只是看不见，因此 count 不是"看得见"的判据。
 *
 * 与 Toast 的区别：通知可以带 title + 图标 + 位置、可堆叠成列表、可点、可被用户
 * 关掉，且 maxItems/超时由这一层自己管。 */

/* 推一条通知，返回它的 id（>0）；失败返回 0。
 * type = info|success|warning|error（空或非法按 info）；
 * placement = top_left|top_center|top_right|bottom_left|bottom_center|
 *             bottom_right|left_center|right_center（空或非法按 top_right）；
 * timeoutMs <= 0 = 不自动消失（gpui 的 autohide(false)），要自己 dismiss。
 * phpId 非空时，这条通知**被点**与**消失**分别回
 * {type:'click'|'close', id:phpId}——注意 close 是"已从列表移除"（到期或被关掉），
 * 不是"用户按了关闭按钮"：关闭按钮走 dismiss，移除时同样触发这个回调。 */
int gpui_shim_notify(void* win, const char* php_id, const char* title,
                     const char* message, const char* type,
                     const char* placement, int timeout_ms);
/* 关掉一条（开始它的退场动画）。命中返回 1。 */
int gpui_shim_notify_dismiss(void* win, int notify_id);
/* 全部开始退场。 */
void gpui_shim_notify_clear(void* win);
/* 当前在列表里的条数（含正在退场的）。 */
int gpui_shim_notify_count(void* win);
/* 通知层的挂载节点：返回 ChildView 的 spec id，通常放在树的根之上、铺满窗口。 */
uint32_t gpui_shim_notification_list(void* win, const char* id);

/* 渲染一帧 = 重放一遍扁平 spec 指令序列。begin 清空 arena。 */
void gpui_shim_render_begin(void* win);
/* 把 checkbox/switch 的渲染态写进 lastValues（keyed by elemId）。这是"当前已知
 * 状态"的第二个更新点（第一个是 enqueue 的上报）：PHP 侧改状态重渲染后若没有
 * 这个同步点，lastValues 还是旧值，gpuiShimToggleValue 的翻转就以旧值为基准——
 * 合成双击的第二击会翻错向（实测 checkboxRealClick true→false→false、
 * switchRealClick light→dark→dark）。emitTree 对 checkbox/switch 每帧调用。 */
void gpui_shim_remember_checked(void* win, const char* elemId, int vbool);
/* 压入一个组件节点，返回其 spec id；kind 是字符串（div/text/button/...）。
 * id 非空时，若该 id 已建 retained handle（见 gpui_shim_retained_input），
 * 则写入 component.handle 供 materialize 取回状态。
 * index 写进 component.index —— 上游只有三类节点读它，但读得很实：
 * TableRow 拿它当行号、TableHead/TableCell 拿它当列号（materialize.cpp:1838-1842 →
 * El::AriaRowIndex/AriaColumnIndex）。没有这个槽位时表格的 aria 行/列号恒 0。 */
uint32_t gpui_shim_push(void* win, const char* kind, const char* text,
                        const char* value, const char* id, unsigned index);
/* 按元素 id（即 ->id() 写进去的那个）回读 spec 里的 component.index。
 * 找到返回 1 并写 *out_index，找不到返回 0。存在的理由只有一个：让自检能证明
 * "PHP 写的行号/列号真的进了 spec"——上游的 debug_tree 不打 index，而 aria 值
 * 在物化后的 El 上，PHP 侧拿不到。 */
int gpui_shim_spec_index_by_id(void* win, const char* id, unsigned* out_index);
void gpui_shim_nullary_style(void* win, uint32_t spec, const char* name);
void gpui_shim_param_style(void* win, uint32_t spec, const char* name,
                           const GpuiArg* args, int argc);
void gpui_shim_method(void* win, uint32_t spec, const char* name,
                      const GpuiArg* args, int argc);
/* 声明一个回调 op；elem_id/type 供事件回传时反查。返回 callbackId。 */
// typephp-gpui: host action callback (patch-host-action-cb.sh).
uint64_t gpui_shim_on_action(void* win, uint32_t spec,
                             const char* action_name, const char* elem_id);
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

/* catalog 的保留态实体（#81）：Input/NumberInput/OtpInput/Slider/ColorPicker/
 * Calendar/DatePicker/TimeField 的构造只吃一个 `state` 实参，这个入口就是造它。
 * args 按该 state 描述符的 schema 给（可空）。同一个 (kind,key) 每帧复用同一颗。
 * 成功返回 1 并把 handle 写进 *handle；失败返回 0，原因进 gpui_last_error。
 * handle 必须走出参：上游 ComponentStateStore 的 nextHandle 从 **0** 起
 * （component_registry.h:522），所以 0 是一颗合法实体，不能拿来当失败哨兵。
 * 见 cmd/patch-host-state.sh。 */
int gpui_shim_state(void* win, const char* kind, const char* key,
                    const GpuiArg* args, int argc, uint64_t* handle);

/* 宿主渲染的虚拟列表（#29/#35）。push_list 压一个 list / uniform_list 节点，它的行不在
 * 描述里：materialize 到它时，上游 RenderVirtualItems（cmd/patch-host-list.sh 改过）
 * 回调宿主，shim 切到一块临时 arena，调这里登记的 renderer 要 [first,end) 那一批行的
 * 描述（renderer 照常经 push/style/attach 建树，返回每行根 spec id，写进 out_roots，
 * 至多 cap 个），再把它们 materialize 成行。renderer 在 **materialize 途中** 被调用：
 * 它只能描述，不能 render_begin/render_end。 */
typedef int (*GpuiListRenderFn)(void* win, const char* list_id, int first, int end,
                                uint32_t* out_roots, int cap);
void gpui_shim_set_list_renderer(GpuiListRenderFn fn);

/* 批6 宿主数据回路：materialize 途中同步回调 PHP 处理器（DataTable rows / 图表
 * rows / SearchableList items 的数据回调），返回值经这棵树回填。
 * GpuiDataNode 由 bridge 建（堆上）、shim 走（转 shell::ComponentDataValue）、
 * bridge 释放（gpui_data_node_free）。kind/str/num/b 按 kind 取用；ARRAY 时
 * items/count 有效，keys 非空表示关联数组（shim 侧落成 Object，空表示索引数组）。 */
typedef struct GpuiDataNode {
    GpuiArgKind kind;
    double num;
    int b;
    const char* str;
    struct GpuiDataNode* items;
    const char* const* keys;
    int count;
} GpuiDataNode;

/* bridge 侧：按 (win,id,type) 找 PHP 处理器并 php::call，返回值编成一棵
 * GpuiDataNode。NULL = 没有登记处理器或返回 null（shim 侧落成 Null 结果，
 * descriptor 自己会响亮失败）。 */
typedef GpuiDataNode* (*GpuiDataCallbackFn)(void* win, const char* id,
                                           const char* type);
void gpui_shim_set_data_callback(GpuiDataCallbackFn fn);

/* 批4：token 渲染回调（patch-token-render.sh）——App::on($id,'token',…)
 * 登记的处理器经 bridge 的 invokeTokenRender（php::call → emitTree → 根 SpecId）。
 * 返回 0 = PHP 返回 null / 处理器缺席（上游回落 token label chip）。 */
typedef uint32_t (*GpuiTokenRenderFn)(void* win, const char* id, const char* type);
void gpui_shim_set_token_renderer(GpuiTokenRenderFn fn);

/* 批4：返回 Element 的 catalog 回调（patch-host-element-cb.sh）——render_item /
 * render_cell / render_row 同缝，登记表与数据回调共用（App::on）。
 * 返回 0 = PHP 返回 null（上游回落错误行/chip）。 */
typedef uint32_t (*GpuiElementCbFn)(void* win, const char* id, const char* type);
void gpui_shim_set_element_cb(GpuiElementCbFn fn);

/* 批4：给 retained 输入造一颗 token（InputReplaceWithToken，base/input.cpp:5780）。
 * label 可空。返回 1 = 成功；0 = 元素不在/不是 retained 输入/范围错误。 */
int gpui_shim_input_replace_token(void* win, const char* id, const char* token_id,
                                  const char* text, const char* label);

/* 批5：路径绘制节点（PathFill/PathStroke）。在 Push **前**填全 Component 的
 * background/strokeWidth（PaintMaterialPath 消费）。bgKind：0=Solid(color)、
 * 1=LinearGradient(fromColor→toColor, angle)。命令（move_to/…）由桥按普通
 * method op 追加到返回的 spec。返回 spec id；0 = kind 非法。 */
uint32_t gpui_shim_push_path(void* win, const char* kind, const char* id,
                             double stroke_width, int bg_kind, const char* color,
                             const char* from_color, const char* to_color,
                             double angle);

/* 批5：窗口动作动词（TitleBar B 期最小面）。"minimize"|"zoom"|"close"；
 * 未知动作写 lastError 返回 0。 */
int gpui_shim_window_action(void* win, const char* action);

/* 批5：应用内键位表（NativeBindKeys 的宿主路：KeyChordsParse → ShellActionOf →
 * KeymapBind，整表先验、响亮失败）。返回安装条数；0 = 全表被拒。 */
int gpui_shim_bind_keys(void* win, const char** strokes, const char** actions,
                        const char** contexts, int n);

/* 批5诊断：动作是否有键位绑定。 */
int gpui_shim_keymap_has(const char* action);

/* 批5：带修饰键的合成按键（keymap 和弦匹配读 modifierFlags）。 */
int gpui_shim_real_key_flags(void* win, int keycode, const char* chars,
                             unsigned long flags);

/* shim 走完树后调用释放（递归；keys 数组由 bridge 分配）。 */
void gpui_data_node_free(GpuiDataNode* node);
uint32_t gpui_shim_push_list(void* win, const char* id, int uniform, int count);

/* Dock 面板描述独立持有 arena；begin/end 之间只描述树，不泵帧或重入窗口 API。 */
int gpui_shim_dock_create(void* win, const char* id);
uint32_t gpui_shim_push_dock(void* win, const char* id);
int gpui_shim_dock_panel_begin(void* win, const char* id, const char* panel,
                               const char* title);
int gpui_shim_dock_panel_end(void* win, const char* id, const char* panel,
                             const char* placement, double size, uint32_t root);
int gpui_shim_dock_select(void* win, const char* id, const char* panel);
int gpui_shim_dock_remove(void* win, const char* id, const char* panel);
int gpui_shim_dock_layout(void* win, const char* id, char* out, int cap);
int gpui_shim_dock_load(void* win, const char* id, const char* json);
int gpui_shim_dock_probe(void* win, const char* id, double* out, int cap);

/* 指定根 spec id 并请求下一帧重绘。 */
void gpui_shim_render_end(void* win, uint32_t root_id);

/* ── macOS 系统集成（托盘 / 全局快捷键 / Dock 角标 / OS 通知）──────────────────
 *
 * 实现在独立 TU gpui_mac.mm（只用 Cocoa + Carbon，不 include gpui.h），这一层负责
 * 把事件送回正确的窗口队列：注册时把窗口对象作为 owner 交出去，回调时原样带回来。
 *
 * 验收边界（诚实）：自动化只到 existence-green（注册了几个）与一条 route-green
 * （合成 hotkey 事件走完整条派发链）。菜单栏里有没有图标、快捷键在别的 app 有焦点时
 * 是否仍触发、横幅有没有出现——机器看不见，只能人看。 */

/* 托盘。labels 是菜单项文字（0 个则点击本身作为事件回来）。返回 >0 的 id，失败 0。 */
int gpui_shim_tray_add(void* win, const char* elem_id, const char* tooltip,
                       const char* const* labels, int label_count);
void gpui_shim_tray_remove(void* win, int tray_id);
int gpui_shim_tray_count(void);

/* 全局快捷键。keycode 是 kVK_*（如 F13=105），mods 是 Carbon 位（cmdKey=256
 * shiftKey=512 optionKey=2048 controlKey=4096）。返回 >0 的 id，失败 0。 */
int gpui_shim_hotkey_register(void* win, const char* elem_id, unsigned keycode,
                              unsigned mods);
void gpui_shim_hotkey_unregister(int hotkey_id);
int gpui_shim_hotkey_count(void);
/* 测试接缝：合成一条 hotkey 事件发给 application target，验"Carbon 队列 → 派发 →
 * handler → sink → 窗口队列 → poll"整条路。它不验系统级的全局捕获。 */
int gpui_shim_hotkey_fire(int index);

/* Dock 角标文字（空串清除）。 */
void gpui_shim_dock_badge(const char* text);
/* OS 通知。返回 1 = 已交给系统；不代表屏幕上出现了横幅（裸可执行文件没有 bundle id
 * 时会被静默丢弃）。 */
int gpui_shim_os_notify(const char* title, const char* body);

/* ── 授权文件系统 / 进程 / 存储（Capabilities）───────────────────────────────
 *
 * gpui 的这一层不吃"ambient 路径"：FsRun 要的是 (root, relative) 两半，root 必须是
 * 事先授予过的目录，遍历时逐级 openat + O_NOFOLLOW（filesystem_posix.cpp:49-66）。
 * 所以这里持有一份**进程级** gpui::Capabilities，PHP 启动时声明允许的根，之后每个
 * 调用先 ResolvePath 选根、再 FsRun。没有 grant_root 过的路径一律失败并给出原因。
 *
 * 出参约定：错误文本写进 err（截断到 err_cap-1，必带终止符），返回 1 表示成功。
 * 大块字节（文件内容、进程输出、存储值）落在**进程级 scratch** 里，只活到下一次调用，
 * 所以桥必须立刻拷走——gpui_shim_out() 就是取它的那一道。 */

typedef struct GpuiFsEntry {
    const char* name; /* 只活到下一次调用 */
    int is_dir;
} GpuiFsEntry;

/* 授予一个根。writable=1 是读写根、0 是只读根。返回该类根的数量，失败 0。 */
int gpui_shim_grant_root(const char* root, int writable);
/* 命令白名单。unrestricted=1 时全部放行（count/commands 忽略）。返回 1。 */
int gpui_shim_grant_execute(const char* const* commands, int count,
                            int unrestricted);
/* 允许使用存储。 */
void gpui_shim_grant_storage(int allowed);
int gpui_shim_storage_granted(void);
/* 存储落盘位置（Storage::SetPath）。返回 1。 */
int gpui_shim_storage_path(const char* path, char* err, int err_cap);

int gpui_shim_fs_read(const char* path, char* err, int err_cap);
int gpui_shim_fs_write(const char* path, const char* data, int len, char* err,
                       int err_cap);
/* 目录列举：entries 由调用方给缓冲，cap 是上限，out_count 回实际条数。 */
int gpui_shim_fs_list(const char* path, GpuiFsEntry* entries, int cap,
                      int* out_count, char* err, int err_cap);
int gpui_shim_fs_exists(const char* path);
/* dir=1 删目录（recursive 生效），否则删文件。 */
int gpui_shim_fs_remove(const char* path, int dir, int recursive, char* err,
                        int err_cap);
int gpui_shim_fs_mkdir(const char* path, int dir, char* err, int err_cap);
/* 取上一次成功调用的字节（scratch）。没有则 data=NULL、len=0。 */
void gpui_shim_out(const char** data, int* len);

/* 阻塞跑一个被授权的可执行文件（子进程环境是空的、30s 超时、每路输出上限 8 MiB）。
 * out_code 回退出码；stdout 落在 scratch，stderr 用 gpui_shim_process_err 取。 */
int gpui_shim_process_run(const char* command, const char* const* args,
                          int count, int* out_code, char* err, int err_cap);
void gpui_shim_process_err(const char** data, int* len);

int gpui_shim_storage_set(const char* key, const char* data, int len, char* err,
                          int err_cap);
/* 1 = 有这个键，值在 scratch。 */
int gpui_shim_storage_get(const char* key);
int gpui_shim_storage_remove(const char* key, char* err, int err_cap);
int gpui_shim_storage_len(void);

#ifdef __cplusplus
} /* extern "C" */
#endif

#endif /* TYPEPHP_GPUI_SHIM_H_ */
