import { Dialog } from '@base-ui/react/dialog';
import { useCallback, useEffect, useRef, useState } from 'react';
import { api } from '../lib/api';
import { isReadOnly, platform, readOnlyMessage, phase2Message } from '../lib/platform';
import { singleFlight, settledValues } from '../lib/singleFlight';
import { boundedInt, parseIni, type IniData } from '../lib/ini';
import { AboutPage, type AboutInfo } from './AboutPage';
import { ActivityPage } from './ActivityPage';
import { BiosPage } from './BiosPage';
import { DevicesPage } from './DevicesPage';
import { LightingPage } from './LightingPage';
import { OverviewPage, type MappingItem, type StatusData } from './OverviewPage';
import type { HardwareMonitorData } from './HardwareOverview';

type Route = 'overview' | 'lighting' | 'activity' | 'devices' | 'bios' | 'about';
type Toast = { message: string; type: 'ok' | 'err' } | null;
type HardwareData = HardwareMonitorData;
type BiosData = Record<string, unknown>;

const routeInfo: Record<Route, { title: string; description: string }> = {
  overview: { title: '概览', description: isReadOnly ? '查看 Unraid 网络、磁盘与只读状态。' : '查看灯控服务、实时网速与硬盘活动。' },
  lighting: { title: '灯光设置', description: isReadOnly ? '灯光控制尚未接入。' : '配置硬盘、网络和电源灯的状态颜色。' },
  activity: { title: '活动提示', description: isReadOnly ? '活动灯策略尚未接入。' : '控制磁盘读写和网络流量的速度闪动。' },
  devices: { title: '设备与高级', description: platform.phase2 ? '管理 LED 服务、延迟自启与灯位映射，查看硬件及诊断日志。' : isReadOnly ? '只读查看磁盘映射、机型识别与诊断日志。' : '管理盘位映射、机型后端、监测频率与诊断日志。' },
  bios: { title: 'BIOS 控制', description: platform.unraid ? 'BIOS 控制尚未接入，所有硬件写操作均已禁用。' : '安全管理已支持机型的风扇、来电启动、网络唤醒与 RTC 定时开机。' },
  about: { title: '关于', description: platform.unraid ? `Unraid port 第${platform.phase2 ? '二' : '一'}阶段范围、上游作者与开源许可。` : '查看应用版本、更新、测试群与 GitHub 项目说明。' },
};
const navItems: Array<{ route: Route; label: string; icon: string; warning?: string }> = [
  { route: 'overview', label: '概览', icon: 'bi-grid-1x2' }, { route: 'lighting', label: '灯光设置', icon: 'bi-lightbulb' }, { route: 'activity', label: '活动提示', icon: 'bi-activity' }, { route: 'devices', label: '设备与高级', icon: 'bi-hdd-rack' }, { route: 'bios', label: 'BIOS 控制', icon: 'bi-motherboard' }, { route: 'about', label: '关于', icon: 'bi-info-circle' },
];
const diskStates = [
  { key: 'active', color: '0 255 0', brightness: 128 }, { key: 'idle', color: '255 255 0', brightness: 64 }, { key: 'standby', color: '0 100 255', brightness: 40 }, { key: 'deep_sleep', color: '40 0 80', brightness: 24 }, { key: 'offline', color: 'off', brightness: 0 },
];
const networkStates = [
  { key: 'disconnected', color: '255 0 0', brightness: 64 }, { key: 'connected', color: '0 80 255', brightness: 48 }, { key: 'vpn', color: '160 0 255', brightness: 64 },
];
const modeLabels: Record<string, string> = { off: '关闭全部', on: '开启全部', smart: '智能模式' };

function normalizeRoute(value: string): Route { return value in routeInfo ? value as Route : 'overview'; }

