import { cn } from '@/lib/utils';
import { Head, router } from '@inertiajs/react';
import { Check, ChevronRight, Clipboard, Code2, Download, KeyRound, LoaderCircle, LogOut, Play, Search } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

interface Param { name: string; in: 'path' | 'query'; type: string; required: boolean; example: unknown; description: string }
interface Endpoint { method: string; path: string; title: string; auth: boolean; description?: string; params: Param[]; body?: unknown; response?: unknown; multipart?: boolean }
interface Group { name: string; description: string; endpoints: Endpoint[] }
interface Props {
    developer: { name: string; company: string | null };
    baseUrl: string;
    groups: Group[];
    sandboxTokens: boolean;
    testPhone: string | null;
    otpDebug: boolean;
}

const METHOD_TONE: Record<string, string> = {
    GET: 'bg-sky-500/10 text-sky-700 dark:text-sky-300',
    POST: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    PATCH: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    PUT: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    DELETE: 'bg-rose-500/10 text-rose-700 dark:text-rose-300',
};

const anchor = (e: Endpoint) => `${e.method}-${e.path}`.replace(/[^a-z0-9]+/gi, '-').toLowerCase();
const pretty = (v: unknown) => JSON.stringify(v, null, 2);
const store = {
    get: (k: string) => { try { return sessionStorage.getItem(k) ?? ''; } catch { return ''; } },
    set: (k: string, v: string) => { try { sessionStorage.setItem(k, v); } catch { /* private mode */ } },
};

function CopyButton({ text }: { text: string }) {
    const [done, setDone] = useState(false);
    return (
        <button type="button" onClick={() => { navigator.clipboard?.writeText(text); setDone(true); setTimeout(() => setDone(false), 1200); }} className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs text-muted-foreground hover:bg-muted hover:text-foreground" aria-label="Copy">
            {done ? <Check className="size-3.5" /> : <Clipboard className="size-3.5" />} {done ? 'Copied' : 'Copy'}
        </button>
    );
}

function Code({ value, label }: { value: string; label: string }) {
    return (
        <div className="overflow-hidden rounded-xl border border-border bg-[#0b1220]">
            <div className="flex items-center justify-between border-b border-white/10 px-3 py-1.5 text-xs text-slate-400">
                <span>{label}</span>
                <CopyButton text={value} />
            </div>
            <pre className="max-h-96 overflow-auto p-3 text-xs leading-relaxed text-slate-100">{value}</pre>
        </div>
    );
}

interface Result { status: number; ms: number; body: string; headers: [string, string][] }

