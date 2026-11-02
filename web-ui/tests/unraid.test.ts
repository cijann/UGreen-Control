import assert from 'node:assert/strict';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import { createElement } from 'react';
import * as React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { platformConfig } from '../src/lib/platform.ts';

test('平台默认保留 fnOS API，Unraid 使用 PHP PATH_INFO', () => {
  assert.deepEqual(platformConfig(), { unraid: false, phase2: false, phase3: false, readOnly: false, capabilities: { lighting: true, bios: true, hardwareSettings: true, logWrites: true }, name: 'fnOS', apiBase: '/cgi/ThirdParty/App.Native.UGreenLED/api.cgi' });
  assert.deepEqual(platformConfig('unraid'), { unraid: true, phase2: false, phase3: false, readOnly: true, capabilities: { lighting: false, bios: false, hardwareSettings: false, logWrites: false }, name: 'Unraid', apiBase: '/plugins/ugreen-control/api.php' });
  assert.equal(platformConfig('unraid', '2').capabilities.lighting, true);
  assert.equal(platformConfig('unraid', '2').capabilities.bios, false);
  assert.equal(platformConfig('unraid', '2').phase2, true);
  assert.equal(platformConfig('unraid', '3').capabilities.lighting, true);
  assert.equal(platformConfig('unraid', '3').capabilities.bios, true);
  assert.equal(platformConfig('unraid', '3').phase3, true);
  assert.equal(platformConfig('unraid', '3').readOnly, false);
  assert.equal(platformConfig('fnos', '2').phase2, false);
});

