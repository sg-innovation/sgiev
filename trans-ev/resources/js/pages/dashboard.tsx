import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { inr } from '@/components/site/station-bits';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, BadgePercent, BatteryCharging, CalendarClock, Car, FileText, LocateFixed, Receipt, ShieldCheck, TicketPercent, Wallet, Zap } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'My account', href: '/dashboard' }];

function Empty({ icon: Icon, title, text, cta }: { icon: typeof Zap; title: string; text: string; cta?: { href: string; label: string } }) {
    return (
        <div className="flex flex-col items-center justify-center rounded-2xl border border-dashed border-border px-6 py-10 text-center">
            <div className="flex size-12 items-center justify-center rounded-2xl bg-muted">
                <Icon className="size-6 text-muted-foreground" />
            </div>
            <p className="mt-4 font-semibold text-foreground">{title}</p>
            <p className="mt-1 max-w-xs text-sm text-muted-foreground">{text}</p>
            {cta && (
                <Link href={cta.href} className="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-volt-600 hover:underline">
                    {cta.label} <ArrowRight className="size-4" />
                </Link>
            )}
        </div>
    );
}

interface Props {
    stats: { upcoming: number; kwh: number; spent: number; bookings: number; saved: number };
    documents: { kind: string; number: string; date: string; amount: number; url: string; booking: string; station: string | null }[];
    offers: { code: string; name: string; headline: string; description: string | null; endsAt: string | null; chargerType: string | null; firstBookingOnly: boolean }[];
    upcoming: { code: string; statusLabel: string; status: string; station: { name: string; city: string }; charger: { type: string; powerKw: number }; date: string; time: string; total: number }[];
    vehicles: { id: number; label: string; battery: number }[];
}

