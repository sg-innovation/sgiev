import SiteLayout from '@/layouts/site-layout';
import { type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { AlertTriangle, BatteryCharging, Car, CheckCircle2, Clock, ExternalLink, LoaderCircle, Phone, QrCode, User, Zap } from 'lucide-react';
import { useState } from 'react';

export interface CheckInBooking {
    code: string;
    status: string;
    statusLabel: string;
    customer: string | null;
    phone: string | null;
    vehicle: string;
    registration: string | null;
    station: string;
    charger: string;
    connector: string | null;
    date: string;
    time: string;
    checkedInAt: string | null;
    blocker: string | null;
    adminUrl: string | null;
}

/** Staff opened a scanned booking QR: confirm the customer and check them in. */
export default function CheckInConfirm({ booking, error, token }: { booking: CheckInBooking | null; error: string | null; token: string }) {
    const { flash } = usePage<SharedData & { flash: { success?: string; error?: string } }>().props;
    const [busy, setBusy] = useState(false);
    const done = booking?.status === 'checked_in';

    const confirm = () =>
        router.post(`/checkin/${token}`, {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });

    return (
        <SiteLayout>
            <Head title="Check-in" />
            <div className="mx-auto w-full max-w-lg px-4 py-10 sm:py-16">
                <div className="mb-6 flex items-center gap-3">
                    <div className="flex size-11 items-center justify-center rounded-2xl bg-volt/15 text-volt-600 dark:text-volt">
                        <QrCode className="size-6" />
                    </div>
                    <div>
                        <h1 className="font-display text-2xl font-bold text-foreground">Customer check-in</h1>
                        <p className="text-sm text-muted-foreground">Confirm the vehicle before you check the customer in.</p>
                    </div>
                </div>

                {error || !booking ? (
                    <div className="flex gap-3 rounded-3xl border border-amber-500/30 bg-amber-500/10 p-5 text-sm text-foreground">
                        <AlertTriangle className="size-5 shrink-0 text-amber-500" />
                        <div>
                            <p className="font-semibold">Can’t use this QR</p>
                            <p className="mt-1 text-muted-foreground">{error}</p>
                        </div>
                    </div>
                ) : (
                    <div className="overflow-hidden rounded-3xl border border-border bg-card shadow-sm">
                        <div className={done ? 'bg-emerald-600 px-6 py-5 text-white' : 'bg-gradient-to-br from-slate-900 to-slate-800 px-6 py-5 text-white'}>
                            <p className="text-xs tracking-widest text-white/70 uppercase">Booking</p>
                            <p className="font-mono text-2xl font-bold">{booking.code}</p>
                            <p className="mt-1 text-sm text-white/80">
                                {booking.date} · {booking.time}
                            </p>
                        </div>
                        <dl className="grid gap-4 p-6 sm:grid-cols-2">
                            <Row icon={User} label="Customer" value={booking.customer ?? '—'} />
                            <Row icon={Phone} label="Phone" value={booking.phone ?? '—'} />
                            <Row icon={Car} label="Vehicle" value={booking.vehicle || '—'} sub={booking.registration} big />
                            <Row icon={Zap} label="Charger" value={booking.charger} sub={booking.connector} />
                            <Row icon={BatteryCharging} label="Station" value={booking.station} />
                            <Row icon={Clock} label="Status" value={booking.checkedInAt ? `Checked in at ${booking.checkedInAt}` : booking.statusLabel} />
                        </dl>
                        <div className="border-t border-border p-6">
                            {flash?.success && (
                                <p className="mb-4 flex items-center gap-2 rounded-2xl bg-emerald-500/10 p-3 text-sm font-medium text-emerald-700 dark:text-emerald-300">
                                    <CheckCircle2 className="size-5" /> {flash.success}
                                </p>
                            )}
                            {done ? (
                                <p className="flex items-center gap-2 text-sm font-semibold text-emerald-700 dark:text-emerald-300">
                                    <CheckCircle2 className="size-5" /> Checked in. The customer can plug in and start charging.
                                </p>
                            ) : booking.blocker ? (
                                <p className="flex gap-2 text-sm text-amber-700 dark:text-amber-300">
                                    <AlertTriangle className="size-5 shrink-0" /> {booking.blocker}
                                </p>
                            ) : (
                                <button
                                    type="button"
                                    onClick={confirm}
                                    disabled={busy}
                                    className="inline-flex w-full items-center justify-center gap-2 rounded-full bg-gradient-to-r from-volt-600 to-electric px-6 py-3.5 text-base font-semibold text-white shadow-lg shadow-volt/25 transition hover:brightness-110 disabled:opacity-60"
                                >
                                    {busy ? <LoaderCircle className="size-5 animate-spin" /> : <CheckCircle2 className="size-5" />}
                                    Check in {booking.registration ?? ''}
                                </button>
                            )}
                            {booking.adminUrl && (
                                <a href={booking.adminUrl} className="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-volt-600 hover:underline dark:text-volt">
                                    Open booking in admin <ExternalLink className="size-4" />
                                </a>
                            )}
                        </div>
                    </div>
                )}
            </div>
        </SiteLayout>
    );
}

function Row({ icon: Icon, label, value, sub, big }: { icon: typeof User; label: string; value: string; sub?: string | null; big?: boolean }) {
    return (
        <div className="flex gap-3">
            <Icon className="mt-0.5 size-5 shrink-0 text-muted-foreground" />
            <div className="min-w-0">
                <dt className="text-xs text-muted-foreground">{label}</dt>
                <dd className="font-semibold text-foreground">{value}</dd>
                {sub && <dd className={big ? 'mt-0.5 inline-block rounded-md border border-border bg-muted px-2 py-0.5 font-mono text-base font-bold tracking-wider text-foreground' : 'text-sm text-muted-foreground'}>{sub}</dd>}
            </div>
        </div>
    );
}
