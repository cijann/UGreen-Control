<?php
declare(strict_types=1);
require_once __DIR__ . '/lighting.php';

function ug_session_valid(array $session): bool {
    $login = $session['unraid_login'] ?? null;
    return (is_int($login) || (is_string($login) && ctype_digit($login))) && (int)$login > 0 && (int)$login <= time() + 60;
}
function ug_authenticated(): bool {
    if (PHP_SAPI === 'cli' && defined('UGREEN_HTTP_FIXTURE')) return ug_session_valid(UGREEN_HTTP_FIXTURE['session'] ?? []);
    if (session_status() === PHP_SESSION_ACTIVE) {
        $ok = ug_session_valid($_SESSION); session_write_close(); return $ok;
    }
    $cookie = $_COOKIE[session_name()] ?? null;
    if (!is_string($cookie) || !preg_match('/^[a-zA-Z0-9,-]{16,128}$/D', $cookie)) return false;
    if (!@session_start(['read_and_close' => true, 'use_strict_mode' => 1, 'use_only_cookies' => 1])) return false;
    return ug_session_valid($_SESSION ?? []);
}
function ug_csrf_valid(string $token, string $expected): bool {
    return $expected !== '' && $token !== '' && hash_equals($expected, $token);
}
function ug_authorize(bool $write): void {
    if (!ug_authenticated()) throw new UgHttpError('需要有效的 Unraid 登录会话', 401);
    // 不再重复校验 CSRF：Unraid 的 auto_prepend_file（local_prepend.php）已对所有非
    // login/auth 的 POST 强制校验 X-CSRF-Token / csrf_token，并在通过后 unset 该变量；
    // 在此再次校验只会读到空值并误报 403。
}
class UgHttpError extends RuntimeException {}
function ug_http_params(string $body): array {
    if (strlen($body) > 32768) throw new InvalidArgumentException('请求过大');
    $params = [];
    if (trim($body) !== '') {
        $params = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($params)) throw new InvalidArgumentException('请求必须为 JSON 对象');
    }
    return array_replace($_GET, $params);
}
function ug_only(array $params, array $keys): void {
    if (array_diff(array_keys($params), $keys)) throw new InvalidArgumentException('包含不允许的字段');
}
function ug_http_write(string $path, string $body): array {
    if ($path === 'bios' || str_starts_with($path, 'bios/')) throw new UgHttpError('第二阶段不支持 BIOS/风扇写入', 405);
    if ($path === 'settings') {
        $patch = ug_parse_settings($body);
        $cfg = ug_patch(ug_settings(), $patch);
        ug_validate_serial_map($cfg, ug_disks());
        $cfg = ug_settings_save($patch);
        if (($patch['daemon']['enabled'] ?? null) === 'false' || ($patch['daemon']['enabled'] ?? null) === false) ug_stop(true);
        if (($patch['daemon']['enabled'] ?? null) === 'true' || ($patch['daemon']['enabled'] ?? null) === true) {
            ug_lock('control', static function (): void {
                $file = ug_path('runtime') . '/DISABLED';
                if (is_file($file) && !unlink($file)) throw new RuntimeException('无法清除禁用标记');
            });
        }
        if (isset($patch['autostart']) && in_array(ug_service_status()['state'], ['queued', 'waiting'], true)) ug_stop();
        return ['ok' => true, 'raw' => ug_ini_encode($cfg), 'restart_required' => false];
    }
    $params = ug_http_params($body);
    switch ($path) {
        case 'mode':
            ug_only($params, ['mode']);
            $mode = ug_value('mode', 'global', $params['mode'] ?? '');
            ug_settings_save(['mode' => ['global' => $mode]]);
            $service = ug_service_status();
            if (!$service['running']) ug_queue(false);
            return ['ok' => true, 'mode' => $mode, 'service' => ug_service_status()];
        case 'daemon/start':
            ug_only($params, []); return ug_control('start');
        case 'daemon/stop':
            ug_only($params, []); return ug_control('stop');
        case 'remap':
            ug_only($params, ['disk_map', 'mode']);
            $patch = [];
            if (isset($params['disk_map'])) {
                if (!is_array($params['disk_map'])) throw new InvalidArgumentException('disk_map 必须为对象');
                $patch['disk_map'] = $params['disk_map'];
                $patch['behavior']['disk_map_mode'] = $params['mode'] ?? 'disk';
            } elseif (isset($params['mode'])) $patch['behavior']['disk_map_mode'] = $params['mode'];
            return ug_remap($patch ?: null);
        case 'autostart':
            ug_only($params, ['enabled', 'delay_seconds']);
            if (!array_key_exists('enabled', $params)) throw new InvalidArgumentException('缺少 enabled');
            $enabled = ug_value('autostart', 'enabled', $params['enabled']);
            $delay = ug_value('autostart', 'delay_seconds', $params['delay_seconds'] ?? ug_settings()['autostart']['delay_seconds']);
            ug_settings_save(['autostart' => ['enabled' => $enabled, 'delay_seconds' => $delay]]);
            // 关闭或改变延迟时使已排队的旧计划失效；运行中的服务不因设置变化重启。
            $state = ug_service_status();
            if (!$state['running'] && in_array($state['state'], ['queued', 'waiting'], true)) ug_stop();
            return ['ok' => true, 'enabled' => $enabled === 'true', 'delay_seconds' => (int)$delay, 'service' => ug_service_status()];
        case 'led/set':
            ug_only($params, ['led', 'action']);
            // UI 的紧急全关兼容入口；单灯调试不绕过 daemon 仲裁。
            if (($params['action'] ?? '') !== 'off' || ($params['led'] ?? 'all') !== 'all') throw new InvalidArgumentException('led/set 仅支持 led=all, action=off');
            $cfg = ug_settings_save(['mode' => ['global' => 'off']]);
            if (!ug_service_status()['running']) ug_queue(false);
            return ['ok' => true, 'mode' => $cfg['mode']['global'], 'scope' => 'all_managed_leds'];
        default: throw new UgHttpError('未知写入端点', 404);
    }
}
