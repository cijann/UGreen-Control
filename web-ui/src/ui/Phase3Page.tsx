import { useCallback, useEffect, useState } from 'react';
import { api } from '../lib/api';

type Toast = (message: string, type?: 'ok' | 'err') => void;

type WolInterface = { name: string; available: boolean; value: string | null; error: string | null };
type BiosStatus = {
  ok: boolean;
  supported: boolean;
  model?: string;
  fan: { available: boolean; reason?: string; rpm?: number | null; pwm?: number | null; owner?: string | null; takeover_supported: boolean };
  startup: { available: boolean; value?: string | null; reason?: string };
  wol: { available: boolean; interfaces: WolInterface[] };
  rtc: { available: boolean; epoch: number | null; error: string | null };
  limitations: string[];
};

function Capability({ icon, title, available, detail, children }: { icon: string; title: string; available: boolean; detail?: string; children?: React.ReactNode }) {
  return (
    <article className="glass-surface">
      <div className="section-heading">
        <div><span className="section-kicker">BIOS</span><h2><i className={`bi ${icon}`} aria-hidden="true" /> {title}</h2></div>
        <span className={`soft-badge${available ? ' online' : ''}`}>{available ? '可用' : '不可用'}</span>
      </div>
      {detail ? <p className="surface-help">{detail}</p> : null}
      {children}
    </article>
  );
}

function epochToLocal(epoch: number | null | undefined) {
  if (!epoch) return '';
  const d = new Date(epoch * 1000);
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export function Phase3Page({ hidden, onToast }: { hidden: boolean; onToast: Toast }) {
  const [data, setData] = useState<BiosStatus | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [confirm, setConfirm] = useState(false);
  const [rtcValue, setRtcValue] = useState('');

  const load = useCallback(async () => {
    try { setData(await api<BiosStatus>('/bios/status')); } catch { /* 保持上次数据 */ }
  }, []);
  useEffect(() => { if (!hidden) void load(); }, [hidden, load]);

  const run = async (key: string, action: () => Promise<void>, ok: string) => {
    if (!confirm) { onToast('请先勾选下方的确认选项', 'err'); return; }
    setBusy(key);
    try { await action(); onToast(ok); await load(); } catch (error) { onToast(error instanceof Error ? error.message : '操作失败', 'err'); } finally { setBusy(null); }
  };

  const setWol = (iface: string, policy: 'on' | 'off') => run(`wol-${iface}`, async () => {
    await api('/bios/wol', { method: 'POST', body: JSON.stringify({ interface: iface, policy, confirm: 'apply' }), contentType: 'application/json' });
  }, `WOL ${iface} 已设为 ${policy === 'on' ? '开启' : '关闭'}`);

  const setRtc = () => run('rtc', async () => {
    const epoch = Math.floor(new Date(rtcValue).getTime() / 1000);
    if (!Number.isFinite(epoch)) throw new Error('请选择有效的唤醒时间');
    await api('/bios/rtc', { method: 'POST', body: JSON.stringify({ epoch, confirm: 'apply' }), contentType: 'application/json' });
  }, 'RTC 定时唤醒已设置');

  const clearRtc = () => run('rtc-clear', async () => {
    await api('/bios/rtc', { method: 'POST', body: JSON.stringify({ action: 'clear', confirm: 'apply' }), contentType: 'application/json' });
  }, 'RTC 定时唤醒已清除');

  const fan = data?.fan;
  const startup = data?.startup;
  const wol = data?.wol;
  const rtc = data?.rtc;

  return <section className="react-view" hidden={hidden} aria-label="BIOS 控制">
    <div className="bios-layout">
      <Capability icon="bi-fan" title="风扇控制" available={fan?.available === true} detail={fan?.available === false ? (fan?.reason || '风扇控制不可用') : '接管系统风扇曲线。'}>
        {fan?.rpm != null && <p className="surface-help">当前转速 {fan.rpm} RPM · PWM {fan.pwm ?? '—'}</p>}
        {fan?.owner && <p className="surface-help">控制者：{fan.owner}</p>}
        {fan?.available !== true && <p className="surface-help">为避免冲突，本插件不接管、不停止现有风扇控制。</p>}
      </Capability>

      <Capability icon="bi-power" title="来电启动" available={startup?.available === true} detail={startup?.available === false ? (startup?.reason || '来电启动不可用') : '断电恢复后自动开机策略。'}>
        {startup?.available === true && <p className="surface-help">当前策略：{startup.value ?? '未知'}</p>}
      </Capability>

      <Capability icon="bi-wifi" title="网络唤醒（WOL）" available={wol?.available === true} detail="配置各物理网卡的魔术包唤醒。">
        {wol?.interfaces.map((iface) => (
          <div key={iface.name} className="wol-row">
            <span className="wol-name">{iface.name}</span>
            {iface.available ? (
              <>
                <span className="soft-badge">{iface.value === 'on' ? '已开启' : iface.value === 'off' ? '已关闭' : '未知'}</span>
                <button type="button" className="secondary-button" disabled={busy !== null} onClick={() => setWol(iface.name, iface.value === 'on' ? 'off' : 'on')}>切换</button>
              </>
            ) : <span className="surface-help">{iface.error || '不可用'}</span>}
          </div>
        ))}
      </Capability>

      <Capability icon="bi-alarm" title="RTC 定时唤醒" available={rtc?.available === true} detail="设置一次未来的定时开机；不会在开机后自动清除或重放过期唤醒。">
        {rtc?.available === true ? (
          <>
            <p className="surface-help">当前唤醒：{rtc.epoch ? epochToLocal(rtc.epoch) : '未设置'}</p>
            <div className="rtc-row">
              <input type="datetime-local" value={rtcValue} onChange={(e) => setRtcValue(e.target.value)} />
              <button type="button" className="primary-button" disabled={busy !== null || !rtcValue} onClick={setRtc}>设置唤醒</button>
              <button type="button" className="secondary-button" disabled={busy !== null} onClick={clearRtc}>清除</button>
            </div>
          </>
        ) : <span className="surface-help">{rtc?.error || '不可用'}</span>}
      </Capability>

      <article className="glass-surface">
        <div className="section-heading"><div><span className="section-kicker">NOTES</span><h2>当前限制</h2></div></div>
        <ul className="surface-help">{data?.limitations.map((line) => <li key={line}>{line}</li>)}</ul>
        <label className="confirm-line"><input type="checkbox" checked={confirm} onChange={(e) => setConfirm(e.target.checked)} /> 我已了解上述写入为真实硬件操作，且未覆盖的能力不会显示为可操作</label>
      </article>
    </div>
  </section>;
}
