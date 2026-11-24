import { directionsUrl, inr } from '@/components/site/station-bits';
import CancellationPolicyCard, { type PolicyView } from '@/components/site/cancellation-policy';
import CheckInPass from '@/components/site/check-in-pass';
import RunningLate from '@/components/site/running-late';
import Reschedule, { type RescheduleInfo } from '@/components/site/reschedule';
import SiteLayout from '@/layouts/site-layout';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, BatteryCharging, CalendarDays, Car, CheckCircle2, Clock, CreditCard, FileText, LoaderCircle, MapPin, Navigation, RefreshCw, RotateCcw, ScrollText, ShieldCheck, Timer, UserX, XCircle, Zap } from 'lucide-react';
import { useEffect, useState } from 'react';

interface BookingDetail {
    code: string;
    status: string;
    statusLabel: string;
    holdActive: boolean;
    holdExpiresAt: string | null;
    station: { name: string; slug: string; city: string };
    charger: { code: string; type: string; powerKw: number };
    date: string;
    time: string;
    stationAddress: string;
    lat: number;
    lng: number;
    connector: string;
    durationMinutes: number;
    batteryFrom: number;
    batteryTo: number;
    vehicleSnapshot: { make: string; model: string; registration_no: string; battery_kwh: string };
    invoice: { number: string; date: string; total: number; url: string } | null;
    creditNotes: { title: string; number: string; date: string; total: number; url: string }[];
    customer: { name: string; email: string; phone: string; gstin: string | null };
    amounts: {
        kwh: number; ratePerKwh: number; energy: number; sessionFee: number; discount: number; couponCode: string | null; parking: number; lines: { type: string; label: string; qty: number; unit: string; rate: number; amount: number }[]; taxable: number; gstRate: number;
        cgst: number; sgst: number; igst: number; total: number; sac: string; placeOfSupply: string;
    };
    rules: { version: number; acceptedAt: string } | null;
    canCancel: boolean;
    canPay: boolean;
    cancellationPolicy: PolicyView;
    cancelQuote: { paid: number; refund: number; retained: number; percent: number; rule: string } | null;
    payment: { status: string; amount: number; mode: string | null; gatewayTxnId: string | null; paidAt: string | null; simulated: boolean } | null;
    lastFailure: string | null;
    refunds: { amount: number; retained: number; status: string; rule: string | null; reason: string; date: string }[];
    cancelledAt: string | null;
    cancelReason: string | null;
    createdAt: string;
    reschedule: RescheduleInfo | null;
    history: { at: string; by: string; from: string; to: string; reason: string | null }[];
    late: { canNotify: boolean; holdUntil: string; eta: string | null; maxExtra: number } | null;
    noShow: { at: string; grace: number; percent: number; refund: number; retained: number; auto: boolean } | null;
    finalBill: { kwh: number; total: number; prepaid: number; difference: number; status: string; balanceDue: number | null; outcome: string | null } | null;
}

function useCountdown(iso: string | null) {
    const [left, setLeft] = useState(() => (iso ? Math.max(0, new Date(iso).getTime() - Date.now()) : 0));
    useEffect(() => {
        if (!iso) return;
        const t = setInterval(() => setLeft(Math.max(0, new Date(iso).getTime() - Date.now())), 1000);
        return () => clearInterval(t);
    }, [iso]);
    return left;
}

// Badges sit on the dark header, so they use light text in both themes.
const TONES: Record<string, string> = {
    held: 'bg-amber-400/20 text-amber-200 ring-amber-300/40',
    confirmed: 'bg-emerald-400/20 text-emerald-200 ring-emerald-300/40',
    checked_in: 'bg-emerald-400/20 text-emerald-200 ring-emerald-300/40',
    completed: 'bg-sky-400/20 text-sky-200 ring-sky-300/40',
};

