<?php

declare(strict_types=1);

/**
 * examples/_shared/features/kit.php — 功能域「kit：差距清单的 catalog 工厂」。
 *
 * 2026-10-28 四批落地的 El:: 工厂在这一页各有一个真调用（门 12 扫的是调用不是提及）：
 * tier-A 批 27 个（Separator…Carousel），「纯工厂热身」批 5 个（Spinner/TableFooter/
 * GroupBox/VForm/InputGroupText，全走 reg() 通道、上游零改动），批2 六颗
 * （Label/Toolbar/StatusBar/Clipboard/HorizontalRadioGroup + SplitButton 组合糖，
 * 其中 StatusBar 只发中段——left/right_content 吃 SchemaElement 是 shim 缺口），
 * 批4 嵌套结构与第二张脸（Questionnaire 四件、catalog 版 Accordion（title 是
 * SchemaElement 缺口按项数建）、catalog 四颗升级糖 Select/Combobox/Popover/TabBar；
 * catalogHoverCard 的 trigger_element 已由批2 经 element_arg 桥喂入（原"硬缺口
 * 不进树"注已订正——批5 桥早已通用）。
 * 三棵树按"基础件 / 数据与导航 / 弹层与 CSV"分：native --selftest 逐棵渲染、
 * registeredError/componentFailure 逐棵清零——哪一棵里哪颗工厂建不出来，报告停在那一棵。
 *
 * 唯一不在树里的工厂是 El::dataTable()：它的 rows/render_cell 构造实参要"返回数据"
 * 的回调，shim 回调通道只会发事件、不带返回值（runtime.cpp:12922 的 shimId 分支），
 * 真渲染必然当帧 Fail。把它放进树就是把错误节点放进基线；它的形状门与
 * "失败看得见"的门都在 kit 的 --selftest 里（dataTable* 系列），数据通道要等宿主
 * 回路（patch-host-list.sh 同形状）升 tier B。
 */

use TypePHP\Gpui\App;
use TypePHP\Gpui\Element as El;

/** 本域的像素树清单：树号 → [标题, builder]。 */
function kitTrees(): array
{
    return [
        1 => ['kit 基础件', 'sectionKitBasics'],
        2 => ['kit 数据与导航', 'sectionKitData'],
        3 => ['kit 弹层与 CSV', 'sectionKitOverlays'],
        4 => ['kit 滚动条模式对拍', 'sectionKitScrollbarPair'],
    ];
}

/** 节头两行（标题 + 副题）。nano 里数组展开没有先例，各节用 array_merge 显式拼。 */
function kitHeader(string $title, string $sub): array
{
    return [
        El::text($title)->font(22.0)->semibold(),
        El::text($sub)->font(11.0)->truncate(),
    ];
}

/** 滚动条模式对拍（收尾轮 A3 的 --shot 验收树）：hover vs always 两盒同内容
 * 同尺寸，唯一差别 scrollbarMode——cmd/check-scrollbar-paint.sh 读两盒右缘
 * 竖条区域断互异。探针 244/504 换算基准：外层 vFlex **p(40)** + hFlex gap40 +
 * 盒 w220 → 盒1 右缘内 16px 竖条 = x 244..260、盒2 = x 504..520（y 48..108，
 * 与 main.php scrollbarModePaintPair 门同构——首版漏了 p(40)，盒从 (0,0) 起，
 * 探针 244/504 读到根底色，2026-10-31 已修）。**不放 kitHeader**：header 两行
 * 会把盒子 y 推离 48 探针基准，判别树坐标确定性优先于观感一致。 */
function sectionKitScrollbarPair(array $s): El
{
    $rows = static function (string $tag): array {
        $out = [];
        for ($i = 1; $i <= 8; $i++) {
            $out[] = El::text($tag . '盒 · 第 ' . sprintf('%02d', $i) . ' 行')->font(11.0);
        }
        return $out;
    };
    $box = static function (array $children, string $mode): El {
        return El::vFlex($children)->gap(4.0)->p(8.0)->w(220.0)->h(120.0)
            ->bg('#ffffff')->rounded(6.0)->overflowScrollbar()->scrollbarMode($mode);
    };
    return El::vFlex([
        El::hFlex([
            $box($rows('hover'), 'hover'),
            $box($rows('always'), 'always'),
        ])->gap(40.0)->itemsStart(),
    ])->p(40.0);
}

