#!/usr/bin/env bash
# cmd/check-token-paint-png.sh — tokenDeliveryRender 判别臂 b 的 PNG 通道判读。
#
# 来路（批1 收尾轮 A2，2026-10-31）：kit selftest 的 tokenDeliveryRender 门里，
# 宿主渲染回调（elementCallback token）链路硬门全绿（造粒/事件送达/renderCalls
# 恒 >0），但徽章（红 #ff0000）像素在 regionRgb 全网格扫描确定性缺席——3 遍
# 读数逐字相同，非多帧抖动。判别臂 b 用第二条色通道复核：selftest 在 ON 帧在
# 屏时 App::snapshot() 离屏出图到 /tmp/gpui-kit-token-probe.png（420x200，与
# regionRgb 同逻辑点坐标、1:1 像素，tree1 基线实测口径），本脚本用 PHP GD 按
# selftest 同一网格（x 2..410 步 10、y 2..192 步 6）找红点。
#
# GD 判读放脚本侧不进 AOT 编译域（examples 编译域无 GD 函数先例）。
#
# 判定（判别臂不出门红——它只出证据，硬门仍在 selftest）：
#   rc=0 + "PNG通道=有红@(x,y)"  → 通道分歧实证：paint/合成帧里有徽章而
#         regionRgb 读不到（tree30 案同款两读分歧）——嫌疑改判"读数通道"；
#   rc=0 + "PNG通道=无红"        → 缺口实证：两条色通道都读不到，宿主回调
#         subtree 确实没进 paint/合成帧（与 messageRowRender 同款）——修复
#         另立轮（findings.md 有记）；
#   rc=2                         → 未判定：二进制不在 / selftest 没出图 /
#         PNG 不可解（前提不成立，不硬断）。
#
# 用法：bash cmd/check-token-paint-png.sh
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

BIN="$ROOT/examples/kit/build/kit"
# AOT 进程的 sys_get_temp_dir() 读环境 TMPDIR（macOS = /var/folders/…/T/），
# 脚本侧必须同源，别写死 /tmp——首版写死导致 selftest 出图在 TMPDIR 而脚本找
# /tmp，报"没出图"（2026-10-31 实测）。
PNG="${TMPDIR:-/tmp}/gpui-kit-token-probe.png"

if [[ ! -x "$BIN" ]]; then
    echo "TOKEN-PNG $BIN 不在 ⇒ 未判定（先 php bin/gpui build kit）"
    exit 2
fi

rm -f "$PNG"
# selftest 内部 tokenDeliveryRender 门在 ON 帧出图；selftest 本身要全绿到达该门。
"$BIN" --selftest > /tmp/gpui-kit-token-png-selftest.log 2>&1
rc=$?
if [[ $rc -ne 0 ]]; then
    echo "TOKEN-PNG selftest rc=$rc ⇒ 未判定（门红时到不了出图点，见 /tmp/gpui-kit-token-png-selftest.log）"
    exit 2
fi
if [[ ! -f "$PNG" ]]; then
    echo "TOKEN-PNG selftest 没出图（$PNG 不在）⇒ 未判定（snapshot 前提不成立）"
    exit 2
fi

php -r '
$png = $argv[1];
$img = @imagecreatefrompng($png);
if ($img === false) { fwrite(STDERR, "TOKEN-PNG PNG 不可解 ⇒ 未判定\n"); exit(2); }
$w = imagesx($img); $h = imagesy($img);
if ($w !== 420 || $h !== 200) {
    echo "TOKEN-PNG PNG 尺寸 {$w}x{$h} ≠ 420x200（坐标换算基准失准）⇒ 未判定\n";
    exit(2);
}
$at = null;
for ($gx = 2; $gx <= 410 && $at === null; $gx += 10) {
    for ($gy = 2; $gy <= 192; $gy += 6) {
        $c = imagecolorat($img, $gx, $gy);
        $r = ($c >> 16) & 255; $g = ($c >> 8) & 255; $b = $c & 255;
        if ($r > 200 && $g < 90 && $b < 90) { $at = [$gx, $gy, sprintf("rgb(%d,%d,%d)", $r, $g, $b)]; break; }
    }
}
imagedestroy($img);
if ($at !== null) {
    echo "TOKEN-PNG PNG通道=有红@({$at[0]},{$at[1]}) {$at[2]} ⇒ 通道分歧实证（paint/合成帧有徽章、regionRgb 读不到）\n";
    exit(0);
}
echo "TOKEN-PNG PNG通道=无红 ⇒ 缺口实证（两色通道一致缺席，宿主回调 subtree 未进 paint/合成帧；修复另立轮）\n";
exit(0);
' "$PNG"
