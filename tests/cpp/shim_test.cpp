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

} // namespace

int main() {
    testKindFromString();
    testAStrCopiesIntoArena();
    testToComponentArg();
    testBuildComponentArgs();
    if (g_failures) {
        std::fprintf(stderr, "SHIM CPP TESTS FAIL (%d)\n", g_failures);
        return 1;
    }
    std::puts("SHIM CPP TESTS OK");
    return 0;
}
