# Getting Started

TypePHP GPUI lets you write cross-platform, GPU-accelerated desktop applications in
PHP: a declarative `Element` tree is compiled ahead-of-time by
[TypePHP](https://github.com/swoole/typephp) into a native binary, on top of
[gpui-kit-cpp](https://github.com/longbridge/gpui-kit) (a C++ port of Zed's GPUI).

> Only **macOS arm64** is supported today (nano mode — a self-contained binary, no libphp).
>
> The English guide covers architecture, components, upstream coverage, events,
> system capabilities, testing, and the CLI. Use the language menu for the Chinese edition.

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

The upstream sources are **vendored into this repository** at
`upstream/gpui-kit-cpp`. The tracked tree already contains host adaptations;
build patches must remain idempotent. Before building, `cmd/sync-upstream.sh`
copies it into `build/gpui-kit-cpp`, so generated files and patch execution do not
dirty the tracked source tree. To build against an external checkout, point
`GPUI_DIR` at it; automatic synchronization then skips that tree:

```bash
export GPUI_DIR=/path/to/gpui-kit-cpp   # optional; defaults to build/gpui-kit-cpp
```

`php bin/gpui doctor` checks the source tree and every patch marker from the current
manifest. The manifest, not a fixed count in this guide, is authoritative.

## 3. Build in one command

```bash
composer bootstrap        # same as: bin/gpui build hello
# or
php bin/gpui build hello
```

Internally: synchronize the sandbox → apply `patchManifest()` → `verifyPatches()` →
`bun cmd/build.ts` generates the amalgam → `cmd/build-shim.sh` compiles the C++20
static library → `tpc --nano` compiles PHP + the C++17 bridge and links. Output:
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

- [Architecture and the C ABI](./architecture)
- [Component API](./components) and [Upstream Coverage](./upstream-coverage)
- [Events and Callbacks](./events)
- [System Capabilities](./system-capabilities)
- [Testing and Gates](./testing) and [CLI](./cli)
