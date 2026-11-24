/** Small JSON helpers that send Laravel's XSRF token for same-origin requests. */
function xsrf(): string {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

export async function getJson<T>(url: string, signal?: AbortSignal): Promise<T> {
    const r = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, signal, credentials: 'same-origin' });
    if (!r.ok) throw new Error(`HTTP ${r.status}`);
    return r.json();
}

export async function postJson<T>(url: string, body: unknown, signal?: AbortSignal): Promise<T> {
    const r = await fetch(url, {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
        body: JSON.stringify(body),
        signal,
        credentials: 'same-origin',
    });
    if (!r.ok) throw new Error(`HTTP ${r.status}`);
    return r.json();
}
