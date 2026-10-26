<?php

declare(strict_types=1);

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;
use TypePHP\Gpui\FakeBridge;

/*
 * 自动化 dropbox 的无头门（Pest + FakeBridge）——task_plan.md Phase 18。
 *
 * 守的是三件事，缺一不可：
 *   ① 外部写的命令文件被执行、被删除、有回执（文件契约）；
 *   ② 按序号逐口消费、缺号先等（否则外部脚本会以为自己的命令被执行过）；
 *   ③ 产物路径逃不出 dropbox（这是给外部程序用的接口，路径必须自己管住）。
 * 真实二进制的端到端驱动另由 `gpui run hello --automation <dir>` 的演示覆盖，
 * 这里只验证 PHP 侧的编排逻辑，不依赖 gpui 或编译器。
 */

/** 起一个带 view + 点击处理器的 app，handler 命中数写进 $hits。 */
function autoApp(string $dir, int &$hits): App
{
    $app = new App();
    $app->create();
    $app->createWindow('auto', 400, 300);
    $app->view(static fn (): El => El::text('auto'));
    $app->on('inc', 'click', static function () use (&$hits): void {
        $hits++;
    });
    $app->automation($dir);

    return $app;
}

test('a widget-click command is consumed, reaches the handler, and is answered in snapshot', function (): void {
    $dir = sys_get_temp_dir() . '/gpui-auto-' . getmypid() . '-click';
    @mkdir($dir, 0755, true);
    file_put_contents($dir . '/command-1.txt', "widget-click inc\n");

    $hits = 0;
    $app = autoApp($dir, $hits);
    // 合成事件要下一帧的 pump/drain 才回到 PHP，所以给 3 帧：命令、派发、富余。
    $app->loop(3);

    expect(file_exists($dir . '/command-1.txt'))->toBeFalse();
    $snap = (string) file_get_contents($dir . '/snapshot.txt');
    expect($snap)->toContain('commands=1');
    expect($snap)->toContain('#1 widget-click inc -> clicked');
    expect($hits)->toBe(1);

    @unlink($dir . '/snapshot.txt');
    @rmdir($dir);
});

test('out-of-order commands wait for their turn instead of being skipped', function (): void {
    $dir = sys_get_temp_dir() . '/gpui-auto-' . getmypid() . '-order';
    @mkdir($dir, 0755, true);
    file_put_contents($dir . '/command-2.txt', "value inc\n");

    $app = new App();
    $app->create();
    $app->createWindow('auto', 400, 300);
    $app->automation($dir);

    // 只有 2 号：等，不跳号——跳号会让外部脚本以为 2 号已经执行过。
    $app->loop(2);
    expect(file_exists($dir . '/command-2.txt'))->toBeTrue();
    expect(file_exists($dir . '/snapshot.txt'))->toBeFalse();

    // 1 号补上：两口按序吃掉。
    file_put_contents($dir . '/command-1.txt', "value inc\n");
    $app->loop(3);
    expect(file_exists($dir . '/command-1.txt'))->toBeFalse();
    expect(file_exists($dir . '/command-2.txt'))->toBeFalse();
    $snap = (string) file_get_contents($dir . '/snapshot.txt');
    expect($snap)->toContain('#1 value inc');
    expect($snap)->toContain('#2 value inc');
    expect($snap)->toContain('commands=2');

    @unlink($dir . '/snapshot.txt');
    @rmdir($dir);
});

test('quit stops the loop and leaves a final snapshot', function (): void {
    $dir = sys_get_temp_dir() . '/gpui-auto-' . getmypid() . '-quit';
    @mkdir($dir, 0755, true);
    file_put_contents($dir . '/command-1.txt', "quit\n");

    $app = new App();
    $app->create();
    $app->createWindow('auto', 400, 300);
    $app->automation($dir);
    $app->loop(50);

    // 没有 quit 的话这一轮会跑满 50 帧；quit 必须在第一口就被认出来。
    expect($app->frameCount())->toBeLessThan(10);
    $snap = (string) file_get_contents($dir . '/snapshot.txt');
    expect($snap)->toContain('#1 quit -> bye');

    @unlink($dir . '/snapshot.txt');
    @rmdir($dir);
});

test('screenshot labels cannot escape the dropbox', function (): void {
    $dir = sys_get_temp_dir() . '/gpui-auto-' . getmypid() . '-shot';
    @mkdir($dir, 0755, true);
    // 直白的一次逃逸尝试：标签里的 / 与 .. 必须被替换掉，产物仍落在 dropbox 内。
    file_put_contents($dir . '/command-1.txt', "screenshot ../../evil\n");

    $app = new App();
    $app->create();
    $app->createWindow('auto', 400, 300);
    $app->automation($dir);
    $app->loop(2);

    expect(FakeBridge::$snapshotRequests)->toHaveCount(1);
    $path = FakeBridge::$snapshotRequests[0]['path'];
    expect($path)->toBe($dir . '/screenshot-..-..-evil.png');
    expect(str_starts_with($path, $dir . '/'))->toBeTrue();

    @unlink($dir . '/snapshot.txt');
    @rmdir($dir);
});

test('an unknown verb is answered, not thrown, and does not wedge the queue', function (): void {
    $dir = sys_get_temp_dir() . '/gpui-auto-' . getmypid() . '-verb';
    @mkdir($dir, 0755, true);
    file_put_contents($dir . '/command-1.txt', "fly to the moon\n");
    file_put_contents($dir . '/command-2.txt', "value inc\n");

    $app = new App();
    $app->create();
    $app->createWindow('auto', 400, 300);
    $app->automation($dir);
    $app->loop(4);

    $snap = (string) file_get_contents($dir . '/snapshot.txt');
    expect($snap)->toContain("#1 fly to the moon -> err unknown verb 'fly'");
    expect($snap)->toContain('#2 value inc');
    expect(file_exists($dir . '/command-1.txt'))->toBeFalse();
    expect(file_exists($dir . '/command-2.txt'))->toBeFalse();

    @unlink($dir . '/snapshot.txt');
    @rmdir($dir);
});

