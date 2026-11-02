# UGreen Control for Unraid

绿联 NAS 的 Unraid 硬件监控与前面板 LED 控制插件，提供遥测看板、盘位映射和灯效设置。

## 验证范围与当前状态

- 仅实测 **DX4600 / Unraid 7.3.1 / 内核 `6.18.33-Unraid` / x86_64**。DX4600+、DX4600 Pro 及其他系统组合未经验证。
- 随包驱动仅面向 `6.18.33-Unraid`；其他内核跳过驱动安装及加载，不启动 LED 硬件服务。
- `3.0.7-unraid-p2.5` 已验证延迟等待取消、持久禁用、重复启动防重和应用回退。缺模块恢复分支通过隔离测试；尚未重启或卸载现用驱动验证完整冷启动流程。
- 尚未发布 Release。`dist/` 被 Git 忽略，未上传仓库，目前没有可用的在线 `.plg` 安装链接。

## 功能

- 集成 Unraid WebGUI，提供硬件遥测、物理盘位映射与手动校准。
- 提供电源、网络、四盘位 LED 的灯效设置及活动指示；实际效果取决于硬件和驱动状态。
- 显示可用的温度、风扇转速与 PWM 数据；阵列盘温度和休眠状态复用 Unraid 缓存。
- 支持阵列挂载后延迟启动、取消排队任务及持久禁用。

CLI 此前的 ACK 不可靠，目前只能观测失败现象，失败原因尚未定位，不能断言 `open` / `close` 是根因。延迟启动用于减少引导早期的硬件访问，不能保证消除启动卡死或内核调用阻塞。

## 离线构建与安装

本地需要 Node.js 20+、npm 与 Python 3.10+。在仓库根目录执行：

```bash
npm ci --prefix web-ui
python3 scripts/build_unraid.py --version 3.0.7-unraid-p2.5
```

`build_unraid.py` 负责设置 `VITE_PLATFORM=unraid`、`VITE_UNRAID_PHASE=2` 并执行 Vite 构建，再生成 `dist/` 中的 `.plg`、应用 `.txz` 和校验文件。不要用普通前端构建产物替代 Unraid 构建。

完成本次实现并检查构建产物后，离线安装步骤如下（仅供手动执行）：

1. 将同一次构建的 `ugreen-control.plg`、`ugreen-control-unraid-3.0.7-unraid-p2.5.txz` 及其校验文件复制到 `/boot/config/plugins/ugreen-control/`。
2. 在 Unraid 终端安装：

   ```bash
   plugin install /boot/config/plugins/ugreen-control/ugreen-control.plg
   ```

3. 在 **设置 (Settings) → 实用工具 (Utilities) → UGreen Control** 打开面板，先检查状态，再按需启动或排队。

安装阶段仅铺设文件，不执行 `modprobe`，不直接启动 LED 硬件服务。持久禁用标记存在时，重装或重启也不会解除禁用。

## 冷启动与延迟恢复流程

实现的启动流程如下；完整冷启动仍待实测：

1. 冷启动时，插件安装阶段仅铺文件；`event/disks_mounted` 在阵列挂载后，通过 `at` 排队延迟任务。默认等待 **300 秒**，允许范围 **60–3600 秒**；事件触发排队仍受 autostart 设置和禁用状态约束。
2. 到期后核验任务 generation 与禁用状态，再检查随插件 `drivers/` 打包的驱动包：

   ```text
   ugreen_leds-20260305-6.18.33-Unraid-1.txz
   SHA256: aa8ddd21ababb03bc2e792162150a225c69b9c4d290931b2584f9578835b9a2d
   ```

3. 校验驱动包 SHA256，并通过 `modinfo` 检查模块。缺模块时才执行 `installpkg` 和 `depmod`，随后重新检查模块；只有 `vermagic` 与当前内核精确匹配才允许加载。
4. 校验失败或内核不匹配时停止后续硬件启动。其他内核跳过随包驱动安装及加载；延长等待时间不会解决驱动不匹配。
5. `stop` / `disable` 使任务 generation 失效，阻止已排队或正在执行的旧任务继续后续步骤。已开始的内核调用不能强杀，也不能保证取消立刻生效；调用返回后停止后续步骤。

