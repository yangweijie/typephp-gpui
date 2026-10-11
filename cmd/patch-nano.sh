#!/usr/bin/env bash
# cmd/patch-nano.sh — 修正 swoole/php-nano v1.1.0 的依赖解析缺口。
#
# 现象：nano 构建的 TypePHP 产物启动时报 "Unable to start PHP Nano extensions"。
# 根因：tpc 生成的项目模块带 ZEND_MOD_REQUIRED("Core")（引擎核心），但
# php-nano/src/extension.cpp 的 find_available() 只在传入的 composer extensions
# 数组里按名查找；而 "Core" 由 php_nano_startup_core() 注册进全局 module_registry
# （小写键），不在该数组中 → 被判为 unsatisfiable → 依赖校验失败。
#
# 修复：find_available 数组未命中时，回落到 module_registry 以小写名查找，
# 让引擎注册的模块（Core 等）能被解析。上游更新后重跑本脚本即可。
#
# 用法：NANO_DIR=<php-nano> cmd/patch-nano.sh   （默认 vendor/swoole/php-nano）

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
NANO_DIR="${NANO_DIR:-$ROOT/vendor/swoole/php-nano}"
EXT="$NANO_DIR/src/extension.cpp"

[[ -f "$EXT" ]] || { echo "ERROR: $EXT not found. composer require swoole/php-nano?" >&2; exit 1; }

MARK="Engine-provided modules"
if grep -qF "$MARK" "$EXT"; then
    echo "extension.cpp: already patched, skip"
    exit 0
fi

echo "extension.cpp: patching find_available (module_registry fallback)..."
cp "$EXT" "$EXT.bak"
python3 - "$EXT" <<'PYEOF'
import sys
path = sys.argv[1]
src = open(path).read()
old = '''zend_module_entry *find_available(
    zend_module_entry *const *extensions, size_t count, const char *name) {
    for (size_t index = 0; index < count; ++index) {
        if (extensions[index] != nullptr && extensions[index]->name != nullptr
            && std::strcmp(extensions[index]->name, name) == 0) {
            return extensions[index];
        }
    }
    return nullptr;
}'''
new = '''zend_module_entry *find_available(
    zend_module_entry *const *extensions, size_t count, const char *name) {
    for (size_t index = 0; index < count; ++index) {
        if (extensions[index] != nullptr && extensions[index]->name != nullptr
            && std::strcmp(extensions[index]->name, name) == 0) {
            return extensions[index];
        }
    }
    // Engine-provided modules (e.g. "Core") are registered into module_registry by
    // php_nano_startup_core() under a lower-cased key, but are not passed in the
    // composer extensions array. Resolve required deps against the registry too,
    // otherwise a project module's ZEND_MOD_REQUIRED("Core") reads as unsatisfiable.
    if (name != nullptr) {
        char lowered[64];
        size_t n = std::strlen(name);
        if (n < sizeof(lowered)) {
            for (size_t i = 0; i < n; ++i) {
                char c = name[i];
                lowered[i] = (c >= 'A' && c <= 'Z') ? static_cast<char>(c + ('a' - 'A')) : c;
            }
            lowered[n] = '\\0';
            return static_cast<zend_module_entry *>(
                zend_hash_str_find_ptr(&module_registry, lowered, n));
        }
    }
    return nullptr;
}'''
if old not in src:
    sys.exit("find_available block not found — nano version drifted; update this patch")
src = src.replace(old, new, 1)
open(path, "w").write(src)
print("  find_available resolves engine modules via module_registry")
PYEOF
