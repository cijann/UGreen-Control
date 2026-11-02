#!/usr/bin/env python3
"""
UGreen Control Unraid Phase-1 packaging script.
Builds a txz package and a matching .plg install definition.

Usage:
    python3 scripts/build_unraid.py [--version VERSION]

Outputs to dist/:
    ugreen-control-unraid-<version>.txz
    ugreen-control-unraid-<version>.txz.md5
    ugreen-control.plg
"""
from __future__ import annotations
import argparse
import hashlib
import os
import shutil
import subprocess
import sys
import tarfile
import tempfile
from pathlib import Path

ROOT   = Path(__file__).resolve().parent.parent
DIST   = ROOT / 'dist'
UNRAID = ROOT / 'unraid'
WEB_UI = ROOT / 'web-ui'
DIST_UNRAID = WEB_UI / 'dist'

PLG_TEMPLATE = """\
<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
  <!ENTITY name        "ugreen-control">
  <!ENTITY version     "{version}">
  <!ENTITY md5         "{md5}">
  <!ENTITY url         "https://github.com/BearHero520/UGreen-Control">
  <!ENTITY txz         "ugreen-control-unraid-{version}.txz">
  <!ENTITY plugin      "/boot/config/plugins/&name;">
  <!ENTITY emhttp      "/usr/local/emhttp/plugins/&name;">
]>

<PLUGIN name="&name;" version="&version;" support="&url;" min="7.0.0">

<CHANGES>
### {version}
- Phase 1: read-only hardware overview, disk mapping, system telemetry.
</CHANGES>

<FILE Run="/bin/bash">
<INLINE>
if [ -f &emhttp;/scripts/control.php ]; then
  /usr/bin/php &emhttp;/scripts/control.php stop || exit 1
fi
</INLINE>
</FILE>

<FILE Name="&plugin;/&txz;" Run="upgradepkg --install-new">
<URL>&plugin;/&txz;</URL>
<MD5>&md5;</MD5>
</FILE>

<FILE Run="/bin/bash">
<INLINE>
echo "UGreen Control Phase 2 installed. LED service is started only by an explicit action or the delayed array event."
</INLINE>
</FILE>

<FILE Run="/bin/bash" Method="remove">
<INLINE>
echo "Removing UGreen Control..."
if [ -f &emhttp;/scripts/control.php ]; then
  /usr/bin/php &emhttp;/scripts/control.php disable || exit 1
fi
removepkg &name;-unraid-{version} 2>/dev/null || true
echo "UGreen Control removed; cached packages and saved configuration retained."
</INLINE>
</FILE>

</PLUGIN>
"""

def build_ui() -> None:
    env = {**os.environ, 'VITE_PLATFORM': 'unraid', 'VITE_UNRAID_PHASE': '2'}
    subprocess.run(
        ['npm', 'run', 'build', '--', '--outDir', str(DIST_UNRAID)],
        cwd=str(WEB_UI), env=env, check=True,
    )

def md5_file(path: Path) -> str:
    h = hashlib.md5()
    with open(path, 'rb') as f:
        for chunk in iter(lambda: f.read(65536), b''):
            h.update(chunk)
    return h.hexdigest()

def build_txz(version: str, tmp: Path) -> Path:
    pkg = tmp / 'pkg'
    # Unraid package layout: install/slack-desc + filesystem tree.
    (pkg / 'install').mkdir(parents=True)
    (pkg / 'usr/local/emhttp/plugins/ugreen-control/www').mkdir(parents=True)
    (pkg / 'boot/config/plugins/ugreen-control').mkdir(parents=True)

    # Slack package description.
    (pkg / 'install/slack-desc').write_text(
        'ugreen-control: UGreen Control (Unraid port)\n' * 11
    )

    # Plugin page, PHP API, version.
    target = pkg / 'usr/local/emhttp/plugins/ugreen-control'
    shutil.copytree(UNRAID / 'emhttp/plugins/ugreen-control', target, dirs_exist_ok=True)
    cli = ROOT / 'server/bin/ugreen_leds_cli'
    expected = '3d09aa29d7f6d1c90811d270cde6bd2218470a49fe989646804884477535e8e7'
    if hashlib.sha256(cli.read_bytes()).hexdigest() != expected:
        raise RuntimeError('LED CLI SHA256 mismatch')
    (target / 'server/bin').mkdir(parents=True, exist_ok=True)
    shutil.copy2(cli, target / 'server/bin/ugreen_leds_cli')
    shutil.copy2(ROOT / 'server/vendor/UGREEN-NAS-Hardware/led/LICENSE.upstream-led', target / 'server/bin/LICENSE.upstream-led')

    (pkg / 'usr/local/emhttp/plugins/ugreen-control/version.txt').write_text(version + '\n')
    shutil.copy2(ROOT / 'LICENSE', pkg / 'usr/local/emhttp/plugins/ugreen-control/LICENSE')

    # React SPA built output.
    if not (DIST_UNRAID / 'index.html').is_file():
        raise RuntimeError('Missing Unraid UI build; refusing incomplete package')
    if DIST_UNRAID.exists():
        shutil.copytree(
            DIST_UNRAID,
            pkg / 'usr/local/emhttp/plugins/ugreen-control/www',
            dirs_exist_ok=True,
        )

    txz_path = DIST / f'ugreen-control-unraid-{version}.txz'
    txz_path.parent.mkdir(parents=True, exist_ok=True)
    with tarfile.open(txz_path, 'w:xz') as tar:
        for root, dirs, files in os.walk(pkg):
            dirs.sort(); files.sort()
            for fname in files:
                full = Path(root) / fname
                arcname = full.relative_to(pkg)
                info = tar.gettarinfo(str(full), arcname=str(arcname))
                info.uid = info.gid = 0
                info.uname = info.gname = 'root'
                info.mode = 0o755 if fname.endswith('.sh') or fname == 'ugreen_leds_cli' or 'event' in arcname.parts else 0o644
                with full.open('rb') as data:
                    tar.addfile(info, data)
    return txz_path

def main() -> None:
    parser = argparse.ArgumentParser(description='Build UGreen Control Unraid package')
    parser.add_argument('--version', default='3.0.7-unraid-p1')
    parser.add_argument('--skip-ui', action='store_true', help='Skip npm build (use existing dist-unraid)')
    args = parser.parse_args()
    version = args.version

    print(f'Building UGreen Control Unraid Phase-1 package v{version}...')

    if not args.skip_ui:
        if not (WEB_UI / 'node_modules').exists():
            print('Running npm ci...')
            subprocess.run(['npm', 'ci'], cwd=str(WEB_UI), check=True)
        print('Building Unraid React SPA...')
        build_ui()
    else:
        print('Skipping UI build (--skip-ui)')

    with tempfile.TemporaryDirectory(prefix='ugreen-control-') as tmp:
        print('Creating .txz package...')
        txz = build_txz(version, Path(tmp))

    digest = md5_file(txz)
    md5_path = txz.with_suffix('.txz.md5')
    md5_path.write_text(f'{digest}  {txz.name}\n')

    plg_content = PLG_TEMPLATE.format(version=version, md5=digest)
    plg_path = DIST / 'ugreen-control.plg'
    plg_path.write_text(plg_content)

    print(f'\nDone.')
    print(f'  Package : {txz}')
    print(f'  MD5     : {digest}')
    print(f'  PLG     : {plg_path}')

if __name__ == '__main__':
    main()
