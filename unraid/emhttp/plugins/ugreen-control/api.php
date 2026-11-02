<?php
/**
 * Unraid 第二阶段：本地状态和受会话/CSRF 保护的 LED 服务。
 * 所有硬件写入由 at 管理的 daemon 调用随包 LED CLI 完成。
 * BIOS、风扇、AC 恢复始终不可写；不提供网络请求或包安装功能。
 */

declare(strict_types=1);
define('PLUGIN',    'ugreen-control');
define('PLUGIN_DIR','/usr/local/emhttp/plugins/' . PLUGIN);
define('BOOT_DIR',  '/boot/config/plugins/' . PLUGIN);
define('VAR_EMHTTP','/var/local/emhttp');
define('HWMON_BASE','/sys/class/hwmon');
define('DMI_NAME',  '/sys/class/dmi/id/product_name');
define('JSON_FLAGS', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
define('LOG_MAX_BYTES', 256 * 1024);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function respond(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_FLAGS);
    exit;
}
function refuse(string $message = 'Method not allowed', int $status = 405): void {
    respond(['ok' => false, 'error' => $message], $status);
}
function safe_read(string $path): ?string {
    if (!is_readable($path)) return null;
    $raw = @file_get_contents($path);
    if ($raw === false) return null;
    $v = trim($raw);
    return $v === '' ? null : $v;
}
function intval_or_null(?string $v): ?int {
    return is_numeric($v) ? intval($v) : null;
}
function floatval_1dp_or_null(?string $v): ?float {
    if (!is_numeric($v)) return null;
    $f = floatval($v);
    return ($f < -999 || $f > 999) ? null : round($f, 1);
}

require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/bios.php';

// 只允许 CLI 预定义 fixture；HTTP 永远不能启用测试入口。
if (PHP_SAPI === 'cli' && defined('UGREEN_API_TEST_ONLY') && UGREEN_API_TEST_ONLY === true) return;
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = trim((string)($_SERVER['PATH_INFO'] ?? '/'), '/');
try {
    if (!in_array($method, ['GET', 'POST'], true)) refuse('仅支持 GET/POST', 405);
    if ($path === 'session' || $method === 'POST') ug_authorize($method === 'POST');
    if ($method === 'POST') {
        ug_init();
        $input = fopen('php://input', 'rb');
        if (!$input) throw new RuntimeException('无法读取请求');
        $body = stream_get_contents($input, 32769); fclose($input);
        respond(ug_http_write($path, $body === false ? '' : $body));
    }
    if ($path === 'session') {
        $token = ug_ini(ug_path('emhttp') . '/var.ini', false)['csrf_token'] ?? '';
        if ($token === '') throw new UgHttpError('Unraid CSRF token 尚不可用', 503);
        respond(['ok' => true, 'csrf_token' => $token]);
    }
} catch (UgHttpError $e) { refuse($e->getMessage(), $e->getCode()); }
catch (InvalidArgumentException|JsonException $e) { refuse($e->getMessage(), 400); }
catch (Throwable $e) { refuse($e->getMessage(), 409); }

