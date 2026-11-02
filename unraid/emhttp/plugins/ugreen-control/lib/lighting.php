<?php
declare(strict_types=1);
require_once __DIR__ . '/service.php';
require_once __DIR__ . '/bios.php';

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
function ug_style(string $color, int $brightness, bool $blink = false): array {
    if ($color === 'off' || $brightness === 0) return ['off' => true];
    return ['color' => $color, 'brightness' => $brightness, 'blink' => $blink];
}
function ug_desired(array $cfg, array $mapping, array $net): array {
    $desired = []; $mode = $cfg['mode']['global'];
    $slots = ['disk1', 'disk2', 'disk3', 'disk4'];
    if ($cfg['behavior']['manage_power'] === 'true') $slots[] = 'power';
    if ($cfg['behavior']['manage_netdev'] === 'true') $slots[] = 'netdev';
    if ($mode !== 'smart') {
        foreach ($slots as $slot) $desired[$slot] = $mode === 'off' ? ['off' => true] : ug_style($cfg['power']['all_on_color'], (int)($slot === 'power' ? $cfg['power']['brightness'] : $cfg['disk_brightness']['active']));
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
        $desired[$row['led']] = ug_style($cfg['disk_colors'][$state], (int)$cfg['disk_brightness'][$state], $blink);
    }
    if ($cfg['behavior']['manage_power'] === 'true') $desired['power'] = ug_style($cfg['power']['smart_color'], (int)$cfg['power']['brightness']);
    if ($cfg['behavior']['manage_netdev'] === 'true' && $net['network'] !== null) {
        $state = $net['network'];
        // 网络灯闪烁由 netdev 内核触发器自动处理，这里只设置颜色和亮度。
        $desired['netdev'] = ug_style($cfg['netdev_colors'][$state], (int)$cfg['netdev_brightness'][$state], false);
        if (!isset($desired['netdev']['off'])) {
            $desired['netdev']['trigger'] = $state === 'connected' && $cfg['activity']['network_blink'] === 'true' ? 'netdev' : 'none';
        }
    }
    return $desired;
}
function ug_net_iface(): string {
    $cfg = ug_read('/boot/config/network.cfg') ?? '';
    if (preg_match('/IFNAME\[0\]\s*=\s*"?([a-zA-Z0-9_.:-]+)"?/', $cfg, $m) && $m[1] !== '') return $m[1];
    return 'eth0';
}
function ug_sysfs_write(string $led, string $attr, string $value): void {
    $path = ug_path('leds') . '/' . $led . '/' . $attr;
    if (!is_writable($path)) throw new RuntimeException("sysfs 节点不可写: $path");
    if (@file_put_contents($path, $value) === false) throw new RuntimeException("写 sysfs 失败: $path = $value");
}
function ug_sysfs_init(): void {
    // 硬盘灯：oneshot 触发器，活动时写 shot 触发单次闪烁（与旧监控一致的稳定路径）。
    foreach (['disk1', 'disk2', 'disk3', 'disk4'] as $led) {
        ug_sysfs_write($led, 'trigger', 'oneshot');
        ug_sysfs_write($led, 'invert', '1');
        ug_sysfs_write($led, 'delay_on', '100');
        ug_sysfs_write($led, 'delay_off', '100');
    }
    // 网络灯：netdev 内核触发器自动处理 tx/rx 闪烁。
    ug_sysfs_write('netdev', 'trigger', 'netdev');
    ug_sysfs_write('netdev', 'device_name', ug_net_iface());
    ug_sysfs_write('netdev', 'link', '1');
    ug_sysfs_write('netdev', 'tx', '1');
    ug_sysfs_write('netdev', 'rx', '1');
    ug_sysfs_write('netdev', 'interval', '500');
    // 电源灯：静态 none 触发器，由颜色与亮度控制。
    ug_sysfs_write('power', 'trigger', 'none');
}
function ug_apply_led_state(string $led, array $intent): void {
    if (($intent['off'] ?? false)) {
        // 硬盘灯用 color 0 0 0 熄灭以保持 oneshot 触发器（写 brightness 0 会重置触发器、
        // 使 shot 节点消失，导致恢复后活动闪烁失败）。
        if (str_starts_with($led, 'disk')) ug_sysfs_write($led, 'color', '0 0 0');
        else ug_sysfs_write($led, 'brightness', '0');
        return;
    }
    if ($led === 'netdev') {
        $trigger = $intent['trigger'] ?? 'none';
        if (!str_contains(ug_read(ug_path('leds') . '/netdev/trigger') ?? '', '[' . $trigger . ']')) {
            ug_sysfs_write('netdev', 'trigger', $trigger);
        }
        if ($trigger === 'netdev') {
            ug_sysfs_write('netdev', 'device_name', ug_net_iface());
            ug_sysfs_write('netdev', 'link', '1');
            ug_sysfs_write('netdev', 'tx', '1');
            ug_sysfs_write('netdev', 'rx', '1');
            ug_sysfs_write('netdev', 'interval', '500');
        }
    }
    ug_sysfs_write($led, 'color', $intent['color']);
    ug_sysfs_write($led, 'brightness', (string)$intent['brightness']);
}
function ug_apply(array $desired, string $generation): void {
    ug_lock('cli', static function () use ($desired, $generation): void {
        if (!ug_allowed($generation)) return;
        $cache = ug_json_read('led-cache');
        foreach ($desired as $led => $intent) {
            if (!in_array($led, ['disk1', 'disk2', 'disk3', 'disk4', 'power', 'netdev'], true)) throw new InvalidArgumentException('灯位无效');
            if (!ug_allowed($generation)) return;
            $blink = $intent['blink'] ?? false;
            unset($intent['blink']);
            // 颜色/亮度变化才写 sysfs（缓存去重）；活动闪烁每轮写 shot，不缓存。
            if (($cache[$led] ?? null) !== $intent) {
                ug_apply_led_state($led, $intent);
                $cache[$led] = $intent;
                ug_json_write('led-cache', $cache);
            }
            if ($blink && str_starts_with($led, 'disk')) {
                // 防御性恢复 oneshot 触发器，避免 shot 节点因外部操作丢失。
                if (!is_writable(ug_path('leds') . '/' . $led . '/shot')) ug_sysfs_write($led, 'trigger', 'oneshot');
                ug_sysfs_write($led, 'shot', '1');
            }
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
        ug_state_for($generation, ['state' => 'starting']);
        ug_ensure_driver($generation);
        ug_guarded($generation, static function (): void { ug_sysfs_init(); });
        ug_json_write('led-cache', []);
        ug_state_for($generation, ['state' => 'running', 'error' => null]);
        ug_log('service.start', 'LED 服务开始');
        try { ug_bios_restore(); } catch (Throwable $e) { ug_log('bios.restore_error', $e->getMessage()); }
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
    } catch (UgCancelled $e) {
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
