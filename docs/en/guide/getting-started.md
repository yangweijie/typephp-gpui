# Getting Started

TypePHP GPUI lets you write cross-platform, GPU-accelerated desktop applications in
PHP: a declarative `Element` tree is compiled ahead-of-time by
[TypePHP](https://github.com/swoole/typephp) into a native binary, on top of
[gpui-kit-cpp](https://github.com/longbridge/gpui-kit) (a C++ port of Zed's GPUI).

> Only **macOS arm64** is supported today (nano mode — a self-contained binary, no libphp).
>
> The rest of this documentation currently lives in
> [Chinese](/guide/getting-started); this page is the first of the English set, and the
> nav only lists pages that actually exist so the build's dead-link check stays green.

## Prerequisites

| Tool | Purpose | Check |
|------|---------|-------|
| PHP 8.4+ | language runtime + build scripts | `php -v` |
| Composer | pulls typephp / php-nano / pest / phpstan | `composer -V` |
| bun | generates the gpui-kit-cpp amalgam | `bun -v` |
| clang++ (Xcode CLT) | builds the C++20 shim + C++17 bridge | `clang++ --version` |
| gpui-kit-cpp sources | the upstream GUI library | see below |

PHP 8.4 is a floor, not a preference: `composer.lock` pins `swoole/typephp` and
`swoole/phpx` at `php >=8.4 <8.6`, so an 8.3 checkout cannot install its dev
dependencies at all.

## 1. Install PHP dependencies

```bash
composer install
```

## 2. Place the gpui-kit-cpp sources

Upstream has no git remote, so you supply the source tree yourself, at
`vendor/gpui-kit-cpp` (or point `GPUI_DIR` anywhere):

```bash
export GPUI_DIR=/path/to/gpui-kit-cpp   # optional; defaults to vendor/gpui-kit-cpp
```

`bin/gpui doctor` checks that the sources are present and that all seven patches have
landed.

## 3. Build in one command

```bash
composer bootstrap        # same as: bin/gpui build hello
# or
php bin/gpui build hello
```

Internally, in order: apply the seven patches → `verifyPatches()` → `bun cmd/build.ts`
generates the amalgam → `cmd/build-shim.sh` compiles the C++20 static library →
`tpc --nano` compiles PHP + the C++17 bridge and links. Output:
`examples/hello/build/hello`.

## 4. Run it, then verify it

```bash
php bin/gpui run hello          # opens a window
php bin/gpui selftest hello     # 21 end-to-end links inside the real AOT binary
php bin/gpui shotdiff check hello   # 18 trees compared against recorded baselines
```

`--selftest` and `--shot` are the two gates that actually prove the native side: the
first drives real events through the C ABI, the second screenshots a live `NSView` and
diffs it pixel-by-pixel. A green PHP-level test suite proves the declarations, not the
window.

## Where to go next

- Architecture and why the C-ABI firewall exists — [架构与 C-ABI 防火墙](/guide/architecture)
- Component coverage — [组件 API](/guide/components)
- Event routing — [事件与回调](/guide/events)
- System capabilities (clipboard, tray, sandboxed fs…) — [系统能力](/guide/system-capabilities)
