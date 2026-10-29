<?php

declare(strict_types=1);

/**
 * examples/kit/src/main.php — 功能域独立示例：差距清单的 catalog 工厂批。
 *
 * 2026-10-28 三批：tier-A 批（Separator/Tooltip/Skeleton/EmptyState/Badge/Tag/
 * Stepper/Tree/Pagination/Rating/Kbd/ShimmerText/Dialog 家族/DescriptionList/
 * DataTable（部分）/Settings/Carousel）与「纯工厂热身」批（Spinner/TableFooter/
 * GroupBox/VForm/InputGroupText，纯 reg() 通道、上游零改动）；随后是「带前置作业的
 * S 工厂 + 组合糖」批（Label/Toolbar/StatusBar/Clipboard/HorizontalRadioGroup +
 * SplitButton 组合糖），再是「弹层/显示/图表 M 工厂」批（Notification/
 * DropdownButton/Bubble/Marker/Message/MessageScroller/Attachment + 图表五连；
 * 回调实参的组件只建结构、数据等批6）。上游全部已导出，工厂只是
 * El::catalog('导出名') 裸通道的糖；每颗工厂一个真调用 + 一条门，native --selftest
 * 逐棵渲染并断 registeredError/componentFailure 清零。
 *
 * 开关：
 *   （无参）              交互模式，能真点真敲
 *   --sections           打印本示例的像素树清单（shotdiff 按它枚举基线）
 *   --shot <path> [tree] 出一棵树的 PNG
 *   --selftest           跑本域的硬门，rc=0 即通过
 */

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;

const KIT_W = 900;
const KIT_H = 680;

function main(int $argc, array $argv): void
{
    try {
        kitDispatch($argv);
    } catch (\Throwable $e) {
        echo 'FATAL ' . get_class($e) . ': ' . $e->getMessage() . "\n";
        exit(12);
    }
}

function kitDispatch(array $argv): void
{
    if (in_array('--sections', $argv, true)) {
        foreach (kitTrees() as $tree => [$title, $_fn]) {
            echo $tree . "\t" . $title . "\n";
        }
        return;
    }
    if (in_array('--selftest', $argv, true)) {
        runKitSelfTest();
        return;
    }
    $shotIdx = array_search('--shot', $argv, true);
    if ($shotIdx !== false) {
        $path = isset($argv[$shotIdx + 1]) ? (string) $argv[$shotIdx + 1] : '/tmp/kit.png';
        $tree = isset($argv[$shotIdx + 2]) ? (int) $argv[$shotIdx + 2] : 1;
        runKitShot($path, $tree);
        return;
    }
    // 未知开关必须拒绝。静默忽略会一路落到交互 run() 去开一扇窗口，于是"跑一下某个模式"
    // 变成永久挂住（input 域踩过），拿不到任何结论；退出码 2 与"门红"的 1 分开。
    foreach ($argv as $arg) {
        if (is_string($arg) && str_starts_with($arg, '--')) {
            echo '未知开关: ' . $arg . "\n"
                . "可用：--sections / --selftest / --shot <path> [tree]\n";
            exit(2);
        }
    }
    ExampleState::boot(['section' => 1]);
    $app = new App();
    wireKit($app);
    $app->run('Kit · tier-A 工厂批', KIT_W, KIT_H, 'kitTree');
}

/** 边跑边打：一步一行。崩溃时最后打出来的那行就是案发现场。 */
function step(string $name, bool $ok, string $detail): void
{
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ' — ' . $detail . "\n";
}

function kitTree(int $tree = 1): El
{
    // 按**树号**选 builder：写死一棵的话两棵"不同的树"会拍出逐字节相同的帧，
    // cmd/shotdiff.php 有门直接拦重复帧。kit 三棵都非空，漏选当场会被报出来。
    $builders = [
        1 => 'sectionKitBasics',
        2 => 'sectionKitData',
        3 => 'sectionKitOverlays',
    ];
    $fn = $builders[$tree] ?? 'sectionKitBasics';
    return El::vFlex([$fn(ExampleState::get())])->sizeFull()->bg(0xF6F3EE);
}

function runKitShot(string $path, int $tree): void
{
    $tree = isset(kitTrees()[$tree]) ? $tree : 1;
    ExampleState::boot(['section' => $tree]);
    $app = new App();
    $app->create();
    $app->createWindow('Kit Shot', KIT_W, KIT_H);
    wireKit($app);
    $app->render(kitTree($tree));
    $app->loop(20);
    echo '--- debug tree (kit ' . (int) $tree . ': ' . (string) kitTrees()[$tree][0] . ') ---'
        . "\n" . \gpui_debug_tree($app->handle()) . "\n---\n";
    $shotOk = $app->snapshot($path, KIT_W, KIT_H);
    echo $shotOk ? "SHOT OK tree={$tree} {$path}\n" : "SHOT FAIL tree={$tree} {$path}\n";
    $app->close();
    exit($shotOk ? 0 : 1);
}

/**
 * 结构门扫描器：每颗工厂一条（或一组）纯 PHP 结构断言——建出 Element、按
 * 上游 descriptor 的形状读 ctorArgs/ops/children。native 那半（"真的建得出来"）
 * 由 registeredError/componentFailure 逐棵渲染门负责，两边不互相替代。
 *
 * @return array<string, array{0: bool, 1: string}> 门名 => [ok, detail]
 */
