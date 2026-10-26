<?php

declare(strict_types=1);

/**
 * cmd/bundle-identity.php — 从 project.macos.yml 读交付身份（bundle id / 名称 / 版本 / 图标）。
 *
 * 为什么单拎一个文件：这三项原先硬编码在 bin/gpui 的 appBundle() 里，于是每支示例共用一个
 * bundle id 前缀、版本永远是 1.0、应用永远没有图标。CLI 脚本本身没法被 Pest 单测，
 * 所以解析逻辑放这里当纯函数库，bin/gpui 只负责用它填 Info.plist。
 *
 * 可选键（写在 examples/<ex>/project.macos.yml 顶层）：
 *   bundle-id:      com.example.myapp      # 缺省 top.typephp.gpui.<example>
 *   bundle-name:    我的应用                # 缺省 yml 的 name: 值
 *   bundle-version: 1.2.0                   # 缺省 1.0
 *   icon:           assets/App.icns         # 相对示例目录；缺失则跳过，不进 Resources
 *
 * 返回的 icon_src/icon_name 是拆开的两半：name 是**目标 basename**（净化过的），
 * 调用方把它拼到 Contents/Resources/ 后面。把净化放在这里而不是调用方，是为了让
 * "路径里的 ../ 逃不出 bundle" 这条不变式可被单测——放在 bin/gpui 里就测不到了。
 *
 * 解析刻意不用 YAML 库：yml 现在是手写的 `key: value` 小格式，加解析器是过度工程。
 * 畸形值按"没写这个键"处理并记一条 warning——不让一个坏键把整个构建炸掉，
 * 但也不静默：调用方把 warnings 打出来。
 */

if (!function_exists('bundle_identity')) {
    /**
     * @return array{id:string,name:string,version:string,icon_src:?string,icon_name:string,warnings:list<string>}
     */
    function bundle_identity(string $exampleDir): array
    {
        $example = basename(rtrim($exampleDir, '/'));
        $warnings = [];

        $yml = $exampleDir . '/project.macos.yml';
        $keys = [];
        if (is_file($yml)) {
            foreach (file($yml, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                if (!preg_match('/^([a-z0-9-]+):[ \t]*(.*)$/', $line, $m)) {
                    continue;
                }
                $keys[trim($m[1])] = trim($m[2]);
            }
        }

        // bundle id：不合法的identifier 会让 codesign 与 LaunchServices 出怪事，
        // 所以只收 [A-Za-z0-9.-]，其余回落缺省并警告（而不是照写进 Info.plist）。
        $id = 'top.typephp.gpui.' . $example;
        if (isset($keys['bundle-id']) && $keys['bundle-id'] !== '') {
            $raw = $keys['bundle-id'];
            if (preg_match('/^[A-Za-z0-9.-]+$/', $raw) === 1) {
                $id = $raw;
            } else {
                $warnings[] = "bundle-id 含非法字符（只用 A-Za-z0-9.-）：'{$raw}'，已回落缺省值";
            }
        }

        $name = $keys['name'] ?? $example;
        if (isset($keys['bundle-name']) && $keys['bundle-name'] !== '') {
            $name = $keys['bundle-name'];
        }

        $version = '1.0';
        if (isset($keys['bundle-version']) && $keys['bundle-version'] !== '') {
            if (preg_match('/^\d+(\.\d+)*$/', $keys['bundle-version']) === 1) {
                $version = $keys['bundle-version'];
            } else {
                $warnings[] = "bundle-version 不是数字点分（如 1.2.0）：'{$keys['bundle-version']}'，已回落 1.0";
            }
        }

        // 图标：源路径照解析，但**目标名只取 basename**——路径里带 ../ 的话，产物就能被
        // 写到 bundle 外面去。这一半的净化放在库里，正是为了让它可被单测。
        $iconSrc = null;
        $iconName = '';
        if (isset($keys['icon']) && $keys['icon'] !== '') {
            $abs = $keys['icon'][0] === '/' ? $keys['icon'] : $exampleDir . '/' . $keys['icon'];
            if (is_file($abs)) {
                // realpath 归一化：`示例目录/../x.png` 这种拼法能读也能写，但把带 .. 的
                // 原样返回给调用方只是把理解成本推给别人。目标名仍然只取 basename。
                $iconSrc = realpath($abs) ?: $abs;
                $iconName = basename($iconSrc);
            } else {
                $warnings[] = "icon 指向的文件不存在：{$keys['icon']}（已跳过，bundle 无图标）";
            }
        }

        return ['id' => $id, 'name' => $name, 'version' => $version,
                'icon_src' => $iconSrc, 'icon_name' => $iconName, 'warnings' => $warnings];
    }
}
