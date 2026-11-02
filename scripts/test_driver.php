<?php
declare(strict_types=1);
// 仅临时文件和桩函数；不引入 service，不启动任何进程，不访问真实 /sys。
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = sys_get_temp_dir() . '/ugreen-driver-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
foreach (['boot/drivers', 'bundled/drivers'] as $dir) mkdir($root . '/' . $dir, 0700, true);
define('UGREEN_PHASE2_FIXTURE', ['boot' => $root . '/boot', 'bundled' => $root . '/bundled', 'kernel' => $root . '/kernel']);
require dirname(__DIR__) . '/unraid/emhttp/plugins/ugreen-control/lib/driver.php';

$count = 0;
$state = [];
function driver_check(bool $ok, string $label): void {
    global $count;
    if (!$ok) throw new RuntimeException('失败: ' . $label);
    $count++;
}
function driver_rejects(callable $fn, string $label, string $class = RuntimeException::class): void {
    try { $fn(); } catch (Throwable $e) {
        driver_check($e instanceof $class, $label . '：异常类型');
        return;
    }
    driver_check(false, $label);
}
function driver_reset(array $patch = []): void {
    global $state;
    file_put_contents(UGREEN_PHASE2_FIXTURE['kernel'], UG_DRIVER_KERNEL);
    $state = array_replace(['allowed' => true, 'checks' => 0, 'cancel_at' => 0, 'guard_cancel' => false,
        'guarded' => false, 'guards' => 0, 'loaded' => false, 'commands' => [], 'after_exec' => null,
        'modinfo' => [['code' => 1, 'output' => ''], ['code' => 0, 'output' => UG_DRIVER_KERNEL . ' SMP mod_unload']],
        'install_code' => 0, 'depmod_code' => 0], $patch);
}
function ug_path(string $key): string {
    if ($key !== 'boot') throw new LogicException('fixture 禁止访问其他系统路径');
    return UGREEN_PHASE2_FIXTURE[$key];
}
function ug_allowed(string $generation): bool {
    global $state;
    $state['checks']++;
    if ($state['cancel_at'] === $state['checks']) $state['allowed'] = false;
    return $generation === 'fixture-generation' && $state['allowed'];
}
function ug_guarded(string $generation, callable $fn) {
    global $state;
    $state['guards']++;
    if ($state['guard_cancel']) $state['allowed'] = false;
    if (!ug_allowed($generation)) throw new UgCancelled('fixture 取消');
    if ($state['guarded']) throw new LogicException('不得嵌套 setup 锁');
    $state['guarded'] = true;
    try { return $fn(); } finally { $state['guarded'] = false; }
}
function ug_module_loaded(string $name): bool {
    global $state;
    if ($name !== 'led_ugreen') throw new LogicException('意外的模块');
    return $state['loaded'];
}
function ug_exec(array $args, string $input = '', float $seconds = 5): array {
    global $state;
    if ($input !== '') throw new LogicException('意外的标准输入');
    if ($args === ['/sbin/modinfo', '-F', 'vermagic', '-k', UG_DRIVER_KERNEL, 'led-ugreen']) {
        if ($seconds !== 5.0) throw new LogicException('modinfo 超时无界');
        $result = array_shift($state['modinfo']);
        if (!is_array($result)) throw new LogicException('多余的 modinfo 调用');
    } elseif ($args === ['timeout', '--signal=TERM', '--kill-after=2s', '45s', '/sbin/installpkg',
        UGREEN_PHASE2_FIXTURE['bundled'] . '/drivers/' . UG_DRIVER_PACKAGE]) {
        if (!$state['guarded'] || $seconds !== 50.0) throw new LogicException('安装缺少锁或超时错误');
        $result = ['code' => $state['install_code'], 'output' => 'fixture-secret'];
    } elseif ($args === ['/sbin/depmod', '-a', UG_DRIVER_KERNEL]) {
        if (!$state['guarded'] || $seconds !== 30.0) throw new LogicException('depmod 缺少锁或超时错误');
        $result = ['code' => $state['depmod_code'], 'output' => 'fixture-secret'];
    } else {
        throw new LogicException('fixture 拒绝未知命令（包括 modprobe）');
    }
    $state['commands'][] = $args;
    if ($state['after_exec'] !== null) ($state['after_exec'])(count($state['commands']));
    return $result;
}
function driver_cleanup(string $dir): void {
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . '/' . $entry;
        if (is_dir($path) && !is_link($path)) driver_cleanup($path); else unlink($path);
    }
    rmdir($dir);
}

