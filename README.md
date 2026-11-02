# UGreen Control for Unraid

专为运行在绿联 NAS（DX4600 / DX4600+ / DX4600 Pro 等系列机型）上的 **Unraid OS** 打造的硬件监控与前面板控制插件。

将硬件遥测看板、物理盘位状态监测与前面板 LED 控制集中到 Unraid 原生 WebGUI 界面，解决刷机后前面板灯光失效、缺乏读写活动指示的问题。

---

## 主要特性

- **🚀 原生 Unraid WebGUI 深度集成**
  - 位于 Unraid **设置 (Settings) → 实用工具 (Utilities)**，支持直接嵌入与独立全屏访问。
  - 遵循 Unraid 原生会话认证机制与 CSRF 安全防护，无须另开 Web 服务端口。

- **💡 前面板灯光与活动指示**
  - 支持电源灯、网络灯、四盘位硬盘灯的开关、颜色、亮度及动态灯效配置。
  - 实时根据硬盘读写吞吐、网络流量变化触发智能闪烁（活动提示）。
  - 区分硬盘活动（Active）、空闲（Idle）、休眠（Standby）与离线（Offline）灯效。

- **🌡️ 硬件遥测与健康看板**
  - 实时读取 CPU 温度（coretemp）、主板芯片（IT8613/IT8620）及系统风扇实时转速（RPM）与当前 PWM。
  - **低唤醒设计**：阵列硬盘温度与休眠状态直接复用 Unraid 内存缓存，避免频繁查询轮询导致休眠中的数据盘被误唤醒。

- **🛡️ 开机时序安全守护（规避引导故障）**
  - 内置开机延迟加载机制：等待 Unraid 阵列就绪（`disks_mounted`）且系统完全稳定后，再在后台延时加载硬件服务。
  - 彻底规避在系统引导早期访问 I²C/MCU 硬件或加载内核模块引发的启动卡死、无法进入系统风险（如开源社区记录的 issue #87）。

- **📊 物理盘位映射识别**
  - 自动识别 PCI 与原生 SATA 端口拓扑，区分 Unraid 逻辑阵列盘符与物理盘仓位置。
  - 支持手动序列号校准，避免硬盘盘符漂移导致灯位与实体盘位不对应。

---

## 系统要求

- **操作系统**：Unraid OS 7.0.0 及以上（推荐 7.2+ / 7.3+，已在 Unraid 7.3.1 实机验证）
- **机型**：绿联 DX4600 / DX4600+ / DX4600 Pro（其他机型支持正在适配中）
- **架构**：x86_64

---

## 安装方法

### 方式一：通过 Unraid WebGUI 插件管理器安装

1. 打开 Unraid 管理网页，进入 **插件 (Plugins)** 标签页；
2. 切换到 **安装插件 (Install Plugin)** 子页面；
3. 在输入框中粘贴本插件的 `.plg` 链接：
   ```text
   https://raw.githubusercontent.com/cijann/UGreen-Control/main/dist/ugreen-control.plg
   ```
4. 点击 **安装 (Install)**，等待安装完成；
5. 安装成功后，可在 **设置 (Settings) → 实用工具 (Utilities) → UGreen Control** 打开控制面板。

### 方式二：手动离线安装

1. 从本仓库的 `dist/` 目录下载 `ugreen-control.plg` 和 `ugreen-control-unraid-*.txz`；
2. 将文件上传至 Unraid 引导盘：
   ```bash
   /boot/config/plugins/ugreen-control/
   ```
3. 在 Unraid 终端中执行安装命令：
   ```bash
   plugin install /boot/config/plugins/ugreen-control/ugreen-control.plg
   ```

---

## 架构与目录设计

```text
├── unraid/                     # Unraid 原生插件集成目录
│   └── emhttp/plugins/ugreen-control/
│       ├── UGreen.Control.page # Unraid WebGUI 菜单入口定义
│       ├── page.php            # 原生 iframe 页面渲染器
│       ├── api.php             # 内部受保护的 REST API（会话鉴权 + CSRF 验证）
│       ├── lib/                # 后端核心业务逻辑（设置管理、硬件控制、服务调度）
│       ├── scripts/            # 守护进程与生命周期控制脚本 (control.php / daemon.php)
│       └── event/              # Unraid 阵列事件钩子 (disks_mounted / stopping_svcs)
├── web-ui/                     # 基于 React + TypeScript + Vite 打造的管理面板源码
│   ├── src/                    # 状态监控、设备映射、灯效设置组件
│   └── dist/                   # 独立构建的前端静态资源
├── server/bin/                 # 预编译硬件底座程序 (ugreen_leds_cli / ugreenctl)
├── scripts/
│   └── build_unraid.py         # 专用的 Unraid .txz 包与 .plg 自动化构建打包工具
└── dist/                       # 最终打包生成的 Unraid 插件安装产物
```

---

## 本地开发与打包

需要 Node.js 20+ 与 Python 3.10+：

```bash
# 1. 安装前端依赖并构建
cd web-ui
npm install
npm run build
cd ..

# 2. 生成 Unraid 插件包与 .plg 文件
python3 scripts/build_unraid.py --version 3.0.7-unraid-p2.0
```

打包产物将自动输出到 `dist/` 目录。

---

## 开源许可与致谢

- 本项目遵循 **GNU Affero General Public License v3.0 (AGPL-3.0)** 许可证。
- 硬件协议与底层逆向成果参考自社区先驱项目：
  - [miskcoo/ugreen_dx4600_leds_controller](https://github.com/miskcoo/ugreen_dx4600_leds_controller)
  - [BearHero520/UGreen-Control](https://github.com/BearHero520/UGreen-Control) 与 [UGREEN-NAS-Hardware](https://github.com/BearHero520/UGREEN-NAS-Hardware)
  - [ich777/unraid-ugreenleds-driver](https://github.com/ich777/unraid-ugreenleds-driver)
- 感谢所有为绿联 NAS 第三方固件生态做出贡献的开发者！
