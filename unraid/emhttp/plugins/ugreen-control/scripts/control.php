<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/lib/service.php';
// disks_mounted: php scripts/control.php schedule
// stopping_svcs: php scripts/control.php stop
// start 为立即排入 at，schedule 使用 autostart.delay_seconds（默认 300）。
// stop 取消所有旧 generation，最多等 10 秒，不强杀，不改变 LED 状态。
try {
    ug_init();
    $result = ug_control($argv[1] ?? 'status');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    exit($result['ok'] ? 0 : 1);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    exit(1);
}