/** 基础件：分割线 / Tooltip / 骨架屏 / 空状态 / 徽标 / 标签 / 键帽 / 微光文字。 */
function sectionKitBasics(array $s): El
{
    // 分割线：四个变体整表遍历（Separator/VerticalSeparator/DashedSeparator/
    // VerticalDashedSeparator，separator.cpp kConstructors）；可嵌字 label 与
    // 语气色走既有 ->label()/->color() 链式糖（separator.cpp kMethods）。
    $separators = [];
    foreach (El::SEPARATOR_VARIANTS as $vname => $export) {
        $separators[] = El::separator($vname);
    }
    $tagVariants = [];
    foreach (['primary', 'secondary', 'success', 'warning', 'danger', 'info'] as $tv) {
        $tagVariants[] = El::tag($tv, $tv);
    }
    return El::vFlex(array_merge(kitHeader('kit · tier-A 工厂批（基础件）',
        'Separator/Tooltip/Skeleton/EmptyState/Badge/Tag/Kbd/ShimmerText/GroupBox/InputGroupText/Spinner 全走上游已导出名'), [
        El::text('Separator 分割线：四变体 + 可嵌字（->label）')->font(13.0)->semibold(),
        El::separator('horizontal')->label('或')->color('#e07b39'),
        El::hFlex($separators)->gap(12.0)->itemsCenter()->h(40.0),
        El::hFlex([
            El::catalogTooltip('kit-tt', '悬停我', '这是 catalog Tooltip 的受管提示文本'),
            El::button('普通按钮（对照）'),
        ])->gap(12.0)->itemsCenter(),
        El::text('EmptyState 空状态：五件套组装（Empty/Media(icon)/Title/Description/Content）')->font(13.0)->semibold(),
        El::emptyState('icons/square-terminal.svg', '还没有任务', '创建第一个任务开始使用',
            El::button('新建任务')),
        // 批5 A/B 对照：同参的 header 槽路（patch-element-arg.sh 解锁）——EmptyHeader 的
        // media/title/description 是方法实参 SchemaElement，经 elementArg 桥（子元素先建、
        // SpecId 回填）；根的 header 走具名槽。视觉与五件套路不同（图标走 EmptyMedia 的
        // icon 形链），两路各自进像素基线。
        El::text('EmptyState header 槽路：EmptyHeader(元素实参) 经 elementArg 桥 + 具名槽')->font(12.0),
        El::emptyState('icons/square-terminal.svg', '还没有任务', '创建第一个任务开始使用', null, true),
        // elementArg 的直接用形（emptyState header 槽路内部也走它）：给 registered_method
        // 的 SchemaElement 实参喂元素——bridge 先 emitTree 子元素拿 SpecId、再以它当实参
        // 发方法（emitTree 的 op 在 children 之前，所以必须像 slot 一样先建子）。
        El::catalog('EmptyHeader', [])
            ->elementArg('media',
                El::catalog('EmptyMedia', [], [El::icon('icons/square-terminal.svg')])
                ->rm('variant', ['icon']))
            ->elementArg('title', El::catalog('EmptyTitle', [], [El::text('直接用形标题')]))
            ->elementArg('description',
                El::catalog('EmptyDescription', [], [El::text('直接用形描述')])),
        El::text('Badge 徽标：数字（+max 加号阈值）与圆点两形；Tag 标签：六种语气变体')->font(13.0)->semibold(),
        El::hFlex([
            El::hFlex([El::button('收件箱'), El::badge(9, false, 99)])->gap(4.0)->itemsCenter(),
            El::hFlex([El::button('通知'), El::badge(0, true)])->gap(4.0)->itemsCenter(),
            El::hFlex($tagVariants)->gap(6.0)->itemsCenter(),
            El::tag('草稿', 'secondary')->outline(),
            El::tag('圆角', 'info')->pill(),
            // ->variant()/->pill() 是 Tag 方法表的链式糖（与 Button 的零参变体是两族）。
            El::tag('语气改写', 'secondary')->variant('warning'),
        ])->gap(16.0)->itemsCenter(),
        El::text('GroupBox 分组盒（title 走方法；variant 集 normal/fill/outline）与 '
            . 'InputGroupText 输入组弱化文字（独立渲染，组合缺口见下注）')->font(13.0)->semibold(),
        // ->variant() 的白名单是各组件字面量集的并（Tag 语气色 + GroupBox 形）；
        // 集内他组件的字面量由上游 enum 拒（响亮），PHP 侧并表不拦。
        El::hFlex([
            El::groupBox('外观', [El::text('分组内容甲'), El::text('分组内容乙')])->variant('fill'),
            El::groupBox('边框', [El::text('outline 形')])->variant('outline'),
            El::groupBox('默认', [El::text('normal 形')]),
        ])->gap(16.0)->itemsStart(),
        // InputGroupText 无自身盒宽：与分组盒同排会被均分挤成竖排，单独一排才见
        // 「弱化文字」的本形（灰字、单行）。
        El::hFlex([
            El::inputGroupText('https://'),
            El::inputGroupText('单位：元'),
            El::inputGroupText('最多 20 个字符'),
        ])->gap(24.0)->itemsStart(),
        // 批5 数据：ProgressCircle 环形进度（上游 descriptor 补洞 patch-progress-circle.sh，
        // 五件套 Payload/Op/Record/Component/Materialize 照 compound/progress.cpp 的
        // kProgress 样式复刻）。定态 value 弧是静态画的；label 把百分比印在环心；color 走
        // ParseColorArgument（spinner 同款）。本行排在树 2 内容尾部（"最近一次部件回调"
        // 之前）——树 2 的滚动视口到 DescriptionList 已满（主题表格/VForm 同款处境），
        // 这段在折叠线之下：像素基线逐字节不变（实测 80064→80064），materialize 照跑
        // （滚动不虚拟化），原生侧证据由 tree2 registeredError/componentFailure 清零门
        // 给出（ProgressCircle 物化失败会进 componentFailure——先例：header 槽路实参
        // 发错时 9 颗失败）。
        El::text('ProgressCircle 环形进度（上游 descriptor 补洞）：定态 value 弧 + label + '
            . 'color；loading 不定态是动画，只在形状门里建')->font(13.0)->semibold(),
        El::hFlex([
            El::progressCircle('kit-pc', 62.0)->progressCircleLabel()->progressCircleSize(72.0),
            El::progressCircle('kit-pc-plain', 28.0)->progressCircleSize(56.0),
            El::progressCircle('kit-pc-c', 44.0)->progressCircleColor('#e07b39')->progressCircleSize(64.0),
        ])->gap(24.0)->itemsCenter(),
        El::text('最近一次部件回调：' . (string) ($s['kitLog']['last'] ?? '—'))->font(11.0),
        // 批2 五件 + 组合糖：排在树1 尾部（折叠线下，Carousel 同款处境——
        // materialize 照常跑，原生证据 = tree1 registeredError 清零门）。
        // Label：->secondaryLabel()/->masked()/->labelHighlights() 是 Label 方法表
        // 的糖（text.cpp kLabelMethods）；highlights 是单个匹配串（SchemaString）。
        El::text('Label 文本标签：catalogLabel（->label 被实例糖占名，按 catalogTooltip 先例加前缀）')->font(13.0)->semibold(),
        El::hFlex([
            El::catalogLabel('src/Element.php'),
            El::catalogLabel('修改于 3 分钟前')->secondaryLabel('（次级灰字）'),
            El::catalogLabel('sk-…-9f2c')->masked(),
            El::catalogLabel('把 Element 树交给 shell')->labelHighlights('Element'),
        ])->gap(20.0)->itemsStart(),
        El::text('Toolbar 工具栏（id 非空；无方法表；普通子节点按源序进 Contents）与 '
            . 'StatusBar 状态条（中段=普通子节点；left/right_content 吃 SchemaElement，'
            . 'shim 缺口不发——见 statusBarElementArgLoud 探针）')->font(13.0)->semibold(),
        El::toolbar('kit-tb', [
            // 子节点随意：这里一颗 catalog Button（primary 形）、一颗 kind 按钮、
            // 一颗 Label 次级字——Toolbar 对 children 不挑名字（普通子节点）。
            El::catalogButton('kit-tb-new', '新建')->primary(),
            El::button('打开'),
            El::catalogLabel('3 项已选'),
        ])->gap(8.0)->itemsCenter(),
        El::statusBar([
            El::catalogLabel('就绪'),
            El::catalogLabel('UTF-8'),
            El::catalogLabel('第 12 行，第 4 列'),
        ])->gap(16.0)->itemsCenter(),
        El::text('Clipboard 复制按钮（拒 children；点击复制是部件内置行为，无 on_copied 导出——'
            . '行为门前提式）与 SplitButton（组合糖，内部发 DropdownMenu，非独立导出）')->font(13.0)->semibold(),
        El::hFlex([
            El::clipboard('kit-clip', 'typephp-gpui', '点击复制仓库名'),
            // SplitButton 的 item 回调 id 派生为 $id-item-<i>（splitButton 文档注明）。
            El::splitButton('kit-split', '导出…', ['导出为 PNG', '导出为 CSV', '复制到剪贴板']),
        ])->gap(20.0)->itemsCenter(),
        // Skeleton/ShimmerText 两行 ->hidden()：两者都是**动画**（脉冲/扫光），快照相位
        // 随帧时序漂，留在视口里基线每跑必抖（实测 gdfrac≈0.025，约半数跑次红；挪到
        // kitLog 之后、加 700px 空撑都拉不回来——布局反馈把行又送回视口底缘）。
        // hidden() 是 display 级隐藏：materialize 照常跑（registeredError/
        // componentFailure 两个门照常量它们），像素恒为零、基线逐字节确定。
        El::text('Skeleton 骨架屏（hidden，见上注）：原生 Skeleton + ->secondary() + div 组合的卡片变体')->font(13.0)->semibold()->hidden(),
        El::hFlex([
            El::skeleton()->w(120.0)->h(16.0),
            El::skeleton()->secondary()->w(120.0)->h(16.0),
            // Ely 的 SkeletonCard 变体上游没有导出：div+样式链组合（tier-A 计划里的降级用法）。
            El::vFlex([
                El::skeleton()->w(140.0)->h(72.0)->rounded(8.0),
                El::skeleton()->secondary()->w(100.0)->h(12.0)->rounded(4.0),
            ])->gap(6.0)->p(8.0)->borderWidth(1.0)->rounded(8.0),
        ])->gap(16.0)->itemsStart()->hidden(),
        El::text('Kbd 键帽 / KbdCombo 组合键 / ShimmerText 微光文字（hidden，见上注）')->font(13.0)->semibold()->hidden(),
        El::hFlex([
            El::kbd('⌘'),
            El::kbd('K')->outline(),
            El::kbdCombo(['⌘', 'Shift', 'P']),
            El::shimmerText('正在加载…'),
            El::shimmerText('慢速反向扫光')->rm('duration_ms', [2400.0])->rm('reverse', [true]),
        ])->gap(16.0)->itemsCenter()->hidden(),
        // Spinner 同理是旋转动画（hidden 的理由同上）：四方法表各发一笔——
        // size 走 ->sizeEnum()（kSizeLiterals 同 El::SIZES）、icon/ease 是本组件
        // 私有枚举走 ->spinnerIcon()/->spinnerEase()、color 走 ->color()。
        // 图标/缓动枚举每个取值各来一颗（枚举覆盖门：字面量要真的出现在示例里）。
        El::text('Spinner 加载指示器（hidden，见上注）：size/icon/color/ease 四方法各一笔')->font(13.0)->semibold()->hidden(),
        El::hFlex([
            El::spinner(),
            El::spinner()->sizeEnum('large')->spinnerIcon('loader_circle')
                ->color('#e07b39')->spinnerEase('ease_in_out'),
            El::spinner()->spinnerIcon('loader'),
            El::spinner()->spinnerEase('linear'),
            El::spinner()->spinnerEase('ease_out_quint'),
        ])->gap(16.0)->itemsCenter()->hidden(),
        // ── 批1（2026-10-30）：零参样式糖差集补齐（72→135/137，剩 h_flex/v_flex 两颗
        // 走 El::hFlex()/vFlex() 的 kind 名不算洞）+ 高级滚动 op 糖。每组至少一段真调用
        // （门 12 逐颗查示例源码里的调用；63 颗"在编出来的产物里逐个调"的全量门住在
        // examples/style 的 --selftest，detached 链像素不动）。本段各行都在树 1 视口
        // 折叠线之下——与 ProgressCircle 同款处境：materialize 照常跑（滚动不虚拟化），
        // 像素基线逐字节不变，原生侧证据 = tree1 registeredError/componentFailure 清零门
        // + kitStructuralGates 的 styleSugarDiff 门。
        El::text('样式糖差集·阴影四档（shadow_2xs/xs/xl/2xl；none/sm/md/lg 旧批已有）')->font(13.0)->semibold(),
        El::hFlex([
            El::vFlex([El::text('2xs')->font(11.0)])->w(110.0)->h(48.0)->itemsCenter()->justifyCenter()
                ->bg('#ffffff')->rounded(8.0)->shadow2xs(),
            El::vFlex([El::text('xs')->font(11.0)])->w(110.0)->h(48.0)->itemsCenter()->justifyCenter()
                ->bg('#ffffff')->rounded(8.0)->shadowXs(),
            El::vFlex([El::text('xl')->font(11.0)])->w(110.0)->h(48.0)->itemsCenter()->justifyCenter()
                ->bg('#ffffff')->rounded(8.0)->shadowXl(),
            El::vFlex([El::text('2xl')->font(11.0)])->w(110.0)->h(48.0)->itemsCenter()->justifyCenter()
                ->bg('#ffffff')->rounded(8.0)->shadow2xl(),
        ])->gap(24.0)->itemsCenter(),
        El::text('样式糖差集·文字装饰：样式三档（none 取消下划线）+ 粗细五档（要有装饰线才见粗细）')->font(13.0)->semibold(),
        El::hFlex([
            El::text('none')->font(12.0)->underline()->textDecorationNone(),
            El::text('solid')->font(12.0)->underline()->textDecorationSolid(),
            El::text('wavy')->font(12.0)->underline()->textDecorationWavy(),
            El::text('粗0')->font(12.0)->underline()->textDecoration0(),
            El::text('粗1')->font(12.0)->underline()->textDecoration1(),
            El::text('粗2')->font(12.0)->underline()->textDecoration2(),
            El::text('粗4')->font(12.0)->underline()->textDecoration4(),
            El::text('粗8')->font(12.0)->underline()->textDecoration8(),
        ])->gap(16.0)->itemsBaseline(),
        El::text('样式糖差集·光标图例 18 颗（悬停才换形，静态快照不显形；pointer/text/move/'
            . 'not_allowed/grab 旧批已有）')->font(13.0)->semibold(),
        El::hFlex([
            El::text('alias')->font(11.0)->px(6.0)->py(2.0)->borderWidth(1.0)->rounded(4.0)->cursorAlias(),
            El::text('col_resize')->font(11.0)->px(6.0)->py(2.0)->borderWidth(1.0)->rounded(4.0)->cursorColResize(),
            El::text('context_menu')->font(11.0)->px(6.0)->py(2.0)->borderWidth(1.0)->rounded(4.0)->cursorContextMenu(),
            El::text('copy')->font(11.0)->px(6.0)->py(2.0)->borderWidth(1.0)->rounded(4.0)->cursorCopy(),
            El::text('crosshair')->font(11.0)->px(6.0)->py(2.0)->borderWidth(1.0)->rounded(4.0)->cursorCrosshair(),
            El::text('default')->font(11.0)->px(6.0)->py(2.0)->borderWidth(1.0)->rounded(4.0)->cursorDefault(),
            El::text('e_resize')->font(11.0)->px(6.0)->py(2.0)->borderWidth(1.0)->rounded(4.0)->cursorEResize(),
            El::text('ew_resize')->font(11.0)->px(6.0)->py(2.0)->borderWidth(1.0)->rounded(4.0)->cursorEwResize(),
            El::text('grabbing')->font(11.0)->px(6.0)->py(2.0)->borderWidth(1.0)->rounded(4.0)->cursorGrabbing(),
        ])->gap(8.0)->itemsCenter(),
        El::hFlex([
            El::text('n_resize')->font(11.0)->px(6.0)->py(2.0)->borderWidth(1.0)->rounded(4.0)->cursorNResize(),
            El::text('nesw_resize')->font(11.0)->px(6.0)->py(2.0)->borderWidth(1.0)->rounded(4.0)->cursorNeswResize(),
            El::text('no_drop')->font(11.0)->px(6.0)->py(2.0)->borderWidth(1.0)->rounded(4.0)->cursorNoDrop(),
            El::text('ns_resize')->font(11.0)->px(6.0)->py(2.0)->borderWidth(1.0)->rounded(4.0)->cursorNsResize(),
            El::text('nwse_resize')->font(11.0)->px(6.0)->py(2.0)->borderWidth(1.0)->rounded(4.0)->cursorNwseResize(),
            El::text('row_resize')->font(11.0)->px(6.0)->py(2.0)->borderWidth(1.0)->rounded(4.0)->cursorRowResize(),
            El::text('s_resize')->font(11.0)->px(6.0)->py(2.0)->borderWidth(1.0)->rounded(4.0)->cursorSResize(),
            El::text('vertical_text')->font(11.0)->px(6.0)->py(2.0)->borderWidth(1.0)->rounded(4.0)->cursorVerticalText(),
            El::text('w_resize')->font(11.0)->px(6.0)->py(2.0)->borderWidth(1.0)->rounded(4.0)->cursorWResize(),
        ])->gap(8.0)->itemsCenter(),
        // 调试族：DEBUG/NDEBUG 条件编译——release 产物（-rel）里 DebugBorder/ShellDebug
        // 是上游侧 no-op（style.cpp:885-901/907-920），op 照发、像素不动。如实记录，
        // 不假装这张图里看得见描边。
        El::text('样式糖差集·调试族 7 颗（release 构建里是上游 no-op，op 照发）')->font(13.0)->semibold(),
        El::hFlex([
            El::text('debug')->font(11.0)->px(8.0)->py(3.0)->borderWidth(1.0)->debug(),
            El::text('below')->font(11.0)->px(8.0)->py(3.0)->borderWidth(1.0)->debugBelow(),
            El::text('red')->font(11.0)->px(8.0)->py(3.0)->borderWidth(1.0)->debugRed(),
            El::text('blue')->font(11.0)->px(8.0)->py(3.0)->borderWidth(1.0)->debugBlue(),
            El::text('yellow')->font(11.0)->px(8.0)->py(3.0)->borderWidth(1.0)->debugYellow(),
            El::text('green')->font(11.0)->px(8.0)->py(3.0)->borderWidth(1.0)->debugGreen(),
            El::text('pink')->font(11.0)->px(8.0)->py(3.0)->borderWidth(1.0)->debugPink(),
        ])->gap(10.0)->itemsStart(),
        El::text('样式糖差集·弹性定值（flex_grow_0/1、flex_shrink_0/1）与 justify_end：四颗全被'
            . '推到行尾（justifyEnd 生效的形状）')->font(13.0)->semibold(),
        El::hFlex([
            El::text('grow0')->font(11.0)->px(6.0)->py(2.0)->borderWidth(1.0)->flexGrow0(),
            El::text('grow1')->font(11.0)->px(6.0)->py(2.0)->borderWidth(1.0)->flexGrow1(),
            El::text('shrink0')->font(11.0)->px(6.0)->py(2.0)->borderWidth(1.0)->flexShrink0(),
            El::text('shrink1')->font(11.0)->px(6.0)->py(2.0)->borderWidth(1.0)->flexShrink1(),
        ])->gap(8.0)->w(420.0)->justifyEnd(),
        El::text('样式糖差集·交叉轴 self_*（flex_start/baseline/flex_end 各配不同盒高）与多行 '
            . 'content_*（start/normal/end 三盒对拍，同内容）')->font(13.0)->semibold(),
        El::hFlex([
            El::vFlex([El::text('高 24 flex_start')->font(11.0)])->h(24.0)->px(8.0)->py(2.0)
                ->borderWidth(1.0)->selfFlexStart(),
            El::vFlex([El::text('高 24 baseline')->font(11.0)])->h(24.0)->px(8.0)->py(2.0)
                ->borderWidth(1.0)->selfBaseline(),
            El::vFlex([El::text('高 40 flex_end')->font(11.0)])->h(40.0)->px(8.0)->py(2.0)
                ->borderWidth(1.0)->selfFlexEnd(),
        ])->gap(10.0)->itemsStart()->h(48.0),
        El::hFlex([
            El::hFlex([El::text('start 甲')->font(11.0), El::text('start 乙')->font(11.0),
                El::text('start 丙')->font(11.0), El::text('start 丁')->font(11.0)])
                ->w(150.0)->h(52.0)->flexWrap()->contentStart()->borderWidth(1.0)->p(4.0),
            El::hFlex([El::text('normal 甲')->font(11.0), El::text('normal 乙')->font(11.0),
                El::text('normal 丙')->font(11.0), El::text('normal 丁')->font(11.0)])
                ->w(150.0)->h(52.0)->flexWrap()->contentNormal()->borderWidth(1.0)->p(4.0),
            El::hFlex([El::text('end 甲')->font(11.0), El::text('end 乙')->font(11.0),
                El::text('end 丙')->font(11.0), El::text('end 丁')->font(11.0)])
                ->w(150.0)->h(52.0)->flexWrap()->contentEnd()->borderWidth(1.0)->p(4.0),
        ])->gap(12.0)->itemsStart(),
        El::text('样式糖差集·网格放置（*_span_full 跨满、*_start/end_auto 回自动流；grid 无'
            . '模板列可发，形状交给上游自动流）')->font(13.0)->semibold(),
        El::vFlex([
            El::text('列 span 全跨')->font(11.0)->px(6.0)->py(2.0)->borderWidth(1.0)->colSpanFull(),
            El::hFlex([
                El::text('col_start_auto')->font(11.0)->px(6.0)->py(2.0)->borderWidth(1.0)->colStartAuto(),
                El::text('col_end_auto')->font(11.0)->px(6.0)->py(2.0)->borderWidth(1.0)->colEndAuto(),
                El::text('row_start_auto')->font(11.0)->px(6.0)->py(2.0)->borderWidth(1.0)->rowStartAuto(),
                El::text('row_end_auto')->font(11.0)->px(6.0)->py(2.0)->borderWidth(1.0)->rowEndAuto(),
            ])->gap(6.0),
            El::text('行 span 全跨')->font(11.0)->px(6.0)->py(2.0)->borderWidth(1.0)->rowSpanFull(),
        ])->grid()->gap(6.0)->w(420.0)->itemsStart(),
        El::text('样式糖差集·文字面：text_left / 省略号 middle·start / whitespace_normal / '
            . 'not_italic / 字重两档 / 分轴隐藏')->font(13.0)->semibold(),
        El::vFlex([
            El::text('左对齐（text_left）')->font(12.0)->textLeft(),
            El::text('中间省略号：这段文本足够长所以会从中间收起只留两头')->font(12.0)->w(200.0)->textEllipsisMiddle(),
            El::text('起点省略号：这段文本足够长所以会从起点收起只留尾部')->font(12.0)->w(200.0)->textEllipsisStart(),
            El::text('whitespace normal 会 按空格 换行 所以 这段 超长的 文本 不截断 只折行')->font(12.0)->w(200.0)->whitespaceNormal(),
            El::text('先 italic 再 not_italic（后者胜）')->font(12.0)->italic()->notItalic(),
            El::text('extrabold 特粗')->font(12.0)->fontExtrabold(),
            El::text('extralight 特细')->font(12.0)->fontExtralight(),
        ])->gap(6.0)->itemsStart(),
        El::hFlex([
            El::text('x_hidden 超宽内容被裁剪 不换行不滚动 这是一段很长的文本还在继续')->font(11.0)
                ->w(150.0)->h(30.0)->overflowXHidden(),
            El::text('y_hidden 超高内容被裁剪 第一行 第二行 第三行 第四行 第五行')->font(11.0)
                ->w(150.0)->h(30.0)->overflowYHidden(),
        ])->gap(16.0)->itemsStart(),
        // 高级滚动·滚动条三态（批1 op 糖）：overflow_scrollbar = 横竖都滚且**挂原生滚动条**
        // （behavior.scrollbar=true 时 materialize.cpp:2782-2786 不再 HideScrollbar）。
        // hover/always 两盒同内容同尺寸——"对拍"由自检的 regionRgb 像素门
        // （scrollbarModePaintPair：常驻滚动条只出现在 always 盒右缘）与基线共同钉住；
        // scrolling 档由 kitStructuralGates 的 advancedScrollSugar 门发出（词表在册）。
        El::text('高级滚动·滚动条三态小样：左盒 hover（悬停才见滚动条）、右盒 always（常驻）'
            . '——内容相同，右缘滚动条只该出现在右盒')->font(13.0)->semibold(),
        El::hFlex([
            El::vFlex([
                El::text('hover 盒 · 第 01 行')->font(11.0),
                El::text('hover 盒 · 第 02 行')->font(11.0),
                El::text('hover 盒 · 第 03 行')->font(11.0),
                El::text('hover 盒 · 第 04 行')->font(11.0),
                El::text('hover 盒 · 第 05 行')->font(11.0),
                El::text('hover 盒 · 第 06 行')->font(11.0),
                El::text('hover 盒 · 第 07 行')->font(11.0),
                El::text('hover 盒 · 第 08 行')->font(11.0),
            ])->gap(4.0)->p(8.0)->w(220.0)->h(120.0)->bg('#ffffff')->rounded(6.0)
                ->overflowScrollbar()->scrollbarMode('hover'),
            El::vFlex([
                El::text('always 盒 · 第 01 行')->font(11.0),
                El::text('always 盒 · 第 02 行')->font(11.0),
                El::text('always 盒 · 第 03 行')->font(11.0),
                El::text('always 盒 · 第 04 行')->font(11.0),
                El::text('always 盒 · 第 05 行')->font(11.0),
                El::text('always 盒 · 第 06 行')->font(11.0),
                El::text('always 盒 · 第 07 行')->font(11.0),
                El::text('always 盒 · 第 08 行')->font(11.0),
            ])->gap(4.0)->p(8.0)->w(220.0)->h(120.0)->bg('#ffffff')->rounded(6.0)
                ->overflowScrollbar()->scrollbarMode('always'),
        ])->gap(24.0)->itemsStart(),
        // 批2（2026-10-30）：Resizable catalog 第二张脸——与 kind 面 hResizable()/
        // vResizable() 同组件、两套孩子形态（catalog 版只收 catalogResizablePanel、
        // 布局状态上游 keyed state 内部持有；kind 版是布局原语 + name 键）。
        // axis/cross_size 走实例糖；面板尺寸/范围走 panelSize/panelSizeRange。
        // 拖拽本身要真人 HID（patch-resize-handle.sh 的既有探针口径）——静态基线
        // 只钉"面板树真的建出来了、cross_size 生效"，拖拽验收不在此帧。
        El::text('Resizable catalog 版（第二张脸）：horizontal 两面板，左窄右宽，'
            . 'cross_size 96；拖拽把手验收属真人 HID')->font(13.0)->semibold(),
        El::catalogResizable('kit-rz', [
            // Resizable 本体拒样式（descriptor："Resizable does not implement Styled;
            // style its parent or panels"——首次落树实测 loud Fail），高度打到 panel。
            El::catalogResizablePanel(El::vFlex([El::catalogLabel('面板甲')]))
                ->panelSize(180.0)->panelSizeRange(120.0, 320.0)->h(96.0),
            El::catalogResizablePanel(El::vFlex([El::catalogLabel('面板乙（cross_size 作用于组）')]))
                ->panelVisible(true)->h(96.0),
        ])->axis('horizontal')->crossSize(96.0),
        // 批5（2026-10-31）：路径绘制——PathFill 实心三角 + PathStroke 圆弧。
        // 命令坐标按 bounds 解析，元素要有 w/h。像素证据在 selftest 的
        // pathPaintGate（三角质心取到填充色）。
        El::text('路径绘制：PathFill 三角 + PathStroke 圆弧（六命令 move_to/…）')
            ->font(13.0)->semibold(),
        El::hFlex([
            El::pathFill('kit-path-tri', '#2f6fed')
                ->moveTo(10, 90)->lineTo(50, 10)->lineTo(90, 90)->closePath()
                ->w(100.0)->h(100.0),
            El::pathStroke('kit-path-arc', 3.0, '#e05a2b')
                ->moveTo(10, 80)->arcTo(40, 40, 0, false, true, 90, 80)
                ->w(100.0)->h(100.0),
        ])->gap(24.0)->itemsStart(),
        El::hFlex([
            // 渐变填充 + 二次/三次曲线（门12 逐工厂真调用）
            El::pathFillGradient('kit-path-grad', '#ff0000', '#0000ff', 90.0)
                ->moveTo(10, 10)->curveTo(40, 60, 90, 10)
                ->lineTo(90, 90)->lineTo(10, 90)->closePath()
                ->w(100.0)->h(100.0),
            El::pathFill('kit-path-bez', '#2fae5c')
                ->moveTo(10, 50)->cubicBezierTo(30, 10, 70, 90, 90, 50)
                ->closePath()->w(100.0)->h(100.0),
        ])->gap(24.0)->itemsStart(),
        // 批3（2026-10-30）：base kind 手风琴根——本体是 Div+Id+Role(Group)
        // （base/accordion.cpp:5-8），价值在把 accordionItem 三段件合法挂树；
        // 与 catalog 版 catalogAccordion（带 multiple/bordered/size 方法表）是
        // 两张脸。shim 的 kKindNames 本批加了 {"accordion", K::Accordion} 登记
        // （不登记 kindFromString 不认、节点直接拒建）。
        El::text('Accordion 根（base kind 版）：Div+Group role 挂三段件；'
            . '点头部翻 open（on_change → kit-acc-i0/i1）')->font(13.0)->semibold(),
        El::accordion('kit-acc', [
            El::accordionItem('第一节（点我翻页）', El::text('第一节的折叠内容。')->font(12.0), 'kit-acc-i0'),
            El::accordionItem('第二节', El::vFlex([El::text('第二节的折叠内容。')->font(12.0)])
                ->gap(4.0), 'kit-acc-i1'),
        ])->w(420.0),
        // 批3：ScrollbarHandle/Scroll/Scrollbar 三件——15 个 state kind 的最后一个
        // 缺口闭合（Scroll 视口与 Scrollbar 条共用一颗 ScrollbarHandle，条本体由
        // 共享 handle 的视口侧画，scroll.cpp:133-136 ApplyBar）。Scrollbar 自己
        // 拒 children/泛 style（物化成绝对定位空 Div 覆在视口上）。
        El::text('Scroll 视口 + Scrollbar 条（共享 ScrollbarHandle；always 常驻条'
            . '画在视口右缘）')->font(13.0)->semibold(),
        El::hFlex([
            El::vFlex([
                El::catalogScroll('kit-sb', [
                    El::text('视口行 01')->font(11.0), El::text('视口行 02')->font(11.0),
                    El::text('视口行 03')->font(11.0), El::text('视口行 04')->font(11.0),
                    El::text('视口行 05')->font(11.0), El::text('视口行 06')->font(11.0),
                ])->gap(4.0)->p(8.0)->w(220.0)->h(96.0)->bg('#ffffff')->rounded(6.0),
                // Scrollbar 是绝对定位覆盖层：盖在视口右缘（同 relative 容器内）。
                El::catalogScrollbar('kit-sb', 'vertical', 'always'),
            ])->relative()->w(220.0),
            El::text('↑ 视口 6 行在 96px 高里溢出，常驻条在右缘。'),
        ])->gap(16.0)->itemsStart(),
        // 批3：transition 宽度动画两态（点按钮在 40↔240 间过渡，transition 走
        // materialize 采样器 MotionValue 每帧回写）。要动画的元素**必须 ->id()**
        // ——跨帧动画身份 = id op（behavior.key），不落 id 就退化成 specId 逐帧跳变。
        // 静态基线里是窄态；selftest 的 transitionInterpolation 门用专属窗口做
        // "早期帧≠终值、多帧后=终值"的插值断言。
        El::text('transition 宽度动画（点按钮 40↔240，300ms 插值）')->font(13.0)->semibold(),
        El::hFlex([
            El::button((string) (($s['animWide'] ?? false) ? '收窄' : '展宽'))
                ->id('kit-anim-toggle')
                ->onClick(),
            El::vFlex([El::text('')])
                ->id('kit-anim-box')
                ->w((bool) ($s['animWide'] ?? false) ? 240.0 : 40.0)
                ->h(32.0)->bg('#2f6fed')->rounded(4.0)
                ->transition('width', 300.0),
            El::text('animWide=' . var_export((bool) ($s['animWide'] ?? false), true)),
        ])->gap(16.0)->itemsCenter(),
        // 五属性 × 三缓动图例（枚举覆盖门要求每个取值有真调用；这些元素没有
        // 两态驱动，transition 声明挂着但目标值不变——采样器收敛在原值，不动）。
        El::hFlex([
            El::text('宽·linear')->font(11.0)->px(6.0)->py(2.0)->borderWidth(1.0)
                ->id('kit-an-w')->transition('width', 300.0, 0.0, 'linear'),
            El::text('高·ease-in')->font(11.0)->px(6.0)->py(2.0)->borderWidth(1.0)
                ->id('kit-an-h')->transition('height', 300.0, 0.0, 'ease-in'),
            El::text('透明·ease-in-out')->font(11.0)->px(6.0)->py(2.0)->borderWidth(1.0)
                ->id('kit-an-o')->transition('opacity', 300.0, 0.0, 'ease-in-out'),
            El::text('左')->font(11.0)->px(6.0)->py(2.0)->borderWidth(1.0)
                ->id('kit-an-l')->transition('left', 300.0),
            El::text('顶')->font(11.0)->px(6.0)->py(2.0)->borderWidth(1.0)
                ->id('kit-an-t')->transition('top', 300.0),
            El::text('弹簧 width')->font(11.0)->px(6.0)->py(2.0)->borderWidth(1.0)
                ->id('kit-an-s')->spring('width', 250.0, 1.0, 0.001),
        ])->gap(10.0)->itemsCenter(),
        // ── 批2 A4：TitleBar C 期（专用 kind + descriptor 双通道）。组件形态在
        // 普通窗里只展示 bar 本体（混色面 + 子元素 justify-between）；拖拽带、
        // 红绿灯与 windowAction 三动词的完整语义在 selftest 的 titlebarWindowGate
        // （borderless+clientTitleBar 专属窗，树 1 基线不受影响——bar 高 34px
        // 落在视口折叠线下时基线逐字节不变，这里放显眼处会改 tree1 基线，随构建
        // 一并重录）。children 走 content 槽：materialize 的 kind 分派逐个
        // TitleBar::Child，不落 bar 根。
        El::text('TitleBar 客户端标题条（完整拖拽/红绿灯语义在 borderless 窗，见 selftest）')
            ->font(13.0)->semibold(),
        El::titleBar([
            El::text('kit · TitleBar C 期')->font(12.0),
            El::text('34px · theme 混色')->font(11.0),
        ])->w(480.0),
    ]))->sizeFull()->gap(10.0)->p(16.0)->overflowYScroll();
}

