<?php
declare(strict_types=1);
/**
 * UGreen Control — BIOS / 风扇 / 来电启动 / WOL / RTC 只读与受保护写入（第三阶段）。
 *
 * 约束：
 *  - 仅 require service.php（连带 settings.php 提供 ug_path、ug_read、ug_json_read、ug_json_write、ug_lock、ug_atomic、ug_exec）。
 *  - 只读 sysfs 遥测，绝不手写 /sys pwm 或 devport，绝不停止风扇、卸载 it87。
 *  - DX4600 上 it87/it8620 已接管风扇与来电启动寄存器，原厂 ugreenctl 仅接受 it8613，
 *    因此 fan/startup 必须真实返回 available=false + reason，不宣称完整完成。
 *  - WOL / RTC 走 ugreenctl 内建 wake 命令（wake_cli.c），真实可用。
 *  - 所有写操作统一使用全局锁 ug_lock('bios')；CSRF 由 master HTTP 层在写前强制校验，
 *    本文件不自行校验 CSRF（与 http.php 现有约定一致）。
 *  - fixture 仅允许 PHP CLI 通过 UG_BIOS_FIXTURE 常量注入 ugreenctl 路径与 payload hash，
 *    HTTP 请求永远无法注入任何 shell / 环境 / 路径。
 *
 * 原始 CLI 参数（确认来源 server/vendor/UGREEN-NAS-Hardware/core/wake_cli.c）：
 *   读取 WOL：  network wol get --interface NAME          （文本输出 on|off，不用 --json）
 *   写入 WOL：  --force --apply network wol set on|off --interface NAME
 *   读取 RTC：  power rtc-wake get                        （文本输出 epoch，0 = 未设置）
 *   设置 RTC：  --force --apply power rtc-wake set <epoch>（仅接受纯数字；CLI 要求 > now+60 且 RTC since_epoch 与系统 UTC 差 ≤5s）
 *   清除 RTC：  --force --apply power rtc-wake clear
 *   读取 AC：   power startup get                         （on|off|restore）
 *   写入 AC：   --force --apply power startup set on|off|restore
 */

require_once __DIR__ . '/service.php';

const UG_BIOS_MODEL = 'DX4600';
const UG_BIOS_UGREENCTL_SHA256 = '5388eaeef1d399153cf73532b75d62ef7cc422832aaf1256f76bd2b371e4bef9';
const UG_BIOS_DX4600_SO_SHA256 = '12648ab2af69a99f782cc1cb5d65dd7299a5c19b72e6eb65e494cf261a1bc483';
const UG_BIOS_CACHE_SECONDS = 10;
const UG_BIOS_RTC_MAX_AHEAD = 31622400;   // 366 天
const UG_BIOS_RTC_MIN_AHEAD = 120;
const UG_BIOS_WOL_INTERFACES = ['eth0', 'eth1'];
const UG_BIOS_WRITE_TIMEOUT = 10.0;

// ── fixture / 路径 ───────────────────────────────────────────────────────────

function ug_bios_root(): string {
    return dirname(__DIR__);
}

function ug_bios_ugreenctl(): string {
    if (PHP_SAPI === 'cli' && defined('UG_BIOS_FIXTURE') && isset(UG_BIOS_FIXTURE['ugreenctl'])) {
        return UG_BIOS_FIXTURE['ugreenctl'];
    }
    return ug_bios_root() . '/server/bin/ugreenctl';
}

function ug_bios_model_dir(): string {
    if (PHP_SAPI === 'cli' && defined('UG_BIOS_FIXTURE') && isset(UG_BIOS_FIXTURE['models'])) {
        return UG_BIOS_FIXTURE['models'];
    }
    return ug_bios_root() . '/server/lib/ugreenctl/models';
}

function ug_bios_dx4600_so(): string {
    return ug_bios_model_dir() . '/dx4600.so';
}

function ug_bios_hwmon(): string {
    if (PHP_SAPI === 'cli' && defined('UG_BIOS_FIXTURE') && isset(UG_BIOS_FIXTURE['hwmon'])) {
        return UG_BIOS_FIXTURE['hwmon'];
    }
    return '/sys/class/hwmon';
}

function ug_bios_dmi(): ?string {
    return ug_read(ug_path('dmi'));
}

