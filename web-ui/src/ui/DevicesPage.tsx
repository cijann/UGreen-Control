import { GlassSelect } from "./GlassSelect";
import { useCallback, useEffect, useState } from 'react';
import { api } from '../lib/api';
import { platform } from '../lib/platform';
import { boundedInt, formatRate, mergeIni, type IniData } from '../lib/ini';
import type { MappingItem, StatusData } from './OverviewPage';

type HardwareData = Record<string, unknown>;
type LogData = { content?: string; write_level?: string; requested_lines?: number; size_bytes?: number; updated_at?: number; clipped?: boolean };
type Toast = (message: string, type?: 'ok' | 'err') => void;

const profiles = [
  ['auto', '自动识别'], ['dx4600', 'UGREEN DX4600 / Plus / Pro'], ['dx4700', 'UGREEN DX4700+'], ['dxp2800', 'UGREEN DXP2800'], ['dxp2800_gt', 'UGREEN DXP2800 GT（待验证）'], ['dxp4800', 'UGREEN DXP4800'], ['dxp4800_plus', 'UGREEN DXP4800 Plus'], ['dxp4800_pro', 'UGREEN DXP4800 Pro（待验证）'], ['dxp4800s', 'UGREEN DXP4800S（BIOS 控制固件逆向）'], ['dxp4800_gt', 'UGREEN DXP4800 GT（实验性）'], ['dxp6800', 'UGREEN DXP6800 Pro'], ['dxp8800', 'UGREEN DXP8800 Plus'], ['dxp480t_plus', 'UGREEN DXP480T / Plus（仅电源灯）'], ['idx6011', 'UGREEN iDX6011（实验性）'], ['idx6011_pro', 'UGREEN iDX6011 Pro（实验性）'],
] as const;
const diskLabels: Record<string, string> = { active: '活动', idle: '空闲', standby: '休眠', deep_sleep: '深度睡眠', offline: '离线', unknown: '未知', error: '异常', '?': '未知' };
const supportLabels: Record<string, string> = { stable: '已验证', experimental: '实验性', unverified: '待验证', limited: '受限支持', unsupported: '暂不支持', unknown: '未知机型' };

function downloadText(content: string, filename: string) {
  const blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url; link.download = filename; document.body.append(link); link.click(); link.remove(); URL.revokeObjectURL(url);
}

function bytes(value: unknown) {
  const size = Math.max(0, Number(value) || 0);
  if (size >= 1024 * 1024) return `${(size / 1024 / 1024).toFixed(2)} MiB`;
  if (size >= 1024) return `${(size / 1024).toFixed(1)} KiB`;
  return `${size} B`;
}

function StateChip({ state }: { state?: string }) {
  const value = String(state || 'unknown').trim();
  return <span className={`state-chip ${value || 'unknown'}`}>{diskLabels[value] || value || '未知'}</span>;
}

