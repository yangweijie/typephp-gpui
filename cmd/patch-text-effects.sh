#!/usr/bin/env bash
# cmd/patch-text-effects.sh — 新建 ui/text_effects.h/.cpp（Typewriter/GradientText/
# AnimatedNumber 三个 widget）+ component_shell/controls/text.cpp 加三件 descriptor。
#
# 这是批7（唯一从零写 C++ widget 的批）：三个 widget 上游 ui 层和参考仓均无
# （grep ui/ 零命中），按 Ely spec 描述新写。三层法（照 ShimmerText 全链）：
#   1. ui 层 widget（Typewriter/GradientText/AnimatedNumber）
#   2. component_shell descriptor（controls/text.cpp）
#   3. PHP 工厂（El::typewriter/gradientText/animatedNumber）
#
# Typewriter：逐字 reveal，MotionAppear 动画，speed = ms/字符。
# GradientText：静态渐变，每字 RgbaMix(from,to,t)，无动画。
# AnimatedNumber：count_up 动画，MotionAppear 0→value，decimals+pad 格式化。
# 三者都用 MotionAppear（内部管理 state，不需额外 retained 实体——ShimmerText 先例）。
#
# 幂等：widget 文件 marker 在 + descriptor marker 在 + 注册链在 = 已打。
# 用法：GPUI_DIR=<gpui-kit-cpp> cmd/patch-text-effects.sh（默认 build/gpui-kit-cpp）

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GPUI_DIR="${GPUI_DIR:-$ROOT/build/gpui-kit-cpp}"
TEH="$GPUI_DIR/src/ui/text_effects.h"
TEP="$GPUI_DIR/src/ui/text_effects.cpp"
TXT="$GPUI_DIR/src/component_shell/controls/text.cpp"
MARK="typephp-gpui: text effects"

[[ -f "$TXT" ]] || { echo "ERROR: controls/text.cpp not found" >&2; exit 1; }

if grep -qF "$MARK" "$TEH" 2>/dev/null && grep -qF 'ComponentDescriptor kTypewriter' "$TXT" 2>/dev/null; then
    echo "text effects already patched, skip"
    exit 0
fi

echo "creating ui/text_effects.h/.cpp + descriptor..."

# ── 1) ui/text_effects.h ──
cat > "$TEH" <<'TEH_EOF'
// typephp-gpui: text effects — Typewriter / GradientText / AnimatedNumber
//
// Three text-effect widgets new-written for batch 7 (the only batch that
// writes C++ widgets from scratch in the upstream ui layer). The Ely spec
// describes them; neither the upstream tree nor the reference repo has them.

#ifndef GPUI_SRC_UI_TEXT_EFFECTS_H_
#define GPUI_SRC_UI_TEXT_EFFECTS_H_

#include "ui/sizing.h"
#include "base/animation.h"

namespace gpui {

namespace component {

// Typewriter: text that reveals characters one by one at a given speed.
// Uses MotionAppear for the reveal animation (oneshot, from the frame the
// element first appeared). Each frame, the number of revealed characters is
// chars * progress; revealed chars get the foreground color, unrevealed get
// the theme's muted foreground.
struct Typewriter {
    Arena* a = nullptr;
    Ctx* cx = nullptr;
    Str text = {};
    float speedMs = 50.0f;  // ms per character
    Str id = {};
    Rgba fg = {};
    bool hasFg = false;

    static Typewriter* New(Ctx* cx, Str text);
    Typewriter* Speed(float msPerChar);
    Typewriter* Id(Str value);
    Typewriter* Fg(Rgba color);
    El* IntoEl();
};

// GradientText: text with a static gradient from one color to another.
// Each character gets a color interpolated from `from` to `to` based on its
// position in the text. No animation — the gradient is computed once per
// materialize.
struct GradientText {
    Arena* a = nullptr;
    Ctx* cx = nullptr;
    Str text = {};
    Rgba from = {};
    Rgba to = {};
    bool hasColors = false;
    Str id = {};