function NavButton({ item, active, available, onNavigate }: { item: typeof navItems[number]; active: boolean; available: boolean; onNavigate: (route: Route) => void }) {
  return <button type="button" className={`nav-item${active ? ' active' : ''}`} aria-label={item.label} aria-current={active ? 'page' : undefined} hidden={!available} onClick={() => onNavigate(item.route)}><i className={`bi ${item.icon}`} aria-hidden="true" /><span>{item.label}</span>{item.warning ? <small>{item.warning}</small> : null}</button>;
}

function serviceLabel(running: boolean | null, state?: string) {
  if (isReadOnly) return running === null ? '等待读取状态' : 'Unraid · 只读';
  if (running === null) return '服务状态未连接';
  return ({ running: '服务运行中', waiting: '等待延迟启动', queued: '启动已排队', stopped: '后台已停止', error: '服务异常' } as Record<string, string>)[state || ''] || (running ? '服务运行中' : '后台已停止');
}

function PageHeader({ route, running, state, onRefresh, refreshing }: { route: Route; running: boolean | null; state?: string; onRefresh: () => void; refreshing: boolean }) {
  const dotClass = `status-dot${!isReadOnly && running === true ? ' online' : !isReadOnly && running === false ? ' offline' : ''}`;
  return <header className="page-header"><div><p className="eyebrow">UGREEN NAS SYSTEM TOOLBOX · {platform.name}</p><h1>{routeInfo[route].title}</h1><p className="page-description">{routeInfo[route].description}</p></div><div className="header-actions"><Dialog.Root><Dialog.Trigger className="icon-button" title="界面说明" aria-label="界面说明"><i className="bi bi-question-circle" aria-hidden="true" /></Dialog.Trigger><Dialog.Portal><Dialog.Backdrop className="newui-dialog-backdrop" /><Dialog.Popup className="newui-dialog-popup"><Dialog.Title>UGreen Control</Dialog.Title><Dialog.Description>{platform.unraid ? (platform.phase2 ? phase2Message : readOnlyMessage) : '灯光请求经 CGI API 转交内置 CLI；BIOS 请求仅转交 UGREEN-NAS-Hardware，上层界面不会绕过硬件安全保护。'}</Dialog.Description><div className="newui-dialog-actions"><Dialog.Close className="secondary-button">知道了</Dialog.Close></div></Dialog.Popup></Dialog.Portal></Dialog.Root><button type="button" className={`icon-button${refreshing ? ' busy' : ''}`} title="刷新状态" aria-label="刷新状态" disabled={refreshing} onClick={onRefresh}><i className="bi bi-arrow-clockwise" aria-hidden="true" /></button><span className="status-pill"><span className={dotClass} /><span>{serviceLabel(running, state)}</span></span></div></header>;
}

function ModeDock({ mode, disabled, onModeChange }: { mode: string; disabled: boolean; onModeChange: (mode: string) => void }) {
  const modes = [['off', 'bi-power', '关闭全部'], ['on', 'bi-brightness-high', '开启全部'], ['smart', 'bi-stars', '智能模式']] as const;
  return <div className="mode-dock" aria-label="灯光模式"><span className="dock-label">{isReadOnly ? '只读' : '模式'}</span>{modes.map(([value, icon, label]) => <button key={value} type="button" disabled={isReadOnly || disabled} title={isReadOnly ? readOnlyMessage : disabled ? '等待服务连接与 LED 能力确认' : undefined} className={`mode-button${value === 'smart' ? ' primary' : ''}${!isReadOnly && mode === value ? ' active' : ''}`} aria-pressed={!isReadOnly && mode === value} onClick={() => onModeChange(value)}><i className={`bi ${icon}`} aria-hidden="true" /><span>{label}</span></button>)}</div>;
}