/** 数据与导航：步骤条 / 树形 / 分页 / 评分 / 描述列表 / 轮播。 */
function sectionKitData(array $s): El
{
    return El::vFlex(array_merge(kitHeader('kit · tier-A 工厂批（数据与导航）',
        'Settings/Stepper/Tree/Pagination/Rating/DescriptionList/Table(+Footer)/VForm/Carousel；回调经 cb(change,id) 路由'), [
        // Settings 放在树顶：它必须真进滚动视口才有像素证据（批1 首录时它排在
        // 轮播之后、整段都在折叠线下，像素逐字节不变，等于没拍着——基线重录过一次）。
        // Settings/SettingPage 拒样式（descriptor: "style is rejected"），别给它 ->w()。
        El::text('Settings 设置页族：Settings > SettingPage > SettingGroup > SettingItem（字段经 content 槽）')->font(13.0)->semibold(),
        El::settings('kit-set', [
            El::settingPage('通用', [
                El::settingGroup('外观', [
                    El::settingItem('主题', El::switchBox('深色')),
                ]),
            ]),
        ])
            // 原生侧栏宽度是"逐帧沉降"的布局反馈（ui/setting.cpp：从第二帧起收缩到
            // size range 下缘），不钉住就是快照相位抖动源（实测 3 跑 2 红）。
            ->rm('sidebar_width', [200.0])
            ->rm('sidebar_size_range', [160.0, 240.0]),
        El::text('Stepper 步骤条：三步、当前第 2 步（on_change → kit-steps）')->font(13.0)->semibold(),
        El::stepper('kit-steps', ['购物车', '下单', '支付'], 1, false, 'kit-steps'),
        El::stepper('kit-steps-v', ['起', '承', '转', '合'], 2, true),
        El::text('Tree 树形：TreeItem 可嵌 TreeItem，expanded 是初始展开态')->font(13.0)->semibold(),
        El::tree('kit-tree', [
            El::treeItem('kit-tree-src', 'src/')->rm('expanded', [true])->child(
                El::treeItem('kit-tree-main', 'main.php')),
            El::treeItem('kit-tree-docs', 'docs/')->rm('expanded', [true])->child(
                El::treeItem('kit-tree-guide', 'guide/')->child(
                    El::treeItem('kit-tree-events', 'events.md'))),
            El::treeItem('kit-tree-lock', 'GPUI.lock'),
        ]),
        El::text('Pagination 分页（on_change → kit-pg）与 Rating 评分（on_change → kit-rate；只读形走 disabled 位）')->font(13.0)->semibold(),
        El::hFlex([
            El::pagination('kit-pg', 2, 10, 5, 'kit-pg'),
            El::rating('kit-rate', 3.0, 5, false, 'kit-rate'),
            El::rating('kit-rate-ro', 4.0, 5, true)->color('#e07b39'),
        ])->gap(20.0)->itemsCenter(),
        El::text('DescriptionList 描述列表：横排（bordered）与 span 跨列')->font(13.0)->semibold(),
        El::descriptionList(['名称' => 'typephp-gpui', '用途' => 'PHP 原生桌面 GUI'], false, true),
        El::descriptionList([
            El::descriptionItem('跨列项', '占两格')->rm('span', [2.0]),
            El::descriptionItem('普通项', '一格'),
        ], true),
        // 主题表格与 VForm 排在 DescriptionList 之后：树2 的 680px 视口到
        // DescriptionList 已满，这两段在折叠线下——像素基线逐字节不变（Carousel
        // 同款处境）。materialize 照常跑（滚动不是虚拟化），原生侧证据由 --selftest
        // 的 tree2 registeredError/componentFailure 清零门给出；把 Settings 之上的
        // 既有段挪下去换像素是负和（tier-A 那批的像素证据会丢），故保持原序。
        El::text('主题表格（注册侧 Table 家族）：tableFooter 是 ComponentKind 之外的导出名，'
            . '行/格须用注册侧 TableRow/TableCell（kind 行没有 componentName，挂进去每帧报错）')->font(13.0)->semibold(),
        El::catalog('Table', [], [
            El::catalog('TableHeader', [], [
                El::catalog('TableRow', [], [
                    El::catalog('TableHead', [], [El::text('名称')->font(12.0)->semibold()]),
                    El::catalog('TableHead', [], [El::text('数量')->font(12.0)->semibold()]),
                ]),
            ]),
            El::catalog('TableBody', [], [
                El::catalog('TableRow', [], [
                    El::catalog('TableCell', [], [El::text('alpha')]),
                    El::catalog('TableCell', [], [El::text('3')]),
                ]),
                El::catalog('TableRow', [], [
                    El::catalog('TableCell', [], [El::text('beta')]),
                    El::catalog('TableCell', [], [El::text('7')]),
                ]),
            ]),
            El::tableFooter([
                El::catalog('TableRow', [], [
                    El::catalog('TableCell', [], [El::text('合计')->semibold()]),
                    El::catalog('TableCell', [], [El::text('10')->semibold()]),
                ]),
            ]),
        ]),
        El::text('VForm（Form 的第二导出名，同一构造器）：children 只收 Field；'
            . '字段控件用 kind 版 checkbox/switchBox，不添 state 实体（tree2 的 componentStates 门钉着 =1）')->font(13.0)->semibold(),
        El::vForm([
            El::field([El::checkbox('每周摘要')])->label('订阅'),
            El::field([El::switchBox('深色模式')])->label('外观'),
        ])->w(320.0),
        El::text('Carousel 轮播：八件套一次发齐，共用一颗 CarouselState（key=kit-car）')->font(13.0)->semibold(),
        El::carousel('kit-car', [
            El::vFlex([El::text('第一页')->font(16.0)->semibold(), El::text('轮播内容甲')])->gap(4.0),
            El::vFlex([El::text('第二页')->font(16.0)->semibold(), El::text('轮播内容乙')])->gap(4.0),
            El::vFlex([El::text('第三页')->font(16.0)->semibold(), El::text('轮播内容丙')])->gap(4.0),
        ])->w(480.0)->h(120.0),
        El::text('HorizontalRadioGroup 横排单选组（children 只收注册侧 Radio；on_change → kit-hrg，'
            . '值是选中下标）')->font(13.0)->semibold(),
        El::hRadioGroup('kit-hrg', ['按名称', '按路径', '按大小'], 0, 'kit-hrg'),
        // 批3 数据：聊天域静态件（Bubble/Message/Attachment/Marker）。MessageScroller
        // 的 render_item 是"返回元素"的回调——批4 的宿主元素回调分叉
        // （patch-host-element-cb.sh）落地后已能真渲染（见树 2 的 kit-msgs 样与
        // messageRowRender 门）；"不进树"的旧限制随分叉落地作废。
        El::hFlex([
            El::bubble('start', 'filled')->child(El::catalogLabel('标题气泡')),
            El::bubble('', 'secondary')->child(El::catalogLabel('普通气泡')),
            El::message('start', [El::catalogLabel('消息甲')]),
            El::attachment('kit-att', 'uploading', 'vertical', 'large'),
            El::marker('kit-mk', 'separator', true, 'spinner'),
        ])->gap(16.0)->itemsStart(),
        // 图表五连：构造只吃 rows 回调（shim 通道取不出行数据——真数据等批6宿主数据
        // 回路）；响亮失败门未落地（native 负向渲染进程段错误、ChartErrorProbe 探针无
        // 读口），卡点见 selftest skipped 记录。形状门在 kitStructuralGates。
        El::vFlex([
            El::barChart(),
            El::lineChart(),
            El::areaChart(),
            El::pieChart(),
            El::radarChart(),
        ])->gap(12.0)->itemsStart(),
        // 批7 特效段：Typewriter/GradientText（纯样式，无状态）。Typewriter 逐字
        // reveal（MotionAppear），GradientText 静态渐变（RgbaMix 每字插值）。
        // 特效族视觉验收只能逐帧快照/真人——selftest 只断"不崩 + 账本干净"。
        El::text('Typewriter 打字机文本（逐字 reveal，speed=50ms/字符）')->font(13.0)->semibold(),
        El::typewriter('打字机效果演示文本'),
        El::text('GradientText 渐变文字（#ff6600 → #0066ff 静态渐变）')->font(13.0)->semibold(),
        El::gradientText('渐变文字效果演示', '#ff6600', '#0066ff'),
        // 枚举覆盖：各白名单常量的其余取值也出现在示例里（枚举覆盖门要求每个
        // 取值都有真调用）。
        El::hFlex([
            El::catalogNotification('kit-not-extra', 'info'),
            El::catalogNotification('kit-not-extra-s', 'success'),
            El::catalogNotification('kit-not-extra-e', 'error'),
            El::dropdownButton('db-extra-v', '变体', 'danger', 'top_right')->dropdownButtonVariant('ghost'),
            El::dropdownButton('db-extra-a', '锚点', 'primary', 'top_left')->dropdownButtonAnchor('top_right'),
            El::bubble('start', 'muted'),
            El::bubble('start', 'tinted'),
            El::bubble('start', 'outline'),
            El::bubble('start', 'ghost'),
            El::bubble('start', 'destructive'),
            El::marker('mk-extra-1', 'plain', false, ''),
            El::marker('mk-extra-2', 'border', true, 'shimmer'),
            El::attachment('att-extra-1', 'pending', 'horizontal', 'xsmall'),
            El::attachment('att-extra-2', 'processing', 'horizontal', 'small'),
            El::attachment('att-extra-3', 'complete', 'vertical', 'large'),
            El::attachment('att-extra-f', 'failed', 'horizontal', 'medium'),
            El::catalogTabBar('kit-tb-v2', ['名称'])->tabBarVariant('segmented'),
            El::catalogTabBar('kit-tb-v3', ['路径'])->tabBarVariant('underline'),
        ])->gap(12.0)->itemsStart(),
        // 批4：Questionnaire 四件（嵌套最深家族：根→Item→Choice/Input，typed children
        // 逐层校验；根 shortcuts 走 QUESTIONNAIRE_SHORTCUTS 白名单）。事件面核对过：
        // 该家族没有选项变化回调（焦点/导航由 retained state 内部驱动），无需新 patch。
        El::questionnaire('kit-q', [
            El::questionnaireItem('age', '年龄', [
                El::questionnaireChoice('18', '18 岁以下', null, false, true),
            ])->rm('required', [true]),
        ], 'letters'),
        // enum 覆盖：shortcuts 的另一个取值 numbers。
        El::questionnaire('kit-q-nums', [
            El::questionnaireItem('age', '年龄', [
                El::questionnaireChoice('18', '18 岁以下'),
            ]),
        ], 'numbers'),
        // Accordion catalog 版 vs kind 版对照：kind 版是 El::accordionItem
        // （header/trigger/panel 三段件 + open(true)，无 onChange 会 Warn"cannot open"），
        // catalog 版是 registered Accordion/AccordionItem（multiple/bordered/size/on_toggle；
        // title 是 SchemaElement shim 缺口——项按计数建，无 title，见 Element.php）。
        El::hFlex([
            El::accordionItem('kind 折叠组', El::vFlex([El::catalogLabel('kind 内容')])->gap(4.0), 'kind-change'),
            El::catalogAccordion('acc-cat', 3, false, true, 'medium', 'acc-cat-tog'),
        ])->gap(16.0)->itemsStart(),
        // catalog 四颗升级糖（第二张脸）：Select/Combobox 带占位/菜单宽/搜索方法表，
        // TabBar 只收注册侧 Tab（kind 版 El::tab() 不是注册节点，挂进去每帧报错）。
        // rows 数据通道只建结构（同 DataTable/图表缺口，探针无读口）。
        El::hFlex([
            El::catalogSelect('kit-sel', '请选择')->selectMenuWidth(200.0),
            El::catalogCombobox('kit-cb', '下拉搜索', '搜索提示', 'kit-cb-change', 'kit-cb-confirm')
                ->searchPlaceholder('搜索提示（方法糖重发）'),
            El::catalogCombobox('kit-cb2', '搜索2', '', 'kit-cb2-change', 'kit-cb2-confirm')
                ->comboboxSearchable()->comboboxMenuWidth(220.0),
            El::catalogTabBar('kit-tb', ['名称', '路径', '大小'])
                ->tabBarMenu()->tabBarSelectedIndex(1)->tabBarVariant('tab')
                ->tabBarSize('large')->tabBarOnToggle('kit-tb-toggle'),
            ])->gap(16.0)->itemsStart(),
        // 批6 金融段：CandlestickChart 蜡烛图——宿主数据回路的第一个图表受益者
        // （rows 回调经 CallInLayout 的 shimId 分叉同步回填，handler 键 = kit-candle，
        // 见 wireKit）。行 schema {open,high,low,close}，与五图（tree1）同一条
        // SnapshotRowsWith 路但独立 handler id（五图固定 'kit-chart' 只建结构）。
        El::text('CandlestickChart 蜡烛图（宿主数据回路：render_rows 返回 '
            . '{open,high,low,close} 四字段行）')->font(13.0)->semibold(),
        El::candlestickChart('kit-candle'),
        // 批6 流量段：SankeyChart 桑基图——宿主数据回路的第三个受益者。与蜡烛图
        // 不同：双回调（render_nodes + render_links），descriptor 命令式建图
        // （New → Node×n → Link×n）。节点是四档收入/支出，链接是流向。
        El::text('SankeyChart 桑基图（宿主数据回路：render_nodes 返回 {label} 行、'
            . 'render_links 返回 {source,target,value} 行）')->font(13.0)->semibold(),
        El::sankeyChart('kit-sankey'),
        // 批6 搜索段：SearchableList 可搜索列表——宿主数据回路的第四个受益者。
        // rows 回调返回 [{title,value,section,disabled,badge}]，descriptor 转
        // SearchableListItem 数组 + SearchableListDelegate::Items 工厂（默认
        // matches：title 大小写不敏感子串）。query 传 null（无搜索输入框）。
        El::text('SearchableList 可搜索列表（宿主数据回路：render_rows 返回 '
            . '{title,value,section,disabled,badge} 行；delegate 的 matches 按 title '
            . '子串过滤）')->font(13.0)->semibold(),
        El::searchableList('kit-sl', 'kit-sl-rows')->w(320.0)->h(200.0),
        // 批2（2026-10-30）：Command 命令面板三件套专用工厂——CommandGroup(label)
        // 只收 CommandItem、CommandItem(label) 方法 disabled/keyword/checked/action
        // 走 ->rm()、CommandSeparator() 无参。根 catalogCommand 是 retained
        // CommandState（与 catalog.php 通用通道同一条路，这里换有校验的工厂糖）。
        // Item 的 action 是 ShellAction 名：真选中要真人键盘导航（面板焦点/搜索是
        // retained 状态），action 事件链与 NativeMenu 同一反查缝。
        El::text('Command 命令面板（catalogCommand + commandGroup/Item/Separator 三件套；'
            . 'action 选中派发 ShellAction，真选中需真人操作）')->font(13.0)->semibold(),
        El::catalogCommand('kit-cmd', [
            El::commandGroup('文件', [
                El::commandItem('新建文件')->rm('action', ['kit-cmd-new'])->rm('keyword', ['new create']),
                El::commandItem('打开…')->rm('keyword', ['open']),
            ]),
            El::commandSeparator(),
            El::commandItem('切换主题')->rm('checked', [true]),
        ])->h(160.0)->w(420.0),
        // 批4（2026-10-31）：MessageScroller 数据半边——render_item 是"返回元素"的
        // 回调，宿主元素回调分叉（patch-host-element-cb.sh）让它经 emitTree 进
        // 临时 arena 物化成真消息行（此前 shim 表项 function 空、必落 "Failed to
        // render message row" 错误行）。处理器经 App::elementCallback 登记描述数组。
        // 2026-10-30 的"只建结构、不进树"旧注随之作废——现在真进树、真渲染。
        El::text('MessageScroller 消息行宿主渲染（render_item → 宿主元素；'
            . '正/负路门 messageRowRender）')->font(13.0)->semibold(),
        El::messageScroller('kit-msgs', 3)->w(360.0)->h(140.0),
        // 批7 特效段：AnimatedNumber 数字滚动（count_up 动画，MotionAppear 0→value）。
        // id 是 MotionName 键（动画跨帧续跑）。decimals=1, pad=3。
        El::text('AnimatedNumber 数字滚动（count_up 0→42.5，decimals=1，pad=3）')->font(13.0)->semibold(),
        El::animatedNumber('kit-an', 42.5, 1)->pad(3),
        El::text('最近一次部件回调：' . (string) ($s['kitLog']['last'] ?? '—'))->font(11.0),
    ]))->sizeFull()->gap(10.0)->p(16.0)->overflowYScroll();
}

