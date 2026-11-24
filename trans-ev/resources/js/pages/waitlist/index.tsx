import { inr } from '@/components/site/station-bits';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowRight, CheckCircle2, Clock, CreditCard, Hourglass, MapPin, Timer, XCircle } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Entry {
    uuid: string;
    status: string;
    statusLabel: string;
    station: { name: string; slug: string; city: string };
    date: string;
    window: string;
    duration: number;
    vehicle: string;
    position: number | null;
    estimate: string | null;
    offer: { code: string; slot: string; expiresAt: string | null; amount: number } | null;
    bookingCode: string | null;
    closedReason: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Waiting lists', href: '/account/waitlist' }];

const TONE: Record<string, string> = {
    waiting: 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
    offered: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    booked: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    passed: 'bg-muted text-muted-foreground',
    expired: 'bg-muted text-muted-foreground',
    cancelled: 'bg-muted text-muted-foreground',
};

function Countdown({ iso }: { iso: string }) {
    const [left, setLeft] = useState(() => Math.max(0, new Date(iso).getTime() - Date.now()));
    useEffect(() => {
        const t = setInterval(() => setLeft(Math.max(0, new Date(iso).getTime() - Date.now())), 1000);
        return () => clearInterval(t);
    }, [iso]);
    const m = Math.floor(left / 60000);
    const s = Math.floor((left % 60000) / 1000);
    return (
        <span className="tabular-nums">
            {m}:{String(s).padStart(2, '0')}
        </span>
    );
}

export default function WaitlistIndex({ entries }: { entries: Entry[] }) {
    const { flash } = usePage<{ flash: { success?: string } }>().props;

    // Offers arrive in the background: refresh while waiting.
    useEffect(() => {
        if (!entries.some((e) => e.status === 'waiting' || e.status === 'offered')) return;
        const t = setInterval(() => router.reload({ only: ['entries'] }), 30_000);
        return () => clearInterval(t);
    }, [entries]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Waiting lists" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="font-display text-2xl font-bold text-foreground">Waiting lists</h1>
                    <p className="text-sm text-muted-foreground">When a slot you’re waiting for frees up, we hold it for you for a few minutes and email you. Pay in time to confirm it.</p>
                </div>
                {flash?.success && <p className="flex items-center gap-2 rounded-2xl bg-emerald-500/10 p-3 text-sm font-medium text-emerald-700 dark:text-emerald-300"><CheckCircle2 className="size-5" /> {flash.success}</p>}

                {entries.length === 0 ? (
                    <div className="rounded-3xl border border-dashed border-border p-10 text-center">
                        <Hourglass className="mx-auto size-10 text-muted-foreground" />
                        <p className="mt-3 font-semibold text-foreground">You are not on any waiting list</p>
                        <p className="mt-1 text-sm text-muted-foreground">If a station is full when you want to charge, join its waiting list from the booking page.</p>
                        <Link href="/stations" className="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-volt-600 hover:underline dark:text-volt">
                            Find a station <ArrowRight className="size-4" />
                        </Link>
                    </div>
                ) : (
                    <ul className="grid gap-4 lg:grid-cols-2">
                        {entries.map((e) => (
                            <li key={e.uuid} className={cn('rounded-3xl border bg-card p-5', e.status === 'offered' ? 'border-amber-500/50' : 'border-border')}>
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <Link href={`/stations/${e.station.slug}`} className="font-semibold text-foreground hover:underline">{e.station.name}</Link>
                                        <p className="flex items-center gap-1 text-xs text-muted-foreground"><MapPin className="size-3.5" /> {e.station.city}</p>
                                    </div>
                                    <span className={cn('rounded-full px-2.5 py-0.5 text-xs font-semibold', TONE[e.status])}>{e.statusLabel}</span>
                                </div>
                                <p className="mt-3 flex items-center gap-2 text-sm text-foreground">
                                    <Clock className="size-4 text-muted-foreground" /> {e.date} · start between {e.window} · {e.duration} min
                                </p>
                                <p className="mt-1 text-sm text-muted-foreground">{e.vehicle}</p>

                                {e.status === 'waiting' && (
                                    <div className="mt-4 grid grid-cols-2 gap-3 text-center">
                                        <div className="rounded-2xl bg-muted p-3">
                                            <p className="text-xs text-muted-foreground">Your place</p>
                                            <p className="font-display text-2xl font-bold text-foreground">#{e.position}</p>
                                        </div>
                                        <div className="rounded-2xl bg-muted p-3">
                                            <p className="text-xs text-muted-foreground">Next free (estimated)</p>
                                            <p className="font-display text-2xl font-bold text-foreground">{e.estimate ?? '—'}</p>
                                        </div>
                                    </div>
                                )}

                                {e.offer && (
                                    <div className="mt-4 rounded-2xl bg-amber-500/10 p-4">
                                        <p className="font-semibold text-foreground">A slot is held for you: {e.offer.slot}</p>
                                        {e.offer.expiresAt && (
                                            <p className="mt-1 flex items-center gap-1.5 text-sm text-amber-800 dark:text-amber-200">
                                                <Timer className="size-4" /> Pay within <Countdown iso={e.offer.expiresAt} /> or it goes to the next person.
                                            </p>
                                        )}
                                        <Link href={`/account/bookings/${e.offer.code}`} className="mt-3 inline-flex items-center gap-2 rounded-full bg-gradient-to-r from-volt-600 to-electric px-5 py-2.5 text-sm font-semibold text-white">
                                            <CreditCard className="size-4" /> Pay {inr(e.offer.amount)} to confirm
                                        </Link>
                                    </div>
                                )}
                                {e.bookingCode && (
                                    <Link href={`/account/bookings/${e.bookingCode}`} className="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-volt-600 hover:underline dark:text-volt">
                                        View booking {e.bookingCode} <ArrowRight className="size-4" />
                                    </Link>
                                )}
                                {e.closedReason && !e.bookingCode && <p className="mt-3 text-xs text-muted-foreground">{e.closedReason}</p>}

                                {(e.status === 'waiting' || e.status === 'offered') && (
                                    <button type="button" onClick={() => confirm('Leave this waiting list?') && router.post(`/account/waitlist/${e.uuid}/leave`, {}, { preserveScroll: true })}
                                        className="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-red-600 hover:underline dark:text-red-300">
                                        <XCircle className="size-4" /> Leave the list
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AppLayout>
    );
}
