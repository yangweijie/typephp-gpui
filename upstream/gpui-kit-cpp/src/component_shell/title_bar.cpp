// crates/component-shell/src/shell/title_bar.cpp (typephp-gpui: TitleBar kind)
// The client-drawn title bar for a window opened with WinOpts::clientTitleBar.
// The widget layer is the vendored ui/title_bar.{h,cpp}: bar height is
// kTitleBarHeight (34), the surface mixes default_title_bar_background 55%
// into the window background, the unclaimed surface is the drag region
// (ClickWinCaption), and on macOS the traffic lights are Cocoa's so
// WindowControls renders nothing. This file is the shell surface:
//   · title_bar_node_materialize — the dedicated-kind dispatch target that
//     materialize.cpp calls (children already materialized, handed in);
//   · kTitleBar — the registry descriptor (no-arg constructor + optional
//     `title` method; rows are ordinary children through the content slot)
//     so the JS catalog route can build the same bar by name.

#include "component_shell/families.h"
#include "component_shell/support.h"
#include "shell/view.h"
#include "ui/title_bar.h"

namespace gpui::component_shell {

// Shared core: build the bar and hand children through the content slot.
// `error` stays unused — the widget layer cannot fail here, and the PHP
// route reports shape problems before it ever reaches this point.
static El* BuildTitleBar(Ctx* cx, El** children, int count, ShellError* error) {
    (void)error;
    component::TitleBar* bar = component::TitleBar::New(cx);
    for (int i = 0; i < count; i++) {
        if (children[i]) bar->Child(children[i]);
    }
    return bar->IntoEl();
}

// Called from materialize.cpp's kind dispatch. The caller owns child
// materialization (MaterializeNode recursion is static to that TU).
El* title_bar_node_materialize(Ctx* cx, El** children, int count,
                               ShellError* error) {
    return BuildTitleBar(cx, children, count, error);
}

namespace title_bar {

struct TitleBarPayload {
    Str id;
};

// A `title` method call: its own payload type, visited via EachMethod
// (last call wins, the same fold window_effects runs over its Ops).
struct TitleBarText {
    Str text;
};

static El* MaterializeTitleBar(MaterializeRequest* request) {
    const TitleBarPayload* payload =
        request->PayloadAs<TitleBarPayload>();
    if (!payload) {
        return request
            ->Fail(StrL("TitleBar received an incompatible payload"));
    }
    Ctx* cx = request->cx;
    Str title;
    EachMethod<TitleBarText>(request,
                             [&](const TitleBarText& op) { title = op.text; });
    component::TitleBar* bar = nullptr;
    {
        IdScope scope(cx, payload->id);
        bar = component::TitleBar::New(cx);
    }
    if (len(title) > 0) {
        bar->Child(TextEl(cx->a, title));
    }
    El** children = nullptr;
    int count = 0;
    if (!request->TakeChildren(&children, &count)) return nullptr;
    for (int i = 0; i < count; i++) {
        if (!children[i]) continue;
        bar->Child(children[i]);
    }
    return bar->IntoEl();
}

static bool ConstructTitleBar(PayloadBuild* build,
                              const ComponentArgument*, int) {
    build->New<TitleBarPayload>()->id = StrL("title_bar");
    return true;
}

static bool RecordTitle(PayloadBuild* build, const ComponentArgument* args,
                        int count) {
    if (count != 1 || len(args[0].string) == 0)
        return build->Fail(StrL("title expects a non-empty string"));
    build->New<TitleBarText>()->text = build->Dup(args[0].string);
    return true;
}

static constexpr ArgumentDescriptor kTitleArgs[] = {
    {"title", SchemaString()}};
static constexpr const char* kTitleBarDocumentation =
    "The client-drawn title bar for a borderless window: 34px tall, theme-"
    "mixed surface, drag region where no child claimed space.";

static constexpr ConstructorDescriptor kTitleBarConstructors[] = {
    {"TitleBar", {}, &ConstructTitleBar}};
static constexpr MethodDescriptor kTitleBarMethods[] = {
    {"title", kTitleArgs, kTitleBarDocumentation, &RecordTitle}};
static constexpr ComponentDescriptor kTitleBar = {
    "TitleBar", kTitleBarConstructors, kTitleBarMethods,
    kTitleBarDocumentation, &MaterializeTitleBar};

} // namespace title_bar

bool RegisterTitleBar(shell::ComponentRegistry* registry,
                      shell::RegistryError* error) {
    return registry->Register(&title_bar::kTitleBar, error);
}

} // namespace gpui::component_shell