    static GradientText* New(Ctx* cx, Str text);
    GradientText* Colors(Rgba from, Rgba to);
    GradientText* Id(Str value);
    El* IntoEl();
};

// AnimatedNumber: a number that counts up from 0 to the target value.
// Uses MotionAppear for the count-up animation. Each frame, the current value
// is value * progress, formatted with `decimals` decimal places and padded
// with leading zeros to `pad` digits.
struct AnimatedNumber {
    Arena* a = nullptr;
    Ctx* cx = nullptr;
    double value = 0;
    int decimals = 0;
    int pad = 0;
    Str id = {};
    Rgba fg = {};
    bool hasFg = false;

    static AnimatedNumber* New(Ctx* cx, double value, int decimals);
    AnimatedNumber* Color(Rgba color);
    AnimatedNumber* Pad(int digits);
    AnimatedNumber* Id(Str value);
    El* IntoEl();
};

} // namespace component
} // namespace gpui
#endif // GPUI_SRC_UI_TEXT_EFFECTS_H_
TEH_EOF

# ── 2) ui/text_effects.cpp ──
cat > "$TEP" <<'TEP_EOF'
// typephp-gpui: text effects implementation

#include "ui/text_effects.h"
#include "base.h"
#include "base/motion.h"
#include <cstdio>
#include <cstring>
#include <math.h>

