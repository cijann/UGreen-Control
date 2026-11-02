export type HardwareMonitorData = Record<string, unknown> & {
  capabilities?: { lighting?: boolean; bios?: boolean };
  cpu_temp_c?: number | null;
  fans?: Array<{ name: string; rpm: number | null; pwm: number | null; pwm_percent: number | null }>;
  disks?: Array<{ name: string; device: string; temperature_c: number | null; spundown: boolean | null }>;
  unraid_version?: string;
  array_state?: string;
  uptime_seconds?: number | null;
};

function measurement(value: number | null | undefined, unit = '') {
  return typeof value === 'number' && Number.isFinite(value) ? `${value}${unit}` : '—';
}

function label(value?: string) {
  const text = value?.trim();
  return !text || text.toLowerCase() === 'unknown' ? '—' : text;
}

function uptime(value?: number | null) {
  if (typeof value !== 'number' || !Number.isFinite(value) || value < 0) return '—';
  const minutes = Math.floor(value / 60);
  return `${Math.floor(minutes / 1440)} 天 ${Math.floor(minutes / 60) % 24} 小时 ${minutes % 60} 分钟`;
}

export function HardwareOverview({ hardware }: { hardware: HardwareMonitorData }) {
  const fans = hardware.fans || [];
  const disks = hardware.disks || [];
  return <div className="overview-grid hardware-overview" aria-label="只读硬件监控">
    <article className="glass-surface network-panel">
      <div className="section-heading"><div><span className="section-kicker">SYSTEM MONITOR</span><h2>系统与温度</h2></div><span className="soft-badge">Unraid · 只读</span></div>
      <div className="hardware-facts">
        <span><small>CPU 温度</small><strong>{measurement(hardware.cpu_temp_c, ' °C')}</strong></span>
        <span><small>Unraid 版本</small><strong>{label(hardware.unraid_version)}</strong></span>
        <span><small>阵列状态</small><strong>{label(hardware.array_state)}</strong></span>
        <span><small>运行时间</small><strong>{uptime(hardware.uptime_seconds)}</strong></span>
      </div>
      <p className="surface-help">仅展示后端采样结果；未提供的数据以“—”显示。</p>
    </article>
    <article className="glass-surface disk-panel">
      <div className="section-heading"><div><span className="section-kicker">FAN TELEMETRY</span><h2>系统风扇</h2></div><span className="soft-badge">仅监控</span></div>
      <div className="table-scroll"><table className="data-table">
        <thead><tr><th>风扇</th><th>转速</th><th>当前 PWM</th><th>PWM 百分比</th></tr></thead>
        <tbody>{fans.length ? fans.map((fan, index) => <tr key={`${fan.name}-${index}`}><td>{label(fan.name)}</td><td>{measurement(fan.rpm, ' RPM')}</td><td>{measurement(fan.pwm)}</td><td>{measurement(fan.pwm_percent, '%')}</td></tr>) : <tr><td colSpan={4} className="empty-row">—</td></tr>}</tbody>
      </table></div>
      <p className="surface-help">当前 PWM 为只读反馈，不提供风扇调速。</p>
    </article>
    <article className="glass-surface disk-panel hardware-temperatures">
      <div className="section-heading"><div><span className="section-kicker">DISK TEMPERATURE</span><h2>硬盘温度</h2></div><span className="soft-badge">只读</span></div>
      <div className="table-scroll"><table className="data-table">
        <thead><tr><th>盘名</th><th>设备</th><th>温度</th><th>休眠状态</th></tr></thead>
        <tbody>{disks.length ? disks.map((disk, index) => <tr key={`${disk.device}-${index}`}><td>{label(disk.name)}</td><td>{label(disk.device)}</td><td>{measurement(disk.temperature_c, ' °C')}</td><td>{disk.spundown === true ? '休眠' : disk.spundown === false ? '未休眠' : '—'}</td></tr>) : <tr><td colSpan={4} className="empty-row">—</td></tr>}</tbody>
      </table></div>
    </article>
  </div>;
}
