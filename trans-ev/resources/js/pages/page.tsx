import { inr } from '@/components/site/station-bits';
import SiteLayout from '@/layouts/site-layout';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { type Option } from '@/types/station';
import ContactForm from '@/components/site/contact-form';
import { fill, icon, items, list, str, type SiteSections } from '@/lib/site-content';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    BadgeIndianRupee,
    Clock,
    FileText,
    Info,
    Lock,
    Mail,
    MapPin,
    MessageCircle,
    Phone,
    RotateCcw,
    Scale,
    ShieldCheck,
    Zap,
} from 'lucide-react';
import { type ReactNode } from 'react';

type PageKey = 'about' | 'contact' | 'pricing' | 'terms' | 'privacy' | 'refund-policy' | 'safety' | 'custom';

/** A page edited in Admin → Content → Website pages (HTML sanitised on the server). */
interface Live {
    stats: { stations: number; online: number; cities: number; chargers: number; maxKw: number; fromRate: number };
    pricing: { acMin: number; acMax: number; dcMin: number; dcMax: number; sessionFee: number; gstRate: number; idleFee: number; idleGrace: number };
}
interface Ctx {
    brand: SharedData['brand'];
    topics: Option[];
    site: SiteSections;
    live: Live | null;
}

interface DbContent {
    title: string;
    intro: string | null;
    version: number;
    updated: string | null;
    lead: string;
    sections: { id: string; title: string; html: string }[];
}

const NAV: { key: PageKey; label: string; icon: typeof Info }[] = [
    { key: 'about', label: 'About us', icon: Info },
    { key: 'pricing', label: 'Pricing', icon: BadgeIndianRupee },
    { key: 'safety', label: 'Charging safety', icon: ShieldCheck },
    { key: 'contact', label: 'Contact', icon: Mail },
    { key: 'terms', label: 'Terms of service', icon: Scale },
    { key: 'privacy', label: 'Privacy policy', icon: Lock },
    { key: 'refund-policy', label: 'Refund & cancellation', icon: RotateCcw },
];

function Card({ icon: Icon, title, children, tone = 'volt' }: { icon: typeof Info; title: string; children: ReactNode; tone?: string }) {
    const tones: Record<string, string> = {
        volt: 'bg-volt/10 text-volt-600',
        electric: 'bg-electric/10 text-electric',
        amber: 'bg-amber-500/10 text-amber-600 dark:text-amber-300',
        red: 'bg-red-500/10 text-red-600 dark:text-red-300',
    };
    return (
        <div className="rounded-2xl border border-border bg-card p-6">
            <div className={cn('flex size-11 items-center justify-center rounded-xl', tones[tone] ?? tones.volt)}>
                <Icon className="size-5" />
            </div>
            <h3 className="mt-4 font-display text-lg font-semibold text-foreground">{title}</h3>
            <div className="mt-2 text-muted-foreground">{children}</div>
        </div>
    );
}

