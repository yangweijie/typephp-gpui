<?php

declare(strict_types=1);

use TypePHP\Gpui\Element as El;

/*
 * Element 链式 builder 的无头单测（Pest），走 FakeBridge，不需要 gpui 或编译器。
 */

test('div defaults to div kind', function (): void {
    expect(El::div()->kind)->toBe('div');
});

test('text carries content', function (): void {
    $el = El::text('Hello');
    expect($el->kind)->toBe('text');
    expect($el->text)->toBe('Hello');
});

test('button records label as child and variant op', function (): void {
    $el = El::button('Go')->primary();
    expect($el->kind)->toBe('button');
    // 标签是子 text 元素（gpui Button 可见标签来自 children）
    expect($el->children)->toHaveCount(1);
    expect($el->children[0]->kind)->toBe('text');
    expect($el->children[0]->text)->toBe('Go');

    $found = false;
    foreach ($el->ops as $op) {
        if ($op['name'] === 'variant' && $op['args'][0] === 'primary') {
            $found = true;
        }
    }
    expect($found)->toBeTrue('primary variant op recorded');
});

test('style chain records nullary + param ops', function (): void {
    $el = El::div()->flexCol()->sizeFull()->gap(8)->itemsCenter();
    $names = array_map(fn ($o) => $o['name'], $el->ops);

    expect($names)->toContain('flex_col');
    expect($names)->toContain('items_center');
    // sizeFull 现为参数化 size + "100%"（C++ 无 nullary size_full）
    expect($names)->toContain('size');

    $gap = null;
    foreach ($el->ops as $op) {
        if ($op['name'] === 'gap') {
            $gap = $op;
        }
    }
    expect($gap)->not->toBeNull();
    expect($gap['kind'])->toBe('param_style');
    expect($gap['args'][0])->toBe(8.0);
});

test('children are nested Element instances', function (): void {
    $parent = El::vFlex([El::text('a'), El::text('b')]);
    expect($parent->children)->toHaveCount(2);
    expect($parent->children[0])->toBeInstanceOf(El::class);
});

test('onClick records a callback op', function (): void {
    $el = El::button('x')->id('x')->onClick();
    expect($el->hasCallbacks())->toBeTrue();

    $cb = null;
    foreach ($el->ops as $op) {
        if ($op['kind'] === 'callback') {
            $cb = $op;
        }
    }
    expect($cb)->not->toBeNull();
    expect($cb['name'])->toBe('on_click');
});

test('toArray nests children and id', function (): void {
    $tree = El::vFlex([El::text('hi')->font(16.0)])->id('root');
    $arr = $tree->toArray();

    expect($arr['kind'])->toBe('v_flex');
    expect($arr['id'])->toBe('root');
    expect($arr['children'])->toHaveCount(1);
    expect($arr['children'][0]['kind'])->toBe('text');
    expect($arr['children'][0]['text'])->toBe('hi');
});

// ── Stage A：C 类便捷工厂 ──

test('C-class factories emit the kinds kindFromString already recognizes', function (): void {
    $map = [
        'numberInput' => 'number_input',
        'otpInput' => 'otp_input',
        'progressTrack' => 'progress_track',
        'progressIndicator' => 'progress_indicator',
        'tableHead' => 'table_head',
        'hResizable' => 'h_resizable',
        'vResizable' => 'v_resizable',
        'resizablePanel' => 'resizable_panel',
        'popup' => 'popup',
        'radioGroup' => 'radio_group',
        'toggleGroup' => 'toggle_group',
    ];
    foreach ($map as $factory => $kind) {
        expect(El::{$factory}()->kind)->toBe($kind, $factory);
    }
    expect(El::tableCaption('total')->kind)->toBe('table_caption');
});

test('numberInput/otpInput carry id for event routing', function (): void {
    $qty = El::numberInput('qty');
    expect($qty->kind)->toBe('number_input');
    expect($qty->id)->toBe('qty');
    $otp = El::otpInput('code');
    expect($otp->kind)->toBe('otp_input');
    expect($otp->toArray()['id'])->toBe('code');
});

test('group and resizable factories nest children', function (): void {
    $rg = El::radioGroup([El::radio('a'), El::radio('b')]);
    expect($rg->children)->toHaveCount(2);
    expect($rg->toArray()['children'][0]['kind'])->toBe('radio');

    $panel = El::resizablePanel(El::text('pane'));
    expect($panel->children)->toHaveCount(1);
    expect($panel->toArray()['children'][0]['text'])->toBe('pane');

    $empty = El::resizablePanel();
    expect($empty->children)->toHaveCount(0);
});

test('tableCaption carries caption text', function (): void {
    $cap = El::tableCaption('Quarterly totals');
    expect($cap->text)->toBe('Quarterly totals');
    expect($cap->toArray()['text'])->toBe('Quarterly totals');
});

// ── Stage C：catalog（Registered）组件工厂 ──

