import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { cn } from '@/lib/utils';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle, Banknote, BatteryCharging, Gauge, Camera, Car, CheckCircle2, Clock, FileText, LayoutDashboard, LoaderCircle, Phone, Play, PlugZap, Plus, QrCode, RefreshCw, Smartphone, Square, UserX, X, Zap,
} from 'lucide-react';
import { type FormEvent, type ReactNode, useEffect, useRef, useState } from 'react';

interface Session { uuid: string; status: string; startedAt: string; startedIso: string; meterStart: number | null; energy: number | null; minutes: number; lastReading: { at: string; kwh: number | null; soc: number | null; power: number | null } | null }
interface Arrival {
    code: string; status: string; statusLabel: string; start: string; end: string; startIso: string; endIso: string;
    customer: string | null; phone: string | null; vehicle: string; registration: string | null; charger: string | null; connector: string | null;
    batteryFrom: number; batteryTo: number; estKwh: number; checkedInAt: string | null; blocker: string | null; canNoShow: boolean; session: Session | null;
    source: string; settlement: string | null;
    bill: { kind: 'walk_in' | 'final'; total: number; due: number; prepaid: number | null; outcome: string | null; taxable: number | null; tax: number | null; lines: { label: string; qty: number; unit: string; amount: number }[]; paidBy: string | null; invoiceUrl: string | null } | null;
}
interface WalkInOptions {
    connectors: { id: number; label: string; type: string; powerKw: number; maxMinutes: number; freeUntil: string | null; price: string | null }[];
    durations: number[];
}
interface ConnectorRow {
    id: number; no: number; type: string; live: string | null; liveLabel: string | null; tone: string | null; enabled: boolean; note: string | null; since: string | null;
    session: { uuid: string; booking: string | null; registration: string | null; startedIso: string; meterStart: number | null } | null;
    next: { code: string; at: string } | null;
}
interface ChargerRow { code: string; type: string; powerKw: number; connectors: ConnectorRow[] }
interface Props {
    stations: { id: number; name: string; city: string }[];
    station: { id: number; name: string } | null;
    today: string;
    arrivals: Arrival[];
    chargers: ChargerRow[];
    can: { checkIn: boolean; sessions: boolean; status: boolean; walkIns: boolean };
    walkIn: WalkInOptions | null;
    stopReasons: Record<string, string>;
    manualStatuses: Record<string, string>;
    noShowMinutes: number;
}

const TONE: Record<string, string> = {
    success: 'bg-emerald-500/15 text-emerald-700 ring-emerald-500/30 dark:text-emerald-300',
    warning: 'bg-amber-500/15 text-amber-700 ring-amber-500/30 dark:text-amber-300',
    danger: 'bg-red-500/15 text-red-700 ring-red-500/30 dark:text-red-300',
    info: 'bg-sky-500/15 text-sky-700 ring-sky-500/30 dark:text-sky-300',
    gray: 'bg-muted text-muted-foreground ring-border',
};
const STATUS_TONE: Record<string, string> = { confirmed: 'info', checked_in: 'warning', completed: 'success', no_show: 'gray' };

function useNow(ms = 1000) {
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const t = setInterval(() => setNow(Date.now()), ms);
        return () => clearInterval(t);
    }, [ms]);
    return now;
}

const elapsed = (iso: string, now: number) => {
    const m = Math.max(0, Math.floor((now - new Date(iso).getTime()) / 60000));
    return m >= 60 ? `${Math.floor(m / 60)}h ${m % 60}m` : `${m} min`;
};