function UnavailablePanel({ route }: { route: 'lighting' | 'activity' | 'bios' }) {
  return <section className="react-view" aria-label={routeInfo[route].title}><article className="glass-surface readonly-panel"><span className="soft-badge">Unraid · 未接入 / 只读</span><h2>{routeInfo[route].title}尚未接入</h2><p>{platform.phase2 ? phase2Message : readOnlyMessage}</p><p>{route === 'bios' ? '风扇、来电启动、网络唤醒与 RTC 定时开机均不可修改。可在设备与高级页面下载只读硬件诊断。' : '当前不启动灯控服务、不应用颜色或活动策略，也不接管现有硬件控制。'}</p></article></section>;
}

function lightingSettingsLines(settings: IniData) {
  const lines: string[] = [];
  for (const state of diskStates) { lines.push(`disk_colors.${state.key}=${state.key === 'offline' ? 'off' : settings.disk_colors?.[state.key] || state.color}`); lines.push(`disk_brightness.${state.key}=${boundedInt(settings.disk_brightness?.[state.key], 0, 255, state.brightness)}`); }
  for (const state of networkStates) { lines.push(`netdev_colors.${state.key}=${settings.netdev_colors?.[state.key] || state.color}`); lines.push(`netdev_brightness.${state.key}=${boundedInt(settings.netdev_brightness?.[state.key], 0, 255, state.brightness)}`); }
  const power = settings.power || {};
  lines.push(`power.smart_color=${power.smart_color || '100 100 100'}`, `power.all_on_color=${power.all_on_color || '180 180 180'}`, `power.brightness=${boundedInt(power.brightness, 0, 255, 40)}`);
  if (platform.phase2) lines.push(`behavior.manage_power=${settings.behavior?.manage_power !== 'false'}`, `behavior.manage_netdev=${settings.behavior?.manage_netdev !== 'false'}`);
  return lines;
}

