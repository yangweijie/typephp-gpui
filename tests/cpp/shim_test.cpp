// typephp-gpui — shim 纯函数 C++ 回归测试（headless，不开窗口）。
//
// 复用整个 gpui_shim.cpp TU（#include）以测其匿名命名空间里的纯函数：
// kindFromString / AStr / toComponentArg / buildComponentArgs。这些正是 bug #18
// （arena 字符串悬垂）与 Stage B schema 映射的核心，不依赖 NSWindow/显示，
// 故能在任意 CI 跑。构建见 cmd/test-shim.sh（链接 amalgam .o，不链 libgpui_shim.a）。

#include "gpui_shim.cpp"

#include <cstdio>
#include <cstring>
#include <cstdlib>
#include <unistd.h>
#include <string>

namespace {

int g_failures = 0;

#define CHECK(cond)                                                          \
    do {                                                                     \
        if (!(cond)) {                                                       \
            std::fprintf(stderr, "  ✗ %s:%d  CHECK(%s)\n", __FILE__,         \
                         __LINE__, #cond);                                   \
            ++g_failures;                                                    \
        }                                                                    \
    } while (0)

using K = gpui::shell::ComponentKind;
using AK = gpui::shell::ComponentArgumentKind;
using ArgDesc = gpui::shell::ArgumentDescriptor;
using SliceDesc = gpui::shell::Slice<ArgDesc>;

// 供 Array/Optional 复用的内层 String schema（须存活于使用点）。
const gpui::shell::ArgumentSchema& strElemSchema() {
    static const gpui::shell::ArgumentSchema s = gpui::shell::SchemaString();
    return s;
}

// 1) kindFromString：已映射 kind 精确；认不出的**必须标出来**，不再静默当 Div。
// 原来这条断言的是 `未知 -> Div`，那等于给上游漂移上了保险：新 kind 拼错或没登记时，
// 整棵子树会渲染成看不见的空盒子，而 registeredError() 只管 registered 那条路，
// 于是四层各自"看着对"、现场零报错。现在 push 侧据 known 拒绝建节点并写 lastError。
void testKindFromString() {
    std::puts("kindFromString");
    bool known = false;
    known = true; CHECK(kindFromString("button", &known) == K::Button && known);
    known = true; CHECK(kindFromString("number_input", &known) == K::NumberInput && known);
    known = true; CHECK(kindFromString("otp_input", &known) == K::OtpInput && known);
    known = true; CHECK(kindFromString("resizable_panel", &known) == K::ResizablePanel && known);
    known = true; CHECK(kindFromString("toggle_group", &known) == K::ToggleGroup && known);
    // 兜底：值仍是 Div（不新增枚举，避免动 C ABI 结构），但 known 必须翻成 false。
    known = true; CHECK(kindFromString("totally_unknown", &known) == K::Div && !known);
    known = true; CHECK(kindFromString(nullptr, &known) == K::Div && !known);
    // 不传出参也不能崩（gpui_shim_push 之外的调用形状）。
    CHECK(kindFromString("div") == K::Div);
}

// 2) AStr：arena 拷贝必须独立于源缓冲（bug #18 的直接守卫）。
void testAStrCopiesIntoArena() {
    std::puts("AStr arena copy");
    gpui::Arena* a = gpui::ArenaNew();
    char buf[8] = "100%";
    gpui::Str s = AStr(a, buf);
    std::memcpy(buf, "ZZZZ", 4);  // 改写源：arena 副本必须不受影响
    CHECK(s.len == 4);
    CHECK(std::memcmp(s.s, "100%", 4) == 0);
    gpui::Str empty = AStr(a, nullptr);
    CHECK(empty.len == 0);
    gpui::ArenaDelete(a);
}

// 3) toComponentArg：各 SchemaKind 映射 + Enum 白名单 + Array/Optional/Callback。
void testToComponentArg() {
    std::puts("toComponentArg schema mapping");
    gpui::Arena* a = gpui::ArenaNew();
    gpui::shell::ComponentArgument out{};
    std::string err;

    GpuiArg gs{};
    gs.kind = GPUI_ARG_STR;
    gs.str = "hello";
    CHECK(toComponentArg(a, gs, gpui::shell::SchemaString(), &out, &err));
    CHECK(out.kind == AK::String);
    CHECK(out.string.len == 5);

    GpuiArg gn{};
    gn.kind = GPUI_ARG_NUM;
    gn.num = 3.5;
    CHECK(toComponentArg(a, gn, gpui::shell::SchemaNumber(), &out, &err));
    CHECK(out.kind == AK::Number && out.number == 3.5);

    // Number 也能从数字串来（bridge 有时把数值当字符串传）。
    GpuiArg gns{};
    gns.kind = GPUI_ARG_STR;
    gns.str = "42";
    CHECK(toComponentArg(a, gns, gpui::shell::SchemaNumber(), &out, &err));
    CHECK(out.number == 42.0);

    GpuiArg gb{};
    gb.kind = GPUI_ARG_BOOL;
    gb.b = 1;
    CHECK(toComponentArg(a, gb, gpui::shell::SchemaBoolean(), &out, &err));
    CHECK(out.kind == AK::Boolean && out.boolean == true);

    // Enum：白名单内命中、外拒绝。
    const char* lits[] = {"small", "large"};
    gpui::shell::ArgumentSchema enumS =
        gpui::shell::SchemaEnum(gpui::shell::Slice<const char*>(lits, 2));
    GpuiArg ge{};
    ge.kind = GPUI_ARG_STR;
    ge.str = "large";
    CHECK(toComponentArg(a, ge, enumS, &out, &err));
    CHECK(out.kind == AK::Enum);
    GpuiArg gbad{};
    gbad.kind = GPUI_ARG_STR;
    gbad.str = "huge";
    CHECK(!toComponentArg(a, gbad, enumS, &out, &err));  // 非白名单 → false

    // Array<String>：递归 items。
    gpui::shell::ArgumentSchema strArr =
        gpui::shell::SchemaArray(&strElemSchema());
    GpuiArg items[2];
    items[0] = {};
    items[0].kind = GPUI_ARG_STR;
    items[0].str = "Home";
    items[1] = {};
    items[1].kind = GPUI_ARG_STR;
    items[1].str = "Data";
    GpuiArg garr{};
    garr.kind = GPUI_ARG_ARRAY;
    garr.items = items;
    garr.count = 2;
    CHECK(toComponentArg(a, garr, strArr, &out, &err));
    CHECK(out.kind == AK::Array && out.count == 2);
    CHECK(out.items[0].string.len == 4);  // "Home"
    CHECK(out.items[1].string.len == 4);  // "Data"

    // Optional：NIL → count 0（缺席）。
    GpuiArg gnil{};
    gnil.kind = GPUI_ARG_NIL;
    CHECK(toComponentArg(a, gnil, gpui::shell::SchemaOptional(&strElemSchema()),
                         &out, &err));
    CHECK(out.kind == AK::Optional && out.count == 0);

    // Callback：num 携带预分配 callbackId。
    GpuiArg gcb{};
    gcb.kind = GPUI_ARG_CB;
    gcb.num = 77;
    CHECK(toComponentArg(a, gcb, gpui::shell::SchemaCallback("() => void"),
                         &out, &err));
    CHECK(out.kind == AK::Callback && out.callback == 77);

    gpui::ArenaDelete(a);
}

// 4) buildComponentArgs：恒按 schema 元数分配；缺的须 Optional，否则 -1。
void testBuildComponentArgs() {
    std::puts("buildComponentArgs arity");
    gpui::Arena* a = gpui::ArenaNew();
    ArgDesc decls[] = {
        {"id", gpui::shell::SchemaString()},
        {"opt", gpui::shell::SchemaOptional(&strElemSchema())},
    };
    SliceDesc view(decls, 2);

    // 只给 1 个实参：第 2 个是 Optional → 缺席填充，返回 schema 元数 2。
    GpuiArg args[1];
    args[0] = {};
    args[0].kind = GPUI_ARG_STR;
    args[0].str = "x";
    gpui::shell::ComponentArgument* out = nullptr;
    std::string err;
    int n = buildComponentArgs(a, view, args, 1, &out, &err);
    CHECK(n == 2);
    CHECK(out[0].kind == AK::String);
    CHECK(out[1].kind == AK::Optional && out[1].count == 0);

    // 必填缺失：第 1 个是 String（非 Optional），argc=0 → -1。
    ArgDesc req[] = {{"id", gpui::shell::SchemaString()}};
    SliceDesc reqView(req, 1);
    gpui::shell::ComponentArgument* out2 = nullptr;
    CHECK(buildComponentArgs(a, reqView, nullptr, 0, &out2, &err) == -1);

    gpui::ArenaDelete(a);
}

// 5) 系统能力的纯判据：open_url 白名单 + FPS 锚点名 + copyOut 截断。
//    URL 表与 tests/SystemTest.php 里 'the url gate mirrors gpui' 那条逐条同内容，
//    于是替身（FakeBridge::isOpenableUrl）与真实判据（gpui::IsOpenableUrl）
//    被同一组用例从两侧钉住。
void testCapabilityGates() {
    std::puts("capability gates");
    struct Case {
        const char* url;
        bool want;
    };
    static const Case kCases[] = {
        {"https://example.com/x?y=1", true},
        {"HTTP://EXAMPLE.COM", true},
        {"https://user:pw@example.com/p", true},
        {"http://host:8080", true},
        {"http://[::1]", true},
        {"http://[::1]:8080/p", true},
        {"http://@example.com", true},
        {"http://", false},
        {"http://example.com@", false},
        {"http://host:", false},
        {"http://host:abc", false},
        {"http://host:99999", false},
        {"http://[]:80", false},
        {"http://:80", false},
        {"http://exa\nmple.com", false},
        {"http://ex\\ample.com", false},
        {"javascript:alert(1)", false},
        {"mailto:a@b.c", false},
        {"not a url", false},
        {"ftp://example.com", false},
    };
    for (const Case& c : kCases) {
        CHECK(gpui::IsOpenableUrl(gpui::Str(c.url)) == c.want);
    }

    // authority 扫描看长度不看 '\0'，所以串里嵌 NUL 也得被拒。
    gpui::Str nulIn("http://a\0b.com", 13);
    CHECK(nulIn.len == 13);
    CHECK(!gpui::IsOpenableUrl(nulIn));

    // FPS 锚点：App::FPS_ANCHORS 的八个名字全认，非法名返回 false 且不动 *out。
    const char* names[] = {"top_left",     "top_right",     "bottom_left",
                           "bottom_right", "top_center",    "bottom_center",
                           "left_center",  "right_center"};
    for (const char* n : names) {
        gpui::FpsAnchor a = gpui::FpsAnchor::TopLeft;
        CHECK(gpui::FpsAnchorFromName(gpui::Str(n), &a));
    }
    gpui::FpsAnchor keep = gpui::FpsAnchor::BottomCenter;
    CHECK(!gpui::FpsAnchorFromName(gpui::Str("middle"), &keep));
    CHECK(keep == gpui::FpsAnchor::BottomCenter);

    // copyOut：截到 outlen-1 且必带终止符；空串返回 0；out 为空不崩。
    char buf[8];
    CHECK(copyOut(buf, (int)sizeof(buf), gpui::Str("abcdefghij")) == 7);
    CHECK(std::strcmp(buf, "abcdefg") == 0);
    CHECK(copyOut(buf, (int)sizeof(buf), gpui::Str("")) == 0);
    CHECK(buf[0] == '\0');
    CHECK(copyOut(nullptr, 8, gpui::Str("x")) == 0);
}

// 6c) 通知的 type / placement 字符串映射。PHP 侧只保证传进来的是枚举成员，真正落到
//     gpui 枚举的是这两个函数；映射错了像素基本看不出来（八个锚点里基线只拍得到两个，
//     四种 type 只差一个图标，而图标本端口不画）。
void testNotifyMapping() {
    std::puts("notify type/anchor mapping");
    using T = gpui::component::NotificationType;
    CHECK(NotifyTypeFor("info") == T::Info);
    CHECK(NotifyTypeFor("success") == T::Success);
    CHECK(NotifyTypeFor("warning") == T::Warning);
    CHECK(NotifyTypeFor("error") == T::Error);
    // 不宽容大小写、不认空指针：全回落 Info（与 toast 的 level 同一套字面）。
    CHECK(NotifyTypeFor("SUCCESS") == T::Info);
    CHECK(NotifyTypeFor("") == T::Info);
    CHECK(NotifyTypeFor(nullptr) == T::Info);

    using A = gpui::Anchor;
    CHECK(NotifyAnchorFor("top_left") == A::TopLeft);
    CHECK(NotifyAnchorFor("top_center") == A::TopCenter);
    CHECK(NotifyAnchorFor("top_right") == A::TopRight);
    CHECK(NotifyAnchorFor("bottom_left") == A::BottomLeft);
    CHECK(NotifyAnchorFor("bottom_center") == A::BottomCenter);
    CHECK(NotifyAnchorFor("bottom_right") == A::BottomRight);
    CHECK(NotifyAnchorFor("left_center") == A::LeftCenter);
    CHECK(NotifyAnchorFor("right_center") == A::RightCenter);
    CHECK(NotifyAnchorFor("middle") == A::TopRight);
    CHECK(NotifyAnchorFor(nullptr) == A::TopRight);

    // 八个锚点必须各占一个 group 槽：notification.cpp:825 按 (int)anchor 索引
    // groups[8]，越界的那条会被静默丢掉（continue），所以枚举不能超出 0..7。
    int seen[8] = {};
    const char* all[8] = {"top_left",   "top_center",   "top_right",    "bottom_left",
                          "bottom_center", "bottom_right", "left_center", "right_center"};
    for (const char* n : all) {
        int ix = (int)NotifyAnchorFor(n);
        CHECK(ix >= 0 && ix < 8);
        seen[ix]++;
    }
    for (int i = 0; i < 8; i++) CHECK(seen[i] == 1);
}


// 6e) 图标查表链路：通知/toast/El::icon 能不能画出图标，全看这张编译进去的表答不答得上。
//     纯查表、headless 可跑，所以把它放在像素之前——像素只能证明"画出来了"，证明不了
//     "查表这一层是好的"。三条形状要求（前缀 icons/、后缀 .svg、base 命中表）与
//     "查不到就静默返回空"都要钉住，否则下一个人名对了形状、名错了，只会得到一片白。
void testAssetIconLookup() {
    std::puts("asset icon lookup table");
    int len = -1;
    // 通知用的四个 type 图标 + demo 用的两个，必须在表里且字节码非空。
    const char* names[] = {"info", "circle-check", "triangle-alert", "circle-x",
                           "square-terminal", "settings"};
    for (const char* n : names) {
        len = -1;
        CHECK(gpui::AssetIconFind(gpui::Str(n), &len) != nullptr);
        CHECK(len > 0);
    }
    // 枚举那条路的产物形状：IconNamePath 给的正是 "icons/<base>.svg"。
    len = -1;
    CHECK(gpui::AssetIconForPath(gpui::Str("icons/circle-check.svg"), &len) != nullptr);
    CHECK(len > 0);

    // 三种"看着像但答不上"的写法，全部静默返回空——这就是 El::icon 侧要挡的东西。
    len = -1;
    CHECK(gpui::AssetIconForPath(gpui::Str("circle-check"), &len) == nullptr);  // 没形状
    len = -1;
    CHECK(gpui::AssetIconForPath(gpui::Str("icons/circle-check"), &len) == nullptr);  // 没后缀
    len = -1;
    CHECK(gpui::AssetIconForPath(gpui::Str("icons/home.svg"), &len) == nullptr);
    // ^ 表里真没有 home（它是 sidebar navIcon 的逻辑名，另一套命名空间）。demo 曾经
    //   就写这个，结果 tree21 的基线把那里录成了一片白。
    len = -1;
    CHECK(gpui::AssetIconForPath(gpui::Str("icons/nope.svg"), &len) == nullptr);
    CHECK(gpui::AssetIconFind(gpui::Str(""), &len) == nullptr);
}


// 6g) 授权文件系统 / 进程 / 存储：门的两侧都要量。
//     只量"授予后能用"会漏掉最危险的一半——未授予的路径必须**被拒且说得出原因**，
//     否则授权模型形同虚设。递归删也要真量：gpui 的 RemoveDirectory 是
//     unlinkat(AT_REMOVEDIR)，非空目录会失败，recursive 是我们在 shim 里补的。
void testCapabilityServices() {
    std::puts("capability fs / process / storage");
    char tmpl[] = "/tmp/gpui-caps-XXXXXX";
    const char* root = mkdtemp(tmpl);
    CHECK(root != nullptr);
    if (!root) return;
    char err[256];
    const std::string dir = std::string(root) + "/tree";
    CHECK(gpui_shim_grant_root(root, 1) == 1);
    CHECK(gpui_shim_fs_mkdir(dir.c_str(), 1, err, (int)sizeof(err)) == 1);

    // 写进去的东西必须真在文件系统上（用 POSIX 自己读回来对照），反之亦然。
    const std::string inner = dir + "/a.txt";
    const char body[] = "第一段\n第二段";
    CHECK(gpui_shim_fs_write(inner.c_str(), body, (int)sizeof(body) - 1, err,
                             (int)sizeof(err)) == 1);
    {
        FILE* f = std::fopen(inner.c_str(), "rb");
        CHECK(f != nullptr);
        char back[32] = {};
        const size_t got = f ? std::fread(back, 1, sizeof(back) - 1, f) : 0;
        if (f) std::fclose(f);
        CHECK(std::string(back, got) == "第一段\n第二段");
    }
    // 二进制安全：含 \0 的内容要原样穿过去。
    const std::string binPath = dir + "/b.bin";
    const char bin[] = "\x00\x89PNG\x1a\n";
    CHECK(gpui_shim_fs_write(binPath.c_str(), bin, 8, err, (int)sizeof(err)) == 1);
    CHECK(gpui_shim_fs_read(binPath.c_str(), err, (int)sizeof(err)) == 1);
    {
        const char* d = nullptr;
        int len = 0;
        gpui_shim_out(&d, &len);
        CHECK(len == 8);
        CHECK(d != nullptr && std::memcmp(d, bin, 8) == 0);
    }
    // 列目录：两条，且都不被标成目录。
    GpuiFsEntry entries[8];
    int total = -1;
    CHECK(gpui_shim_fs_list(dir.c_str(), entries, 8, &total, err, (int)sizeof(err)) == 1);
    CHECK(total == 2);
    int dirs = 0;
    for (int i = 0; i < total; i++) dirs += entries[i].is_dir;
    CHECK(dirs == 0);
    CHECK(gpui_shim_fs_exists(inner.c_str()) == 1);
    CHECK(gpui_shim_fs_exists((dir + "/nope").c_str()) == 0);

    // 递归删：非空目录在 gpui 那一层是删不掉的，shim 补的遍历要真把它带走。
    CHECK(gpui_shim_fs_remove(dir.c_str(), 1, 0, err, (int)sizeof(err)) == 0);
    CHECK(gpui_shim_fs_remove(dir.c_str(), 1, 1, err, (int)sizeof(err)) == 1);
    CHECK(gpui_shim_fs_exists(dir.c_str()) == 0);

    // 反向锚：没授予过的路径必须失败，且原因写得出来（桥那边靠它）。
    err[0] = '\0';
    CHECK(gpui_shim_fs_read("/etc/hosts", err, (int)sizeof(err)) == 0);
    CHECK(std::strlen(err) > 0);
    CHECK(std::strstr(err, "授权") != nullptr);
    CHECK(gpui_shim_fs_exists("/etc/hosts") == 0);

    // 进程白名单：名单内跑得通并带回输出，名单外被拒。
    const char* allowed[] = {"/bin/echo"};
    CHECK(gpui_shim_grant_execute(allowed, 1, 0) == 1);
    const char* arg[] = {"caps"};
    int code = -1;
    CHECK(gpui_shim_process_run("/bin/echo", arg, 1, &code, err, (int)sizeof(err)) == 1);
    CHECK(code == 0);
    {
        const char* d = nullptr;
        int len = 0;
        gpui_shim_out(&d, &len);
        CHECK(d != nullptr && std::string(d, (size_t)len).find("caps") != std::string::npos);
    }
    err[0] = '\0';
    const char* catArg[] = {"/etc/hosts"};
    CHECK(gpui_shim_process_run("/bin/cat", catArg, 1, &code, err, (int)sizeof(err)) == 0);
    CHECK(std::strstr(err, "白名单") != nullptr);

    // 存储：没授权时连 Set 都进不去；授权后 set/get/len 一致，且真落过盘。
    CHECK(gpui_shim_storage_granted() == 0);
    CHECK(gpui_shim_storage_set("k", "v", 1, err, (int)sizeof(err)) == 0);
    gpui_shim_grant_storage(1);
    CHECK(gpui_shim_storage_granted() == 1);
    const std::string storePath = std::string(root) + "/store.json";
    CHECK(gpui_shim_storage_path(storePath.c_str(), err, (int)sizeof(err)) == 1);
    CHECK(gpui_shim_storage_set("theme", "dark", 4, err, (int)sizeof(err)) == 1);
    CHECK(gpui_shim_storage_get("theme") == 1);
    {
        const char* d = nullptr;
        int len = 0;
        gpui_shim_out(&d, &len);
        CHECK(len == 4 && d != nullptr && std::string(d, 4) == "dark");
    }
    CHECK(gpui_shim_storage_get("missing") == 0);
    CHECK(gpui_shim_storage_len() == 1);
    CHECK(gpui_shim_storage_remove("theme", err, (int)sizeof(err)) == 1);
    CHECK(gpui_shim_storage_len() == 0);
    {
        FILE* f = std::fopen(storePath.c_str(), "rb");
        CHECK(f != nullptr);
        if (f) std::fclose(f);
    }
    std::remove(storePath.c_str());
    ::rmdir(root);
}

// 6f) copyBounded：二进制通道唯一的截断/长度语义，剪贴板图像与解压输出都靠它。
//     真实长度必须与 cap 无关地报出来，否则调用方无法区分"就这么长"和"被截了"。
void testCopyBounded() {
    std::puts("copyBounded");
    const char src[] = "abc\0def";  // 中间有 NUL：图像流里这是正常数据
    char buf[8];

    // dry run：dst 为空 / cap<=0 都只回长度，不写不崩。
    CHECK(copyBounded(nullptr, 0, src, 7) == 7);
    CHECK(copyBounded(buf, 0, src, 7) == 7);
    CHECK(copyBounded(buf, -1, src, 7) == 7);

    // 装得下：原样拷，含 NUL。
    std::memset(buf, '#', sizeof(buf));
    CHECK(copyBounded(buf, 8, src, 7) == 7);
    CHECK(std::memcmp(buf, src, 7) == 0);

    // 装不下：写满 cap 字节，但**返回的仍是需要的长度**。
    std::memset(buf, '#', sizeof(buf));
    CHECK(copyBounded(buf, 3, src, 7) == 7);
    CHECK(buf[0] == 'a' && buf[1] == 'b' && buf[2] == 'c');

    // need<0 归零；src 为空时不写但仍报 need。
    CHECK(copyBounded(buf, 8, src, -5) == 0);
    CHECK(copyBounded(nullptr, 8, nullptr, 4) == 4);
}

// 6) 缺口服务：解压要读得懂**外部真数据**，HTTP 判据要只认 http(s)。
//    那两串向量是开发机上 PHP 的 zlib 生成的 "hello gpui" 容器，与
//    examples/hello 的 selftest 第 13 步、tests/SystemTest.php 的 inflate 那条用的是
//    同一批字节 —— 三方钉一处：gpui 的解压器、gpui 侧的 C++ 调用、PHP 自己的 zlib。
void testGapServices() {
    std::puts("gap services");
    const char plain[] = "hello gpui";
    const int plainLen = 10;
    static const uint8_t kZlib[] = {
        0x78, 0x9C, 0xCB, 0x48, 0xCD, 0xC9, 0xC9, 0x57,
        0x48, 0x2F, 0x28, 0xCD, 0x04, 0x00, 0x15, 0x74, 0x03, 0xEA};
    static const uint8_t kGzip[] = {
        0x1F, 0x8B, 0x08, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x13, 0xCB, 0x48,
        0xCD, 0xC9, 0xC9, 0x57, 0x48, 0x2F, 0x28, 0xCD, 0x04, 0x00, 0x52, 0x1A,
        0x59, 0x50, 0x0A, 0x00, 0x00, 0x00};

    gpui::Str out{};
    gpui::Str msg{};
    CHECK(gpui::shell::ZlibInflate(
        gpui::Str((const char*)kZlib, (int)sizeof(kZlib)), false, &out, &msg));
    CHECK(out.len == plainLen);
    CHECK(out.s && std::memcmp(out.s, plain, (size_t)plainLen) == 0);
    if (out.s) base::StrFree(out);
    if (msg.s) base::StrFree(msg);
    out = {}; msg = {};

    CHECK(gpui::shell::ZlibInflate(
        gpui::Str((const char*)kGzip, (int)sizeof(kGzip)), true, &out, &msg));
    CHECK(out.len == plainLen);
    CHECK(out.s && std::memcmp(out.s, plain, (size_t)plainLen) == 0);
    if (out.s) base::StrFree(out);
    if (msg.s) base::StrFree(msg);
    out = {}; msg = {};

    // 坏数据：报失败，不能崩、也不能吐出东西来。
    CHECK(!gpui::shell::ZlibInflate(gpui::Str("not a zlib stream"), false, &out,
                                    &msg));
    CHECK(out.s == nullptr || out.len == 0);
    if (out.s) base::StrFree(out);
    if (msg.s) base::StrFree(msg);
    out = {}; msg = {};

    // 回环 + 互操作前提：ZlibDeflate 写的是 stored 块（不压缩），但容器得自洽且
    // 头是对的——否则"只加壳"这个说法本身就不成立。
    gpui::Str packed{};
    gpui::Str e1{};
    CHECK(gpui::shell::ZlibDeflate(gpui::Str(plain), false, &packed, &e1));
    CHECK(packed.len > 2);
    CHECK(packed.s && (uint8_t)packed.s[0] == 0x78);
    CHECK(gpui::shell::ZlibInflate(packed, false, &out, &msg));
    CHECK(out.len == plainLen);
    CHECK(out.s && std::memcmp(out.s, plain, (size_t)plainLen) == 0);
    if (out.s) base::StrFree(out);
    if (msg.s) base::StrFree(msg);
    if (packed.s) base::StrFree(packed);
    if (e1.s) base::StrFree(e1);
    out = {}; msg = {}; packed = {}; e1 = {};

    CHECK(gpui::shell::ZlibDeflate(gpui::Str(plain), true, &packed, &e1));
    CHECK(packed.s && (uint8_t)packed.s[0] == 0x1F && (uint8_t)packed.s[1] == 0x8B);
    CHECK(gpui::shell::ZlibInflate(packed, true, &out, &msg));
    CHECK(out.len == plainLen);
    if (out.s) base::StrFree(out);
    if (msg.s) base::StrFree(msg);
    if (packed.s) base::StrFree(packed);
    if (e1.s) base::StrFree(e1);

    // HttpUrlIsRemote：http(s) 之外一律不试。
    CHECK(gpui::HttpUrlIsRemote(gpui::Str("https://example.com")));
    CHECK(gpui::HttpUrlIsRemote(gpui::Str("http://h/p?q#f")));
    CHECK(!gpui::HttpUrlIsRemote(gpui::Str("data:text/plain,hi")));
    CHECK(!gpui::HttpUrlIsRemote(gpui::Str("ftp://h/f")));
    CHECK(!gpui::HttpUrlIsRemote(gpui::Str("//h/x")));
    CHECK(!gpui::HttpUrlIsRemote(gpui::Str("")));
}

} // namespace

int main() {
    testKindFromString();
    testAStrCopiesIntoArena();
    testToComponentArg();
    testBuildComponentArgs();
    testCapabilityGates();
    testNotifyMapping();
    testAssetIconLookup();
    testCopyBounded();
    testGapServices();
    testCapabilityServices();
    if (g_failures) {
        std::fprintf(stderr, "SHIM CPP TESTS FAIL (%d)\n", g_failures);
        return 1;
    }
    std::puts("SHIM CPP TESTS OK");
    return 0;
}
