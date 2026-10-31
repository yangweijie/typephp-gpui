#!/usr/bin/env bash
# cmd/check-scrollbar-paint.sh — "scrollbarMode always 真画出滚动条"的 --shot 通道验收。
#
# 来路（收尾轮 A3，2026-10-31）：kit selftest 的 scrollbarModePaintPair 门里，
# regionRgb 读两盒右缘完全同色（rgb(224,224,224)）——归因是 macOS 无头渲染自动
# 隐藏原生滚动条（NSScroller overlay 无真人鼠标事件不画 thumb）。tree30 案证明
# --shot 通道（离屏渲染，App::snapshot）与 regionRgb 是两条色通道、可分歧，所以
# "always 真的画了"要用 --shot 通道复验一次。tree4（sectionKitScrollbarPair）
# 就是这个判别场景的像素树：hover / always 两盒同内容同尺寸 w220/h120/gap40/p40，
# 唯一差别 scrollbarMode。
#
# 探针换算：外层 vFlex p40 + hFlex gap40 + 盒 w220 → 盒1 右缘内 16x60 竖条
# = (244,48)，盒2 = (504,48)；PNG 与逻辑点 1:1（tests/baseline/kit/tree1.png
# = 900x680 实测口径）。与 selftest 门 244/504 同构（那扇窗 560x240，两盒
# itemsStart 靠左上，几何相同）。
#
# 判定（前提式门——不硬断）：
#   rc=0 + 两盒互异读数  → --shot 通道画了 always 滚动条（"自动隐藏"只挡
#         regionRgb 通道或需要真人事件，两说由读数自明）；
#   rc=2 未判定          → 出不了图 / PNG 尺寸不对（坐标换算基准失准）/
#         两盒仍同色（--shot 通道也没画——无真人鼠标事件时 overlay 仍自动
#         隐藏，前提不成立记 applicable=false，读数回填 scrollbarModePaintPair）。
# 不设 rc=1：本门是探针验收，"没画出滚动条"不是回归红，是前提不成立。
#
# 用法：bash cmd/check-scrollbar-paint.sh
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

BIN="$ROOT/examples/kit/build/kit"
PNG="/tmp/kit-sb-tree4.png"

if [[ ! -x "$BIN" ]]; then
    echo "SCROLLBAR-PAINT $BIN 不在 ⇒ 未判定（先 php bin/gpui build kit）"
    exit 2
fi

rm -f "$PNG"
"$BIN" --shot "$PNG" 4 > /tmp/gpui-kit-sb-shot.log 2>&1
rc=$?
if [[ $rc -ne 0 || ! -f "$PNG" ]]; then
    echo "SCROLLBAR-PAINT --shot rc=$rc / PNG 不在 ⇒ 未判定（见 /tmp/gpui-kit-sb-shot.log）"
    exit 2
fi

php -r '
$png = $argv[1];
$img = @imagecreatefrompng($png);
if ($img === false) { fwrite(STDERR, "SCROLLBAR-PAINT PNG 不可解 ⇒ 未判定\n"); exit(2); }
$w = imagesx($img); $h = imagesy($img);
if ($w !== 900 || $h !== 680) {
    echo "SCROLLBAR-PAINT PNG {$w}x{$h} ≠ 900x680（探针 244/504 换算基准失准）⇒ 未判定\n";
    exit(2);
}
$avg = static function (int $x, int $y): array {
    global $img;
    $sr = $sg = $sb = $n = 0;
    for ($px = $x; $px < $x + 16; $px++) {
        for ($py = $y; $py < $y + 60; $py++) {
            $c = imagecolorat($img, $px, $py);
            $sr += ($c >> 16) & 255; $sg += ($c >> 8) & 255; $sb += $c & 255; $n++;
        }
    }
    return [intdiv($sr, $n), intdiv($sg, $n), intdiv($sb, $n)];
};
$hover = $avg(244, 48);
$always = $avg(504, 48);
imagedestroy($img);
$f = static fn (array $c): string => sprintf("rgb(%d,%d,%d)", $c[0], $c[1], $c[2]);
if ($hover === $always) {
    echo "SCROLLBAR-PAINT hover 右缘={$f($hover)} always 右缘={$f($always)} 两盒同色"
        . " ⇒ 未判定（--shot 通道也没画：无真人鼠标事件 overlay 仍自动隐藏，"
        . "applicable=false；读数回填 scrollbarModePaintPair）\n";
    exit(2);
}
echo "SCROLLBAR-PAINT hover 右缘={$f($hover)} always 右缘={$f($always)} 两盒互异"
    . " ⇒ --shot 通道实证 always 真画出滚动条\n";
exit(0);
' "$PNG"