namespace gpui {

namespace component {

// ── Typewriter ──────────────────────────────────────────────────────────

Typewriter* Typewriter::New(Ctx* cx, Str text) {
    Arena* a = cx->a;
    Typewriter* t = ArenaNew<Typewriter>(a);
    t->a = a;
    t->cx = cx;
    t->text = text;
    return t;
}

Typewriter* Typewriter::Speed(float msPerChar) {
    speedMs = msPerChar > 0.f ? msPerChar : 50.0f;
    return this;
}

Typewriter* Typewriter::Id(Str value) {
    id = value;
    return this;
}

Typewriter* Typewriter::Fg(Rgba color) {
    fg = color;
    hasFg = true;
    return this;
}

El* Typewriter::IntoEl() {
    El* container = Div(a)->MinW(0);
    const Theme& th = ThemeNow(cx->app);
    Rgba base = hasFg ? fg : th.foreground;
    if (hasFg) container->Fg(base);

    El* run = TextEl(a, text);
    container->Child(run);

    if (MotionReduced() || speedMs <= 0.f) {
        return container;
    }

    // Count UTF-8 characters.
    const char* bytes = text.s;
    int n = len(text);
    int chars = 0;
    for (int i = 0; i < n; i++) {
        if (((uint8_t)bytes[i] & 0xC0) != 0x80) chars++;
    }
    if (chars == 0) return container;

    // Get progress from MotionAppear.
    uint32_t key = len(id) > 0 ? MotionName(cx, id) : MotionName(cx, text);
    float durationMs = (float)chars * speedMs;
    float progress = MotionAppear(cx, key, durationMs, EaseLinear);

    // Compute the byte offset of the revealed character count.
    int revealed = (int)(chars * progress);
    int revealedBytes = 0;
    int charIx = 0;
    int i = 0;
    while (i < n && charIx < revealed) {
        int next = i + 1;
        while (next < n && ((uint8_t)bytes[next] & 0xC0) == 0x80) next++;
        revealedBytes = next;
        charIx++;
        i = next;
    }

    // Create two spans: revealed (visible) and unrevealed (muted).
    TextSpan* spans = (TextSpan*)Alloc(a, 2 * (int)sizeof(TextSpan));
    int nSpans = 0;
    if (revealedBytes > 0) {
        TextSpan span;
        span.lo = 0;
        span.hi = revealedBytes;
        span.color = base;
        spans[nSpans++] = span;
    }
    if (revealedBytes < n) {
        TextSpan span;
        span.lo = revealedBytes;
        span.hi = n;
        span.color = th.mutedFg;
        spans[nSpans++] = span;
    }
    if (nSpans > 0) {
        run->Spans(spans, nSpans);
    }

    if (progress < 1.f) {
        MotionWantsFrame(cx);
    }
    return container;
}

// ── GradientText ───────────────────────────────────────────────────────

GradientText* GradientText::New(Ctx* cx, Str text) {
    Arena* a = cx->a;
    GradientText* g = ArenaNew<GradientText>(a);
    g->a = a;
    g->cx = cx;
    g->text = text;
    return g;
}

GradientText* GradientText::Colors(Rgba from, Rgba to) {
    this->from = from;
    this->to = to;
    hasColors = true;
    return this;
}

GradientText* GradientText::Id(Str value) {
    id = value;
    return this;
}

El* GradientText::IntoEl() {
    El* container = Div(a)->MinW(0);
    El* run = TextEl(a, text);
    container->Child(run);

    if (!hasColors) return container;

    // Count UTF-8 characters.
    const char* bytes = text.s;
    int n = len(text);
    int chars = 0;
    for (int i = 0; i < n; i++) {
        if (((uint8_t)bytes[i] & 0xC0) != 0x80) chars++;
    }
    if (chars == 0) return container;

    // Create per-character spans with interpolated colors.
    TextSpan* spans = (TextSpan*)Alloc(a, chars * (int)sizeof(TextSpan));
    int nSpans = 0;
    int charIx = 0;
    int i = 0;
    while (i < n) {
        int next = i + 1;
        while (next < n && ((uint8_t)bytes[next] & 0xC0) == 0x80) next++;
        float t = (float)charIx / (float)(chars - 1);
        TextSpan span;
        span.lo = i;
        span.hi = next;
        span.color = RgbaMix(from, to, t);
        spans[nSpans++] = span;
        charIx++;
        i = next;
    }
    if (nSpans > 0) {
        run->Spans(spans, nSpans);
    }
    return container;
}

// ── AnimatedNumber ─────────────────────────────────────────────────────

AnimatedNumber* AnimatedNumber::New(Ctx* cx, double value, int decimals) {
    Arena* a = cx->a;
    AnimatedNumber* n = ArenaNew<AnimatedNumber>(a);
    n->a = a;
    n->cx = cx;
    n->value = value;
    n->decimals = decimals < 0 ? 0 : (decimals > 10 ? 10 : decimals);
    return n;
}

AnimatedNumber* AnimatedNumber::Color(Rgba color) {
    fg = color;
    hasFg = true;
    return this;
}

AnimatedNumber* AnimatedNumber::Pad(int digits) {
    pad = digits < 0 ? 0 : digits;
    return this;
}

AnimatedNumber* AnimatedNumber::Id(Str value) {
    id = value;
    return this;
}

El* AnimatedNumber::IntoEl() {
    El* container = Div(a)->MinW(0);
    const Theme& th = ThemeNow(cx->app);
    Rgba base = hasFg ? fg : th.foreground;
    if (hasFg) container->Fg(base);

    double current = value;
    if (!MotionReduced()) {
        uint32_t key = len(id) > 0 ? MotionName(cx, id)
                                   : MotionName(cx, StrL("animated-number"));
        float progress = MotionAppear(cx, key, 1000.0f, EaseLinear);
        current = value * (double)progress;
        if (progress < 1.f) MotionWantsFrame(cx);
    }

    // Format with decimals.
    char buf[64];
    snprintf(buf, sizeof(buf), "%.*f", decimals, current);

    // Pad the integer part with leading zeros.
    char* dot = strchr(buf, '.');
    if (pad > 0) {
        int intLen = dot ? (int)(dot - buf) : (int)strlen(buf);
        if (pad > intLen) {
            char padded[64];
            int padCount = pad - intLen;
            for (int i = 0; i < padCount; i++) padded[i] = '0';
            memcpy(padded + padCount, buf, intLen + 1);
            if (dot) {
                snprintf(buf, sizeof(buf), "%s%s", padded, dot);
            } else {
                snprintf(buf, sizeof(buf), "%s", padded);
            }
        }
    }

    El* run = TextEl(a, StrDup(a, Str(buf)));
    container->Child(run);
    return container;
}

} // namespace component
} // namespace gpui
TEP_EOF

