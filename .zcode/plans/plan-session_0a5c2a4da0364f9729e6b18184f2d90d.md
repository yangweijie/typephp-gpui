# gpui-kit-cpp 延后组件·续集轮（批8 起）——实施计划

## 背景与现状
- 上一轮 dynamic workflow「延后清单组件实现」(dwfrun-789fb882) 已按 7 批落实 31 项、终态门禁 35/36 绿，交付就是当前工作区里**未提交**的批5/6/7 文件（四支新补丁脚本、Element.php、kit 示例、中英文档、基线、GPUI.lock/bin 登记）。
- 本轮继续同一系列：消化剩余延后清单。已确认范围 = **组件尾洞 + 样式糖 + op 能力糖 + 大件立项**（动画/路径绘制/键位/TitleBar 全上）。
- 剩余清单的主要来源（以 docs/guide/upstream-coverage.md 现账为准，侦察时重建）：
  - 组件尾洞：MessageScroller 数据半边（批6 宿主数据回路已是前置，现可接）、ScrollbarHandle/scrollbar 专用工厂、裸 catalog 四组（Command 三件/NativeMenu/Resizable/base-kind Accordion 根）专用工厂、catalogHoverCard 的 trigger_element（patch-element-arg.sh 有先例）；
  - 样式糖 ~20 颗（col_span_full、shadow_2xs/xs/xl/2xl、text_left、flex_grow_0/1、cursor_* 等，"一行糖 + isCalled 门"）；
  - op 糖：浮层行为（default_open/overlay_closable/open_delay/close_delay）、高级滚动（mode/scroll_size/overflow_scrollbar/track_scroll 等）、token 渲染回调（返回内容回调 → 走批6 数据回路正路）；
  - 大件：动画 transition/spring 整族（动 style.cpp 参数化管线）、路径绘制 PathFill/PathStroke + move_to/line_to/curve_to/cubic_bezier_to/arc_to/close（新 op 族）、应用内键位 bind_keys（keymap + automation `key` 动词可作门）、TitleBar/无边框窗口（窗口 chrome 立项，风险最大放最后）。
  - 文档张力修正：coverage 文档 DataTableState"数据通道未通"段与批6 实况不同步、Notification 工厂中英两处矛盾、"剩余缺口 2 个"句已过时（El::messageScroller 已建 state）——随批修正。

## 第 0 步：分批提交批5/6/7 交付（主会话，开工前）
沿用 progress.md 既定口径：**按名字分批 add/commit，绝不 `git add -A`，`.workbuddy-ai/` 不入库**。拟分三笔：
1. `feat: 批7 文字特效`——cmd/patch-text-effects.sh + src/Element.php + tests/ElementTest.php + examples/kit 与 _shared/features/kit.php + components.md 中英；
2. `feat: 批6 三颗数据回路组件入清单`——cmd/patch-{candlestick,sankey,searchable-list}.sh + GPUI.lock + bin/gpui + cmd/check-upstream-parity.php + cpp-src/gpui_shim_events.inc + upstream-coverage/testing 中英；
3. `test: hello tree27 基线重录`——tests/baseline/hello/tree27.png。
提交后跑 `composer test:all` 确认干净树上全绿再开工。

## 第 1 步：编写新一轮 workflow 脚本
新建草稿 `.zcode/workflow-drafts/延后清单续集.dwf.ts`，完全复用上轮已验证的骨架（侦察→合诊→hole 按批施工+门禁→终检报告），要点更新：
- **侦察员**：从当前仓库状态重建清单（不许拿旧文档当事实——MessageScrollerState 句即为例）；逐项在参考仓 /Volumes/data/git/c/gpui-kit-cpp 找 refWhere/refStyle；大件四项单独核接缝：style.cpp 的 transition/spring 参数化名与 FamilyFields 掩码、materialize 的路径 op 链、shell keymap 面、window_*.cpp 的 frameless 面。
- **批次原则不变**：同层同管线一批、3-6 项/批、风险大往后排、outOfScope 写明理由。预期批次序（侦察可调）：A 样式糖+文档张力修正 → B op 糖（浮层/滚动）→ C 组件尾洞（State/工厂/trigger_element）→ D token 回调 → E 动画 → F 路径绘制 → G 键位 → H TitleBar/无边框。
- **门禁不变**：每批 build kit + selftest / doctor / pest（门12/10/11）/ phpstan / 动上游时 shim_test（先删旧产物防假绿）/ 声明制重录 kit 基线；红一道回炉一次；终检 shotdiff check kit + 文档锚点门 + markdown 报告 artifact。
- **大件验收边界**：动画/路径的视觉验收沿批7 特效族口径（逐帧快照/真人 + selftest 结构读数）；键位用 automation `key` 动词出原生读数；TitleBar/无边框允许降级为"立项设计交付 + skipped 写明卡点"，不许假装。
- **承上轮教训写进 FIXER_SYSTEM**：数据面走"返回数据"回调（SnapshotRowsWith 先例），**不做** render_item/render_cell 元素回调（SIGBUS 实证）；混合默认值签名一律实例糖（TypePhp 翻译器缺陷）；三层 parity 门会查新名字，同名必须三处同步（bridge/shim/stub/FakeBridge）。
- 子代理模型沿用上轮 new-provider/spark-x2.6（不可用时落回会话默认）。

## 第 2 步：运行与盯守
- CreateWorkflow 启动（后台），按完成通知读 GetWorkflowRun；批次红门时只在脚本内回炉，不人工干预；跑完读报告 artifact 与 result。
- 预计规模与上轮同量级（上轮 355M tokens / 22h）。

## 第 3 步：收尾
- 向用户转述报告：门禁终态、落实/skipped/outOfScope 清单、改动文件、真人复验清单（examples/kit 逐组件过）。
- 把"计划读数"按惯例归档进 progress.md（新 Phase 段落），更新 task_plan.md。
- 提交本轮交付（同样按名字分批，等门禁全绿）。

## 明确 outOfScope（除非侦察升级判定）
i18n、wasm/mobile、语义 token 表暴露、字体装载、http 图片策略——与上轮口径一致，理由照抄/重申。