function ug_bios_modules(): ?string {
    return ug_read(ug_path('proc') . '/modules');
}

function ug_bios_settings_file(): string {
    return ug_path('boot') . '/bios-settings.json';
}

// ── payload 期望值（fixture 可覆盖，HTTP 不可注入） ───────────────────────────

function ug_bios_payload_expected(string $which): string {
    if (PHP_SAPI === 'cli' && defined('UG_BIOS_FIXTURE') && isset(UG_BIOS_FIXTURE['hashes'][$which])) {
        return (string)UG_BIOS_FIXTURE['hashes'][$which];
    }
    return $which === 'ugreenctl' ? UG_BIOS_UGREENCTL_SHA256 : UG_BIOS_DX4600_SO_SHA256;
}

function ug_bios_payload_ok(string $which): array {
    $path = $which === 'ugreenctl' ? ug_bios_ugreenctl() : ug_bios_dx4600_so();
    if (!is_file($path) || !is_readable($path)) {
        return ['which' => $which, 'path' => $path, 'ok' => false, 'error' => 'payload 文件缺失或不可读'];
    }
    $sha = @hash_file('sha256', $path);
    if (!is_string($sha)) {
        return ['which' => $which, 'path' => $path, 'ok' => false, 'error' => '无法计算 payload SHA256'];
    }
    if (!hash_equals(ug_bios_payload_expected($which), $sha)) {
        return ['which' => $which, 'path' => $path, 'ok' => false, 'sha256' => $sha, 'error' => 'payload SHA256 不匹配'];
    }
    return ['which' => $which, 'path' => $path, 'ok' => true, 'sha256' => $sha];
}

function ug_bios_payload_status(): array {
    $ctl = ug_bios_payload_ok('ugreenctl');
    $so = ug_bios_payload_ok('dx4600_so');
    return ['ugreenctl' => $ctl, 'dx4600_so' => $so, 'ok' => $ctl['ok'] && $so['ok']];
}

/**
 * 写前强制精确身份 + payload hash 校验；失败抛异常（master 转 409）。
 */
function ug_bios_identity(): array {
    $dmi = ug_bios_dmi();
    if ($dmi !== UG_BIOS_MODEL) {
        throw new RuntimeException('BIOS 写入仅支持精确 DX4600，检测到: ' . ($dmi === null ? '未知' : $dmi));
    }
    $status = ug_bios_payload_status();
    foreach (['ugreenctl', 'dx4600_so'] as $which) {
        if (!$status[$which]['ok']) {
            throw new RuntimeException('payload 校验失败: ' . $which . '（' . ($status[$which]['error'] ?? '未知错误') . '）');
        }
    }
    return ['model' => UG_BIOS_MODEL, 'ugreenctl' => $status['ugreenctl']['path'], 'dx4600_so' => $status['dx4600_so']['path']];
}

// ── CLI 执行 ─────────────────────────────────────────────────────────────────

function ug_bios_exec(array $args, float $seconds = 5.0): array {
    return ug_exec(array_merge([ug_bios_ugreenctl()], $args), '', $seconds);
}

function ug_bios_cli_error(string $output): string {
    foreach (explode("\n", $output) as $line) {
        $line = trim($line);
        if (str_starts_with($line, 'error:')) {
            $msg = trim(substr($line, 6));
            if ($msg !== '') return $msg;
        }
    }
    $flat = trim((string)preg_replace('/\s+/', ' ', $output));
    return $flat !== '' ? $flat : '命令失败';
}

// ── 解析（纯函数，可测） ─────────────────────────────────────────────────────

function ug_bios_last_line(string $output): ?string {
    $lines = array_values(array_filter(array_map('trim', explode("\n", $output)), static fn(string $l): bool => $l !== ''));
    return $lines === [] ? null : end($lines);
}

function ug_bios_parse_policy(string $output): ?string {
    $last = ug_bios_last_line($output);
    return in_array($last, ['on', 'off'], true) ? $last : null;
}

function ug_bios_parse_startup(string $output): ?string {
    $last = ug_bios_last_line($output);
    return in_array($last, ['on', 'off', 'restore'], true) ? $last : null;
}