# ── 3) component_shell/controls/text.cpp：加三件 descriptor ──
cp "$TXT" "$TXT.texteffects.bak"
python3 - "$TXT" "$MARK" <<'PYEOF'
import sys
path, mark = sys.argv[1], sys.argv[2]
src = open(path).read()

# Include the new widget header
anchor_include = '#include "ui/link.h"'
assert src.count(anchor_include) == 1, "include anchor count=%d" % src.count(anchor_include)
src = src.replace(anchor_include, anchor_include + '\n#include "ui/text_effects.h"', 1)

# ── Payload structs ──
anchor_payload = 'struct KbdOp {'
assert src.count(anchor_payload) == 1, "payload anchor count=%d" % src.count(anchor_payload)
src = src.replace(anchor_payload, '''// typephp-gpui: text effects
struct TypewriterPayload {
    Str text;
};
struct GradientTextPayload {
    Str text;
    Rgba from = {};
    Rgba to = {};
    bool hasColors = false;
};
struct AnimatedNumberPayload {
    Str id;
    double value = 0;
    int decimals = 0;
    int pad = 0;
};

''' + anchor_payload, 1)

# ── Op structs ──
anchor_ops = 'struct KbdOp {'
src = src.replace(anchor_ops, '''struct TypewriterOp {
    enum Kind : uint8_t { Speed, Id, Color } kind = Speed;
    float speed = 50.0f;
    Str id;
    Rgba color = {};
    bool hasColor = false;
};
struct GradientTextOp {
    enum Kind : uint8_t { Id } kind = Id;
    Str id;
};
struct AnimatedNumberOp {
    enum Kind : uint8_t { Color, Pad } kind = Color;
    Rgba color = {};
    bool hasColor = false;
    int pad = 0;
};

''' + anchor_ops, 1)

# ── Construct functions ──
anchor_construct = 'static bool ConstructKbd(PayloadBuild* build,'
assert src.count(anchor_construct) == 1, "construct anchor count=%d" % src.count(anchor_construct)
src = src.replace(anchor_construct, '''static bool ConstructTypewriter(PayloadBuild* build,
                              const ComponentArgument* args, int) {
    if (len(args[0].string) == 0)
        return build->Fail(StrL("Typewriter text must not be empty"));
    build->New<TypewriterPayload>()->text = args[0].string;
    return true;
}

static bool ConstructGradientText(PayloadBuild* build,
                                  const ComponentArgument* args, int count) {
    if (count != 3 || args[0].kind != shell::ComponentArgumentKind::String ||
        args[1].kind != shell::ComponentArgumentKind::String ||
        args[2].kind != shell::ComponentArgumentKind::String ||
        len(args[0].string) == 0)
        return build->Fail(StrL("GradientText expects text, from-color, "
                                "and to-color"));
    auto* payload = build->New<GradientTextPayload>();
    payload->text = args[0].string;
    if (!ParseColorArgument(build, "GradientText", args[1].string,
                            &payload->from))
        return false;
    if (!ParseColorArgument(build, "GradientText", args[2].string,
                            &payload->to))
        return false;
    payload->hasColors = true;
    return true;
}

static bool ConstructAnimatedNumber(PayloadBuild* build,
                                    const ComponentArgument* args, int count) {
    if (count != 3 || args[0].kind != shell::ComponentArgumentKind::String ||
        args[1].kind != shell::ComponentArgumentKind::Number ||
        args[2].kind != shell::ComponentArgumentKind::Number ||
        len(StrTrim(args[0].string)) == 0)
        return build->Fail(StrL("AnimatedNumber expects a non-empty id, "
                                "value, and decimals"));
    auto* payload = build->New<AnimatedNumberPayload>();
    payload->id = args[0].string;
    payload->value = args[1].number;
    payload->decimals = (int)args[2].number;
    return true;
}

''' + anchor_construct, 1)

