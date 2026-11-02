<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/lib/lighting.php';
try {
    // 正常由 daemon.sh 持有 exec.lock；直接调用也必须取得相同执行锁。
    $fd = @fopen('/proc/self/fd/9', 'r');
    $target = @readlink('/proc/self/fd/9');
    if ($fd) fclose($fd);
    $lock = null;
    if ($target !== ug_path('runtime') . '/exec.lock') {
        ug_init();
        $lock = fopen(ug_path('runtime') . '/exec.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('daemon 执行锁已被占用');
    }
    exit(ug_daemon($argv[1] ?? '', $argv[2] ?? ''));
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n"); exit(1);
}
