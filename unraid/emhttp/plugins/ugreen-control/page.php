<?php
/**
 * UGreen Control — Unraid WebGUI page
 * Renders the React SPA in a full-height div; auth is enforced upstream by
 * Unraid's nginx auth_request, so no additional login check is needed here.
 */
declare(strict_types=1);
$plugin_ver = trim((string)@file_get_contents('/usr/local/emhttp/plugins/ugreen-control/version.txt'));
if (!$plugin_ver) $plugin_ver = '3.0.7-unraid-p1';
$base = "/plugins/ugreen-control/www";
$v    = "?v=" . urlencode($plugin_ver);
?>
<style>
  #ugreen-control-root { display: flex; height: calc(100vh - 48px); overflow: hidden; }
  #ugreen-control-root iframe { width: 100%; height: 100%; border: 0; }
</style>
<div id="ugreen-control-root">
  <iframe
    src="<?= htmlspecialchars($base . '/index.html' . $v, ENT_QUOTES, 'UTF-8') ?>"
    title="UGreen Control"
    sandbox="allow-scripts allow-same-origin allow-downloads"
    loading="lazy">
  </iframe>
</div>