export default function StaffConsole(props: Props) {
    const { station, stations, today, arrivals, chargers, can } = props;
    const { flash, errors } = usePage<{ flash: { success?: string; error?: string }; errors: Record<string, string> }>().props;
    const [tab, setTab] = useState<'arrivals' | 'chargers'>('arrivals');
    const [startFor, setStartFor] = useState<Arrival | null>(null);
    const [stopFor, setStopFor] = useState<{ uuid: string; label: string; meterStart: number | null; startedIso: string } | null>(null);
    const [statusFor, setStatusFor] = useState<ConnectorRow | null>(null);
    const [walkInOpen, setWalkInOpen] = useState(false);
    const [collectFor, setCollectFor] = useState<Arrival | null>(null);
    const [readingFor, setReadingFor] = useState<{ uuid: string; label: string; meterStart: number | null } | null>(null);
    const [toast, setToast] = useState<{ kind: 'ok' | 'err'; text: string } | null>(null);
    const [refreshing, setRefreshing] = useState(false);

    // Keep the screen current while it sits on the counter.
    useEffect(() => {
        const t = setInterval(() => router.reload({ only: ['arrivals', 'chargers', 'walkIn'], onStart: () => setRefreshing(true), onFinish: () => setRefreshing(false) }), 30_000);
        return () => clearInterval(t);
    }, []);

    useEffect(() => {
        const err = Object.values(errors ?? {})[0];
        if (flash?.success) setToast({ kind: 'ok', text: flash.success });
        else if (err) setToast({ kind: 'err', text: err });
        else if (flash?.error) setToast({ kind: 'err', text: flash.error });
    }, [flash, errors]);

    useEffect(() => {
        if (!toast) return;
        const t = setTimeout(() => setToast(null), toast.kind === 'ok' ? 4000 : 8000);
        return () => clearTimeout(t);
    }, [toast]);

    const post = (url: string, data: Record<string, unknown> = {}, onSuccess?: () => void) =>
        router.post(url, data as never, { preserveScroll: true, preserveState: true, onSuccess });

    const counts = {
        waiting: arrivals.filter((a) => a.status === 'confirmed').length,
        charging: arrivals.filter((a) => a.session?.status === 'active').length,
        done: arrivals.filter((a) => a.status === 'completed').length,
        unpaid: arrivals.filter((a) => a.settlement === 'due' && a.status === 'completed').length,
    };

    return (
        <div className="min-h-screen bg-muted/40 pb-24 text-foreground">
            <Head title="Staff console" />
            <header className="sticky top-0 z-30 border-b border-border bg-background/95 backdrop-blur">
                <div className="mx-auto flex max-w-3xl items-center gap-3 px-4 py-3">
                    <Link href="/" aria-label="Trans EV home" className="shrink-0">
                        <img src="/brand/logo-mark.svg" alt="" className="size-9" />
                    </Link>
                    <div className="min-w-0 flex-1">
                        {stations.length > 1 ? (
                            <select
                                aria-label="Station"
                                value={station?.id ?? ''}
                                onChange={(e) => router.get('/staff', { station: e.target.value }, { preserveState: false })}
                                className="w-full truncate rounded-lg border border-border bg-background px-2 py-1.5 text-sm font-semibold"
                            >
                                {stations.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name} · {s.city}
                                    </option>
                                ))}
                            </select>
                        ) : (
                            <p className="truncate font-semibold">{station?.name ?? 'No station assigned'}</p>
                        )}
                        <p className="text-xs text-muted-foreground">{today}</p>
                    </div>
                    <button type="button" aria-label="Refresh" onClick={() => router.reload({ only: ['arrivals', 'chargers'] })} className="rounded-full p-2 text-muted-foreground hover:bg-muted">
                        <RefreshCw className={cn('size-5', refreshing && 'animate-spin')} />
                    </button>
                    <a href="/admin" aria-label="Admin" className="rounded-full p-2 text-muted-foreground hover:bg-muted">
                        <LayoutDashboard className="size-5" />
                    </a>
                </div>
                <div className="mx-auto grid max-w-3xl grid-cols-2 gap-1 px-4 pb-2">
                    {(['arrivals', 'chargers'] as const).map((t) => (
                        <button
                            key={t}
                            type="button"
                            onClick={() => setTab(t)}
                            className={cn('rounded-xl py-2 text-sm font-semibold', tab === t ? 'bg-foreground text-background' : 'text-muted-foreground hover:bg-muted')}
                        >
                            {t === 'arrivals' ? `Arrivals · ${counts.waiting} waiting` : `Chargers · ${counts.charging} charging`}
                        </button>
                    ))}
                </div>
            </header>

            <main className="mx-auto max-w-3xl space-y-4 px-4 py-4">
                {!station && (
                    <div className="rounded-2xl border border-border bg-card p-6 text-sm text-muted-foreground">You are not assigned to any station yet. Ask your company admin.</div>
                )}

                {station && (can.checkIn || can.walkIns) && (
                    <div className="flex gap-2">
                        <div className="flex-1">{can.checkIn && <ScanBar />}</div>
                        {can.walkIns && props.walkIn && (
                            <button type="button" onClick={() => setWalkInOpen(true)} className="flex h-[60px] shrink-0 items-center gap-2 rounded-2xl bg-gradient-to-r from-volt-600 to-electric px-4 font-semibold text-white shadow-md shadow-volt/20">
                                <Plus className="size-5" /> Walk-in
                            </button>
                        )}
                    </div>
                )}

                {counts.unpaid > 0 && (
                    <p className="flex items-center gap-2 rounded-2xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm font-medium text-amber-800 dark:text-amber-200">
                        <Banknote className="size-5" /> {counts.unpaid} {counts.unpaid > 1 ? 'drivers' : 'driver'} still to pay at the station
                    </p>
                )}

                {station && tab === 'arrivals' && (
                    <>
                        <div className="grid grid-cols-3 gap-2 text-center">
                            <Stat label="To arrive" value={counts.waiting} />
                            <Stat label="Charging" value={counts.charging} />
                            <Stat label="Done" value={counts.done} />
                        </div>
                        {arrivals.length === 0 ? (
                            <div className="rounded-2xl border border-border bg-card p-8 text-center text-sm text-muted-foreground">No bookings for today at this station.</div>
                        ) : (
                            arrivals.map((a) => (
                                <ArrivalCard
                                    key={a.code}
                                    a={a}
                                    can={can}
                                    onCheckIn={() => post(`/staff/bookings/${a.code}/check-in`)}
                                    onStart={() => setStartFor(a)}
                                    onStop={() => a.session && setStopFor({ uuid: a.session.uuid, label: `${a.code} · ${a.registration ?? ''}`, meterStart: a.session.meterStart, startedIso: a.session.startedIso })}
                                    onCollect={() => setCollectFor(a)}
                                    onReading={() => a.session && setReadingFor({ uuid: a.session.uuid, label: `${a.code} · ${a.registration ?? ''}`, meterStart: a.session.meterStart })}
                                    onNoShow={() => confirm(`Mark ${a.code} as a no-show? The customer gets the no-show refund agreed at booking.`) && post(`/staff/bookings/${a.code}/no-show`)}
                                />
                            ))
                        )}
                    </>
                )}

                {station && tab === 'chargers' && (
                    <div className="space-y-4">
                        {chargers.map((c) => (
                            <section key={c.code} className="rounded-2xl border border-border bg-card p-4">
                                <h2 className="flex items-center gap-2 font-semibold">
                                    <Zap className="size-4 text-volt-600" /> <span className="font-mono">{c.code}</span>
                                    <span className="text-sm font-normal text-muted-foreground">
                                        {c.type} · {c.powerKw} kW
                                    </span>
                                </h2>
                                <ul className="mt-3 space-y-2">
                                    {c.connectors.map((k) => (
                                        <ConnectorItem
                                            key={k.id}
                                            k={k}
                                            can={can}
                                            onStatus={() => setStatusFor(k)}
                                            onStop={() => k.session && setStopFor({ uuid: k.session.uuid, label: `${k.session.booking ?? ''} · ${k.session.registration ?? ''}`, meterStart: k.session.meterStart, startedIso: k.session.startedIso })}
                                        />
                                    ))}
                                </ul>
                            </section>
                        ))}
                    </div>
                )}
            </main>

            {toast && (
                <div className="fixed inset-x-0 bottom-4 z-50 flex justify-center px-4">
                    <div role="status" className={cn('flex max-w-md items-start gap-2 rounded-2xl px-4 py-3 text-sm font-medium shadow-xl', toast.kind === 'ok' ? 'bg-emerald-600 text-white' : 'bg-red-600 text-white')}>
                        {toast.kind === 'ok' ? <CheckCircle2 className="size-5 shrink-0" /> : <AlertTriangle className="size-5 shrink-0" />}
                        <span>{toast.text}</span>
                        <button type="button" aria-label="Dismiss" onClick={() => setToast(null)} className="ml-1 opacity-80 hover:opacity-100">
                            <X className="size-4" />
                        </button>
                    </div>
                </div>
            )}

            <StartSheet a={startFor} onClose={() => setStartFor(null)} />
            <StopSheet s={stopFor} reasons={props.stopReasons} onClose={() => setStopFor(null)} />
            <StatusSheet k={statusFor} statuses={props.manualStatuses} onClose={() => setStatusFor(null)} />
            {props.walkIn && <WalkInSheet open={walkInOpen} options={props.walkIn} onClose={() => setWalkInOpen(false)} />}
            <CollectSheet a={collectFor} onClose={() => setCollectFor(null)} />
            <ReadingSheet s={readingFor} onClose={() => setReadingFor(null)} />
        </div>
    );
}