# ── Record functions ──
anchor_record = 'static bool RecordAppearance(PayloadBuild* build,'
assert src.count(anchor_record) == 1, "record anchor count=%d" % src.count(anchor_record)
src = src.replace(anchor_record, '''static bool RecordTypewriterSpeed(PayloadBuild* build,
                                  const ComponentArgument* args, int count) {
    if (count != 1 || args[0].kind != shell::ComponentArgumentKind::Number ||
        !isfinite(args[0].number) || args[0].number < 0.0)
        return build->Fail(StrL("Typewriter.speed expects a finite "
                                "non-negative number"));
    auto* op = build->New<TypewriterOp>();
    op->kind = TypewriterOp::Speed;
    op->speed = (float)args[0].number;
    return true;
}

static bool RecordTypewriterId(PayloadBuild* build,
                               const ComponentArgument* args, int count) {
    if (count != 1 || args[0].kind != shell::ComponentArgumentKind::String ||
        len(StrTrim(args[0].string)) == 0)
        return build->Fail(StrL("Typewriter.id expects a non-empty id"));
    auto* op = build->New<TypewriterOp>();
    op->kind = TypewriterOp::Id;
    op->id = args[0].string;
    return true;
}

static bool RecordTypewriterColor(PayloadBuild* build,
                                  const ComponentArgument* args, int count) {
    if (count != 1 || args[0].kind != shell::ComponentArgumentKind::String)
        return build->Fail(StrL("Typewriter.color expects a color string"));
    Rgba color;
    if (!ParseColorArgument(build, "Typewriter", args[0].string, &color))
        return false;
    auto* op = build->New<TypewriterOp>();
    op->kind = TypewriterOp::Color;
    op->color = color;
    op->hasColor = true;
    return true;
}

static bool RecordGradientTextId(PayloadBuild* build,
                                 const ComponentArgument* args, int count) {
    if (count != 1 || args[0].kind != shell::ComponentArgumentKind::String ||
        len(StrTrim(args[0].string)) == 0)
        return build->Fail(StrL("GradientText.id expects a non-empty id"));
    auto* op = build->New<GradientTextOp>();
    op->kind = GradientTextOp::Id;
    op->id = args[0].string;
    return true;
}

static bool RecordAnimatedNumberColor(PayloadBuild* build,
                                      const ComponentArgument* args, int count) {
    if (count != 1 || args[0].kind != shell::ComponentArgumentKind::String)
        return build->Fail(StrL("AnimatedNumber.color expects a color string"));
    Rgba color;
    if (!ParseColorArgument(build, "AnimatedNumber", args[0].string, &color))
        return false;
    auto* op = build->New<AnimatedNumberOp>();
    op->kind = AnimatedNumberOp::Color;
    op->color = color;
    op->hasColor = true;
    return true;
}

static bool RecordAnimatedNumberPad(PayloadBuild* build,
                                    const ComponentArgument* args, int count) {
    if (count != 1 || args[0].kind != shell::ComponentArgumentKind::Number ||
        !isfinite(args[0].number) || args[0].number < 0.0 ||
        args[0].number > (double)INT_MAX)
        return build->Fail(StrL("AnimatedNumber.pad expects a non-negative "
                                "integer"));
    auto* op = build->New<AnimatedNumberOp>();
    op->kind = AnimatedNumberOp::Pad;
    op->pad = (int)args[0].number;
    return true;
}

''' + anchor_record, 1)

