<?php
declare(strict_types=1);
require_once __DIR__ . '/service.php';

function ug_disks(): array { return ug_ini(ug_path('emhttp') . '/disks.ini'); }
function ug_serial(array $disk, string $base): string {
    return trim((string)($disk['serial'] ?? ug_read($base . '/serial') ?? $disk['id'] ?? ''));
}
function ug_mapping(array $disks, array $cfg): array {
    $rows = []; $used = []; $seenSerials = [];
    foreach ($disks as $slot => $disk) {
        if (!is_array($disk)) continue;
        $device = $disk['device'] ?? '';
        if (!is_string($device) || !preg_match('/^sd[a-z]+$/D', $device)) continue;
        $base = ug_path('block') . '/' . $device . '/device';
        $path = realpath($base);
        if ($path === false) continue;
        $serial = ug_serial($disk, $base);
        $hctl = preg_match('~/(\d+:\d+:\d+:\d+)$~D', $path, $h) ? $h[1] : null;
        $ata = preg_match('~/ata([1-4])/~', $path . '/', $m) ? (int)$m[1] : null;
        // USB/SAS 不猜灯位；显式序列号校准也必须来自本机 SATA 设备。
        if (!preg_match('~/ata\d+/~', $path . '/') && ug_read($base . '/vendor') !== 'ATA') continue;
        $led = null; $evidence = '未校准';
        if ($cfg['behavior']['disk_map_mode'] === 'disk') {
            foreach ($cfg['disk_map'] as $target => $wanted) {
                if ($serial !== '' && hash_equals($wanted, $serial)) { $led = $target; $evidence = '显式序列号校准'; }
            }
        } elseif ($ata !== null) {
            $led = 'disk' . $ata; $evidence = 'SATA端口推定，未目视校准';
        }
        if ($led !== null && isset($used[$led])) throw new RuntimeException('映射冲突：多个设备指向同一灯位');
        if ($serial !== '' && isset($seenSerials[$serial])) throw new RuntimeException('映射冲突：序列号不唯一');
        if ($led !== null) $used[$led] = true;
        if ($serial !== '') $seenSerials[$serial] = true;
        $spundown = ($disk['spundown'] ?? '') === '1';
        $rows[] = ['device' => '/dev/' . $device, 'array_slot' => (string)$slot, 'led' => $led ?? '未校准',
            'evidence' => $evidence, 'sysfs_path' => $path, 'hctl' => $hctl, 'ata_port' => $ata,
            'serial' => $serial, 'spundown' => $spundown, 'state' => $spundown ? 'standby' : null,
            'read_kbps' => null, 'write_kbps' => null];
    }
    return $rows;
}
function ug_validate_serial_map(array $cfg, array $disks): void {
    if ($cfg['behavior']['disk_map_mode'] !== 'disk') return;
    if (!$cfg['disk_map']) throw new InvalidArgumentException('序列号模式需要至少一项校准');
    $mapped = array_filter(ug_mapping($disks, $cfg), static fn($row) => $row['evidence'] === '显式序列号校准');
    if (count($mapped) !== count($cfg['disk_map'])) throw new InvalidArgumentException('校准序列号必须唯一匹配当前 SATA 设备');
}
function ug_diskstats(?string $raw): array {
    $out = [];
    foreach (explode("\n", $raw ?? '') as $line) {
        $f = preg_split('/\s+/', trim($line));
        if (count($f) < 14 || !preg_match('/^sd[a-z]+$/D', $f[2])) continue;
        if (!ctype_digit($f[5]) || !ctype_digit($f[9])) continue;
        $out[$f[2]] = ['read' => (float)$f[5], 'write' => (float)$f[9]];
    }
    return $out;
}
function ug_rate(float $before, float $after, float $elapsed, float $unit = 1024): ?float {
    return $elapsed > 0 && $after >= $before ? round(($after - $before) / $elapsed / $unit, 2) : null;
}
function ug_network(?array $before, float $now): array {
    $base = ug_path('net') . '/eth0';
    $carrier = ug_read($base . '/carrier');
    $network = $carrier === '1' ? 'connected' : ($carrier === '0' ? 'disconnected' : null);
    $rx = ug_read($base . '/statistics/rx_bytes'); $tx = ug_read($base . '/statistics/tx_bytes');
    $result = ['interface' => 'eth0', 'network' => $network, 'speed_mbps' => is_numeric(ug_read($base . '/speed')) ? (int)ug_read($base . '/speed') : null,
        'rx_bytes' => $rx, 'tx_bytes' => $tx, 'sampled_at' => $now, 'rx_kbps' => null, 'tx_kbps' => null];
    if ($before !== null && isset($before['sampled_at']) && $now > $before['sampled_at']) {
        foreach (['rx', 'tx'] as $key) {
            $old = $before[$key . '_bytes'] ?? null; $value = $result[$key . '_bytes'];
            if (is_string($old) && ctype_digit($old) && is_string($value) && ctype_digit($value)) {
                $result[$key . '_kbps'] = ug_rate((float)$old, (float)$value, $now - $before['sampled_at']);
            }
        }
    }
    return $result;
}
function ug_style(string $color, int $brightness, bool $blink = false, float $speed = 0, int $threshold = 1): array {
    if ($color === 'off' || $brightness === 0) return ['-off'];
    $args = ['-color', ...explode(' ', $color), '-brightness', (string)$brightness];
    if ($blink) {
        $period = $speed >= $threshold * 16 ? 260 : ($speed >= $threshold * 4 ? 480 : 820);
        $on = max(80, min(240, (int)($period / 3)));
        return [...$args, '-blink', (string)$on, (string)($period - $on)];
    }
    return [...$args, '-on'];
}
function ug_desired(array $cfg, array $mapping, array $net): array {
    $desired = []; $mode = $cfg['mode']['global'];
    $slots = ['disk1', 'disk2', 'disk3', 'disk4'];
    if ($cfg['behavior']['manage_power'] === 'true') $slots[] = 'power';
    if ($cfg['behavior']['manage_netdev'] === 'true') $slots[] = 'netdev';
    if ($mode !== 'smart') {
        foreach ($slots as $slot) $desired[$slot] = $mode === 'off' ? ['-off'] : ug_style($cfg['power']['all_on_color'], (int)($slot === 'power' ? $cfg['power']['brightness'] : $cfg['disk_brightness']['active']));
        return $desired;
    }
    foreach (['disk1', 'disk2', 'disk3', 'disk4'] as $slot) $desired[$slot] = ug_style($cfg['disk_colors']['offline'], (int)$cfg['disk_brightness']['offline']);
    foreach ($mapping as $row) {
        if (!preg_match('/^disk[1-4]$/D', $row['led'])) continue;
        if ($row['state'] === null) { unset($desired[$row['led']]); continue; }
        $state = $row['state'];
        $speed = ($row['read_kbps'] ?? 0) + ($row['write_kbps'] ?? 0);
        $threshold = (int)$cfg['activity']['disk_threshold_kbps'];
        $blink = $state === 'active' && $cfg['activity']['disk_blink'] === 'true' && $speed >= $threshold;
        $desired[$row['led']] = ug_style($cfg['disk_colors'][$state], (int)$cfg['disk_brightness'][$state], $blink, $speed, $threshold);
    }
    if ($cfg['behavior']['manage_power'] === 'true') $desired['power'] = ug_style($cfg['power']['smart_color'], (int)$cfg['power']['brightness']);
    if ($cfg['behavior']['manage_netdev'] === 'true' && $net['network'] !== null) {
        $state = $net['network']; $threshold = (int)$cfg['activity']['network_threshold_kbps'];
        $speed = ($net['rx_kbps'] ?? 0) + ($net['tx_kbps'] ?? 0);
        $blink = $state === 'connected' && $cfg['activity']['network_blink'] === 'true' && $speed >= $threshold;
        $desired['netdev'] = ug_style($cfg['netdev_colors'][$state], (int)$cfg['netdev_brightness'][$state], $blink, $speed, $threshold);
    }
    return $desired;
}
function ug_apply(array $desired, string $generation): void {
    ug_lock('cli', static function () use ($desired, $generation): void {
        if (!ug_allowed($generation)) return;
        $cache = ug_json_read('led-cache');
        $settle = (int)ug_settings()['daemon']['led_settle_ms'];
        foreach ($desired as $led => $args) {
            if (!in_array($led, ['disk1', 'disk2', 'disk3', 'disk4', 'power', 'netdev'], true)) throw new InvalidArgumentException('灯位无效');
            // stop 改变 generation 后不再启动下一次 CLI，即使本批尚有其他 LED。
            if (!ug_allowed($generation)) return;
            if (($cache[$led] ?? null) === $args) continue;
            // 灯与灯之间静置，避免连续块写导致 MCU 不确认命令（DX4600 已知时序问题）。
            if ($settle > 0) usleep($settle * 1000);
            $result = null;
            for ($attempt = 0; $attempt < 2; $attempt++) {
                if ($attempt > 0) usleep(max(2000000, $settle * 1000)); // 失败后给 MCU 更长恢复时间再重试一次
                ug_preflight();
                $result = ug_exec(['/usr/bin/timeout', '--signal=TERM', '--kill-after=0.2s', '5s', ug_path('cli'), $led, ...$args, '--force', '--apply'], '', 5.5);
                if ($result['code'] === 0) break;
            }
            if ($result['code'] !== 0) {
                ug_json_write('led-cache', []);
                throw new RuntimeException('LED CLI 失败 ' . $led . ' rc=' . $result['code'] . ' ' . $result['output']);
            }
            $cache[$led] = $args;
            ug_json_write('led-cache', $cache);
        }
    });
}
function ug_remap(?array $patch = null): array {
    $cfg = ug_settings();
    if ($patch !== null) {
        $cfg = ug_patch($cfg, $patch); ug_validate_serial_map($cfg, ug_disks());
        $cfg = ug_settings_save($patch);
    }
    $mapping = ug_mapping(ug_disks(), $cfg);
    ug_lock('mapping', static function () use ($mapping): void {
        ug_json_write('mapping', ['mapping' => $mapping, 'updated_at' => time()]);
        ug_atomic(ug_path('runtime') . '/remap', bin2hex(random_bytes(8)));
    });
    return ['ok' => true, 'mapping' => $mapping];
}
function ug_daemon(string $action, string $generation): int {
    ug_init();
    if (!in_array($action, ['run', 'wait'], true) || !preg_match('/^[a-f0-9]{32}$/D', $generation)) throw new InvalidArgumentException('daemon 参数无效');
    $admitted = ug_lock('control', static function () use ($generation): bool {
        if (!ug_allowed($generation)) return false;
        $id = ['pid' => getmypid(), 'starttime' => ug_starttime(ug_read(ug_path('proc') . '/' . getmypid() . '/stat') ?? ''), 'generation' => $generation];
        if ($id['starttime'] === null) throw new RuntimeException('无法校验 daemon 进程身份');
        ug_json_write('pid', $id);
        return true;
    });
    if (!$admitted) return 0;
    try {
        if ($action === 'wait') {
            ug_state_for($generation, ['state' => 'waiting']);
            while (true) {
                $job = ug_json_read('service');
                $decision = ug_wait_decision($job, $generation, !ug_allowed($generation), ug_array_started(), microtime(true));
                if ($decision === 'cancel') return 0;
                if ($decision === 'run') break;
                usleep(100000);
            }
        }
        ug_preflight();
        ug_json_write('led-cache', []);
        ug_state_for($generation, ['state' => 'running', 'error' => null]);
        ug_log('service.start', 'LED 服务开始');
        $previous = []; $lastActivity = []; $net = null; $mapping = []; $disks = [];
        $lastRefresh = 0.0; $seenRemap = null; $lastTick = null; $mapConfig = null;
        while (ug_allowed($generation)) {
            $cfg = ug_settings();
            if ($cfg['daemon']['enabled'] !== 'true') break;
            $now = hrtime(true) / 1e9;
            $remap = ug_read(ug_path('runtime') . '/remap');
            $currentMapConfig = [$cfg['behavior']['disk_map_mode'], $cfg['disk_map']];
            if ($mapping === [] || $seenRemap !== $remap || $mapConfig !== $currentMapConfig ||
                ($cfg['behavior']['remap_on_hotplug'] === 'true' && $now - $lastRefresh >= (int)$cfg['daemon']['hotplug_check_interval'])) {
                $disks = ug_disks(); $mapping = ug_mapping($disks, $cfg); $lastRefresh = $now; $seenRemap = $remap; $mapConfig = $currentMapConfig;
            } else {
                // Unraid 的休眠缓存不向磁盘发命令，每轮读取，避免过时的电源状态。
                $disks = ug_disks();
            }
            $stats = ug_diskstats(ug_read(ug_path('proc') . '/diskstats'));
            foreach ($mapping as &$row) {
                $device = basename($row['device']); $cur = $stats[$device] ?? null; $prev = $previous[$device] ?? null;
                $row['read_kbps'] = $row['write_kbps'] = null;
                if ($cur !== null && $prev !== null && $lastTick !== null) {
                    $row['read_kbps'] = ug_rate($prev['read'], $cur['read'], $now - $lastTick, 2);
                    $row['write_kbps'] = ug_rate($prev['write'], $cur['write'], $now - $lastTick, 2);
                    if (($row['read_kbps'] ?? 0) + ($row['write_kbps'] ?? 0) > 0) $lastActivity[$device] = $now;
                }
                $cached = $disks[$row['array_slot']] ?? [];
                $row['spundown'] = ($cached['spundown'] ?? '') === '1';
                $present = isset($cached['device']) && $cached['device'] === $device && $cur !== null;
                $row['state'] = !$present ? 'offline' : ($row['spundown'] ? 'standby' :
                    (isset($lastActivity[$device]) && $now - $lastActivity[$device] < (int)$cfg['daemon']['io_idle_seconds'] ? 'active' :
                    (($cached['spundown'] ?? '') === '0' ? 'idle' : null)));
            }
            unset($row);
            $previous = $stats; $lastTick = $now; $net = ug_network($net, $now);
            ug_json_write('mapping', ['mapping' => $mapping, 'updated_at' => time()]);
            ug_json_write('network', $net + ['updated_at' => time()]);
            $desired = ug_desired($cfg, $mapping, $net);
            ug_apply($desired, $generation);
            ug_json_write('snapshot', ['mode' => $cfg['mode']['global'], 'led_status' => $desired, 'updated_at' => time()]);
            $until = hrtime(true) / 1e9 + (int)$cfg['daemon']['refresh_interval'];
            while (hrtime(true) / 1e9 < $until && ug_allowed($generation)) usleep(100000);
        }
        return 0;
    } catch (Throwable $e) {
        ug_state_for($generation, ['state' => 'error', 'error' => $e->getMessage()]);
        ug_log('service.error', $e->getMessage());
        return 1;
    } finally {
        ug_lock('control', static function () use ($generation): void {
            $id = ug_json_read('pid');
            if (($id['pid'] ?? null) === getmypid() && ($id['generation'] ?? '') === $generation) ug_json_write('pid', []);
            if (ug_generation() === $generation && (ug_json_read('service')['state'] ?? '') !== 'error') ug_json_write('service', ['generation' => $generation, 'state' => 'stopped']);
        });
    }
}
