#include "base/popup.h"

namespace gpui {
Point PopupResolvedCorner(PopupAnchor anchor, Bounds b) {
    switch (anchor) {
        case PopupAnchor::TopLeft:
            return {b.x, b.y};
        case PopupAnchor::TopCenter:
            return {b.CenterX(), b.y};
        case PopupAnchor::TopRight:
            return {b.Right(), b.y};
        case PopupAnchor::BottomLeft:
            return {b.x, b.y - b.h};
        case PopupAnchor::BottomCenter:
            return {b.CenterX(), b.y - b.h};
        case PopupAnchor::BottomRight:
            return {b.Right(), b.y - b.h};
        default:
            // LeftCenter and RightCenter: Rust hands back the origin, since a
            // popup anchored sideways is placed by the positioner instead.
            return {b.x, b.y};
    }
}

El* PopupPlaceContent(El* content, PopupAnchor anchor, float offset) {
    if (!content) {
        return content;
    }
    // Positioner::corner with Popup's own 8 px viewport margin. The runtime
    // can measure the trigger and child in one layout pass, so it does not
    // need Rust's first-frame prepaint capture; the resulting bounds are the
    // same. Deferred is the structural equivalent of with_priority(100).
    //
    // `.occlude()`: the host blocks the mouse, so no caller has to remember —
    // what a popup covers belongs to the popup. That takes in Popover, the
    // dropdown menu over it and HoverCard in one place. The surface records
    // a hit rect ahead of its children, so its own content still hears the
    // pointer and the panel under it does not.
    return content->AnchorCorner(anchor, kPopupWindowMargin, offset)
        ->StopMouseDown()
        ->Deferred();
}

Popup* Popup::New(Ctx* cx, Str id, El* trigger, PopupAnchor anchor) {
    Arena* a = cx->a;
    Popup* p = ArenaNew<Popup>(a);
    p->a = a;
    p->anchor = anchor;
    // typephp-gpui: popup mounts same frame: contentReady 直通。Rust 的首帧预绘捕获在这里没有对应物——
    // PlaceAnchored 在 trigger 与 child 同一趟布局之后跑（PopupPlaceContent 注释
    // 「the resulting bounds are the same」），弹层尺寸当帧即可知。按元素 id 存的
    // PopupAnchorState 只为把内容推迟一帧，代价是开弹层那一帧命中表里没有弹层的
    // 任何矩形：真人那一击落到底下的页面（input 示例正下方是 in-ccal 裸日历，
    // 真人截图「下方弹层高亮 6」即它吃了误点）。合成 realClick 背靠背 + loop 泵帧
    // 跨过这一帧，所以 selftest 一直量不到。砍掉死帧，弹层可见即命中。
    p->contentReady = true;
    // Root sizes to the trigger only. Content is an overlay (Rust Positioner).
    p->root = Div(a)->Id(id);
    if (trigger) {
        p->root->Child(trigger);
    }
    return p;
}

Popup* Popup::Anchor(PopupAnchor value) {
    anchor = value;
    return this;
}

Popup* Popup::AnchorRight(bool on) {
    anchor = on ? PopupAnchor::TopRight : PopupAnchor::TopLeft;
    return this;
}

Popup* Popup::Offset(float v) {
    offset = v;
    return this;
}

Popup* Popup::OnPosition(PopupOnPositionFn fn, void* user) {
    onPosition = fn;
    onPositionUser = user;
    return this;
}

struct PopupPositionHook {
    AnchoredPlacedHook placed;
    PopupOnPositionFn fn = nullptr;
    void* user = nullptr;
};

static void PopupPlaced(void* user, AnchoredPosition placed, Bounds trigger) {
    PopupPositionHook* hook = (PopupPositionHook*)user;
    ResolvedPosition position = {};
    position.bounds = placed.bounds;
    hook->fn(hook->user, position, trigger);
}

Popup* Popup::Content(El* content) {
    if (!content || !contentReady) {
        return this;
    }
    // Positioner::corner is out of flow and deferred; in-flow content would
    // grow its trigger's page and paint below later siblings.
    if (!content->style.absolute) {
        PopupPlaceContent(content, anchor, offset);
    }
    if (onPosition) {
        // Positioner::on_position, which Rust's Popup wraps with the trigger
        // bounds it captured; here the anchored pass knows both.
        PopupPositionHook* hook = ArenaNew<PopupPositionHook>(a);
        hook->fn = onPosition;
        hook->user = onPositionUser;
        hook->placed.fn = &PopupPlaced;
        hook->placed.user = hook;
        content->onPlaced = &hook->placed;
    }
    root->Child(content);
    return this;
}

El* Popup::IntoEl() {
    return root;
}
} // namespace gpui
