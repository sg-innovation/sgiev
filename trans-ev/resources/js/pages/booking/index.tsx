import { directionsUrl, inr } from '@/components/site/station-bits';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowRight,
    BatteryCharging,
    CalendarClock,
    Car,
    CreditCard,
    FileText,
    LifeBuoy,
    MapPin,
    Navigation,
    Plug,
    Receipt,
    Search,
    TicketPercent,
    Wallet,
    Zap,
} from 'lucide-react';
import { type FormEvent, useEffect, useState } from 'react';

interface Item {
    code: string;
    status: string;
    statusLabel: string;
    holdActive: boolean;
    holdExpiresAt: string | null;
    station: { name: string; slug: string; city: string };
    charger: { code: string; type: string; powerKw: number };
    date: string;
    time: string;
    startAt: string;
    total: number;
    vehicle: string | null;
    connector: string;
    lat: number;
    lng: number;
    kwh: number;
    invoiceUrl: string | null;
    receiptUrl: string | null;
    coupon: string | null;
}
type Tab = 'upcoming' | 'past' | 'cancelled' | 'all';
interface Props {
    tab: Tab;
    search: string;
    counts: Record<Tab, number>;
    stats: { sessions: number; kwh: number; spent: number; saved: number };
    bookings: { data: Item[]; links: { url: string | null; label: string; active: boolean }[] };
}

const TONE: Record<string, string> = {
    held: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    confirmed: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    checked_in: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    completed: 'bg-electric/10 text-electric',
    cancelled: 'bg-rose-500/10 text-rose-700 dark:text-rose-300',
    expired: 'bg-muted text-muted-foreground',
    no_show: 'bg-muted text-muted-foreground',
};

const TABS: { key: Tab; label: string }[] = [
    { key: 'upcoming', label: 'Upcoming' },
    { key: 'past', label: 'Past' },
    { key: 'cancelled', label: 'Cancelled' },
    { key: 'all', label: 'All' },
];

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'My account', href: '/dashboard' },
    { title: 'My bookings', href: '/account/bookings' },
];

function startsIn(iso: string, now: number): string | null {
    const ms = new Date(iso).getTime() - now;
    if (ms <= 0) return null;
    const mins = Math.round(ms / 60000);
    if (mins < 60) return `Starts in ${mins} min`;
    const hrs = Math.round(mins / 60);
    if (hrs < 24) return `Starts in ${hrs} h`;
    const days = Math.round(hrs / 24);
    return `In ${days} day${days > 1 ? 's' : ''}`;
}

