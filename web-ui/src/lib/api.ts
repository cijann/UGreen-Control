import { platform, readOnlyMessage } from './platform.ts';

export type ApiOptions = {
  method?: 'GET' | 'POST';
  query?: string;
  body?: string;
  signal?: AbortSignal;
  contentType?: 'text/plain' | 'application/json';
};

export type ApiError = Error & {
  requestId?: string;
  status?: number;
  path?: string;
};

function createRequestId() {
  if (window.crypto?.randomUUID) return window.crypto.randomUUID();
  return `react-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

let csrfToken: string | undefined;
let sessionRequest: Promise<string> | undefined;

async function sessionToken() {
  if (csrfToken) return csrfToken;
  if (!sessionRequest) {
    sessionRequest = api<{ ok: boolean; csrf_token?: string }>('/session').then((data) => {
      if (data.ok !== true || !data.csrf_token) throw new Error('无法获取 Unraid 写入会话，请重新登录后刷新');
      csrfToken = data.csrf_token;
      return csrfToken;
    }).finally(() => { sessionRequest = undefined; });
  }
  return sessionRequest;
}

export async function api<T>(path: string, options: ApiOptions = {}): Promise<T> {
  const normalized = path.startsWith('/') ? path : `/${path}`;
  const writing = (options.method ?? 'GET') !== 'GET' || options.body !== undefined;
  const allowedWrites = ['/settings', '/mode', '/daemon/start', '/daemon/stop', '/remap', '/autostart'];
  if (platform.unraid && (normalized === '/bios' || normalized.startsWith('/bios/'))) {
    const error = new Error('Unraid BIOS / 风扇控制尚未接入，仅提供只读说明') as ApiError;
    error.status = 405; error.path = path; throw error;
  }
  if ((platform.readOnly && writing) || (platform.phase2 && writing && !allowedWrites.includes(normalized))) {
    const error = new Error(platform.readOnly ? readOnlyMessage : 'Unraid 第二阶段不支持此写入操作') as ApiError;
    error.status = 405;
    error.path = path;
    throw error;
  }
  const requestId = createRequestId();
  const headers: Record<string, string> = { 'X-Request-ID': requestId };
  if (options.body !== undefined) headers['Content-Type'] = options.contentType || 'text/plain';
  if (platform.phase2 && options.method === 'POST') headers['X-CSRF-Token'] = await sessionToken();

  const response = await fetch(`${platform.apiBase}${path.startsWith('/') ? path : `/${path}`}${options.query ? `?${options.query}` : ''}`, {
    method: options.method ?? 'GET',
    headers,
    body: options.body,
    credentials: 'same-origin',
    cache: 'no-store',
    signal: options.signal,
  });
  const text = await response.text();
  let data: T & { ok?: boolean; error?: string };
  try {
    data = JSON.parse(text) as T & { ok?: boolean; error?: string };
  } catch {
    const error = new Error(`服务器返回异常响应（HTTP ${response.status}）`) as ApiError;
    error.requestId = requestId;
    error.status = response.status;
    error.path = path;
    throw error;
  }

  if (!response.ok || data.ok === false || (platform.phase2 && writing && data.ok !== true)) {
    if (platform.phase2 && (response.status === 401 || response.status === 403)) csrfToken = undefined;
    const error = new Error(data.error || `请求失败（HTTP ${response.status}）`) as ApiError;
    error.requestId = response.headers.get('X-Request-ID') || requestId;
    error.status = response.status;
    error.path = path;
    throw error;
  }
  return data;
}