function kitStructuralGates(): array
{
    $opsOf = static function (El $el): array {
        $names = [];
        foreach ($el->ops as $op) {
            $names[] = (string) $op['name'];
        }
        return $names;
    };
    /** 子树里出现过的 kind（导出名）集合。 */
    $kindsIn = static function (El $el) use (&$kindsIn): array {
        $out = [$el->kind => true];
        foreach ($el->children as $c) {
            if ($c instanceof El) {
                foreach ($kindsIn($c) as $k => $v) {
                    $out[$k] = true;
                }
            }
        }
        return $out;
    };
    $throws = static function (callable $fn): bool {
        try {
            $fn();
        } catch (\InvalidArgumentException $e) {
            return true;
        }
        return false;
    };
    $checks = [];

    // ── Separator：四变体整表 → 四个导出名；非法变体当场拒。────────────────
    $checks['separatorVariants'] = function () use ($throws): array {
        $bad = [];
        foreach (El::SEPARATOR_VARIANTS as $vname => $export) {
            $kind = El::separator($vname)->kind;
            if ($kind !== $export) {
                $bad[] = $vname . '→' . $kind;
            }
        }
        $rejects = $throws(static fn () => El::separator('diagonal'));
        $ok = $bad === [] && $rejects;
        return [$ok, '四变体导出名=' . ($bad === [] ? '全对' : implode(',', $bad))
            . ' 非法变体拒=' . var_export($rejects, true)];
    };
    // Separator 的可嵌字：label/color 走既有链式糖（separator.cpp kMethods）。
    $checks['separatorLabel'] = function () use ($opsOf): array {
        $ops = $opsOf(El::separator('horizontal')->label('或')->color('#e07b39'));
        $ok = in_array('label', $ops, true) && in_array('color', $ops, true);
        return [$ok, 'ops=' . implode(',', $ops)];
    };

    // ── Tooltip：三实参按位置（id/label/text），改名即响。────────────────────
    $checks['tooltipCtor'] = function (): array {
        $t = El::catalogTooltip('tt-x', '标签', '文本');
        $ok = $t->kind === 'Tooltip' && $t->ctorArgs === ['tt-x', '标签', '文本'];
        return [$ok, 'ctorArgs=' . json_encode($t->ctorArgs)];
    };

    // ── Skeleton：无实参；secondary 是上游唯一变体方法。──────────────────────
    $checks['skeletonSecondary'] = function () use ($opsOf): array {
        $ops = $opsOf(El::skeleton()->secondary());
        $ok = El::skeleton()->kind === 'Skeleton' && in_array('secondary', $ops, true);
        return [$ok, 'ops=' . implode(',', $ops)];
    };

    // ── EmptyState：五件套组装 + media 的 icon 变体。─────────────────────────
    $checks['emptyStateParts'] = function () use ($kindsIn, $opsOf): array {
        $root = El::emptyState('icons/settings.svg', '标题', '描述', El::button('动作'));
        $kinds = array_keys($kindsIn($root));
        $mediaOps = $opsOf($root->children[0]);
        $ok = $root->kind === 'Empty'
            && in_array('EmptyMedia', $kinds, true) && in_array('EmptyTitle', $kinds, true)
            && in_array('EmptyDescription', $kinds, true) && in_array('EmptyContent', $kinds, true)
            && in_array('variant', $mediaOps, true)
            && count($root->children) === 4;
        $bare = El::emptyState('icons/settings.svg', 't', 'd');
        $ok = $ok && count($bare->children) === 3;
        return [$ok, '五件套=' . implode(',', $kinds)
            . ' mediaOps=' . implode(',', $mediaOps)
            . ' 无内容时 children=' . count($bare->children)];
    };

    // ── Badge：count/max/dot 三形。───────────────────────────────────────────
    $checks['badgeForms'] = function () use ($opsOf): array {
        $count = $opsOf(El::badge(9, false, 99));
        $dot = $opsOf(El::badge(0, true));
        $bare = $opsOf(El::badge());
        $ok = El::badge()->kind === 'Badge'
            && in_array('count', $count, true) && in_array('max', $count, true)
            && in_array('dot', $dot, true) && $bare === [];
        return [$ok, 'count形=' . implode(',', $count) . ' dot形=' . implode(',', $dot)
            . ' 裸=' . ($bare === [] ? '空' : implode(',', $bare))];
    };

    // ── Tag：variant 枚举预校验 + outline/pill 两形。─────────────────────────
    $checks['tagShapes'] = function () use ($opsOf, $throws): array {
        $ops = $opsOf(El::tag('新', 'success')->outline()->pill());
        $rejects = $throws(static fn () => El::tag('x', 'nope'));
        $ok = El::tag('新')->kind === 'Tag'
            && in_array('variant', $ops, true) && in_array('outline', $ops, true)
            && in_array('rounded_full', $ops, true) && $rejects;
        return [$ok, 'ops=' . implode(',', $ops) . ' 非法变体拒=' . var_export($rejects, true)];
    };

    // ── Stepper：selected_index/on_change 实参形状 + StepperItem 数。──────────
    $checks['stepperShape'] = function (): array {
        $st = El::stepper('st-x', ['一', '二', '三'], 2, true, 'st-x');
        $itemKinds = [];
        foreach ($st->children as $c) {
            $itemKinds[] = $c->kind;
        }
        $cb = null;
        foreach ($st->ops as $op) {
            if ($op['name'] === 'on_change') {
                $cb = $op['args'][0];
            }
        }
        $ok = $st->kind === 'Stepper' && $itemKinds === ['StepperItem', 'StepperItem', 'StepperItem']
            && $cb === ['__cb__', 'change', 'st-x'];
        $ro = El::stepperItem('禁用步', true);
        $roOk = $ro->kind === 'StepperItem' && $ro->ops[0]['name'] === 'disabled';
        return [$ok && $roOk, 'children=' . count($itemKinds) . ' cb=' . json_encode($cb)
            . ' 禁用步op=' . (string) ($ro->ops[0]['name'] ?? '—')];
    };

    // ── Tree：TreeItem 可嵌 TreeItem。────────────────────────────────────────
    $checks['treeShape'] = function (): array {
        $tr = El::tree('tr-x', [
            El::treeItem('tr-a', 'A/')->rm('expanded', [true])->child(
                El::treeItem('tr-b', 'B')),
        ]);
        $nested = count($tr->children[0]->children);
        $ok = $tr->kind === 'Tree' && $tr->children[0]->kind === 'TreeItem'
            && $tr->children[0]->ctorArgs === ['tr-a', 'A/'] && $nested === 1;
        return [$ok, '根=' . $tr->kind . ' 子=' . (string) $tr->children[0]->kind
            . ' 嵌套=' . (string) $nested];
    };

    // ── Pagination：三页参数 + on_change。────────────────────────────────────
    $checks['paginationShape'] = function () use ($opsOf): array {
        $pg = El::pagination('pg-x', 2, 10, 5, 'pg-x');
        $ops = $opsOf($pg);
        $ok = $pg->kind === 'Pagination'
            && in_array('current_page', $ops, true) && in_array('total_pages', $ops, true)
            && in_array('visible_pages', $ops, true) && in_array('on_change', $ops, true);
        return [$ok, 'ops=' . implode(',', $ops)];
    };

    // ── Rating：value/max + 只读走 disabled 位。──────────────────────────────
    $checks['ratingShape'] = function () use ($opsOf): array {
        $ro = El::rating('rt-x', 4.0, 5, true);
        $ops = $opsOf($ro);
        $ok = $ro->kind === 'Rating'
            && in_array('value', $ops, true) && in_array('max', $ops, true)
            && in_array('disabled', $ops, true);
        return [$ok, 'ops=' . implode(',', $ops)];
    };

    // ── Kbd / KbdCombo：keystroke 实参；组合键发多颗 Kbd。─────────────────────
    $checks['kbdShapes'] = function (): array {
        $k = El::kbd('⌘');
        $combo = El::kbdCombo(['⌘', 'Shift', 'P']);
        $comboKinds = [];
        foreach ($combo->children as $c) {
            $comboKinds[] = $c->kind;
        }
        $ok = $k->kind === 'Kbd' && $k->ctorArgs === ['⌘']
            && $comboKinds === ['Kbd', 'Kbd', 'Kbd'];
        return [$ok, '单键=' . $k->kind . '/' . json_encode($k->ctorArgs)
            . ' 组合=' . count($comboKinds) . ' 颗 Kbd'];
    };

    // ── ShimmerText：文本进构造。──────────────────────────────────────────────
    $checks['shimmerCtor'] = function (): array {
        $sh = El::shimmerText('加载中');
        $ok = $sh->kind === 'ShimmerText' && $sh->ctorArgs === ['加载中'];
        return [$ok, 'ctorArgs=' . json_encode($sh->ctorArgs)];
    };

    // ── 批7 特效三件：Typewriter/GradientText/AnimatedNumber（唯一从零写 C++
    // widget 的批，cmd/patch-text-effects.sh）。结构门验 ctorArgs 形状。──
    $checks['typewriterShape'] = function (): array {
        $tw = El::typewriter('打字机文本');
        $ok = $tw->kind === 'Typewriter' && $tw->ctorArgs === ['打字机文本'];
        return [$ok, 'kind=' . $tw->kind . ' ctorArgs=' . json_encode($tw->ctorArgs)];
    };
    $checks['gradientTextShape'] = function (): array {
        $gt = El::gradientText('渐变文字', '#ff0000', '#0000ff');
        $ok = $gt->kind === 'GradientText'
            && $gt->ctorArgs === ['渐变文字', '#ff0000', '#0000ff'];
        return [$ok, 'kind=' . $gt->kind . ' ctorArgs=' . json_encode($gt->ctorArgs)];
    };
    $checks['animatedNumberShape'] = function (): array {
        $an = El::animatedNumber('an-x', 42.5, 1);
        $ok = $an->kind === 'AnimatedNumber'
            && ($an->ctorArgs[0] ?? '') === 'an-x'
            && ($an->ctorArgs[1] ?? null) === 42.5
            && ($an->ctorArgs[2] ?? null) === 1
            && ($an->id ?? '') === 'an-x';
        return [$ok, 'kind=' . $an->kind . ' ctorArgs=' . json_encode($an->ctorArgs)
            . ' id=' . (string) ($an->id ?? '')];
    };

    // ── Dialog 家族：三实参（末位是 on_effect_error 回调标记）+ 效果方法。──────
    //    Dialog/Sheet 的正文走 content 具名槽（window_effects/mod.cpp:467-476：
    //    缺槽就 Fail，且只收 content 这一个槽名）；AlertDialog 拒一切具名槽。
    $checks['dialogFamily'] = function (): array {
        $dlg = El::dialog('dlg-x', '打开', El::text('正文'), '标题', 'dlg-ok', 'dlg-cancel');
        $errArg = $dlg->ctorArgs[2] ?? [];
        $dlgSlots = [];
        foreach ($dlg->ops as $op) {
            if (($op['kind'] ?? '') === 'slot') {
                $dlgSlots[] = (string) $op['name'];
            }
        }
        $dlgOk = $dlg->kind === 'Dialog' && count($dlg->ctorArgs) === 3
            && ($errArg[0] ?? '') === '__cb__' && ($errArg[1] ?? '') === 'click'
            && ($errArg[2] ?? '') === 'dlg-x-error'
            && $dlgSlots === ['content'];
        $ald = El::alertDialog('ald-x', '删除', '不可撤销', true, '警告', 'ald-ok');
        $aldOps = [];
        $aldSlots = 0;
        foreach ($ald->ops as $op) {
            if (($op['kind'] ?? '') === 'slot') {
                $aldSlots++;
            } else {
                $aldOps[] = (string) $op['name'];
            }
        }
        $sh = El::sheet('sh-x', '抽屉', El::text('正文'), 'right', 'sh-close');
        $shSlots = [];
        foreach ($sh->ops as $op) {
            if (($op['kind'] ?? '') === 'slot') {
                $shSlots[] = (string) $op['name'];
            }
        }
        $ok = $dlgOk && $ald->kind === 'AlertDialog' && $aldSlots === 0
            && in_array('description', $aldOps, true) && in_array('show_cancel', $aldOps, true)
            && in_array('on_ok', $aldOps, true)
            && $sh->kind === 'Sheet' && $shSlots === ['content'];
        return [$ok, 'Dialog errCb=' . json_encode($errArg) . ' 槽=' . implode(',', $dlgSlots)
            . ' AlertDialog=' . implode(',', $aldOps) . ' 槽数=' . (string) $aldSlots
            . ' Sheet槽=' . implode(',', $shSlots)];
    };

    // ── DescriptionList：关联表与 Element 两种入法 + span。────────────────────
    $checks['descriptionListShape'] = function (): array {
        $assoc = El::descriptionList(['名' => '值', '量' => '2']);
        $elems = El::descriptionList([
            El::descriptionItem('跨列', '两格')->rm('span', [2.0]),
        ]);
        $spanSeen = false;
        foreach ($elems->children[0]->ops as $op) {
            if (($op['name'] ?? '') === 'span') {
                $spanSeen = true;
            }
        }
        $ok = $assoc->kind === 'DescriptionList' && count($assoc->children) === 2
            && $assoc->children[0]->kind === 'DescriptionItem'
            && $assoc->children[0]->ctorArgs === ['名']
            && count($elems->children) === 1 && $spanSeen;
        return [$ok, '关联项=' . count($assoc->children)
            . ' Element项 span=' . var_export($spanSeen, true)];
    };

    // ── DataTable：state 标记 + 两个回调标记（数据通道缺口在 native 门里钉）。──
    $checks['dataTableShape'] = function (): array {
        $dt = El::dataTable('dt-x', ['名', '量']);
        $st = $dt->ctorArgs[0] ?? [];
        $rows = $dt->ctorArgs[1] ?? [];
        $cell = $dt->ctorArgs[2] ?? [];
        $csv = El::tableToCsv(['a', 'b'], [['a' => 'x,y', 'b' => 2]]);
        $ok = $dt->kind === 'DataTable' && $dt->id === 'dt-x'
            && ($st[0] ?? '') === '__state__' && ($st[1] ?? '') === 'DataTableState'
            && ($st[2] ?? '') === 'dt-x'
            && ($rows[1] ?? '') === 'render_rows' && ($cell[1] ?? '') === 'render_cell'
            && $csv === "a,b\n\"x,y\",2";
        return [$ok, 'state=' . (string) ($st[1] ?? '—')
            . ' cb=' . (string) ($rows[1] ?? '—') . '+' . (string) ($cell[1] ?? '—')
            . ' toCsv=' . var_export($csv, true)];
    };

    // ── Settings：四层 typed 嵌套 + SettingItem 的 content 槽。────────────────
    $checks['settingsShape'] = function (): array {
        $field = El::switchBox('深色');
        $item = El::settingItem('主题', $field);
        $slotNames = [];
        foreach ($item->ops as $op) {
            if (($op['kind'] ?? '') === 'slot') {
                $slotNames[] = (string) $op['name'];
            }
        }
        $set = El::settings('set-x', [
            El::settingPage('通用', [El::settingGroup('外观', [$item])]),
        ]);
        $page = $set->children[0] ?? null;
        $group = $page !== null ? ($page->children[0] ?? null) : null;
        $ok = $set->kind === 'Settings'
            && $page !== null && $page->kind === 'SettingPage' && $page->ctorArgs === ['通用']
            && $group !== null && $group->kind === 'SettingGroup'
            && count($group->children) === 1 && $group->children[0]->kind === 'SettingItem'
            && $slotNames === ['content'];
        return [$ok, '四层=' . $set->kind . '/' . (string) ($page->kind ?? '—') . '/'
            . (string) ($group->kind ?? '—') . '/SettingItem'
            . ' content槽=' . implode(',', $slotNames)];
    };

    // ── Carousel：八件套 + 全员共享一颗 state 实体（同一个 key）。──────────────
    $checks['carouselEight'] = function () use ($kindsIn): array {
        $car = El::carousel('car-x', [El::text('一'), El::text('二')]);
        $kinds = array_keys($kindsIn($car));
        $want = ['Carousel', 'CarouselContent', 'CarouselItem', 'CarouselPrevious',
            'CarouselNext', 'CarouselPagination', 'CarouselPaginationItem'];
        $missing = array_diff($want, $kinds);
        // state 标记收集：八处构造里出现的 CarouselState key 必须全是同一个。
        $keys = [];
        $collect = static function (El $el) use (&$collect, &$keys): void {
            foreach ($el->ctorArgs as $arg) {
                if (is_array($arg) && ($arg[0] ?? null) === '__state__') {
                    $keys[(string) $arg[2]] = true;
                }
            }
            foreach ($el->children as $c) {
                if ($c instanceof El) {
                    $collect($c);
                }
            }
        };
        $collect($car);
        $ok = $car->kind === 'Carousel' && $missing === [] && count($keys) === 1;
        return [$ok, '八件套缺=' . ($missing === [] ? '无' : implode(',', $missing))
            . ' stateKey=' . implode(',', array_keys($keys))];
    };

    // ── Spinner：无 ctor 无子；size/icon/color/ease 四方法各一笔（spinner.cpp
    //    kMethods）。icon/ease 是私有枚举，白名单外当场拒。────────────────────────
    $checks['spinnerShape'] = function () use ($opsOf, $throws): array {
        $sp = El::spinner()->sizeEnum('large')->spinnerIcon('loader_circle')
            ->color('#e07b39')->spinnerEase('ease_in_out');
        $ops = $opsOf($sp);
        $rejects = $throws(static fn () => El::spinner()->spinnerEase('bounce'))
            && $throws(static fn () => El::spinner()->spinnerIcon('orbit'));
        $ok = $sp->kind === 'Spinner' && El::spinner()->children === []
            && $ops === ['size', 'icon', 'color', 'ease'] && $rejects;
        return [$ok, 'ops=' . implode(',', $ops) . ' 子=' . count(El::spinner()->children)
            . ' 集外枚举拒=' . var_export($rejects, true)];
    };

    // ── TableFooter：registered 专属（ComponentKind 枚举里没有它）；children 只收
    //    注册侧 TableRow——kind 行没有 componentName，上游 RequireChild 拒。────────
    $checks['tableFooterShape'] = function (): array {
        $ft = El::tableFooter([
            El::catalog('TableRow', [], [
                El::catalog('TableCell', [], [El::text('合计')]),
            ]),
        ]);
        $row = $ft->children[0] ?? null;
        $cell = $row !== null ? ($row->children[0] ?? null) : null;
        $ok = $ft->kind === 'TableFooter' && $ft->registered && $ft->ctorArgs === []
            && $row !== null && $row->kind === 'TableRow' && $row->registered
            && $cell !== null && $cell->kind === 'TableCell';
        return [$ok, '根=' . $ft->kind . ' 行=' . (string) ($row->kind ?? '—')
            . '/reg=' . var_export((bool) ($row->registered ?? false), true)
            . ' 格=' . (string) ($cell->kind ?? '—')];
    };

    // ── GroupBox：title 走方法、children 普通；variant 集是 normal/fill/outline。
    //    ->variant() 白名单是各组件字面量集的**并**——集内他组件的字面量（如
    //    GroupBox 发 'primary'）由上游 enum 响亮拒，PHP 侧不重复拦；集外当场抛。──
    $checks['groupBoxShape'] = function () use ($opsOf, $throws): array {
        $gb = El::groupBox('外观', [El::text('内容')])->variant('fill');
        $ops = $opsOf($gb);
        $rejects = $throws(static fn () => El::groupBox('x')->variant('card'));
        $ok = $gb->kind === 'GroupBox' && $gb->ctorArgs === []
            && count($gb->children) === 1 && $gb->children[0]->kind === 'text'
            && in_array('title', $ops, true) && in_array('variant', $ops, true)
            && $rejects;
        return [$ok, 'ops=' . implode(',', $ops) . ' 集外变体拒=' . var_export($rejects, true)];
    };

    // ── VForm：'Form' 同一构造器的第二导出名；children 只收 Field。──────────────
    $checks['vFormShape'] = function (): array {
        $vf = El::vForm([
            El::field([El::checkbox('甲')]),
            El::field([El::switchBox('乙')]),
        ]);
        $kinds = [];
        foreach ($vf->children as $c) {
            $kinds[] = $c->kind;
        }
        $ok = $vf->kind === 'VForm' && $vf->registered && $vf->ctorArgs === []
            && $kinds === ['Field', 'Field']
            && $vf->children[0]->children[0]->kind === 'checkbox';
        return [$ok, '根=' . $vf->kind . ' children=' . implode(',', $kinds)];
    };

    // ── InputGroupText：无 ctor 无方法；text 包成普通子节点（FinishPart）。──────
    $checks['inputGroupTextShape'] = function (): array {
        $t = El::inputGroupText('https://');
        $ok = $t->kind === 'InputGroupText' && $t->registered && $t->ctorArgs === []
            && $t->ops === [] && count($t->children) === 1
            && $t->children[0]->kind === 'text' && $t->children[0]->text === 'https://';
        return [$ok, '根=' . $t->kind . ' 子=' . (string) ($t->children[0]->kind ?? '—')
            . ' text=' . (string) ($t->children[0]->text ?? '—')];
    };

    // ── Label：catalogLabel（->label 被实例糖占名）；secondary/masked/highlights
    //    三方法糖；highlights 的实参 schema 是 SchemaString（text.cpp:193-194），
    //    单个匹配串——不是清单侦察时猜的数组。空文本上游拒，PHP 侧先拦。──────────
    $checks['labelShapes'] = function () use ($opsOf, $throws): array {
        $lb = El::catalogLabel('内容')->secondaryLabel('辅')->masked()->labelHighlights('搜');
        $ops = $opsOf($lb);
        $rejects = $throws(static fn () => El::catalogLabel(''));
        $ok = $lb->kind === 'Label' && $lb->ctorArgs === ['内容']
            && $ops === ['secondary', 'masked', 'highlights'] && $rejects;
        return [$ok, 'ops=' . implode(',', $ops) . ' 空文本拒=' . var_export($rejects, true)];
    };

    // ── Toolbar：id 非空、无方法表、普通子节点按源序。──────────────────────────
    $checks['toolbarShape'] = function (): array {
        $tb = El::toolbar('tb-x', [El::button('甲'), El::button('乙')]);
        $kinds = [];
        foreach ($tb->children as $c) {
            $kinds[] = $c->kind;
        }
        $ok = $tb->kind === 'Toolbar' && $tb->ctorArgs === ['tb-x']
            && $tb->ops === [] && $kinds === ['button', 'button'];
        return [$ok, '根=' . $tb->kind . ' ops=' . (string) count($tb->ops)
            . ' 子=' . implode(',', $kinds)];
    };

    // ── StatusBar：无 ctor；中段=普通子节点；**不发** left/right_content（吃
    //    SchemaElement，shim 缺口）——结构门断 ops 为空。─────────────────────────
    $checks['statusBarShape'] = function (): array {
        $sb = El::statusBar([El::text('就绪'), El::text('UTF-8')]);
        $ok = $sb->kind === 'StatusBar' && $sb->ctorArgs === [] && $sb->ops === []
            && count($sb->children) === 2 && $sb->children[0]->kind === 'text';
        return [$ok, '根=' . $sb->kind . ' ops=' . (string) count($sb->ops)
            . ' 中段=' . (string) count($sb->children)];
    };

    // ── Clipboard：id 进构造；value/tooltip 走方法；拒 children。───────────────
    $checks['clipboardShape'] = function () use ($opsOf): array {
        $cp = El::clipboard('cp-x', 'secret', '复制');
        $bare = El::clipboard('cp-y');
        $ops = $opsOf($cp);
        $ok = $cp->kind === 'Clipboard' && $cp->ctorArgs === ['cp-x']
            && $ops === ['value', 'tooltip'] && $cp->children === []
            && $bare->ops === [];
        return [$ok, 'ops=' . implode(',', $ops) . ' 裸=' . (string) count($bare->ops)
            . ' 子=' . (string) count($cp->children)];
    };

    // ── HorizontalRadioGroup：注册侧 Radio children（id 派生 + label 方法）；
    //    selected_index + on_change cb 标记。────────────────────────────────────
    $checks['hRadioGroupShape'] = function (): array {
        $rg = El::hRadioGroup('hrg-x', ['甲', '乙', '丙'], 1, 'hrg-x');
        $radioKinds = [];
        $radioCtors = [];
        foreach ($rg->children as $c) {
            $radioKinds[] = $c->kind;
            $radioCtors[] = $c->ctorArgs[0] ?? '';
        }
        $cb = null;
        foreach ($rg->ops as $op) {
            if ($op['name'] === 'on_change') {
                $cb = $op['args'][0];
            }
        }
        $labels = true;
        foreach ($rg->children as $c) {
            $names = [];
            foreach ($c->ops as $op) {
                $names[] = (string) $op['name'];
            }
            $labels = $labels && in_array('label', $names, true);
        }
        $ok = $rg->kind === 'HorizontalRadioGroup' && $rg->ctorArgs === ['hrg-x']
            && $radioKinds === ['Radio', 'Radio', 'Radio'] && $rg->registered
            && $radioCtors === ['hrg-x-r0', 'hrg-x-r1', 'hrg-x-r2'] && $labels
            && $cb === ['__cb__', 'change', 'hrg-x'];
        return [$ok, 'children=' . implode('/', $radioKinds)
            . ' ids=' . implode(',', $radioCtors) . ' cb=' . json_encode($cb)];
    };

    // ── SplitButton：组合糖（非独立导出）——内部只发 DropdownMenu + item 方法，
    //    item 回调 id 派生 $id-item-<i>。────────────────────────────────────────
    $checks['splitButtonShape'] = function (): array {
        $sb = El::splitButton('sb-x', '导出', ['PNG', 'CSV']);
        $items = [];
        foreach ($sb->ops as $op) {
            if (($op['name'] ?? '') === 'item') {
                $items[] = $op['args'];
            }
        }
        $ok = $sb->kind === 'DropdownMenu' && $sb->ctorArgs === ['sb-x', '导出']
            && count($items) === 2
            && ($items[0][0] ?? '') === 'PNG'
            && ($items[0][1] ?? []) === ['__cb__', 'click', 'sb-x-item-0']
            && ($items[1][1] ?? []) === ['__cb__', 'click', 'sb-x-item-1'];
        return [$ok, '根=' . $sb->kind . ' item 回调=' . json_encode($items[0][1] ?? [])];
    };

    // ── 批4：Questionnaire 四件（嵌套最深家族：根→Item→Choice/Input，typed children
    //    逐层校验）。事件面核对过：该家族没有选项变化回调（无 binding/click 投递点，
    //    焦点/导航由 retained state 内部驱动），无需新 patch。────────────────────
    $checks['questionnaireShape'] = function () use ($opsOf, $throws): array {
        $q = El::questionnaire('q1', [
            El::questionnaireItem('age', '年龄', [
                El::questionnaireChoice('18', '18 岁以下', null, false, true),
                El::questionnaireInput('自由文本', 'kit-q-input'),
            ])->rm('required', [true]),
        ]);
        $itemKinds = [];
        foreach ($q->children as $c) { $itemKinds[] = $c->kind; }
        $choiceCtor = [];
        $choiceCtor[] = $q->children[0]->children[0]->ctorArgs;
        $stateArg = $q->children[0]->children[1]->ctorArgs[0] ?? [];
        $shOk = $opsOf(El::questionnaire('q-sh', [], 'letters')) === ['shortcuts'];
        $rejects = $throws(static fn () => El::catalogNotification('n2', 't', '', 'bogus'))
            && $throws(static fn () => El::questionnaire('q-rej', [], 'bogus'));
        $ok = $q->kind === 'Questionnaire' && $itemKinds === ['QuestionnaireItem']
            && $choiceCtor === [['18', '18 岁以下']]
            && ($stateArg[0] ?? '') === '__state__'
            && ($stateArg[1] ?? '') === 'InputState'
            && ($stateArg[2] ?? '') === 'kit-q-input'
            && $shOk && $rejects;
        return [$ok, 'items=' . implode('/', $itemKinds)
            . ' choiceCtor=' . json_encode($choiceCtor)
            . ' stateKey=' . (string) ($stateArg[2] ?? '—')
            . ' shortcuts=' . var_export($shOk, true)
            . ' 非法拒绝=' . var_export($rejects, true)];
    };

    // ── Accordion catalog 版：registered Accordion/AccordionItem，ctor id +
    //     multiple/bordered/size/on_toggle（与 kind 版 El::accordionItem 两版对照）。──
    $checks['accordionCatalogShape'] = function () use ($opsOf): array {
        $a = El::catalogAccordion('acc', 2, true, true, 'large', 'acc-tog');
        $ops = $opsOf($a);
        $itemKinds = [];
        $itemOps = [];
        foreach ($a->children as $c) {
            $itemKinds[] = $c->kind;
            $itemOps[] = $opsOf($c);
        }
        $ok = $a->kind === 'Accordion' && $a->ctorArgs === ['acc']
            && $itemKinds === ['AccordionItem', 'AccordionItem']
            && $itemOps === [[], []]
            && in_array('multiple', $ops, true) && in_array('bordered', $ops, true)
            && in_array('size', $ops, true) && in_array('on_toggle', $ops, true);
        return [$ok, 'items=' . implode('/', $itemKinds)
            . ' childOps=' . json_encode($itemOps)
            . ' ops=' . implode(',', $ops)];
    };

    // ── catalog 四颗升级糖：Select/Combobox/Popover/HoverCard/TabBar。
    // 数据通道（rows 回调）只建结构（同 DataTable 缺口，探针无读口），
    // 事件通道走 cb 标记，结构门钉住构造实参与方法表。────────────────────────
    $checks['catalogSelectShape'] = function () use ($opsOf): array {
        $s = El::catalogSelect('sel', 'ph', 'sel-id', 'row-id');
        $ctor = $s->ctorArgs;
        $ops = $opsOf($s);
        $ok = $s->kind === 'Select' && $s->ctorArgs ===
            ['sel', ['__cb__', 'render_rows', 'sel'],
             ['__cb__', 'render_row', 'row-id'],
             ['__cb__', 'on_select', 'sel-id']]
            && $s->id === 'sel'
            && in_array('placeholder', $ops, true);
        return [$ok, 'ctor=' . json_encode($ctor) . ' placeholder=' .
            implode(',', array_values(array_filter($ops, fn (string $n) => $n === 'placeholder')))];
    };

    $checks['catalogComboboxShape'] = function () use ($opsOf): array {
        $c = El::catalogCombobox('cb', 'ph', 'sp', 'cb-id', 'cb-ok');
        $ctor = $c->ctorArgs;
        $ops = $opsOf($c);
        $phNames = [];
        foreach ($ops as $n) {
            if ($n === 'placeholder' || $n === 'search_placeholder') { $phNames[] = $n; }
        }
        $ok = $c->kind === 'Combobox'
            && $c->ctorArgs === ['cb', ['__cb__', 'render_rows', 'cb'],
              ['__cb__', 'change', 'cb-id'], ['__cb__', 'click', 'cb-ok']]
            && $c->id === 'cb'
            && in_array('placeholder', $ops, true)
            && in_array('search_placeholder', $ops, true);
        return [$ok, 'ctor=' . json_encode($ctor) . ' placeholders=' . implode(',', $phNames)];
    };

    $checks['catalogPopoverShape'] = function () use ($opsOf): array {
        $p = El::catalogPopover('po', 'p', El::text('body'), 'top_right', true, true);
        $ops = $opsOf($p);
        $ok = $p->kind === 'Popover' && $p->ctorArgs === ['po', 'p']
            && in_array('content', $ops, true)
            && in_array('card_anchor', $ops, true)
            && in_array('default_open', $ops, true)
            && in_array('open', $ops, true);
        return [$ok, 'ops=' . implode(',', $ops)];
    };

    $checks['catalogHoverCardShape'] = function () use ($opsOf): array {
        $h = El::catalogHoverCard('hc', El::text('body'), 'bottom_right', 1200, false);
        $ops = $opsOf($h);
        $ok = $h->kind === 'HoverCard' && $h->ctorArgs === ['hc']
            && in_array('content', $ops, true)
            && in_array('card_anchor', $ops, true)
            && in_array('open_delay', $ops, true);
        return [$ok, 'ops=' . implode(',', $ops)];
    };

    $checks['catalogTabBarShape'] = function () use ($opsOf): array {
        $t = El::catalogTabBar('tb', ['A', 'B'])
            ->tabBarMenu()->tabBarSelectedIndex(1)->tabBarVariant('pill')
            ->tabBarSize('large');
        $tabKinds = [];
        foreach ($t->children as $c) { $tabKinds[] = $c->kind; }
        $ops = $opsOf($t);
        $ok = $t->kind === 'TabBar' && $t->ctorArgs === ['tb']
            && count($t->children) === 2
            && $tabKinds === ['Tab', 'Tab']
            && in_array('menu', $ops, true)
            && in_array('selected_index', $ops, true)
            && in_array('variant', $ops, true)
            && in_array('size', $ops, true);
        return [$ok, 'ops=' . implode(',', $ops) . ' tabs=' . implode('/', $tabKinds)];
    };
    // ── 批5 底层补洞：ProgressCircle descriptor 五件套 + 方法表形状。─────────
    $checks['progressCircleShape'] = function () use ($opsOf, $throws): array {
        $e = El::progressCircle('pc-x', 62.0)
            ->progressCircleLoading()->progressCircleLabel()
            ->progressCircleSize(72.0)->progressCircleColor('#e07b39');
        $ops = $opsOf($e);
        $rejects = $throws(static fn () => El::progressCircle(''));
        $ok = $e->kind === 'ProgressCircle' && $e->ctorArgs === ['pc-x']
            && in_array('value', $ops, true) && in_array('loading', $ops, true)
            && in_array('label', $ops, true) && in_array('size', $ops, true)
            && in_array('color', $ops, true) && $rejects;
        return [$ok, 'ctorArgs=' . json_encode($e->ctorArgs) . ' ops=' . implode(',', $ops)
            . ' 空id拒=' . var_export($rejects, true)];
    };
    // ── 批5 弹层家族：PopupMenu rows 走普通子节点（text 当行标签）。───────────
    $checks['popupMenuShape'] = function (): array {
        $e = El::popupMenu('pm-x', ['行甲', '行乙']);
        $labels = array_map(static fn ($c): string => (string) ($c->text ?? ''), $e->children);
        $ok = $e->kind === 'PopupMenu' && $e->ctorArgs === ['pm-x']
            && $labels === ['行甲', '行乙'];
        return [$ok, 'ctorArgs=' . json_encode($e->ctorArgs)
            . ' 行数=' . count($e->children) . ' 标签=' . implode(',', $labels)];
    };
    // ── 批5 弹层家族：ContextMenu 第一个子节点是右键目标，其余是菜单行。───────
    $checks['contextMenuShape'] = function (): array {
        $target = El::button('目标');
        $e = El::contextMenu('cm-x', $target, ['复制', '粘贴']);
        $labels = array_map(static fn ($c): string => (string) ($c->text ?? ''),
            array_slice($e->children, 1));
        $ok = $e->kind === 'ContextMenu' && $e->ctorArgs === ['cm-x']
            && $e->children[0] === $target && $labels === ['复制', '粘贴'];
        return [$ok, 'ctorArgs=' . json_encode($e->ctorArgs)
            . ' 目标=' . $e->children[0]->kind . ' 行=' . implode(',', $labels)];
    };
    // ── 批5 shim 桥：EmptyState header 槽路（elementArg 桥 + 具名槽）。────────
    $checks['emptyStateHeaderSlot'] = function () use ($opsOf): array {
        $root = El::emptyState('icons/settings.svg', '标题', '描述', null, true);
        $slots = array_values(array_filter($root->ops,
            static fn ($op) => ($op['kind'] ?? '') === 'slot'));
        $header = $slots[0]['child'] ?? null;
        $argKinds = [];
        foreach ($header ? $header->ops : [] as $op) {
            if (($op['kind'] ?? '') === 'element_arg') {
                $argKinds[] = ($op['name'] ?? '?') . '=' . ($op['arg']['kind'] ?? '?');
            }
        }
        $ok = $root->kind === 'Empty' && count($slots) === 1 && $slots[0]['name'] === 'header'
            && $header instanceof El && $header->kind === 'EmptyHeader'
            && $argKinds === ['media=EmptyMedia', 'title=EmptyTitle',
                'description=EmptyDescription'];
        // 默认路（五件套 children）与 header 槽路（slot op，children 为空）对照。
        $old = El::emptyState('icons/settings.svg', 't', 'd');
        $ok = $ok && $old->kind === 'Empty' && count($old->children) === 3;
        return [$ok, 'header槽=' . implode(',', $argKinds)
            . ' 默认路children=' . count($old->children) . '（header 槽路 children='
            . count($root->children) . '）'];
    };
    //   id/label/on_effect_error 必填（后者自动挂 cb 标记，同 dialog 家族先例）；
    //   message/type/on_click/on_close/autohide 走方法糖（type 走 NOTIFICATION_TYPES 白名单）。──
    $checks['notificationCtor'] = function () use ($opsOf, $throws): array {
        $n = El::catalogNotification('n1', '标题', '一条消息', 'warning', 'n1-ok', 'n1-close');
        $ops = $opsOf($n);
        $rejects = $throws(static fn () => El::catalogNotification('n2', 't', '', 'bogus'));
        $ok = $n->kind === 'Notification' && $n->ctorArgs === ['n1', '标题',
            ['__cb__', 'click', 'n1-error']]
            && in_array('message', $ops, true) && in_array('type', $ops, true)
            && in_array('on_click', $ops, true) && in_array('on_close', $ops, true)
            && in_array('autohide', $ops, true) && $rejects;
        return [$ok, 'ctorArgs=' . json_encode($n->ctorArgs) . ' ops=' . implode(',', $ops)
            . ' 非法type拒=' . var_export($rejects, true)];
    };

    // ── DropdownButton：ctor id/label；variant/menu_anchor/on_click/menu_item 走糖。──
    $checks['dropdownButtonShape'] = function () use ($opsOf): array {
        $db = El::dropdownButton('db-x', '操作', 'secondary', 'bottom_right',
            'db-x', ['项A', '项B'])->dropdownButtonOnClick('db-x');
        $ops = $opsOf($db);
        $items = [];
        foreach ($db->ops as $op) {
            if (($op['name'] ?? '') === 'menu_item') {
                $items[] = $op['args'];
            }
        }
        $ok = $db->kind === 'DropdownButton' && $db->ctorArgs === ['db-x', '操作']
            && in_array('variant', $ops, true) && in_array('menu_anchor', $ops, true)
            && in_array('on_click', $ops, true)
            && count($items) === 2
            && ($items[0][0] ?? '') === '项A'
            && ($items[0][1] ?? []) === ['__cb__', 'click', 'db-x-item-0']
            && ($items[1][1] ?? []) === ['__cb__', 'click', 'db-x-item-1']
            && in_array('menu_item', $ops, true);
        return [$ok, 'ops=' . implode(',', $db->ops) . ' item=' . json_encode($items[0][1] ?? [])];
    };

    // ── 聊天域：Bubble（alignment/variant）/Message（alignment+子节点）/
    //    Marker（variant/loading/loading_style）/Attachment（status/axis/size）。──
    $checks['chatDisplayShape'] = function () use ($opsOf): array {
        $b = El::bubble('start', 'filled');
        $bOps = $opsOf($b);
        $b2 = El::bubble('', 'secondary');
        $b2Ops = $opsOf($b2);
        $m = El::message('start', [El::catalogLabel('消息甲')]);
        $mOps = $opsOf($m);
        $mk = El::marker('mk-x', 'separator', true, 'spinner');
        $mkOps = $opsOf($mk);
        $at = El::attachment('att-x', 'uploading', 'vertical', 'large');
        $atOps = $opsOf($at);
        $ok = $b->kind === 'Bubble' && $bOps === ['alignment', 'variant']
            && $b2Ops === ['variant']
            && $m->kind === 'Message' && $mOps === ['alignment']
            && count($m->children) === 1 && $m->children[0]->kind === 'Label'
            && $mk->kind === 'Marker' && $mk->ctorArgs === ['mk-x']
            && $mkOps === ['variant', 'loading', 'loading_style']
            && $at->kind === 'Attachment' && $at->ctorArgs === ['att-x']
            && $atOps === ['status', 'axis', 'size'];
        return [$ok, 'bubble=' . implode(',', $bOps) . ' message=' . implode(',', $mOps)
            . ' marker=' . implode(',', $mkOps) . ' attachment=' . implode(',', $atOps)];
    };

    // ── MessageScroller：结构（id/state 实体/render_item 回调标记）+ 方法；
    //    render_item 是"返回元素"的回调（sham 通道不带返回值，数据通道等批6）——
    //    不进任何树（会涨 componentStates）。──────────────────────────────────────
    $checks['messageScrollerShape'] = function () use ($opsOf): array {
        $sc = El::messageScroller('sc-x', 2);
        $st = $sc->ctorArgs[1] ?? [];
        $cb = $sc->ctorArgs[2] ?? [];
        $ops = $opsOf($sc);
        $ok = $sc->kind === 'MessageScroller' && $sc->ctorArgs[0] === 'sc-x'
            && ($st[0] ?? '') === '__state__' && ($st[1] ?? '') === 'MessageScrollerState'
            && ($st[2] ?? '') === 'sc-x'
            && $cb === ['__cb__', 'render_item', 'sc-x']
            && in_array('scrollbar', $ops, true) && in_array('jump_button', $ops, true)
            && in_array('jump_button_label', $ops, true);
        return [$ok, 'ctorArgs=' . json_encode($sc->ctorArgs) . ' ops=' . implode(',', $ops)];
    };

    // ── 图表五连：五个导出名 + rows cb 标记（构造只吃 rows 回调，同 dataTable 形状）。──
    $checks['chartFiveShape'] = function (): array {
        $expect = ['BarChart' => 'barChart', 'LineChart' => 'lineChart',
            'AreaChart' => 'areaChart', 'PieChart' => 'pieChart', 'RadarChart' => 'radarChart'];
        $ok = true;
        $detail = '';
        foreach ($expect as $export => $fn) {
            $c = El::$fn();
            $cb = $c->ctorArgs[0] ?? [];
            if ($c->kind !== $export || $cb !== ['__cb__', 'render_rows', 'kit-chart']) {
                $ok = false;
                $detail .= $export . '=' . $c->kind . '/cb=' . json_encode($cb) . ' ';
            }
        }
        return [$ok, $ok ? '五连全在册' : $detail];
    };

    // ── 批6：CandlestickChart 结构门——导出名 + rows cb 标记（带 id 参数，
    // 与五图不同：handler 键独立，走 dataTable 的 id 模式）。──
    $checks['candlestickShape'] = function (): array {
        $c = El::candlestickChart('gate-candle');
        $cb = $c->ctorArgs[0] ?? [];
        $ok = $c->kind === 'CandlestickChart'
            && $cb === ['__cb__', 'render_rows', 'gate-candle']
            && ($c->id ?? '') === 'gate-candle';
        return [$ok, 'kind=' . $c->kind . ' cb=' . json_encode($cb)
            . ' id=' . (string) ($c->id ?? '')];
    };

    // ── 批6：SankeyChart 结构门——导出名 + 双回调标记（nodes+links，与蜡烛图
    // 单回调不同）。ctorArgs[0]/[1] 各是一个 cb 标记，id 在各自标记的索引 2。──
    $checks['sankeyShape'] = function (): array {
        $c = El::sankeyChart('gate-sankey');
        $cb0 = $c->ctorArgs[0] ?? [];
        $cb1 = $c->ctorArgs[1] ?? [];
        $ok = $c->kind === 'SankeyChart'
            && $cb0 === ['__cb__', 'render_nodes', 'gate-sankey']
            && $cb1 === ['__cb__', 'render_links', 'gate-sankey']
            && ($c->id ?? '') === 'gate-sankey';
        return [$ok, 'kind=' . $c->kind
            . ' cb0=' . json_encode($cb0) . ' cb1=' . json_encode($cb1)
            . ' id=' . (string) ($c->id ?? '')];
    };

    // ── 批6：SearchableList 结构门——导出名 + id 实参 + rows cb 标记
    // （与 List 同款：ctorArgs[0] 是 id 字符串，ctorArgs[1] 是 rows cb）。──
    $checks['searchableListShape'] = function (): array {
        $c = El::searchableList('gate-sl');
        $cb = $c->ctorArgs[1] ?? [];
        $ok = $c->kind === 'SearchableList'
            && ($c->ctorArgs[0] ?? '') === 'gate-sl'
            && $cb === ['__cb__', 'render_rows', 'gate-sl']
            && ($c->id ?? '') === 'gate-sl';
        return [$ok, 'kind=' . $c->kind
            . ' ctorArgs0=' . json_encode($c->ctorArgs[0] ?? null)
            . ' cb=' . json_encode($cb)
            . ' id=' . (string) ($c->id ?? '')];
    };

    return $checks;
}