/**
 * 弹层与 CSV：Dialog/AlertDialog/Sheet 是真按钮触发的原生窗口效果（触发器就在页面上）；
 * DataTable 的数据通道缺口与 to_csv 的 PHP 侧对应物在这一节说明。
 */
function sectionKitOverlays(array $s): El
{
    $csv = El::tableToCsv(['名', '量'], [
        ['名' => '带逗号,的值', '量' => 3],
        ['名' => '带"引号"的值', '量' => 'x'],
    ]);
    return El::vFlex(array_merge(kitHeader('kit · tier-A 工厂批（弹层与 CSV）',
        'Dialog/AlertDialog/Sheet 触发器 + Notification/DropdownButton + tableToCsv；DataTable 数据通道缺口见下方说明'), [
        El::text('Dialog / AlertDialog / Sheet（点触发器开原生效果；ok/cancel/close 事件已接线）')->font(13.0)->semibold(),
        El::hFlex([
            // Dialog/Sheet 的正文走 content 具名槽（window_effects/mod.cpp:467-476 硬约束）；
            // AlertDialog 拒具名槽，正文走 description 方法。
            El::dialog('kit-dlg', '打开确认框',
                El::vFlex([El::text('要继续这个操作吗？'), El::text('确认后不可回退。')])->gap(4.0),
                '确认操作', 'kit-dlg-ok', 'kit-dlg-cancel'),
            El::alertDialog('kit-ald', '删除数据', '此操作不可撤销，确定继续？', true, '警告', 'kit-ald-ok'),
            El::sheet('kit-sheet', '打开抽屉',
                El::vFlex([El::text('抽屉正文：从右侧滑入。'), El::text('关闭走 on_close（kit-sheet-close）。')])->gap(4.0),
                'right', 'kit-sheet-close'),
        ])->gap(12.0)->itemsCenter(),
        // 批3 弹层：Notification 通知（catalog 组件版，与 App::notify() 两条路——
        // catalog 版没有宿主保留态窗层，事件走 on_click/on_close 回调标记）；
        // DropdownButton 原生下拉触发钮（构造 id/label，变体/锚点/点击/菜单项走工厂内糖）。
        El::catalogNotification('kit-not', '标题', '一条提示消息', 'warning',
            'kit-not-ok', 'kit-not-close')
            ->notificationOnClick('kit-not-ok')
            ->notificationOnClose('kit-not-close'),
        El::dropdownButton('kit-db-btn', '导出菜单', 'secondary', 'bottom_right',
            'kit-db-btn', ['导出 PNG', '导出 CSV']),
        // 糖再发一遍：与工厂内部发出的同名 op 值相同，materialize 按种类折叠（同值
        // 覆盖），用于演示这四个糖与工厂同形（链式门要求示例里真调用它们）。
        El::dropdownButton('kit-db-btn-extra', '更多', 'primary', 'top_left')
            ->dropdownButtonVariant('danger')
            ->dropdownButtonAnchor('bottom_left')
            ->dropdownButtonOnClick('kit-db-extra')
            ->dropdownMenuItem('新建', 'kit-db-extra-item-0')
            ->dropdownMenuItem('删除', 'kit-db-extra-item-1'),
        // 批5 弹层：PopupMenu/ContextMenu（上游 descriptor 补洞 patch-popup-menu.sh——
        // widget 层 ui/menu.h 早已在 vendored 上游，只差 catalog 面）。rows 走普通
        // 子节点：子元素的 text 是行标签、onClick 不接线（Func0 打包不暴露回调 id，
        // 见 patch 脚本注释——如实限制）。ContextMenu 第一个子节点是右键目标（上游
        // ContextMenu::Child 接线 OnMouseDownRight；真右键需真人 HID，selftest 前提式门）。
        El::text('PopupMenu 弹层菜单 / ContextMenu 上下文菜单（右键在目标上按下开菜单）')->font(13.0)->semibold(),
        El::hFlex([
            El::popupMenu('kit-pm', ['新建任务', '导出 CSV', '清空列表']),
            El::contextMenu('kit-cm', El::button('右键目标'), ['复制', '粘贴']),
        ])->gap(24.0)->itemsStart(),
        // 批4 弹层：Popover / HoverCard catalog 版（ctor 实参 + 锚点/开延迟/外观 +
        // on_open_change 回调；content 走具名槽——Popover/HoverCard 硬约束
        // require content(element)）。
        El::catalogPopover('kit-po', '弹窗',
            El::vFlex([El::catalogLabel('弹窗正文')])->gap(4.0)),
        // 批2 订正（2026-10-30）：catalogHoverCard 的 trigger_element 原注"SchemaElement
        // shim 缺口、不进树"已失真——批5 的 element_arg 桥（patch-element-arg.sh）
        // 把这类方法实参打通用（EmptyHeader 在用），HoverCard 直接受益。上游对
        // trigger_element 是**硬要求**（hover_card.cpp:79-82/145：缺了每帧 Fail
        // "HoverCard requires trigger_element(element)"），所以工厂加 $trigger 实参、
        // 经 elementArg 桥喂入；下面这颗就是真进树的（trigger 是 kind button——
        // 上游 ResolveElement 只要求 Element 并物化它，不限注册侧）。
        El::catalogHoverCard('kit-hc',
            El::vFlex([El::catalogLabel('悬停卡正文（catalog 版）')])->gap(4.0),
            '', 120.0, 80.0, false, null, El::button('悬停我（catalog HoverCard）')),
        El::hFlex([
            // POPOVER_ANCHORS 八个取值各来一颗（枚举覆盖门要求每个取值有真调用）。
            El::catalogPopover('po-top-left', '左上', El::vFlex([El::catalogLabel('正文')])->gap(4.0)),
            El::catalogPopover('po-top-center', '上中', El::vFlex([El::catalogLabel('正文')])->gap(4.0), 'top_center'),
            El::catalogPopover('po-top-right', '右上', El::vFlex([El::catalogLabel('正文')])->gap(4.0), 'top_right'),
            El::catalogPopover('po-bottom-left', '左下', El::vFlex([El::catalogLabel('正文')])->gap(4.0), 'bottom_left'),
            El::catalogPopover('po-bottom-center', '下中', El::vFlex([El::catalogLabel('正文')])->gap(4.0), 'bottom_center'),
            El::catalogPopover('po-bottom-right', '右下', El::vFlex([El::catalogLabel('正文')])->gap(4.0), 'bottom_right'),
            El::catalogPopover('po-left-center', '左中', El::vFlex([El::catalogLabel('正文')])->gap(4.0), 'left_center'),
            El::catalogPopover('po-right-center', '右中', El::vFlex([El::catalogLabel('正文')])->gap(4.0), 'right_center'),
        ])->gap(8.0)->itemsStart(),
        // 批1（2026-10-30）：**元素级**浮层行为糖——kind 节点的行为链。default_open 只在
        // 保留态**首帧播种**时读（materialize.cpp:2503-2508 held->open = defaultOpen），
        // 所以左下这颗 kind popover 在基线里就是开着的（打开的弹层正文压在锚点行上，
        // 属预期——这就是"糖真的到了部件"的像素证据）；overlay_closable=false 是"点外
        // 不收"（base/popover.cpp:174/237），静态帧不显形。open/close_delay 的消费端只有
        // HoverCard（base/hover_card.cpp:58、materialize.cpp:2633-2634），悬停才见效、
        // 快照不显形——发名证据由 overlayBehaviorSugar 结构门钉住。
        // 通道分界：registered catalog 节点（上面那排 catalogPopover）的同名配置走
        // descriptor 的 registered_method 表（EachMethod 只认 RegisteredMethod op），
        // 工厂 ctor 实参已覆盖；这四颗糖是给 kind 节点用的，两条通道别混。
        El::text('元素级浮层行为糖：kind popover 默认打开 + 点外不收（弹层在基线里是开着的）；'
            . 'kind hoverCard 开/收延迟（悬停才见效）')->font(13.0)->semibold(),
        El::hFlex([
            El::popover('元素级糖·默认打开', El::vFlex([El::catalogLabel('开箱即见的正文')])->gap(4.0))
                ->defaultOpen(true)->overlayClosable(false),
            El::hoverCard('元素级糖·悬停卡', El::vFlex([El::catalogLabel('120ms 后出现，80ms 后收起')])->gap(4.0))
                ->openDelayMs(120)->closeDelayMs(80),
        ])->gap(24.0)->itemsStart(),
        // 批2（2026-10-30）：NativeMenu 三件套专用工厂——NativeMenuTrigger(id,label)
        // **materialize 硬要求 on_effect_error(callback)**（native_menu.cpp
        // MaterializeTrigger），所以错误回调 id 是必填第三参；typed children 只收
        // NativeMenuItem(label,action)/NativeMenuSeparator。行选中派发 ShellAction →
        // gpuiAppActionHandler 反查回 'action' 事件（事件 id = action 名，
        // cpp-src/gpui_shim.cpp:293-302），wireKit 已接 $app->on(...,'action')；
        // 真点选（NSMenu 行选择是模态会话）要真人 HID——本域路由门是前提式，同 clipboard。
        El::text('NativeMenu 原生菜单触发器（trigger + item(label,action) + separator；'
            . '点触发器弹 OS 菜单，行选中派发 action 事件——真点选需真人）')->font(13.0)->semibold(),
        El::nativeMenuTrigger('kit-nm', '原生菜单', 'kit-nm-err', [
            El::nativeMenuItem('复制路径', 'kit-nm-copy'),
            El::nativeMenuSeparator(),
            El::nativeMenuItem('删除记录', 'kit-nm-delete')->rm('disabled', [true]),
        ]),
        // 批4（2026-10-31）：token 造粒 + 事件/渲染量化。inputReplaceToken 给
        // retained 输入当前选区造 token（InputReplaceWithToken）；on_token_click/
        // on_token_hover 经 Dispatch→DispatchArgs 的 shimId 早返回自动入队（送达
        // 量化在 selftest 的 tokenDeliveryRender 门，native 鼠标命中 token 属真人
        // HID）；'token' 渲染回调经 patch-token-render.sh 由宿主出元素（正/负对拍
        // = 徽章 vs label chip）。link 的 on_link_click 用 realClick 全原生路径量化。
        El::text('TokenInput：造 token + on_token_click/hover + token 渲染回调；'
            . 'link 的 on_link_click（realClick 全原生路径）')->font(13.0)->semibold(),
        El::hFlex([
            El::input('kit-tok-in')->placeholder('输入 @ 提及…')
                ->tokenRenderer()->onTokenClick()->onTokenHover()->w(320.0),
            El::link('打开文档', 'https://example.com')->id('kit-link')->onLinkClick(),
        ])->gap(16.0)->itemsCenter(),
        // 批5（TitleBar B 期）：无边框 + clientTitleBar 窗口的样例在 selftest 的
        // titlebarWindowGate（独立窗口：PHP 自拼 34px 标题条 + windowAction 三动词；
        // 系统红绿灯由 Cocoa 画在透明标题栏上）。C 期（TitleBar 出 descriptor/kind）
        // 仅立项设计，见 coverage 页。
        El::text('dialogCount=' . (string) ($s['dialogCount'] ?? 0)
            . ' kitLog=' . json_encode((array) ($s['kitLog'] ?? [])))->font(11.0),
        El::text('El::tableToCsv（Ely to_csv 的 PHP 侧对应物，纯数据变换）：')->font(13.0)->semibold(),
        El::text($csv)->font(12.0)->p(8.0)->borderWidth(1.0)->rounded(6.0),
        El::text('El::dataTable()：rows 数据半边已于批 6 接通（宿主数据回路 '
            . 'cmd/patch-host-data.sh——rows 快照经 shimId 分叉同步回填，'
            . '$app->on($id,\'render_rows\',…) 返回真数组；正/负路门 dataTableRowsCallbackSuccess '
            . '/ dataTableRowsCallbackLoud，探针 dataTableFails 留作负路证据）。还没通的是 '
            . 'render_cell 那半边：它返回的是元素不是数据，宿主数据回路只运"返回数据"回调'
            . '——元素回调是 BuildComponentElement shimId 分叉会 SIGBUS 的实证禁区，'
            . 'cell 现走上游 cellText 默认渲染。')
            ->font(12.0)->p(8.0)->borderWidth(1.0)->rounded(6.0),
    ]))->sizeFull()->gap(10.0)->p(16.0)->overflowYScroll();
}

