#!/bin/bash
# Explicit application rollback only; never unload modules or start LED hardware.
set -euo pipefail
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
BASE=$(builtin cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
CHECK=false
if [[ ${1:-} == --check ]]; then CHECK=true; shift; fi
[[ $# == 1 ]] || { printf 'Usage: rollback.sh [--check] /boot/config/plugins/ugreen-control/backup-VERSION/ugreen-control.plg\n' >&2; exit 2; }
TARGET=$1
/usr/bin/php -d auto_prepend_file= -r '
$p=realpath($argv[1]); $root="/boot/config/plugins/ugreen-control/";
if(!$p || !str_starts_with($p,$root."backup-") || basename($p)!=="ugreen-control.plg") exit(2);
$base=dirname($p); $files=glob($base."/ugreen-control-unraid-*.txz");
if(count($files)!==1) exit(3);
foreach([$p,$files[0]] as $file) {
  $sum=trim((string)@file_get_contents($file.".sha256"));
  if(!preg_match("/^[a-f0-9]{64}$/D",$sum) || !hash_equals($sum,hash_file("sha256",$file))) exit(4);
}
$xml=file_get_contents($p); $name=basename($files[0]);
if(!str_contains($xml,"\"".$name."\"") || !str_contains($xml,md5_file($files[0]))) exit(5);
$proc=proc_open(["/usr/bin/tar","-tJf",$files[0]],[0=>["pipe","r"],1=>["pipe","w"],2=>["pipe","w"]],$pipes);
if(!is_resource($proc)) exit(6);
fclose($pipes[0]); $listing=stream_get_contents($pipes[1]); fclose($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[2]);
if(proc_close($proc)!==0) exit(7);
foreach(explode("\n",trim($listing)) as $entry) {
  $entry=preg_replace("~^\./~","",$entry);
  if($entry==="" || $entry==="install/slack-desc") continue;
  if(str_starts_with($entry,"/") || in_array("..",explode("/",$entry),true) || !str_starts_with($entry,"usr/local/emhttp/plugins/ugreen-control/")) exit(8);
}
echo "Verified backup: ",$p,"\n";
' -- "$TARGET"
if $CHECK; then exit 0; fi
/usr/bin/php "$BASE/control.php" disable
# Both plugin-manager and Slackware upgradepkg independently reject downgrades.
# The backup and its single application package have already passed validation.
PACKAGES=("$(dirname -- "$TARGET")"/ugreen-control-unraid-*.txz)
/usr/bin/timeout --kill-after=2s 60s /sbin/upgradepkg --reinstall "${PACKAGES[0]}"
# plugin uses the file basename as registration ID; preserve canonical filename.
/usr/local/sbin/plugin install "$TARGET" forced
/usr/bin/php -d auto_prepend_file= -r '
$xml=file_get_contents($argv[1]);
if(!preg_match("/<!ENTITY version\\s+\"([A-Za-z0-9._-]+)\"/",$xml,$m)) exit(1);
if(trim((string)file_get_contents("/usr/local/emhttp/plugins/ugreen-control/version.txt"))!==$m[1]) exit(2);
echo "Application rollback verified; service remains disabled.\n";
' -- "$TARGET"
