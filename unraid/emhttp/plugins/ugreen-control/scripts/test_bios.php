<?php
// BIOS 第三阶段本地 fixture：不调用真实 ugreenctl、不访问真实 /sys、/boot、不执行 NAS 硬件。
// CLI mock 仅由 UG_BIOS_FIXTURE 常量在 PHP CLI 下注入；HTTP 永远无法注入任何 shell/环境/路径。
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = sys_get_temp_dir() . '/ugreen-bios-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
foreach (['run', 'boot', 'emhttp', 'proc', 'block', 'net', 'leds', 'hwmon'] as $dir) mkdir($root . '/' . $dir, 0700, true);
mkdir($root . '/hwmon/hwmon0', 0700, true);
mkdir($root . '/models', 0700, true);

// —— mock ugreenctl（POSIX sh，模拟 wake_cli.c 文本输出与状态 readback） ——
$mock = $root . '/ugreenctl';
file_put_contents($mock, <<<'SH'
#!/bin/sh
dir=$(cd "$(dirname "$0")" && pwd)
mode=$(cat "$dir/cli-mode.txt" 2>/dev/null || echo ok)
case "$mode" in
  fail) echo "error: simulated backend failure" >&2; exit 1 ;;
  garbage) echo "totally unexpected garbage"; exit 0 ;;
esac
last=""
for a in "$@"; do last="$a"; done
case "$*" in
  "network wol get --interface eth0") cat "$dir/wol-eth0.state" 2>/dev/null || echo "on" ;;
  "network wol get --interface eth1") cat "$dir/wol-eth1.state" 2>/dev/null || echo "off" ;;
  "--force --apply network wol set on --interface eth0") echo "on" > "$dir/wol-eth0.state"; echo "WOL on; driver readback verified; shutdown wake unverified" ;;
  "--force --apply network wol set off --interface eth0") echo "off" > "$dir/wol-eth0.state"; echo "WOL off; driver readback verified; shutdown wake unverified" ;;
  "--force --apply network wol set on --interface eth1") echo "on" > "$dir/wol-eth1.state"; echo "WOL on; driver readback verified; shutdown wake unverified" ;;
  "--force --apply network wol set off --interface eth1") echo "off" > "$dir/wol-eth1.state"; echo "WOL off; driver readback verified; shutdown wake unverified" ;;
  "power rtc-wake get") cat "$dir/rtc.state" 2>/dev/null || echo "0" ;;
  "--force --apply power rtc-wake set "*) echo "$last" > "$dir/rtc.state"; echo "RTC alarm set: $last; interface readback verified; actual power-on unverified" ;;
  "--force --apply power rtc-wake clear") echo "0" > "$dir/rtc.state"; echo "RTC alarm cleared: 0; interface readback verified; actual power-on unverified" ;;
  *) echo "error: unrecognized command: $*" >&2; exit 2 ;;
esac
exit 0
SH
);
chmod($mock, 0755);
file_put_contents($root . '/models/dx4600.so', 'fixture-dx4600-payload');
file_put_contents($root . '/dmi', 'DX4600');
file_put_contents($root . '/proc/modules', "it87 28672 0 - Live 0xffffffffc0000000\nled_ugreen 16384 0 - Live 0xffffffffc0010000\n");
file_put_contents($root . '/hwmon/hwmon0/name', 'it8620');
file_put_contents($root . '/hwmon/hwmon0/fan3_input', '1500');
file_put_contents($root . '/hwmon/hwmon0/pwm3', '120');
file_put_contents($root . '/hwmon/hwmon0/pwm3_enable', '2');

$ctl_hash = hash_file('sha256', $mock);
$so_hash = hash_file('sha256', $root . '/models/dx4600.so');

define('UGREEN_PHASE2_FIXTURE', [
    'runtime' => "$root/run", 'boot' => "$root/boot", 'log' => "$root/service.log",
    'emhttp' => "$root/emhttp", 'proc' => "$root/proc", 'block' => "$root/block", 'net' => "$root/net",
    'dmi' => "$root/dmi", 'cli' => "$root/not-a-cli", 'leds' => "$root/leds",
]);
define('UG_BIOS_FIXTURE', [
    'ugreenctl' => $mock, 'models' => "$root/models", 'hwmon' => "$root/hwmon",
    'hashes' => ['ugreenctl' => $ctl_hash, 'dx4600_so' => $so_hash],
]);

require dirname(__DIR__) . '/lib/bios.php';

