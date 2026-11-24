import { StationCardView, inr } from '@/components/site/station-bits';
import SiteLayout from '@/layouts/site-layout';
import { cn } from '@/lib/utils';
import { type StationCard } from '@/types/station';
import { fill, icon, items, list, str, type SiteSections } from '@/lib/site-content';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowRight,
    BadgeIndianRupee,
    BatteryCharging,
    Building2,
    Car,
    ChevronDown,
    Clock,
    LocateFixed,
    MapPinned,
    Plug,
    Receipt,
    Search,
    ShieldCheck,
    Sparkles,
    Zap,
} from 'lucide-react';
import { type FormEvent, useMemo, useState } from 'react';

interface Props {
    stats: { stations: number; online: number; cities: number; chargers: number; maxKw: number; fromRate: number };
    pricing: { acMin: number; acMax: number; dcMin: number; dcMax: number; sessionFee: number; gstRate: number; idleFee: number; idleGrace: number };
    cities: { city: string; state: string; stations: number; online: number }[];
    featured: StationCard[];
    site: SiteSections;
}

function SectionHeading({ eyebrow, title, text, center }: { eyebrow: string; title: string; text?: string; center?: boolean }) {
    return (
        <div className={cn('max-w-2xl', center && 'mx-auto text-center')}>
            <p className="inline-flex items-center gap-1.5 rounded-full bg-volt/10 px-3 py-1 text-xs font-semibold tracking-wide text-volt-600 uppercase ring-1 ring-volt/20">
                <Sparkles className="size-3.5" /> {eyebrow}
            </p>
            <h2 className="mt-4 font-display text-3xl font-bold tracking-tight text-foreground sm:text-4xl">{title}</h2>
            {text && <p className="mt-3 text-lg text-muted-foreground">{text}</p>}
        </div>
    );
}