# ── Materialize functions ──
anchor_mat = 'static El* MaterializeLabel(MaterializeRequest* request) {'
assert src.count(anchor_mat) == 1, "mat anchor count=%d" % src.count(anchor_mat)
src = src.replace(anchor_mat, '''static El* MaterializeTypewriter(MaterializeRequest* request) {
    const TypewriterPayload* payload = request->PayloadAs<TypewriterPayload>();
    if (!payload)
        return request->Fail(StrL("Typewriter received an incompatible "
                                  "payload"));
    Ctx* cx = request->cx;
    component::Typewriter* typewriter =
        component::Typewriter::New(cx, payload->text);
    EachMethod<TypewriterOp>(request, [&](const TypewriterOp& op) {
        switch (op.kind) {
            case TypewriterOp::Speed:
                typewriter->Speed(op.speed);
                break;
            case TypewriterOp::Id:
                typewriter->Id(op.id);
                break;
            case TypewriterOp::Color:
                typewriter->Fg(op.color);
                break;
        }
    });
    return request->ApplyStyle(typewriter->IntoEl());
}

static El* MaterializeGradientText(MaterializeRequest* request) {
    const GradientTextPayload* payload =
        request->PayloadAs<GradientTextPayload>();
    if (!payload)
        return request->Fail(StrL("GradientText received an incompatible "
                                  "payload"));
    Ctx* cx = request->cx;
    component::GradientText* gradient =
        component::GradientText::New(cx, payload->text);
    if (payload->hasColors) {
        gradient->Colors(payload->from, payload->to);
    }
    EachMethod<GradientTextOp>(request, [&](const GradientTextOp& op) {
        if (op.kind == GradientTextOp::Id) {
            gradient->Id(op.id);
        }
    });
    return request->ApplyStyle(gradient->IntoEl());
}

static El* MaterializeAnimatedNumber(MaterializeRequest* request) {
    const AnimatedNumberPayload* payload =
        request->PayloadAs<AnimatedNumberPayload>();
    if (!payload)
        return request->Fail(StrL("AnimatedNumber received an incompatible "
                                  "payload"));
    Ctx* cx = request->cx;
    component::AnimatedNumber* number = component::AnimatedNumber::New(
        cx, payload->value, payload->decimals);
    if (payload->pad > 0) number->Pad(payload->pad);
    if (len(payload->id) > 0) number->Id(payload->id);
    EachMethod<AnimatedNumberOp>(request, [&](const AnimatedNumberOp& op) {
        switch (op.kind) {
            case AnimatedNumberOp::Color:
                number->Color(op.color);
                break;
            case AnimatedNumberOp::Pad:
                number->Pad(op.pad);
                break;
        }
    });
    return request->ApplyStyle(number->IntoEl());
}

''' + anchor_mat, 1)