export function DevicesPage({
  hidden, mapping, hardware, settings, status, writable = !platform.unraid, onSettingsChange, onSaveSettings, onLoadSettings, onRefresh, onToast,
}: {
  hidden: boolean;
  mapping: MappingItem[];
  hardware: HardwareData;
  settings: IniData;
  status?: StatusData | null;
  writable?: boolean;
  onLoadSettings?: () => Promise<void>;
  onSettingsChange: (settings: IniData) => void;
  onSaveSettings: (lines: string[], successMessage: string) => Promise<void>;
  onRefresh: () => Promise<void>;
  onToast: Toast;
}) {
  const [logs, setLogs] = useState<LogData | null>(null);
  const [logError, setLogError] = useState('');
  const [filter, setFilter] = useState('all');
  const [lineCount, setLineCount] = useState('500');
  const [writeLevel, setWriteLevel] = useState('info');
  const [busy, setBusy] = useState<string | null>(null);
  const [serialMap, setSerialMap] = useState<Record<string, string>>({});
  const service = status?.service || status?.daemon_state;
  const startupSection = settings.startup ? 'startup' : 'autostart';
  const startup = settings[startupSection] || {};
  const autostart = startup.enabled !== undefined ? startup.enabled === 'true' : service?.autostart === true;
  const delay = boundedInt(startup.delay_seconds ?? service?.delay_seconds, 60, 3600, 300);
  const serviceState = status?.daemon || service?.state;
  const waiting = serviceState === 'waiting' || serviceState === 'queued';
  const settingsHardware = settings.hardware || {};
  const profile = settingsHardware.profile || 'auto';
  const backend = settingsHardware.backend || 'cli';
  const protocol = 'auto';
  const probeInterval = boundedInt(settings.daemon?.disk_power_probe_interval, 10, 3600, 60);
  const hotplugInterval = boundedInt(settings.daemon?.hotplug_check_interval, 5, 3600, 30);

  const loadLogs = useCallback(async () => {
    const data = await api<LogData>(`/logs`, { query: `source=application&level=${encodeURIComponent(filter)}&lines=${encodeURIComponent(lineCount)}` });
    setLogs(data); setLogError(''); setWriteLevel(String(data.write_level || 'info'));
  }, [filter, lineCount]);
  useEffect(() => { if (!hidden) void loadLogs().catch((error) => setLogError(error instanceof Error ? error.message : '日志读取失败')); }, [hidden, loadLogs]);

  const withBusy = async (name: string, action: () => Promise<void>) => {
    if (busy) return;
    setBusy(name);
    try { await action(); } catch (error) { onToast(error instanceof Error ? error.message : '操作失败', 'err'); } finally { setBusy(null); }
  };
  const updateSettings = (section: string, patch: Record<string, string>) => onSettingsChange(mergeIni(settings, { [section]: patch }));
  const saveHardware = async () => {
    if (backend === 'cli' && Boolean(hardware.driver_conflict)) { onToast('内核驱动仍在占用 MCU，请先卸载驱动并切回 CLI', 'err'); return; }
    await onSaveSettings([`hardware.backend=${backend}`, `hardware.profile=${profile}`, `hardware.write_protocol=${protocol}`], '硬件设置已保存');
  };
  const currentBackend = platform.readOnly ? '未接管（只读）' : hardware.backend_active === 'sysfs' ? '内核驱动 / sysfs' : hardware.backend_active === 'power-0x26' ? 'N76E003 电源灯控制' : hardware.backend_active === 'cli' ? '内置 CLI' : '不可用';
  const hardwareMessage = [
    hardware.led_plugin_conflict ? `检测到可能占用 LED 控制器的模块：${hardware.led_plugin_modules || '未知模块'}。请停用后重启灯光服务。` : '',
    hardware.driver_conflict ? '检测到厂商 LED 内核模块，可能与内置 CLI 争用控制器。' : '',
    ['experimental', 'unverified'].includes(String(hardware.support)) ? '该机型需要实机验证，请确认检测到的机型及盘位映射。' : '',
  ].filter(Boolean).join(' ');

  return <section className="react-view" hidden={hidden} aria-label="设备与高级">
    {platform.unraid && <div className="hardware-message" role="note">{platform.phase2 ? '第二阶段可管理 LED 服务、延迟自启和序列号灯位绑定；机型、写协议、驱动安装和日志写操作尚未接入。' : '仅展示检测结果与日志。服务启动/停止、重新映射、硬件设置、监测频率、日志记录级别及清理均不可操作；日志刷新与诊断下载仅使用 GET。'}</div>}
    <div className="device-layout">
      {platform.phase2 && <article className="glass-surface service-surface">
        <div className="section-heading"><div><span className="section-kicker">LED SERVICE</span><h2>灯控服务与延迟自启</h2></div><span className="soft-badge">{({ running: '运行中', waiting: '等待延迟启动', queued: '启动已排队', stopped: '已停止', error: '异常' } as Record<string, string>)[serviceState || ''] || '未连接'}</span></div>
        <p className="surface-help">立即启动跳过延迟；停止或取消等待会取消排队任务，保留最后灯态。需要熄灯请使用“关闭全部”。</p>
        {service?.error && <p role="alert">{service.error}</p>}
        <div className="service-actions">
          <button type="button" className="primary-button" disabled={!writable || Boolean(busy) || serviceState === 'running'} onClick={() => void withBusy('start', async () => { await api('/daemon/start', { method: 'POST', body: '' }); await onRefresh(); onToast('启动请求已接受，请查看服务状态'); })}>立即启动</button>
          <button type="button" className="secondary-button" disabled={!writable || Boolean(busy)} onClick={() => void withBusy('stop', async () => { await api('/daemon/stop', { method: 'POST', body: '' }); await Promise.all([onRefresh(), onLoadSettings?.()]); onToast('停止请求已处理，排队任务已取消'); })}>{waiting ? '取消等待' : '停止服务'}</button>
        </div>
        <fieldset className="capability-fields" disabled={!writable || Boolean(busy)}>
          <label className="native-check"><input type="checkbox" checked={autostart} onChange={(event) => updateSettings(startupSection, { enabled: String(event.target.checked) })} /><span>阵列启动后自动启动灯控</span></label>
          <label className="threshold-field"><span>启动延迟（60–3600 秒，默认 300）</span><span className="number-wrap"><input aria-label="启动延迟秒数" type="number" min="60" max="3600" step="1" value={delay} onChange={(event) => updateSettings(startupSection, { delay_seconds: String(boundedInt(event.target.value, 60, 3600, 300)) })} /><em>秒</em></span></label>
          <button type="button" className="primary-button" onClick={() => void withBusy('autostart', async () => { await api('/autostart', { method: 'POST', body: JSON.stringify({ enabled: autostart, delay_seconds: delay }), contentType: 'application/json' }); await Promise.all([onRefresh(), onLoadSettings?.()]); onToast('延迟自启设置已保存'); })}>保存延迟自启</button>
        </fieldset>
      </article>}
      {platform.phase2 && <article className="glass-surface serial-map-surface">
        <div className="section-heading"><div><span className="section-kicker">SERIAL MAPPING</span><h2>灯位序列号绑定</h2></div></div>
        <p className="surface-help">输入 Unraid 中当前 SATA 硬盘的完整序列号，绑定到实际灯位。自动映射仅为端口推定；请依据实际灯位核对。空项不绑定，保存会替换已有绑定。</p>
        <fieldset className="capability-fields" disabled={!writable || Boolean(busy)}>
          {[1, 2, 3, 4].map((slot) => { const key = `disk${slot}`; return <label className="threshold-field" key={key}><span>灯位 {slot}</span><input className="serial-input" aria-label={`灯位 ${slot} 序列号`} type="text" maxLength={128} autoComplete="off" value={serialMap[key] ?? settings.disk_map?.[key] ?? ''} onChange={(event) => setSerialMap((current) => ({ ...current, [key]: event.target.value }))} /></label>; })}
          <div className="service-actions"><button type="button" className="primary-button" onClick={() => void withBusy('serial', async () => {
            const disk_map = Object.fromEntries([1, 2, 3, 4].map((slot) => { const key = `disk${slot}`; return [key, (serialMap[key] ?? settings.disk_map?.[key] ?? '').trim()]; }).filter(([, serial]) => serial));
            if (!Object.keys(disk_map).length) throw new Error('请至少填写一个序列号，或使用恢复自动映射');
            if (new Set(Object.values(disk_map)).size !== Object.keys(disk_map).length) throw new Error('同一序列号不能绑定多个灯位');
            await api('/remap', { method: 'POST', contentType: 'application/json', body: JSON.stringify({ mode: 'disk', disk_map }) });
            await Promise.all([onRefresh(), onLoadSettings?.()]); setSerialMap({}); onToast('序列号灯位绑定已保存');
          })}>保存序列号绑定</button><button type="button" className="secondary-button" onClick={() => void withBusy('auto-map', async () => { await api('/remap', { method: 'POST', contentType: 'application/json', body: JSON.stringify({ mode: 'auto', disk_map: {} }) }); await Promise.all([onRefresh(), onLoadSettings?.()]); setSerialMap({}); onToast('已恢复自动映射，灯位仍需核对'); })}>恢复自动映射</button></div>
        </fieldset>
      </article>}
      <article className="glass-surface device-map-surface"><div className="section-heading"><div><span className="section-kicker">HCTL MAPPING</span><h2>硬盘与 LED 映射</h2></div><button type="button" className="secondary-button" disabled={!writable || Boolean(busy)} onClick={() => void withBusy('remap', async () => { const result = await api<{ message?: string }>('/remap', { method: 'POST', body: '' }); await onRefresh(); onToast(result.message || '硬盘映射已更新'); })}><i className="bi bi-arrow-repeat" aria-hidden="true" />重新检测</button></div><div className="table-scroll"><table className="data-table"><thead><tr><th>设备</th><th>LED</th><th>状态</th><th>读取</th><th>写入</th></tr></thead><tbody>{mapping.length ? mapping.map((item, index) => <tr key={`${item.device || 'disk'}-${index}`}><td>{item.device || '—'}</td><td>{item.led || '—'}{platform.phase2 && item.evidence && <small className="mapping-evidence">{item.evidence}</small>}</td><td><StateChip state={item.state} /></td><td>{platform.unraid && item.read_kbps == null ? '—' : formatRate(item.read_kbps)}</td><td>{platform.unraid && item.write_kbps == null ? '—' : formatRate(item.write_kbps)}</td></tr>) : <tr><td colSpan={5} className="empty-row">暂未检测到硬盘映射</td></tr>}</tbody></table></div></article>

      <article className="glass-surface hardware-surface"><div className="section-heading"><div><span className="section-kicker">HARDWARE BACKEND</span><h2>机型与控制后端</h2><p className="surface-help">{platform.unraid ? (platform.phase2 ? '只读显示 DMI 与机型信息；LED 服务统一使用随包 CLI，机型与协议不可修改。' : '只读显示 DMI 与机型信息；LED 控制后端尚未接入。') : '确认当前机型识别结果与 LED 控制路径；大多数情况下保持自动识别即可。'}</p></div><span className="soft-badge">{supportLabels[String(hardware.support)] || String(hardware.support || '检测中')}</span></div><div className="hardware-identity-strip">
        <div className="hardware-identity-item"><i className="bi bi-pc-display-horizontal" aria-hidden="true" /><span><small>DMI 机型</small><strong title={String(hardware.product_name || '未读取到')}>{String(hardware.product_name || '未读取到')}</strong></span></div>
        <div className="hardware-identity-item"><i className="bi bi-grid-3x3-gap" aria-hidden="true" /><span><small>识别档案</small><strong>{String(hardware.profile_name || hardware.profile || '未知')}</strong></span></div>
        <div className="hardware-identity-item"><i className="bi bi-terminal" aria-hidden="true" /><span><small>CLI 版本</small><strong>{String(hardware.cli_version || '未知')}</strong></span></div>
        <div className="hardware-identity-item"><i className="bi bi-diagram-3" aria-hidden="true" /><span><small>当前路径</small><strong>{currentBackend}</strong></span></div>
      </div><div className="hardware-fields" hidden={platform.unraid}>
        <label className="hardware-field"><span><strong>机型档案</strong><small>决定灯位数量、编号和硬盘 HCTL 映射；通常保持自动识别</small></span><GlassSelect label="机型档案" disabled={platform.unraid} value={profile} options={profiles.map(([value, label]) => ({ value, label }))} onValueChange={(value) => updateSettings('hardware', { profile: value })} /></label>
        <label className="hardware-field"><span><strong>控制后端</strong><small>推荐保持自动或使用内置 CLI</small></span><GlassSelect label="控制后端" disabled={platform.unraid} value={backend} options={[{value:'auto',label:'自动选择（推荐）'},{value:'cli',label:'内置 CLI'}]} onValueChange={(value) => updateSettings('hardware', { backend: value })} /></label>
        <label className="hardware-field"><span><strong>写入协议</strong><small>由 Almighty 后端根据真实硬件自动识别</small></span><GlassSelect label="写入协议" value={'auto'} options={[{value:'auto',label:'上游自动识别'}]} disabled /></label>
      </div><div className="hardware-facts"><span><small>写入协议</small><strong>{platform.unraid ? '未接入' : '上游自动识别'}</strong></span><span><small>灯位布局</small><strong>{platform.unraid && hardware.netdev_count == null ? '—' : Number(hardware.netdev_count ?? (platform.unraid ? 0 : 1))} 网络灯 · {platform.unraid && hardware.disk_count == null ? '—' : Number(hardware.disk_count || 0)} 硬盘灯</strong></span><span><small>系统内核</small><strong>{String(hardware.kernel || '未知')}</strong></span><span><small>控制范围</small><strong>{platform.unraid ? (platform.phase2 ? '仅 LED；BIOS / 风扇只读' : '只读诊断，未接管硬件') : hardware.backend_active === 'power-0x26' ? '电源灯模式（保持当前颜色）' : '电源、网络与硬盘灯'}</strong></span></div>{hardwareMessage ? <div className="hardware-message" role="status">{hardwareMessage}</div> : null}<div className="hardware-path-note" hidden={platform.unraid}><i className="bi bi-shield-check" aria-hidden="true" /><span>所有 LED 写入统一交给应用内置 <strong>ugreen_leds_cli</strong>；这里仅保存应用机型档案，不直接访问 I²C 或硬件寄存器。</span></div><details className="hardware-guide" hidden={platform.unraid}><summary className="hardware-guide-summary"><span className="hardware-guide-title"><i className="bi bi-info-circle" aria-hidden="true" /><span><strong>查看控制路径说明</strong><small>内置 CLI 与 DXP480T 专用能力</small></span></span><i className="bi bi-chevron-down" aria-hidden="true" /></summary><div className="hardware-guide-body"><div className="hardware-guide-grid"><div className={`hardware-guide-item${hardware.backend_active === 'cli' ? ' is-active' : ''}`}><div className="hardware-guide-item-head"><span><i className="bi bi-terminal" aria-hidden="true" /><strong>内置 CLI</strong></span></div><p>应用唯一的 LED 写入入口，负责普通机型的电源灯、网络灯和硬盘灯。</p></div><div className={`hardware-guide-item${hardware.backend_active === 'power-0x26' ? ' is-active' : ''}`}><div className="hardware-guide-item-head"><span><i className="bi bi-lightbulb" aria-hidden="true" /><strong>DXP480T 专用能力</strong></span></div><p>通过同一内置 CLI 控制电源灯模式（保持当前颜色），不提供硬盘灯与网络灯。</p></div></div></div></details><div className="hardware-actions"><button type="button" className="primary-button" disabled={platform.unraid || busy === 'hardware'} onClick={() => void withBusy('hardware', saveHardware)}><i className="bi bi-floppy" aria-hidden="true" />保存硬件设置</button></div></article>

      <article className="glass-surface monitoring-surface" hidden={platform.unraid}><div className="section-heading"><div><span className="section-kicker">LOW WAKE</span><h2>监测频率</h2></div><span className="soft-badge">单位：秒</span></div><p className="surface-help">数值越低，状态更新越快，但可能增加硬盘或控制器被查询的频率。</p><div className="monitoring-fields"><label className="threshold-field"><span className="monitoring-field-copy"><strong>硬盘电源状态</strong><small>建议 60 秒，可设置 10–3600 秒</small></span><span className="number-wrap"><input disabled={platform.unraid} type="number" min="10" max="3600" value={probeInterval} onChange={(event) => updateSettings('daemon', { disk_power_probe_interval: String(boundedInt(event.target.value, 10, 3600, 60)) })} /><em>秒</em></span></label><label className="threshold-field"><span className="monitoring-field-copy"><strong>热插拔扫描</strong><small>建议 30 秒，可设置 5–3600 秒</small></span><span className="number-wrap"><input disabled={platform.unraid} type="number" min="5" max="3600" value={hotplugInterval} onChange={(event) => updateSettings('daemon', { hotplug_check_interval: String(boundedInt(event.target.value, 5, 3600, 30)) })} /><em>秒</em></span></label></div><div className="monitoring-actions"><button type="button" className="primary-button" disabled={platform.unraid || busy === 'monitor'} onClick={() => void withBusy('monitor', async () => onSaveSettings([`daemon.disk_power_probe_interval=${probeInterval}`, `daemon.hotplug_check_interval=${hotplugInterval}`], '监测频率已保存'))}><i className="bi bi-floppy" aria-hidden="true" />保存监测频率</button></div></article>

      <article className="glass-surface log-surface"><div className="section-heading log-heading"><div><span className="section-kicker">TROUBLESHOOTING LOG</span><h2>应用诊断日志</h2><p className="surface-help">包含组件、事件、PID、请求 ID、来源与错误上下文；自动脱敏、截断并轮转。</p></div><span className="soft-badge">{String(logs?.write_level || 'info').toUpperCase()} · {logs?.requested_lines || lineCount} 行上限</span></div><div className="log-toolbar"><label><span>查看级别</span><GlassSelect label="查看级别" value={filter} options={[{value:'all',label:'全部'},{value:'error',label:'仅 ERROR'},{value:'warn',label:'仅 WARN'},{value:'info',label:'仅 INFO'},{value:'debug',label:'仅 DEBUG'}]} onValueChange={setFilter} /></label><label><span>最近行数</span><GlassSelect label="最近行数" value={lineCount} options={[{value:'200',label:'200 行'},{value:'500',label:'500 行'},{value:'1000',label:'1000 行'}]} onValueChange={setLineCount} /></label><label><span>记录级别</span><GlassSelect label="记录级别" disabled={platform.unraid} value={writeLevel} options={[{value:'info',label:'INFO（日常）'},{value:'debug',label:'DEBUG（详细排错）'},{value:'warn',label:'WARN'},{value:'error',label:'ERROR'}]} onValueChange={setWriteLevel} /></label><button type="button" className="secondary-button" disabled={platform.unraid || busy === 'log-level'} onClick={() => void withBusy('log-level', async () => { const result = await api<{ message?: string }>('/logs/config', { method: 'POST', query: `level=${encodeURIComponent(writeLevel)}`, body: '' }); onToast(result.message || `日志级别已切换为 ${writeLevel.toUpperCase()}`); await loadLogs(); })}><i className="bi bi-sliders" aria-hidden="true" />应用级别</button><button type="button" className="secondary-button" disabled={busy === 'logs'} onClick={() => void withBusy('logs', loadLogs)}><i className="bi bi-arrow-clockwise" aria-hidden="true" />刷新</button><button type="button" className="secondary-button" onClick={() => { if (!logs?.content) { onToast('当前没有可复制的日志', 'err'); return; } void navigator.clipboard?.writeText(logs.content).then(() => onToast('日志已复制到剪贴板')).catch(() => onToast('复制日志失败', 'err')); }}><i className="bi bi-clipboard" aria-hidden="true" />复制</button><button type="button" className="secondary-button" onClick={() => { if (!logs?.content) { onToast('当前没有可下载的日志', 'err'); return; } downloadText(`${logs.content}\n`, `ugreen-led-application-${new Date().toISOString().replace(/[:.]/g, '-')}.log`); }}><i className="bi bi-download" aria-hidden="true" />下载当前日志</button><button type="button" className="secondary-button" disabled={busy === 'diagnostics'} onClick={() => void withBusy('diagnostics', async () => { const data = await api<{ content?: string; filename?: string }>('/hardware/diagnostics'); if (!data.content) throw new Error('诊断包内容为空'); downloadText(`${data.content}\n`, data.filename || 'ugreen-led-diagnostics.txt'); onToast('硬件诊断包已生成并开始下载'); })}><i className="bi bi-file-earmark-arrow-down" aria-hidden="true" />一键下载诊断包</button><button type="button" className="danger-button" disabled={platform.unraid || busy === 'clear'} onClick={() => { if (window.confirm('确定清空 app.log 及其轮转历史吗？此操作无法撤销。')) void withBusy('clear', async () => { const result = await api<{ message?: string }>('/logs/clear', { method: 'POST', query: 'confirm=clear-logs', body: '' }); onToast(result.message || '应用诊断日志已清空'); await loadLogs(); }); }}><i className="bi bi-trash3" aria-hidden="true" />清空</button></div><pre className="log-console" tabIndex={0}>{logError || (logs ? logs.content || '当前暂无应用日志' : '正在读取应用日志…')}</pre><div className="log-footnote"><span>app.log · {bytes(logs?.size_bytes)} · {logs?.updated_at ? `更新于 ${new Date(Number(logs.updated_at) * 1000).toLocaleString()}` : '尚无日志'}{logs?.clipped ? ' · 输出已截断' : ''}</span><span>{platform.unraid ? '只读：不可更改记录级别或清空日志。' : 'DEBUG 用完后建议恢复 INFO。'}</span></div></article>
    </div>
  </section>;
}
