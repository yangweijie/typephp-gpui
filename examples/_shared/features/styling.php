<?php

declare(strict_types=1);

/**
 * examples/_shared/features/styling.php — 功能域「样式、图标与弹层原语」。
 *
 * 从原画廊（已删除）的 §12 搬来（builder + 那四条 size 按钮的接线 + 自报的像素树号）。
 * 这一域的"对不对"大部分只能靠像素与"原生侧没报错节点"判，所以它单独成一支的主要收益是：
 * 47 个零参样式糖在**编出来的产物**里逐个调用一遍这件事，崩了会停在这一行的报告里，
 * 而不是画廊那份只在末尾打印一次的总报告。
 */

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;

/** 本域的像素树清单：树号 → [标题, builder]。 */
function stylingTrees(): array
{
    return [1 => ['样式、图标与弹层原语', 'sectionStyling']];
}

function sectionStyling(array $s): El
{
    return El::vFlex([
        El::text('样式、图标与弹层原语')->font(22.0)->semibold(),
        El::text('rounded / roundedMd / roundedLg / roundedFull / border / borderWidth / shadowSm / shadowMd / opacity / bg / textColor / borderColor')->font(11.0)->truncate(),
        El::hFlex([
            El::text('roundedFull + shadowMd')->font(12.0)->p(12.0)->bg(0xFFFFFF)->textColor(0x2F2F2F)->borderWidth(1)->borderColor(0xE0DAD1)->roundedFull()->shadowMd(),
            El::text('roundedLg + shadowSm')->font(12.0)->p(12.0)->bg(0xF3EFE8)->roundedLg()->shadowSm()->opacity(0.9),
            El::text('rounded(8) + roundedMd')->font(12.0)->p(12.0)->bg(0xE8F0E4)->rounded(8.0)->roundedMd(),
        ])->gap(14.0)->itemsCenter(),
        El::hFlex([
            El::text('underline')->font(13.0)->underline(),
            El::text('textCenter')->font(13.0)->textCenter()->w(120.0)->bg(0xF7F4EF),
            El::text('textRight')->font(13.0)->textRight()->w(120.0)->bg(0xF7F4EF),
            El::text('这是一段会被 truncate 截断的很长很长的文本，用来验证单行省略')->font(13.0)->truncate()->w(180.0),
        ])->gap(8.0)->itemsCenter(),
        El::hFlex([
            El::catalogButton('st-tooltip', '悬停有 tooltip')->onClick()->tooltip('这是 tooltip')->ghost(),
            // progress 是 kind 原语：value/size 走 method op。rm('size') 打在 kind 节点上
            // 是**没用的**（上游 RegisteredMethod op 只被 registered 节点读），所以这里不演。
            El::progress(0.5)->w(120.0)->value('0.5')->size('small'),
            // popup 是无 catalog 名的浮层原语，内容走 child。
            El::popup()->child(El::text('popup 内容')->font(12.0)->p(6.0)),
            El::vFlex([El::text('children 数组形式')->font(12.0)])->children([
                El::text('child 单个形式')->font(12.0),
            ])->gap(2.0),
        ])->gap(10.0)->itemsCenter(),
        El::text('图标名必须在编译进去的 111 个表里，错了静默什么都不画：')->font(11.0),
        El::hFlex(array_map(
            static fn (string $n): El => El::icon('icons/' . $n . '.svg')->sizeEnum('medium'),
            ['circle-check', 'triangle-alert', 'circle-x', 'info', 'star', 'heart', 'save', 'search']
        ))->gap(10.0)->itemsCenter(),
        // SIZES 全枚举：整条列表 foreach 一遍，每个取值都真传进 size()/sizeEnum()。
        // size() 是 method op（原语吃），sizeEnum() 是 catalog 组件的 registered_method。
        // 这一行演后者：catalog Button 的 size 是它自己声明的方法（xsmall/small/medium/large）。
        El::hFlex(array_map(
            static fn (string $sz): El => El::catalogButton('st-size-' . $sz, $sz)->onClick()->sizeEnum($sz),
            El::SIZES
        ))->gap(8.0)->itemsCenter(),
        // 通用 catalog 工厂 El::catalog(导出名, 构造实参) 开的是上游剩下那 100+ 个组件的门；
        // 这四颗演 Button 剩下的方法：label(string)、success/warning/compact() 零参、loading(bool)。
        // ->id() 与构造器里的 id 必须一致：事件按元素 id 路由，只给构造器不给 ->id()
        // 的话点击会落到空 id 上（El::catalogButton 把这两件事一起做掉，这里手工对齐一次）。
        El::hFlex([
            El::catalog('Button', ['st-success'])->id('st-success')->label('success')->onClick()->success(),
            El::catalog('Button', ['st-warning'])->id('st-warning')->label('warning')->onClick()->warning(),
            El::catalog('Button', ['st-compact'])->id('st-compact')->label('compact')->onClick()->compact(),
            El::catalog('Button', ['st-loading'])->id('st-loading')->label('loading')->onClick()->loading(true),
        ])->gap(8.0)->itemsCenter(),
        El::hFlex(array_map(
            static fn (string $sz): El => El::icon('icons/star.svg')->sizeEnum($sz),
            El::SIZES
        ))->gap(10.0)->itemsCenter(),
        El::text("size={$s['size']}")->font(11.0),
    ])->sizeFull()->gap(12.0)->p(16.0)->overflowYScroll();
}

/** 本域元素的接线。 */
function wireStyling(App $app): void
{
    foreach (El::SIZES as $sz) {
        $app->on('st-size-' . $sz, 'click', function () use ($sz): void {
            ExampleState::$v['size'] = $sz;
        });
    }
}