test('fnOS 保留 CGI、模式切换与原控制页面', async () => {
  const server = await createServer({
    root: fileURLToPath(new URL('..', import.meta.url)),
    configFile: false,
    server: { middlewareMode: true, hmr: false },
    optimizeDeps: { noDiscovery: true, include: [] },
    define: { 'import.meta.env.VITE_PLATFORM': JSON.stringify('fnos') },
  });
  const originalLocation = Object.getOwnPropertyDescriptor(globalThis, 'location');
  Object.defineProperty(globalThis, 'location', { configurable: true, value: { hash: '#overview' } });
  try {
    const { App } = await server.ssrLoadModule('/src/ui/App.tsx');
    const html = renderToStaticMarkup(createElement(App));
    assert.match(html, /BearHero · fnOS/);
    assert.doesNotMatch(html, /Unraid port · 第一阶段 · 只读/);
    assert.match(html, /灯控状态/);
    assert.doesNotMatch(html, /只读硬件监控|CPU 温度|系统风扇|硬盘温度/);
    for (const [button] of html.matchAll(/<button\b[^>]*class="mode-button[^>]*>/g)) assert.doesNotMatch(button, /disabled/);
    assert.match(html, /保存灯光设置/);
  } finally {
    if (originalLocation) Object.defineProperty(globalThis, 'location', originalLocation); else Reflect.deleteProperty(globalThis, 'location');
    await server.close();
  }
});

test('Unraid phase2 CSRF、写入白名单与页面能力', async () => {
  const server = await createServer({
    root: fileURLToPath(new URL('..', import.meta.url)), configFile: false,
    server: { middlewareMode: true, hmr: false },
    optimizeDeps: { noDiscovery: true, include: [] },
    define: { 'import.meta.env.VITE_PLATFORM': JSON.stringify('unraid'), 'import.meta.env.VITE_UNRAID_PHASE': JSON.stringify('2') },
  });
  const originals = Object.fromEntries(['window', 'location', 'fetch'].map((key) => [key, Object.getOwnPropertyDescriptor(globalThis, key)]));
  const requests: Array<{ url: string; options?: RequestInit }> = [];
  let sessionAllowed = true;
  let postStatus = 200;
  let postOk: boolean | undefined = true;
  Object.defineProperty(globalThis, 'window', { configurable: true, value: { crypto: globalThis.crypto } });
  globalThis.fetch = async (input, options) => {
    const url = String(input); requests.push({ url, options });
    if (url.endsWith('/session')) return Response.json(sessionAllowed ? { ok: true, csrf_token: 'fixture-only' } : { ok: false, error: '会话失效' }, { status: sessionAllowed ? 200 : 401 });
    return Response.json({ ok: postOk }, { status: options?.method === 'POST' ? postStatus : 200 });
  };
  try {
    const { api } = await server.ssrLoadModule('/src/lib/api.ts');
    await api('/settings', { method: 'POST', body: 'power.brightness=40\nbehavior.manage_power=true' });
    assert.equal(requests[0].url, '/plugins/ugreen-control/api.php/session');
    assert.equal(requests[0].options?.method, 'GET');
    assert.equal(requests[1].options?.headers?.['X-CSRF-Token'], 'fixture-only');
    assert.equal(requests[1].options?.headers?.['Content-Type'], 'text/plain');
    assert.equal(requests[1].options?.body, 'power.brightness=40\nbehavior.manage_power=true');
    await api('/mode', { method: 'POST', query: 'mode=smart', body: '' });
    assert.match(requests.at(-1)!.url, /\/mode\?mode=smart$/);
    await api('/autostart', { method: 'POST', body: JSON.stringify({ enabled: true, delay_seconds: 300 }), contentType: 'application/json' });
    assert.equal(requests.at(-1)!.options?.headers?.['Content-Type'], 'application/json');
    assert.equal(requests.filter(({ url }) => url.endsWith('/session')).length, 1);
    for (const path of ['/daemon/start', '/daemon/stop', '/remap']) await api(path, { method: 'POST', body: '' });
    const count = requests.length;
    for (const path of ['/bios/status', '/bios/fan', '/logs/clear', '/logs/config', '/logs/client', '/power26/apply', '/driver/install']) await assert.rejects(api(path, { method: path === '/bios/status' ? 'GET' : 'POST', body: path === '/bios/status' ? undefined : '' }), { status: 405 });
    assert.equal(requests.length, count);
    postStatus = 403; postOk = false;
    await assert.rejects(api('/mode', { method: 'POST', query: 'mode=off', body: '' }), { status: 403 });
    const after403 = requests.length;
    sessionAllowed = false;
    await assert.rejects(api('/daemon/start', { method: 'POST', body: '' }), { status: 401 });
    assert.equal(requests.length, after403 + 1);
    assert.ok(requests.at(-1)!.url.endsWith('/session'));
    sessionAllowed = true; postStatus = 200; postOk = undefined;
    await assert.rejects(api('/daemon/start', { method: 'POST', body: '' }));
    const { App } = await server.ssrLoadModule('/src/ui/App.tsx');
    Object.defineProperty(globalThis, 'location', { configurable: true, value: { hash: '#lighting' } });
    const html = renderToStaticMarkup(createElement(App));
    assert.match(html, /Unraid port · 第二阶段 · LED 控制/);
    assert.match(html, /保存灯光设置/);
    assert.match(html, /保存活动设置/);
    assert.match(html, /等待服务连接/);
    assert.match(html, /<fieldset disabled="" class="capability-fields"/);
    for (const [button] of html.matchAll(/<button\b[^>]*class="mode-button[^>]*>/g)) assert.match(button, /disabled/);
    Object.defineProperty(globalThis, 'location', { configurable: true, value: { hash: '#bios' } });
    const bios = renderToStaticMarkup(createElement(App));
    assert.match(bios, /BIOS 控制尚未接入/);
    assert.doesNotMatch(bios, /风扇曲线|保存风扇|来电启动设置/);
    Object.defineProperty(globalThis, 'location', { configurable: true, value: { hash: '#about' } });
    const about = renderToStaticMarkup(createElement(App));
    assert.match(about, /PHASE 2/); assert.match(about, /AGPL-3.0/); assert.match(about, /BearHero520/);
    assert.doesNotMatch(about, /检查更新|下载升级包|刷新 README/);
    const { DevicesPage } = await server.ssrLoadModule('/src/ui/DevicesPage.tsx');
    const devices = renderToStaticMarkup(createElement(DevicesPage, { hidden: false, mapping: [], hardware: {}, settings: { startup: { enabled: 'true', delay_seconds: '300' } }, status: { daemon: 'waiting', service: { autostart: true, delay_seconds: 300 } }, writable: true, onSettingsChange() {}, async onSaveSettings() {}, async onRefresh() {}, onToast() {} }));
    assert.match(devices, /取消等待/); assert.match(devices, /保存序列号绑定/);
    assert.match(devices, /min="60" max="3600"[^>]*value="300"/);
    assert.match(devices, /type="checkbox"[^>]*checked/);
    const buttons = [...devices.matchAll(/<button\b[^>]*>[\s\S]*?<\/button>/g)].map(([value]) => value);
    for (const label of ['立即启动', '取消等待', '重新检测', '保存序列号绑定']) assert.doesNotMatch(buttons.find((value) => value.replace(/<[^>]+>/g, '').includes(label))!, /disabled/);
    for (const label of ['保存硬件设置', '清空', '应用级别']) assert.match(buttons.find((value) => value.replace(/<[^>]+>/g, '').includes(label))!, /disabled/);
    const { OverviewPage } = await server.ssrLoadModule('/src/ui/OverviewPage.tsx');
    const overview = (status: unknown) => renderToStaticMarkup(createElement(OverviewPage, { hidden: false, status, mapping: [], hardware: {}, onDevices() {} }));
    assert.match(overview({ daemon: 'waiting', network: 'unknown', network_label: 'unknown' }), /等待延迟启动/);
    assert.doesNotMatch(overview({ daemon: 'error', network: 'unknown', network_label: 'unknown' }), /unknown|后台已停止|0 KB\/s/);
    assert.match(overview(null), /服务状态未连接/);
  } finally {
    for (const [key, descriptor] of Object.entries(originals)) { if (descriptor) Object.defineProperty(globalThis, key, descriptor); else Reflect.deleteProperty(globalThis, key); }
    await server.close();
  }
});

test('phase2 实际页面回调保存不启动、模式契约与断连保护', async () => {
  const server = await createServer({
    root: fileURLToPath(new URL('..', import.meta.url)), configFile: false,
    server: { middlewareMode: true, hmr: false }, optimizeDeps: { noDiscovery: true, include: [] },
    define: { 'import.meta.env.VITE_PLATFORM': JSON.stringify('unraid'), 'import.meta.env.VITE_UNRAID_PHASE': JSON.stringify('2') },
  });
  const originals = Object.fromEntries(['window', 'location', 'fetch'].map((key) => [key, Object.getOwnPropertyDescriptor(globalThis, key)]));
  const requests: Array<{ path: string; method?: string; body?: string | null }> = [];
  let offline = false;
  Object.defineProperty(globalThis, 'window', { configurable: true, value: { crypto: globalThis.crypto, setTimeout: () => 1, clearTimeout() {} } });
  Object.defineProperty(globalThis, 'location', { configurable: true, value: { hash: '#lighting' } });
  globalThis.fetch = async (input, options) => {
    const url = new URL(String(input), 'http://localhost'); const path = url.pathname.split('api.php')[1];
    requests.push({ path: path + url.search, method: options?.method, body: options?.body as string });
    if (offline) return Response.json({ ok: false, error: '模拟断连' }, { status: 503 });
    if (path === '/session') return Response.json({ ok: true, csrf_token: 'fixture-only' });
    if (path === '/settings' && options?.method === 'GET') return Response.json({ ok: true, raw: '[startup]\nenabled=true\ndelay_seconds=300\n' });
    if (path === '/status') return Response.json({ ok: true, daemon: 'waiting', mode: 'smart', service: { autostart: true, delay_seconds: 300 } });
    if (path === '/hardware/status') return Response.json({ ok: true, capabilities: { lighting: true, bios: false } });
    return Response.json({ ok: true, mapping: [] });
  };
  // 直接运行组件的事件回调；保留 hook 状态，不依赖浏览器或 DOM 模拟依赖。
  const internals = (React as any).__CLIENT_INTERNALS_DO_NOT_USE_OR_WARN_USERS_THEY_CANNOT_UPGRADE;
  const originalDispatcher = internals.H;
  const states: any[] = []; let cursor = 0;
  const dispatcher = {
    useState(initial: any) { const index = cursor++; if (!(index in states)) states[index] = typeof initial === 'function' ? initial() : initial; return [states[index], (next: any) => { states[index] = typeof next === 'function' ? next(states[index]) : next; }]; },
    useRef(initial: any) { const index = cursor++; return states[index] ||= { current: initial }; },
    useCallback(callback: any) { return callback; }, useEffect() {},
  };
  const nodes = (node: any): any[] => !node || typeof node !== 'object' ? [] : Array.isArray(node) ? node.flatMap(nodes) : [node, ...nodes(node.props?.children)];
  try {
    const { App } = await server.ssrLoadModule('/src/ui/App.tsx');
    const render = () => { cursor = 0; internals.H = dispatcher; try { return nodes(App()); } finally { internals.H = originalDispatcher; } };
    let tree = render();
    let devices = tree.find((node) => node.type?.name === 'DevicesPage');
    await devices.props.onLoadSettings(); await devices.props.onRefresh();
    tree = render();
    devices = tree.find((node) => node.type?.name === 'DevicesPage');
    assert.equal(devices.props.writable, true);
    const lighting = tree.find((node) => node.type?.name === 'LightingPage');
    await lighting.props.onSave();
    assert.ok(requests.some((item) => item.path === '/settings' && item.method === 'POST' && item.body?.includes('behavior.manage_power=true')));
    assert.equal(requests.filter((item) => item.path === '/daemon/start').length, 0);
    const activity = tree.find((node) => node.type?.name === 'ActivityPage');
    await activity.props.onSave(['activity.disk_blink=true', 'activity.disk_threshold_kbps=256'], '活动设置已保存');
    assert.ok(requests.some((item) => item.body === 'activity.disk_blink=true\nactivity.disk_threshold_kbps=256'));
    const dock = tree.find((node) => node.type?.name === 'ModeDock');
    dock.props.onModeChange('off');
    await new Promise<void>((resolve) => setImmediate(resolve));
    assert.ok(requests.some((item) => item.path === '/mode?mode=off' && item.method === 'POST'));
    assert.ok(requests.every((item) => !item.path.startsWith('/bios')));
    offline = true;
    await assert.rejects(devices.props.onRefresh(), /模拟断连/);
    tree = render();
    devices = tree.find((node) => node.type?.name === 'DevicesPage');
    assert.equal(devices.props.writable, false);
    assert.equal(tree.find((node) => node.type?.name === 'ModeDock').props.disabled, true);
    const count = requests.length;
    await assert.rejects(devices.props.onSaveSettings(['power.brightness=1'], '成功'), /服务未连接/);
    assert.equal(requests.length, count);
    assert.equal(devices.props.status, null);
    offline = false; states.length = 0;
    const { DevicesPage } = await server.ssrLoadModule('/src/ui/DevicesPage.tsx');
    const props = { ...devices.props, writable: true, hidden: false, status: { daemon: 'waiting' }, settings: { startup: { enabled: 'true', delay_seconds: '300' } } };
    const renderDevices = () => { cursor = 0; internals.H = dispatcher; try { return nodes(DevicesPage(props)); } finally { internals.H = originalDispatcher; } };
    const click = async (label: string) => {
      const button = renderDevices().find((node) => node.type === 'button' && nodes(node.props.children).length === 0 && node.props.children === label);
      assert.ok(button, `找不到可操作按钮 ${label}`); button.props.onClick();
      await new Promise<void>((resolve) => setImmediate(resolve));
    };
    await click('保存延迟自启');
    assert.ok(requests.some((item) => item.path === '/autostart' && item.body === '{"enabled":true,"delay_seconds":300}'));
    await click('立即启动'); await click('取消等待');
    assert.ok(requests.some((item) => item.path === '/daemon/start' && item.method === 'POST'));
    assert.ok(requests.some((item) => item.path === '/daemon/stop' && item.method === 'POST'));
    const serialInput = renderDevices().find((node) => node.props?.['aria-label'] === '灯位 1 序列号');
    serialInput.props.onChange({ target: { value: 'LOCAL-SATA-SERIAL-1' } });
    await click('保存序列号绑定');
    assert.ok(requests.some((item) => item.path === '/remap' && item.body === '{"mode":"disk","disk_map":{"disk1":"LOCAL-SATA-SERIAL-1"}}'));
    await click('恢复自动映射');
    assert.ok(requests.some((item) => item.path === '/remap' && item.body === '{"mode":"auto","disk_map":{}}'));
  } finally {
    internals.H = originalDispatcher;
    for (const [key, descriptor] of Object.entries(originals)) { if (descriptor) Object.defineProperty(globalThis, key, descriptor); else Reflect.deleteProperty(globalThis, key); }
    await server.close();
  }
});

test('Unraid 请求保护与页面只读入口', async (t) => {
  const server = await createServer({
    root: fileURLToPath(new URL('..', import.meta.url)),
    configFile: false,
    server: { middlewareMode: true, hmr: false },
    optimizeDeps: { noDiscovery: true, include: [] },
    define: { 'import.meta.env.VITE_PLATFORM': JSON.stringify('unraid') },
  });
  const originalFetch = globalThis.fetch;
  const originalWindow = Object.getOwnPropertyDescriptor(globalThis, 'window');
  const originalLocation = Object.getOwnPropertyDescriptor(globalThis, 'location');
  const requests: Array<{ url: string; options?: RequestInit }> = [];
  Object.defineProperty(globalThis, 'window', { configurable: true, value: { crypto: globalThis.crypto } });
  globalThis.fetch = async (input, options) => {
    requests.push({ url: String(input), options });
    return new Response(JSON.stringify({ ok: true }), { headers: { 'Content-Type': 'application/json' } });
  };
  try {
    const { api } = await server.ssrLoadModule('/src/lib/api.ts');
    await t.test('GET 保留路径、查询参数与同源凭据', async () => {
      await api('/status');
      await api('/logs', { query: 'source=application&lines=200' });
      assert.equal(requests[0].url, '/plugins/ugreen-control/api.php/status');
      assert.equal(requests[1].url, '/plugins/ugreen-control/api.php/logs?source=application&lines=200');
      assert.equal(requests[0].options?.credentials, 'same-origin');
      for (const path of ['/mapping', '/hardware/status', '/settings', '/about/info', '/hardware/diagnostics', '/about/readme', '/update/check']) await api(path);
      assert.ok(requests.every(({ options }) => options?.method === 'GET'));
    });
    await t.test('所有写请求包括客户端日志在 fetch 前被拦截', async () => {
      const count = requests.length;
      for (const path of ['/settings', '/mode', '/daemon/start', '/daemon/stop', '/remap', '/logs/clear', '/logs/config', '/logs/client', '/bios/fan']) {
        await assert.rejects(api(path, { method: 'POST', body: '' }), { status: 405, path });
      }
      await assert.rejects(api('/settings', { body: 'unexpected' }), { status: 405 });
      await assert.rejects(api('/bios/status'), { status: 405 });
      assert.equal(requests.length, count);
    });
    const { App } = await server.ssrLoadModule('/src/ui/App.tsx');
    const render = (route: string) => {
      Object.defineProperty(globalThis, 'location', { configurable: true, value: { hash: `#${route}` } });
      return renderToStaticMarkup(createElement(App));
    };
    const button = (html: string, label: string) => {
      const result = [...html.matchAll(/<button\b[^>]*>[\s\S]*?<\/button>/g)].map(([value]) => value).find((value) => value.replace(/<[^>]+>/g, '').includes(label));
      assert.ok(result, `找不到按钮：${label}`);
      return result;
    };
    await t.test('概览模式按钮禁用，设备只读按钮可用', () => {
      const html = render('overview');
      assert.match(html, /Unraid port · 第一阶段 · 只读/);
      for (const label of ['关闭全部', '开启全部', '智能模式']) assert.match(button(html, label), /disabled=""/);
      assert.doesNotMatch(button(html, '查看映射'), /disabled=""/);
      for (const label of ['重新检测', '保存硬件设置', '保存监测频率', '应用级别', '清空']) assert.match(button(html, label), /disabled=""/);
      for (const label of ['一键下载诊断包', '下载当前日志', '复制']) assert.doesNotMatch(button(html, label), /disabled=""/);
      for (const label of ['机型档案', '控制后端', '记录级别']) {
        const trigger = [...html.matchAll(/<button\b[^>]*>/g)].map(([value]) => value).find((value) => value.includes(`aria-label="${label}"`));
        assert.ok(trigger);
        assert.match(trigger, /disabled=""/);
      }
      assert.doesNotMatch(html, /0 KB\/s/);
    });
    const { OverviewPage } = await server.ssrLoadModule('/src/ui/OverviewPage.tsx');
    const renderHardware = (hardware: Record<string, unknown>) => renderToStaticMarkup(createElement(OverviewPage, { hidden: false, status: null, mapping: [], hardware, onDevices: () => {} }));
    await t.test('硬件概览展示后端温度、RPM、PWM、磁盘休眠与系统信息', () => {
      const html = renderHardware({
        cpu_temp_c: 47.5, unraid_version: '7.2.0', array_state: 'STARTED', uptime_seconds: 90060,
        fans: [{ name: '系统风扇 1', rpm: 1250, pwm: 128, pwm_percent: 50.2 }, { name: '系统风扇 2', rpm: 0, pwm: 0, pwm_percent: 0 }],
        disks: [{ name: 'disk1', device: '/dev/sda', temperature_c: 34, spundown: false }, { name: 'disk2', device: '/dev/sdb', temperature_c: null, spundown: true }],
      });
      for (const value of ['47.5 °C', '7.2.0', 'STARTED', '1 天 1 小时 1 分钟', '系统风扇 1', '1250 RPM', '128', '50.2%', '0 RPM', '0%', 'disk1', '/dev/sda', '34 °C', '未休眠', 'disk2', '休眠']) assert.ok(html.includes(value), `缺少监控数据：${value}`);
      assert.match(html, /<td>disk2<\/td><td>\/dev\/sdb<\/td><td>—<\/td><td>休眠<\/td>/);
      const monitor = html.match(/<div class="overview-grid hardware-overview"[\s\S]*?<\/article>\s*<\/div>/)?.[0];
      assert.ok(monitor);
      assert.doesNotMatch(monitor, /<button|<input|<select/);
    });
    await t.test('硬件未知值显示破折号，零值保留且休眠不推断为温度零', () => {
      const html = renderHardware({
        cpu_temp_c: null, unraid_version: 'unknown', array_state: '', uptime_seconds: null,
        fans: [{ name: 'unknown', rpm: null, pwm: null, pwm_percent: null }],
        disks: [{ name: 'disk1', device: '/dev/sda', temperature_c: null, spundown: null }],
      });
      assert.match(html, /CPU 温度<\/small><strong>—<\/strong>/);
      for (const field of ['Unraid 版本', '阵列状态', '运行时间']) assert.ok(html.includes(`${field}</small><strong>—</strong>`));
      assert.match(html, /<td>—<\/td><td>—<\/td><td>—<\/td><td>—<\/td>/);
      assert.match(html, /<td>disk1<\/td><td>\/dev\/sda<\/td><td>—<\/td><td>—<\/td>/);
      assert.doesNotMatch(html, /0 °C|NaN|undefined|unknown/);
      const zero = renderHardware({ cpu_temp_c: 0, uptime_seconds: 0 });
      assert.match(zero, /0 °C/);
      assert.match(zero, /0 天 0 小时 0 分钟/);
      const empty = renderHardware({});
      assert.match(empty, /只读硬件监控/);
      assert.doesNotMatch(empty, /NaN|undefined/);
    });
    await t.test('灯光、活动、BIOS 只挂载未接入面板', () => {
      for (const route of ['lighting', 'activity', 'bios']) {
        const html = render(route);
        assert.match(html, /未接入 \/ 只读/);
        const panel = html.match(new RegExp(`<section[^>]*aria-label="${route === 'lighting' ? '灯光设置' : route === 'activity' ? '活动提示' : 'BIOS 控制'}"[^>]*>[\\s\\S]*?<\\/section>`))?.[0];
        assert.ok(panel);
        assert.doesNotMatch(panel, /<input|<button/);
        for (const [input] of html.matchAll(/<input\b[^>]*type="(?:radio|range|number)"[^>]*>/g)) assert.match(input, /disabled=""/);
      }
    });
    await t.test('关于保留上游与 AGPL，不渲染 FPK 更新或 README 下载入口', () => {
      const html = render('about');
      assert.match(html, /BearHero520/);
      assert.match(html, /AGPL-3.0/);
      assert.doesNotMatch(html, /下载升级包|检查更新|刷新 README/);
      assert.doesNotMatch(html, /href="[^"]*(?:\.fpk|releases\/download)/);
    });
  } finally {
    globalThis.fetch = originalFetch;
    if (originalWindow) Object.defineProperty(globalThis, 'window', originalWindow); else Reflect.deleteProperty(globalThis, 'window');
    if (originalLocation) Object.defineProperty(globalThis, 'location', originalLocation); else Reflect.deleteProperty(globalThis, 'location');
    await server.close();
  }
});