export default function BookingShow({ booking: b }: { booking: BookingDetail }) {
    const { flash, errors } = usePage<SharedData & { flash: { success?: string; error?: string }; errors: Record<string, string> }>().props;
    const [paying, setPaying] = useState(false);
    const [confirmCancel, setConfirmCancel] = useState(false);
    const [cancelling, setCancelling] = useState(false);
    const left = useCountdown(b.holdActive ? b.holdExpiresAt : null);
    const mm = String(Math.floor(left / 60000)).padStart(2, '0');
    const ss = String(Math.floor((left % 60000) / 1000)).padStart(2, '0');
    const expiredNow = b.holdActive && left === 0;

    useEffect(() => {
        if (expiredNow) {
            const t = setTimeout(() => router.reload({ only: ['booking'] }), 1500);
            return () => clearTimeout(t);
        }
    }, [expiredNow]);

    const [payError, setPayError] = useState<string | null>(null);
    const pay = async () => {
        setPaying(true);
        setPayError(null);
        try {
            const res = await fetch(`/account/bookings/${b.code}/pay`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/)?.[1] ?? '') },
            });
            const json = await res.json().catch(() => ({}));
            if (res.ok && json.url) {
                window.location.assign(json.url); // SabPaisa hosted checkout
                return;
            }
            setPayError(json.errors?.payment?.[0] ?? json.message ?? 'Could not start the payment. Please try again.');
        } catch {
            setPayError('Network error. Please check your connection and try again.');
        }
        setPaying(false);
    };
    const cancel = () => {
        setCancelling(true);
        router.post(`/account/bookings/${b.code}/cancel`, {}, { preserveScroll: true, onFinish: () => { setCancelling(false); setConfirmCancel(false); } });
    };
    const pending = b.payment?.status === 'pending';

    // While a payment is being confirmed, re-check every few seconds.
    useEffect(() => {
        if (!pending || b.status !== 'held') return;
        const t = setInterval(() => router.post(`/account/bookings/${b.code}/payment-status`, {}, { preserveScroll: true, preserveState: true }), 6000);
        return () => clearInterval(t);
    }, [pending, b.status, b.code]);

    return (
        <SiteLayout>
            <Head title={`Booking ${b.code}`} />
            <section className="relative overflow-hidden bg-ink text-white">
                <div className="pointer-events-none absolute -top-24 -right-24 size-96 rounded-full bg-volt/25 blur-[100px]" />
                <div className="relative mx-auto max-w-5xl px-4 py-10 sm:px-6">
                    <Link href="/account/bookings" className="inline-flex items-center gap-1.5 text-sm text-white/70 hover:text-white">
                        <ArrowLeft className="size-4" /> My bookings
                    </Link>
                    <div className="mt-4 flex flex-wrap items-center gap-3">
                        <h1 className="font-display text-3xl font-bold">Booking {b.code}</h1>
                        <span className={cn('rounded-full px-3 py-1 text-sm font-semibold ring-1', TONES[b.status] ?? 'bg-white/10 text-white/80 ring-white/20')}>{b.statusLabel}</span>
                    </div>
                    <p className="mt-1 text-white/70">Created {b.createdAt}</p>
                </div>
            </section>

            <div className="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6">
                {(flash?.error || errors?.payment) && (
                    <div className="flex items-center gap-3 rounded-2xl bg-red-500/10 p-4 text-sm font-medium text-red-800 ring-1 ring-red-500/30 dark:text-red-200">
                        <AlertTriangle className="size-5 shrink-0" /> {flash?.error ?? errors.payment}
                    </div>
                )}
                {flash?.success && (
                    <div className="flex items-center gap-3 rounded-2xl bg-emerald-500/10 p-4 text-sm font-medium text-emerald-800 ring-1 ring-emerald-500/30 dark:text-emerald-200">
                        <CheckCircle2 className="size-5 shrink-0" /> {flash.success}
                    </div>
                )}

                {b.holdActive && !expiredNow && (
                    <div className="overflow-hidden rounded-3xl border border-amber-500/30 bg-gradient-to-r from-amber-500/10 to-transparent p-6">
                        <div className="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex items-center gap-4">
                                <div className="flex size-14 items-center justify-center rounded-2xl bg-amber-500/15">
                                    <Timer className="size-7 text-amber-600 dark:text-amber-300" />
                                </div>
                                <div>
                                    <p className="font-semibold text-foreground">Your slot is reserved</p>
                                    <p className="text-sm text-muted-foreground">Complete payment before the timer runs out to confirm it.</p>
                                </div>
                            </div>
                            <p className="font-display text-4xl font-extrabold text-foreground tabular-nums">
                                {mm}:{ss}
                            </p>
                        </div>
                        <div className="mt-5 flex flex-wrap gap-3">
                            <button
                                type="button"
                                onClick={pay}
                                disabled={paying}
                                className="inline-flex items-center gap-2 rounded-full bg-gradient-to-r from-volt-600 to-electric px-6 py-3 font-semibold text-white shadow-lg shadow-volt/25 transition hover:brightness-110 disabled:opacity-60"
                            >
                                {paying ? <LoaderCircle className="size-5 animate-spin" /> : <CreditCard className="size-5" />}
                                {pending ? 'Continue payment' : `Pay ${inr(b.amounts.total)} securely`}
                            </button>
                            <span className="flex items-center gap-1.5 self-center text-xs text-muted-foreground">
                                <ShieldCheck className="size-4 text-volt-600" /> UPI, cards &amp; net-banking via SabPaisa. We never see your card or UPI details.
                            </span>
                        </div>
                        {pending && (
                            <p className="mt-3 flex items-center gap-2 text-sm text-muted-foreground">
                                <RefreshCw className="size-4 animate-spin" /> Waiting for the bank to confirm your payment…
                            </p>
                        )}
                        {payError && <p className="mt-3 text-sm font-medium text-red-700 dark:text-red-300">{payError}</p>}
                        {b.lastFailure && !pending && (
                            <p className="mt-3 text-sm text-red-700 dark:text-red-300">Last attempt was not completed. You can try again.</p>
                        )}
                    </div>
                )}

                {(expiredNow || b.status === 'expired') && (
                    <div className="flex gap-3 rounded-2xl bg-muted p-4 text-sm text-foreground">
                        <AlertTriangle className="size-5 shrink-0 text-amber-500" /> The payment window has ended and the slot was released.{' '}
                        <Link href={`/book/${b.station.slug}`} className="font-semibold text-volt-600 hover:underline">Book again</Link>
                    </div>
                )}

                {b.status === 'cancelled' && (
                    <div className="flex gap-3 rounded-2xl bg-muted p-4 text-sm text-foreground">
                        <XCircle className="size-5 shrink-0 text-red-500" /> Cancelled on {b.cancelledAt}. {b.cancelReason}
                    </div>
                )}

                {b.late?.canNotify && <RunningLate code={b.code} late={b.late} />}
                {b.noShow && (
                    <div className="flex gap-3 rounded-2xl bg-muted p-4 text-sm text-foreground">
                        <UserX className="size-5 shrink-0 text-amber-500" />
                        <p>
                            Marked as a no-show on {b.noShow.at} (not checked in within {b.noShow.grace} minutes of the start). Under the policy agreed at booking,{' '}
                            {inr(b.noShow.refund)} ({b.noShow.percent}%) is refunded and {inr(b.noShow.retained)} is kept.
                        </p>
                    </div>
                )}
                {(b.status === 'confirmed' || b.status === 'checked_in') && <CheckInPass code={b.code} chargerCode={b.charger.code} />}
                {b.reschedule && <Reschedule code={b.code} info={b.reschedule} />}
                {b.history.length > 0 && (
                    <div className="rounded-2xl bg-muted p-4 text-sm text-foreground">
                        <p className="font-semibold">Changes to this booking</p>
                        <ul className="mt-2 space-y-1 text-muted-foreground">
                            {b.history.map((h, i) => (
                                <li key={i}>
                                    {h.at} · {h.by} moved it from {h.from} to <span className="font-medium text-foreground">{h.to}</span>
                                    {h.reason && <> · “{h.reason}”</>}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                <div className="grid gap-6 md:grid-cols-[1fr_340px]">
                    <div className="space-y-6">
                        <section className="rounded-3xl border border-border bg-card p-6">
                            <h2 className="font-display text-lg font-semibold text-foreground">Charging session</h2>
                            <dl className="mt-5 grid gap-4 sm:grid-cols-2">
                                <Item icon={MapPin} label="Station" value={b.station.name} sub={b.stationAddress} />
                                <Item icon={Zap} label="Charger" value={`${b.charger.type} · ${b.charger.powerKw} kW`} sub={`${b.charger.code} · ${b.connector}`} />
                                <Item icon={CalendarDays} label="Date" value={b.date} />
                                <Item icon={Clock} label="Time (IST)" value={b.time} sub={`${b.durationMinutes} minutes`} />
                                <Item icon={Car} label="Vehicle" value={`${b.vehicleSnapshot.make} ${b.vehicleSnapshot.model}`} sub={b.vehicleSnapshot.registration_no} />
                                <Item icon={BatteryCharging} label="Planned charge" value={`${b.batteryFrom}% → ${b.batteryTo}%`} sub={`≈ ${b.amounts.kwh.toFixed(2)} kWh`} />
                            </dl>
                            <div className="mt-6 flex flex-wrap gap-3">
                                <a href={directionsUrl(b.lat, b.lng)} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-2 rounded-full border border-border px-5 py-2.5 text-sm font-semibold text-foreground hover:border-electric hover:text-electric">
                                    <Navigation className="size-4" /> Directions
                                </a>
                                {b.canCancel && (
                                    <button type="button" onClick={() => setConfirmCancel(true)} className="inline-flex items-center gap-2 rounded-full border border-red-500/40 px-5 py-2.5 text-sm font-semibold text-red-600 hover:bg-red-500/10 dark:text-red-300">
                                        <XCircle className="size-4" /> Cancel booking
                                    </button>
                                )}
                            </div>
                        </section>

                        {b.finalBill && (
                            <section className="rounded-3xl border border-border bg-card p-6">
                                <h2 className="flex items-center gap-2 font-display text-lg font-semibold text-foreground">
                                    <Zap className="size-5 text-volt-600" /> Final bill
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground">Re-calculated with the energy your vehicle actually received.</p>
                                <dl className="mt-4 grid grid-cols-3 gap-3 text-center">
                                    <div className="rounded-2xl bg-muted p-3">
                                        <dt className="text-xs text-muted-foreground">Energy delivered</dt>
                                        <dd className="font-display text-lg font-bold text-foreground">{b.finalBill.kwh.toFixed(2)} kWh</dd>
                                    </div>
                                    <div className="rounded-2xl bg-muted p-3">
                                        <dt className="text-xs text-muted-foreground">Final bill</dt>
                                        <dd className="font-display text-lg font-bold text-foreground">{inr(b.finalBill.total)}</dd>
                                    </div>
                                    <div className="rounded-2xl bg-muted p-3">
                                        <dt className="text-xs text-muted-foreground">You prepaid</dt>
                                        <dd className="font-display text-lg font-bold text-foreground">{inr(b.finalBill.prepaid)}</dd>
                                    </div>
                                </dl>
                                {b.finalBill.outcome && (
                                    <p className={cn('mt-4 rounded-2xl p-3 text-sm font-medium', b.finalBill.status === 'due' ? 'bg-amber-500/10 text-amber-800 dark:text-amber-200' : 'bg-emerald-500/10 text-emerald-800 dark:text-emerald-200')}>
                                        {b.finalBill.status === 'due' ? `A balance of ${inr(b.finalBill.balanceDue ?? 0)} is due for the extra energy. Please pay at the station.` : b.finalBill.outcome.replace('by the operator (nothing to collect)', '— nothing more to pay')}
                                    </p>
                                )}
                            </section>
                        )}

                        {b.payment?.status === 'success' && (
                            <section className="rounded-3xl border border-emerald-500/30 bg-emerald-500/5 p-6">
                                <div className="flex flex-wrap items-start justify-between gap-4">
                                    <div className="flex gap-3">
                                        <CheckCircle2 className="mt-0.5 size-6 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                        <div>
                                            <h2 className="font-display text-lg font-semibold text-foreground">Payment received</h2>
                                            <p className="text-sm text-muted-foreground">
                                                {inr(b.payment.amount)} · {b.payment.mode ?? 'Online'} · {b.payment.paidAt}
                                            </p>
                                            <p className="mt-1 text-xs text-muted-foreground">Transaction ID: {b.payment.gatewayTxnId}{b.payment.simulated && ' (test payment)'}</p>
                                        </div>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <Link href={`/account/bookings/${b.code}/receipt`} className="inline-flex items-center gap-2 rounded-full border border-border bg-card px-4 py-2 text-sm font-semibold text-foreground hover:border-volt">
                                            <FileText className="size-4" /> Receipt
                                        </Link>
                                        {b.invoice && (
                                            <a href={b.invoice.url} target="_blank" rel="noopener" className="inline-flex items-center gap-2 rounded-full bg-foreground px-4 py-2 text-sm font-semibold text-background hover:opacity-90">
                                                <FileText className="size-4" /> GST invoice
                                            </a>
                                        )}
                                    </div>
                                </div>
                                {b.invoice ? (
                                    <div className="mt-4 rounded-2xl border border-border bg-card p-4 text-sm">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <span className="text-muted-foreground">
                                                Tax invoice <span className="font-mono font-semibold text-foreground">{b.invoice.number}</span> · {b.invoice.date}
                                            </span>
                                            <span className="font-semibold text-foreground">{inr(b.invoice.total)}</span>
                                        </div>
                                        {b.creditNotes.map((c) => (
                                            <a key={c.number} href={c.url} target="_blank" rel="noopener" className="mt-2 flex flex-wrap items-center justify-between gap-2 border-t border-border pt-2 hover:text-foreground">
                                                <span className="text-muted-foreground">
                                                    {c.title} <span className="font-mono font-semibold text-foreground">{c.number}</span> · {c.date}
                                                </span>
                                                <span className="font-semibold text-emerald-700 dark:text-emerald-400">− {inr(c.total)}</span>
                                            </a>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="mt-3 text-xs text-muted-foreground">Your GST tax invoice will appear here once it is issued by the operator.</p>
                                )}
                            </section>
                        )}

                        {b.refunds.length > 0 && (
                            <section className="rounded-3xl border border-border bg-card p-6">
                                <h2 className="flex items-center gap-2 font-display text-lg font-semibold text-foreground">
                                    <RotateCcw className="size-5 text-electric" /> Refunds
                                </h2>
                                <ul className="mt-4 space-y-3">
                                    {b.refunds.map((r, i) => (
                                        <li key={i} className="flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-muted/60 p-4 text-sm">
                                            <div>
                                                <p className="font-semibold text-foreground">{inr(r.amount)} {r.retained > 0 && <span className="font-normal text-muted-foreground">({inr(r.retained)} retained)</span>}</p>
                                                <p className="text-muted-foreground">{r.rule} · {r.date}</p>
                                            </div>
                                            <span className={cn('rounded-full px-3 py-1 text-xs font-semibold', r.status === 'success' ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300' : r.status === 'failed' ? 'bg-red-500/15 text-red-700 dark:text-red-300' : 'bg-amber-500/15 text-amber-700 dark:text-amber-300')}>
                                                {r.status === 'success' ? 'Refunded' : r.status === 'failed' ? 'Needs attention (support will contact you)' : 'Refund in progress'}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                                <p className="mt-3 text-xs text-muted-foreground">Refunds go back to your original payment method: usually 1–3 working days for UPI and 5–7 for cards and net-banking.</p>
                            </section>
                        )}

                        {b.rules && (
                            <section className="flex items-start gap-3 rounded-3xl border border-border bg-card p-6 text-sm">
                                <ScrollText className="size-5 shrink-0 text-volt-600" />
                                <p className="text-muted-foreground">
                                    You accepted the <strong className="text-foreground">station rules v{b.rules.version}</strong> on {b.rules.acceptedAt}. This acceptance is stored with your booking.
                                </p>
                            </section>
                        )}
                    </div>

                    <div className="h-fit space-y-6">
                    <aside className="rounded-3xl border border-border bg-card p-6 text-sm">
                        <h2 className="font-display text-lg font-semibold text-foreground">Price details</h2>
                        <div className="mt-4 space-y-2.5">
                            {b.amounts.lines.length > 0 ? (
                                b.amounts.lines.map((l, i) => (
                                    <Row key={i} label={l.type === 'energy' ? `${l.label} (${l.qty.toFixed(2)} kWh × ${inr(l.rate)})` : l.label} value={inr(l.amount)} />
                                ))
                            ) : (
                                <>
                                    <Row label={`Energy (${b.amounts.kwh.toFixed(2)} kWh × ${inr(b.amounts.ratePerKwh)})`} value={inr(b.amounts.energy)} />
                                    <Row label="Booking fee" value={inr(b.amounts.sessionFee)} />
                                </>
                            )}
                            {b.amounts.discount > 0 && <Row label={`Discount · ${b.amounts.couponCode}`} value={`− ${inr(b.amounts.discount)}`} />}
                            <Row label="Taxable value" value={inr(b.amounts.taxable)} />
                            {b.amounts.igst > 0 ? (
                                <Row label={`IGST ${b.amounts.gstRate}%`} value={inr(b.amounts.igst)} />
                            ) : (
                                <>
                                    <Row label={`CGST ${b.amounts.gstRate / 2}%`} value={inr(b.amounts.cgst)} />
                                    <Row label={`SGST ${b.amounts.gstRate / 2}%`} value={inr(b.amounts.sgst)} />
                                </>
                            )}
                            <div className="flex items-end justify-between border-t border-border pt-3">
                                <span className="font-semibold text-foreground">Total</span>
                                <span className="font-display text-2xl font-extrabold text-foreground">{inr(b.amounts.total)}</span>
                            </div>
                        </div>
                        <p className="mt-4 text-xs text-muted-foreground">
                            SAC {b.amounts.sac} · Place of supply {b.amounts.placeOfSupply}. Your GST tax invoice is issued after payment.
                        </p>
                        <div className="mt-4 rounded-xl bg-muted/60 p-3 text-xs text-muted-foreground">
                            Billed to <strong className="text-foreground">{b.customer.name}</strong>
                            {b.customer.gstin && <> · GSTIN {b.customer.gstin}</>}
                            <br />
                            {b.customer.email} · +91 {b.customer.phone}
                        </div>
                    </aside>
                    <CancellationPolicyCard policy={b.cancellationPolicy} agreed className="rounded-3xl p-6" />
                    </div>
                </div>
            </div>
            {confirmCancel && b.cancelQuote && (
                <div className="fixed inset-0 z-[60] flex items-end justify-center bg-black/50 p-4 backdrop-blur-sm sm:items-center" role="dialog" aria-modal="true" aria-labelledby="cancel-title">
                    <div className="rise-in w-full max-w-md rounded-3xl border border-border bg-card p-6 shadow-2xl">
                        <h2 id="cancel-title" className="font-display text-xl font-bold text-foreground">Cancel this booking?</h2>
                        <p className="mt-1 text-sm text-muted-foreground">{b.station.name} · {b.date}, {b.time}</p>
                        {b.cancelQuote.paid > 0 ? (
                            <dl className="mt-5 space-y-2 rounded-2xl bg-muted/60 p-4 text-sm">
                                <div className="flex justify-between"><dt className="text-muted-foreground">Paid</dt><dd className="font-medium text-foreground">{inr(b.cancelQuote.paid)}</dd></div>
                                <div className="flex justify-between"><dt className="text-muted-foreground">Cancellation charge</dt><dd className="font-medium text-foreground">{inr(b.cancelQuote.retained)}</dd></div>
                                <div className="flex justify-between border-t border-border pt-2"><dt className="font-semibold text-foreground">You get back</dt><dd className="font-display text-lg font-bold text-foreground">{inr(b.cancelQuote.refund)}</dd></div>
                                <p className="pt-1 text-xs text-muted-foreground">{b.cancelQuote.rule}. See the <Link href="/refund-policy" className="underline">refund policy</Link>.</p>
                            </dl>
                        ) : (
                            <p className="mt-5 rounded-2xl bg-muted/60 p-4 text-sm text-muted-foreground">Nothing has been paid yet, so there is no charge. The slot will be released.</p>
                        )}
                        <div className="mt-6 flex gap-3">
                            <button type="button" onClick={() => setConfirmCancel(false)} className="flex-1 rounded-xl border border-border py-3 text-sm font-semibold text-foreground hover:bg-muted">Keep booking</button>
                            <button type="button" onClick={cancel} disabled={cancelling} className="flex flex-1 items-center justify-center gap-2 rounded-xl bg-red-600 py-3 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-60">
                                {cancelling && <LoaderCircle className="size-4 animate-spin" />} Cancel booking
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </SiteLayout>
    );
}

function Item({ icon: Icon, label, value, sub }: { icon: typeof Zap; label: string; value: string; sub?: string }) {
    return (
        <div className="flex gap-3">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted">
                <Icon className="size-5 text-volt-600" />
            </span>
            <div className="min-w-0">
                <dt className="text-xs text-muted-foreground">{label}</dt>
                <dd className="font-semibold text-foreground">{value}</dd>
                {sub && <dd className="text-sm text-muted-foreground">{sub}</dd>}
            </div>
        </div>
    );
}

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex justify-between gap-3 text-foreground">
            <span className="text-muted-foreground">{label}</span>
            <span className="font-medium">{value}</span>
        </div>
    );
}
