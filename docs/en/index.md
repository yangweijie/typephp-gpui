---
layout: home

hero:
  name: TypePHP GPUI
  text: Native desktop GUIs written in PHP
  tagline: A declarative Element tree → TypePHP AOT → a GPU-accelerated native binary (macOS arm64)
  actions:
    - theme: brand
      text: Getting Started
      link: /en/guide/getting-started
    - theme: alt
      text: Full docs (中文)
      link: /guide/getting-started

features:
  - title: Declarative chained API
    details: El::div()->flexCol()->sizeFull()->gap(8)->child(...) — every call records one SpecOp, sent down once as a native El tree.
  - title: Reuses the gpui-kit component library
    details: Covers the gpui base ComponentKind primitives and wires up component_shell's rich components (Alert/Sidebar/Form/Menu/Breadcrumb/Icon/DropdownMenu…) through the Registered mechanism.
  - title: C-ABI firewall
    details: The gpui amalgam (C++20) and the nano application (C++17) are decoupled through a pure C ABI, so no native type ever leaks to the PHP side.
  - title: Events back to the main loop
    details: Clicks, input, sliders, timers, dialogs and catalog callbacks all queue up; App->on(id, type, handler) dispatches them. No QuickJS.
  - title: Reproducible build
    details: One bootstrap command (patches → amalgam → shim archive → tpc nano), with a GPUI.lock contract and patch-marker verification.
  - title: Six gates
    details: Pest / PHPStan / C++ shim_test / --selftest / --shot baseline diff / doctor. All green means shipped.
---
