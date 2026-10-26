<?php

declare(strict_types=1);

use TypePHP\Gpui\App;

/*
 * 交付身份的门（Pest，纯函数）——task_plan.md Phase 20。
 *
 * 守的是"缺省行为不变"这一条底线：三项都能不写，而不写时的结果必须与
 * bin/gpui 原先硬编码的值逐字相同——否则本轮就是一次静默的行为变更。
 * 另守两条安全：非法 bundle id / 非法版本号不许照写进 Info.plist，
 * 图标路径里的 ../ 不许逃出 bundle。
 */

require_once dirname(__DIR__) . '/cmd/bundle-identity.php';

/** 造一个临时示例目录，写一份可选的 yml，返回目录路径。 */
function idDir(string $suffix, ?string $yml): string
{
    $dir = sys_get_temp_dir() . '/gpui-id-' . getmypid() . '-' . $suffix;
    if (is_dir($dir)) {
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
    } else {
        // 已存在就别再 mkdir：@ 抑制不了 Pest 的错误处理器，"File exists" 会变成
        // 一条挂在无关测试名下的 warning，比它想报的真问题更扰人。
        @mkdir($dir, 0755, true);
    }
    if ($yml !== null) {
        file_put_contents($dir . '/project.macos.yml', $yml);
    }
    return $dir;
}

test('no identity keys keeps the previously hardcoded defaults', function (): void {
    $dir = idDir('defaults', "name: gpui-hello\nmode: bin\noutput: build/hello\n");
    $id = bundle_identity($dir);

    // 与改动前 appBundle() 里的硬编码逐字一致——这三行是本轮的"行为不变"契约。
    expect($id['id'])->toBe('top.typephp.gpui.' . basename($dir));
    expect($id['version'])->toBe('1.0');
    expect($id['name'])->toBe('gpui-hello');
    expect($id['icon_src'])->toBeNull();
    expect($id['icon_name'])->toBe('');
    expect($id['warnings'])->toBe([]);

    @unlink($dir . '/project.macos.yml');
    @rmdir($dir);
});

test('all three keys are honoured when present', function (): void {
    $dir = idDir('set', <<<YML
name: gpui-hello
bundle-id: com.example.hello
bundle-name: Hello 应用
bundle-version: 1.2.3
icon: assets/App.icns
YML);
    @mkdir($dir . '/assets', 0755, true);
    copy(dirname(__DIR__) . '/php-src/gpui.stub.php', $dir . '/assets/App.icns');

    $id = bundle_identity($dir);
    expect($id['id'])->toBe('com.example.hello');
    expect($id['name'])->toBe('Hello 应用');
    expect($id['version'])->toBe('1.2.3');
    // realpath 会把 /var/folders/... 归一成 /private/var/...（macOS 的 temp 是符号链接），
    // 期望值也跟着归一化——否则测的是符号链接而不是被测代码。
    expect($id['icon_src'])->toBe(realpath($dir . '/assets/App.icns'));
    expect($id['icon_name'])->toBe('App.icns');
    expect($id['warnings'])->toBe([]);

    @unlink($dir . '/assets/App.icns');
    @rmdir($dir . '/assets');
    @unlink($dir . '/project.macos.yml');
    @rmdir($dir);
});

test('a malformed bundle id falls back instead of reaching Info.plist', function (): void {
    $dir = idDir('badid', "name: gpui-hello\nbundle-id: com.example.my app!\n");
    $id = bundle_identity($dir);

    // Info.plist 里的 CFBundleIdentifier 不是"能跑就行"的字段：codesign 与
    // LaunchServices 都按它认东西。所以宁可回落缺省值并出声，不照写。
    expect($id['id'])->toBe('top.typephp.gpui.' . basename($dir));
    expect($id['warnings'])->toHaveCount(1);
    expect($id['warnings'][0])->toContain('bundle-id');

    @unlink($dir . '/project.macos.yml');
    @rmdir($dir);
});

test('a non dotted-numeric version falls back to 1.0', function (): void {
    $dir = idDir('badver', "name: gpui-hello\nbundle-version: 1.0-beta\n");
    $id = bundle_identity($dir);

    expect($id['version'])->toBe('1.0');
    expect($id['warnings'])->toHaveCount(1);
    expect($id['warnings'][0])->toContain('bundle-version');

    @unlink($dir . '/project.macos.yml');
    @rmdir($dir);
});

test('an icon path cannot escape the bundle through ..', function (): void {
    // 源文件真实存在于上一级：这一条要锁的是"目标名被净化成 basename"，
    // 于是 ../ 只能在**读取侧**指到真实文件，落盘侧永远只在 Resources/ 里。
    $parent = dirname(idDir('escape', null));
    copy(dirname(__DIR__) . '/php-src/gpui.stub.php', $parent . '/gpui-id-escape-evil.png');

    $dir = idDir('escape', "name: gpui-hello\nicon: ../gpui-id-escape-evil.png\n");
    $id = bundle_identity($dir);

    expect($id['icon_src'])->toBe(realpath($parent . '/gpui-id-escape-evil.png'));
    expect($id['icon_name'])->toBe('gpui-id-escape-evil.png');
    expect($id['icon_name'])->not->toContain('/');
    expect($id['warnings'])->toBe([]);

    @unlink($parent . '/gpui-id-escape-evil.png');
    @unlink($dir . '/project.macos.yml');
    @rmdir($dir);
});

test('a missing icon file warns and disables the icon, it does not break the build', function (): void {
    $dir = idDir('noicon', "name: gpui-hello\nicon: assets/DoesNotExist.icns\n");
    $id = bundle_identity($dir);

    expect($id['icon_src'])->toBeNull();
    expect($id['warnings'])->toHaveCount(1);
    expect($id['warnings'][0])->toContain('icon');

    @unlink($dir . '/project.macos.yml');
    @rmdir($dir);
});

test('a missing yml still yields defaults', function (): void {
    $dir = idDir('noyml', null);
    $id = bundle_identity($dir);

    expect($id['id'])->toBe('top.typephp.gpui.' . basename($dir));
    expect($id['name'])->toBe(basename($dir));
    expect($id['version'])->toBe('1.0');
    expect($id['warnings'])->toBe([]);

    @rmdir($dir);
});

test('the scaffolded yml carries the identity keys as comments a newcomer can find', function (): void {
    // gpui new 的产出是本轮交付面的一半：键必须**带注释**出现——没注释的键等于不存在，
    // 新人不会知道能配。锁的是"注释里出现这三个键名"，不是注释的措辞。
    $out = shell_exec('cd ' . escapeshellarg(sys_get_temp_dir())
        . ' && rm -rf gpui-new-check && php ' . escapeshellarg(dirname(__DIR__) . '/bin/gpui')
        . ' new gpui-new-check 2>&1');
    expect($out)->toContain('gpui-new-check');

    $yml = (string) file_get_contents(sys_get_temp_dir() . '/gpui-new-check/project.macos.yml');
    expect($yml)->toContain('#   bundle-id:');
    expect($yml)->toContain('#   bundle-name:');
    expect($yml)->toContain('#   bundle-version:');
    expect($yml)->toContain('#   icon:');

    shell_exec('rm -rf ' . escapeshellarg(sys_get_temp_dir() . '/gpui-new-check'));
});