test('catalog factories mark registered + carry export name as kind', function (): void {
    $alert = El::alert('a1', 'msg');
    expect($alert->registered)->toBeTrue();
    $arr = $alert->toArray();
    expect($arr['registered'])->toBeTrue();
    expect($arr['kind'])->toBe('Alert');
    expect($arr['ctorArgs'])->toBe(['a1', 'msg']);

    expect(El::alert('a', 'm', 'error')->toArray()['kind'])->toBe('ErrorAlert');
    expect(El::icon('i.svg')->toArray()['kind'])->toBe('Icon');
    expect(El::sidebar('s')->toArray()['kind'])->toBe('Sidebar');
    expect(El::form(true)->toArray()['kind'])->toBe('HForm');
    expect(El::menuBar('File')->toArray()['kind'])->toBe('MenuBar');
});

test('breadcrumb serializes its labels as a nested string array ctor arg', function (): void {
    $bc = El::breadcrumb(['Home', 'Library', 'Data']);
    $args = $bc->toArray()['ctorArgs'];
    expect($args)->toHaveCount(1);
    expect($args[0])->toBe(['Home', 'Library', 'Data']);
});

test('rm records a registered_method op and cb is a positional marker', function (): void {
    $el = El::alert('a', 'm')->title('Heads up')->rm('size', ['large']);
    $ops = $el->toArray()['ops'];
    $names = array_map(fn ($o) => $o['name'], $ops);
    expect($names)->toContain('title');
    expect($names)->toContain('size');
    $size = null;
    foreach ($ops as $o) {
        if ($o['name'] === 'size') { $size = $o; }
    }
    expect($size['kind'])->toBe('registered_method');
    expect($size['args'])->toBe(['large']);

    expect(El::cb('click', 'x'))->toBe(['__cb__', 'click', 'x']);
});

test('sidebar nests typed children and header/footer slots', function (): void {
    $sb = El::sidebar('sb', [
        El::sidebarMenu([
            El::sidebarMenuItem('Home')->navIcon('home'),
        ]),
    ])->slot('header', El::sidebarHeader(El::text('Workspace')));

    $arr = $sb->toArray();
    expect($arr['kind'])->toBe('Sidebar');
    // typed child SidebarMenu → its own typed child SidebarMenuItem
    expect($arr['children'][0]['kind'])->toBe('SidebarMenu');
    expect($arr['children'][0]['children'][0]['kind'])->toBe('SidebarMenuItem');
    expect($arr['children'][0]['children'][0]['ctorArgs'])->toBe(['Home']);
    // header is a slot op, not an ordinary child
    $slotNames = [];
    foreach ($arr['ops'] as $o) {
        if (($o['kind'] ?? '') === 'slot') { $slotNames[] = $o['name']; }
    }
    expect($slotNames)->toContain('header');
});

test('dropdownMenu item carries a callback marker as its second arg', function (): void {
    $dd = El::dropdownMenu('dd', 'Actions')->item('New file', 'dd-new');
    $arr = $dd->toArray();
    expect($arr['registered'])->toBeTrue();
    expect($arr['kind'])->toBe('DropdownMenu');
    expect($arr['ctorArgs'])->toBe(['dd', 'Actions']);
    $item = $arr['ops'][0];
    expect($item['kind'])->toBe('registered_method');
    expect($item['name'])->toBe('item');
    expect($item['args'][0])->toBe('New file');
    expect($item['args'][1])->toBe(['__cb__', 'click', 'dd-new']);
});

// ── P2-4：枚举预校验（render 前抛异常，而非只在 debug_tree 现形）──

test('enum sugar accepts valid literals and emits registered_method', function (): void {
    $el = El::sidebar('sb')->side('left')->collapsibleMode('icon');
    $ops = $el->toArray()['ops'];
    $names = array_map(fn ($o) => $o['name'], $ops);
    expect($names)->toContain('side');
    expect($names)->toContain('collapsible');
    // 合法枚举不抛（直接调用，若抛则测试失败）
    expect(El::field([])->align('center'))->toBeInstanceOf(El::class);
    expect(El::icon('i.svg')->sizeEnum('large'))->toBeInstanceOf(El::class);
});

test('enum sugar rejects invalid literals with InvalidArgumentException', function (): void {
    expect(fn () => El::sidebar('s')->side('middle'))->toThrow(\InvalidArgumentException::class);
    expect(fn () => El::sidebar('s')->collapsibleMode('magic'))->toThrow(\InvalidArgumentException::class);
    expect(fn () => El::sidebarMenuItem('x')->navIcon('spaceship'))->toThrow(\InvalidArgumentException::class);
    expect(fn () => El::field([])->align('justify'))->toThrow(\InvalidArgumentException::class);
    expect(fn () => El::alert('a', 'm')->sizeEnum('gigantic'))->toThrow(\InvalidArgumentException::class);
});

test('alert rejects unknown variant but accepts all known variants', function (): void {
    expect(fn () => El::alert('a', 'm', 'nope'))->toThrow(\InvalidArgumentException::class);
    foreach (array_keys(El::ALERT_VARIANTS) as $v) {
        expect(El::alert('a', 'm', $v))->toBeInstanceOf(El::class);
    }
    expect(El::alert('a', 'm', 'success')->toArray()['kind'])->toBe('SuccessAlert');
});




