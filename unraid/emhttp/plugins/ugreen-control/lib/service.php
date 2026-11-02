<?php
declare(strict_types=1);
require_once __DIR__ . '/settings.php';

function ug_log(string $key, string $message): void {
    ug_lock('log', static function () use ($key, $message): void {
        $rates = ug_json_read('log-rates');
        if (time() - ($rates[$key] ?? 0) < 30) return;
        $rates[$key] = time();
        $rates = array_filter($rates, static fn($t) => time() - $t < 3600);
        ug_json_write('log-rates', $rates);
        $file = ug_path('log');
        $old = ug_read($file) ?? '';
        $line = date('c') . ' ' . $key . ' ' . preg_replace('/[\r\n\x00-\x1f]+/', ' ', substr($message, 0, 1200)) . "\n";
        $max = (int)ug_settings()['logging']['max_size_kb'] * 1024;
        $text = $old . ($old !== '' ? "\n" : '') . $line;
        if (strlen($text) > $max) {
            $text = substr($text, -$max);
            $nl = strpos($text, "\n");
            $text = $nl === false ? $line : substr($text, $nl + 1);
        }
        // /var/log 同目录原子替换，最多 256 KiB，不另留历史文件。
        ug_atomic($file, $text);
    });
}
function ug_exec(array $args, string $input = '', float $seconds = 5): array {
    $p = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($p)) throw new RuntimeException('不能启动子进程');
    fwrite($pipes[0], $input); fclose($pipes[0]);
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    $output = ''; $deadline = hrtime(true) / 1e9 + $seconds; $exit = -1; $timed = false;
    do {
        foreach ([1, 2] as $fd) {
            $chunk = stream_get_contents($pipes[$fd]);
            $output = substr($output . ($chunk === false ? '' : $chunk), -8192);
        }
        $status = proc_get_status($p);
        if (!$status['running']) { $exit = $status['exitcode']; break; }
        if (hrtime(true) / 1e9 >= $deadline) {
            // 只终止本调用刚创建且仍由 proc_open 持有的子进程。
            proc_terminate($p); $timed = true; break;
        }
        usleep(20000);
    } while (true);
    if ($timed) {
        $until = hrtime(true) / 1e9 + 0.3;
        do { $status = proc_get_status($p); if (!$status['running']) break; usleep(10000); } while (hrtime(true) / 1e9 < $until);
        if ($status['running']) proc_terminate($p, 9);
        $exit = 124;
    }
    foreach ([1, 2] as $fd) { $output = substr($output . stream_get_contents($pipes[$fd]), -8192); fclose($pipes[$fd]); }
    $closed = proc_close($p);
    return ['code' => $exit < 0 ? $closed : $exit, 'output' => $output];
}
function ug_starttime(string $stat): ?string {
    $end = strrpos($stat, ')');
    if ($end === false) return null;
    $fields = preg_split('/\s+/', trim(substr($stat, $end + 1)));
    return isset($fields[19]) && ctype_digit($fields[19]) ? $fields[19] : null;
}
function ug_identity_alive(array $id): bool {
    if (!isset($id['pid'], $id['starttime'], $id['generation']) || !is_int($id['pid']) || $id['pid'] < 2) return false;
    $base = ug_path('proc') . '/' . $id['pid'];
    if (ug_starttime(ug_read($base . '/stat') ?? '') !== $id['starttime']) return false;
    $cmd = @file_get_contents($base . '/cmdline');
    if ($cmd === false) return false;
    $args = explode("\0", rtrim($cmd, "\0"));
    return in_array(dirname(__DIR__) . '/scripts/daemon.php', $args, true)
        && in_array($id['generation'], $args, true) && (in_array('run', $args, true) || in_array('wait', $args, true));
}
function ug_generation(): string { return ug_read(ug_path('runtime') . '/generation') ?? ''; }
function ug_array_started(): bool { return (ug_ini(ug_path('emhttp') . '/var.ini', false)['mdState'] ?? '') === 'STARTED'; }
function ug_allowed(string $generation): bool {
    return $generation !== '' && hash_equals(ug_generation(), $generation)
        && !is_file(ug_path('runtime') . '/DISABLED') && ug_array_started();
}
function ug_wait_decision(array $job, string $generation, bool $disabled, bool $arrayStarted, float $now): string {
    if ($disabled || !$arrayStarted || ($job['generation'] ?? '') !== $generation) return 'cancel';
    return $now >= ($job['due_at'] ?? PHP_INT_MAX) ? 'run' : 'wait';
}
function ug_service_status(): array {
    $state = ug_json_read('service');
    $alive = ug_identity_alive(ug_json_read('pid'));
    $valid = ($state['generation'] ?? '') === ug_generation();
    $status = $valid ? ($state['state'] ?? 'stopped') : 'stopped';
    if (in_array($status, ['running', 'waiting'], true) && !$alive) $status = 'stopped';
    // atd 未运行/锁等待失败不能永久伪装成排队成功。
    if ($status === 'queued' && time() - ($state['queued_at'] ?? time()) > 120) {
        $status = 'error'; $state['error'] = 'at 任务超过 120 秒未启动，请检查 atd 和执行锁';
    }
    // queued 是 at 尚未运行；旧任务即使仍在 at 队列中也不能越过 generation 检查。
    return ['state' => $status, 'running' => $alive && $status === 'running', 'pid' => $alive ? ug_json_read('pid')['pid'] : null,
        'error' => $status === 'error' ? ($state['error'] ?? '服务错误') : null,
        'due_at' => in_array($status, ['queued', 'waiting'], true) ? ($state['due_at'] ?? null) : null,
        'at_job' => in_array($status, ['queued', 'waiting'], true) ? ($state['at_job'] ?? null) : null,
        'disabled' => is_file(ug_path('runtime') . '/DISABLED') || ug_settings()['daemon']['enabled'] !== 'true',
        'stop_behavior' => '停止后保留最后一次 LED 状态；关闭全部请选择 off 模式'];
}
function ug_preflight(): void {
    if (ug_read(ug_path('dmi')) !== 'DX4600') throw new RuntimeException('仅精确 DX4600 允许 LED 写入');
    $modules = ug_read(ug_path('proc') . '/modules');
    if ($modules === null) throw new RuntimeException('无法检查内核模块');
    if (preg_match('/^(led_ugreen|leds_ugreen|ugreen_leds|leds_mcu\S*)\s/m', $modules)) throw new RuntimeException('LED 内核驱动冲突；需由管理员移除原控制器');
    foreach (glob(ug_path('proc') . '/[0-9]*/cmdline') ?: [] as $file) {
        $cmd = @file_get_contents($file);
        if ($cmd === false || $cmd === '') continue;
        $args = explode("\0", rtrim($cmd, "\0"));
        foreach (array_slice($args, 0, 3) as $arg) {
            if (in_array(basename($arg), ['ugreen-leds', 'ugreen-leds-delayed.sh', 'led_daemon.sh', 'ugreenleds', 'ugreen-leds-monitor', 'ugreen_leds_cli'], true)) {
                throw new RuntimeException('发现其他 LED 控制进程，拒绝并行接管');
            }
        }
    }
    $cli = ug_path('cli');
    if (!is_executable($cli) || hash_file('sha256', $cli) !== '3d09aa29d7f6d1c90811d270cde6bd2218470a49fe989646804884477535e8e7') throw new RuntimeException('随包 LED CLI 缺失、不可执行或 SHA256 不匹配');
    if (!is_executable('/usr/bin/timeout')) throw new RuntimeException('缺少 /usr/bin/timeout');
}
function ug_queue_command(string $generation): string {
    if (!preg_match('/^[a-f0-9]{32}$/D', $generation)) throw new InvalidArgumentException('generation 无效');
    return 'exec /bin/bash ' . escapeshellarg(dirname(__DIR__) . '/scripts/daemon.sh') . ' wait ' . escapeshellarg($generation) . ' >/dev/null 2>&1' . "\n";
}
function ug_queue(bool $delayed): array {
    ug_init();
    return ug_lock('control', static function () use ($delayed): array {
        $cfg = ug_settings();
        if (is_file(ug_path('runtime') . '/DISABLED') || $cfg['daemon']['enabled'] !== 'true') throw new RuntimeException('服务已禁用，请先 enable');
        if (!ug_array_started()) throw new RuntimeException('阵列必须为 STARTED');
        if ($delayed && $cfg['autostart']['enabled'] !== 'true') return ['ok' => true, 'scheduled' => false, 'reason' => '自动启动已关闭'];
        $old = ug_service_status();
        if ($old['running'] || ($delayed && in_array($old['state'], ['queued', 'waiting'], true))) return ['ok' => true, 'service' => $old];
        if ($old['state'] === 'stopping') throw new RuntimeException('服务正在停止');
        $exec = fopen(ug_path('runtime') . '/exec.lock', 'c');
        $free = $exec && flock($exec, LOCK_EX | LOCK_NB);
        if ($free) flock($exec, LOCK_UN);
        if ($exec) fclose($exec);
        if (!$free && $old['state'] !== 'waiting') throw new RuntimeException('执行锁仍被占用，拒绝启动第二个服务');
        try {
            ug_preflight();
            if (!is_executable('/usr/bin/at')) throw new RuntimeException('缺少原生 at 服务');
        } catch (Throwable $e) {
            ug_json_write('service', ['generation' => ug_generation(), 'state' => 'error', 'error' => $e->getMessage()]);
            ug_log('service.preflight', $e->getMessage());
            throw $e;
        }
        $generation = bin2hex(random_bytes(16));
        $job = ['generation' => $generation, 'state' => 'queued', 'queued_at' => time(), 'due_at' => microtime(true) + ($delayed ? (int)$cfg['autostart']['delay_seconds'] : 0), 'delayed' => $delayed];
        ug_atomic(ug_path('runtime') . '/generation', $generation);
        ug_json_write('service', $job);
        $result = ug_exec(['/usr/bin/at', '-M', 'now'], ug_queue_command($generation), 3);
        if ($result['code'] !== 0) {
            $job['state'] = 'error'; $job['error'] = 'at 提交失败: ' . $result['output']; ug_json_write('service', $job);
            ug_log('service.queue', $job['error']);
            throw new RuntimeException($job['error']);
        }
        if (preg_match('/\bjob\s+(\d+)/i', $result['output'], $m)) $job['at_job'] = (int)$m[1];
        ug_json_write('service', $job);
        return ['ok' => true, 'service' => ug_service_status(), 'generation' => $generation];
    });
}
function ug_stop(bool $disable = false): array {
    $generation = ug_lock('control', static function () use ($disable): string {
        if ($disable) ug_atomic(ug_path('runtime') . '/DISABLED', '1');
        $generation = bin2hex(random_bytes(16));
        ug_atomic(ug_path('runtime') . '/generation', $generation);
        ug_json_write('service', ['generation' => $generation, 'state' => 'stopping']);
        return $generation;
    });
    // 不向磁盘上记录的 PID 发信号；执行中的一次 CLI 最多五秒，之后循环自行退出。
    $deadline = hrtime(true) / 1e9 + 10;
    do {
        $id = ug_json_read('pid');
        $lock = fopen(ug_path('runtime') . '/exec.lock', 'c');
        $cliLock = fopen(ug_path('runtime') . '/cli.lock', 'c');
        $cliFree = $cliLock && flock($cliLock, LOCK_EX | LOCK_NB);
        if ($cliFree) flock($cliLock, LOCK_UN);
        if ($cliLock) fclose($cliLock);
        $free = $lock && flock($lock, LOCK_EX | LOCK_NB);
        if ($free) { flock($lock, LOCK_UN); fclose($lock); }
        elseif ($lock) fclose($lock);
        if ($free && $cliFree && !ug_identity_alive($id)) break;
        usleep(100000);
    } while (hrtime(true) / 1e9 < $deadline);
    $done = $free && $cliFree && !ug_identity_alive(ug_json_read('pid'));
    ug_lock('control', static function () use ($generation, $done): void {
        if (ug_generation() === $generation) ug_json_write('service', ['generation' => $generation,
            'state' => $done ? 'stopped' : 'error', 'error' => $done ? null : '停止超时；未强杀进程，禁止再次启动直到执行锁释放']);
    });
    return ['ok' => $done, 'service' => ug_service_status()];
}
function ug_control(string $action): array {
    return match ($action) {
        'start' => ug_queue(false), 'schedule' => ug_queue(true),
        'stop' => ug_stop(), 'disable' => ug_disable(), 'enable' => ug_enable(),
        'status' => ['ok' => true, 'service' => ug_service_status()],
        default => throw new InvalidArgumentException('用法: control.php start|schedule|stop|status|disable|enable'),
    };
}
function ug_disable(): array {
    ug_settings_save(['autostart' => ['enabled' => 'false'], 'daemon' => ['enabled' => 'false']]);
    return ug_stop(true);
}
function ug_enable(): array {
    return ug_lock('control', static function (): array {
        ug_settings_save(['daemon' => ['enabled' => 'true'], 'autostart' => ['enabled' => 'true']]);
        $file = ug_path('runtime') . '/DISABLED';
        if (is_file($file) && !unlink($file)) throw new RuntimeException('无法清除禁用标记');
        return ['ok' => true, 'service' => ug_service_status()];
    });
}
function ug_state_for(string $generation, array $patch): void {
    ug_lock('control', static function () use ($generation, $patch): void {
        if (ug_generation() === $generation) ug_json_write('service', array_replace(ug_json_read('service'), $patch));
    });
}