function Stat({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-2xl border border-border bg-card px-3 py-2">
            <p className="font-display text-2xl font-bold tabular-nums">{value}</p>
            <p className="text-xs text-muted-foreground">{label}</p>
        </div>
    );
}

function Chip({ tone, children }: { tone: string; children: ReactNode }) {
    return <span className={cn('inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1', TONE[tone] ?? TONE.gray)}>{children}</span>;
}

function ArrivalCard({ a, can, onCheckIn, onStart, onStop, onNoShow, onCollect, onReading }: { a: Arrival; can: Props['can']; onCheckIn: () => void; onStart: () => void; onStop: () => void; onNoShow: () => void; onCollect: () => void; onReading: () => void }) {
    const now = useNow(15_000);
    const lateBy = a.status === 'confirmed' ? Math.floor((now - new Date(a.startIso).getTime()) / 60000) : 0;
    const active = a.session?.status === 'active';

    return (
        <article className={cn('rounded-2xl border bg-card p-4 shadow-sm', active ? 'border-amber-500/50' : 'border-border')}>
            <div className="flex items-start justify-between gap-3">
                <div>
                    <p className="font-display text-lg font-bold tabular-nums">
                        {a.start} <span className="text-sm font-normal text-muted-foreground">– {a.end}</span>
                    </p>
                    <p className="font-mono text-xs text-muted-foreground">{a.code}</p>
                </div>
                <div className="flex flex-col items-end gap-1">
                    {a.source === 'walk_in' && <Chip tone="info">Walk-in</Chip>}
                    <Chip tone={active ? 'warning' : (STATUS_TONE[a.status] ?? 'gray')}>{active ? 'Charging' : a.status === 'checked_in' ? 'Checked in' : a.statusLabel}</Chip>
                    {lateBy > 0 && a.status === 'confirmed' && <Chip tone="danger">Late {lateBy} min</Chip>}
                </div>
            </div>
            <div className="mt-3 grid grid-cols-2 gap-3 text-sm">
                <div className="flex gap-2">
                    <Car className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                    <div className="min-w-0">
                        <p className="inline-block rounded-md border border-border bg-muted px-1.5 font-mono text-sm font-bold tracking-wider">{a.registration ?? '—'}</p>
                        <p className="truncate text-xs text-muted-foreground">{a.vehicle}</p>
                    </div>
                </div>
                <div className="flex gap-2">
                    <PlugZap className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                    <div className="min-w-0">
                        <p className="font-mono font-semibold">{a.charger}</p>
                        <p className="truncate text-xs text-muted-foreground">{a.connector}</p>
                    </div>
                </div>
                <div className="flex gap-2">
                    <Phone className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                    <div className="min-w-0">
                        <p className="truncate font-medium">{a.customer}</p>
                        {a.phone && (
                            <a href={`tel:${a.phone}`} className="text-xs font-semibold text-volt-600 dark:text-volt">
                                {a.phone}
                            </a>
                        )}
                    </div>
                </div>
                {a.source !== 'walk_in' && (
                    <div className="flex gap-2">
                        <BatteryCharging className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                        <div>
                            <p className="font-medium">
                                {a.batteryFrom}% → {a.batteryTo}%
                            </p>
                            <p className="text-xs text-muted-foreground">≈ {a.estKwh.toFixed(1)} kWh booked</p>
                        </div>
                    </div>
                )}
            </div>

            {active && a.session && (
                <p className="mt-3 flex items-center gap-2 rounded-xl bg-amber-500/10 px-3 py-2 text-sm text-amber-800 dark:text-amber-200">
                    <Clock className="size-4" /> Charging since {a.session.startedAt} · {elapsed(a.session.startedIso, now)}
                    {a.session.meterStart !== null && <span className="text-xs">· meter {a.session.meterStart}</span>}
                </p>
            )}
            {active && a.session?.lastReading && (
                <p className="mt-1 text-xs text-muted-foreground">
                    Last reading {a.session.lastReading.at}
                    {a.session.lastReading.kwh !== null && ` · ${a.session.lastReading.kwh.toFixed(2)} kWh`}
                    {a.session.lastReading.power !== null && ` · ${a.session.lastReading.power} kW`}
                    {a.session.lastReading.soc !== null && ` · ${a.session.lastReading.soc}% battery`}
                </p>
            )}
            {a.status === 'completed' && a.session?.energy !== null && a.session && (
                <p className="mt-3 flex items-center gap-2 rounded-xl bg-emerald-500/10 px-3 py-2 text-sm text-emerald-800 dark:text-emerald-200">
                    <CheckCircle2 className="size-4" /> {a.session.energy?.toFixed(2)} kWh in {a.session.minutes} min
                </p>
            )}
            {a.status === 'confirmed' && a.blocker && <p className="mt-3 text-xs text-muted-foreground">{a.blocker}</p>}
            {a.bill && (
                <div className={cn('mt-3 rounded-xl border p-3 text-sm', a.settlement === 'due' ? 'border-amber-500/40 bg-amber-500/5' : 'border-emerald-500/30 bg-emerald-500/5')}>
                    {a.bill.lines.map((l, i) => (
                        <div key={i} className="flex justify-between gap-2 text-muted-foreground">
                            <span>
                                {l.label} {l.unit === 'kWh' && <span className="text-xs">({l.qty.toFixed(2)} kWh)</span>}
                            </span>
                            <span className="tabular-nums">₹{l.amount.toFixed(2)}</span>
                        </div>
                    ))}
                    {a.bill.tax !== null && (
                        <div className="flex justify-between gap-2 text-muted-foreground">
                            <span>GST</span>
                            <span className="tabular-nums">₹{a.bill.tax.toFixed(2)}</span>
                        </div>
                    )}
                    {a.bill.kind === 'final' ? (
                        <>
                            <div className="mt-1 flex justify-between gap-2 border-t border-border pt-1 font-semibold">
                                <span>Final bill (incl. GST)</span>
                                <span className="tabular-nums">₹{a.bill.total.toFixed(2)}</span>
                            </div>
                            <div className="flex justify-between gap-2 text-muted-foreground">
                                <span>Prepaid</span>
                                <span className="tabular-nums">₹{a.bill.prepaid?.toFixed(2)}</span>
                            </div>
                            {a.bill.outcome && <p className="mt-1 text-xs font-medium text-foreground">{a.bill.outcome}</p>}
                        </>
                    ) : (
                        <div className="mt-1 flex justify-between gap-2 border-t border-border pt-1 text-base font-bold">
                            <span>{a.settlement === 'paid' ? 'Paid' : 'To pay'}</span>
                            <span className="tabular-nums">₹{a.bill.total.toFixed(2)}</span>
                        </div>
                    )}
                    {a.bill.paidBy && <p className="mt-1 text-xs text-emerald-700 dark:text-emerald-300">{a.bill.paidBy}</p>}
                </div>
            )}

            <div className="mt-4 flex flex-wrap gap-2">
                {a.status === 'confirmed' && can.checkIn && !a.blocker && (
                    <Action onClick={onCheckIn} primary icon={<CheckCircle2 className="size-4" />}>
                        Check in
                    </Action>
                )}
                {(a.status === 'checked_in' || (a.status === 'confirmed' && !a.blocker)) && !a.session && can.sessions && (
                    <Action onClick={onStart} primary={a.status === 'checked_in'} icon={<Play className="size-4" />}>
                        Start charging
                    </Action>
                )}
                {active && can.sessions && (
                    <Action onClick={onStop} danger icon={<Square className="size-4" />}>
                        Stop charging
                    </Action>
                )}
                {active && can.sessions && (
                    <Action onClick={onReading} icon={<Gauge className="size-4" />}>
                        Add reading
                    </Action>
                )}
                {a.bill && a.settlement === 'due' && can.walkIns && (
                    <Action onClick={onCollect} primary icon={<Banknote className="size-4" />}>
                        Take payment · ₹{a.bill.due.toFixed(2)}
                    </Action>
                )}
                {a.bill?.invoiceUrl && (
                    <a href={a.bill.invoiceUrl} target="_blank" rel="noopener" className="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-full border border-border bg-background px-4 text-sm font-semibold hover:bg-muted sm:flex-none">
                        <FileText className="size-4" /> Invoice
                    </a>
                )}
                {a.canNoShow && (
                    <Action onClick={onNoShow} icon={<UserX className="size-4" />}>
                        No-show
                    </Action>
                )}
            </div>
        </article>
    );
}

