import SiteLayout from '@/layouts/site-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, CheckCircle2, Clock, LoaderCircle, PlugZap, Zap } from 'lucide-react';
import { useState } from 'react';
import { type CheckInBooking } from './confirm';

interface ChargerInfo {
    key: string;
    code: string;
    name: string | null;
    type: string;
    powerKw: number;
    station: { name: string; slug: string; city: string };
}

/** Customer scanned the QR sticker on a charger. */
export default function ChargerCheckIn({ charger, bookings }: { charger: ChargerInfo; bookings: (CheckInBooking & { thisCharger: boolean })[] }) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [busy, setBusy] = useState<string | null>(null);

    const checkIn = (code: string) =>
        router.post(`/charge/${charger.key}`, { booking: code }, { preserveScroll: true, onStart: () => setBusy(code), onFinish: () => setBusy(null) });

    return (
        <SiteLayout>
            <Head title={`Charger ${charger.code}`} />
            <div className="mx-auto w-full max-w-lg px-4 py-10 sm:py-16">
                <div className="overflow-hidden rounded-3xl bg-gradient-to-br from-slate-900 via-slate-900 to-emerald-950 p-6 text-white shadow-xl">
                    <div className="flex items-center gap-3">
                        <div className="flex size-12 items-center justify-center rounded-2xl bg-white/10">
                            <PlugZap className="size-7 text-volt" />
                        </div>
                        <div>
                            <p className="text-xs tracking-widest text-white/60 uppercase">You’re at</p>
                            <h1 className="font-display text-xl font-bold">{charger.station.name}</h1>
                        </div>
                    </div>
                    <p className="mt-4 flex items-center gap-2 text-sm text-white/80">
                        <Zap className="size-4 text-volt" /> Charger <span className="font-mono font-semibold text-white">{charger.code}</span> · {charger.type} · {charger.powerKw} kW
                    </p>
                </div>

                {errors?.booking && (
                    <p className="mt-5 flex gap-2 rounded-2xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-foreground">
                        <AlertTriangle className="size-5 shrink-0 text-amber-500" /> {errors.booking}
                    </p>
                )}

                <h2 className="mt-8 font-display text-lg font-semibold text-foreground">Your bookings here</h2>
                {bookings.length === 0 ? (
                    <div className="mt-3 rounded-3xl border border-border bg-card p-6 text-sm text-muted-foreground">
                        You have no paid booking at this station in the next few hours.
                        <Link href={`/book/${charger.station.slug}`} className="mt-4 flex items-center gap-1.5 font-semibold text-volt-600 hover:underline dark:text-volt">
                            Book this station <ArrowRight className="size-4" />
                        </Link>
                    </div>
                ) : (
                    <ul className="mt-3 space-y-3">
                        {bookings.map((b) => {
                            const done = b.status === 'checked_in';
                            return (
                                <li key={b.code} className="rounded-3xl border border-border bg-card p-5">
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <p className="font-mono text-sm font-semibold text-foreground">{b.code}</p>
                                            <p className="mt-1 flex items-center gap-1.5 text-sm text-muted-foreground">
                                                <Clock className="size-4" /> {b.date} · {b.time}
                                            </p>
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                {b.charger} {b.registration && <>· {b.registration}</>}
                                            </p>
                                        </div>
                                        {done && (
                                            <span className="inline-flex items-center gap-1 rounded-full bg-emerald-500/15 px-3 py-1 text-xs font-semibold text-emerald-700 dark:text-emerald-300">
                                                <CheckCircle2 className="size-4" /> Checked in {b.checkedInAt}
                                            </span>
                                        )}
                                    </div>
                                    {!done &&
                                        (!b.thisCharger ? (
                                            <p className="mt-4 text-sm text-amber-700 dark:text-amber-300">This booking is for another charger: {b.charger}. Please scan the QR on that charger.</p>
                                        ) : b.blocker ? (
                                            <p className="mt-4 text-sm text-amber-700 dark:text-amber-300">{b.blocker}</p>
                                        ) : (
                                            <button
                                                type="button"
                                                onClick={() => checkIn(b.code)}
                                                disabled={busy !== null}
                                                className="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-full bg-gradient-to-r from-volt-600 to-electric px-6 py-3 font-semibold text-white shadow-lg shadow-volt/25 transition hover:brightness-110 disabled:opacity-60"
                                            >
                                                {busy === b.code ? <LoaderCircle className="size-5 animate-spin" /> : <CheckCircle2 className="size-5" />}
                                                Check in & start
                                            </button>
                                        ))}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>
        </SiteLayout>
    );
}