function ug_bios_parse_epoch(string $output): ?int {
    $last = ug_bios_last_line($output);
    if ($last === null || !ctype_digit($last)) return null;
    return (int)$last;
}

// ── 检测（纯函数，可测） ─────────────────────────────────────────────────────

function ug_bios_hwmon_find(string $name, ?string $base = null): ?string {
    $base ??= ug_bios_hwmon();
    foreach (glob($base . '/hwmon*') ?: [] as $d) {
        if (ug_read($d . '/name') === $name) return $d;
    }
    return null;
}

function ug_bios_fan_controller(): ?string {
    return ug_bios_hwmon_find('it8620') ?? ug_bios_hwmon_find('it8613');
}

/**
 * it87/it8620 内核驱动是否已接管风扇（DX4600 上恒真）。
 */
function ug_bios_fan_owner_active(?string $modules, ?string $controller): bool {
    $it87 = $modules !== null && preg_match('/^(it87|it8620)\s/m', $modules) === 1;
    return $it87 || $controller !== null;
}

function ug_bios_int(?string $v): ?int {
    if ($v === null || !is_numeric($v)) return null;
    return (int)$v;
}

// ── 读取 ─────────────────────────────────────────────────────────────────────

function ug_bios_fan_telemetry(): array {
    $dir = ug_bios_hwmon_find('it8620') ?? ug_bios_hwmon_find('it8613');
    $rpm = $pwm = null;
    if ($dir !== null) {
        $rpm = ug_bios_int(ug_read($dir . '/fan3_input'));
        $pwm = ug_bios_int(ug_read($dir . '/pwm3'));
    }
    return [$rpm, $pwm];
}

function ug_bios_read_fan(): array {
    [$rpm, $pwm] = ug_bios_fan_telemetry();
    $controller = ug_bios_fan_controller();
    $owner = ug_bios_fan_owner_active(ug_bios_modules(), $controller) ? 'FanCtrlPlus' : null;
    return [
        'available' => false,
        'reason' => $owner !== null
            ? '风扇由 it87/it8620（FanCtrlPlus）内核驱动接管，原厂 ugreenctl 仅支持 it8613；为避免冲突不接管、不停止风扇'
            : '风扇控制不可用',
        'rpm' => $rpm,
        'pwm' => $pwm,
        'owner' => $owner,
        'takeover_supported' => false,
    ];
}

function ug_bios_read_startup(): array {
    $controller = ug_bios_fan_controller();
    if (ug_bios_fan_owner_active(ug_bios_modules(), $controller)) {
        return [
            'available' => false,
            'value' => null,
            'reason' => '来电启动寄存器由 it87/it8620（FanCtrlPlus）内核驱动占用，原厂 ugreenctl 仅支持 it8613；为避免冲突不提供读写',
        ];
    }
    // 受保护 CLI 能力保留：未来 it87 未占用时读取上游 startup 策略。
    try {
        $r = ug_bios_exec(['--plugin-dir', ug_bios_model_dir(), 'power', 'startup', 'get'], 5);
        if ($r['code'] !== 0) return ['available' => false, 'value' => null, 'reason' => ug_bios_cli_error($r['output'])];
        $policy = ug_bios_parse_startup($r['output']);
        if ($policy === null) return ['available' => false, 'value' => null, 'reason' => '来电启动策略返回了无法解析的值'];
        return ['available' => true, 'value' => $policy, 'reason' => null];
    } catch (Throwable $e) {
        return ['available' => false, 'value' => null, 'reason' => $e->getMessage()];
    }
}

function ug_bios_read_wol_interface(string $name): array {
    if (!in_array($name, UG_BIOS_WOL_INTERFACES, true)) {
        return ['name' => $name, 'available' => false, 'value' => null, 'error' => '接口不在白名单'];
    }
    if (!is_file(ug_bios_ugreenctl())) {
        return ['name' => $name, 'available' => false, 'value' => null, 'error' => '内置 ugreenctl 缺失'];
    }
    try {
        $r = ug_bios_exec(['network', 'wol', 'get', '--interface', $name], 5);
        if ($r['code'] !== 0) return ['name' => $name, 'available' => false, 'value' => null, 'error' => ug_bios_cli_error($r['output'])];
        $policy = ug_bios_parse_policy($r['output']);
        if ($policy === null) return ['name' => $name, 'available' => false, 'value' => null, 'error' => 'WOL 状态返回了无法解析的值'];
        return ['name' => $name, 'available' => true, 'value' => $policy, 'error' => null];
    } catch (Throwable $e) {
        return ['name' => $name, 'available' => false, 'value' => null, 'error' => $e->getMessage()];
    }
}

