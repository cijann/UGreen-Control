<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$boot = '/boot/config/plugins/ugreen-control';
$plg = '/boot/config/plugins/ugreen-control.plg';
try {
    $xml = file_get_contents($plg);
    if ($xml === false || !preg_match('/<!ENTITY version\s+"([A-Za-z0-9._-]+)"/', $xml, $m)) throw new RuntimeException('Cannot read installed plugin version');
    $version = $m[1];
    $name = 'ugreen-control-unraid-' . $version . '.txz';
    $txz = $boot . '/' . $name;
    if (!is_file($txz) || !str_contains($xml, md5_file($txz))) throw new RuntimeException('Installed package checksum does not match plugin definition');
    $dir = $boot . '/backup-' . $version;
    if (is_dir($dir)) {
        foreach (['ugreen-control.plg', $name] as $file) {
            $sum = trim((string)@file_get_contents($dir . '/' . $file . '.sha256'));
            if (!is_file($dir . '/' . $file) || !hash_equals($sum, hash_file('sha256', $dir . '/' . $file))) throw new RuntimeException('Existing backup is incomplete; refusing overwrite');
        }
        echo "Backup retained: $dir\n"; exit;
    }
    if (!mkdir($dir, 0700)) throw new RuntimeException('Cannot create backup directory');
    foreach ([$plg => 'ugreen-control.plg', $txz => $name] as $source => $file) {
        if (!copy($source, $dir . '/' . $file)) throw new RuntimeException('Cannot copy backup');
        $hash = hash_file('sha256', $source);
        if (!hash_equals($hash, hash_file('sha256', $dir . '/' . $file))) throw new RuntimeException('Backup verification failed');
        file_put_contents($dir . '/' . $file . '.sha256', $hash . "\n");
    }
    if (is_file($boot . '/settings.conf')) copy($boot . '/settings.conf', $dir . '/settings.conf.snapshot');
    echo "Backup verified: $dir\n";
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
