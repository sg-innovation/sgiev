import { inr } from '@/components/site/station-bits';
import { router } from '@inertiajs/react';
import { BatteryCharging, Clock, Gauge, Hand, LoaderCircle, PlugZap, Zap } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

interface Snapshot {
    status: string;
    startedAt: string;
    startedIso: string;
    minutes: number;
    energy: number;
    measured: boolean;
    power: number;
    powerMeasured: boolean;
    soc: number | null;
    target: number | null;
    progress: number | null;
    finishAt: string | null;
    readingAt: string | null;
    cost: number | null;
    prepaid: number | null;
}
interface Live {
    state: 'charging' | 'finished' | 'waiting_start' | 'not_started';
    session: Snapshot | null;
    stopRequestedAt: string | null;
    canRequestStop: boolean;
    updatedAt: string;
}

/** C9: the customer's live charging card — energy, time, power, cost so far and an estimated finish. Refreshes every 5 s. */
export default function LiveSession({ code }: { code: string }) {
    const [live, setLive] = useState<Live | null>(null);
    const [now, setNow] = useState(Date.now());
    const [stopping, setStopping] = useState(false);

    const load = useCallback(async () => {
        try {
            const r = await fetch(`/account/bookings/${code}/live`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (r.ok) setLive(await r.json());
        } catch {
            /* keep the last snapshot */
        }
    }, [code]);

    useEffect(() => {
        load();
        const t = setInterval(load, 5000);
        const c = setInterval(() => setNow(Date.now()), 1000);
        return () => {
            clearInterval(t);
            clearInterval(c);
        };
    }, [load]);

    // When the session finishes, reload the page so the final bill appears.
    useEffect(() => {
        if (live?.state === 'finished') router.reload();
    }, [live?.state]);

    if (!live || live.state === 'not_started') return null;

    if (live.state === 'waiting_start') {
        return (
            <section className="flex items-center gap-4 rounded-3xl border border-electric/30 bg-electric/5 p-6">
                <PlugZap className="size-10 shrink-0 text-electric" />
                <div>
                    <h2 className="font-display text-lg font-semibold text-foreground">Plug in your vehicle</h2>
                    <p className="text-sm text-muted-foreground">You’re checked in. This page shows your charge live as soon as the station starts the session.</p>
                </div>
            </section>
        );
    }

    const s = live.session!;
    const secs = Math.max(0, Math.floor((now - new Date(s.startedIso).getTime()) / 1000));
    const hh = Math.floor(secs / 3600);
    const mm = String(Math.floor((secs % 3600) / 60)).padStart(2, '0');
    const ss = String(secs % 60).padStart(2, '0');
    const pct = s.progress ?? 0;
    const refund = s.prepaid !== null && s.cost !== null ? Math.max(0, s.prepaid - s.cost) : null;

    return (
        <section className="overflow-hidden rounded-3xl border border-emerald-500/30 bg-card">
            <div className="bg-gradient-to-br from-slate-900 via-slate-900 to-emerald-950 p-6 text-white">
                <div className="flex items-center justify-between gap-3">
                    <p className="flex items-center gap-2 text-sm font-semibold text-emerald-300">
                        <span className="relative flex size-2.5"><span className="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-75" /><span className="relative inline-flex size-2.5 rounded-full bg-emerald-400" /></span>
                        Charging now
                    </p>
                    <p className="text-xs text-white/60">Updated {live.updatedAt}</p>
                </div>
                <div className="mt-5 flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p className="font-display text-5xl font-extrabold tabular-nums">
                            {s.energy.toFixed(2)}
                            <span className="ml-1 text-xl font-semibold text-white/70">kWh</span>
                        </p>
                        <p className="mt-1 text-sm text-white/70">
                            {s.target ? `of ≈ ${s.target.toFixed(1)} kWh planned` : 'delivered so far'}
                            {!s.measured && ' · estimated until the next meter reading'}
                        </p>
                    </div>
                    <p className="font-mono text-2xl font-bold tabular-nums text-white/90">
                        <Clock className="mr-1 inline size-5 align-[-2px] text-white/60" />
                        {hh > 0 && `${hh}:`}{mm}:{ss}
                    </p>
                </div>
                {s.progress !== null && (
                    <div className="mt-4 h-2.5 overflow-hidden rounded-full bg-white/10" role="progressbar" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100} aria-label="Charge progress">
                        <div className="h-full rounded-full bg-gradient-to-r from-emerald-400 to-electric transition-all duration-700" style={{ width: `${pct}%` }} />
                    </div>
                )}
            </div>
            <dl className="grid grid-cols-2 gap-px bg-border sm:grid-cols-4">
                <Cell icon={Zap} label={s.powerMeasured ? 'Power' : 'Power (est.)'} value={`${s.power} kW`} />
                <Cell icon={BatteryCharging} label="Battery" value={s.soc !== null ? `${s.soc}%` : '—'} />
                <Cell icon={Gauge} label="Cost so far" value={s.cost !== null ? inr(s.cost) : '—'} sub={refund !== null && refund > 0 ? `≈ ${inr(refund)} back if you stop now` : undefined} />
                <Cell icon={Clock} label="Estimated finish" value={s.finishAt ?? '—'} />
            </dl>
            <div className="flex flex-wrap items-center justify-between gap-3 p-5">
                <p className="text-xs text-muted-foreground">
                    Started {s.startedAt}
                    {s.readingAt ? ` · last meter reading ${s.readingAt}` : ''}. You pay only for the energy delivered; any difference is refunded.
                </p>
                {live.stopRequestedAt ? (
                    <p className="text-sm font-medium text-amber-700 dark:text-amber-300">Stop requested at {live.stopRequestedAt}. Staff are on their way.</p>
                ) : (
                    live.canRequestStop && (
                        <button type="button" disabled={stopping}
                            onClick={() => confirm('Ask the station staff to stop charging now?') && router.post(`/account/bookings/${code}/stop-request`, {}, { preserveScroll: true, onStart: () => setStopping(true), onFinish: () => { setStopping(false); load(); } })}
                            className="inline-flex items-center gap-2 rounded-full border border-red-500/40 px-4 py-2 text-sm font-semibold text-red-600 hover:bg-red-500/10 disabled:opacity-60 dark:text-red-300">
                            {stopping ? <LoaderCircle className="size-4 animate-spin" /> : <Hand className="size-4" />} Stop charging
                        </button>
                    )
                )}
            </div>
        </section>
    );
}

function Cell({ icon: Icon, label, value, sub }: { icon: typeof Zap; label: string; value: string; sub?: string }) {
    return (
        <div className="bg-card p-4">
            <dt className="flex items-center gap-1.5 text-xs text-muted-foreground">
                <Icon className="size-4" /> {label}
            </dt>
            <dd className="mt-1 font-display text-lg font-bold text-foreground tabular-nums">{value}</dd>
            {sub && <dd className="text-xs text-emerald-700 dark:text-emerald-300">{sub}</dd>}
        </div>
    );
}
