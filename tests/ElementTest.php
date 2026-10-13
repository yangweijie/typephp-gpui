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

test('button records label as a child text element', function (): void {
    $el = El::button('Go');
    expect($el->kind)->toBe('button');
    // 标签是子 text 元素（gpui Button 可见标签来自 children）
    expect($el->children)->toHaveCount(1);
    expect($el->children[0]->kind)->toBe('text');
    expect($el->children[0]->text)->toBe('Go');
});

// 这一对是 #66 的回归位。以前 primary() 发的是 method('variant', ['primary'])，
// 而上游的 Button 变体是 catalog 的**零参方法** primary()（`variant(enum)` 属于
// DropdownButton/Tag/…）——名字两边都不认，带参未知 Method op 会被
// materialize.cpp:451-454 那条链尾无 else 的行为链静默丢掉，于是五个变体糖
// 一个像素都不改变（实测 tests/baseline/showcase/tree3.png 六颗按钮无差别）。
// 现在：kind 节点上直接抛，catalog 节点上发 registered_method。
test('variant sugar is loud on a kind node, not silently dropped', function (): void {
    expect(fn () => El::button('Go')->primary())
        ->toThrow(InvalidArgumentException::class, '只对 catalog 组件有效');
    expect(fn () => El::div()->ghost())->toThrow(InvalidArgumentException::class);
    expect(fn () => El::text('x')->outline())->toThrow(InvalidArgumentException::class);
});

test('catalogButton records the catalog channel: id ctor + label/primary methods', function (): void {
    $el = El::catalogButton('btn-p', 'Go')->onClick()->primary();
    expect($el->registered)->toBeTrue();
    expect($el->kind)->toBe('Button');                 // kind 存的是导出名
    expect($el->ctorArgs)->toBe(['btn-p']);            // Button 构造器只要一个非空 id
    expect($el->id)->toBe('btn-p');                    // 事件路由靠它

    $ops = [];
    foreach ($el->ops as $op) { $ops[$op['kind'] . ':' . $op['name']] = $op; }
    expect($ops)->toHaveKey('registered_method:label');
    expect($ops['registered_method:label']['args'])->toBe(['Go']);
    expect($ops)->toHaveKey('registered_method:primary');
    expect($ops['registered_method:primary']['args'])->toBe([]);
    expect($ops)->toHaveKey('callback:on_click');
    // 关键：不能再有 method:variant —— 那是走错通道的那个名字。
    expect($ops)->not->toHaveKey('method:variant');
});

