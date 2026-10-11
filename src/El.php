<?php

declare(strict_types=1);

namespace TypePHP\Gpui;

/**
 * El = Element 的简写子类，缩短链式调用书写（El::button(...)->...）。
 * 不新增成员；所有工厂与链式方法都继承自 Element。
 */
class El extends Element
{
}