function ug_bios_read_wol(): array {
    $interfaces = [];
    $any = false;
    foreach (UG_BIOS_WOL_INTERFACES as $name) {
        $row = ug_bios_read_wol_interface($name);
        $interfaces[] = $row;
        if ($row['available']) $any = true;
    }
    return ['available' => $any, 'interfaces' => $interfaces];
}

function ug_bios_read_rtc(): array {
    if (!is_file(ug_bios_ugreenctl())) {
        return ['available' => false, 'epoch' => null, 'error' => '内置 ugreenctl 缺失'];
    }
    try {
        $r = ug_bios_exec(['power', 'rtc-wake', 'get'], 5);
        if ($r['code'] !== 0) return ['available' => false, 'epoch' => null, 'error' => ug_bios_cli_error($r['output'])];
        $epoch = ug_bios_parse_epoch($r['output']);
        if ($epoch === null) return ['available' => false, 'epoch' => null, 'error' => 'RTC 时间返回了无法解析的值'];
        return ['available' => true, 'epoch' => $epoch, 'error' => null];
    } catch (Throwable $e) {
        return ['available' => false, 'epoch' => null, 'error' => $e->getMessage()];
    }
}

// ── status（10 秒缓存，无网络调用，不保存 token） ─────────────────────────────

function ug_bios_status_fresh(): array {
    $dmi = ug_bios_dmi();
    $supported = $dmi === UG_BIOS_MODEL;
    $payload = ug_bios_payload_status();
    $limitations = [];
    if (!$supported) {
        $limitations[] = '仅精确 DX4600 支持 BIOS 能力';
    }
    if (!$payload['ok']) {
        $limitations[] = '内置 ugreenctl 或 dx4600.so 缺失/校验失败，WOL/RTC 不可用';
    }
    // 每项能力独立采集，任一失败不影响其他能力与整个接口。
    $fan = ug_bios_read_fan();
    $startup = ug_bios_read_startup();
    $wol = $payload['ugreenctl']['ok'] ? ug_bios_read_wol() : ['available' => false, 'interfaces' => [], 'error' => 'payload 不可用'];
    $rtc = $payload['ugreenctl']['ok'] ? ug_bios_read_rtc() : ['available' => false, 'epoch' => null, 'error' => 'payload 不可用'];
    $limitations[] = '风扇与来电启动不可写：原厂 CLI 仅支持 it8613，当前由 it87/it8620 接管';
    $limitations[] = 'RTC 定时唤醒不自动恢复；已设置的未来唤醒不会在开机时被偷偷清除';
    return [
        'ok' => true,
        'supported' => $supported,
        'model' => $dmi,
        'fan' => $fan,
        'startup' => $startup,
        'wol' => $wol,
        'rtc' => $rtc,
        'limitations' => $limitations,
    ];
}

function ug_bios_status(): array {
    $cached = ug_json_read('bios-status');
    if (time() - (int)($cached['updated_at'] ?? 0) < UG_BIOS_CACHE_SECONDS && ($cached['model'] ?? null) === ug_bios_dmi()) {
        unset($cached['updated_at']);
        return $cached;
    }
    $report = ug_bios_status_fresh();
    $report['updated_at'] = time();
    ug_json_write('bios-status', $report);
    unset($report['updated_at']);
    return $report;
}

// ── 持久化（仅保存成功写入的明确选择） ────────────────────────────────────────

function ug_bios_settings_read(): array {
    $v = json_decode(ug_read(ug_bios_settings_file()) ?? '', true);
    return is_array($v) ? $v : [];
}