function CostCalculator({ pricing, note }: { pricing: Props['pricing']; note: string }) {
    const [battery, setBattery] = useState(40);
    const [from, setFrom] = useState(20);
    const [to, setTo] = useState(80);
    const [kind, setKind] = useState<'ac' | 'dc'>('dc');

    const result = useMemo(() => {
        const rate = kind === 'dc' ? pricing.dcMin : pricing.acMax;
        const powerKw = kind === 'dc' ? 60 : 22;
        const kwh = Math.max(0, ((to - from) / 100) * battery) / 0.9; // ~90% charging efficiency
        const energy = kwh * rate;
        const subtotal = energy + pricing.sessionFee;
        const gst = subtotal * (pricing.gstRate / 100);
        const minutes = (kwh / (powerKw * 0.85)) * 60;
        const km = ((to - from) / 100) * battery * 7; // ~7 km per kWh typical
        return { kwh, energy, gst, total: subtotal + gst, minutes, km, perKm: km > 0 ? (subtotal + gst) / km : 0 };
    }, [battery, from, to, kind, pricing]);

    return (
        <div className="grid gap-6 rounded-3xl border border-border bg-card p-6 shadow-xl shadow-black/5 sm:p-8 lg:grid-cols-2">
            <div className="space-y-6">
                <div className="flex rounded-full bg-muted p-1">
                    {(['dc', 'ac'] as const).map((k) => (
                        <button
                            key={k}
                            onClick={() => setKind(k)}
                            className={cn('flex-1 rounded-full py-2 text-sm font-semibold transition', kind === k ? 'bg-card text-foreground shadow' : 'text-muted-foreground')}
                        >
                            {k === 'dc' ? 'DC Fast (60 kW)' : 'AC (22 kW)'}
                        </button>
                    ))}
                </div>
                {[
                    { label: 'Battery size', value: battery, set: setBattery, min: 2, max: 100, unit: 'kWh' },
                    { label: 'Current charge', value: from, set: (v: number) => setFrom(Math.min(v, to - 5)), min: 0, max: 95, unit: '%' },
                    { label: 'Charge up to', value: to, set: (v: number) => setTo(Math.max(v, from + 5)), min: 5, max: 100, unit: '%' },
                ].map((f) => (
                    <label key={f.label} className="block">
                        <span className="flex justify-between text-sm">
                            <span className="font-medium text-foreground">{f.label}</span>
                            <span className="font-display font-semibold text-volt-600">
                                {f.value} {f.unit}
                            </span>
                        </span>
                        <input type="range" min={f.min} max={f.max} value={f.value} onChange={(e) => f.set(Number(e.target.value))} className="mt-2 w-full accent-[#00B386]" />
                    </label>
                ))}
                {note && <p className="text-xs text-muted-foreground">{note}</p>}
            </div>

            <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-ink to-[#0d2a45] p-6 text-white">
                <div className="absolute -top-16 -right-16 size-48 rounded-full bg-volt/30 blur-3xl" />
                <p className="text-sm text-white/70">Estimated total (incl. {pricing.gstRate}% GST)</p>
                <p className="mt-1 font-display text-5xl font-extrabold">{inr(result.total)}</p>
                <dl className="mt-6 grid grid-cols-2 gap-3 text-sm">
                    {[
                        ['Energy', `${result.kwh.toFixed(1)} kWh`],
                        ['Approx. time', `${Math.max(1, Math.round(result.minutes))} min`],
                        ['Range added', `~${Math.round(result.km)} km`],
                        ['Cost per km', `${inr(result.perKm)}`],
                        ['Energy charge', inr(result.energy)],
                        ['GST', inr(result.gst)],
                    ].map(([k, v]) => (
                        <div key={k} className="rounded-xl bg-white/10 p-3 ring-1 ring-white/10">
                            <dt className="text-xs text-white/60">{k}</dt>
                            <dd className="mt-0.5 font-semibold">{v}</dd>
                        </div>
                    ))}
                </dl>
                <Link href="/stations" className="mt-6 flex items-center justify-center gap-2 rounded-full bg-gradient-to-r from-volt to-electric py-3 font-semibold text-white">
                    Find a charger <ArrowRight className="size-4" />
                </Link>
            </div>
        </div>
    );
}

const CITY_PREVIEW = 8;

export default function Home({ stats, pricing, cities, featured, site }: Props) {
    // Editor text may contain live values in {braces}.
    const vars = {
        stations: stats.stations, online: stats.online, cities: stats.cities, chargers: stats.chargers, max_kw: stats.maxKw,
        from_rate: stats.fromRate, session_fee: pricing.sessionFee, gst_rate: pricing.gstRate, idle_fee: pricing.idleFee, idle_grace: pricing.idleGrace,
    };
    const t = (section: string, key: string) => fill(str(site[section], key), vars);
    const [showAllCities, setShowAllCities] = useState(false);
    const [openFaq, setOpenFaq] = useState<number | null>(0);
    const [q, setQ] = useState('');

    const search = (e: FormEvent) => {
        e.preventDefault();
        router.visit(`/stations${q ? `?q=${encodeURIComponent(q)}` : ''}`);
    };

    return (
        <SiteLayout transparentHeader>
            <Head title={t('seo', 'title')}>
                <meta name="description" content={t('seo', 'description')} />
            </Head>

            {/* ───────── Hero ───────── */}
            <section className="relative isolate overflow-hidden bg-ink pt-16 text-white">
                <div className="pointer-events-none absolute inset-0 -z-10">
                    <div className="absolute -top-40 -right-40 size-[40rem] rounded-full bg-volt/25 blur-[120px]" />
                    <div className="absolute -bottom-48 -left-32 size-[36rem] rounded-full bg-electric/35 blur-[120px]" />
                    <div className="absolute inset-0 bg-[linear-gradient(to_right,rgba(255,255,255,0.05)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.05)_1px,transparent_1px)] bg-[size:56px_56px] [mask-image:radial-gradient(ellipse_at_center,black_30%,transparent_75%)]" />
                </div>

                <div className="mx-auto grid max-w-7xl items-center gap-14 px-4 pt-16 pb-20 sm:px-6 lg:grid-cols-[1.1fr_1fr] lg:px-8 lg:pt-24 lg:pb-28">
                    <div className="rise-in">
                        <span className="inline-flex items-center gap-2 rounded-full bg-white/10 px-3.5 py-1.5 text-xs font-semibold text-volt ring-1 ring-white/15 backdrop-blur">
                            <span className="relative flex size-2">
                                <span className="absolute inline-flex size-full animate-ping rounded-full bg-volt opacity-75" />
                                <span className="relative inline-flex size-2 rounded-full bg-volt" />
                            </span>
                            {t('hero', 'badge')}
                        </span>
                        <h1 className="mt-6 font-display text-[2.6rem] leading-[1.04] font-extrabold tracking-tight sm:text-6xl lg:text-7xl">
                            {t('hero', 'title_line1')}
                            <br />
                            <span className="bg-gradient-to-r from-volt via-[#3be8ff] to-electric bg-clip-text text-transparent">{t('hero', 'title_line2')}</span>
                        </h1>
                        <p className="mt-6 max-w-xl text-lg leading-relaxed text-white/75">
                            {t('hero', 'text')}
                        </p>

                        <form onSubmit={search} className="mt-8 flex max-w-xl flex-col gap-2 rounded-2xl bg-white/10 p-2 ring-1 ring-white/15 backdrop-blur sm:flex-row">
                            <div className="relative flex-1">
                                <Search className="pointer-events-none absolute top-1/2 left-3.5 size-5 -translate-y-1/2 text-white/50" />
                                <input
                                    value={q}
                                    onChange={(e) => setQ(e.target.value)}
                                    placeholder={t('hero', 'search_placeholder')}
                                    className="h-12 w-full rounded-xl bg-transparent pr-3 pl-11 text-white placeholder:text-white/50 focus:outline-none"
                                    aria-label="Search stations"
                                />
                            </div>
                            <button type="submit" className="h-12 rounded-xl bg-white px-5 font-semibold text-ink transition hover:bg-white/90">
                                Search
                            </button>
                            <Link
                                href="/stations?near=1"
                                className="flex h-12 items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-volt to-electric px-5 font-semibold text-white shadow-lg shadow-volt/30 transition hover:brightness-110"
                            >
                                <LocateFixed className="size-5" /> Near me
                            </Link>
                        </form>

                        <div className="mt-6 flex flex-wrap gap-x-6 gap-y-2 text-sm text-white/70">
                            {list(site.hero, 'bullets').map((b) => (
                                <span key={b} className="flex items-center gap-1.5">
                                    <ShieldCheck className="size-4 text-volt" /> {fill(b, vars)}
                                </span>
                            ))}
                        </div>
                    </div>

                    {/* Hero visual: live charging card */}
                    <div className="rise-in relative mx-auto w-full max-w-md [animation-delay:150ms]">
                        <div className="absolute -inset-6 -z-10 rounded-[2.5rem] bg-gradient-to-br from-volt/40 via-transparent to-electric/40 blur-2xl" />
                        <div className="rounded-[2rem] bg-white/[0.08] p-6 ring-1 ring-white/15 backdrop-blur-xl">
                            <div className="flex items-center justify-between">
                                <div className="flex items-center gap-3">
                                    <img src="/brand/logo-mark.svg" alt="" className="size-11" />
                                    <div>
                                        <p className="text-xs text-white/60">Booking TEV-24918</p>
                                        <p className="font-semibold">Expressway Highway Hub</p>
                                    </div>
                                </div>
                                <span className="rounded-full bg-volt/20 px-3 py-1 text-xs font-semibold text-volt">● Charging</span>
                            </div>

                            <div className="mt-8 flex items-center justify-center">
                                <div className="relative flex size-44 items-center justify-center rounded-full bg-[conic-gradient(#00E5A8_0_76%,rgba(255,255,255,0.08)_76%_100%)] p-2">
                                    <div className="flex size-full flex-col items-center justify-center rounded-full bg-ink">
                                        <BatteryCharging className="size-7 text-volt" />
                                        <p className="mt-1 font-display text-4xl font-extrabold">76%</p>
                                        <p className="text-xs text-white/60">≈ 8 min to 80%</p>
                                    </div>
                                </div>
                            </div>

                            <div className="mt-8 grid grid-cols-3 gap-3 text-center">
                                {[
                                    ['142', 'kW now'],
                                    ['31.4', 'kWh added'],
                                    [inr(pricing.dcMax, 0), 'per kWh'],
                                ].map(([v, l]) => (
                                    <div key={l} className="rounded-xl bg-white/[0.06] p-3 ring-1 ring-white/10">
                                        <p className="font-display text-lg font-bold">{v}</p>
                                        <p className="text-[11px] text-white/60">{l}</p>
                                    </div>
                                ))}
                            </div>
                            <div className="mt-4 flex items-center justify-between rounded-xl bg-white/[0.06] px-4 py-3 text-sm ring-1 ring-white/10">
                                <span className="flex items-center gap-2 text-white/70"><Receipt className="size-4 text-volt" /> GST invoice ready</span>
                                <span className="font-semibold">Download</span>
                            </div>
                        </div>
                        <div className="absolute -bottom-6 -left-6 hidden rounded-2xl bg-white p-4 text-ink shadow-2xl sm:block">
                            <p className="text-xs text-slate-500">Nearest charger</p>
                            <p className="font-display font-bold">1.2 km · 4 min</p>
                        </div>
                    </div>
                </div>

                <div className="border-t border-white/10 bg-black/20 backdrop-blur">
                    <dl className="mx-auto grid max-w-7xl grid-cols-2 gap-6 px-4 py-8 sm:px-6 md:grid-cols-4 lg:px-8">
                        {[
                            [stats.stations, 'Charging stations', MapPinned],
                            [stats.chargers, 'Charge points', Plug],
                            [`${stats.maxKw} kW`, 'Fastest charger', Zap],
                            [inr(stats.fromRate, 0), 'From, per kWh', BadgeIndianRupee],
                        ].map(([v, l, Icon]) => {
                            const I = Icon as typeof Zap;
                            return (
                                <div key={l as string} className="flex items-center gap-4">
                                    <div className="flex size-12 items-center justify-center rounded-xl bg-white/10 ring-1 ring-white/10">
                                        <I className="size-6 text-volt" />
                                    </div>
                                    <div>
                                        <dd className="font-display text-2xl font-bold sm:text-3xl">{v as string | number}</dd>
                                        <dt className="text-sm text-white/60">{l as string}</dt>
                                    </div>
                                </div>
                            );
                        })}
                    </dl>
                </div>
            </section>

            {/* ───────── Compatible vehicles strip ───────── */}
            <section className="border-b border-border bg-muted/50 py-6">
                <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-center gap-x-8 gap-y-3 px-4 text-sm text-muted-foreground sm:px-6 lg:px-8">
                    <span className="flex items-center gap-2 font-semibold text-foreground">
                        <Car className="size-4 text-volt-600" /> {t('vehicles', 'lead')}
                    </span>
                    {list(site.vehicles, 'items').map((v) => (
                        <span key={v} className="font-medium">{v}</span>
                    ))}
                </div>
            </section>

            {/* ───────── How it works ───────── */}
            <section className="mx-auto max-w-7xl px-4 py-24 sm:px-6 lg:px-8">
                <SectionHeading eyebrow={t('steps', 'eyebrow')} title={t('steps', 'title')} text={t('steps', 'text')} />
                <div className="relative mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="absolute top-12 right-[12%] left-[12%] hidden h-px bg-gradient-to-r from-volt/0 via-volt/50 to-electric/0 lg:block" />
                    {items(site.steps).map((s, i) => {
                        const StepIcon = icon(s.icon);
                        return (
                        <div key={`${s.title}-${i}`} className="relative rounded-3xl border border-border bg-card p-7 transition duration-300 hover:-translate-y-1 hover:border-volt/40 hover:shadow-2xl hover:shadow-volt/10">
                            <div className="flex items-center justify-between">
                                <div className="flex size-14 items-center justify-center rounded-2xl bg-gradient-to-br from-volt to-electric text-white shadow-lg shadow-volt/25">
                                    <StepIcon className="size-7" />
                                </div>
                                <span className="font-display text-5xl font-extrabold text-foreground/10">0{i + 1}</span>
                            </div>
                            <h3 className="mt-6 font-display text-xl font-semibold text-foreground">{fill(s.title, vars)}</h3>
                            <p className="mt-2 text-muted-foreground">{fill(s.text, vars)}</p>
                        </div>
                        );
                    })}
                </div>
            </section>

            {/* ───────── Charger types & pricing ───────── */}
            <section className="bg-muted/50 py-24">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <SectionHeading eyebrow={t('chargers', 'eyebrow')} title={t('chargers', 'title')} text={t('chargers', 'text')} />
                    <div className="mt-12 grid gap-5 md:grid-cols-2 xl:grid-cols-4">
                        {items(site.chargers).map((c, i) => {
                            const tone = c.kind === 'dc' ? 'volt' : 'electric';
                            const rate = pricing[(c.rate as keyof Props['pricing']) ?? 'acMin'] ?? 0;
                            return (
                            <div key={`${c.name}-${i}`} className="group relative overflow-hidden rounded-3xl border border-border bg-card p-6 transition hover:-translate-y-1 hover:shadow-2xl">
                                <div className={cn('absolute inset-x-0 top-0 h-1', tone === 'volt' ? 'bg-gradient-to-r from-volt to-electric' : 'bg-gradient-to-r from-electric to-[#7c3aed]')} />
                                <div className="flex items-center justify-between">
                                    <span className={cn('rounded-full px-3 py-1 text-xs font-bold', tone === 'volt' ? 'bg-volt/15 text-volt-600' : 'bg-electric/10 text-electric')}>
                                        {tone === 'volt' ? 'DC' : 'AC'}
                                    </span>
                                    <Zap className={cn('size-5', tone === 'volt' ? 'text-volt-600' : 'text-electric')} />
                                </div>
                                <h3 className="mt-4 font-display text-xl font-bold text-foreground">{c.name}</h3>
                                <p className="text-sm text-muted-foreground">{c.power}</p>
                                <p className="mt-5">
                                    <span className="font-display text-4xl font-extrabold text-foreground">{inr(rate, 0)}</span>
                                    <span className="text-muted-foreground"> /kWh</span>
                                </p>
                                <p className="text-xs text-muted-foreground">+ GST · from</p>
                                <ul className="mt-5 space-y-2.5 border-t border-border pt-5 text-sm">
                                    <li className="flex gap-2 text-foreground/80"><Plug className="mt-0.5 size-4 shrink-0 text-muted-foreground" />{c.connector}</li>
                                    <li className="flex gap-2 text-foreground/80"><Clock className="mt-0.5 size-4 shrink-0 text-muted-foreground" />{c.time}</li>
                                    <li className="flex gap-2 text-foreground/80"><Car className="mt-0.5 size-4 shrink-0 text-muted-foreground" />{c.best}</li>
                                </ul>
                            </div>
                            );
                        })}
                    </div>
                </div>
            </section>

            {/* ───────── Calculator ───────── */}
            <section className="mx-auto max-w-7xl px-4 py-24 sm:px-6 lg:px-8">
                <div className="grid items-center gap-12 lg:grid-cols-[0.8fr_1.2fr]">
                    <div>
                        <SectionHeading eyebrow={t('calculator', 'eyebrow')} title={t('calculator', 'title')} text={t('calculator', 'text')} />
                        <ul className="mt-8 space-y-4">
                            {items(site.calculator).map((p, i) => {
                                const I = icon(p.icon);
                                return (
                                    <li key={`${p.text}-${i}`} className="flex gap-3 text-foreground/80">
                                        <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-volt/10"><I className="size-5 text-volt-600" /></span>
                                        <span className="pt-1.5">{fill(p.text, vars)}</span>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                    <CostCalculator pricing={pricing} note={t('calculator', 'note')} />
                </div>
            </section>

            {/* ───────── Featured stations ───────── */}
            {featured.length > 0 && (
                <section className="bg-muted/50 py-24">
                    <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                        <div className="flex flex-wrap items-end justify-between gap-4">
                            <SectionHeading eyebrow={t('featured', 'eyebrow')} title={t('featured', 'title')} />
                            <Link href="/stations" className="flex items-center gap-1.5 rounded-full border border-border bg-card px-5 py-2.5 font-semibold text-foreground transition hover:border-volt">
                                {t('featured', 'link_label')} <ArrowRight className="size-4" />
                            </Link>
                        </div>
                        <div className="mt-12 grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                            {featured.map((s) => (
                                <StationCardView key={s.slug} station={s} />
                            ))}
                        </div>
                    </div>
                </section>
            )}

            {/* ───────── Cities ───────── */}
            <section className="mx-auto max-w-7xl px-4 py-24 sm:px-6 lg:px-8">
                <SectionHeading eyebrow={t('network', 'eyebrow')} title={t('network', 'title')} text={t('network', 'text')} />
                <div id="network-cities" className="mt-12 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                    {(showAllCities ? cities : cities.slice(0, CITY_PREVIEW)).map((c) => (
                        <Link
                            key={`${c.city}-${c.state}`}
                            href={`/stations?q=${encodeURIComponent(c.city)}`}
                            className="group relative overflow-hidden rounded-2xl border border-border bg-card p-5 transition hover:-translate-y-1 hover:border-volt/50 hover:shadow-xl"
                        >
                            <Building2 className="size-6 text-muted-foreground transition group-hover:text-volt-600" />
                            <p className="mt-4 font-display text-lg font-semibold text-foreground">{c.city}</p>
                            <p className="text-sm text-muted-foreground">{c.state}</p>
                            <p className="mt-3 text-sm font-medium text-foreground">
                                {c.stations} station{c.stations > 1 ? 's' : ''}
                                <span className="ml-2 text-xs text-volt-600">● {c.online} live</span>
                            </p>
                            <ArrowRight className="absolute top-5 right-5 size-4 text-muted-foreground opacity-0 transition group-hover:opacity-100" />
                        </Link>
                    ))}
                </div>
                {cities.length > CITY_PREVIEW && (
                    <div className="mt-10 flex justify-center">
                        <button
                            type="button"
                            onClick={() => setShowAllCities((v) => !v)}
                            aria-expanded={showAllCities}
                            aria-controls="network-cities"
                            className="inline-flex items-center gap-2 rounded-full border border-border bg-card px-6 py-3 text-sm font-semibold text-foreground shadow-sm transition hover:border-volt/50 hover:shadow-md"
                        >
                            {showAllCities ? 'Show fewer cities' : `View all ${cities.length} cities`}
                            <ChevronDown className={cn('size-4 transition-transform', showAllCities && 'rotate-180')} />
                        </button>
                    </div>
                )}
            </section>

            {/* ───────── Features ───────── */}
            <section className="relative overflow-hidden bg-ink py-24 text-white">
                <div className="pointer-events-none absolute -top-40 left-1/3 size-[32rem] rounded-full bg-volt/15 blur-[120px]" />
                <div className="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="max-w-2xl">
                        <p className="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold tracking-wide text-volt uppercase ring-1 ring-white/15">
                            <Sparkles className="size-3.5" /> {t('features', 'eyebrow')}
                        </p>
                        <h2 className="mt-4 font-display text-3xl font-bold tracking-tight sm:text-4xl">{t('features', 'title')}</h2>
                        <p className="mt-3 text-lg text-white/70">{t('features', 'text')}</p>
                    </div>
                    <div className="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        {items(site.features).map((f, i) => {
                            const FIcon = icon(f.icon);
                            return (
                            <div key={`${f.title}-${i}`} className="rounded-3xl bg-white/[0.05] p-7 ring-1 ring-white/10 transition hover:bg-white/[0.08]">
                                <div className="flex size-12 items-center justify-center rounded-2xl bg-gradient-to-br from-volt/30 to-electric/30 ring-1 ring-white/15">
                                    <FIcon className="size-6 text-volt" />
                                </div>
                                <h3 className="mt-5 font-display text-lg font-semibold">{fill(f.title, vars)}</h3>
                                <p className="mt-2 text-white/70">{fill(f.text, vars)}</p>
                            </div>
                            );
                        })}
                    </div>
                </div>
            </section>

            {/* ───────── Business / fleet ───────── */}
            <section className="mx-auto max-w-7xl px-4 py-24 sm:px-6 lg:px-8">
                <div className="grid gap-6 lg:grid-cols-2">
                    {items(site.business).map((b, i) => {
                        const BIcon = icon(b.icon);
                        const blue = b.tone === 'electric';
                        return (
                            <div key={`${b.title}-${i}`} className="rounded-3xl border border-border bg-card p-8 sm:p-10">
                                <BIcon className={cn('size-9', blue ? 'text-electric' : 'text-volt-600')} />
                                <h3 className="mt-5 font-display text-2xl font-bold text-foreground">{fill(b.title, vars)}</h3>
                                <p className="mt-3 text-muted-foreground">{fill(b.text, vars)}</p>
                                {b.link_url && (
                                    <Link href={b.link_url} className={cn('mt-6 inline-flex items-center gap-2 font-semibold hover:underline', blue ? 'text-electric' : 'text-volt-600')}>
                                        {b.link_label} <ArrowRight className="size-4" />
                                    </Link>
                                )}
                            </div>
                        );
                    })}
                </div>
            </section>

            {/* ───────── FAQ ───────── */}
            <section className="bg-muted/50 py-24">
                <div className="mx-auto max-w-3xl px-4 sm:px-6">
                    <SectionHeading eyebrow={t('faq', 'eyebrow')} title={t('faq', 'title')} center />
                    <div className="mt-10 space-y-3">
                        {items(site.faq).map((f, i) => (
                            <div key={`${f.q}-${i}`} className={cn('rounded-2xl border bg-card transition', openFaq === i ? 'border-volt/40 shadow-lg shadow-volt/5' : 'border-border')}>
                                <button
                                    onClick={() => setOpenFaq(openFaq === i ? null : i)}
                                    className="flex w-full items-center justify-between gap-4 px-6 py-5 text-left font-semibold text-foreground"
                                    aria-expanded={openFaq === i}
                                >
                                    {fill(f.q, vars)}
                                    <ChevronDown className={cn('size-5 shrink-0 text-muted-foreground transition', openFaq === i && 'rotate-180 text-volt-600')} />
                                </button>
                                {openFaq === i && <p className="px-6 pb-5 leading-relaxed text-muted-foreground">{fill(f.a, vars)}</p>}
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            {/* ───────── CTA ───────── */}
            <section className="px-4 py-24 sm:px-6 lg:px-8">
                <div className="relative mx-auto max-w-7xl overflow-hidden rounded-[2.5rem] bg-gradient-to-br from-volt-600 via-[#0aa5a0] to-electric px-8 py-16 text-white sm:px-16">
                    <div className="absolute -top-24 -right-24 size-80 rounded-full bg-white/15 blur-3xl" />
                    <div className="absolute -bottom-32 left-1/3 size-80 rounded-full bg-ink/30 blur-3xl" />
                    <div className="relative grid items-center gap-8 lg:grid-cols-[1fr_auto]">
                        <div>
                            <h2 className="font-display text-3xl font-bold sm:text-5xl">{t('cta', 'title')}</h2>
                            <p className="mt-4 max-w-xl text-lg text-white/90">{t('cta', 'text')}</p>
                        </div>
                        <div className="flex flex-wrap gap-3">
                            <Link href="/stations?near=1" className="inline-flex items-center gap-2 rounded-full bg-white px-7 py-4 font-semibold text-ink shadow-xl transition hover:-translate-y-0.5">
                                <LocateFixed className="size-5" /> {t('cta', 'primary_label')}
                            </Link>
                            <Link href={route('register')} className="inline-flex items-center gap-2 rounded-full bg-ink/30 px-7 py-4 font-semibold text-white ring-1 ring-white/30 backdrop-blur transition hover:bg-ink/40">
                                {t('cta', 'secondary_label')}
                            </Link>
                        </div>
                    </div>
                </div>
            </section>
        </SiteLayout>
    );
}