/** The live console for one endpoint: fills path/query/body from the examples and shows the real response. */
function TryIt({ e, baseUrl, token }: { e: Endpoint; baseUrl: string; token: string }) {
    const [values, setValues] = useState<Record<string, string>>(() => Object.fromEntries(e.params.map((p) => [p.name, p.example === null || p.example === undefined ? '' : String(p.example)])));
    const [body, setBody] = useState(e.body !== undefined && !e.multipart ? pretty(e.body) : '');
    const [file, setFile] = useState<File | null>(null);
    const [busy, setBusy] = useState(false);
    const [res, setRes] = useState<Result | null>(null);

    const url = useMemo(() => {
        let path = e.path;
        e.params.filter((p) => p.in === 'path').forEach((p) => { path = path.replace(`{${p.name}}`, encodeURIComponent(values[p.name] ?? '')); });
        const q = new URLSearchParams();
        e.params.filter((p) => p.in === 'query' && values[p.name] !== '').forEach((p) => q.set(p.name, values[p.name]));
        return `${baseUrl}${path}${q.toString() ? `?${q}` : ''}`;
    }, [e, values, baseUrl]);

    const send = async () => {
        setBusy(true);
        const headers: Record<string, string> = { Accept: 'application/json' };
        if (token) headers.Authorization = `Bearer ${token}`;
        let payload: BodyInit | undefined;
        if (e.multipart) {
            const fd = new FormData();
            if (file) fd.append('photo', file);
            payload = fd;
        } else if (e.method !== 'GET' && body.trim()) {
            headers['Content-Type'] = 'application/json';
            payload = body;
        }
        const started = performance.now();
        try {
            const r = await fetch(url, { method: e.method, headers, body: payload, credentials: 'omit' });
            const text = await r.text();
            let shown = text;
            try { shown = pretty(JSON.parse(text)); } catch { /* not JSON */ }
            setRes({ status: r.status, ms: Math.round(performance.now() - started), body: shown, headers: [...r.headers.entries()].filter(([k]) => ['content-type', 'x-ratelimit-limit', 'x-ratelimit-remaining', 'retry-after'].includes(k)) });
        } catch (err) {
            setRes({ status: 0, ms: Math.round(performance.now() - started), body: String(err), headers: [] });
        } finally {
            setBusy(false);
        }
    };

    const curl = [`curl -X ${e.method} '${url}'`, `  -H 'Accept: application/json'`, ...(e.auth ? [`  -H 'Authorization: Bearer ${token || '<token>'}'`] : []),
        ...(e.multipart ? [`  -F 'photo=@photo.jpg'`] : body.trim() && e.method !== 'GET' ? [`  -H 'Content-Type: application/json'`, `  -d '${body.replace(/\s*\n\s*/g, ' ')}'`] : [])].join(' \\\n');

    return (
        <div className="space-y-3 rounded-2xl border border-border bg-muted/30 p-4">
            <p className="flex items-center gap-2 text-sm font-semibold text-foreground"><Play className="size-4 text-electric" /> Try it</p>
            {e.params.length > 0 && (
                <div className="grid gap-2 sm:grid-cols-2">
                    {e.params.map((p) => (
                        <label key={p.name} className="grid gap-1 text-xs">
                            <span className="font-medium text-foreground">{p.name} <span className="text-muted-foreground">({p.in}{p.required ? ', required' : ''})</span></span>
                            <input value={values[p.name] ?? ''} onChange={(ev) => setValues({ ...values, [p.name]: ev.target.value })} className="h-9 rounded-lg border border-input bg-background px-2.5 font-mono text-xs text-foreground outline-none focus:border-electric" />
                        </label>
                    ))}
                </div>
            )}
            {e.multipart ? (
                <label className="grid gap-1 text-xs"><span className="font-medium text-foreground">photo (file)</span><input type="file" accept="image/*" onChange={(ev) => setFile(ev.target.files?.[0] ?? null)} className="text-xs" /></label>
            ) : e.method !== 'GET' && e.body !== undefined ? (
                <label className="grid gap-1 text-xs">
                    <span className="font-medium text-foreground">Body (JSON)</span>
                    <textarea value={body} onChange={(ev) => setBody(ev.target.value)} spellCheck={false} rows={Math.min(16, body.split('\n').length + 1)} className="rounded-lg border border-input bg-background p-2.5 font-mono text-xs text-foreground outline-none focus:border-electric" />
                </label>
            ) : null}
            <div className="flex flex-wrap items-center gap-2">
                <button type="button" onClick={send} disabled={busy} className="inline-flex h-9 items-center gap-2 rounded-lg bg-foreground px-4 text-sm font-semibold text-background disabled:opacity-50">
                    {busy ? <LoaderCircle className="size-4 animate-spin" /> : <Play className="size-4" />} Send request
                </button>
                {e.auth && !token && <span className="text-xs text-amber-700 dark:text-amber-300">Needs a token: get one at the top of the page.</span>}
                <span className="truncate font-mono text-xs text-muted-foreground">{url}</span>
            </div>
            {res && (
                <div className="space-y-2">
                    <div className="flex flex-wrap items-center gap-2 text-xs">
                        <span className={cn('rounded-full px-2.5 py-0.5 font-semibold', res.status >= 200 && res.status < 300 ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300' : 'bg-rose-500/15 text-rose-700 dark:text-rose-300')}>{res.status || 'Network error'}</span>
                        <span className="text-muted-foreground">{res.ms} ms</span>
                        {res.headers.map(([k, v]) => <span key={k} className="font-mono text-muted-foreground">{k}: {v}</span>)}
                    </div>
                    <Code value={res.body} label="Response" />
                </div>
            )}
            <details className="text-xs"><summary className="cursor-pointer text-muted-foreground hover:text-foreground">cURL</summary><div className="mt-2"><Code value={curl} label="Shell" /></div></details>
        </div>
    );
}

export default function DeveloperPortal({ developer, baseUrl, groups, sandboxTokens, testPhone, otpDebug }: Props) {
    const [token, setToken] = useState('');
    const [filter, setFilter] = useState('');
    const [sandbox, setSandbox] = useState<{ busy: boolean; note: string }>({ busy: false, note: '' });

    useEffect(() => setToken(store.get('transev-api-token')), []);
    useEffect(() => store.set('transev-api-token', token), [token]);

    const getSandbox = async () => {
        setSandbox({ busy: true, note: '' });
        try {
            const csrf = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
            const r = await fetch('/developers/sandbox-token', { method: 'POST', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrf } });
            const j = await r.json();
            if (r.ok) {
                setToken(j.token);
                setSandbox({ busy: false, note: `Token for sandbox customer “${j.user.name}”, valid until ${new Date(j.expires_at).toLocaleString()}.` });
            } else setSandbox({ busy: false, note: j.message ?? 'Could not create a token.' });
        } catch {
            setSandbox({ busy: false, note: 'Could not create a token.' });
        }
    };

    const q = filter.trim().toLowerCase();
    const shown = groups.map((g) => ({ ...g, endpoints: g.endpoints.filter((e) => !q || `${e.method} ${e.path} ${e.title}`.toLowerCase().includes(q)) })).filter((g) => g.endpoints.length > 0);

    return (
        <div className="min-h-svh bg-background">
            <Head title="Trans EV API · Developer portal">
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <header className="sticky top-0 z-20 border-b border-border bg-background/90 backdrop-blur">
                <div className="mx-auto flex max-w-7xl items-center gap-3 px-4 py-3">
                    <span className="flex size-8 items-center justify-center rounded-lg bg-electric/10 text-electric"><Code2 className="size-4" /></span>
                    <div className="min-w-0">
                        <p className="font-display text-sm font-bold text-foreground">Trans EV API v1</p>
                        <p className="truncate text-xs text-muted-foreground">Signed in as {developer.name}{developer.company ? ` · ${developer.company}` : ''}</p>
                    </div>
                    <a href="/developers/openapi.json" className="ml-auto inline-flex items-center gap-1.5 rounded-lg border border-border px-3 py-1.5 text-xs font-medium text-foreground hover:bg-muted"><Download className="size-3.5" /> OpenAPI</a>
                    <button type="button" onClick={() => router.post('/developers/logout')} className="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs text-muted-foreground hover:bg-muted hover:text-foreground"><LogOut className="size-3.5" /> Leave</button>
                </div>
            </header>

            <div className="mx-auto grid max-w-7xl gap-8 px-4 py-8 lg:grid-cols-[240px_1fr]">
                <nav className="lg:sticky lg:top-20 lg:max-h-[calc(100svh-6rem)] lg:overflow-auto" aria-label="Endpoints">
                    <label className="relative block">
                        <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                        <input value={filter} onChange={(e) => setFilter(e.target.value)} placeholder="Find an endpoint" className="h-9 w-full rounded-lg border border-input bg-background pr-2 pl-8 text-sm outline-none focus:border-electric" />
                    </label>
                    <ul className="mt-4 space-y-4 text-sm">
                        {shown.map((g) => (
                            <li key={g.name}>
                                <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">{g.name}</p>
                                <ul className="mt-1.5 space-y-0.5">
                                    {g.endpoints.map((e) => (
                                        <li key={anchor(e)}>
                                            <a href={`#${anchor(e)}`} className="flex items-center gap-2 rounded-md px-2 py-1 text-foreground/80 hover:bg-muted hover:text-foreground">
                                                <span className={cn('w-12 shrink-0 rounded px-1 text-center font-mono text-[10px] font-bold', METHOD_TONE[e.method])}>{e.method}</span>
                                                <span className="truncate">{e.title}</span>
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                            </li>
                        ))}
                    </ul>
                </nav>

                <main className="min-w-0 space-y-10">
                    <section className="rounded-3xl border border-border bg-card p-6">
                        <h1 className="font-display text-2xl font-bold text-foreground">Build the Trans EV apps</h1>
                        <p className="mt-2 text-sm text-muted-foreground">Base URL <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-xs text-foreground">{baseUrl}</code>. Every example below can be sent from this page; responses are real.</p>
                        <div className="mt-5 grid gap-3">
                            <label className="grid gap-1.5 text-sm">
                                <span className="flex items-center gap-2 font-medium text-foreground"><KeyRound className="size-4 text-electric" /> Bearer token for signed-in calls</span>
                                <input value={token} onChange={(e) => setToken(e.target.value.trim())} placeholder="Paste a token from /auth/otp/verify, or get a sandbox token" className="h-10 rounded-lg border border-input bg-background px-3 font-mono text-xs text-foreground outline-none focus:border-electric" />
                            </label>
                            <div className="flex flex-wrap items-center gap-2">
                                {sandboxTokens && (
                                    <button type="button" onClick={getSandbox} disabled={sandbox.busy} className="inline-flex h-9 items-center gap-2 rounded-lg bg-electric px-4 text-sm font-semibold text-white disabled:opacity-50">
                                        {sandbox.busy ? <LoaderCircle className="size-4 animate-spin" /> : <KeyRound className="size-4" />} Get a sandbox token
                                    </button>
                                )}
                                {token && <button type="button" onClick={() => setToken('')} className="h-9 rounded-lg px-3 text-sm text-muted-foreground hover:bg-muted">Clear token</button>}
                                {sandbox.note && <span className="text-xs text-muted-foreground">{sandbox.note}</span>}
                            </div>
                            <ul className="list-disc space-y-1 pl-5 text-xs text-muted-foreground">
                                {otpDebug && <li>This is a test environment: the <code>/auth/otp</code> response includes <code>debug_code</code>, so you can sign in without an SMS.</li>}
                                {testPhone && <li>Test number for app-store reviewers: <code>{testPhone}</code> (fixed code, no SMS). Ask Trans EV for the code.</li>}
                                <li>The token is kept only in this browser tab. Payments in the sandbox use test gateways.</li>
                            </ul>
                        </div>
                    </section>

                    {shown.map((g) => (
                        <section key={g.name} className="space-y-6">
                            <div>
                                <h2 className="font-display text-xl font-bold text-foreground">{g.name}</h2>
                                <p className="mt-1 text-sm text-muted-foreground" dangerouslySetInnerHTML={{ __html: g.description.replace('{base}', baseUrl.replace(/\/api\/v1$/, '')).replace(/`([^`]+)`/g, '<code>$1</code>').replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>') }} />
                            </div>
                            {g.endpoints.map((e) => (
                                <article key={anchor(e)} id={anchor(e)} className="scroll-mt-24 space-y-4 rounded-3xl border border-border bg-card p-5">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className={cn('rounded-md px-2 py-0.5 font-mono text-xs font-bold', METHOD_TONE[e.method])}>{e.method}</span>
                                        <code className="font-mono text-sm text-foreground">{e.path}</code>
                                        {e.auth && <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] text-muted-foreground">token</span>}
                                    </div>
                                    <div>
                                        <h3 className="flex items-center gap-1 font-semibold text-foreground"><ChevronRight className="size-4 text-electric" /> {e.title}</h3>
                                        {e.description && <p className="mt-1 text-sm text-muted-foreground">{e.description}</p>}
                                    </div>
                                    {e.params.length > 0 && (
                                        <div className="overflow-x-auto">
                                            <table className="w-full text-left text-xs">
                                                <thead className="text-muted-foreground"><tr><th className="py-1 pr-3 font-medium">Parameter</th><th className="pr-3 font-medium">In</th><th className="pr-3 font-medium">Type</th><th className="font-medium">Description</th></tr></thead>
                                                <tbody className="divide-y divide-border">
                                                    {e.params.map((p) => (
                                                        <tr key={p.name}><td className="py-1.5 pr-3 font-mono text-foreground">{p.name}{p.required && <span className="text-rose-500">*</span>}</td><td className="pr-3 text-muted-foreground">{p.in}</td><td className="pr-3 text-muted-foreground">{p.type}</td><td className="text-muted-foreground">{p.description}</td></tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    )}
                                    <div className="grid gap-3 xl:grid-cols-2">
                                        {e.body !== undefined && <Code value={e.multipart ? 'multipart/form-data: photo=<file>' : pretty(e.body)} label="Request body" />}
                                        {e.response !== undefined && <Code value={pretty(e.response)} label="Example response" />}
                                    </div>
                                    <TryIt e={e} baseUrl={baseUrl} token={token} />
                                </article>
                            ))}
                        </section>
                    ))}
                </main>
            </div>
        </div>
    );
}