function ug_bios_settings_save(array $settings): void {
    $dir = ug_path('boot');
    if (!is_dir($dir)) throw new RuntimeException('持久配置目录不可用');
    ug_atomic(ug_bios_settings_file(), json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}

function ug_bios_persist_wol(string $interface, string $policy): void {
    $s = ug_bios_settings_read();
    $s['model'] = UG_BIOS_MODEL;
    $s['wol'] = array_replace($s['wol'] ?? [], [$interface => $policy]);
    $s['updated_at'] = time();
    ug_bios_settings_save($s);
}

function ug_bios_persist_rtc(?int $epoch): void {
    $s = ug_bios_settings_read();
    $s['model'] = UG_BIOS_MODEL;
    $s['rtc'] = ['epoch' => $epoch, 'set_at' => time()];
    $s['updated_at'] = time();
    ug_bios_settings_save($s);
}

// ── 写入 ─────────────────────────────────────────────────────────────────────

function ug_bios_only(array $params, array $keys): void {
    if (array_diff(array_keys($params), $keys)) throw new InvalidArgumentException('包含不允许的字段');
}

function ug_bios_params(string $body): array {
    if (strlen($body) > 32768) throw new InvalidArgumentException('请求过大');
    $body = trim($body);
    if ($body === '') throw new InvalidArgumentException('请求体不能为空');
    $params = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($params)) throw new InvalidArgumentException('请求必须为 JSON 对象');
    return $params;
}

function ug_bios_write_wol(array $params): array {
    ug_bios_only($params, ['interface', 'policy', 'confirm']);
    $interface = $params['interface'] ?? '';
    $policy = $params['policy'] ?? '';
    if (!in_array($interface, UG_BIOS_WOL_INTERFACES, true)) throw new InvalidArgumentException('interface 必须为 eth0 或 eth1');
    if (!in_array($policy, ['on', 'off'], true)) throw new InvalidArgumentException('policy 必须为 on 或 off');
    if (($params['confirm'] ?? '') !== 'apply') throw new InvalidArgumentException('需要 confirm: apply');
    ug_bios_identity();

    $before = ug_bios_read_wol_interface($interface);
    $r = ug_bios_exec(['--force', '--apply', 'network', 'wol', 'set', $policy, '--interface', $interface], UG_BIOS_WRITE_TIMEOUT);
    if ($r['code'] !== 0) {
        $err = ug_bios_cli_error($r['output']);
        ug_log('bios.wol_write_failed', 'WOL 写入失败 ' . $interface . '=' . $policy . '：' . $err);
        throw new RuntimeException('WOL 写入失败：' . $err);
    }
    $after = ug_bios_read_wol_interface($interface);
    if (!$after['available'] || $after['value'] !== $policy) {
        $current = $after['available'] ? ($after['value'] ?? 'unknown') : 'unknown';
        ug_log('bios.wol_readback_mismatch', 'WOL 读回不匹配 ' . $interface . ' 期望=' . $policy . ' 实际=' . $current . '；未持久化');
        throw new RuntimeException('WOL 写入读回不匹配，当前状态未确认（' . $current . '），未持久化');
    }
    ug_bios_persist_wol($interface, $policy);
    ug_log('bios.wol_write', 'WOL 写入并持久化成功 ' . $interface . '=' . $policy);
    return ['ok' => true, 'interface' => $interface, 'policy' => $policy, 'before' => $before['available'] ? $before['value'] : null];
}

