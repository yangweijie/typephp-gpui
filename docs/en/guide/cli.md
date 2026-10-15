# gpui CLI

`bin/gpui` is the build and development entry point. Run it from the repository
root; each example lives under `examples/<name>/`.

```bash
php bin/gpui <command> [args]
```

## Doctor

```bash
php bin/gpui doctor
```

Doctor checks bun, clang++, tpc, php-nano, the upstream tree, the pure C ABI, and
every required patch marker. `patchManifest()` is the executable patch inventory;
`GPUI.lock` records the pinned upstream context. Do not rely on a fixed patch count
copied from an older guide.

A missing tool or patch produces a nonzero exit code. A directory existing is not
sufficient: marker checks detect a partially patched sandbox.

## Build

```bash
php bin/gpui build hello
```

The full build runs in this order:

1. Synchronize the vendored upstream tree into the build sandbox.
2. Apply every patch in `patchManifest()` and verify its markers.
3. Run upstream `bun cmd/build.ts` to generate the amalgam and `gpui.o`.
4. Build the C++20 shim archive with `cmd/build-shim.sh`.
5. Compile PHP and the C++17 bridge with `tpc --nano`, then link the native binary.

The binary is `examples/hello/build/hello`. CLI execution uses the generated
`.app` bundle so the process has application identity; a raw Mach-O launch is not
an equivalent foreground/input test.

### Reusing shared native artifacts

```bash
php bin/gpui build input --no-native
```

This runs only the example's AOT stage and reuses `gpui.o` and
`libgpui_shim.a`. It rejects missing prerequisites, but does not establish that
existing native artifacts match changed C++ sources or patches.

After native changes, build the first example without this option and reuse its
artifacts for subsequent examples. The full sync can remove sandbox-generated
`.work` and `out` directories; repeatedly full-building each example wastes work
and can interfere with tests using the same object files.

```bash
bash cmd/all-examples.sh first-native
```

## Run, shot, and selftest

```bash
php bin/gpui run hello
php bin/gpui shot hello /tmp/hello.png
php bin/gpui selftest hello
```

These commands build before running. Selftest exit code 0 means its assertions
passed, not that all manual system interactions have been accepted.

Independent examples include input, choices, text, style, layout, table, overlay,
chrome, embedded, catalog, mac, and system. They compile shared feature sources
for one domain and print their own selftest steps. Discover the current list from
`examples/*/project.macos.yml` rather than maintaining another hard-coded list.

Domain examples expose a common diagnostic shape:

```bash
php bin/gpui run table --sections --no-native
php bin/gpui run table --shot /tmp/table.png 1 --no-native
php bin/gpui selftest table --no-native
```

`--sections` lists available tree ids, `--shot` captures a selected tree, and
`--selftest` runs the domain's checks. Hello additionally has its own fixed test
tree set, `--caps`, and `--clip`.

Arguments after the example name are forwarded by `run`, except `--no-native`,
which the CLI consumes. A `--` separator is not required or stripped by the CLI;
use the direct forms shown here unless the example explicitly handles a separator.

### Focused native diagnostics

```bash
php bin/gpui run overlay --escape
php bin/gpui run layout --dock
php bin/gpui run hello --caps
php bin/gpui run hello --clip
```

- `overlay --escape` opens a dialog, lets its focus trap settle, sends native
  Escape, and reports whether it closed. The observed trap can be zero immediately
  after opening and nonzero after pumping frames. This is a diagnostic, not a
  foreground-independent hard gate or a human HID test.
- `layout --dock` opens the retained Dock demonstration.
- `hello --caps` probes functions in the AOT artifact, not the development PHP CLI.
- `hello --clip` reports the existing clipboard representations; prepare known
  clipboard data before interpreting the output.

The independent `input` example performs native typing checks inside its
`--selftest` and owns the two human-facing modes: `--wait-key <sec>` (prints a
verdict, exits 0 only if a real key arrived) and `--trace` (appends every event
that reaches PHP to `/tmp/input-events.log`). `overlay --escape` and
`layout --dock` are the other two. Unknown flags are rejected with exit 2 rather
than falling through to the interactive window, so a mistyped mode fails fast.
There is no `--liveclick`, `--livetyping`, or `--probe-events` any more: what
they used to read is asserted by `chrome`'s `navRowsReachable` (first frame must
already be clickable), `input`'s per-box typing, and the per-domain event gates in
`choices`/`overlay`/`text`. The native probe APIs and foreground-state
distinctions are described in [Events](./events).

Foreground ownership is contested by the terminal, IDE, and other applications.
A successful in-process event route does not prove a physical keypress, and an
automation activation failure does not prove the event route is broken.

## Shotdiff

```bash
php bin/gpui shotdiff check hello
php bin/gpui shotdiff record hello
```

Shotdiff requires an already-built binary. It checks declared source freshness,
captures each registered tree, and compares with `tests/baseline/<example>/`.
It also rejects suspicious duplicate frames from different trees.

`record` writes baselines. Inspect the intended change first; do not record an
unexplained failure or increase tolerance to conceal timing differences. The FPS
HUD resource-row issue is still open. See [Testing](./testing#snapshot-stability).

## New application

```bash
php bin/gpui new myapp
```

The scaffold copies a project yml and creates a minimal `main.php` under the new
example directory. Keep all compilation sources in the yml: nano builds do not
load arbitrary PHP `require`/`include` files at runtime.

## Build environment

| Variable | Default | Meaning |
|---|---|---|
| `GPUI_DIR` | `build/gpui-kit-cpp` | Build sandbox copied from `upstream/gpui-kit-cpp`; an external path selects an externally supplied source tree |
| `NANO_DIR` | `vendor/swoole/php-nano` | php-nano location used by its patch step |

These variables configure build scripts. They do not imply that `getenv()` is
available in a compiled nano application; use supported application arguments for
runtime test signals.

## Composer scripts

| Script | Action |
|---|---|
| `composer test` | Pest |
| `composer analyse` | PHPStan level 5 |
| `composer test:cpp` | Native C++ shim tests |
| `composer parity:upstream` | Upstream vocabulary check |
| `composer test:all` | Pest, PHPStan, upstream parity, and shim tests |
| `composer bootstrap` / `composer build` | Full native build |
| `composer patch` | Apply patches without building the amalgam |
| `composer run` | Build and run the default example |
| `composer shot:check` / `composer shot:record` | Compare or record pixel baselines |
| `composer examples:all` | Discover and test all examples |
| `composer doctor` | Toolchain and patch checks |

For the patch and upstream synchronization workflow, see the
[contribution guide in Chinese](/contributing).