export function App() {
  const [route, setRoute] = useState<Route>(() => normalizeRoute(location.hash.slice(1)));
  const [status, setStatus] = useState<StatusData | null>(null);
  const [mapping, setMapping] = useState<MappingItem[]>([]);
  const [settings, setSettings] = useState<IniData>({});
  const [hardware, setHardware] = useState<HardwareData>({});
  const [bios, setBios] = useState<BiosData | null>(null);
  const [aboutInfo, setAboutInfo] = useState<AboutInfo | null>(null);
  const [refreshing, setRefreshing] = useState(false);
  const [settingsLoaded, setSettingsLoaded] = useState(false);
  const [connected, setConnected] = useState(false);
  const [modeBusy, setModeBusy] = useState(false);
  const [toast, setToast] = useState<Toast>(null);
  const toastTimer = useRef<number | undefined>(undefined);
  const showToast = useCallback((message: string, type: 'ok' | 'err' = 'ok') => { if (toastTimer.current) window.clearTimeout(toastTimer.current); setToast({ message, type }); toastTimer.current = window.setTimeout(() => setToast(null), 3200); }, []);

  const [refreshOnce] = useState(() => singleFlight(async () => {
    setRefreshing(true);
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 30000);
    const options = { signal: controller.signal };
    try {
      const [nextStatus, nextMapping, nextHardware, nextBios] = await settledValues([api<StatusData>('/status', options), api<{ mapping?: MappingItem[] }>('/mapping', options), api<HardwareData>('/hardware/status', options), platform.capabilities.bios ? api<BiosData>('/bios/status', options) : Promise.resolve(null)] as const);
      const daemonState = nextStatus.daemon || nextStatus.service?.state || nextStatus.daemon_state?.state;
      if (platform.phase2 && (nextStatus.ok !== true || !['running', 'waiting', 'queued', 'stopped', 'error'].includes(daemonState || ''))) throw new Error('服务未返回有效状态');
      setStatus({ ...nextStatus, daemon: daemonState }); setMapping(nextMapping.mapping || []); setHardware(nextHardware); setBios(nextBios); setConnected(true);
    } catch (error) { setConnected(false); setStatus(null); throw error; } finally { window.clearTimeout(timeout); setRefreshing(false); }
  }));
  const refresh = useCallback(async (notifyOnError = false) => {
    try { await refreshOnce(); }
    catch (error) { if (notifyOnError) showToast(error instanceof Error ? error.message : '状态刷新失败', 'err'); }
  }, [refreshOnce, showToast]);
  const loadSettings = useCallback(async () => {
    try {
      const data = await api<{ raw?: string }>('/settings');
      if (platform.phase2 && typeof data.raw !== 'string') throw new Error('服务未返回有效设置');
      setSettings(parseIni(data.raw || '')); setSettingsLoaded(true);
    } catch (error) { setSettingsLoaded(false); throw error; }
  }, []);
  useEffect(() => { const syncRoute = () => setRoute(normalizeRoute(location.hash.slice(1))); window.addEventListener('hashchange', syncRoute); return () => window.removeEventListener('hashchange', syncRoute); }, []);
  useEffect(() => { void loadSettings().catch((error) => showToast(error instanceof Error ? error.message : '读取设置失败', 'err')); void refresh(); const interval = window.setInterval(() => { if (!document.hidden) void refresh(); }, 10000); const onVisible = () => { if (!document.hidden) void refresh(); }; document.addEventListener('visibilitychange', onVisible); return () => { window.clearInterval(interval); document.removeEventListener('visibilitychange', onVisible); }; }, [loadSettings, refresh, showToast]);
  useEffect(() => { void api<AboutInfo>('/about/info').then(setAboutInfo).catch(() => setAboutInfo(null)); }, []);
  useEffect(() => () => { if (toastTimer.current) window.clearTimeout(toastTimer.current); }, []);

  const navigate = useCallback((next: Route) => { setRoute(next); if (location.hash !== `#${next}`) location.hash = next; document.getElementById('appMain')?.scrollTo({ top: 0, behavior: 'instant' }); }, []);
  const lightingAvailable = !platform.unraid || (platform.phase2 && connected && settingsLoaded && hardware.capabilities?.lighting === true);
  const saveSettings = useCallback(async (lines: string[], successMessage: string) => {
    if (!lightingAvailable) throw new Error('服务未连接或尚未确认 LED 能力，无法保存');
    await api('/settings', { method: 'POST', body: lines.join('\n') });
    if (!platform.unraid) await api('/daemon/start', { method: 'POST', body: '' });
    await Promise.all([loadSettings(), refreshOnce()]); showToast(successMessage);
  }, [lightingAvailable, loadSettings, refreshOnce, showToast]);
  const setMode = useCallback(async (mode: string) => {
    if (!modeLabels[mode] || !lightingAvailable || modeBusy) return;
    setModeBusy(true);
    try {
      await api('/mode', { method: 'POST', query: `mode=${encodeURIComponent(mode)}`, body: '' });
      await refreshOnce();
      showToast(platform.phase2 ? '模式已保存，灯光执行状态请查看服务状态' : `已切换到${modeLabels[mode]}`);
    } catch (error) { showToast(error instanceof Error ? error.message : '模式切换失败', 'err'); } finally { setModeBusy(false); }
  }, [lightingAvailable, modeBusy, refreshOnce, showToast]);
  const saveLighting = useCallback(async () => {
    try { await saveSettings(lightingSettingsLines(settings), '灯光设置已保存'); }
    catch (error) { showToast(error instanceof Error ? error.message : '保存灯光设置失败', 'err'); throw error; }
  }, [saveSettings, settings, showToast]);
  const applyPower26 = useCallback(async (color: string, effect: string, threshold: number, off: boolean) => { const data = await api<{ message?: string; mode?: string }>('/power26/apply', { method: 'POST', query: `color=${encodeURIComponent(color)}&effect=${encodeURIComponent(off ? 'off' : effect)}&threshold=${threshold}`, body: '' }); setStatus((current) => ({ ...current, mode: data.mode || (off ? 'off' : 'on') })); await Promise.all([loadSettings(), refresh()]); showToast(data.message || (off ? '电源灯已关闭' : '480T 电源灯设置已应用')); }, [loadSettings, refresh, showToast]);
  const power26 = !platform.unraid && hardware.profile === 'dxp480t_plus'; const state = status?.daemon; const running = status ? status.daemon === 'running' : null; const currentMode = status?.mode || (platform.unraid ? '' : settings.mode?.global || 'smart');
  const railDotClass = `status-dot${!isReadOnly && running === true ? ' online' : !isReadOnly && running === false ? ' offline' : ''}`;

  return <div className={`app-shell${platform.unraid ? ' unraid' : ''}`}>
    <aside className="app-rail" aria-label="主导航">
      <div className="brand-block"><img src="./images/logo.png?v=2.1.0-toolbox" alt="UGreen Control" className="brand-logo" width="48" height="48" /><div className="brand-copy"><strong>UGreen Control</strong><span>BearHero · {platform.name}</span></div></div>
      <nav className="rail-nav">{navItems.map((item) => <NavButton key={item.route} item={item} active={item.route === route} available={platform.unraid || item.route !== 'bios' || Boolean(bios?.supported)} onNavigate={navigate} />)}</nav>
      <div className="rail-footer"><span className="rail-state"><span className={railDotClass} /><span>{serviceLabel(running, state)}</span></span><small>v{aboutInfo?.version || '—'} · BearHero</small></div>
    </aside>
    <main className="app-main" id="appMain">
      <PageHeader key={route} route={route} running={running} state={state} refreshing={refreshing} onRefresh={() => void refresh(true)} />
      {isReadOnly && <div className="readonly-banner" role="note"><i className="bi bi-shield-lock" aria-hidden="true" /><div><strong>Unraid port · 第一阶段 · 只读</strong><p>{readOnlyMessage}</p></div></div>}
      {platform.phase2 && <div className="readonly-banner" role="note"><i className="bi bi-lightbulb" aria-hidden="true" /><div><strong>Unraid port · 第二阶段 · LED 控制</strong><p>{phase2Message}</p></div></div>}
      <div className={`action-message${toast ? ` visible ${toast.type}` : ''}`} aria-live="polite">{toast?.message}</div>
      <OverviewPage hidden={route !== 'overview'} status={status} mapping={mapping} hardware={hardware} onDevices={() => navigate('devices')} />
      {isReadOnly ? ((route === 'lighting' || route === 'activity') && <UnavailablePanel route={route} />) : <>
        {platform.phase2 && !lightingAvailable && (route === 'lighting' || route === 'activity') && <p role="status">等待服务连接、设置读取与 LED 能力确认；暂不可写入。</p>}
        <fieldset disabled={!lightingAvailable} className="capability-fields" hidden={route !== 'lighting' && route !== 'activity'}>
          <LightingPage hidden={route !== 'lighting'} settings={settings} power26={power26} onSettingsChange={setSettings} onSave={saveLighting} onPower26Apply={applyPower26} onToast={showToast} />
          <ActivityPage hidden={route !== 'activity'} settings={settings} onSettingsChange={setSettings} onSave={saveSettings} onToast={showToast} />
        </fieldset>
      </>}
      {platform.capabilities.bios ? <BiosPage hidden={route !== 'bios'} bios={bios} hardware={hardware} onRefresh={refresh} onToast={showToast} /> : route === 'bios' && <UnavailablePanel route="bios" />}
      <DevicesPage hidden={route !== 'devices'} mapping={mapping} hardware={hardware} settings={settings} status={status} writable={lightingAvailable} onSettingsChange={setSettings} onSaveSettings={saveSettings} onLoadSettings={loadSettings} onRefresh={refreshOnce} onToast={showToast} />
      <AboutPage hidden={route !== 'about'} info={aboutInfo} onToast={showToast} />
    </main>
    {(route === 'overview' || route === 'lighting') && <ModeDock mode={currentMode} disabled={!lightingAvailable || modeBusy} onModeChange={(mode) => void setMode(mode)} />}
  </div>;
}
