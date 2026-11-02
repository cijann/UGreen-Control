// 本地浏览器验收夹具：不连接 NAS，不记录或输出会话令牌。
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { resolve, extname } from 'node:path';

const root = fileURLToPath(new URL('../dist-phase2', import.meta.url));
let state = 'waiting';
let mode = 'smart';
let enabled = true;
let delay = 300;
let disconnected = false;
const writes = [];
let raw = '[startup]\nenabled=true\ndelay_seconds=300\n[activity]\ndisk_blink=true\nnetwork_blink=true\n';
const server = createServer(async (req, res) => {
  try {
        const url = new URL(req.url, 'http://127.0.0.1');
        const reply = (data, status = 200) => { res.statusCode = status; res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify(data)); };
        if (url.pathname === '/fixture/writes') return reply({ writes });
        if (url.pathname === '/fixture/disconnect') { disconnected = true; return reply({ ok: true }); }
        if (!url.pathname.startsWith('/plugins/ugreen-control/api.php/')) {
          const relative = url.pathname.replace(/^\/plugins\/ugreen-control\/www\//, '').replace(/^\//, '') || 'index.html';
          const file = resolve(root, relative);
          if (!file.startsWith(`${root}/`)) return reply({ ok: false }, 403);
          const content = await readFile(file);
          res.setHeader('Content-Type', ({ '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.png': 'image/png' })[extname(file)] || 'application/octet-stream');
          res.end(extname(file) === '.html' ? content.toString().replace('<head>', '<head><script>window.addEventListener("error",(event)=>{document.body.textContent="本地验收错误："+event.message});</script>') : content); return;
        }
        const path = url.pathname.split('api.php')[1];
        if (disconnected) return reply({ ok: false, error: '模拟服务断连' }, 503);
        if (path.startsWith('/bios')) return reply({ ok: false, error: 'BIOS 不应收到请求' }, 500);
        if (req.method === 'POST') {
          let body = ''; for await (const chunk of req) body += chunk;
          if (req.headers['x-csrf-token'] !== 'local-fixture-only') return reply({ ok: false, error: '缺少 CSRF' }, 403);
          writes.push({ path, query: url.search, body, contentType: req.headers['content-type'] });
          if (path === '/settings') {
            const sections = {};
            for (const line of body.split('\n')) {
              const match = line.match(/^([a-z_]+)\.([a-z_]+)=(.*)$/);
              if (!match) return reply({ ok: false, error: '配置格式错误' }, 400);
              (sections[match[1]] ||= []).push(`${match[2]}=${match[3]}`);
            }
            raw += Object.entries(sections).map(([section, lines]) => `\n[${section}]\n${lines.join('\n')}\n`).join('');
          } else if (path === '/mode') mode = url.searchParams.get('mode');
          else if (path === '/daemon/start') state = 'running';
          else if (path === '/daemon/stop') state = 'stopped';
          else if (path === '/autostart') {
            const parsed = JSON.parse(body); enabled = parsed.enabled; delay = parsed.delay_seconds;
            if (typeof enabled !== 'boolean' || !Number.isInteger(delay) || delay < 60 || delay > 3600) return reply({ ok: false, error: '自启格式错误' }, 400);
            raw += `\n[startup]\nenabled=${enabled}\ndelay_seconds=${delay}\n`;
          }
          return reply({ ok: true });
        }
        if (path === '/session') return reply({ ok: true, csrf_token: 'local-fixture-only' });
        if (path === '/status') return reply({ ok: true, daemon: state, mode, service: { state, autostart: enabled, delay_seconds: delay }, network: 'unknown', capabilities: { lighting: true, bios: false } });
        if (path === '/hardware/status') return reply({ ok: true, profile: 'dx4600', product_name: 'DX4600', capabilities: { lighting: true, bios: false } });
        if (path === '/settings') return reply({ ok: true, raw });
        if (path === '/mapping') return reply({ ok: true, mapping: [{ device: '/dev/sda', led: 'disk1', state: 'idle', evidence: 'SATA端口推定，未目视校准' }] });
        if (path === '/logs') return reply({ ok: true, content: '本地验收夹具' });
        if (path === '/about/info') return reply({ ok: true, version: 'phase2-fixture' });
        return reply({ ok: false, error: '夹具未实现端点' }, 404);
  } catch { res.statusCode = 500; res.end('本地验收夹具请求失败'); }
});
server.listen(5178, '127.0.0.1');
