<?php

// phpstan 的并行 worker 每个 PHP 进程默认吃 php.ini 的 memory_limit（本机 128M），
// 分析 src/Element.php 这种大文件就 OOM 崩（2026-10-31 实测 "reached configured
// PHP memory limit: 128M while running parallel worker"）。CLI 的
// --memory-limit 只盖 composer 脚本那条路；bootstrapFiles 在每个 worker 里
// 早于分析执行，把直接 vendor/bin/phpstan 的调用也盖住。
if ((int) ini_get('memory_limit') > 0 && (int) ini_get('memory_limit') < 1024) {
    ini_set('memory_limit', '1G');
}
