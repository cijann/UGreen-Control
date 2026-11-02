#!/bin/bash
# at 拥有进程生命周期；禁止 shell 脱离。锁经 exec 保持到 PHP 和其 CLI 子进程全部退出。
set -euo pipefail
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
BASE=$(builtin cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
case "${1:-status}" in
  start|schedule|stop|status|disable|enable)
    exec /usr/bin/php "$BASE/control.php" "${1:-status}"
    ;;
  run|wait)
    [[ ${2:-} =~ ^[a-f0-9]{32}$ ]] || exit 2
    umask 077
    [[ -d /run/ugreen-control && ! -L /run/ugreen-control ]] || exit 1
    exec 9>/run/ugreen-control/exec.lock
    # 手动 start 替代正在等待的旧任务时，允许旧 generation 在检查点自行退出。
    /usr/bin/flock -w 10 9 || exit 1
    exec /usr/bin/php "$BASE/daemon.php" "$1" "$2"
    ;;
  *) exit 2 ;;
esac
