---
layout: home

hero:
  name: TypePHP GPUI
  text: 用 PHP 写原生桌面 GUI
  tagline: 声明式 Element 树 → TypePHP AOT → GPU 加速原生二进制（macOS arm64）
  actions:
    - theme: brand
      text: 快速开始
      link: /guide/getting-started
    - theme: alt
      text: 架构总览
      link: /guide/architecture

features:
  - title: 声明式链式 API
    details: El::div()->flexCol()->sizeFull()->gap(8)->child(...)，每个方法记一个 SpecOp，一次性下发成原生 El 树。
  - title: 复用 gpui-kit 组件库
    details: 覆盖 gpui base ComponentKind 原语，并经 Registered 机制接入 component_shell 富组件（Alert/Sidebar/Form/Menu/Breadcrumb/Icon/DropdownMenu…）。
  - title: C-ABI 防火墙
    details: gpui amalgam（C++20）与 nano 应用（C++17）经纯 C ABI 解耦，PHP 侧零原生类型泄漏。
  - title: 事件回主循环
    details: 点击/输入/滑块/定时器/对话框/catalog 回调统一入队，App->on(id, type, handler) 分派，无 QuickJS。
  - title: 可复现构建
    details: 一键 bootstrap（补丁→amalgam→shim 库→tpc nano），GPUI.lock 契约 + 补丁 marker 校验。
  - title: 六道门禁
    details: Pest / PHPStan / C++ shim_test / --selftest / --shot 基线 diff / doctor，全绿即交付。
---
