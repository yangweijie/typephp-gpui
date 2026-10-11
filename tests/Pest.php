<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use TypePHP\Gpui\FakeBridge;

/*
| Pest 全局配置：每个测试前重置 FakeBridge 静态状态，替代各 TestCase 的 setUp()。
| 这些无头测试走 src/FakeBridge.php（composer autoload-dev.files 注入全局 gpui_* 函数），
| 不需要 gpui-kit-cpp 或 TypePHP 编译器。
|
| 钩子必须挂在 uses()->in() 的作用域上：beforeEach() 在 Pest.php 里注册的下标是
| Pest.php 自身这个文件名，测试文件查的是自己的下标，于是钩子静默不跑、
| FakeBridge 的静态状态跨测试泄漏。
*/

uses(TestCase::class)->in(__DIR__)->beforeEach(function (): void {
    FakeBridge::reset();
});
