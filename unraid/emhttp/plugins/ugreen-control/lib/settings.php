<?php
declare(strict_types=1);

// 测试路径只能由 PHP CLI 预定义，HTTP 不接受环境变量、请求参数或常量注入。
function ug_path(string $key): string {
    $paths = [
        'runtime' => '/run/ugreen-control', 'boot' => '/boot/config/plugins/ugreen-control',
        'log' => '/var/log/ugreen-control.log', 'emhttp' => '/var/local/emhttp',
        'proc' => '/proc', 'block' => '/sys/class/block', 'net' => '/sys/class/net',
        'dmi' => '/sys/class/dmi/id/product_name',
        'leds' => '/sys/class/leds', 'i2cdev' => '/sys/class/i2c-dev', 'bus_i2c' => '/sys/bus/i2c/devices',
        'cli' => dirname(__DIR__) . '/server/bin/ugreen_leds_cli',
    ];
    if (PHP_SAPI === 'cli' && defined('UGREEN_PHASE2_FIXTURE')) {
        $paths = array_replace($paths, UGREEN_PHASE2_FIXTURE);
    }
    return $paths[$key];
}
function ug_read(string $file): ?string {
    $text = @file_get_contents($file);
    return $text === false ? null : trim($text);
}
function ug_ini(string $file, bool $sections = true): array {
    $data = is_readable($file) ? @parse_ini_file($file, $sections, INI_SCANNER_RAW) : [];
    $normalize = static function (array $items) use (&$normalize): array {
        $out = [];
        foreach ($items as $key => $value) {
            $out[trim((string)$key, "\"' \t")] = is_array($value) ? $normalize($value) : trim((string)$value, "\"' \t\r\n");
        }
        return $out;
    };
    return $normalize(is_array($data) ? $data : []);
}
function ug_init(): void {
    umask(0077);
    if (!(PHP_SAPI === 'cli' && defined('UGREEN_PHASE2_FIXTURE')) &&
        (!function_exists('posix_geteuid') || posix_geteuid() !== 0)) {
        throw new RuntimeException('服务必须以 root 运行');
    }
    $dir = ug_path('runtime');
    if (is_link($dir)) throw new RuntimeException('运行目录不得为符号链接');
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('不能创建运行目录');
    if (!@chmod($dir, 0700)) throw new RuntimeException('不能保护运行目录');
}
function ug_lock(string $name, callable $fn) {
    ug_init();
    $file = fopen(ug_path('runtime') . '/' . $name . '.lock', 'c');
    if (!$file || !flock($file, LOCK_EX)) throw new RuntimeException('不能取得服务锁');
    try { return $fn(); } finally { flock($file, LOCK_UN); fclose($file); }
}
function ug_atomic(string $file, string $contents): void {
    $temp = $file . '.tmp.' . bin2hex(random_bytes(8));
    try {
        if (file_put_contents($temp, $contents) !== strlen($contents) || !chmod($temp, 0600) || !rename($temp, $file)) {
            throw new RuntimeException('原子保存失败');
        }
    } finally { if (is_file($temp)) @unlink($temp); }
}
function ug_json_read(string $name): array {
    $v = json_decode(ug_read(ug_path('runtime') . '/' . $name . '.json') ?? '', true);
    return is_array($v) ? $v : [];
}
function ug_json_write(string $name, array $value): void {
    ug_atomic(ug_path('runtime') . '/' . $name . '.json', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
}
function ug_defaults(): array {
    return [
        'mode' => ['global' => 'smart'],
        'daemon' => ['enabled' => 'true', 'check_interval' => '5', 'refresh_interval' => '5', 'io_idle_seconds' => '8',
            'network_probe_interval' => '30', 'disk_power_probe_interval' => '60', 'hotplug_check_interval' => '30', 'led_settle_ms' => '2000'],
        'logging' => ['level' => 'info', 'max_size_kb' => '256', 'retained_files' => '0'],
        'activity' => ['disk_blink' => 'false', 'network_blink' => 'false', 'disk_threshold_kbps' => '128', 'network_threshold_kbps' => '32'],
        'disk_colors' => ['active' => '0 255 0', 'idle' => '255 255 0', 'standby' => '0 100 255', 'deep_sleep' => '40 0 80', 'offline' => 'off'],
        'disk_brightness' => ['active' => '128', 'idle' => '64', 'standby' => '40', 'deep_sleep' => '24', 'offline' => '0'],
        'netdev_colors' => ['disconnected' => '255 0 0', 'connected' => '0 80 255', 'vpn' => '160 0 255'],
        'netdev_brightness' => ['disconnected' => '64', 'connected' => '48', 'vpn' => '64'],
        'power' => ['smart_color' => '100 100 100', 'all_on_color' => '180 180 180', 'brightness' => '40'],
        'behavior' => ['remap_on_hotplug' => 'true', 'manage_power' => 'true', 'manage_netdev' => 'true', 'disk_map_mode' => 'auto'],
        'disk_map' => [], 'autostart' => ['enabled' => 'true', 'delay_seconds' => '300'],
    ];
}
function ug_value(string $section, string $key, mixed $value): string {
    if (!is_string($value) && !is_int($value) && !is_bool($value)) throw new InvalidArgumentException('配置值必须为标量');
    $v = is_bool($value) ? ($value ? 'true' : 'false') : trim((string)$value);
    $defaults = ug_defaults();
    if ($section === 'disk_map') {
        // disk1=序列号；序列号与 disks.ini 精确匹配，不接受设备名或任意命令。
        if (!preg_match('/^disk[1-4]$/D', $key) || !preg_match('/^[A-Za-z0-9_.:+ -]{1,128}$/D', $v)) throw new InvalidArgumentException('无效的序列号灯位映射');
        return $v;
    }
    if (!isset($defaults[$section]) || !array_key_exists($key, $defaults[$section])) throw new InvalidArgumentException("不允许的配置: $section.$key");
    $bools = ['daemon.enabled', 'activity.disk_blink', 'activity.network_blink', 'behavior.remap_on_hotplug',
        'behavior.manage_power', 'behavior.manage_netdev', 'autostart.enabled'];
    $name = "$section.$key";
    if (in_array($name, $bools, true)) {
        if (!in_array($v, ['true', 'false'], true)) throw new InvalidArgumentException("$name 必须为 true/false");
    } elseif (str_ends_with($section, '_colors') || ($section === 'power' && str_ends_with($key, '_color'))) {
        if ($v !== 'off') {
            if (!preg_match('/^(\d{1,3})\s+(\d{1,3})\s+(\d{1,3})$/D', $v, $m) || max((int)$m[1], (int)$m[2], (int)$m[3]) > 255) throw new InvalidArgumentException('RGB 必须在 0..255 或为 off');
            $v = (int)$m[1] . ' ' . (int)$m[2] . ' ' . (int)$m[3];
        }
    } elseif ($name === 'mode.global') {
        if (!in_array($v, ['off', 'on', 'smart'], true)) throw new InvalidArgumentException('模式无效');
    } elseif ($name === 'behavior.disk_map_mode') {
        if (!in_array($v, ['auto', 'disk'], true)) throw new InvalidArgumentException('仅允许 auto 或已校准序列号 disk 映射');
    } elseif ($name === 'logging.level') {
        if (!in_array($v, ['info', 'debug'], true)) throw new InvalidArgumentException('日志级别无效');
    } else {
        [$min, $max] = match (true) {
            str_ends_with($section, '_brightness'), $name === 'power.brightness' => [0, 255],
            $section === 'activity' => [1, 1048576],
            $name === 'autostart.delay_seconds' => [60, 3600],
            in_array($name, ['daemon.check_interval', 'daemon.refresh_interval'], true) => [1, 60],
            $name === 'daemon.led_settle_ms' => [0, 5000],
            $name === 'logging.max_size_kb' => [16, 256],
            $name === 'logging.retained_files' => [0, 0],
            default => [1, 3600],
        };
        if (!ctype_digit($v) || strlen($v) > 10 || (int)$v < $min || (int)$v > $max) throw new InvalidArgumentException("$name 必须在 $min..$max");
        $v = (string)(int)$v;
    }
    return $v;
}
function ug_patch(array $base, array $patch): array {
    foreach ($patch as $section => $items) {
        if (!is_array($items)) throw new InvalidArgumentException('配置需要分节');
        if ($section === 'disk_map') $base['disk_map'] = [];
        foreach ($items as $key => $value) $base[$section][$key] = ug_value((string)$section, (string)$key, $value);
        if (!array_key_exists($section, ug_defaults())) throw new InvalidArgumentException('未知配置节');
    }
    if (isset($patch['daemon']['check_interval']) && !isset($patch['daemon']['refresh_interval'])) $base['daemon']['refresh_interval'] = $base['daemon']['check_interval'];
    if (isset($patch['daemon']['refresh_interval'])) $base['daemon']['check_interval'] = $base['daemon']['refresh_interval'];
    $serials = array_values($base['disk_map']);
    if (count(array_unique($serials)) !== count($serials)) throw new InvalidArgumentException('一个序列号不能绑定多个灯位');
    return $base;
}
function ug_settings(): array {
    $base = ug_defaults();
    foreach (ug_ini(ug_path('boot') . '/settings.conf') as $section => $values) {
        if (!is_array($values)) continue;
        foreach ($values as $key => $value) {
            try { $base[$section][$key] = ug_value((string)$section, (string)$key, $value); }
            catch (InvalidArgumentException $e) { /* 历史 BIOS/硬件字段不进入 LED 服务。 */ }
        }
    }
    $raw = ug_ini(ug_path('boot') . '/settings.conf');
    if (!isset($raw['daemon']['refresh_interval'])) $base['daemon']['refresh_interval'] = $base['daemon']['check_interval'];
    return $base;
}
function ug_ini_encode(array $cfg): string {
    $text = '';
    foreach ($cfg as $section => $values) {
        $text .= "[$section]\n";
        foreach ($values as $key => $value) $text .= $key . '="' . $value . "\"\n";
        $text .= "\n";
    }
    return $text;
}
function ug_settings_save(array $patch): array {
    return ug_lock('settings', static function () use ($patch): array {
        $cfg = ug_patch(ug_settings(), $patch);
        $dir = ug_path('boot');
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('持久配置目录不可用');
        ug_atomic($dir . '/settings.conf', ug_ini_encode($cfg));
        return $cfg;
    });
}
function ug_parse_settings(string $body): array {
    if (strlen($body) > 32768) throw new InvalidArgumentException('配置过大');
    $body = trim($body);
    if ($body === '') throw new InvalidArgumentException('配置不能为空');
    if ($body[0] === '{') {
        $patch = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($patch)) throw new InvalidArgumentException('配置必须为对象');
        return $patch;
    }
    $patch = []; $section = null;
    foreach (preg_split('/\r?\n/', $body) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') continue;
        if (preg_match('/^\[([a-z_]+)\]$/D', $line, $m)) { $section = $m[1]; continue; }
        if (!preg_match('/^([a-z0-9_.]+)\s*=\s*(.*?)$/D', $line, $m)) throw new InvalidArgumentException('配置格式无效');
        $parts = explode('.', $m[1], 2);
        [$s, $k] = count($parts) === 2 ? $parts : [$section, $parts[0]];
        if ($s === null) throw new InvalidArgumentException('配置缺少节名');
        if (isset($patch[$s][$k])) throw new InvalidArgumentException('配置键重复');
        $patch[$s][$k] = trim($m[2], "\"'");
    }
    return $patch;
}