test('the generic catalog factory reaches exports with no dedicated factory', function (): void {
    $el = El::catalog('Accordion', ['acc-1']);
    expect($el->registered)->toBeTrue();
    expect($el->kind)->toBe('Accordion');
    expect($el->ctorArgs)->toBe(['acc-1']);
    // registered 节点上 catalog 糖不再抛，发的是 registered_method。
    $badge = El::catalog('Badge')->color('#e07b39');
    $names = array_map(fn ($o) => $o['kind'] . ':' . $o['name'], $badge->ops);
    expect($names)->toContain('registered_method:color');
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
    expect(El::icon('icons/settings.svg')->toArray()['kind'])->toBe('Icon');
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
    expect(El::icon('icons/settings.svg')->sizeEnum('large'))->toBeInstanceOf(El::class);
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





test('icon() rejects the shapes gpui would silently paint nothing for', function (): void {
    // 上游查不到图标是**静默什么都不画**（AssetIconForPath 返空、没有错误通道），错了只会
    // 得到一片白，所以形状这一侧必须拦。base 在不在编译表里拦不住（那是上游 111 个的名字），
    // 由 tests/cpp/shim_test.cpp 的查表探针钉住。
    expect(fn () => El::icon('settings'))->toThrow(InvalidArgumentException::class);
    expect(fn () => El::icon('icons/settings'))->toThrow(InvalidArgumentException::class);
    expect(fn () => El::icon('icons/settings.png'))->toThrow(InvalidArgumentException::class);
    expect(El::icon('icons/settings.svg')->toArray()['ctorArgs'])->toBe(['icons/settings.svg']);
});

/**
 * 带 $id 的工厂：id 必须落在**该 kind 实际用来路由的那个字段**上。
 *
 * 两套约定（都在这条门里钉住）：
 *  - base kind（div/input/table/list/tabs/text_view/…）：bridge 的回调路由键是
 *    node['id']（gpui_bridge.cc:149,294）。id 落在 text/value 里等于这颗元素没名字，
 *    挂 ->onClick() 注册的是空 id，事件永远到不了 PHP。审核 P0-3 抓到 table/list/tabs/
 *    textView 四个工厂正是这么写的——画面正常、不报错、点着没反应。
 *  - registered 组件（alert/dropdownMenu/…）：$id 是**原生构造实参** ctorArgs[0]
 *    （schema 按位置对），事件走 item()/cb() 那条旁路，不要求 node['id']。
 * 用反射一次覆盖全部带 $id 的工厂：手写断言会漏掉下一个新加的工厂。
 */
test('every factory that takes an $id puts it where its kind routes events', function (): void {
    $rc = new ReflectionClass(El::class);
    $sentinel = 'probe-id-9';
    $base = 0;
    $registered = 0;
    foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
        // getMethods 的掩码是"命中任一标志即入选"，只写 IS_STATIC 会把 public 实例方法
        // （如 item()）一起捞进来，反射调用它们时要对象实例——所以这里显式再筛一遍。
        if (!$m->isStatic() || $m->getDeclaringClass()->getName() !== El::class || $m->isConstructor()) {
            continue;
        }
        $params = $m->getParameters();
        $hasId = false;
        foreach ($params as $p) {
            if ($p->getName() === 'id') {
                $hasId = true;
            }
        }
        if (!$hasId) {
            continue;
        }
        // 只看"返回 Element 的工厂"：cb() 这类静态辅助也带 $id，但它返回 marker 数组。
        $ret = $m->getReturnType();
        if (!$ret instanceof ReflectionNamedType
            || !in_array($ret->getName(), ['self', \TypePHP\Gpui\Element::class], true)) {
            continue;
        }
        $args = [];
        foreach ($params as $p) {
            $t = $p->getType();
            if ($p->getName() === 'id') {
                $args[] = $sentinel;
                continue;
            }
            if ($p->isDefaultValueAvailable()) {
                // 有默认值的参数一律不传：传了就要猜形状，不传则由工厂自己兜底。
                continue;
            }
            if ($t === null) {
                $args[] = 'x';
                continue;
            }
            $tn = $t instanceof ReflectionNamedType ? $t->getName() : '';
            $args[] = match ($tn) {
                'string' => 'x',
                'float' => 1.0,
                'int' => 1,
                'bool' => false,
                'array' => [],
                default => ($tn === El::class || $tn === \TypePHP\Gpui\Element::class)
                    ? El::div()
                    : 'x',
            };
        }
        $el = $m->invokeArgs(null, $args);
        expect($el)->toBeInstanceOf(El::class, $m->getName() . ' 的返回值不是 Element');
        $arr = $el->toArray();
        if (!empty($arr['registered'])) {
            expect($arr['ctorArgs'][0] ?? null)->toBe(
                $sentinel,
                $m->getName() . '() 是注册组件，$id 必须进 ctorArgs[0]（原生按位置取它）'
            );
            $registered++;
            continue;
        }
        expect($arr['id'])->toBe(
            $sentinel,
            $m->getName() . '() 的 $id 没进 id 字段（base kind 靠 node[\'id\'] 路由回调）'
        );
        $base++;
    }
    // 两类都得真跑到，别让它悄悄退化成"一个都没查"或"只查了一类"。
    expect($base)->toBeGreaterThanOrEqual(8);
    expect($registered)->toBeGreaterThanOrEqual(2);
});
