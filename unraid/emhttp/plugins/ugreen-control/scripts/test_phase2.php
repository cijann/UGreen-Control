<?php
// 本地/NAS PHP CLI fixture；不调用 at、不运行硬件 CLI、不访问真实 /sys 或 /boot。
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = sys_get_temp_dir() . '/ugreen-phase2-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
foreach (['run', 'boot', 'emhttp', 'proc', 'block', 'net'] as $dir) mkdir($root . '/' . $dir, 0700);
define('UGREEN_PHASE2_FIXTURE', ['runtime' => "$root/run", 'boot' => "$root/boot", 'log' => "$root/service.log",
    'emhttp' => "$root/emhttp", 'proc' => "$root/proc", 'block' => "$root/block", 'net' => "$root/net", 'dmi' => "$root/dmi", 'cli' => "$root/not-a-cli"]);
define('UGREEN_API_TEST_ONLY', true);
require dirname(__DIR__) . '/api.php';
$count = 0;
function check(bool $ok, string $label): void {
    global $count;
    if (!$ok) throw new RuntimeException('失败: ' . $label);
    $count++;
}
function rejects(callable $fn, string $label): void {
    try { $fn(); } catch (InvalidArgumentException|UgHttpError $e) { check(true, $label); return; }
    check(false, $label);
}
function remove_fixture(string $dir): void {
    foreach (scandir($dir) ?: [] as $file) {
        if ($file === '.' || $file === '..') continue;
        $path = "$dir/$file";
        if (is_dir($path) && !is_link($path)) remove_fixture($path); else unlink($path);
    }
    rmdir($dir);
}
try {
    ug_init();
    $defaults = ug_defaults();
    check($defaults['disk_colors']['active'] === '0 255 0' && $defaults['autostart']['delay_seconds'] === '300', '沿用原始灯色和 300 秒延迟');
    $cfg = ug_patch($defaults, ug_parse_settings("disk_colors.active=255 255 255\nactivity.disk_blink=true\ndaemon.refresh_interval=2"));
    check($cfg['daemon']['check_interval'] === '2' && $cfg['disk_colors']['active'] === '255 255 255', '前端点号配置和间隔别名');
    $cfg2 = ug_patch($defaults, ug_parse_settings("[disk_colors]\nactive=\"off\"\n[power]\nbrightness=\"0\""));
    check($cfg2['disk_colors']['active'] === 'off' && $cfg2['power']['brightness'] === '0', 'INI 格式');
    foreach ([['disk_colors' => ['active' => '256 0 0']], ['disk_colors' => ['idle' => '1 2']],
        ['disk_brightness' => ['idle' => '-1']], ['behavior' => ['manage_power' => 'yes']],
        ['bios' => ['fan' => '255']], ['fan_curve' => ['enabled' => true]], ['hardware' => ['profile' => 'dx4600']],
        ['daemon' => ['refresh_interval' => '0']], ['daemon' => ['refresh_interval' => '61']],
        ['autostart' => ['delay_seconds' => '59']], ['autostart' => ['delay_seconds' => '3601']],
        ['disk_map' => ['disk5' => 's1']], ['disk_map' => ['disk1' => 'same', 'disk2' => 'same']],
        ['disk_map' => ['disk1' => 'x; id']], ['bios' => []]] as $bad) rejects(static fn() => ug_patch($defaults, $bad), '拒绝非法/BIOS 配置');
    rejects(static fn() => ug_parse_settings("mode.global=on\nmode.global=off"), '拒绝重复配置');
    rejects(static fn() => ug_http_write('bios/fan', '{}'), '拒绝 BIOS POST');
    check(!ug_session_valid([]) && !ug_session_valid(['unraid_login' => true]) && ug_session_valid(['unraid_login' => time()]), '独立会话校验');
    check(!ug_csrf_valid('', '') && !ug_csrf_valid('wrong', 'valid') && ug_csrf_valid('valid', 'valid'), 'CSRF 使用非空精确匹配');
    rejects(static fn() => ug_authorize(true), '无登录会话不能写入');
    define('UGREEN_HTTP_FIXTURE', ['session' => ['unraid_login' => time()]]);
    ug_authorize(true); check(true, '已登录即可写入；CSRF 由 Unraid local_prepend 在 POST 前强制校验');
    file_put_contents("$root/emhttp/disks.ini", "[\"disk1\"]\ntype=\"Data\"\ndevice=\"sdb\"\nspundown=\"1\"\n");
    check(read_disks_ini()['disk1']['device'] === 'sdb' && spundown_or_null(read_disks_ini()['disk1']), 'disks.ini 节与引号归一化');
    mkdir("$root/block/ata1/host0/target0:0:0/0:0:0:0", 0700, true);
    mkdir("$root/block/sdb");
    symlink("$root/block/ata1/host0/target0:0:0/0:0:0:0", "$root/block/sdb/device");
    file_put_contents("$root/block/sdb/device/vendor", 'ATA');
    file_put_contents("$root/block/sdb/device/serial", 'fixture-serial');
    $mapped = ug_mapping(read_disks_ini(), $defaults);
    check($mapped[0]['led'] === 'disk1' && $mapped[0]['evidence'] === 'SATA端口推定，未目视校准' && $mapped[0]['hctl'] === '0:0:0:0', '稳定 ata1 映射及证据');
    $serialCfg = ug_patch($defaults, ['behavior' => ['disk_map_mode' => 'disk'], 'disk_map' => ['disk4' => 'fixture-serial']]);
    ug_validate_serial_map($serialCfg, read_disks_ini());
    check(ug_mapping(read_disks_ini(), $serialCfg)[0]['led'] === 'disk4', '显式序列号校准');
    $serialCfg['disk_map']['disk4'] = 'missing';
    rejects(static fn() => ug_validate_serial_map($serialCfg, read_disks_ini()), '拒绝不存在的校准序列号');
    ug_settings_save(['power' => ['brightness' => '17']]);
    check(ug_settings()['power']['brightness'] === '17', '原子持久配置往返');
    $before = file_get_contents("$root/boot/settings.conf");
    rejects(static fn() => ug_settings_save(['power' => ['brightness' => '999']]), '非法保存被拒绝');
    check(file_get_contents("$root/boot/settings.conf") === $before, '非法保存不污染文件');
    $job = ['generation' => str_repeat('a', 32), 'due_at' => 400.0];
    check(ug_wait_decision($job, str_repeat('a', 32), false, true, 399.99) === 'wait', '延迟不提前执行');
    check(ug_wait_decision($job, str_repeat('a', 32), false, true, 400.0) === 'run', '到期执行');
    check(ug_wait_decision($job, str_repeat('b', 32), false, true, 1000) === 'cancel', '取消尚未启动的 at generation');
    check(ug_wait_decision($job, str_repeat('a', 32), true, true, 1000) === 'cancel', 'DISABLED 取消延迟');
    check(ug_wait_decision($job, str_repeat('a', 32), false, false, 1000) === 'cancel', '阵列停止取消延迟');
    check(str_contains(ug_queue_command(str_repeat('a', 32)), '/scripts/daemon.sh') && !str_contains(ug_queue_command(str_repeat('a', 32)), 'nohup'), 'at 命令引用实际随包脚本');
    rejects(static fn() => ug_queue_command('x; id'), '拒绝 generation 命令注入');
    ug_atomic("$root/run/generation", $job['generation']);
    ug_json_write('service', $job + ['state' => 'queued']);
    $result = ug_stop();
    check($result['ok'] && ug_generation() !== $job['generation'] && $result['service']['state'] === 'stopped', '空闲 stop 取消未启动任务');
    check(ug_wait_decision($job, ug_generation(), false, true, 1000) === 'cancel', '旧 at 即使随后开始仍无效');
    check(ug_starttime('7 (name with ) parens) S ' . implode(' ', array_fill(0, 18, '0')) . ' 123 0') === '123', '进程名括号不破坏 starttime 解析');
    mkdir("$root/proc/123");
    file_put_contents("$root/proc/123/stat", '123 (php) S ' . implode(' ', array_fill(0, 18, '0')) . ' 999 0');
    file_put_contents("$root/proc/123/cmdline", '/usr/bin/php' . "\0" . dirname(__DIR__) . '/scripts/daemon.php' . "\0run\0" . $job['generation'] . "\0");
    check(ug_identity_alive(['pid' => 123, 'starttime' => '999', 'generation' => $job['generation']]), 'PID+starttime+命令身份匹配');
    check(!ug_identity_alive(['pid' => 123, 'starttime' => '888', 'generation' => $job['generation']]), 'PID 复用拒绝');
    check(ug_rate(100, 2148, 2) === 1.0 && ug_rate(100, 50, 1) === null, '网络计数器回退不产生虚假速率');
    $net = ['network' => 'connected', 'rx_kbps' => 1024, 'tx_kbps' => 0];
    $row = ['led' => 'disk1', 'state' => 'active', 'read_kbps' => 4096, 'write_kbps' => 0];
    $cfg['activity']['network_blink'] = 'true';
    $desired = ug_desired($cfg, [$row], $net);
    check(($desired['disk1']['blink'] ?? false) === true && ($desired['netdev']['blink'] ?? false) === false, '硬盘活动闪动走 blink 标志，网络灯交给 netdev 触发器');
    check($desired['disk1']['color'] === '255 255 255', '用户白色活动灯');
    $cfg['mode']['global'] = 'off';
    check(count(ug_desired($cfg, [], $net)) === 6 && ug_desired($cfg, [], $net)['disk4'] === ['off' => true], 'off 管理六灯');
    $cfg['behavior']['manage_power'] = $cfg['behavior']['manage_netdev'] = 'false';
    check(count(ug_desired($cfg, [], $net)) === 4, '尊重接管开关');
    file_put_contents("$root/dmi", 'DX4600 Pro');
    try { ug_preflight(); check(false, '非精确型号被拒绝'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), '精确'), '非精确型号被拒绝'); }
    file_put_contents("$root/dmi", 'DX4600');
    ug_preflight(); check(true, '精确 DX4600 且无旧监控进程时 preflight 通过');
    mkdir("$root/proc/777", 0700, true);
    file_put_contents("$root/proc/777/cmdline", "/bin/bash\0/usr/bin/ugreen-leds\0");
    try { ug_preflight(); check(false, '旧监控进程被拒绝'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), '旧版'), '旧监控进程被拒绝'); }
    for ($i = 0; $i < 20; $i++) ug_log('test-' . $i, str_repeat('日志', 10000));
    check(filesize("$root/service.log") <= 256 * 1024, '日志大小有界');
    echo "通过 $count 项 phase2 fixture 检查；未执行硬件或 at。\n";
} finally { remove_fixture($root); }