# ── Descriptors ──
anchor_desc = 'static constexpr ConstructorDescriptor kKbdConstructors[] = {'
assert src.count(anchor_desc) == 1, "desc anchor count=%d" % src.count(anchor_desc)
src = src.replace(anchor_desc, '''// Typewriter
static constexpr ArgumentDescriptor kTypewriterSpeedArgs[] = {
    {"speed", SchemaNumber()}};
static constexpr ArgumentDescriptor kTypewriterIdArgs[] = {
    {"id", SchemaString()}};
static constexpr ArgumentDescriptor kTypewriterColorArgs[] = {
    {"color", SchemaString()}};
static constexpr ConstructorDescriptor kTypewriterConstructors[] = {
    {"Typewriter", kTextArgs, &ConstructTypewriter}};
static constexpr MethodDescriptor kTypewriterMethods[] = {
    {"speed", kTypewriterSpeedArgs, "Sets the reveal speed in ms per character.",
     &RecordTypewriterSpeed},
    {"id", kTypewriterIdArgs, "Sets the animation identity.",
     &RecordTypewriterId},
    {"color", kTypewriterColorArgs, "Sets the text color.",
     &RecordTypewriterColor},
};
static constexpr ComponentDescriptor kTypewriter = {
    "Typewriter", kTypewriterConstructors, kTypewriterMethods,
    "Animated text that reveals characters one by one.",
    &MaterializeTypewriter};

// GradientText
static constexpr ArgumentDescriptor kGradientTextArgs[] = {
    {"text", SchemaString()},
    {"from", SchemaString()},
    {"to", SchemaString()}};
static constexpr ArgumentDescriptor kGradientTextIdArgs[] = {
    {"id", SchemaString()}};
static constexpr ConstructorDescriptor kGradientTextConstructors[] = {
    {"GradientText", kGradientTextArgs, &ConstructGradientText}};
static constexpr MethodDescriptor kGradientTextMethods[] = {
    {"id", kGradientTextIdArgs, "Sets the element identity.",
     &RecordGradientTextId},
};
static constexpr ComponentDescriptor kGradientText = {
    "GradientText", kGradientTextConstructors, kGradientTextMethods,
    "Text with a static color gradient from one color to another.",
    &MaterializeGradientText};

// AnimatedNumber
static constexpr ArgumentDescriptor kAnimatedNumberArgs[] = {
    {"id", SchemaString()},
    {"value", SchemaNumber()},
    {"decimals", SchemaNumber()}};
static constexpr ArgumentDescriptor kAnimatedNumberColorArgs[] = {
    {"color", SchemaString()}};
static constexpr ArgumentDescriptor kAnimatedNumberPadArgs[] = {
    {"pad", SchemaNumber()}};
static constexpr ConstructorDescriptor kAnimatedNumberConstructors[] = {
    {"AnimatedNumber", kAnimatedNumberArgs, &ConstructAnimatedNumber}};
static constexpr MethodDescriptor kAnimatedNumberMethods[] = {
    {"color", kAnimatedNumberColorArgs, "Sets the text color.",
     &RecordAnimatedNumberColor},
    {"pad", kAnimatedNumberPadArgs,
     "Pads the integer part with leading zeros.",
     &RecordAnimatedNumberPad},
};
static constexpr ComponentDescriptor kAnimatedNumber = {
    "AnimatedNumber", kAnimatedNumberConstructors, kAnimatedNumberMethods,
    "A number that counts up from zero to its target value.",
    &MaterializeAnimatedNumber};

''' + anchor_desc, 1)

# ── RegisterControlsText：加三件 ──
anchor_reg = '           registry->Register(&controls::text::kKbd, error);'
assert src.count(anchor_reg) == 1, "reg anchor count=%d" % src.count(anchor_reg)
src = src.replace(anchor_reg, '''           registry->Register(&controls::text::kKbd, error) &&
           registry->Register(&controls::text::kTypewriter, error) &&
           registry->Register(&controls::text::kGradientText, error) &&
           registry->Register(&controls::text::kAnimatedNumber, error);''', 1)

open(path, "w").write(src)
print("controls/text.cpp: text effects descriptors added")
PYEOF

# ── 自检 ──
[[ $(grep -cF "$MARK" "$TEH") -eq 1 ]] || { echo "ERROR: widget header marker 没落上" >&2; exit 1; }
[[ $(grep -cF 'struct Typewriter' "$TEH") -eq 1 ]] || { echo "ERROR: Typewriter 没落上" >&2; exit 1; }
[[ $(grep -cF 'struct GradientText' "$TEH") -eq 1 ]] || { echo "ERROR: GradientText 没落上" >&2; exit 1; }
[[ $(grep -cF 'struct AnimatedNumber' "$TEH") -eq 1 ]] || { echo "ERROR: AnimatedNumber 没落上" >&2; exit 1; }
[[ $(grep -cF 'ComponentDescriptor kTypewriter' "$TXT") -eq 1 ]] \
    || { echo "ERROR: Typewriter descriptor 没落上" >&2; exit 1; }
[[ $(grep -cF 'ComponentDescriptor kGradientText' "$TXT") -eq 1 ]] \
    || { echo "ERROR: GradientText descriptor 没落上" >&2; exit 1; }
[[ $(grep -cF 'ComponentDescriptor kAnimatedNumber' "$TXT") -eq 1 ]] \
    || { echo "ERROR: AnimatedNumber descriptor 没落上" >&2; exit 1; }
[[ $(grep -cF 'registry->Register(&controls::text::kTypewriter, error)' "$TXT") -eq 1 ]] \
    || { echo "ERROR: 注册链没落上" >&2; exit 1; }