// Router.
switch (true) {
    case $path === 'status':              respond(route_status()); break;
    case $path === 'mapping':             respond(route_mapping()); break;
    case $path === 'hardware/status':     respond(route_hardware_status()); break;
    case $path === 'bios/status':         respond(route_bios_status()); break;
    case $path === 'settings':            respond(route_settings()); break;
    case $path === 'about/info':          respond(route_about_info()); break;
    case $path === 'hardware/diagnostics':
    case $path === 'diagnostics':         respond(route_diagnostics()); break;
    case $path === 'daemon/status':       respond(ug_control('status')); break;
    case $path === 'logs':                respond(route_logs()); break;
    case $path === 'about/readme':        respond(route_readme()); break;
    case $path === 'update/check':        respond(route_update_check()); break;
    default:
        respond(['ok' => false, 'error' => "Unknown endpoint: /$path"], 404);
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function read_var_ini(): array {
    $f = VAR_EMHTTP . '/var.ini';
    return ug_ini(ug_path('emhttp') . '/var.ini', false);
}
function read_disks_ini(): array {
    $f = VAR_EMHTTP . '/disks.ini';
    return ug_disks();
}
function plugin_version(): ?string {
    return safe_read(PLUGIN_DIR . '/version.txt');
}

function spundown_or_null(array $disk): ?bool {
    $value = isset($disk['spundown']) ? (string)$disk['spundown'] : null;
    return $value === '1' ? true : ($value === '0' ? false : null);
}

function data_disks(array $disks, bool $sata_only = false, string $sys_block = '/sys/class/block'): array {
    $rows = [];
    foreach ($disks as $slot => $disk) {
        if (!is_array($disk) || ($disk['type'] ?? null) !== 'Data') continue;
        $device = trim((string)($disk['device'] ?? ''));
        if ($device === '' || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/D', $device)) continue;
        if ($sata_only) {
            if (!preg_match('/^sd[a-z]+$/D', $device)) continue;
            $device_path = realpath("$sys_block/$device/device");
            $vendor = safe_read("$sys_block/$device/device/vendor");
            if ($vendor !== 'ATA' && ($device_path === false || !preg_match('~/ata[0-9]+/~', $device_path))) continue;
        }
        $disk['device'] = $device;
        $rows[trim((string)$slot, "\"'")] = $disk;
    }
    return $rows;
}

function valid_interface(string $iface): bool {
    return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,14}$/D', $iface) === 1;
}

function default_route_interface(?string $routes): ?string {
    $selected = null;
    $best_metric = PHP_INT_MAX;
    foreach (explode("\n", $routes ?? '') as $line) {
        $fields = preg_split('/\s+/', trim($line));
        if (count($fields) < 8 || $fields[1] !== '00000000' || $fields[7] !== '00000000') continue;
        if (!valid_interface($fields[0]) || !ctype_xdigit($fields[3]) || !ctype_digit($fields[6])) continue;
        $flags = hexdec($fields[3]);
        if (($flags & 1) === 0 || ($flags & 0x200) !== 0) continue;
        $metric = intval($fields[6]);
        if ($selected === null || $metric < $best_metric) {
            $selected = $fields[0];
            $best_metric = $metric;
        }
    }
    return $selected;
}

function network_interface(): ?string {
    $iface = default_route_interface(safe_read('/proc/net/route'));
    if ($iface !== null) return $iface;
    $file = '/boot/config/network.cfg';
    $config = is_readable($file) ? (@parse_ini_file($file, false, INI_SCANNER_RAW) ?: []) : [];
    $names = $config['IFNAME'] ?? null;
    $fallback = is_array($names) ? ($names[0] ?? null) : null;
    return is_string($fallback) && valid_interface($fallback) ? $fallback : null;
}

function network_link(?string $carrier, ?string $operstate): ?string {
    if ($carrier === '1') return 'connected';
    if ($carrier === '0') return 'disconnected';
    if ($operstate === 'up') return 'connected';
    if (in_array($operstate, ['down', 'lowerlayerdown', 'notpresent'], true)) return 'disconnected';
    return null;
}

function network_counters(string $base): array {
    return [
        'rx' => safe_read($base . '/statistics/rx_bytes'),
        'tx' => safe_read($base . '/statistics/tx_bytes'),
    ];
}

function counter_rate(?string $before, ?string $after, float $seconds): ?float {
    if ($seconds <= 0 || $before === null || $after === null || !ctype_digit($before) || !ctype_digit($after)) return null;
    $delta = (float)$after - (float)$before;
    return $delta < 0 ? null : round($delta / $seconds / 1024.0, 2);
}

function sample_network(?string $iface, string $sys_net = '/sys/class/net'): array {
    $result = ['interface' => $iface, 'network' => null, 'network_label' => null, 'rx' => null, 'tx' => null];
    if ($iface === null || !valid_interface($iface)) return $result;
    $base = "$sys_net/$iface";
    $before = network_counters($base);
    $started = hrtime(true);
    usleep(150000);
    $after = network_counters($base);
    $elapsed = (hrtime(true) - $started) / 1e9;
    $result['rx'] = counter_rate($before['rx'], $after['rx'], $elapsed);
    $result['tx'] = counter_rate($before['tx'], $after['tx'], $elapsed);
    $result['network'] = network_link(safe_read($base . '/carrier'), safe_read($base . '/operstate'));
    $label = $result['network'] === 'connected' ? '链路已连接' : ($result['network'] === 'disconnected' ? '链路已断开' : '链路未知');
    $result['network_label'] = "$label ($iface)";
    return $result;
}

// ── Routes ───────────────────────────────────────────────────────────────────

function route_status(): array {
    $var   = read_var_ini();
    $upt   = safe_read('/proc/uptime');
    $uptime_sec = $upt ? intval(explode(' ', $upt)[0]) : null;
    $net = sample_network('eth0');
    $service = ug_service_status();
    $snapshot = ug_json_read('snapshot');
    return [
        'ok'           => true,
        'daemon'       => $service['state'],
        'daemon_state' => $service,
        'mode'         => ug_settings()['mode']['global'],
        'phase2'       => true,
        'capabilities' => ['lighting' => true, 'bios' => false],
        'network'      => $net['network'],
        'network_label'=> $net['network_label'],
        'network_interface' => $net['interface'],
        'network_speed_mbps' => intval_or_null(safe_read(ug_path('net') . '/eth0/speed')),
        // 为兼容前端保留字段名；数值单位是 KiB/s。
        'net_rx_kbps'  => $net['rx'],
        'net_tx_kbps'  => $net['tx'],
        'net_domestic' => null,
        'net_overseas' => null,
        'led_status'   => $snapshot['led_status'] ?? null,
        'unraid_array' => $var['mdState'] ?? null,
        'uptime_seconds' => $uptime_sec,
    ];
}

function mapping_rows(array $disks, string $sys_block = '/sys/class/block'): array {
    $mapping = [];
    foreach (data_disks($disks, true, $sys_block) as $slot => $disk) {
        $spundown = spundown_or_null($disk);
        $mapping[] = [
            'device'     => '/dev/' . $disk['device'],
            'array_slot' => $slot,
            'led'        => '未校准',
            'state'      => $spundown === true ? 'standby' : null,
            'read_kbps'  => null,
            'write_kbps' => null,
        ];
    }
    return $mapping;
}

function route_mapping(): array {
    $cached = ug_json_read('mapping');
    $rows = time() - ($cached['updated_at'] ?? 0) <= 60 ? ($cached['mapping'] ?? []) : [];
    try { if (!$rows) $rows = ug_mapping(ug_disks(), ug_settings()); }
    catch (Throwable $e) { return ['ok' => false, 'mapping' => [], 'error' => $e->getMessage()]; }
    // HTTP 映射保留证据，不披露原始序列号。
    foreach ($rows as &$row) unset($row['serial']);
    unset($row);
    return ['ok' => true, 'mapping' => $rows];
}

function hwmon_find(string $target_name): ?string {
    $dirs = glob(HWMON_BASE . '/hwmon*') ?: [];
    foreach ($dirs as $d) {
        $name = safe_read($d . '/name');
        if ($name === $target_name) return $d;
    }
    return null;
}

function fan_rows(?string $profile, ?string $it_dir): array {
    $fans = [];
    // DX4600 仅确认 fan3/pwm3 已接线；其他机型不猜测风扇数量。
    if ($profile !== 'dx4600' || $it_dir === null) return $fans;
    $rpm = intval_or_null(safe_read($it_dir . '/fan3_input'));
    $pwm = intval_or_null(safe_read($it_dir . '/pwm3'));
    if ($rpm !== null && $rpm < 0) $rpm = null;
    if ($pwm !== null && ($pwm < 0 || $pwm > 255)) $pwm = null;
    if ($rpm === null && $pwm === null) return $fans;
    $fans[] = [
        'name' => '系统风扇 3',
        'channel' => 'fan3/pwm3',
        'rpm' => $rpm,
        'pwm' => $pwm,
        'pwm_percent' => $pwm === null ? null : round($pwm / 255.0 * 100.0, 1),
    ];
    return $fans;
}

function hardware_disk_rows(array $disks): array {
    $rows = [];
    foreach (data_disks($disks) as $slot => $disk) {
        $temp = isset($disk['temp']) && is_numeric($disk['temp']) && (float)$disk['temp'] > 0
            ? (float)$disk['temp'] : null;
        $spundown = spundown_or_null($disk);
        $rows[] = [
            'name' => $slot,
            'array_slot' => $slot,
            'device' => '/dev/' . $disk['device'],
            'temperature_c' => $spundown === true ? null : $temp,
            'spundown' => $spundown,
        ];
    }
    return $rows;
}

function route_hardware_status(): array {
    $product = safe_read(DMI_NAME);
    $kernel  = php_uname('r');
    $var     = read_var_ini();
    $disks   = read_disks_ini();
    $upt     = safe_read('/proc/uptime');
    $uptime_sec = $upt ? intval(explode(' ', $upt)[0]) : null;

    // 机型名称仅用于展示档案，不据此推断已校准灯位。
    $profile = null;
    if ($product) {
        $u = strtoupper($product);
        if (str_contains($u, 'DX4600')) $profile = 'dx4600';
        elseif (str_contains($u, 'DX4700')) $profile = 'dx4700';
        elseif (str_contains($u, 'DXP2800') && str_contains($u, 'GT')) $profile = 'dxp2800_gt';
        elseif (str_contains($u, 'DXP2800')) $profile = 'dxp2800';
        elseif (str_contains($u, 'DXP4800S')) $profile = 'dxp4800s';
        elseif (str_contains($u, 'DXP4800') && str_contains($u, 'PLUS')) $profile = 'dxp4800_plus';
        elseif (str_contains($u, 'DXP4800') && str_contains($u, 'PRO')) $profile = 'dxp4800_pro';
        elseif (str_contains($u, 'DXP4800') && str_contains($u, 'GT')) $profile = 'dxp4800_gt';
        elseif (str_contains($u, 'DXP4800')) $profile = 'dxp4800';
        elseif (str_contains($u, 'DXP6800')) $profile = 'dxp6800';
        elseif (str_contains($u, 'DXP8800')) $profile = 'dxp8800';
        elseif (str_contains($u, 'DXP480T')) $profile = 'dxp480t_plus';
        elseif (str_contains($u, 'IDX6011') && str_contains($u, 'PRO')) $profile = 'idx6011_pro';
        elseif (str_contains($u, 'IDX6011')) $profile = 'idx6011';
    }
    $disk_count = match($profile) {
        'dxp2800', 'dxp2800_gt' => 2,
        'dx4600', 'dx4700', 'dxp4800', 'dxp4800_plus', 'dxp4800_pro', 'dxp4800s', 'dxp4800_gt' => 4,
        'dxp6800', 'idx6011', 'idx6011_pro' => 6,
        'dxp8800' => 8,
        'dxp480t_plus' => 0,
        default => null,
    };

    // CPU temperature (coretemp preferred).
    $cpu_temp = null;
    $dirs = glob(HWMON_BASE . '/hwmon*') ?: [];
    foreach ($dirs as $d) {
        $name = safe_read($d . '/name');
        if (!in_array($name, ['coretemp','k10temp','zenpower'], true)) continue;
        // Look for Package temp first.
        foreach (glob($d . '/temp*_label') ?: [] as $lf) {
            $label = strtolower((string)safe_read($lf));
            if (str_contains($label, 'package') || str_contains($label, 'tctl')) {
                $inp = str_replace('_label', '_input', $lf);
                $raw = safe_read($inp);
                if (is_numeric($raw) && intval($raw) > 0) {
                    $cpu_temp = round(intval($raw) / 1000.0, 1);
                    break 2;
                }
            }
        }
        // Fallback: first valid temp.
        foreach (glob($d . '/temp*_input') ?: [] as $tf) {
            $raw = safe_read($tf);
            if (is_numeric($raw) && intval($raw) > 0) {
                $cpu_temp = round(intval($raw) / 1000.0, 1);
                break 2;
            }
        }
    }

    $it_dir = hwmon_find('it8620') ?? hwmon_find('it8613');
    $fans = fan_rows($profile, $it_dir);
    $disk_rows = hardware_disk_rows($disks);

    return [
        'ok'               => true,
        'product_name'     => $product,
        'profile'          => $profile,
        'profile_name'     => $profile === null ? null : $product,
        'support'          => in_array($profile, ['dx4600','dx4700','dxp2800','dxp4800','dxp4800_plus','dxp6800','dxp8800'], true) ? 'experimental' : null,
        'kernel'           => $kernel,
        'disk_count'       => $disk_count,
        'netdev_count'     => $profile === null ? null : ($profile === 'dxp480t_plus' ? 0 : 1),
        'backend_active'   => ug_service_status()['running'] ? 'sysfs' : null,
        'phase2'           => true,
        'capabilities'     => ['lighting' => true, 'bios' => false],
        'daemon'           => ug_service_status()['state'],
        'daemon_state'     => ug_service_status(),
        'mode'             => ug_settings()['mode']['global'],
        'write_model_supported' => $product === 'DX4600',
        'led_plugin_conflict' => false,
        'led_plugin_modules'  => null,
        'driver_conflict'  => null,
        'cli_version'      => null,
        // Unraid-specific hardware telemetry for the hardware overview card.
        'cpu_temp_c'       => $cpu_temp,
        'fans'             => $fans,
        'disks'            => $disk_rows,
        'unraid_version'   => ($var['version'] ?? null),
        'array_state'      => ($var['mdState'] ?? null),
        'uptime_seconds'   => $uptime_sec,
    ];
}

function route_bios_status(): array {
    return ug_bios_status();
}

function route_settings(): array {
    $cfg = ug_settings();
    return ['ok' => true, 'raw' => ug_ini_encode($cfg), 'settings' => $cfg,
        'autostart' => ['enabled' => $cfg['autostart']['enabled'] === 'true', 'delay_seconds' => (int)$cfg['autostart']['delay_seconds']]];
}

function route_about_info(): array {
    return [
        'ok'           => true,
        'display_name' => 'UGreen Control (Unraid port)',
        'version'      => plugin_version(),
        'maintainer'   => 'BearHero520 (upstream) / Unraid port',
        'license'      => 'AGPL-3.0',
        'repository_url' => 'https://github.com/BearHero520/UGreen-Control',
        'readme_url'   => 'https://github.com/BearHero520/UGreen-Control/blob/main/README.md',
        'qq_group'     => null,
    ];
}

function diagnostic_snapshot(array $hardware, array $mapping): array {
    // 显式白名单；不包含 disks.ini 原始内容、序列号、MAC 或网络配置。
    $snapshot = array_intersect_key($hardware, array_flip([
        'product_name', 'profile', 'profile_name', 'support', 'kernel', 'disk_count',
        'netdev_count', 'backend_active', 'led_plugin_conflict', 'led_plugin_modules',
        'driver_conflict', 'cli_version', 'cpu_temp_c', 'unraid_version',
        'array_state', 'uptime_seconds',
    ]));
    foreach (['fans' => ['name', 'channel', 'rpm', 'pwm', 'pwm_percent'],
              'disks' => ['name', 'array_slot', 'device', 'temperature_c', 'spundown']] as $key => $fields) {
        $snapshot[$key] = array_map(static function (array $row) use ($fields): array {
            return array_intersect_key($row, array_flip($fields));
        }, $hardware[$key] ?? []);
    }
    $snapshot['mapping'] = array_map(static function (array $row): array {
        return array_intersect_key($row, array_flip(['device', 'array_slot', 'led', 'state', 'read_kbps', 'write_kbps']));
    }, $mapping);
    return $snapshot;
}

function route_diagnostics(): array {
    $snapshot = diagnostic_snapshot(route_hardware_status(), route_mapping()['mapping']);
    $hwmon = [];
    foreach (glob(HWMON_BASE . '/hwmon*') ?: [] as $dir) {
        $hwmon[] = ['node' => basename($dir), 'name' => safe_read($dir . '/name')];
    }
    $snapshot['hwmon'] = $hwmon;
    $snapshot['plugin_version'] = plugin_version();
    $snapshot['read_only'] = true;
    $snapshot['phase2'] = true;
    $snapshot['daemon_state'] = ug_service_status();
    $snapshot['mode'] = ug_settings()['mode']['global'];
    $ts = time();
    $content = "# UGreen Control Unraid 第二阶段诊断\n"
        . '# 生成时间: ' . date('c', $ts) . "\n"
        . "# 本次诊断仅读取缓存与遥测；灯位证据见 mapping。\n"
        . json_encode($snapshot, JSON_FLAGS | JSON_PRETTY_PRINT);
    return [
        'ok' => true,
        'content' => $content,
        'filename' => 'ugreen-control-diagnostics-' . gmdate('Ymd-His', $ts) . '.txt',
        'hardware_snapshot' => $snapshot,
        'fetched_at' => $ts,
        'size_bytes' => strlen($content),
    ];
}

function read_log_tail(string $path, int $lines): array {
    $result = ['content' => '', 'clipped' => false, 'updated_at' => null];
    if (!is_readable($path)) return $result;
    $handle = @fopen($path, 'rb');
    if ($handle === false) return $result;
    try {
        $stat = fstat($handle);
        if ($stat === false) return $result;
        $offset = max(0, $stat['size'] - LOG_MAX_BYTES);
        if (fseek($handle, $offset, SEEK_SET) !== 0) return $result;
        $raw = stream_get_contents($handle, LOG_MAX_BYTES);
        if ($raw === false) return $result;
        if ($offset > 0) {
            $newline = strpos($raw, "\n");
            $raw = $newline === false ? '' : substr($raw, $newline + 1);
        }
        $rows = $raw === '' ? [] : explode("\n", rtrim($raw, "\r\n"));
        $limit = max(1, min(1000, $lines));
        $result['content'] = implode("\n", array_slice($rows, -$limit));
        $result['clipped'] = $offset > 0 || count($rows) > $limit;
        $result['updated_at'] = $stat['mtime'];
        return $result;
    } finally {
        fclose($handle);
    }
}

function route_logs(): array {
    $requested = $_GET['lines'] ?? 500;
    $lines_req = max(50, min(1000, is_scalar($requested) ? intval($requested) : 500));
    $log = read_log_tail('/var/log/ugreen-control.log', $lines_req);
    return [
        'ok' => true,
        'content' => $log['content'],
        'filename' => 'ugreen-control.log',
        'write_level' => null,
        'requested_lines' => $lines_req,
        'size_bytes' => strlen($log['content']),
        'updated_at' => $log['updated_at'],
        'clipped' => $log['clipped'],
    ];
}

function route_readme(): array {
    return [
        'ok' => true,
        'content' => "# UGreen Control：Unraid 第二阶段 LED 服务\n\n"
            . "仅精确 DX4600 支持 LED 写入，通过 led-ugreen 内核驱动的 /sys/class/leds 节点稳定控制（规避用户态直写 MCU 的 ACK 失败）。\n\n"
            . "默认沿用原始 INI 灯色、亮度和活动闪动开关；磁盘活动由 /proc/diskstats 采样，休眠来自 disks.ini，不查询磁盘电源。\n\n"
            . "SATA 灯位为端口推定，未目视校准；可用当前 SATA 序列号显式绑定。网口固定 eth0，显示物理链路、速率与 KiB/s 流量，默认已连接为蓝色。\n\n"
            . "自动启动在阵列 STARTED 后延迟 300 秒（可设 60..3600），此时才加载内核驱动。手动启动立即排入 at。停止会取消排队任务并等待守护进程退出，保留最后灯态；需要关闭全部请选 off。\n\n"
            . "BIOS、风扇控制未启用。POST 独立校验 Unraid 会话与 X-CSRF-Token；页面通过 GET /session 获取 token。保存灯光设置不重启服务，下一轮刷新生效。", 
        'source_url' => null,
        'source' => 'local',
        'reachable' => null,
        'cached' => false,
        'fetched_at' => time(),
    ];
}

function route_update_check(): array {
    return [
        'ok' => true,
        'supported' => false,
        'message' => '第一阶段更新功能未接入；未发起联网请求。',
        'reachable' => null,
        'current_version' => plugin_version(),
        'latest_version' => null,
        'release_url' => null,
        'download_url' => null,
        'checked_at' => null,
    ];
}
