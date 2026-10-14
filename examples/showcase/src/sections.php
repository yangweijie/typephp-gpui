<?php

declare(strict_types=1);

/**
 * examples/showcase/src/sections.php — 分节 builders：每节把一组特性走一遍。
 *
 * 这一堆代码的唯一存在理由是"可核对的完整性"：tests/ShowcaseTest.php 从 src/Element.php 与
 * src/App.php 反射出全部公开工厂、链式方法与 App 能力，逐个断言在这里（或 main.php 的动作里）
 * 出现过。少写一个特性，CI 就红——所以"展示了所有特性"不是自述，是断言。
 *
 * 页的划分对齐上游 examples/showcase/（41 个组件页），但这里按 PHP 面的能力分组，且只调
 * 我们**确实有**的签名：registered_method 一律走 Element.php 里已有的糖（title/description/
 * banner/item/size/color/rotate/collapsed/side/collapsibleMode/navIcon/columns/labelWidth/
 * align/sizeEnum），不凭印象造方法名。
 *
 * 约定：所有交互 id 以节名前缀，免得跨节撞车（id 是事件路由键，撞了会串到别人的 handler）。
 */

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;

/** 节清单：树号（--shot 用）→ [标题, builder 函数名]。 */
function showcaseSections(): array
{
    return [
        1 => ['基础原语与文本', 'sectionPrimitives'],
        2 => ['输入与保留态取值', 'sectionInputs'],
        3 => ['选择、开关与按钮变体', 'sectionChoices'],
        4 => ['容器、布局与滚动', 'sectionLayout'],
        5 => ['表格、列表与进度', 'sectionData'],
        6 => ['catalog 富组件', 'sectionCatalog'],
        7 => ['侧栏、表单与菜单', 'sectionChrome'],
        8 => ['浮层：dialog / sheet / toast / 通知', 'sectionOverlays'],
        9 => ['系统能力：剪贴板 · url · 文件系统 · 进程 · 存储', 'sectionSystem'],
        10 => ['服务：http · 目录监听 · 解压 · 系统状态', 'sectionServices'],
        11 => ['WebView 与 Inspector', 'sectionEmbedded'],
        12 => ['样式、图标与弹层原语', 'sectionStyling'],
        13 => ['mac 集成：托盘 · 全局快捷键 · Dock · OS 通知', 'sectionMac'],
    ];
}

// 状态表（exampleState() + ExampleState）在 examples/_shared/state.php：
// 按功能域拆出去的独立示例要共用它，所以它不属于 showcase。

// ── 1. 基础原语 ──> 已搬到 examples/_shared/features/primitives.php
//    （sectionPrimitives + wirePrimitives + primitivesTrees），独立示例 examples/text 与画廊共用那一份。

// ── 2. 输入与保留态取值 ──> 已搬到 examples/_shared/features/inputs.php
//    （sectionInputs + wireInputs + inputsTrees），独立示例 examples/input 与画廊共用那一份。

// ── 3. 选择、开关与按钮变体 ──> 已搬到 examples/_shared/features/choices.php
//    （sectionChoices + wireChoices + choicesTrees），独立示例 examples/choices 与画廊共用那一份。

// ── 4. 容器、布局与滚动 ──> 已搬到 examples/_shared/features/layout.php
//    （sectionLayout + layoutTrees），独立示例 examples/layout 与画廊共用那一份。

// ── 5. 表格、列表与进度 ──> 已搬到 examples/_shared/features/data.php
//    （sectionData + dataTrees；这一域没有点击接线），独立示例 examples/table 与画廊共用那一份。

// ── 6. catalog 富组件（Registered 机制）──> 已搬到 examples/_shared/features/catalog.php
//    （sectionCatalog + wireCatalog + catalogTrees），独立示例 examples/catalog 与画廊共用那一份。

// ── 7. 侧栏、表单与菜单 ──> 已搬到 examples/_shared/features/chrome.php
//    （sectionChrome + wireChrome + chromeTrees），独立示例 examples/chrome 与画廊共用。

// ── 8. 浮层：dialog / sheet / toast / 通知 ──> 已搬到 examples/_shared/features/overlays.php
//    （sectionOverlays + wireOverlays + overlaysTrees），独立示例 examples/overlay 与画廊共用那一份。

// ── 9. 系统能力 ──> 已搬到 examples/_shared/features/system.php
//    （sectionSystem + sectionServices + wireSystem + systemTrees），
//    独立示例 examples/system 与画廊共用那一份。

// ── 10. 服务 ──> 已搬到 examples/_shared/features/system.php（与 §9 同一份）

// ── 11. WebView 与 Inspector ──> 已搬到 examples/_shared/features/embedded.php

// ── 12. 样式、图标与弹层原语 ──> 已搬到 examples/_shared/features/styling.php
//    （sectionStyling + wireStyling + stylingTrees），独立示例 examples/style 与画廊共用那一份。

// ── 13. mac 系统集成 ──> 已搬到 examples/_shared/features/mac.php
//    （sectionMac + wireMac + macTrees），独立示例 examples/mac 与画廊共用那一份。