function ConnectorItem({ k, can, onStatus, onStop }: { k: ConnectorRow; can: Props['can']; onStatus: () => void; onStop: () => void }) {
    const now = useNow(15_000);
    return (
        <li className="rounded-xl border border-border p-3">
            <div className="flex items-start justify-between gap-2">
                <div>
                    <p className="font-semibold">
                        #{k.no} · {k.type}
                    </p>
                    {k.session ? (
                        <p className="text-xs text-muted-foreground">
                            {k.session.registration} · {k.session.booking} · {elapsed(k.session.startedIso, now)}
                        </p>
                    ) : k.next ? (
                        <p className="text-xs text-muted-foreground">
                            Next: {k.next.at} · {k.next.code}
                        </p>
                    ) : (
                        <p className="text-xs text-muted-foreground">No more bookings today</p>
                    )}
                    {k.note && <p className="mt-1 text-xs text-muted-foreground italic">“{k.note}”</p>}
                </div>
                <div className="flex flex-col items-end gap-1">
                    <Chip tone={k.enabled ? (k.tone ?? 'gray') : 'gray'}>{k.enabled ? k.liveLabel : 'Disabled by admin'}</Chip>
                    {k.since && <span className="text-[11px] text-muted-foreground">since {k.since}</span>}
                </div>
            </div>
            <div className="mt-3 flex flex-wrap gap-2">
                {k.session && can.sessions && (
                    <Action onClick={onStop} danger icon={<Square className="size-4" />}>
                        Stop
                    </Action>
                )}
                {can.status && (
                    <Action onClick={onStatus} icon={<RefreshCw className="size-4" />}>
                        Change status
                    </Action>
                )}
            </div>
        </li>
    );
}

function Action({ children, onClick, primary, danger, icon }: { children: ReactNode; onClick: () => void; primary?: boolean; danger?: boolean; icon?: ReactNode }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-full px-4 text-sm font-semibold transition sm:flex-none',
                primary && 'bg-gradient-to-r from-volt-600 to-electric text-white shadow-md shadow-volt/20 hover:brightness-110',
                danger && 'bg-red-600 text-white hover:bg-red-700',
                !primary && !danger && 'border border-border bg-background hover:bg-muted',
            )}
        >
            {icon}
            {children}
        </button>
    );
}

function ScanBar() {
    const form = useForm({ input: '' });
    const [camera, setCamera] = useState(false);
    const submit = (e?: FormEvent, value?: string) => {
        e?.preventDefault();
        if (value !== undefined) form.setData('input', value);
        form.transform(() => ({ input: value ?? form.data.input }));
        form.post('/staff/scan', { preserveScroll: true, onSuccess: () => form.reset() });
    };
    const hasDetector = typeof window !== 'undefined' && 'BarcodeDetector' in window;

    return (
        <form onSubmit={submit} className="flex gap-2 rounded-2xl border border-border bg-card p-2">
            <label htmlFor="staff-scan" className="sr-only">
                Booking code or scanned link
            </label>
            <div className="relative flex-1">
                <QrCode className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                <input
                    id="staff-scan"
                    value={form.data.input}
                    onChange={(e) => form.setData('input', e.target.value)}
                    placeholder="Booking code or scan"
                    autoComplete="off"
                    className="h-11 w-full rounded-xl border border-border bg-background pr-2 pl-9 font-mono text-sm"
                />
            </div>
            {hasDetector && (
                <button type="button" onClick={() => setCamera(true)} aria-label="Scan with camera" className="flex size-11 items-center justify-center rounded-xl border border-border hover:bg-muted">
                    <Camera className="size-5" />
                </button>
            )}
            <button type="submit" disabled={form.processing || !form.data.input} className="h-11 rounded-xl bg-foreground px-4 text-sm font-semibold text-background disabled:opacity-50">
                {form.processing ? <LoaderCircle className="size-4 animate-spin" /> : 'Check in'}
            </button>
            {camera && (
                <CameraScanner
                    onClose={() => setCamera(false)}
                    onResult={(v) => {
                        setCamera(false);
                        submit(undefined, v);
                    }}
                />
            )}
        </form>
    );
}