test('input then value round-trips through the dropbox', function (): void {
    $dir = sys_get_temp_dir() . '/gpui-auto-' . getmypid() . '-io';
    @mkdir($dir, 0755, true);
    file_put_contents($dir . '/command-1.txt', "input name hello world\n");
    file_put_contents($dir . '/command-2.txt', "value name\n");

    $app = new App();
    $app->create();
    $app->createWindow('auto', 400, 300);
    $app->automation($dir);
    $app->loop(4);

    $snap = (string) file_get_contents($dir . '/snapshot.txt');
    expect($snap)->toContain('set name len=11');
    expect($snap)->toContain('name input="hello world"');

    @unlink($dir . '/snapshot.txt');
    @rmdir($dir);
});

/*
 * 实测边界（2026-10-20，真二进制 `gpui run hello --automation` 两轮驱动）：
 *   · `input` 只往 retained 控件里**设值**，不产出 change 事件——问候语不更新就是它，
 *     因为 PHP 侧的 on('name','change') 根本没被调到。要驱动真事件走 `key`。
 *   · `focus` 在真侧返回 -1001、focused 仍为 '-'：焦点这一层依旧是缺口（与 #62/#56 的
 *     历史记录一致）。但 `key` 不依赖它也落得进输入框——本项目的键盘路由本来就有
 *     "落到哪个 retained input"的兜底。所以 focus 动词的价值主要是诊断，不是必需步骤。
 *   · `key a a` ×3 后问候语变成 "Hello, abc!"：这条链是真事件、真状态、真重渲染。
 * 下面这条只锁"动词被执行、有回执"，不锁焦点是否成功——那是 focus 自己的战场。
 */
test('focus verb is answered and never throws', function (): void {
    $dir = sys_get_temp_dir() . '/gpui-auto-' . getmypid() . '-focus';
    @mkdir($dir, 0755, true);
    file_put_contents($dir . '/command-1.txt', "focus name\n");
    file_put_contents($dir . '/command-2.txt', "focus\n");

    $app = new App();
    $app->create();
    $app->createWindow('auto', 400, 300);
    $app->automation($dir);
    $app->loop(3);

    $snap = (string) file_get_contents($dir . '/snapshot.txt');
    expect($snap)->toContain('#1 focus name -> focus name');
    expect($snap)->toContain('#2 focus -> err missing id');

    @unlink($dir . '/snapshot.txt');
    @rmdir($dir);
});

test('a non-dropbox file in the automation dir is not executed', function (): void {
    $dir = sys_get_temp_dir() . '/gpui-auto-' . getmypid() . '-junk';
    @mkdir($dir, 0755, true);
    file_put_contents($dir . '/command.txt', "quit\n");       // 没有 -<n>，不是队列条目
    file_put_contents($dir . '/command-x.txt', "quit\n");    // 序号不是数字
    file_put_contents($dir . '/snapshot.txt', "mine\n");     // 自己的回执，别被当成命令

    $app = new App();
    $app->create();
    $app->createWindow('auto', 400, 300);
    $app->automation($dir);
    $app->loop(3);

    expect($app->frameCount())->toBe(3);   // 没有被 quit 掉
    expect(file_exists($dir . '/command.txt'))->toBeTrue();
    expect(file_get_contents($dir . '/snapshot.txt'))->toBe("mine\n");

    @unlink($dir . '/command.txt');
    @unlink($dir . '/command-x.txt');
    @unlink($dir . '/snapshot.txt');
    @rmdir($dir);
});

/*
 * `change` 动词的存在理由（2026-10-20 实测补缺口）：`input` 只设值不发事件（#93），
 * `key` 走 OS 路径、在无 GUI session 的进程树里投递为 0。于是"change 能不能到 PHP"
 * 这一路在 agent/CI 环境里没有动词能验。合成 change 把"派发缝"与"OS 投递"分开。
 */
test('a change verb reaches handlers with the value it was given', function (): void {
    $dir = sys_get_temp_dir() . '/gpui-auto-' . getmypid() . '-chg';
    @mkdir($dir, 0755, true);
    file_put_contents($dir . '/command-1.txt', "change name 你好 GPUI\n");

    $hits = [];
    $app = new App();
    $app->create();
    $app->createWindow('auto', 400, 300);
    $app->on('name', 'change', static function (array $e) use (&$hits): void {
        $hits[] = (string) ($e['value'] ?? '');
    });
    $app->automation($dir);
    $app->loop(3);

    $snap = (string) file_get_contents($dir . '/snapshot.txt');
    // 命令原文是 `change name 你好 GPUI`（空格分隔），结果行才带引号。
    expect($snap)->toContain('#1 change name 你好 GPUI -> change name "你好 GPUI"');
    // 只数"handler 被调到几次"，不断言值——FakeBridge 的 selftest_fire 推的事件
    // value 恒为 null，而真 bridge 带的是输入框当前文本。这条分歧正是"两个实现"
    // 的边界：值的断言归真二进制（见本轮 input --automation 的实测），
    // 无头门只负责"派发缝通不通"。
    expect($hits)->toHaveCount(1);

    @unlink($dir . '/snapshot.txt');
    @rmdir($dir);
});