`stop` 保留当前灯态和已加载的驱动，不强制卸载模块。停止服务不等于关闭灯光或撤销已经完成的硬件操作。

## 检查、禁用与重新启动

以下命令在插件安装后于 Unraid 终端手动执行：

```bash
cd /usr/local/emhttp/plugins/ugreen-control
php scripts/control.php check
```

| 命令 | 行为 |
| --- | --- |
| `php scripts/control.php check` | 只读核验并报告状态，不安装、不加载、不启动。 |
| `php scripts/control.php stop` | 停止服务并使旧任务 generation 失效；保留灯态与已加载驱动。 |
| `php scripts/control.php disable` | 停止服务、取消旧任务，并持久禁用。 |
| `php scripts/control.php enable` | 解除持久禁用，不启动服务，保留现有 autostart 设置。 |
| `php scripts/control.php start` | 立即尝试启动，仍须通过禁用状态与驱动校验。 |
| `php scripts/control.php schedule` | 按配置延时排队，仍受禁用状态约束。 |

持久禁用标记为 `/boot/config/plugins/ugreen-control/DISABLED`，`enable` 移除此标记。需要恢复时先运行 `check` 查看报告，再 `enable`，最后选择 `start` 或 `schedule`；解除禁用本身不会启动服务。

旧版本的 `enable` 会同时开启 autostart；本次改为保留 autostart 原设置，是否随阵列挂载自动排队需单独确认。

## 应用回退

升级前由 `scripts/backup.php` 保存已安装的定义与应用包，并记录 SHA256（本次已建立 `backup-3.0.7-unraid-p2.3`）。先以实际备份版本替换 `<version>`，执行只读检查：

```bash
cd /usr/local/emhttp/plugins/ugreen-control
scripts/rollback.sh --check /boot/config/plugins/ugreen-control/backup-<version>/ugreen-control.plg
```

工具先校验备份 `.txz` 的 SHA256 sidecar、包内全部路径及待安装包的唯一性；检查失败时不继续安装。检查通过后，手动应用回退：

```bash
scripts/rollback.sh /boot/config/plugins/ugreen-control/backup-<version>/ugreen-control.plg
```

应用回退先执行 `disable`，再用 `plugin install` 安装备份的旧 `.plg`。仅回退应用，保留设置，不更换风扇控制组件，也不强制卸载已加载驱动；回退后保持禁用，检查状态后再决定是否解除禁用并启动。若内核调用仍在阻塞，回退也不能保证立即终止该调用。

## 目录

```text
unraid/emhttp/plugins/ugreen-control/
├── drivers/       # 本次随包驱动
├── lib/           # 后端与驱动核验
├── scripts/       # control.php、daemon.php、rollback.sh
└── event/         # disks_mounted、stopping_svcs
web-ui/            # React / TypeScript / Vite 前端
server/bin/        # 硬件控制程序
scripts/build_unraid.py
dist/              # 本地安装产物，Git 忽略
```

## 开源许可与致谢

- 本项目遵循 **GNU Affero General Public License v3.0 (AGPL-3.0)** 许可证。
- 硬件协议与底层逆向成果参考自社区先驱项目：
  - [miskcoo/ugreen_dx4600_leds_controller](https://github.com/miskcoo/ugreen_dx4600_leds_controller)
  - [BearHero520/UGreen-Control](https://github.com/BearHero520/UGreen-Control) 与 [UGREEN-NAS-Hardware](https://github.com/BearHero520/UGREEN-NAS-Hardware)
  - [ich777/unraid-ugreenleds-driver](https://github.com/ich777/unraid-ugreenleds-driver)
- 感谢所有为绿联 NAS 第三方固件生态做出贡献的开发者。