function ug_bios_write_rtc(array $params): array {
    ug_bios_only($params, ['epoch', 'action', 'confirm']);
    if (($params['confirm'] ?? '') !== 'apply') throw new InvalidArgumentException('需要 confirm: apply');
    ug_bios_identity();

    // 写前捕获旧 alarm，仅用于响应与日志；失败绝不隐式恢复（恢复本身可能危险）。
    $old = ug_bios_read_rtc();

    if (array_key_exists('action', $params)) {
        if ($params['action'] !== 'clear') throw new InvalidArgumentException('action 仅支持 clear');
        $r = ug_bios_exec(['--force', '--apply', 'power', 'rtc-wake', 'clear'], UG_BIOS_WRITE_TIMEOUT);
        if ($r['code'] !== 0) {
            $err = ug_bios_cli_error($r['output']);
            ug_log('bios.rtc_clear_failed', 'RTC 清除失败：' . $err);
            throw new RuntimeException('RTC 清除失败，未隐式恢复：' . $err);
        }
        $after = ug_bios_read_rtc();
        if (!$after['available'] || $after['epoch'] !== 0) {
            $current = $after['available'] ? ($after['epoch'] ?? 'unknown') : 'unknown';
            ug_log('bios.rtc_clear_mismatch', 'RTC 清除读回不匹配 实际=' . $current . '；未持久化');
            throw new RuntimeException('RTC 清除读回不匹配，当前状态未确认（' . $current . '），未持久化');
        }
        ug_bios_persist_rtc(null);
        ug_log('bios.rtc_clear', 'RTC 定时唤醒已清除并持久化');
        return ['ok' => true, 'action' => 'clear', 'before' => $old['available'] ? $old['epoch'] : null];
    }

    if (!array_key_exists('epoch', $params)) throw new InvalidArgumentException('需要 epoch 或 action');
    $epoch = $params['epoch'];
    // 不接受字符串 epoch（防 shell 注入）；仅接受 JSON 整数。
    if (!is_int($epoch) && !(is_float($epoch) && floor($epoch) === $epoch)) {
        throw new InvalidArgumentException('epoch 必须为数值时间戳');
    }
    $epoch = (int)$epoch;
    $now = time();
    if ($epoch < $now + UG_BIOS_RTC_MIN_AHEAD || $epoch > $now + UG_BIOS_RTC_MAX_AHEAD) {
        throw new InvalidArgumentException('epoch 必须在 now+' . UG_BIOS_RTC_MIN_AHEAD . ' 到 now+' . UG_BIOS_RTC_MAX_AHEAD . ' 秒之间');
    }
    // 新请求显式覆盖现有 alarm（上游 update_alarm 先清后设），不自动重放过期值。
    $r = ug_bios_exec(['--force', '--apply', 'power', 'rtc-wake', 'set', (string)$epoch], UG_BIOS_WRITE_TIMEOUT);
    if ($r['code'] !== 0) {
        $err = ug_bios_cli_error($r['output']);
        ug_log('bios.rtc_set_failed', 'RTC 设置失败：' . $err);
        throw new RuntimeException('RTC 设置失败，未隐式恢复：' . $err);
    }
    $after = ug_bios_read_rtc();
    if (!$after['available'] || $after['epoch'] !== $epoch) {
        $current = $after['available'] ? ($after['epoch'] ?? 'unknown') : 'unknown';
        ug_log('bios.rtc_set_mismatch', 'RTC 设置读回不匹配 期望=' . $epoch . ' 实际=' . $current . '；未持久化');
        throw new RuntimeException('RTC 设置读回不匹配，当前状态未确认（' . $current . '），未持久化');
    }
    ug_bios_persist_rtc($epoch);
    ug_log('bios.rtc_set', 'RTC 定时唤醒设置并持久化成功');
    return ['ok' => true, 'epoch' => $epoch, 'before' => $old['available'] ? $old['epoch'] : null];
}

function ug_bios_write_startup(array $params): array {
    ug_bios_only($params, ['policy', 'confirm']);
    $policy = $params['policy'] ?? '';
    if (!in_array($policy, ['on', 'off', 'restore'], true)) throw new InvalidArgumentException('policy 必须为 on/off/restore');
    if (($params['confirm'] ?? '') !== 'apply') throw new InvalidArgumentException('需要 confirm: apply');
    ug_bios_identity();

    if (ug_bios_fan_owner_active(ug_bios_modules(), ug_bios_fan_controller())) {
        throw new RuntimeException('来电启动写入被拒绝：it87/it8620（FanCtrlPlus）内核驱动占用，原厂 CLI 仅支持 it8613；不卸载驱动');
    }
    // 受保护 CLI 能力保留：未来 it87 未占用时写入。来电启动不持久化，避免 AC 恢复冲突。
    $r = ug_bios_exec(['--plugin-dir', ug_bios_model_dir(), '--force', '--apply', 'power', 'startup', 'set', $policy], UG_BIOS_WRITE_TIMEOUT);
    if ($r['code'] !== 0) throw new RuntimeException('来电启动写入失败：' . ug_bios_cli_error($r['output']));
    ug_log('bios.startup_set', '来电启动策略写入成功 policy=' . $policy);
    return ['ok' => true, 'policy' => $policy];
}