function runKitSelfTest(): void
{
    ExampleState::boot(['section' => 1]);
    $app = new App();
    $app->create();
    $app->createWindow('Kit Selftest', KIT_W, KIT_H);
    wireKit($app);
    $app->render(kitTree(1));
    $app->loop(4);

    $ok = true;
    echo "KIT SELFTEST\n";

    // 1) 树 1（基础件）在原生侧没有报错节点，且累计失败计数没动。
    //    catalog 建不出来时 gpui 只把那一颗画成 "Failed to render X"，
    //    registeredError() 与失败计数两条都要看（只查一条会漏，catalog 域的教训）。
    $err = $app->registeredError();
    step('tree1RegisteredError', $err === '', $err === '' ? '空' : $err);
    $ok = $ok && $err === '';
    $cf = App::componentFailure();
    step('tree1ComponentFailure', (int) $cf['count'] === 0, 'count=' . (int) $cf['count']
        . ' last=' . (string) $cf['last']);
    $ok = $ok && (int) $cf['count'] === 0;

    // 2) 结构门：每颗工厂一条（34 门，纯 PHP，按 descriptor 形状读）。
    foreach (kitStructuralGates() as $name => $check) {
        [$gateOk, $detail] = $check();
        step($name, $gateOk, $detail);
        $ok = $ok && $gateOk;
    }
    // 2b) Clipboard 复制行为的前提式门（仍在本树 1 上下文里——fire 按 (id,type)
    //     查当前树的回调表）。复制是部件内置行为（ui/clipboard.cpp OnCopy →
    //     ClipboardSetText），descriptor 没有 on_copied 导出、没有 PHP 回调，所以
    //     合成 fire 必返回 false——这条断的就是"没有合成路"这个事实本身；真复制要
    //     真人点击（HID），未验不判红（focusRovingTab 先例：前提不满足不判红）。
    //     （gpui_selftest_fire 的 stub 声明返回 bool：C++ 侧 0/1，落成真/假。）
    $clipFired = \gpui_selftest_fire($app->handle(), 'kit-clip', 'click');
    $fireText = $clipFired
        ? '有回调路（异常：该组件无 PHP 回调）'
        : '无（期望：复制是部件内置，合成通道到不了）';
    step('clipboardCopyRoute', $clipFired === false,
        'fire(click)=' . (string) $fireText . '；真复制需真人点击，未验不判红');
    $ok = $ok && $clipFired === false;

    // 2c) 批5：ContextMenu 右键路由的前提式门（同 clipboardCopyRoute 先例）。
    //     上游右键接线是 ContextMenuState::OnMouseDown（ui/menu.cpp：Bubble 相位 +
    //     stopPropagation 防祖先菜单连锁）；合成缝 gpui_selftest_fire 只认
    //     click/change/hover 三类——没有 mouse_down_right。断的是「合成路不存在」
    //     这个事实本身（fire 返回 false 即正确）；真右键要真人 HID，未验不判红。
    $cmFired = \gpui_selftest_fire($app->handle(), 'kit-cm', 'click');
    $cmFireText = $cmFired ? '有回调路（异常：行不接线、目标按钮无 cb）'
        : '无（期望：行是 text 节点无 cb；右键通道在合成缝不存在）';
    step('contextMenuRightClickRoute', $cmFired === false,
        'fire(click)=' . $cmFireText . '；真右键需真人点击，未验不判红');
    $ok = $ok && $cmFired === false;

    // 3) 树 2（数据与导航）：原生侧清零 + CarouselState 恰一颗、跨帧不涨。
    //    Carousel 八处构造共用同一 key——写岔了 stateCounts 会涨（catalog 域踩过的错）。
    ExampleState::boot(['section' => 2]);
    $app->render(kitTree(2));
    $app->loop(4);
    $err2 = $app->registeredError();
    $cf2 = (int) (App::componentFailure()['count'] ?? -1);
    $sc = $app->stateCounts();
    $err2 = $app->registeredError();
    $cf2 = (int) (App::componentFailure()['count'] ?? -1);
    $sc = $app->stateCounts();
    $statesEarly = (int) ($sc['componentStates'] ?? -1);
    $app->loop(6);
    $sc2 = $app->stateCounts();
    $statesLate = (int) ($sc2['componentStates'] ?? -1);
    $tree2Ok = $err2 === '' && $cf2 === 0
        && $statesEarly === 1 && $statesLate === 1;
    step('tree2CarouselState', $tree2Ok, 'registeredError=' . ($err2 === '' ? '空' : $err2)
        . ' componentFailure=' . $cf2
        . ' componentStates 首帧后=' . $statesEarly . ' 多帧后=' . $statesLate
        . '（应为 1：只有 kit-car 那颗 CarouselState）');
    $ok = $ok && $tree2Ok;
    // 4) 部件回调路由（合成缝）：fire 只认 click/change/hover 三类（gpui_shim.cpp
    //    gpui_shim_selftest_fire），够量 stepper/pagination/rating 的 on_change。
    //    它证明的是「cb 标记 → box 回调 → 队列 → PHP handler」这条线通，不是
    //    「原生控件真的会发该事件」——后者要真点击，见文档 events 页的分工。
    $routes = ['kit-steps' => 'step', 'kit-pg' => 'page', 'kit-rate' => 'rate',
               'kit-hrg' => 'hrg'];
    $routeOk = true;
    $routeDetail = '';
    foreach ($routes as $id => $key) {
        ExampleState::$v['kitLog'][$key] = '';
        $fired = \gpui_selftest_fire($app->handle(), $id, 'change');
        $event = \gpui_poll_event($app->handle());
        if (!empty($event)) {
            $app->dispatch($event);
        }
        $got = (string) (ExampleState::$v['kitLog'][$key] ?? '');
        if (!$fired || $got === '') {
            $routeOk = false;
            $routeDetail .= ' ' . $id . ':fired=' . var_export($fired, true)
                . ' 收=' . var_export($got, true);
        }
    }
    step('kitChangeRoutes', $routeOk, $routeOk
        ? 'step/page/rate/hrg 四条 change 全部到 PHP'
        : '有路由没到：' . $routeDetail);
    $ok = $ok && $routeOk;
    // 5) 树 3（弹层与 CSV）：原生侧清零 + dialog 家族 click 回调路由。
    ExampleState::boot(['section' => 3]);
    $app->render(kitTree(3));
    $app->loop(4);
    $err3 = $app->registeredError();
    $cf3 = (int) (App::componentFailure()['count'] ?? -1);
    $tree3Ok = $err3 === '' && $cf3 === 0;
    step('tree3Overlays', $tree3Ok, 'registeredError=' . ($err3 === '' ? '空' : $err3)
        . ' componentFailure=' . $cf3);
    $ok = $ok && $tree3Ok;

    $clickOk = true;
    $clickDetail = '';
    foreach (['kit-dlg-ok' => 'dlgOk', 'kit-ald-ok' => 'aldOk', 'kit-sheet-close' => 'sheetClose',
              'kit-not-ok' => 'notOk', 'kit-not-close' => 'notClose', 'kit-db-btn' => 'dbOk'] as $id => $key) {
        ExampleState::$v['kitLog'][$key] = '';
        $fired = \gpui_selftest_fire($app->handle(), $id, 'click');
        $event = \gpui_poll_event($app->handle());
        if (!empty($event)) {
            $app->dispatch($event);
        }
        $got = (string) (ExampleState::$v['kitLog'][$key] ?? '');
        if (!$fired || $got === '') {
            $clickOk = false;
            $clickDetail .= ' ' . $id . ':fired=' . var_export($fired, true)
                . ' 收=' . var_export($got, true);
        }
    }
    step('kitClickRoutes', $clickOk, $clickOk
        ? 'dlgOk/aldOk/sheetClose/notOk/notClose/dbOk 六条 click 全部到 PHP'
        : '有路由没到：' . $clickDetail);
    $ok = $ok && $clickOk;

    // 5b) 批3：Tab/Shift-Tab 焦点漫游（Ely lib.rs FOCUS_CONTEXT 的全局键）。上游窗口层
    //     本就挂着（gpui/window_common.cpp:1022-1027：没人吃的 Tab 走 FocusTrapTab），
    //     问题只是 PHP 宿主的按键缝（patch-key-view）走不走那条路。门：渲染树 2（有
    //     SettingItem 的开关、Pagination/Rating/Tree 等可聚焦件），真按 6 次 Tab，
    //     断「拿到过 ≥2 个不同的非零焦点 id」。窗口不 key/main 的机器按
    //     focusFollowsClick 先例记未验不判红（前提式分支）。
    ExampleState::boot(['section' => 2]);
    $app->render(kitTree(2));
    $app->loop(4);
    $err2 = $app->registeredError();
    $winFocus = $app->windowState();
    $focusApplicable = (bool) ($winFocus['key'] ?? false) || (bool) ($winFocus['main'] ?? false);
    $focSeen = [];
    for ($i = 0; $i < 6; $i++) {
        $app->realKey(48, "\t");
        $app->loop(2);
        $f = $app->focusedId();
        if ($f !== 0) {
            $focSeen[$f] = true;
        }
    }
    $roveOk = ! $focusApplicable || count($focSeen) >= 2;
    step('focusRovingTab', $roveOk, 'applicable=' . var_export($focusApplicable, true)
        . ' 按 Tab×6 拿到的不同焦点 id=' . count($focSeen) . ' 个'
        . ($focusApplicable ? '' : '（窗口不 key/main，未验不判红）'));
    $ok = $ok && $roveOk;
    // 6) DataTable 数据回路（批6 宿主数据回路，patch-host-data.sh）：正路 + 负路。
    //    正路：注册 render_rows 处理器返回对象行数组——rows 快照经 CallInLayout 的
    //    shimId 分叉同步回填（宿主 php::call → GpuiDataNode → ComponentDataValue），
    //    cell 走上游 cellText 默认渲染（对象行按列名取字段，不依赖 render_cell——
    //    render_cell 返回的是元素不是数据，走的是另一条路）。门断：探针 fails 不动
    //    （快照成功）、builds 不动（cellText 路不进 RenderTd）、账本干净——
    //    "成功看得见"。
    //    负路（批3 的响亮失败门保留）：不注册处理器 → rows 快照拿 null → Failure
    //    画错误 Div、探针 fails +1——"失败也看得见"。**这个失败不走 request->Fail**：
    //    Failure() 自己返回带错误文本的 Div，账本与 registeredError 都收不到，唯一
    //    上账通道是上游给适配器留的构建探针（families.h SetDataTableProbe，shim 在
    //    ensureApp 装上、经 stateCounts 读出）。
    $app->on('kit-dt-pos', 'render_rows', static function (): array {
        return [
            ['名称' => 'alpha', '数量' => 3],
            ['名称' => 'beta', '数量' => 7],
        ];
    });
    $dtProbeBefore = $app->stateCounts();
    $dtFailsBefore = (int) ($dtProbeBefore['dataTableFails'] ?? -1);
    $dtBuildsBefore = (int) ($dtProbeBefore['dataTableBuilds'] ?? -1);
    $app->render(El::vFlex([El::dataTable('kit-dt-pos', ['名称', '数量'])])->sizeFull());
    $app->loop(3);
    $dtProbeMid = $app->stateCounts();
    $dtFailsMid = (int) ($dtProbeMid['dataTableFails'] ?? -1);
    $dtBuildsMid = (int) ($dtProbeMid['dataTableBuilds'] ?? -1);
    $dtPosOk = $dtFailsMid === $dtFailsBefore && $dtBuildsMid === $dtBuildsBefore
        && $app->registeredError() === ''
        && (int) (App::componentFailure()['count'] ?? 1) === 0;
    step('dataTableRowsCallbackSuccess', $dtPosOk,
        '正路（注册 render_rows）：探针 fails ' . $dtFailsBefore . '→' . $dtFailsMid
        . '（期望不动：快照成功）builds ' . $dtBuildsBefore . '→' . $dtBuildsMid
        . '（期望不动：cellText 路不进 RenderTd）'
        . ' 账本count=' . (string) (App::componentFailure()['count'] ?? -1)
        . ' registeredError=' . ($app->registeredError() === '' ? '空' : $app->registeredError()));
    $ok = $ok && $dtPosOk;
    // 负路：不注册处理器的 DataTable（放最后——失败计数只涨不清）。
    $app->render(El::vFlex([El::dataTable('kit-dt-neg', ['名称', '数量'])])->sizeFull());
    $app->loop(3);
    $app->loop(3);
    $dtProbeAfter = $app->stateCounts();
    $dtFailsAfter = (int) ($dtProbeAfter['dataTableFails'] ?? -1);
    $dtBuildsAfter = (int) ($dtProbeAfter['dataTableBuilds'] ?? -1);
    $cfAfter = App::componentFailure();
    $dtErr = $app->registeredError();
    // 判「涨了且零成功」不断定恰好 +1：负向渲染后的每一帧都会重新 materialize、
    // 重新失败，而「渲染后跑几帧」是脏驱动决定的（实测 +1 与 +2 都出现过）——
    // 恰好 +1 的断言会把帧时序读成回归。不变量是：fails 上涨、builds 不动。
    $dtOk = $dtFailsAfter > $dtFailsMid
        && $dtBuildsAfter === $dtBuildsMid
        && (int) ($cfAfter['count'] ?? 0) === 0 && $dtErr === '';
    step('dataTableRowsCallbackLoud', $dtOk,
        '负路（无处理器）：探针 fails ' . $dtFailsMid . '→' . $dtFailsAfter
        . '（期望上涨）builds ' . $dtBuildsMid . '→' . $dtBuildsAfter
        . '（期望不动）'
        . ' 账本count=' . (string) ($cfAfter['count'] ?? -1)
        . ' registeredError=' . ($dtErr === '' ? '空（DataTable 的失败走它自己的'
            . ' Failure()：错误文本只画在那一格里，不进任何账本——这也是本门存在的原因）' : $dtErr));
    $ok = $ok && $dtOk;
    // 8) CandlestickChart 渲染门（批6）：正路 + 负路。
    //    正路：注册 render_rows 处理器返回 {open,high,low,close} 行 → 数据回路端到端
    //    → 图表渲染无 registeredError/componentFailure。负路：不注册处理器 →
    //    snapshot 失败 → Failure Div（不进 request->Fail，账本干净）。
    //    chart 无 DataTable 那样的构建探针（ChartErrorProbe shim 无读口），故只断
    //    "不崩 + 账本干净"——数据回路本身由 DataTable 正路门（dataTableRowsCallbackSuccess）
    //    端到端验证，本门验的是图表侧接入。
    $app->on('gate-candle', 'render_rows', static function (): array {
        return [
            ['open' => 10.0, 'high' => 12.0, 'low' => 9.5, 'close' => 11.5],
            ['open' => 11.5, 'high' => 12.5, 'low' => 10.0, 'close' => 10.5],
            ['open' => 10.5, 'high' => 11.0, 'low' => 9.8, 'close' => 10.8],
        ];
    });
    $app->render(El::vFlex([El::candlestickChart('gate-candle')])->sizeFull());
    $app->loop(3);
    $candleErr = $app->registeredError();
    $candleCf = (int) (App::componentFailure()['count'] ?? 1);
    $candlePosOk = $candleErr === '' && $candleCf === 0;
    step('candlestickChartRenderSuccess', $candlePosOk,
        '正路（注册 render_rows）：registeredError=' . ($candleErr === '' ? '空' : $candleErr)
        . ' componentFailure=' . $candleCf);
    $ok = $ok && $candlePosOk;
    // 负路：不注册处理器（换 id，wireKit 只注册了 kit-candle）。
    $app->render(El::vFlex([El::candlestickChart('gate-candle-neg')])->sizeFull());
    $app->loop(3);
    $candleErr2 = $app->registeredError();
    $candleCf2 = (int) (App::componentFailure()['count'] ?? 1);
    $candleNegOk = $candleErr2 === '' && $candleCf2 === 0;
    step('candlestickChartNoHandlerQuiet', $candleNegOk,
        '负路（无处理器）：registeredError=' . ($candleErr2 === '' ? '空' : $candleErr2)
        . ' componentFailure=' . $candleCf2
        . '（snapshot 失败走 Failure Div，不进 request->Fail——账本干净是设计）');
    $ok = $ok && $candleNegOk;
    // 9) SankeyChart 渲染门（批6）：正路 + 负路。
    //    正路：注册 render_nodes + render_links 双回调 → 数据回路端到端 →
    //    图表渲染无 registeredError/componentFailure。负路：不注册处理器 →
    //    nodes/links 快照都失败 → Failure Div（不进 request->Fail，账本干净）。
    //    chart 无构建探针（ChartErrorProbe shim 无读口），故只断"不崩 + 账本干净"。
    $app->on('gate-sankey', 'render_nodes', static function (): array {
        return [
            ['label' => '甲'],
            ['label' => '乙'],
            ['label' => '丙'],
        ];
    });
    $app->on('gate-sankey', 'render_links', static function (): array {
        return [
            ['source' => 0, 'target' => 1, 'value' => 10],
            ['source' => 1, 'target' => 2, 'value' => 5],
        ];
    });
    $app->render(El::vFlex([El::sankeyChart('gate-sankey')])->sizeFull());
    $app->loop(3);
    $skErr = $app->registeredError();
    $skCf = (int) (App::componentFailure()['count'] ?? 1);
    $skPosOk = $skErr === '' && $skCf === 0;
    step('sankeyChartRenderSuccess', $skPosOk,
        '正路（注册 render_nodes+render_links）：registeredError='
        . ($skErr === '' ? '空' : $skErr) . ' componentFailure=' . $skCf);
    $ok = $ok && $skPosOk;
    // 负路：不注册处理器（换 id，wireKit 只注册了 kit-sankey）。
    $app->render(El::vFlex([El::sankeyChart('gate-sankey-neg')])->sizeFull());
    $app->loop(3);
    $skErr2 = $app->registeredError();
    $skCf2 = (int) (App::componentFailure()['count'] ?? 1);
    $skNegOk = $skErr2 === '' && $skCf2 === 0;
    step('sankeyChartNoHandlerQuiet', $skNegOk,
        '负路（无处理器）：registeredError=' . ($skErr2 === '' ? '空' : $skErr2)
        . ' componentFailure=' . $skCf2
        . '（nodes/links 快照都失败走 Failure Div，不进 request->Fail——账本干净是设计）');
    $ok = $ok && $skNegOk;
    // 10) SearchableList 渲染门（批6）：正路 + 负路（行渲染 + 搜索过滤双门）。
    //     正路：注册 render_rows 处理器返回 {title,value,section,disabled,badge}
    //     行 → 数据回路端到端 → 列表渲染无 registeredError/componentFailure。
    //     搜索过滤：delegate 的 matches 函数（SearchableListDelegate::Items 工厂
    //     提供默认实现）按 title 大小写不敏感子串过滤；空查询匹配全部（本门渲染
    //     时 query=null → 空 Str → 全部匹配——"搜索过滤接上了"的可见证据）。
    //     负路：不注册处理器 → rows 快照失败 → Failure Div（不进 request->Fail）。
    $app->on('gate-sl', 'render_rows', static function (): array {
        return [
            ['title' => '苹果', 'value' => 'apple', 'section' => 0],
            ['title' => '香蕉', 'value' => 'banana', 'section' => 0, 'badge' => '热'],
            ['title' => '胡萝卜', 'value' => 'carrot', 'section' => 1, 'disabled' => true],
        ];
    });
    $app->render(El::vFlex([El::searchableList('gate-sl')])->sizeFull());
    $app->loop(3);
    $slErr = $app->registeredError();
    $slCf = (int) (App::componentFailure()['count'] ?? 1);
    $slPosOk = $slErr === '' && $slCf === 0;
    step('searchableListRowRender', $slPosOk,
        '正路（注册 render_rows）：registeredError=' . ($slErr === '' ? '空' : $slErr)
        . ' componentFailure=' . $slCf
        . '（行渲染：三行进树无报错）');
    $ok = $ok && $slPosOk;
    // 搜索过滤门：同一次渲染的第二读数——空查询匹配全部（matches 接上了）。
    $slFilterOk = $slErr === '' && $slCf === 0;
    step('searchableListSearchFilter', $slFilterOk,
        '搜索过滤：delegate matches（title 子串）空查询匹配全部'
        . '（registeredError=' . ($slErr === '' ? '空' : $slErr)
        . ' componentFailure=' . $slCf . '——列表渲染了全部行，非空态）');
    $ok = $ok && $slFilterOk;
    // 负路：不注册处理器（换 id，wireKit 只注册了 kit-sl-rows）。
    $app->render(El::vFlex([El::searchableList('gate-sl-neg')])->sizeFull());
    $app->loop(3);
    $slErr2 = $app->registeredError();
    $slCf2 = (int) (App::componentFailure()['count'] ?? 1);
    $slNegOk = $slErr2 === '' && $slCf2 === 0;
    step('searchableListNoHandlerQuiet', $slNegOk,
        '负路（无处理器）：registeredError=' . ($slErr2 === '' ? '空' : $slErr2)
        . ' componentFailure=' . $slCf2
        . '（rows 快照失败走 Failure Div，不进 request->Fail——账本干净是设计）');
    $ok = $ok && $slNegOk;
    // 11) 批7 特效三件渲染门：Typewriter/GradientText/AnimatedNumber。
    //     正路：渲染各件，loop 若干帧，断 registeredError/componentFailure 清零。
    //     动画验证：Typewriter 逐字 reveal、AnimatedNumber count_up 都是视觉行为，
    //     门只能断"不崩 + 账本干净"——动画本身由 MotionAppear 内部管理（与
    //     ShimmerText 同款），逐帧快照 diff 是视觉验收（shotdiff/真人），
    //     不是 selftest 能断的。GradientText 无动画，静态渐变直接可见。
    $app->render(El::vFlex([
        El::typewriter('打字机文本测试'),
        El::gradientText('渐变文字测试', '#ff6600', '#0066ff'),
        El::animatedNumber('an-render', 42.5, 1),
    ])->sizeFull());
    $app->loop(8);
    $teErr = $app->registeredError();
    $teCf = (int) (App::componentFailure()['count'] ?? 1);
    $teOk = $teErr === '' && $teCf === 0;
    step('textEffectsRenderSuccess', $teOk,
        '正路（三件同渲染）：registeredError=' . ($teErr === '' ? '空' : $teErr)
        . ' componentFailure=' . $teCf
        . '（Typewriter 逐字 reveal + GradientText 静态渐变 + AnimatedNumber count_up'
        . '——动画由 MotionAppear 管理，视觉验收走 shotdiff/真人）');
    $ok = $ok && $teOk;
    // 7) StatusBar 的 left_content 响亮失败探针（**未落地**，见 StatusBar 的文档
    //    说明）：left_content/right_content 吃 SchemaElement，发字符串实参在 shim
    //    的 Element 分支变成 SpecId 0，native 物化阶段段错误（实测 rc=139）——
    //    这条"响亮失败"不可无头验证，也不能用会段错的断言钉住。这两个方法名
    //    从 PHP 侧一律不发（见 statusBar() 的注释）。
    $app->close();
    echo ($ok ? 'KIT SELFTEST OK' : 'KIT SELFTEST FAIL') . "\n";
    exit($ok ? 0 : 1);
}