export default function Dashboard({ stats, upcoming, vehicles, documents = [], offers = [] }: Props) {
    const { auth } = usePage<SharedData>().props;
    const firstName = auth.user.name.split(' ')[0];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My account" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                {/* Welcome banner */}
                <section className="relative overflow-hidden rounded-3xl bg-ink p-6 text-white sm:p-8">
                    <div className="pointer-events-none absolute -top-24 -right-16 size-72 rounded-full bg-volt/30 blur-3xl" />
                    <div className="pointer-events-none absolute -bottom-28 left-1/4 size-72 rounded-full bg-electric/30 blur-3xl" />
                    <div className="relative flex flex-col gap-6 md:flex-row md:items-center md:justify-between">
                        <div>
                            <p className="text-sm text-white/60">Welcome back</p>
                            <h1 className="mt-1 font-display text-3xl font-bold">Hi {firstName}, ready to charge?</h1>
                            <p className="mt-2 max-w-lg text-white/70">Find the nearest charger, book a slot and keep all your receipts and GST invoices here.</p>
                        </div>
                        <div className="flex flex-wrap gap-3">
                            <Link href="/stations?near=1" className="inline-flex items-center gap-2 rounded-full bg-gradient-to-r from-volt to-electric px-5 py-3 font-semibold text-white shadow-lg shadow-volt/30 hover:brightness-110">
                                <LocateFixed className="size-5" /> Chargers near me
                            </Link>
                            <Link href="/stations" className="inline-flex items-center gap-2 rounded-full bg-white/10 px-5 py-3 font-semibold ring-1 ring-white/20 hover:bg-white/15">
                                Browse map
                            </Link>
                        </div>
                    </div>
                </section>

                {/* Stats */}
                <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {[
                        { icon: CalendarClock, label: 'Upcoming bookings', value: String(stats.upcoming), tone: 'text-electric bg-electric/10' },
                        { icon: BatteryCharging, label: 'Energy charged', value: `${stats.kwh.toFixed(1)} kWh`, tone: 'text-volt-600 bg-volt/10' },
                        { icon: Wallet, label: stats.saved > 0 ? `Total spent · saved ${inr(stats.saved, 0)}` : 'Total spent', value: inr(stats.spent, 0), tone: 'text-amber-600 bg-amber-500/10 dark:text-amber-300' },
                        { icon: Receipt, label: 'All bookings', value: String(stats.bookings), tone: 'text-violet-600 bg-violet-500/10 dark:text-violet-300' },
                    ].map((s) => (
                        <div key={s.label} className="rounded-2xl border border-border bg-card p-5">
                            <div className={`flex size-11 items-center justify-center rounded-xl ${s.tone}`}>
                                <s.icon className="size-5" />
                            </div>
                            <p className="mt-4 font-display text-2xl font-bold text-foreground">{s.value}</p>
                            <p className="text-sm text-muted-foreground">{s.label}</p>
                        </div>
                    ))}
                </section>

                <div className="grid gap-6 xl:grid-cols-3">
                    <section className="rounded-3xl border border-border bg-card p-6 xl:col-span-2">
                        <div className="flex items-center justify-between">
                            <h2 className="font-display text-lg font-semibold text-foreground">Upcoming bookings</h2>
                            <Link href="/account/bookings" className="text-sm font-semibold text-volt-600 hover:underline">View all</Link>
                        </div>
                        <div className="mt-5 space-y-3">
                            {upcoming.length === 0 ? (
                                <Empty icon={CalendarClock} title="No upcoming bookings" text="Book a charging slot at any Trans EV station. Your bookings will appear here." cta={{ href: '/stations', label: 'Find a station' }} />
                            ) : (
                                upcoming.map((b) => (
                                    <Link key={b.code} href={`/account/bookings/${b.code}`} className="flex items-center gap-4 rounded-2xl border border-border p-4 transition hover:border-volt/50">
                                        <span className="flex size-11 items-center justify-center rounded-xl bg-volt/10"><Zap className="size-5 text-volt-600" /></span>
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate font-semibold text-foreground">{b.station.name}</span>
                                            <span className="block text-sm text-muted-foreground">{b.date} · {b.time} · {b.charger.type} {b.charger.powerKw} kW</span>
                                        </span>
                                        <span className="text-right">
                                            <span className="block font-semibold text-foreground">{inr(b.total)}</span>
                                            <span className={b.status === 'held' ? 'text-xs font-semibold text-amber-600 dark:text-amber-300' : 'text-xs font-semibold text-volt-600'}>{b.statusLabel}</span>
                                        </span>
                                    </Link>
                                ))
                            )}
                        </div>
                    </section>

                    <section className="rounded-3xl border border-border bg-card p-6">
                        <h2 className="font-display text-lg font-semibold text-foreground">My vehicles</h2>
                        <div className="mt-5 space-y-3">
                            {vehicles.length === 0 ? (
                                <Empty icon={Car} title="Add your EV" text="Tick 'Save this vehicle' when booking to book faster next time." />
                            ) : (
                                vehicles.map((v) => (
                                    <div key={v.id} className="flex items-center gap-3 rounded-2xl bg-muted/60 p-4">
                                        <Car className="size-5 text-volt-600" />
                                        <span className="text-sm font-medium text-foreground">{v.label}</span>
                                        <span className="ml-auto text-xs text-muted-foreground">{v.battery} kWh</span>
                                    </div>
                                ))
                            )}
                        </div>
                    </section>
                </div>

                <div className="grid gap-6 xl:grid-cols-3">
                    <section className="rounded-3xl border border-border bg-card p-6 xl:col-span-2">
                        <div className="flex items-center justify-between">
                            <h2 className="font-display text-lg font-semibold text-foreground">GST invoices & credit notes</h2>
                            <Link href="/account/bookings" className="text-sm font-semibold text-volt-600 hover:underline">All bookings</Link>
                        </div>
                        <div className="mt-5">
                            {documents.length === 0 ? (
                                <Empty icon={FileText} title="No invoices yet" text="A GST tax invoice is issued for every paid booking, and a credit note for every refund." />
                            ) : (
                                <ul className="divide-y divide-border">
                                    {documents.map((d) => (
                                        <li key={d.number}>
                                            <a href={d.url} target="_blank" rel="noopener" className="flex items-center gap-4 py-3 transition hover:text-foreground">
                                                <span className={`flex size-10 shrink-0 items-center justify-center rounded-xl ${d.amount < 0 ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' : 'bg-electric/10 text-electric'}`}>
                                                    <FileText className="size-5" />
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="block text-sm font-semibold text-foreground">
                                                        {d.kind} <span className="font-mono">{d.number}</span>
                                                    </span>
                                                    <span className="block truncate text-xs text-muted-foreground">
                                                        {d.date} · {d.booking}
                                                        {d.station && ` · ${d.station}`}
                                                    </span>
                                                </span>
                                                <span className={`text-sm font-semibold ${d.amount < 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-foreground'}`}>
                                                    {d.amount < 0 ? `− ${inr(-d.amount)}` : inr(d.amount)}
                                                </span>
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </section>

                    {offers.length > 0 ? (
                        <section className="rounded-3xl border border-border bg-gradient-to-br from-volt/10 to-electric/10 p-6">
                            <TicketPercent className="size-8 text-volt-600" />
                            <h2 className="mt-4 font-display text-lg font-semibold text-foreground">Offers for you</h2>
                            <ul className="mt-4 space-y-3">
                                {offers.map((o) => (
                                    <li key={o.code} className="rounded-2xl bg-card/80 p-3 ring-1 ring-border">
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="text-sm font-semibold text-foreground">{o.headline}</span>
                                            <span className="flex items-center gap-1 rounded-md border border-volt/50 px-2 py-0.5 font-mono text-xs font-bold text-volt-700 dark:text-volt">
                                                <BadgePercent className="size-3.5" />
                                                {o.code}
                                            </span>
                                        </div>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {o.description ?? o.name}
                                            {o.endsAt && ` · till ${new Date(o.endsAt).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })}`}
                                        </p>
                                    </li>
                                ))}
                            </ul>
                            <p className="mt-3 text-xs text-muted-foreground">Enter the code in the booking summary at checkout.</p>
                        </section>
                    ) : (
                    <section className="rounded-3xl border border-border bg-gradient-to-br from-volt/10 to-electric/10 p-6">
                        <ShieldCheck className="size-8 text-volt-600" />
                        <h2 className="mt-4 font-display text-lg font-semibold text-foreground">Keep your account secure</h2>
                        <p className="mt-1 text-sm text-muted-foreground">Use a strong, unique password and keep your mobile number up to date for booking alerts.</p>
                        <Link href="/settings/password" className="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-volt-600 hover:underline">
                            Update password <ArrowRight className="size-4" />
                        </Link>
                    </section>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