function ug_bios_write_fan_takeover(array $params): array {
    ug_bios_only($params, ['confirm']);
    if (($params['confirm'] ?? '') !== 'apply') throw new InvalidArgumentException('需要 confirm: apply');
    ug_bios_identity();
    // 能力不可用（it87 接管），明确 409；不实现假接管、不停止风扇、不卸载驱动。
    throw new RuntimeException('风扇接管不可用：it87/it8620（FanCtrlPlus）内核驱动已接管风扇；不提供接管、不停止风扇');
}

function ug_bios_write_fan_release(array $params): array {
    ug_bios_only($params, ['confirm']);
    if (($params['confirm'] ?? '') !== 'apply') throw new InvalidArgumentException('需要 confirm: apply');
    // 无接管 owner 时 no-op，不做假接管。
    return ['ok' => true, 'released' => false, 'owner' => null, 'note' => '当前无接管 owner，无需释放'];
}

function ug_bios_write_locked(string $sub, array $params): array {
    return match ($sub) {
        'wol' => ug_bios_write_wol($params),
        'rtc' => ug_bios_write_rtc($params),
        'startup' => ug_bios_write_startup($params),
        'fan/takeover' => ug_bios_write_fan_takeover($params),
        'fan/release' => ug_bios_write_fan_release($params),
        default => throw new InvalidArgumentException('未知 BIOS 写入端点: /bios/' . $sub),
    };
}

/**
 * 主写入入口。master HTTP 层在调用前完成会话与 CSRF 校验；
 * 这里统一持有全局锁 ug_lock('bios')。
 */
function ug_bios_write(string $path, string $body): array {
    if ($path !== 'bios' && !str_starts_with($path, 'bios/')) {
        throw new InvalidArgumentException('未知 BIOS 端点: /' . $path);
    }
    $sub = $path === 'bios' ? '' : substr($path, 5);
    $params = ug_bios_params($body);
    return ug_lock('bios', static fn(): array => ug_bios_write_locked($sub, $params));
}

// ── restore（主 agent hook 在阵列启动延迟后调用） ──────────────────────────────

function ug_bios_restore(): array {
    return ug_lock('bios', function (): array {
        $s = ug_bios_settings_read();
        if (($s['model'] ?? null) !== UG_BIOS_MODEL) {
            return ['ok' => true, 'restored' => [], 'failed' => [], 'reason' => '无匹配的持久化选择'];
        }
        if (ug_bios_dmi() !== UG_BIOS_MODEL) {
            return ['ok' => true, 'restored' => [], 'failed' => [], 'reason' => '机型不匹配，未恢复'];
        }
        try {
            ug_bios_identity();
        } catch (Throwable $e) {
            ug_log('bios.restore_identity_failed', $e->getMessage());
            return ['ok' => false, 'restored' => [], 'failed' => [], 'reason' => $e->getMessage()];
        }
        $wol = $s['wol'] ?? [];
        if (!is_array($wol) || $wol === []) {
            return ['ok' => true, 'restored' => [], 'failed' => [], 'reason' => '无已保存的 WOL 选择'];
        }
        $restored = [];
        $failed = [];
        foreach ($wol as $interface => $policy) {
            if (!in_array($interface, UG_BIOS_WOL_INTERFACES, true) || !in_array($policy, ['on', 'off'], true)) continue;
            try {
                $r = ug_bios_exec(['--force', '--apply', 'network', 'wol', 'set', $policy, '--interface', $interface], UG_BIOS_WRITE_TIMEOUT);
                if ($r['code'] !== 0) throw new RuntimeException(ug_bios_cli_error($r['output']));
                $after = ug_bios_read_wol_interface($interface);
                if (!$after['available'] || $after['value'] !== $policy) throw new RuntimeException('读回不匹配');
                $restored[$interface] = $policy;
            } catch (Throwable $e) {
                $failed[$interface] = $e->getMessage();
                ug_log('bios.restore_wol_failed', 'WOL 恢复失败 ' . $interface . '=' . $policy . '：' . $e->getMessage());
            }
        }
        return [
            'ok' => $failed === [],
            'restored' => $restored,
            'failed' => $failed,
            'note' => 'RTC 与来电启动不自动恢复（未来唤醒不可偷偷清除，避免 AC 恢复冲突）',
        ];
    });
}
