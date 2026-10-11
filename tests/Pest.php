<?php

declare(strict_types=1);

use TypePHP\Gpui\FakeBridge;

/*
| Pest 全局配置：每个测试前重置 FakeBridge 静态状态，替代各 TestCase 的 setUp()。
| 这些无头测试走 src/FakeBridge.php（composer autoload-dev.files 注入全局 gpui_* 函数），
| 不需要 gpui-kit-cpp 或 TypePHP 编译器。
*/

uses()->beforeEach(function (): void {
    FakeBridge::reset();
});