/** Uses the browser's built-in BarcodeDetector (Chrome/Android); other phones use the camera app, which opens the check-in link. */
function CameraScanner({ onClose, onResult }: { onClose: () => void; onResult: (v: string) => void }) {
    const video = useRef<HTMLVideoElement>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        let stream: MediaStream | null = null;
        let stop = false;
        (async () => {
            try {
                stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
                if (!video.current) return;
                video.current.srcObject = stream;
                await video.current.play();
                // eslint-disable-next-line @typescript-eslint/no-explicit-any
                const detector = new (window as any).BarcodeDetector({ formats: ['qr_code'] });
                const loop = async () => {
                    if (stop || !video.current) return;
                    try {
                        const codes = await detector.detect(video.current);
                        if (codes.length) return onResult(codes[0].rawValue);
                    } catch {
                        /* keep trying */
                    }
                    requestAnimationFrame(loop);
                };
                loop();
            } catch {
                setError('Camera not available. Allow camera access, or use your phone’s camera app to scan the QR.');
            }
        })();
        return () => {
            stop = true;
            stream?.getTracks().forEach((t) => t.stop());
        };
    }, [onResult]);

    return (
        <div className="fixed inset-0 z-50 flex flex-col bg-black">
            <video ref={video} playsInline muted className="h-full w-full object-cover" />
            <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
                <div className="size-64 rounded-3xl border-4 border-white/80" />
            </div>
            {error && <p className="absolute inset-x-4 top-20 rounded-2xl bg-red-600 p-4 text-sm text-white">{error}</p>}
            <button type="button" onClick={onClose} className="absolute top-4 right-4 rounded-full bg-white/20 p-3 text-white">
                <X className="size-6" />
            </button>
            <p className="absolute inset-x-0 bottom-10 text-center text-sm text-white/80">Point at the customer’s check-in QR</p>
        </div>
    );
}

function FieldError({ name }: { name: string }) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    return errors?.[name] ? <p className="mt-1 text-xs text-red-600 dark:text-red-400">{errors[name]}</p> : null;
}

function StartSheet({ a, onClose }: { a: Arrival | null; onClose: () => void }) {
    const form = useForm({ meter_start: '' });
    useEffect(() => form.reset(), [a?.code]); // eslint-disable-line react-hooks/exhaustive-deps
    return (
        <Sheet open={!!a} onOpenChange={(o) => !o && onClose()}>
            <SheetContent side="bottom" className="rounded-t-3xl">
                <SheetHeader>
                    <SheetTitle>Start charging</SheetTitle>
                    <SheetDescription>
                        {a?.code} · {a?.registration} · {a?.charger}. Plug in first, then start.
                    </SheetDescription>
                </SheetHeader>
                <form
                    className="space-y-4 px-4 pb-6"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/staff/bookings/${a?.code}/start`, { preserveScroll: true, onSuccess: onClose });
                    }}
                >
                    <div>
                        <label htmlFor="meter_start" className="text-sm font-medium">
                            Meter reading now (kWh) <span className="font-normal text-muted-foreground">optional</span>
                        </label>
                        <input id="meter_start" inputMode="decimal" value={form.data.meter_start} onChange={(e) => form.setData('meter_start', e.target.value)}
                            className="mt-1 h-12 w-full rounded-xl border border-border bg-background px-3 font-mono text-lg" placeholder="e.g. 10234.5" />
                        <FieldError name="meter_start" />
                        <FieldError name="booking" />
                    </div>
                    <button type="submit" disabled={form.processing} className="flex h-12 w-full items-center justify-center gap-2 rounded-full bg-gradient-to-r from-volt-600 to-electric font-semibold text-white disabled:opacity-60">
                        {form.processing ? <LoaderCircle className="size-5 animate-spin" /> : <Play className="size-5" />} Start
                    </button>
                </form>
            </SheetContent>
        </Sheet>
    );
}

function StopSheet({ s, reasons, onClose }: { s: { uuid: string; label: string; meterStart: number | null; startedIso: string } | null; reasons: Record<string, string>; onClose: () => void }) {
    const form = useForm({ meter_stop: '', energy: '', reason: 'completed', note: '' });
    useEffect(() => form.reset(), [s?.uuid]); // eslint-disable-line react-hooks/exhaustive-deps
    const now = useNow(1000);
    const preview = s?.meterStart !== null && s?.meterStart !== undefined && form.data.meter_stop ? Number(form.data.meter_stop) - s.meterStart : null;
    return (
        <Sheet open={!!s} onOpenChange={(o) => !o && onClose()}>
            <SheetContent side="bottom" className="max-h-[90vh] overflow-y-auto rounded-t-3xl">
                <SheetHeader>
                    <SheetTitle>Stop charging</SheetTitle>
                    <SheetDescription>
                        {s?.label} · running {s ? elapsed(s.startedIso, now) : ''}
                    </SheetDescription>
                </SheetHeader>
                <form
                    className="space-y-4 px-4 pb-6"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/staff/sessions/${s?.uuid}/stop`, { preserveScroll: true, onSuccess: onClose });
                    }}
                >
                    {s?.meterStart !== null ? (
                        <div>
                            <label htmlFor="meter_stop" className="text-sm font-medium">
                                Meter reading now (kWh)
                            </label>
                            <input id="meter_stop" inputMode="decimal" required value={form.data.meter_stop} onChange={(e) => form.setData('meter_stop', e.target.value)}
                                className="mt-1 h-12 w-full rounded-xl border border-border bg-background px-3 font-mono text-lg" />
                            <p className="mt-1 text-xs text-muted-foreground">
                                Started at {s?.meterStart} kWh{preview !== null && !Number.isNaN(preview) && <> · delivered <strong className="text-foreground">{preview.toFixed(3)} kWh</strong></>}
                            </p>
                            <FieldError name="meter_stop" />
                        </div>
                    ) : (
                        <div>
                            <label htmlFor="energy" className="text-sm font-medium">
                                Energy delivered (kWh), as shown on the charger
                            </label>
                            <input id="energy" inputMode="decimal" required value={form.data.energy} onChange={(e) => form.setData('energy', e.target.value)}
                                className="mt-1 h-12 w-full rounded-xl border border-border bg-background px-3 font-mono text-lg" />
                            <FieldError name="energy" />
                        </div>
                    )}
                    <div>
                        <label htmlFor="reason" className="text-sm font-medium">
                            Why did charging stop?
                        </label>
                        <select id="reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} className="mt-1 h-12 w-full rounded-xl border border-border bg-background px-3">
                            {Object.entries(reasons).map(([k, v]) => (
                                <option key={k} value={k}>
                                    {v}
                                </option>
                            ))}
                        </select>
                        <FieldError name="reason" />
                    </div>
                    <div>
                        <label htmlFor="note" className="text-sm font-medium">
                            Note <span className="font-normal text-muted-foreground">optional</span>
                        </label>
                        <input id="note" value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} maxLength={255} className="mt-1 h-11 w-full rounded-xl border border-border bg-background px-3" />
                    </div>
                    <FieldError name="session" />
                    <button type="submit" disabled={form.processing} className="flex h-12 w-full items-center justify-center gap-2 rounded-full bg-red-600 font-semibold text-white hover:bg-red-700 disabled:opacity-60">
                        {form.processing ? <LoaderCircle className="size-5 animate-spin" /> : <Square className="size-5" />} Stop and record
                    </button>
                </form>
            </SheetContent>
        </Sheet>
    );
}

