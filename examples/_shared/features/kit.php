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
 * catalogHoverCard 因 trigger_element 硬缺口不进树）。
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
        // 的 render_item 是"返回元素"的回调（shim 通道只发事件不带返回值，DataTable
        // 实证）——真数据等批6，本批只建结构、不进树（会让 componentStates 计数涨，
        // 破坏 tree2CarouselState 的 ==1 门）。
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
        // require content(element)）。trigger_element 是 SchemaElement shim 缺口
        // （与 StatusBar left_content 同）——不给空参，文档注明。
        El::catalogPopover('kit-po', '弹窗',
            El::vFlex([El::catalogLabel('弹窗正文')])->gap(4.0)),
        // catalogHoverCard 不进树：HoverCard materialize **硬要求** trigger_element
        // （SchemaElement shim 缺口——发字符串实参在 native 物化阶段段错误，实测），
        // 结构与形状门仍钉住 catalogHoverCardShape。
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
        El::text('dialogCount=' . (string) ($s['dialogCount'] ?? 0)
            . ' kitLog=' . json_encode((array) ($s['kitLog'] ?? [])))->font(11.0),
        El::text('El::tableToCsv（Ely to_csv 的 PHP 侧对应物，纯数据变换）：')->font(13.0)->semibold(),
        El::text($csv)->font(12.0)->p(8.0)->borderWidth(1.0)->rounded(6.0),
        El::text('El::dataTable()：工厂与形状门已落（DataTableState + rows/render_cell 两个回调标记），'
            . '但 rows 回调要"返回数据"，shim 通道只发事件不带返回值——真渲染当帧失败。'
            . '这个失败不走 request->Fail：DataTable 的 Failure() 自己返回一颗带错误文本的 '
            . 'Div，账本收不到；shim 经上游 SetDataTableProbe 探针把它记进 stateCounts 的 '
            . 'dataTableBuilds/dataTableFails 两位，--selftest 的 dataTableRowsCallbackLoud '
            . '门据此断"失败看得见"。宿主数据回路（listRenderer 同款）属 tier B。')
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
