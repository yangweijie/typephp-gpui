// typephp-gpui — shim 纯函数 C++ 回归测试（headless，不开窗口）。
//
// 复用整个 gpui_shim.cpp TU（#include）以测其匿名命名空间里的纯函数：
// kindFromString / AStr / toComponentArg / buildComponentArgs。这些正是 bug #18
// （arena 字符串悬垂）与 Stage B schema 映射的核心，不依赖 NSWindow/显示，
// 故能在任意 CI 跑。构建见 cmd/test-shim.sh（链接 amalgam .o，不链 libgpui_shim.a）。

#include "gpui_shim.cpp"

#include <cstdio>
#include <cstring>
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

// 1) kindFromString：已映射 kind 精确、未知回落 Div。
void testKindFromString() {
    std::puts("kindFromString");
    CHECK(kindFromString("button") == K::Button);
    CHECK(kindFromString("number_input") == K::NumberInput);
    CHECK(kindFromString("otp_input") == K::OtpInput);
    CHECK(kindFromString("resizable_panel") == K::ResizablePanel);
    CHECK(kindFromString("toggle_group") == K::ToggleGroup);
    CHECK(kindFromString("totally_unknown") == K::Div);
    CHECK(kindFromString(nullptr) == K::Div);
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

// 6b) copyBounded：二进制通道唯一的截断/长度语义，剪贴板图像与解压输出都靠它。
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
    testCopyBounded();
    testGapServices();
    if (g_failures) {
        std::fprintf(stderr, "SHIM CPP TESTS FAIL (%d)\n", g_failures);
        return 1;
    }
    std::puts("SHIM CPP TESTS OK");
    return 0;
}