/** 本域元素的接线：部件回调统一记进 kitLog['last']，selftest 的路由门据此断。 */
function wireKit(App $app): void
{
    $log = static function (string $key, string $val): void {
        ExampleState::$v['kitLog'][$key] = $val;
        ExampleState::$v['kitLog']['last'] = $key . '=' . $val;
    };
    foreach (['kit-steps' => 'step', 'kit-pg' => 'page', 'kit-rate' => 'rate',
              'kit-hrg' => 'hrg'] as $id => $key) {
        $app->on($id, 'change', static function (array $e) use ($log, $key): void {
            $log($key, (string) ($e['value'] ?? ''));
        });
    }
    $app->on('kit-dlg-ok', 'click', static function () use ($log): void {
        ExampleState::$v['dialogCount'] = (int) ExampleState::$v['dialogCount'] + 1;
        $log('dlgOk', 'ok');
    });
    $app->on('kit-dlg-cancel', 'click', static function () use ($log): void {
        $log('dlgCancel', 'cancel');
    });
    $app->on('kit-ald-ok', 'click', static function () use ($log): void {
        $log('aldOk', 'ok');
    });
    $app->on('kit-sheet-close', 'click', static function () use ($log): void {
        $log('sheetClose', 'closed');
    });
    $app->on('kit-not-ok', 'click', static function () use ($log): void {
        $log('notOk', 'ok');
    });
    $app->on('kit-not-close', 'click', static function () use ($log): void {
        $log('notClose', 'closed');
    });
    $app->on('kit-db-btn', 'click', static function () use ($log): void {
        $log('dbOk', 'ok');
    });
    // 批2：NativeMenu 的两条 PHP 侧接缝——on_effect_error（触发器物化的硬要求回调，
    // 原生菜单异步失败时响）与行选中的 action 事件（事件 id = item 的 action 名，
    // gpuiAppActionHandler 反查；真点选 NSMenu 行要真人 HID，这里把路由先接好，
    // selftest 的 nativeMenuShape 门断形状，送达证据同 clipboard 是前提式）。
    $app->on('kit-nm-err', 'click', static function () use ($log): void {
        $log('nmErr', 'effect-error');
    });
    foreach (['kit-nm-copy' => 'nmCopy', 'kit-nm-delete' => 'nmDelete',
              'kit-cmd-new' => 'cmdNew'] as $actionId => $key) {
        $app->on($actionId, 'action', static function () use ($log, $key): void {
            $log($key, 'action');
        });
    }
    // 批5：应用内键位表——'cmd-s' → 'kit::save'。keymap 派发到窗口无人接时
    // gpuiAppActionHandler enqueue 成 'action' 事件（菜单先例），这里接住落账。
    // 合成验收在 selftest 的 bindKeysGate（realKeyFlags 带 Command 修饰）。
    $app->bindKeys([
        ['keystroke' => 'cmd-s', 'action' => 'kit::save'],
    ]);
    $app->on('kit::save', 'action', static function () use ($log): void {
        $log('keymapSave', 'action');
    });
    // 批5：TitleBar B 期的三动词由 titlebarWindowGate 用独立窗口验证（windowAction
    // 直接调），wireKit 不挂树。
    // 批3：手风琴三段件的 on_change（trigger 翻 open 的回调）与 transition 两态
    // 的切换钮——点一下在 40↔240 间过渡（ExampleState 记宽态，重渲染时目标值变，
    // materialize 采样器逐帧插值）。
    foreach (['kit-acc-i0' => 'accI0', 'kit-acc-i1' => 'accI1'] as $id => $key) {
        $app->on($id, 'change', static function () use ($log, $key): void {
            $log($key, 'toggled');
        });
    }
    $app->on('kit-anim-toggle', 'click', static function () use ($log): void {
        ExampleState::$v['animWide'] = !((bool) (ExampleState::$v['animWide'] ?? false));
        $log('animToggle', (bool) ExampleState::$v['animWide'] ? 'wide' : 'narrow');
    });
    // 批4：MessageScroller 的 render_item 宿主渲染（元素描述经 App::elementCallback
    // 包装；行内容带可辨读的宿主标记，正路门的像素探针据此断）。
    $app->elementCallback('kit-msgs', 'render_item', static function (): El {
        return El::hFlex([El::text('宿主消息行·批4')->font(12.0)])
            ->p(6.0)->bg('#eef4ff');
    });
    // 批4：token 渲染回调（null 对拍 = label chip 回落）+ token/link 事件落账。
    $app->elementCallback('kit-tok-in', 'token', static function (): ?El {
        return (bool) (ExampleState::$v['tokenRenderNull'] ?? false) ? null
            : El::hFlex([El::text('提及')->font(11.0)])->px(6.0)->py(2.0)
                ->bg('#2f6fed')->rounded(4.0);
    });
    foreach (['on_token_click' => 'tokClick', 'on_token_hover' => 'tokHover',
              'on_link_click' => 'linkClick'] as $type => $key) {
        $id = $type === 'on_link_click' ? 'kit-link' : 'kit-tok-in';
        $app->on($id, $type, static function () use ($log, $key): void {
            $log($key, 'delivered');
        });
    }
    // 批6 金融段：CandlestickChart 的 rows 回调——宿主数据回路的图表侧正路。
    // 六个交易日 OHLC；up/down 色由 widget 的 New 取 theme chartBullish/chartBearish。
    $app->on('kit-candle', 'render_rows', static function (): array {
        return [
            ['open' => 42.0, 'high' => 44.5, 'low' => 41.2, 'close' => 43.8],
            ['open' => 43.8, 'high' => 45.0, 'low' => 43.1, 'close' => 44.2],
            ['open' => 44.2, 'high' => 44.8, 'low' => 42.0, 'close' => 42.5],
            ['open' => 42.5, 'high' => 43.0, 'low' => 40.5, 'close' => 41.0],
            ['open' => 41.0, 'high' => 42.5, 'low' => 40.8, 'close' => 42.2],
            ['open' => 42.2, 'high' => 44.0, 'low' => 42.0, 'close' => 43.5],
        ];
    });
    // 批6 流量段：SankeyChart 的双回调——nodes（标签）+ links（流向）。与蜡烛图
    // 单回调不同：descriptor 吃两个 cb（render_nodes + render_links），bridge 按
    // (id,type) 键分别存 handler。四档收支节点 + 五条流向链接。
    $app->on('kit-sankey', 'render_nodes', static function (): array {
        return [
            ['label' => '收入'],
            ['label' => '运营'],
            ['label' => '投资'],
            ['label' => '支出'],
            ['label' => '储蓄'],
        ];
    });
    $app->on('kit-sankey', 'render_links', static function (): array {
        return [
            ['source' => 0, 'target' => 3, 'value' => 50],
            ['source' => 0, 'target' => 4, 'value' => 30],
            ['source' => 1, 'target' => 3, 'value' => 20],
            ['source' => 1, 'target' => 4, 'value' => 10],
            ['source' => 2, 'target' => 4, 'value' => 15],
        ];
    });
    // 批6 搜索段：SearchableList 的 rows 回调——宿主数据回路的列表侧正路。
    // 三档分类 + 禁用项 + 徽标，覆盖 SearchableListItem 的各字段。
    $app->on('kit-sl-rows', 'render_rows', static function (): array {
        return [
            ['title' => '苹果', 'value' => 'apple', 'section' => 0],
            ['title' => '香蕉', 'value' => 'banana', 'section' => 0],
            ['title' => '樱桃', 'value' => 'cherry', 'section' => 0],
            ['title' => '胡萝卜', 'value' => 'carrot', 'section' => 1, 'badge' => '新'],
            ['title' => '芹菜', 'value' => 'celery', 'section' => 1, 'disabled' => true],
            ['title' => '茄子', 'value' => 'eggplant', 'section' => 2],
        ];
    });
}