$count = 0;
function check(bool $ok, string $label): void {
    global $count;
    if (!$ok) throw new RuntimeException('失败: ' . $label);
    $count++;
}
function rejects_arg(callable $fn, string $label): void {
    try { $fn(); } catch (InvalidArgumentException $e) { check(true, $label); return; }
    catch (JsonException $e) { check(true, $label); return; }
    catch (Throwable $e) { check(false, $label . '（异常类型: ' . get_class($e) . '）'); return; }
    check(false, $label);
}
function rejects_runtime(callable $fn, string $label): void {
    try { $fn(); } catch (RuntimeException $e) { check(true, $label); return; }
    catch (Throwable $e) { check(false, $label . '（异常类型: ' . get_class($e) . '）'); return; }
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

    // ── 身份与 payload ──────────────────────────────────────────────
    check(ug_bios_dmi() === 'DX4600', 'DMI 精确 DX4600');
    check(ug_bios_payload_status()['ok'] === true, 'payload hash 均匹配');
    check(ug_bios_identity()['model'] === 'DX4600', '写入前身份校验通过');

    // ── status 结构（it87/it8620 接管场景） ─────────────────────────
    $s = ug_bios_status();
    check($s['ok'] === true && $s['supported'] === true && $s['model'] === 'DX4600', 'status ok/supported/model');
    check($s['fan']['available'] === false && $s['fan']['owner'] === 'FanCtrlPlus' && $s['fan']['takeover_supported'] === false, '风扇不可接管，owner=FanCtrlPlus');
    check($s['fan']['rpm'] === 1500 && $s['fan']['pwm'] === 120, '风扇只读遥测 rpm/pwm');
    check($s['fan']['reason'] !== '', '风扇提供原因');
    check($s['startup']['available'] === false && $s['startup']['value'] === null, '来电启动不可用且值为 null');
    check($s['wol']['available'] === true, 'WOL 可用');
    check(count($s['wol']['interfaces']) === 2, 'WOL 两接口');
    check($s['wol']['interfaces'][0] === ['name' => 'eth0', 'available' => true, 'value' => 'on', 'error' => null], 'WOL eth0=on');
    check($s['wol']['interfaces'][1] === ['name' => 'eth1', 'available' => true, 'value' => 'off', 'error' => null], 'WOL eth1=off');
    check($s['rtc']['available'] === true && $s['rtc']['epoch'] === 0 && $s['rtc']['error'] === null, 'RTC 可用且未设置=0');
    check(is_array($s['limitations']) && $s['limitations'] !== [], '限制说明存在');

    // ── status 缓存（10 秒内复用，不重复调 CLI） ─────────────────────
    file_put_contents("$root/rtc.state", '999');
    $s2 = ug_bios_status();
    check($s2['rtc']['epoch'] === 0, '10 秒缓存命中，RTC 仍为缓存值');
    unlink("$root/rtc.state"); // 重置，避免污染后续 RTC before 断言

    // ── parse errors（独立能力，不因单项失败拖垮整体） ───────────────
    file_put_contents("$root/cli-mode.txt", 'garbage');
    $wol_bad = ug_bios_read_wol_interface('eth0');
    check($wol_bad['available'] === false && $wol_bad['error'] !== null, 'WOL 输出无法解析时 available=false');
    file_put_contents("$root/cli-mode.txt", 'fail');
    $rtc_bad = ug_bios_read_rtc();
    check($rtc_bad['available'] === false && str_contains((string)$rtc_bad['error'], 'simulated'), 'RTC 后端失败时 available=false 且带原因');
    file_put_contents("$root/cli-mode.txt", 'ok');

    // ── bad params（白名单 + confirm） ───────────────────────────────
    rejects_arg(fn() => ug_bios_write('bios/wol', '{"interface":"eth0","policy":"on"}'), 'WOL 缺 confirm 被拒');
    rejects_arg(fn() => ug_bios_write('bios/wol', '{"interface":"eth0","policy":"flip","confirm":"apply"}'), 'WOL 非法 policy 被拒');
    rejects_arg(fn() => ug_bios_write('bios/wol', '{"interface":"eth9","policy":"on","confirm":"apply"}'), 'WOL 非法 interface 被拒');
    rejects_arg(fn() => ug_bios_write('bios/wol', '{"interface":"eth0","policy":"on","confirm":"apply","extra":1}'), 'WOL 多余字段被拒');
    rejects_arg(fn() => ug_bios_write('bios/rtc', '{"epoch":"123; id","confirm":"apply"}'), 'RTC 字符串 epoch（shell 注入）被拒');
    rejects_arg(fn() => ug_bios_write('bios/rtc', '{"epoch":' . (time() + 60) . ',"confirm":"apply"}'), 'RTC epoch 太近被拒');
    rejects_arg(fn() => ug_bios_write('bios/rtc', '{"epoch":' . (time() + 31622400 + 86400) . ',"confirm":"apply"}'), 'RTC epoch 超 366 天被拒');
    rejects_arg(fn() => ug_bios_write('bios/rtc', '{"confirm":"apply"}'), 'RTC 缺 epoch/action 被拒');
    rejects_arg(fn() => ug_bios_write('bios/rtc', '{"action":"wipe","confirm":"apply"}'), 'RTC 非法 action 被拒');
    rejects_arg(fn() => ug_bios_write('bios/startup', '{"policy":"flip","confirm":"apply"}'), 'startup 非法 policy 被拒');
    rejects_arg(fn() => ug_bios_write('bios/fan/takeover', '{}'), 'takeover 缺 confirm 被拒');
    rejects_arg(fn() => ug_bios_write('bios/wol', '{not-json'), '非法 JSON 被拒');

    // ── conflicting controllers（409，不卸载） ───────────────────────
    rejects_runtime(fn() => ug_bios_write('bios/startup', '{"policy":"on","confirm":"apply"}'), 'it87 接管时 startup 写 409');
    rejects_runtime(fn() => ug_bios_write('bios/fan/takeover', '{"confirm":"apply"}'), 'fan takeover 能力不可用 409');
    $rel = ug_bios_write('bios/fan/release', '{"confirm":"apply"}');
    check($rel['ok'] === true && $rel['released'] === false && $rel['owner'] === null, 'release 无 owner no-op');
    check(file_get_contents("$root/hwmon/hwmon0/pwm3_enable") === '2', '未卸载 it87 / 未停止风扇');

    // ── WOL 写 + 读回 + 持久化 + restore ─────────────────────────────
    $w = ug_bios_write('bios/wol', '{"interface":"eth0","policy":"off","confirm":"apply"}');
    check($w['ok'] === true && $w['policy'] === 'off' && $w['before'] === 'on', 'WOL 写成功并记录 before');
    check(ug_bios_read_wol_interface('eth0')['value'] === 'off', 'WOL 写后读回 off');
    $persisted = json_decode((string)file_get_contents("$root/boot/bios-settings.json"), true);
    check(($persisted['model'] ?? null) === 'DX4600' && ($persisted['wol']['eth0'] ?? null) === 'off', 'WOL 选择按接口持久化');
    unlink("$root/wol-eth0.state"); // 模拟重启后驱动回到默认
    check(ug_bios_read_wol_interface('eth0')['value'] === 'on', '重启后（state 丢失）回默认 on');
    $restore = ug_bios_restore();
    check($restore['ok'] === true && ($restore['restored']['eth0'] ?? null) === 'off', 'restore 恢复已保存的 eth0=off');
    check(ug_bios_read_wol_interface('eth0')['value'] === 'off', 'restore 后读回 off');

    // ── RTC set / clear + 读回 + 持久化 ─────────────────────────────
    $epoch = time() + 300;
    $rw = ug_bios_write('bios/rtc', '{"epoch":' . $epoch . ',"confirm":"apply"}');
    check($rw['ok'] === true && $rw['epoch'] === $epoch && $rw['before'] === 0, 'RTC 设置成功并捕获旧 alarm');
    check(ug_bios_read_rtc()['epoch'] === $epoch, 'RTC 设置后读回 epoch');
    $persisted = json_decode((string)file_get_contents("$root/boot/bios-settings.json"), true);
    check(($persisted['rtc']['epoch'] ?? null) === $epoch, 'RTC 成功后持久化');
    $rc = ug_bios_write('bios/rtc', '{"action":"clear","confirm":"apply"}');
    check($rc['ok'] === true && $rc['action'] === 'clear' && $rc['before'] === $epoch, 'RTC 清除成功并捕获旧 alarm');
    check(ug_bios_read_rtc()['epoch'] === 0, 'RTC 清除后读回 0');

    // ── cross model ─────────────────────────────────────────────────
    file_put_contents("$root/dmi", 'DXP4800');
    @unlink("$root/run/bios-status.json");
    $sm = ug_bios_status();
    check($sm['supported'] === false && $sm['model'] === 'DXP4800', '非 DX4600 时 supported=false');
    rejects_runtime(fn() => ug_bios_write('bios/wol', '{"interface":"eth0","policy":"on","confirm":"apply"}'), '跨机型写被拒');
    file_put_contents("$root/dmi", 'DX4600');
    @unlink("$root/run/bios-status.json");

    // ── payload hash mismatch ───────────────────────────────────────
    file_put_contents("$root/models/dx4600.so", 'tampered-payload', FILE_APPEND);
    rejects_runtime(fn() => ug_bios_identity(), 'payload hash 不匹配时身份校验被拒');
    rejects_runtime(fn() => ug_bios_write('bios/wol', '{"interface":"eth0","policy":"on","confirm":"apply"}'), 'payload 篡改后写被拒');
    file_put_contents("$root/models/dx4600.so", 'fixture-dx4600-payload');
    check(ug_bios_identity()['model'] === 'DX4600', '恢复 payload 后身份校验通过');

    // ── restore 未设置 / 跨机型不改 ─────────────────────────────────
    unlink("$root/boot/bios-settings.json");
    check(ug_bios_restore()['ok'] === true && ug_bios_restore()['restored'] === [], '无持久化选择时 restore no-op');

    echo "通过 $count 项 bios fixture 检查；未执行真实硬件或 ugreenctl。\n";
} finally { remove_fixture($root); }
