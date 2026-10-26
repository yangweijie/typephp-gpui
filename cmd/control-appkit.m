/* cmd/control-appkit.m — 环境基线对照：一个 40 行的 stock AppKit 窗口，不链 gpui、不链 PHP。
 *
 * 它要回答的是一个此前无法回答的问题：**在这台机器、这条会话里，一个"教科书写法"的
 * macOS 窗口能不能收到物理 leftMouseDown？**
 *   · 收得到、我们的窗收不到 ⇒ 断点在我们（开窗路径/遮挡/泵），与环境无关；
 *   · 它也收不到 ⇒ 断点在会话/WindowServer 层，我们代码里再找是白费。
 * 之前所有结论都在我们自己进程内量，这一条把参照物放到我们代码之外。
 *
 * 两种激活策略都要量（这是本项目的一个未排除变量：我们的 app 实测 activationPolicy=1）：
 *   regular   = NSApplicationActivationPolicyRegular（正常前台应用）
 *   accessory = NSApplicationActivationPolicyAccessory（我们现在的样子）
 * accessory 应用能否被"点一下"激活、它的第一下是否投递给视图，是这条 A/B 的直接问题。
 *
 * 编译：bash cmd/build-tap-probe.sh   用法：/tmp/gpui-control-appkit [regular|accessory]
 */
#import <Cocoa/Cocoa.h>

static int g_down = 0, g_drag = 0, g_move = 0;

@interface CtrlView : NSView
@end

@implementation CtrlView
- (BOOL)acceptsFirstResponder { return YES; }
- (BOOL)acceptsFirstMouse:(NSEvent*)e { (void)e; return YES; }
- (void)logEvent:(NSString*)what event:(NSEvent*)e {
    NSWindow* w = self.window;
    fprintf(stderr, "CTRL-%s n=%d win=%ld key=%d occl=%ld loc=%.0f,%.0f t=%.3f\n",
            what.UTF8String,
            [what isEqualToString:@"DOWN"] ? g_down
                : [what isEqualToString:@"DRAG"] ? g_drag : g_move,
            (long)(w ? w.windowNumber : -1), (int)(w ? w.isKeyWindow : NO),
            (long)(w ? w.occlusionState : 0), e.locationInWindow.x,
            e.locationInWindow.y, CFAbsoluteTimeGetCurrent());
}
- (void)mouseDown:(NSEvent*)event { g_down++; [self logEvent:@"DOWN" event:event]; }
- (void)mouseDragged:(NSEvent*)event { g_drag++; [self logEvent:@"DRAG" event:event]; }
- (void)mouseMoved:(NSEvent*)event {
    g_move++;
    if (g_move <= 3 || (g_move % 25) == 0) {   /* moved 太密，只打前几条与抽样 */
        [self logEvent:@"MOVE" event:event];
    }
}
@end

int main(int argc, const char** argv) {
    @autoreleasepool {
        const char* mode = argc > 1 ? argv[1] : "regular";
        /* 必须走 sharedApplication：`[[NSApplication alloc] init]` 会 EXC_BREAKPOINT
         * （"More than one NSApplication instance was created"，实测 rc=133，
         * 且死在第一行 fprintf 之前——所以现场只有一枚空日志）。 */
        NSApplication* app = [NSApplication sharedApplication];
        [app setActivationPolicy:(0 == strcmp(mode, "accessory"))
            ? NSApplicationActivationPolicyAccessory
            : NSApplicationActivationPolicyRegular];
        NSWindow* win = [[NSWindow alloc]
            initWithContentRect:NSMakeRect(200, 200, 320, 200)
                      styleMask:(NSWindowStyleMaskTitled | NSWindowStyleMaskClosable |
                                 NSWindowStyleMaskMiniaturizable |
                                 NSWindowStyleMaskResizable)
                        backing:NSBackingStoreBuffered
                          defer:NO];
        win.title = [NSString stringWithFormat:@"Ctrl AppKit (%s)", mode];
        CtrlView* v = [[CtrlView alloc] initWithFrame:NSMakeRect(0, 0, 320, 200)];
        win.contentView = v;
        [win makeKeyAndOrderFront:nil];
        [app activateIgnoringOtherApps:YES];
        fprintf(stderr, "CTRL-READY mode=%s pid=%d win=%ld policy=%ld t=%.3f\n", mode,
                (int)getpid(), (long)win.windowNumber, (long)app.activationPolicy,
                CFAbsoluteTimeGetCurrent());
        [app run];
    }
    return 0;
}