function StatusSheet({ k, statuses, onClose }: { k: ConnectorRow | null; statuses: Record<string, string>; onClose: () => void }) {
    const form = useForm({ status: 'available', note: '' });
    useEffect(() => {
        if (k) form.setData({ status: k.live && k.live in statuses ? k.live : 'available', note: k.note ?? '' });
    }, [k?.id]); // eslint-disable-line react-hooks/exhaustive-deps
    return (
        <Sheet open={!!k} onOpenChange={(o) => !o && onClose()}>
            <SheetContent side="bottom" className="rounded-t-3xl">
                <SheetHeader>
                    <SheetTitle>Connector #{k?.no} status</SheetTitle>
                    <SheetDescription>Tell customers and the team what is happening at this connector.</SheetDescription>
                </SheetHeader>
                <form
                    className="space-y-4 px-4 pb-6"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/staff/connectors/${k?.id}/status`, { preserveScroll: true, onSuccess: onClose });
                    }}
                >
                    <div className="grid grid-cols-2 gap-2">
                        {Object.entries(statuses).map(([v, label]) => (
                            <button
                                key={v}
                                type="button"
                                onClick={() => form.setData('status', v)}
                                className={cn('h-12 rounded-xl border text-sm font-semibold', form.data.status === v ? 'border-foreground bg-foreground text-background' : 'border-border hover:bg-muted')}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                    <input value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} maxLength={255} placeholder="Note (e.g. cable damaged, engineer called)"
                        aria-label="Note" className="h-11 w-full rounded-xl border border-border bg-background px-3" />
                    <FieldError name="status" />
                    <button type="submit" disabled={form.processing} className="flex h-12 w-full items-center justify-center gap-2 rounded-full bg-foreground font-semibold text-background disabled:opacity-60">
                        {form.processing ? <LoaderCircle className="size-5 animate-spin" /> : <CheckCircle2 className="size-5" />} Save status
                    </button>
                </form>
            </SheetContent>
        </Sheet>
    );
}

function WalkInSheet({ open, options, onClose }: { open: boolean; options: WalkInOptions; onClose: () => void }) {
    const form = useForm({ connector_id: '', registration_no: '', make: '', model: '', name: '', phone: '', email: '', gstin: '', minutes: 60, meter_start: '', informed: false as boolean });
    const chosen = options.connectors.find((c) => String(c.id) === form.data.connector_id);
    const durations = options.durations.filter((d) => !chosen || d <= chosen.maxMinutes);
    useEffect(() => {
        if (open) form.reset();
    }, [open]); // eslint-disable-line react-hooks/exhaustive-deps
    useEffect(() => {
        if (chosen && form.data.minutes > chosen.maxMinutes) form.setData('minutes', durations[durations.length - 1] ?? chosen.maxMinutes);
    }, [form.data.connector_id]); // eslint-disable-line react-hooks/exhaustive-deps

    const input = 'mt-1 h-11 w-full rounded-xl border border-border bg-background px-3';
    return (
        <Sheet open={open} onOpenChange={(o) => !o && onClose()}>
            <SheetContent side="bottom" className="max-h-[92vh] overflow-y-auto rounded-t-3xl">
                <SheetHeader>
                    <SheetTitle>Walk-in charging</SheetTitle>
                    <SheetDescription>For drivers without a booking. They pay for the energy actually delivered when charging stops.</SheetDescription>
                </SheetHeader>
                <form
                    className="space-y-4 px-4 pb-6"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/staff/walk-ins', { preserveScroll: true, onSuccess: onClose });
                    }}
                >
                    <fieldset>
                        <legend className="text-sm font-medium">Connector</legend>
                        {options.connectors.length === 0 ? (
                            <p className="mt-2 rounded-xl bg-muted p-3 text-sm text-muted-foreground">No connector is free right now.</p>
                        ) : (
                            <div className="mt-2 grid gap-2">
                                {options.connectors.map((c) => (
                                    <button key={c.id} type="button" onClick={() => form.setData('connector_id', String(c.id))}
                                        className={cn('rounded-xl border p-3 text-left', form.data.connector_id === String(c.id) ? 'border-foreground ring-2 ring-foreground' : 'border-border hover:bg-muted')}>
                                        <p className="font-semibold">{c.label}</p>
                                        <p className="text-xs text-muted-foreground">
                                            {c.powerKw} kW · {c.freeUntil ? `free until ${c.freeUntil}` : 'free for the rest of the day'}{c.price && ` · ${c.price}`}
                                        </p>
                                    </button>
                                ))}
                            </div>
                        )}
                        <FieldError name="connector_id" />
                    </fieldset>
                    <div>
                        <span className="text-sm font-medium">Charge for up to</span>
                        <div className="mt-2 flex flex-wrap gap-2">
                            {durations.map((d) => (
                                <button key={d} type="button" onClick={() => form.setData('minutes', d)}
                                    className={cn('h-10 rounded-full border px-4 text-sm font-semibold', form.data.minutes === d ? 'border-foreground bg-foreground text-background' : 'border-border')}>
                                    {d >= 60 ? `${d / 60} h` : `${d} min`}
                                </button>
                            ))}
                        </div>
                        <p className="mt-1 text-xs text-muted-foreground">Holds the connector against online bookings; it is freed as soon as you stop.</p>
                        <FieldError name="minutes" />
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <div className="col-span-2">
                            <label htmlFor="wi-reg" className="text-sm font-medium">Number plate</label>
                            <input id="wi-reg" required value={form.data.registration_no} onChange={(e) => form.setData('registration_no', e.target.value.toUpperCase())} className={cn(input, 'font-mono text-lg tracking-wider')} placeholder="MH12AB1234" />
                            <FieldError name="registration_no" />
                        </div>
                        <div>
                            <label htmlFor="wi-make" className="text-sm font-medium">Make</label>
                            <input id="wi-make" value={form.data.make} onChange={(e) => form.setData('make', e.target.value)} className={input} placeholder="Tata" />
                        </div>
                        <div>
                            <label htmlFor="wi-model" className="text-sm font-medium">Model</label>
                            <input id="wi-model" value={form.data.model} onChange={(e) => form.setData('model', e.target.value)} className={input} placeholder="Nexon EV" />
                        </div>
                        <div>
                            <label htmlFor="wi-name" className="text-sm font-medium">Driver name</label>
                            <input id="wi-name" required value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className={input} />
                            <FieldError name="name" />
                        </div>
                        <div>
                            <label htmlFor="wi-phone" className="text-sm font-medium">Mobile</label>
                            <input id="wi-phone" required inputMode="numeric" maxLength={10} value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value.replace(/\D/g, ''))} className={input} />
                            <FieldError name="phone" />
                        </div>
                        <div>
                            <label htmlFor="wi-email" className="text-sm font-medium">Email <span className="font-normal text-muted-foreground">for invoice</span></label>
                            <input id="wi-email" type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} className={input} />
                            <FieldError name="email" />
                        </div>
                        <div>
                            <label htmlFor="wi-gstin" className="text-sm font-medium">GSTIN <span className="font-normal text-muted-foreground">business</span></label>
                            <input id="wi-gstin" value={form.data.gstin} onChange={(e) => form.setData('gstin', e.target.value.toUpperCase())} maxLength={15} className={cn(input, 'font-mono')} />
                            <FieldError name="gstin" />
                        </div>
                        <div className="col-span-2">
                            <label htmlFor="wi-meter" className="text-sm font-medium">Meter reading now (kWh) <span className="font-normal text-muted-foreground">optional</span></label>
                            <input id="wi-meter" inputMode="decimal" value={form.data.meter_start} onChange={(e) => form.setData('meter_start', e.target.value)} className={cn(input, 'font-mono')} />
                            <FieldError name="meter_start" />
                        </div>
                    </div>
                    <label className="flex items-start gap-3 rounded-xl bg-muted p-3 text-sm">
                        <input type="checkbox" checked={form.data.informed} onChange={(e) => form.setData('informed', e.target.checked)} className="mt-0.5 size-5" />
                        <span>I told the driver the price{chosen?.price ? ` (${chosen.price})` : ''} and the station rules.</span>
                    </label>
                    <FieldError name="informed" />
                    <button type="submit" disabled={form.processing || !form.data.connector_id} className="flex h-12 w-full items-center justify-center gap-2 rounded-full bg-gradient-to-r from-volt-600 to-electric font-semibold text-white disabled:opacity-60">
                        {form.processing ? <LoaderCircle className="size-5 animate-spin" /> : <Play className="size-5" />} Start charging
                    </button>
                </form>
            </SheetContent>
        </Sheet>
    );
}

function CollectSheet({ a, onClose }: { a: Arrival | null; onClose: () => void }) {
    const [method, setMethod] = useState<'cash' | 'upi_manual' | 'link'>('cash');
    const form = useForm({ method: 'cash', reference: '' });
    const [link, setLink] = useState<{ url: string; qr: string } | null>(null);
    const [linkError, setLinkError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        setMethod('cash');
        setLink(null);
        setLinkError(null);
        form.reset();
    }, [a?.code]); // eslint-disable-line react-hooks/exhaustive-deps

    // While the driver pays online, refresh until the console shows it paid.
    useEffect(() => {
        if (!link || !a) return;
        const t = setInterval(() => router.reload({ only: ['arrivals'] }), 5000);
        return () => clearInterval(t);
    }, [link, a]);

    const makeLink = async () => {
        if (!a) return;
        setLoading(true);
        setLinkError(null);
        try {
            const token = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
            const r = await fetch(`/staff/bookings/${a.code}/payment-link`, { method: 'POST', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token }, credentials: 'same-origin' });
            const j = await r.json();
            if (!r.ok) throw new Error(j?.errors ? (Object.values(j.errors)[0] as string[])[0] : j?.message ?? 'Could not create the link.');
            setLink(j);
        } catch (e) {
            setLinkError((e as Error).message);
        } finally {
            setLoading(false);
        }
    };

    if (a && a.settlement === 'paid' && link) {
        // Paid online while the sheet was open.
        setTimeout(onClose, 0);
    }

    const tabs = [
        { key: 'cash', label: 'Cash', icon: Banknote },
        { key: 'upi_manual', label: 'UPI', icon: Smartphone },
        { key: 'link', label: 'Online link', icon: QrCode },
    ] as const;

    return (
        <Sheet open={!!a} onOpenChange={(o) => !o && onClose()}>
            <SheetContent side="bottom" className="max-h-[92vh] overflow-y-auto rounded-t-3xl">
                <SheetHeader>
                    <SheetTitle>Take payment · ₹{a?.bill?.due.toFixed(2)}</SheetTitle>
                    <SheetDescription>
                        {a?.code} · {a?.registration} · {a?.session?.energy?.toFixed(2)} kWh
                    </SheetDescription>
                </SheetHeader>
                <div className="space-y-4 px-4 pb-6">
                    <div className="grid grid-cols-3 gap-2">
                        {tabs.map((t) => (
                            <button key={t.key} type="button" onClick={() => { setMethod(t.key); form.setData('method', t.key === 'link' ? 'cash' : t.key); }}
                                className={cn('flex h-14 flex-col items-center justify-center gap-0.5 rounded-xl border text-xs font-semibold', method === t.key ? 'border-foreground bg-foreground text-background' : 'border-border')}>
                                <t.icon className="size-5" /> {t.label}
                            </button>
                        ))}
                    </div>

                    {method !== 'link' ? (
                        <form
                            className="space-y-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post(`/staff/bookings/${a?.code}/collect`, { preserveScroll: true, onSuccess: onClose });
                            }}
                        >
                            {method === 'upi_manual' && (
                                <div>
                                    <label htmlFor="utr" className="text-sm font-medium">UPI reference (UTR) from the driver’s app</label>
                                    <input id="utr" required value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value.replace(/\s/g, ''))}
                                        className="mt-1 h-12 w-full rounded-xl border border-border bg-background px-3 font-mono text-lg" inputMode="numeric" maxLength={30} />
                                    <FieldError name="reference" />
                                </div>
                            )}
                            {method === 'cash' && <p className="rounded-xl bg-muted p-3 text-sm text-muted-foreground">Count the cash before you confirm. The GST {a?.bill?.kind === 'final' ? 'debit note' : 'invoice'} is issued straight away.</p>}
                            <FieldError name="method" />
                            <button type="submit" disabled={form.processing} className="flex h-12 w-full items-center justify-center gap-2 rounded-full bg-emerald-600 font-semibold text-white hover:bg-emerald-700 disabled:opacity-60">
                                {form.processing ? <LoaderCircle className="size-5 animate-spin" /> : <CheckCircle2 className="size-5" />}
                                {method === 'cash' ? 'Cash received' : 'UPI payment received'} · ₹{a?.bill?.due.toFixed(2)}
                            </button>
                        </form>
                    ) : link ? (
                        <div className="text-center">
                            <div className="mx-auto w-56 rounded-2xl bg-white p-3">
                                <img src={link.qr} alt="Payment QR" className="w-full [image-rendering:pixelated]" />
                            </div>
                            <p className="mt-3 text-sm text-muted-foreground">Ask the driver to scan this with their phone camera and pay. This screen updates when the payment arrives.</p>
                            <p className="mt-2 flex items-center justify-center gap-2 text-sm font-medium">
                                <LoaderCircle className="size-4 animate-spin" /> Waiting for payment…
                            </p>
                        </div>
                    ) : (
                        <div className="space-y-3">
                            <p className="text-sm text-muted-foreground">Creates a secure SabPaisa link (UPI, cards, net-banking). The driver scans a QR and pays on their own phone.</p>
                            {linkError && <p className="text-sm text-red-600 dark:text-red-400">{linkError}</p>}
                            <button type="button" onClick={makeLink} disabled={loading} className="flex h-12 w-full items-center justify-center gap-2 rounded-full bg-foreground font-semibold text-background disabled:opacity-60">
                                {loading ? <LoaderCircle className="size-5 animate-spin" /> : <QrCode className="size-5" />} Show payment QR
                            </button>
                        </div>
                    )}
                </div>
            </SheetContent>
        </Sheet>
    );
}

function ReadingSheet({ s, onClose }: { s: { uuid: string; label: string; meterStart: number | null } | null; onClose: () => void }) {
    const form = useForm({ register: '', energy: '', power: '', soc: '' });
    useEffect(() => form.reset(), [s?.uuid]); // eslint-disable-line react-hooks/exhaustive-deps
    const input = 'mt-1 h-11 w-full rounded-xl border border-border bg-background px-3 font-mono';
    return (
        <Sheet open={!!s} onOpenChange={(o) => !o && onClose()}>
            <SheetContent side="bottom" className="rounded-t-3xl">
                <SheetHeader>
                    <SheetTitle>Add a reading</SheetTitle>
                    <SheetDescription>{s?.label}. Fill in what the charger or car shows.</SheetDescription>
                </SheetHeader>
                <form
                    className="grid grid-cols-2 gap-3 px-4 pb-6"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/staff/sessions/${s?.uuid}/reading`, { preserveScroll: true, onSuccess: onClose });
                    }}
                >
                    {s?.meterStart !== null ? (
                        <div className="col-span-2">
                            <label htmlFor="rd-register" className="text-sm font-medium">Meter reading (kWh)</label>
                            <input id="rd-register" inputMode="decimal" value={form.data.register} onChange={(e) => form.setData('register', e.target.value)} className={input} />
                            <FieldError name="register" />
                        </div>
                    ) : (
                        <div className="col-span-2">
                            <label htmlFor="rd-energy" className="text-sm font-medium">Energy delivered so far (kWh)</label>
                            <input id="rd-energy" inputMode="decimal" value={form.data.energy} onChange={(e) => form.setData('energy', e.target.value)} className={input} />
                            <FieldError name="energy" />
                        </div>
                    )}
                    <div>
                        <label htmlFor="rd-power" className="text-sm font-medium">Power (kW)</label>
                        <input id="rd-power" inputMode="decimal" value={form.data.power} onChange={(e) => form.setData('power', e.target.value)} className={input} />
                    </div>
                    <div>
                        <label htmlFor="rd-soc" className="text-sm font-medium">Battery (%)</label>
                        <input id="rd-soc" inputMode="numeric" value={form.data.soc} onChange={(e) => form.setData('soc', e.target.value.replace(/\D/g, ''))} maxLength={3} className={input} />
                    </div>
                    <FieldError name="session" />
                    <button type="submit" disabled={form.processing} className="col-span-2 flex h-12 items-center justify-center gap-2 rounded-full bg-foreground font-semibold text-background disabled:opacity-60">
                        {form.processing ? <LoaderCircle className="size-5 animate-spin" /> : <Gauge className="size-5" />} Save reading
                    </button>
                </form>
            </SheetContent>
        </Sheet>
    );
}
