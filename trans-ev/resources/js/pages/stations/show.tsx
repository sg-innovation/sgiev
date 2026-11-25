import { StatusPill, directionsUrl, inr, stationState } from '@/components/site/station-bits';
import CancellationPolicyCard, { type PolicyView } from '@/components/site/cancellation-policy';
import { RatingBadge, StationReviewsSection, type StationReviews } from '@/components/site/reviews';
import StationMap from '@/components/site/station-map';
import SiteLayout from '@/layouts/site-layout';
import { cn } from '@/lib/utils';
import { type StationDetail } from '@/types/station';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, BatteryCharging, CalendarClock, Car, Check, ChevronLeft, ChevronRight, Clock, Mail, MapPin, Navigation, Phone, ShieldCheck, X, Zap } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Props {
    station: StationDetail;
    rules: { title: string; version: number; content: string } | null;
    mapStyle: string;
    cancellationPolicy: PolicyView | null;
    reviews?: StationReviews;
}

const todayIndex = new Date().getDay();

function Gallery({ photos, name }: { photos: string[]; name: string }) {
    const [open, setOpen] = useState<number | null>(null);
    useEffect(() => {
        if (open === null) return;
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') setOpen(null);
            if (e.key === 'ArrowRight') setOpen((i) => (i === null ? i : (i + 1) % photos.length));
            if (e.key === 'ArrowLeft') setOpen((i) => (i === null ? i : (i - 1 + photos.length) % photos.length));
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [open, photos.length]);

    if (photos.length === 0) return null;
    const [cover, ...rest] = photos;

    return (
        <section aria-label="Photos">
            <div className="grid gap-2 overflow-hidden rounded-2xl sm:grid-cols-4 sm:grid-rows-2">
                <button type="button" onClick={() => setOpen(0)} className="group relative aspect-video overflow-hidden sm:col-span-2 sm:row-span-2 sm:aspect-auto">
                    <img src={cover} alt={`${name} – photo 1`} className="size-full object-cover transition duration-500 group-hover:scale-105" loading="eager" />
                </button>
                {rest.slice(0, 4).map((src, i) => (
                    <button key={src} type="button" onClick={() => setOpen(i + 1)} className="group relative hidden aspect-video overflow-hidden sm:block">
                        <img src={src} alt={`${name} – photo ${i + 2}`} className="size-full object-cover transition duration-500 group-hover:scale-105" loading="lazy" />
                        {i === 3 && photos.length > 5 && (
                            <span className="absolute inset-0 flex items-center justify-center bg-black/55 font-semibold text-white">+{photos.length - 5} more</span>
                        )}
                    </button>
                ))}
            </div>
            {photos.length > 1 && (
                <button type="button" onClick={() => setOpen(0)} className="mt-2 text-sm font-medium text-electric hover:underline sm:hidden">
                    View all {photos.length} photos
                </button>
            )}
            {open !== null && (
                <div role="dialog" aria-modal="true" aria-label="Photo viewer" className="fixed inset-0 z-50 flex items-center justify-center bg-black/90 p-4" onClick={() => setOpen(null)}>
                    <img src={photos[open]} alt={`${name} – photo ${open + 1}`} className="max-h-[85vh] max-w-full rounded-xl object-contain" onClick={(e) => e.stopPropagation()} />
                    <button type="button" aria-label="Close" onClick={() => setOpen(null)} className="absolute top-4 right-4 rounded-full bg-white/10 p-2 text-white hover:bg-white/20"><X className="size-5" /></button>
                    {photos.length > 1 && (
                        <>
                            <button type="button" aria-label="Previous photo" onClick={(e) => { e.stopPropagation(); setOpen((open - 1 + photos.length) % photos.length); }} className="absolute left-4 rounded-full bg-white/10 p-2 text-white hover:bg-white/20"><ChevronLeft className="size-6" /></button>
                            <button type="button" aria-label="Next photo" onClick={(e) => { e.stopPropagation(); setOpen((open + 1) % photos.length); }} className="absolute right-4 rounded-full bg-white/10 p-2 text-white hover:bg-white/20"><ChevronRight className="size-6" /></button>
                        </>
                    )}
                    <p className="absolute bottom-4 text-sm text-white/70">{open + 1} / {photos.length}</p>
                </div>
            )}
        </section>
    );
}

export default function StationShow({ station, rules, mapStyle, cancellationPolicy = null, reviews }: Props) {
    const state = stationState(station);

    return (
        <SiteLayout>
            <Head title={`${station.name} – EV charging in ${station.city}`} />

            {/* Hero */}
            <section className="relative overflow-hidden bg-ink text-white">
                <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,rgba(0,229,168,0.25),transparent_55%),radial-gradient(ellipse_at_bottom_left,rgba(0,119,255,0.25),transparent_55%)]" />
                <div className="relative mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
                    <Link href="/stations" className="inline-flex items-center gap-1.5 text-sm text-white/70 hover:text-white">
                        <ArrowLeft className="size-4" /> All stations
                    </Link>
                    <div className="mt-4 flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                        <div>
                            <StatusPill state={state} className="bg-white/10 text-white ring-white/20" />
                            <h1 className="mt-3 font-display text-3xl font-bold sm:text-4xl">{station.name}</h1>
                            {station.rating && (
                                <a href="#reviews" className="mt-1 inline-flex text-white/90 hover:text-white">
                                    <RatingBadge rating={station.rating} />
                                </a>
                            )}
                            {station.operator?.name && (
                                <p className="mt-1 text-sm text-white/70">
                                    Operated by <span className="font-semibold text-white">{station.operator.name}</span>
                                    {!station.operator.isPlatform && ' · listed on Trans EV'}
                                </p>
                            )}
                            <p className="mt-2 flex items-start gap-1.5 text-white/70">
                                <MapPin className="mt-1 size-4 shrink-0" />
                                {station.fullAddress}
                            </p>
                            {station.statusReason && (
                                <p className="mt-3 inline-block rounded-lg bg-amber-400/15 px-3 py-1.5 text-sm text-amber-200">
                                    {station.statusReason}
                                    {station.statusUntil && ` · expected back ${new Date(station.statusUntil).toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' })}`}
                                </p>
                            )}
                        </div>
                        <div className="flex flex-wrap gap-3">
                            <a href={directionsUrl(station.lat, station.lng)} target="_blank" rel="noopener noreferrer" className="flex items-center gap-2 rounded-full border border-white/20 px-5 py-3 text-sm font-semibold hover:bg-white/10">
                                <Navigation className="size-4" /> Directions
                            </a>
                            <Link
                                href={`/book/${station.slug}`}
                                aria-disabled={!station.bookable}
                                className={cn(
                                    'flex items-center gap-2 rounded-full px-6 py-3 text-sm font-semibold shadow-lg transition',
                                    station.bookable ? 'bg-gradient-to-r from-volt to-electric text-white shadow-volt/30 hover:brightness-110' : 'pointer-events-none bg-white/10 text-white/50',
                                )}
                            >
                                <CalendarClock className="size-4" /> {station.bookable ? 'Book a slot' : 'Booking unavailable'}
                            </Link>
                        </div>
                    </div>

                    <dl className="mt-8 grid grid-cols-2 gap-3 sm:grid-cols-4">
                        {[
                            { icon: Zap, label: 'Max power', value: `${station.maxPowerKw} kW` },
                            { icon: BatteryCharging, label: 'Chargers online', value: `${station.chargersOnline} of ${station.chargersTotal}` },
                            { icon: Clock, label: 'Today', value: station.todayHours ?? '—' },
                            { icon: ShieldCheck, label: 'From', value: station.fromRate ? `${inr(station.fromRate)}/kWh` : '—' },
                        ].map(({ icon: Icon, label, value }) => (
                            <div key={label} className="rounded-2xl bg-white/5 p-4 ring-1 ring-white/10 backdrop-blur">
                                <dt className="flex items-center gap-1.5 text-xs text-white/60"><Icon className="size-3.5 text-volt" />{label}</dt>
                                <dd className="mt-1 font-display text-lg font-semibold">{value}</dd>
                            </div>
                        ))}
                    </dl>
                </div>
            </section>

            <div className="mx-auto grid max-w-7xl gap-8 px-4 py-10 sm:px-6 lg:grid-cols-3 lg:px-8">
                <div className="space-y-8 lg:col-span-2">
                    <Gallery photos={station.photos} name={station.name} />
                    {station.description && <p className="text-lg text-muted-foreground">{station.description}</p>}

                    {/* Chargers */}
                    <section>
                        <h2 className="font-display text-xl font-semibold">Chargers</h2>
                        <div className="mt-4 grid gap-3 sm:grid-cols-2">
                            {station.chargers.map((c) => (
                                <div key={c.code} className="flex items-center gap-4 rounded-2xl border border-border bg-card p-4">
                                    <div className={cn('flex size-12 shrink-0 items-center justify-center rounded-xl', c.type === 'DC' ? 'bg-volt/15 text-volt-600' : 'bg-electric/10 text-electric')}>
                                        <Zap className="size-6" />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <p className="font-semibold">{c.type} · {c.powerKw} kW</p>
                                        <p className="truncate text-sm text-muted-foreground">{c.connectors.join(', ')} · {c.code}</p>
                                        {c.electrical && (
                                            <p className="mt-0.5 text-xs text-muted-foreground" title={c.standard ?? undefined}>
                                                {c.electrical}
                                            </p>
                                        )}
                                        {c.units.length > 0 && (
                                            <div className="mt-2 flex flex-wrap gap-1.5">
                                                {c.units.map((u) => (
                                                    <span
                                                        key={u.no}
                                                        className={cn(
                                                            'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold',
                                                            u.live === 'available' ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                                                                : u.live === 'faulted' || u.live === 'unavailable' ? 'bg-red-500/10 text-red-700 dark:text-red-300'
                                                                : 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
                                                        )}
                                                    >
                                                        Gun {u.no}: {u.liveLabel}
                                                    </span>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                    <StatusPill state={c.status} />
                                </div>
                            ))}
                        </div>
                    </section>

                    {/* Pricing */}
                    <section>
                        <h2 className="font-display text-xl font-semibold">Pricing</h2>
                        <div className="mt-4 overflow-hidden rounded-2xl border border-border">
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[560px] text-sm">
                                    <thead className="bg-muted/60 text-left text-xs text-muted-foreground uppercase">
                                        <tr>
                                            <th className="px-4 py-3">Tariff</th>
                                            <th className="px-4 py-3">Energy</th>
                                            <th className="px-4 py-3">Incl. GST</th>
                                            <th className="px-4 py-3">Booking fee</th>
                                            <th className="px-4 py-3">Idle fee</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-border">
                                        {station.tariffs.map((t) => (
                                            <tr key={t.name + t.appliesTo}>
                                                <td className="px-4 py-3 font-medium">
                                                    {t.name}<span className="ml-1.5 text-xs text-muted-foreground">({t.appliesTo})</span>
                                                    {t.bands.map((b) => (
                                                        <span key={b.name + b.from} className="mt-1 block text-xs font-normal text-muted-foreground">
                                                            {b.name}: {b.from}–{b.to}, {b.days} · {inr(b.rate)}/kWh + GST
                                                        </span>
                                                    ))}
                                                    {t.parkingFee + t.parkingFeePerHour > 0 && (
                                                        <span className="mt-1 block text-xs font-normal text-muted-foreground">
                                                            Parking: {t.parkingFee > 0 && `${inr(t.parkingFee)} per booking`}{t.parkingFee > 0 && t.parkingFeePerHour > 0 && ' + '}{t.parkingFeePerHour > 0 && `${inr(t.parkingFeePerHour)}/hour`}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3">{inr(t.ratePerKwh)}/kWh</td>
                                                <td className="px-4 py-3 font-semibold">{inr(t.ratePerKwhInclGst)}/kWh</td>
                                                <td className="px-4 py-3">{inr(t.sessionFee)}</td>
                                                <td className="px-4 py-3">{inr(t.idleFeePerMin)}/min after {t.idleGraceMinutes} min</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <p className="border-t border-border bg-muted/30 px-4 py-2.5 text-xs text-muted-foreground">
                                GST {station.tariffs[0]?.gstRate ?? 18}% (SAC {station.tariffs[0]?.sacCode ?? '998714'}) is added at checkout. A GST tax invoice is issued for every booking.
                            </p>
                        </div>
                    </section>

                    {/* Rules */}
                    {rules && (
                        <section>
                            <h2 className="font-display text-xl font-semibold">Station rules <span className="text-sm font-normal text-muted-foreground">v{rules.version}</span></h2>
                            <p className="mt-1 text-sm text-muted-foreground">You will be asked to accept these rules before you pay.</p>
                            <div
                                className="prose-rules mt-4 rounded-2xl border border-border bg-card p-5 text-sm leading-relaxed [&_li]:mb-2 [&_ol]:list-decimal [&_ol]:pl-5 [&_ul]:list-disc [&_ul]:pl-5"
                                // Admin-authored HTML from the rich editor (staff-only input).
                                dangerouslySetInnerHTML={{ __html: rules.content }}
                            />
                        </section>
                    )}

                    <section id="book" className="scroll-mt-24 rounded-2xl border border-dashed border-volt/50 bg-volt/5 p-6">
                        <h2 className="font-display text-xl font-semibold">Book a charging slot</h2>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {station.status === 'online'
                                ? `Slots are ${station.slotMinutes} minutes long and can be booked up to 7 days ahead.`
                                : 'This station is not taking bookings right now. You can still pick a date after it is back online.'}
                        </p>
                        <Link
                            href={`/book/${station.slug}`}
                            className="mt-4 inline-flex items-center gap-2 rounded-full bg-gradient-to-r from-volt-600 to-electric px-6 py-3 font-semibold text-white shadow-lg shadow-volt/25 hover:brightness-110"
                        >
                            <CalendarClock className="size-4" /> Choose a slot
                        </Link>
                    </section>

                    {reviews && (
                        <div id="reviews" className="scroll-mt-24">
                            <StationReviewsSection reviews={reviews} />
                        </div>
                    )}
                </div>

                <aside className="space-y-6">
                    <div className="overflow-hidden rounded-2xl border border-border">
                        <StationMap stations={[station]} styleUrl={mapStyle} single className="h-64 w-full" />
                    </div>

                    <div className="rounded-2xl border border-border bg-card p-5">
                        <h2 className="font-display font-semibold">Opening hours</h2>
                        {station.is24x7 ? (
                            <p className="mt-3 flex items-center gap-2 text-sm font-medium text-volt-600"><Check className="size-4" />Open 24 hours, 7 days</p>
                        ) : (
                            <ul className="mt-3 space-y-1.5 text-sm">
                                {station.hours.map((h) => (
                                    <li key={h.weekday} className={cn('flex justify-between rounded-lg px-2 py-1', h.weekday === todayIndex && 'bg-volt/10 font-semibold')}>
                                        <span>{h.day}</span>
                                        <span>{h.closed ? 'Closed' : `${h.opens} – ${h.closes}`}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {station.holidays.length > 0 && (
                            <div className="mt-4 border-t border-border pt-3">
                                <h3 className="text-xs font-semibold text-muted-foreground uppercase">Upcoming changes</h3>
                                <ul className="mt-2 space-y-1 text-sm">
                                    {station.holidays.map((h) => (
                                        <li key={h.date} className="flex justify-between">
                                            <span>{new Date(h.date).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })} {h.label && `· ${h.label}`}</span>
                                            <span>{h.closed ? 'Closed' : `${h.opens} – ${h.closes}`}</span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </div>

                    <div className="rounded-2xl border border-border bg-card p-5">
                        <h2 className="flex items-center gap-2 font-display font-semibold"><Car className="size-4 text-electric" />Parking</h2>
                        {station.parking ? (
                            <p className="mt-2 text-sm text-muted-foreground">
                                <span className="font-medium text-foreground">{station.parking.type ?? 'Parking available'}</span>
                                {station.parking.note && <><br />{station.parking.note}</>}
                            </p>
                        ) : (
                            <p className="mt-2 text-sm text-muted-foreground">No dedicated parking. Please plan for drop-off charging.</p>
                        )}
                    </div>

                    {station.amenities.length > 0 && (
                        <div className="rounded-2xl border border-border bg-card p-5">
                            <h2 className="font-display font-semibold">Amenities</h2>
                            <div className="mt-3 flex flex-wrap gap-2">
                                {station.amenities.map((a) => <span key={a} className="rounded-full bg-muted px-3 py-1 text-sm">{a}</span>)}
                            </div>
                        </div>
                    )}

                    <div className="rounded-2xl border border-border bg-card p-5 text-sm">
                        <h2 className="font-display font-semibold">Contact</h2>
                        <ul className="mt-3 space-y-2 text-muted-foreground">
                            {station.phone && <li className="flex gap-2"><Phone className="size-4" />{station.phone}</li>}
                            {station.email && <li className="flex gap-2"><Mail className="size-4" />{station.email}</li>}
                            <li className="text-xs">Station code: {station.code}</li>
                            {station.operatorLegalName && <li className="text-xs">Operator &amp; invoicing: {station.operatorLegalName}</li>}
                        </ul>
                    </div>

                    <CancellationPolicyCard policy={cancellationPolicy} />
                </aside>
            </div>
        </SiteLayout>
    );
}