const PAGES: Record<Exclude<PageKey, 'custom'>, { icon: typeof Info; body: (ctx: Ctx) => ReactNode }> = {
    about: {
        icon: Zap,
        body: ({ site }) => (
            <>
                <div className="grid gap-5 md:grid-cols-3">
                    {items(site.values).map((c, i) => (
                        <Card key={i} icon={icon(c.icon)} title={c.title} tone={c.tone}>
                            {c.text}
                        </Card>
                    ))}
                </div>
                <div className="mt-10 grid items-center gap-8 rounded-3xl border border-border bg-card p-8 md:grid-cols-2">
                    <div>
                        <h2 className="font-display text-2xl font-bold text-foreground">{str(site.what, 'title')}</h2>
                        <ul className="mt-4 space-y-3 text-muted-foreground">
                            {list(site.what, 'points').map((t) => (
                                <li key={t} className="flex gap-3"><Zap className="mt-1 size-4 shrink-0 text-volt-600" />{t}</li>
                            ))}
                        </ul>
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        {items(site.what).map((c, i) => {
                            const Icon = icon(c.icon);
                            return (
                                <div key={i} className="rounded-2xl bg-muted p-5">
                                    <Icon className="size-6 text-volt-600" />
                                    <p className="mt-3 font-semibold text-foreground">{c.title}</p>
                                    <p className="text-sm text-muted-foreground">{c.text}</p>
                                </div>
                            );
                        })}
                    </div>
                </div>
            </>
        ),
    },
    contact: {
        icon: Mail,
        body: ({ brand: b, topics, site }) => (
            <div className="grid gap-8 lg:grid-cols-[1fr_360px]">
                <ContactForm topics={topics} />
                <aside className="space-y-4">
                    <a href={`tel:${(b.phone ?? '').replace(/[^+\d]/g, '')}`} className="group flex gap-4 rounded-2xl border border-border bg-card p-5 transition hover:border-volt/50">
                        <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-volt/10"><Phone className="size-5 text-volt-600" /></span>
                        <span>
                            <span className="block text-sm text-muted-foreground">{str(site.details, 'phone_label')}</span>
                            <span className="block font-semibold text-foreground">{b.phone}</span>
                        </span>
                    </a>
                    {b.whatsapp && (
                        <a href={b.whatsapp} target="_blank" rel="noopener noreferrer" className="group flex gap-4 rounded-2xl border border-border bg-card p-5 transition hover:border-emerald-500/50">
                            <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-emerald-500/10"><MessageCircle className="size-5 text-emerald-600 dark:text-emerald-400" /></span>
                            <span>
                                <span className="block text-sm text-muted-foreground">{str(site.details, 'whatsapp_label')}</span>
                                <span className="block font-semibold text-foreground">{b.phone}</span>
                            </span>
                        </a>
                    )}
                    <a href={`mailto:${b.email}`} className="group flex gap-4 rounded-2xl border border-border bg-card p-5 transition hover:border-electric/50">
                        <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-electric/10"><Mail className="size-5 text-electric" /></span>
                        <span>
                            <span className="block text-sm text-muted-foreground">{str(site.details, 'email_label')}</span>
                            <span className="block font-semibold break-all text-foreground">{b.email}</span>
                        </span>
                    </a>
                    <div className="flex gap-4 rounded-2xl border border-border bg-card p-5">
                        <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-amber-500/10"><MapPin className="size-5 text-amber-600 dark:text-amber-300" /></span>
                        <span>
                            <span className="block text-sm text-muted-foreground">{str(site.details, 'office_label')}</span>
                            <span className="block font-semibold text-foreground">{b.legalName}</span>
                            <span className="block text-sm text-muted-foreground">{b.address}</span>
                            {b.gstin && <span className="mt-1 block text-xs text-muted-foreground">GSTIN {b.gstin}</span>}
                        </span>
                    </div>
                    {str(site.details, 'hours') && (
                        <p className="flex items-start gap-2 px-1 text-sm text-muted-foreground">
                            <Clock className="mt-0.5 size-4 shrink-0" /> {str(site.details, 'hours')}
                        </p>
                    )}
                </aside>
            </div>
        ),
    },
    pricing: {
        icon: BadgeIndianRupee,
        body: ({ site, live }) => {
            const p = live?.pricing;
            const vars: Record<string, number> = p ? { session_fee: p.sessionFee, gst_rate: p.gstRate, idle_fee: p.idleFee, idle_grace: p.idleGrace } : {};
            const tiers = p
                ? [
                      ['AC from', p.acMin, 'electric'],
                      ['AC up to', p.acMax, 'electric'],
                      ['DC from', p.dcMin, 'volt'],
                      ['DC up to', p.dcMax, 'volt'],
                  ]
                : [];
            return (
                <>
                    {tiers.length > 0 && (
                        <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
                            {tiers.map(([n, r, t]) => (
                                <div key={n as string} className="relative overflow-hidden rounded-2xl border border-border bg-card p-6">
                                    <div className={cn('absolute inset-x-0 top-0 h-1', t === 'volt' ? 'bg-gradient-to-r from-volt to-electric' : 'bg-gradient-to-r from-electric to-violet-500')} />
                                    <p className="font-display text-lg font-semibold text-foreground">{n as string}</p>
                                    <p className="mt-5 font-display text-4xl font-extrabold text-foreground">{inr(r as number, 0)}<span className="text-base font-normal text-muted-foreground">/kWh</span></p>
                                    <p className="text-xs text-muted-foreground">current network rate, excl. GST</p>
                                </div>
                            ))}
                        </div>
                    )}
                    <div className="mt-8 overflow-hidden rounded-2xl border border-border bg-card">
                        <table className="w-full text-sm">
                            <tbody className="divide-y divide-border">
                                {items(site.facts).map((f, i) => (
                                    <tr key={i}>
                                        <th className="w-48 bg-muted/50 px-5 py-4 text-left font-semibold text-foreground">{f.label}</th>
                                        <td className="px-5 py-4 text-muted-foreground">{fill(f.text, vars)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {str(site.facts, 'note') && <p className="mt-4 text-sm text-muted-foreground">{str(site.facts, 'note')}</p>}
                    <Link href="/stations" className="mt-6 inline-flex items-center gap-2 rounded-full bg-gradient-to-r from-volt to-electric px-6 py-3 font-semibold text-white shadow-lg shadow-volt/25">
                        {str(site.facts, 'button_label') || 'See station prices'} <ArrowRight className="size-4" />
                    </Link>
                </>
            );
        },
    },
    safety: {
        icon: ShieldCheck,
        body: ({ site }) => (
            <div className="grid gap-5 md:grid-cols-2">
                {items(site.tips).map((c, i) => (
                    <Card key={i} icon={icon(c.icon)} title={c.title} tone={c.tone}>
                        {c.text}
                    </Card>
                ))}
            </div>
        ),
    },
    terms: { icon: Scale, body: () => null },
    privacy: { icon: Lock, body: () => null },
    'refund-policy': { icon: RotateCcw, body: () => null },
};

function DbDoc({ content }: { content: DbContent }) {
    return (
        <div className="grid gap-10 lg:grid-cols-[260px_1fr]">
            {content.sections.length > 1 && (
                <nav className="hidden lg:block" aria-label="On this page">
                    <div className="sticky top-36 rounded-2xl border border-border bg-card p-4">
                        <p className="mb-2 px-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">On this page</p>
                        <ol className="max-h-[60vh] space-y-0.5 overflow-y-auto text-sm">
                            {content.sections.map((s) => (
                                <li key={s.id}>
                                    <a href={`#${s.id}`} className="block rounded-lg px-2 py-1.5 text-muted-foreground transition hover:bg-muted hover:text-foreground">
                                        {s.title}
                                    </a>
                                </li>
                            ))}
                        </ol>
                    </div>
                </nav>
            )}
            <div className={cn('min-w-0 rounded-3xl border border-border bg-card p-6 sm:p-10', content.sections.length <= 1 && 'lg:col-span-2')}>
                {content.updated && (
                    <p className="mb-8 inline-flex rounded-full bg-muted px-3 py-1 text-xs font-medium text-muted-foreground">
                        Last updated: {content.updated} · version {content.version}
                    </p>
                )}
                <div className="legal-prose space-y-10">
                    {/* Server-sanitised HTML from the page editor. */}
                    {content.lead && <div dangerouslySetInnerHTML={{ __html: content.lead }} />}
                    {content.sections.map((s) => (
                        <section key={s.id} id={s.id} className="scroll-mt-36">
                            <h2 className="font-display text-xl font-bold text-foreground">{s.title}</h2>
                            <div className="mt-3" dangerouslySetInnerHTML={{ __html: s.html }} />
                        </section>
                    ))}
                </div>
            </div>
        </div>
    );
}

export default function StaticPage({ page, topics = [], content = null, site = {}, live = null }: { page: PageKey; topics?: Option[]; content?: DbContent | null; site?: SiteSections; live?: Live | null }) {
    const { brand } = usePage<SharedData>().props;
    const p = PAGES[page as Exclude<PageKey, 'custom'>] ?? { icon: FileText, body: () => null };
    // Headers come from the database page (legal) or the site content block (other pages).
    const title = content?.title ?? str(site.header, 'title');
    const intro = content ? content.intro : str(site.header, 'intro');

    return (
        <SiteLayout>
            <Head title={title || NAV.find((n) => n.key === page)?.label || 'Trans EV'} />
            <section className="relative overflow-hidden bg-ink text-white">
                <div className="pointer-events-none absolute -top-32 -right-24 size-96 rounded-full bg-volt/25 blur-[100px]" />
                <div className="pointer-events-none absolute -bottom-40 left-10 size-96 rounded-full bg-electric/25 blur-[100px]" />
                <div className="relative mx-auto max-w-7xl px-4 pt-14 pb-12 sm:px-6 lg:px-8 lg:pt-20">
                    <div className="flex size-14 items-center justify-center rounded-2xl bg-gradient-to-br from-volt to-electric shadow-lg shadow-volt/30">
                        <p.icon className="size-7 text-white" />
                    </div>
                    <h1 className="mt-6 max-w-3xl font-display text-4xl font-extrabold tracking-tight sm:text-5xl">{title}</h1>
                    {intro && <p className="mt-4 max-w-2xl text-lg text-white/70">{intro}</p>}
                </div>
            </section>

            {/* Section tabs */}
            <div className="sticky top-16 z-30 border-b border-border bg-background/85 backdrop-blur-xl">
                <nav className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8" aria-label="Information pages">
                    <ul className="-mb-px flex gap-1 overflow-x-auto [scrollbar-width:none]">
                        {NAV.map((n) => (
                            <li key={n.key} className="shrink-0">
                                <Link
                                    href={`/${n.key}`}
                                    preserveScroll={false}
                                    className={cn(
                                        'flex items-center gap-2 border-b-2 px-3 py-4 text-sm font-medium whitespace-nowrap transition',
                                        n.key === page ? 'border-volt text-foreground' : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground',
                                    )}
                                >
                                    <n.icon className={cn('size-4', n.key === page && 'text-volt-600')} /> {n.label}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </nav>
            </div>

            <article className="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8 lg:py-16">
                {content ? (
                    <DbDoc content={content} />
                ) : ['terms', 'privacy', 'refund-policy'].includes(page) ? (
                    <p className="rounded-2xl bg-muted/60 p-8 text-center text-muted-foreground">This page is being updated. Please check back shortly.</p>
                ) : (
                    p.body({ brand, topics, site, live })
                )}
            </article>
        </SiteLayout>
    );
}
