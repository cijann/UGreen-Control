<?php
declare(strict_types=1);

// 不反向引入 service.php；执行接口及 setup 锁由调用方提供。
if (!class_exists('UgCancelled', false)) {
    class UgCancelled extends RuntimeException {}
}

const UG_DRIVER_KERNEL = '6.18.33-Unraid';
const UG_DRIVER_PACKAGE = 'ugreen_leds-20260305-6.18.33-Unraid-1.txz';
const UG_DRIVER_SHA256 = 'aa8ddd21ababb03bc2e792162150a225c69b9c4d290931b2584f9578835b9a2d';

function ug_driver_kernel(): string {
    // fixture 的 kernel 是临时文件路径，可模拟恢复期间内核变化。
    if (PHP_SAPI === 'cli' && defined('UGREEN_PHASE2_FIXTURE') && isset(UGREEN_PHASE2_FIXTURE['kernel'])) {
        $kernel = @file_get_contents(UGREEN_PHASE2_FIXTURE['kernel']);
        if ($kernel === false) throw new RuntimeException('无法读取 fixture 内核版本');
        return trim($kernel);
    }
    return php_uname('r');
}

function ug_driver_bundled(): string {
    if (PHP_SAPI === 'cli' && defined('UGREEN_PHASE2_FIXTURE') && isset(UGREEN_PHASE2_FIXTURE['bundled'])) {
        return UGREEN_PHASE2_FIXTURE['bundled'];
    }
    return dirname(__DIR__);
}

function ug_driver_plan(string $kernel, string $boot, string $bundled): array {
    if ($kernel !== UG_DRIVER_KERNEL) throw new RuntimeException('驱动仅支持精确内核 ' . UG_DRIVER_KERNEL);
    foreach ([$boot, $bundled] as $root) {
        $package = rtrim($root, '/') . '/drivers/' . UG_DRIVER_PACKAGE;
        clearstatcache(true, $package);
        if (!file_exists($package) && !is_link($package)) continue;
        // 高优先级缓存存在但损坏时不得回退，避免掩盖缓存污染。
        if (!is_file($package) || !is_readable($package)) throw new RuntimeException('驱动包不可读');
        $sha = @hash_file('sha256', $package);
        if (!is_string($sha) || !hash_equals(UG_DRIVER_SHA256, $sha)) throw new RuntimeException('驱动包 SHA256 校验失败');
        return ['kernel' => $kernel, 'package' => $package, 'sha256' => UG_DRIVER_SHA256];
    }
    throw new RuntimeException('缺少已固定版本的驱动包');
}

function ug_driver_vermagic_matches(string $vermagic, string $kernel): bool {
    if ($kernel !== UG_DRIVER_KERNEL) return false;
    $tokens = preg_split('/\s+/', trim($vermagic));
    return ($tokens[0] ?? '') === $kernel;
}

function ug_driver_gate(string $generation, ?string $kernel = null): void {
    if (!ug_allowed($generation)) throw new UgCancelled('驱动恢复已取消');
    if ($kernel !== null && ug_driver_kernel() !== $kernel) throw new RuntimeException('恢复期间内核版本发生变化');
}

function ug_driver_modinfo(string $kernel): array {
    return ug_exec(['/sbin/modinfo', '-F', 'vermagic', '-k', $kernel, 'led-ugreen'], '', 5);
}

function ug_driver_restore(string $generation): void {
    ug_driver_gate($generation);
    $kernel = ug_driver_kernel();
    ug_driver_gate($generation, $kernel);
    $plan = ug_driver_plan($kernel, ug_path('boot'), ug_driver_bundled());
    ug_driver_gate($generation, $kernel);
    $info = ug_driver_modinfo($kernel);
    ug_driver_gate($generation, $kernel);
    if ($info['code'] === 0 && ug_driver_vermagic_matches($info['output'], $kernel)) return;
    // 不覆盖仍在使用的模块；卸载、加载及 sysfs 写入都由 service 负责。
    if (ug_module_loaded('led_ugreen')) throw new RuntimeException('已加载的 led-ugreen 无法确认匹配，拒绝覆盖驱动');

    ug_driver_gate($generation, $kernel);
    ug_guarded($generation, static function () use ($generation, $kernel, $plan): void {
        ug_driver_gate($generation, $kernel);
        $checked = ug_driver_plan($kernel, ug_path('boot'), ug_driver_bundled());
        if ($checked !== $plan) throw new RuntimeException('恢复期间驱动包路径发生变化');
        ug_driver_gate($generation, $kernel);
        $result = ug_exec(['timeout', '--signal=TERM', '--kill-after=2s', '45s', '/sbin/installpkg', $plan['package']], '', 50);
        ug_driver_gate($generation, $kernel);
        if ($result['code'] !== 0) throw new RuntimeException('安装 led-ugreen 驱动包失败');
    });

    ug_driver_gate($generation, $kernel);
    ug_guarded($generation, static function () use ($generation, $kernel): void {
        ug_driver_gate($generation, $kernel);
        $result = ug_exec(['/sbin/depmod', '-a', $kernel], '', 30);
        ug_driver_gate($generation, $kernel);
        if ($result['code'] !== 0) throw new RuntimeException('更新驱动模块依赖失败');
    });

    ug_driver_gate($generation, $kernel);
    $info = ug_driver_modinfo($kernel);
    ug_driver_gate($generation, $kernel);
    if ($info['code'] !== 0 || !ug_driver_vermagic_matches($info['output'], $kernel)) {
        throw new RuntimeException('恢复后的 led-ugreen vermagic 不匹配');
    }
}

function ug_driver_report(): array {
    $kernel = ug_driver_kernel();
    $report = ['kernel' => $kernel, 'supported' => $kernel === UG_DRIVER_KERNEL,
        'package_available' => false, 'vermagic_matches' => false, 'path' => null];
    if (!$report['supported']) return $report;
    try {
        $plan = ug_driver_plan($kernel, ug_path('boot'), ug_driver_bundled());
        $report['package_available'] = true;
        $report['path'] = $plan['package'];
    } catch (RuntimeException $e) {
        // check 只返回可用性，不暴露子进程输出、配置或异常上下文。
    }
    try {
        $info = ug_driver_modinfo($kernel);
        $report['vermagic_matches'] = $info['code'] === 0 && ug_driver_vermagic_matches($info['output'], $kernel);
    } catch (RuntimeException $e) {
        // 缺少 modinfo 时仍能提供内核和包状态。
    }
    return $report;
}
