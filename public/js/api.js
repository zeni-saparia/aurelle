// API client: attaches the access token, refreshes it once on 401, and normalises errors.
const BASE = (window.AURELLE_API_URL || '/api').replace(/\/$/, '');
const KEY = 'aurelle.session';

// "Remember me" keeps the session in localStorage; otherwise it lives in sessionStorage and ends with the tab.
const read = st => { try { return JSON.parse(st.getItem(KEY)); } catch { return null; } };
export const session = {
  get() { return read(sessionStorage) || read(localStorage); },
  set(s, remember) {
    const persist = remember ?? !read(sessionStorage);
    (persist ? localStorage : sessionStorage).setItem(KEY, JSON.stringify(s));
    (persist ? sessionStorage : localStorage).removeItem(KEY);
  },
  clear() { localStorage.removeItem(KEY); sessionStorage.removeItem(KEY); },
};

export class ApiError extends Error {
  constructor(message, status, data) { super(message); this.status = status; this.data = data; }
}

let refreshing = null;
async function refresh() {
  const s = session.get();
  if (!s?.refresh_token) return false;
  refreshing ||= fetch(BASE + '/auth/refresh', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ refresh_token: s.refresh_token }) })
    .then(async r => { if (!r.ok) { session.clear(); return false; } session.set((await r.json()).session); return true; })
    .catch(() => false).finally(() => { refreshing = null; });
  return refreshing;
}

export async function api(path, { method = 'GET', body, query, form, retry = true } = {}) {
  const url = new URL(BASE + path, location.origin);
  if (query) for (const [k, v] of Object.entries(query)) if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, v);
  const headers = {};
  const s = session.get();
  if (s?.access_token) headers.Authorization = 'Bearer ' + s.access_token;
  if (body) headers['Content-Type'] = 'application/json';
  let res;
  try {
    res = await fetch(url, { method, headers, body: form || (body ? JSON.stringify(body) : undefined) });
  } catch {
    throw new ApiError('Cannot reach the server. Check your connection and try again.', 0);
  }
  if (res.status === 401 && retry && s?.refresh_token && await refresh()) return api(path, { method, body, query, form, retry: false });
  const raw = await res.text();
let data = null;

try {
  data = raw ? JSON.parse(raw) : null;
} catch {
  throw new ApiError(
    `Server returned an invalid response (HTTP ${res.status}).`,
    res.status
  );
}

if (!data) {
  throw new ApiError(
    `Server returned an empty response (HTTP ${res.status}). Check the API deployment.`,
    res.status
  );
}
  if (!res.ok) throw new ApiError(data?.error || 'Something went wrong.', res.status, data);
  return data;
}
