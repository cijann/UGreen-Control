export function platformConfig(target?: string, phase?: string) {
  const isFnos = target === 'fnos' || (!target && !phase);
  const unraid = !isFnos;
  const phase2 = unraid && phase === '2';
  return {
    unraid,
    phase2,
    readOnly: unraid && !phase2,
    capabilities: { lighting: isFnos || phase2, bios: isFnos, hardwareSettings: isFnos, logWrites: isFnos },
    name: unraid ? 'Unraid' : 'fnOS',
    apiBase: unraid ? '/plugins/ugreen-control/api.php' : '/cgi/ThirdParty/App.Native.UGreenLED/api.cgi',
  };
}

export const platform = platformConfig(import.meta.env?.VITE_PLATFORM, import.meta.env?.VITE_UNRAID_PHASE);
export const isReadOnly = platform.readOnly;
export const readOnlyMessage = 'Unraid 第一阶段仅提供只读概览与诊断；灯光、服务管理、配置写入和 BIOS 控制尚未接入。';
export const phase2Message = 'Unraid 第二阶段开放 LED 灯光、活动策略与服务管理；BIOS、风扇和其他硬件写操作尚未接入，保持只读。';