try {
    $source = $argv[1] ?? dirname(__DIR__, 2) . '/' . UG_DRIVER_PACKAGE;
    if (!is_file($source)) $source = dirname(__DIR__) . '/unraid/emhttp/plugins/ugreen-control/drivers/' . UG_DRIVER_PACKAGE;
    if (!is_file($source) || hash_file('sha256', $source) !== UG_DRIVER_SHA256) {
        throw new RuntimeException('请以第一个参数提供指定 SHA256 的真实 txz（仅复制和读取，不解包或安装）');
    }
    $boot = UGREEN_PHASE2_FIXTURE['boot'];
    $bundled = UGREEN_PHASE2_FIXTURE['bundled'];
    $bootPackage = $boot . '/drivers/' . UG_DRIVER_PACKAGE;
    $bundledPackage = $bundled . '/drivers/' . UG_DRIVER_PACKAGE;
    driver_rejects(static fn() => ug_driver_plan(UG_DRIVER_KERNEL, $boot, $bundled), '缺包拒绝');
    copy($source, $bundledPackage);
    $plan = ug_driver_plan(UG_DRIVER_KERNEL, $boot, $bundled);
    driver_check($plan === ['kernel' => UG_DRIVER_KERNEL, 'package' => $bundledPackage, 'sha256' => UG_DRIVER_SHA256], '回退随插件包');
    foreach (['6.18.33', '6.18.34-Unraid', '6.18.33-Unraid-extra', UG_DRIVER_KERNEL . "\n", ''] as $kernel) {
        driver_rejects(static fn() => ug_driver_plan($kernel, $boot, $bundled), '非精确内核拒绝');
    }
    copy($source, $bootPackage);
    driver_check(ug_driver_plan(UG_DRIVER_KERNEL, $boot, $bundled)['package'] === $bootPackage, 'boot 包优先');
    file_put_contents($bootPackage, 'bad');
    driver_rejects(static fn() => ug_driver_plan(UG_DRIVER_KERNEL, $boot, $bundled), '坏 boot 包不得回退');
    unlink($bootPackage);
    file_put_contents($bundledPackage, 'bad');
    driver_rejects(static fn() => ug_driver_plan(UG_DRIVER_KERNEL, $boot, $bundled), '坏 bundled 包拒绝');
    copy($source, $bundledPackage);
    foreach ([UG_DRIVER_KERNEL, UG_DRIVER_KERNEL . " SMP preempt mod_unload\n", "  " . UG_DRIVER_KERNEL . "\tSMP"] as $magic) {
        driver_check(ug_driver_vermagic_matches($magic, UG_DRIVER_KERNEL), 'vermagic 首字段精确匹配');
    }
    foreach (['', UG_DRIVER_KERNEL . '-extra SMP', '6.18.34-Unraid SMP', 'prefix ' . UG_DRIVER_KERNEL] as $magic) {
        driver_check(!ug_driver_vermagic_matches($magic, UG_DRIVER_KERNEL), '拒绝错误 vermagic');
    }
    driver_check(!ug_driver_vermagic_matches('6.18.34-Unraid SMP', '6.18.34-Unraid'), 'vermagic 不放宽内核白名单');

    driver_reset();
    ug_driver_restore('fixture-generation');
    driver_check(count($state['commands']) === 4 && $state['guards'] === 2, '查询、受锁安装、受锁 depmod、复验顺序');
    $gateCount = $state['checks'];
    for ($gate = 1; $gate <= $gateCount; $gate++) {
        driver_reset(['cancel_at' => $gate]);
        driver_rejects(static fn() => ug_driver_restore('fixture-generation'), '任一步 gate 取消', UgCancelled::class);
        driver_check(!$state['guarded'], '取消释放 setup 锁');
    }
    driver_reset(['guard_cancel' => true]);
    driver_rejects(static fn() => ug_driver_restore('fixture-generation'), '取得 setup 锁前取消', UgCancelled::class);
    driver_check(count($state['commands']) === 1, '锁内取消不安装');
    foreach ([1, 2, 3, 4] as $cancelAfter) {
        driver_reset(['after_exec' => static function (int $step) use ($cancelAfter): void {
            global $state;
            if ($step === $cancelAfter) $state['allowed'] = false;
        }]);
        driver_rejects(static fn() => ug_driver_restore('fixture-generation'), '命令返回后取消', UgCancelled::class);
        driver_check(count($state['commands']) === $cancelAfter, '取消后不执行下一条命令');
    }
    driver_reset(['modinfo' => [['code' => 0, 'output' => UG_DRIVER_KERNEL . ' SMP']], 'loaded' => true]);
    ug_driver_restore('fixture-generation');
    driver_check(count($state['commands']) === 1 && $state['guards'] === 0, '已有匹配模块不安装');
    unlink($bundledPackage);
    driver_rejects(static fn() => ug_driver_restore('fixture-generation'), '已有匹配模块仍拒绝缺包');
    file_put_contents($bundledPackage, 'bad');
    driver_rejects(static fn() => ug_driver_restore('fixture-generation'), '已有匹配模块仍拒绝坏包');
    copy($source, $bundledPackage);
    driver_reset(['loaded' => true]);
    driver_rejects(static fn() => ug_driver_restore('fixture-generation'), '已加载但无法确认匹配时拒绝覆盖');
    driver_check(count($state['commands']) === 1, '未确认模块不安装');
    foreach (['install_code', 'depmod_code'] as $key) {
        driver_reset([$key => 124]);
        driver_rejects(static fn() => ug_driver_restore('fixture-generation'), '安装或 depmod 失败');
        driver_check(count($state['commands']) === ($key === 'install_code' ? 2 : 3), '失败后不继续');
    }
    driver_reset(['modinfo' => [['code' => 1, 'output' => UG_DRIVER_KERNEL], ['code' => 0, 'output' => '6.18.34-Unraid SMP']]]);
    driver_rejects(static fn() => ug_driver_restore('fixture-generation'), '失败的 modinfo 不算匹配且安装后强制复验');
    driver_check(count($state['commands']) === 4, 'vermagic 复验发生在 depmod 后');
    driver_reset(['after_exec' => static function (int $step): void {
        if ($step === 1) file_put_contents(UGREEN_PHASE2_FIXTURE['kernel'], '6.18.34-Unraid');
    }]);
    driver_rejects(static fn() => ug_driver_restore('fixture-generation'), '恢复期间内核变化拒绝');
    driver_check(count($state['commands']) === 1, '内核变化不安装');
    driver_reset(['after_exec' => static function (int $step) use ($bundledPackage): void {
        if ($step === 1) file_put_contents($bundledPackage, 'changed');
    }]);
    driver_rejects(static fn() => ug_driver_restore('fixture-generation'), '安装前重新校验包');
    driver_check(count($state['commands']) === 1, '校验失败不安装');
    copy($source, $bundledPackage);

    driver_reset(['allowed' => false, 'modinfo' => [['code' => 0, 'output' => UG_DRIVER_KERNEL . ' SMP']]]);
    $report = ug_driver_report();
    driver_check($report === ['kernel' => UG_DRIVER_KERNEL, 'supported' => true, 'package_available' => true,
        'vermagic_matches' => true, 'path' => $bundledPackage], '只读报告字段和有效包路径');
    driver_check($state['guards'] === 0 && $state['checks'] === 0 && count($state['commands']) === 1, '报告只运行 modinfo，不修改系统');
    driver_reset();
    file_put_contents($bootPackage, 'fixture-secret');
    $report = ug_driver_report();
    driver_check(!$report['package_available'] && !$report['vermagic_matches'] && $report['path'] === null,
        '坏缓存报告不可用且不泄露命令输出');
    unlink($bootPackage);
    driver_reset();
    file_put_contents(UGREEN_PHASE2_FIXTURE['kernel'], '6.18.34-Unraid');
    driver_rejects(static fn() => ug_driver_restore('fixture-generation'), '恢复拒绝不支持内核');
    $report = ug_driver_report();
    driver_check(!$report['supported'] && !$report['package_available'] && $report['path'] === null && $state['commands'] === [],
        '不支持内核报告不执行命令');
    echo "通过 $count 项 driver fixture 检查；全部命令为桩，无硬件操作。\n";
} finally {
    driver_cleanup($root);
}