export default function BookingIndex({ tab, search, counts, stats, bookings }: Props) {
    const [q, setQ] = useState(search);
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const id = setInterval(() => setNow(Date.now()), 30000);
        return () => clearInterval(id);
    }, []);

    const go = (next: Partial<{ tab: Tab; q: string }>) =>
        router.get('/account/bookings', { tab: next.tab ?? tab, q: (next.q ?? q) || undefined }, { preserveState: true, preserveScroll: true, replace: true });
    const onSearch = (e: FormEvent) => {
        e.preventDefault();
        go({ q });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My bookings" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                {/* Header */}
                <section className="relative overflow-hidden rounded-3xl bg-ink p-6 text-white sm:p-8">
                    <div className="pointer-events-none absolute -top-24 -right-16 size-72 rounded-full bg-volt/30 blur-3xl" />
                    <div className="pointer-events-none absolute -bottom-28 left-1/3 size-72 rounded-full bg-electric/25 blur-3xl" />
                    <div className="relative flex flex-wrap items-end justify-between gap-6">
                        <div>
                            <h1 className="font-display text-3xl font-bold">My bookings</h1>
                            <p className="mt-1 text-white/70">Your charging slots, receipts and GST invoices in one place.</p>
                        </div>
                        <Link href="/stations" className="inline-flex items-center gap-2 rounded-full bg-gradient-to-r from-volt to-electric px-5 py-3 font-semibold text-white shadow-lg shadow-volt/30 hover:brightness-110">
                            <Zap className="size-5" /> Book a charge
                        </Link>
                    </div>
                    <dl className="relative mt-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
                        {[
                            { icon: CalendarClock, label: 'Charging sessions', value: String(stats.sessions) },
                            { icon: BatteryCharging, label: 'Energy charged', value: `${stats.kwh.toFixed(1)} kWh` },
                            { icon: Wallet, label: 'Total spent', value: inr(stats.spent, 0) },
                            { icon: TicketPercent, label: 'Saved with offers', value: inr(stats.saved, 0) },
                        ].map((s) => (
                            <div key={s.label} className="rounded-2xl bg-white/5 p-4 ring-1 ring-white/10">
                                <dt className="flex items-center gap-1.5 text-xs text-white/60">
                                    <s.icon className="size-3.5 text-volt" />
                                    {s.label}
                                </dt>
                                <dd className="mt-1 font-display text-xl font-semibold">{s.value}</dd>
                            </div>
                        ))}
                    </dl>
                </section>

                {/* Tabs + search */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div role="tablist" aria-label="Filter bookings" className="flex flex-wrap gap-1 rounded-2xl bg-muted p-1">
                        {TABS.map((t) => (
                            <button
                                key={t.key}
                                role="tab"
                                aria-selected={tab === t.key}
                                onClick={() => go({ tab: t.key })}
                                className={cn(
                                    'flex items-center gap-2 rounded-xl px-4 py-2 text-sm font-semibold transition',
                                    tab === t.key ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {t.label}
                                <span className={cn('rounded-full px-1.5 text-xs', tab === t.key ? 'bg-volt/15 text-volt-700 dark:text-volt' : 'bg-background/60')}>{counts[t.key]}</span>
                            </button>
                        ))}
                    </div>
                    <form onSubmit={onSearch} className="relative w-full sm:w-72">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <input
                            type="search"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder="Search code, station or city"
                            aria-label="Search bookings"
                            className="h-10 w-full rounded-xl border border-input bg-background pr-3 pl-9 text-sm text-foreground placeholder:text-muted-foreground focus:border-volt focus:ring-2 focus:ring-volt/30 focus:outline-none"
                        />
                    </form>
                </div>

                {/* List */}
                {bookings.data.length === 0 ? (
                    <div className="rounded-3xl border border-dashed border-border p-12 text-center">
                        <CalendarClock className="mx-auto size-10 text-muted-foreground" />
                        <p className="mt-4 font-display text-lg font-semibold text-foreground">
                            {search ? 'No bookings match your search' : tab === 'upcoming' ? 'No upcoming bookings' : tab === 'cancelled' ? 'Nothing cancelled' : 'No bookings yet'}
                        </p>
                        <p className="mt-1 text-muted-foreground">
                            {tab === 'upcoming' ? 'Reserve a slot at a nearby station and it will show up here.' : 'Your charging history will appear here.'}
                        </p>
                        <Link href="/stations?near=1" className="mt-5 inline-flex items-center gap-2 font-semibold text-volt-600 hover:underline">
                            Find chargers near me <ArrowRight className="size-4" />
                        </Link>
                    </div>
                ) : (
                    <ul className="grid gap-4 xl:grid-cols-2">
                        {bookings.data.map((b) => {
                            const d = new Date(b.startAt);
                            const soon = (b.status === 'confirmed' || b.status === 'checked_in') && startsIn(b.startAt, now);
                            return (
                                <li key={b.code} className="group flex flex-col overflow-hidden rounded-3xl border border-border bg-card transition hover:border-volt/50 hover:shadow-xl hover:shadow-black/5">
                                    <Link href={`/account/bookings/${b.code}`} className="flex gap-4 p-5">
                                        <div className="flex w-16 shrink-0 flex-col items-center justify-center rounded-2xl bg-gradient-to-b from-volt/15 to-electric/10 py-2 text-center">
                                            <span className="text-xs font-semibold text-muted-foreground uppercase">{d.toLocaleDateString('en-IN', { month: 'short', timeZone: 'Asia/Kolkata' })}</span>
                                            <span className="font-display text-2xl leading-tight font-bold text-foreground">{d.toLocaleDateString('en-IN', { day: 'numeric', timeZone: 'Asia/Kolkata' })}</span>
                                            <span className="text-xs text-muted-foreground">{d.toLocaleDateString('en-IN', { weekday: 'short', timeZone: 'Asia/Kolkata' })}</span>
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className={cn('rounded-full px-2.5 py-0.5 text-xs font-semibold', TONE[b.status] ?? 'bg-muted text-muted-foreground')}>
                                                    {b.holdActive ? 'Awaiting payment' : b.statusLabel}
                                                </span>
                                                {soon && <span className="text-xs font-semibold text-volt-700 dark:text-volt">{soon}</span>}
                                                <span className="ml-auto font-mono text-xs text-muted-foreground">{b.code}</span>
                                            </div>
                                            <p className="mt-1.5 truncate font-display text-lg font-semibold text-foreground">{b.station.name}</p>
                                            <p className="flex items-center gap-1 text-sm text-muted-foreground">
                                                <MapPin className="size-3.5 shrink-0" /> {b.station.city} · {b.time}
                                            </p>
                                            <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                                <span className="flex items-center gap-1">
                                                    <Zap className="size-3.5" /> {b.charger.type} {b.charger.powerKw} kW
                                                </span>
                                                <span className="flex items-center gap-1">
                                                    <Plug className="size-3.5" /> {b.connector}
                                                </span>
                                                {b.vehicle && (
                                                    <span className="flex items-center gap-1">
                                                        <Car className="size-3.5" /> {b.vehicle}
                                                    </span>
                                                )}
                                                <span className="flex items-center gap-1">
                                                    <BatteryCharging className="size-3.5" /> ~{b.kwh.toFixed(1)} kWh
                                                </span>
                                            </div>
                                        </div>
                                        <div className="text-right">
                                            <p className="font-display text-lg font-bold text-foreground">{inr(b.total)}</p>
                                            {b.coupon && <p className="text-xs font-medium text-volt-700 dark:text-volt">{b.coupon}</p>}
                                        </div>
                                    </Link>
                                    <div className="mt-auto flex flex-wrap items-center gap-2 border-t border-border bg-muted/30 px-5 py-3">
                                        {b.holdActive && (
                                            <Link href={`/account/bookings/${b.code}`} className="inline-flex items-center gap-1.5 rounded-full bg-gradient-to-r from-volt-600 to-electric px-3 py-1.5 text-xs font-semibold text-white">
                                                <CreditCard className="size-3.5" /> Pay now
                                            </Link>
                                        )}
                                        {(b.status === 'confirmed' || b.status === 'checked_in' || b.holdActive) && (
                                            <a href={directionsUrl(b.lat, b.lng)} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1.5 rounded-full border border-border bg-card px-3 py-1.5 text-xs font-semibold text-foreground hover:border-volt">
                                                <Navigation className="size-3.5" /> Directions
                                            </a>
                                        )}
                                        {b.invoiceUrl && (
                                            <a href={b.invoiceUrl} target="_blank" rel="noopener" className="inline-flex items-center gap-1.5 rounded-full border border-border bg-card px-3 py-1.5 text-xs font-semibold text-foreground hover:border-volt">
                                                <FileText className="size-3.5" /> GST invoice
                                            </a>
                                        )}
                                        {b.receiptUrl && (
                                            <Link href={b.receiptUrl} className="inline-flex items-center gap-1.5 rounded-full border border-border bg-card px-3 py-1.5 text-xs font-semibold text-foreground hover:border-volt">
                                                <Receipt className="size-3.5" /> Receipt
                                            </Link>
                                        )}
                                        <Link href={`/account/support?booking=${encodeURIComponent(b.code)}`} className="ml-auto inline-flex items-center gap-1.5 text-xs font-semibold text-muted-foreground hover:text-foreground">
                                            <LifeBuoy className="size-3.5" /> Get help
                                        </Link>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}

                {bookings.links.length > 3 && (
                    <nav aria-label="Pages" className="flex flex-wrap justify-center gap-1">
                        {bookings.links.map((l, i) => (
                            <Link
                                key={i}
                                href={l.url ?? '#'}
                                preserveScroll
                                className={cn('rounded-lg px-3 py-1.5 text-sm', l.active ? 'bg-ink text-white dark:bg-volt dark:text-ink' : 'text-muted-foreground hover:bg-muted', !l.url && 'pointer-events-none opacity-40')}
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ))}
                    </nav>
                )}
            </div>
        </AppLayout>
    );
}
