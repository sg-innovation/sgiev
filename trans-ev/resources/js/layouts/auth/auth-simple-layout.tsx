import { Link } from '@inertiajs/react';
import { ArrowLeft, BatteryCharging, CalendarCheck, MapPinned, Receipt, ShieldCheck } from 'lucide-react';

interface AuthLayoutProps {
    children: React.ReactNode;
    name?: string;
    title?: string;
    description?: string;
}

const BENEFITS = [
    { icon: MapPinned, title: 'Nearest charger, live', text: 'GPS map with real-time charger status' },
    { icon: CalendarCheck, title: 'Book in seconds', text: 'Guaranteed slots, no waiting in queues' },
    { icon: Receipt, title: 'GST invoice every time', text: 'Receipts and tax invoices in your account' },
];

/** Split-screen auth layout: brand panel on the left, form card on the right. */
export default function AuthSimpleLayout({ children, title, description }: AuthLayoutProps) {
    return (
        <div className="grid min-h-svh bg-background lg:grid-cols-[1.05fr_1fr]">
            {/* Brand panel */}
            <aside className="relative hidden overflow-hidden bg-ink p-12 text-white lg:flex lg:flex-col">
                <div className="pointer-events-none absolute inset-0">
                    <div className="absolute -top-40 -left-24 size-[34rem] rounded-full bg-volt/25 blur-[120px]" />
                    <div className="absolute -right-32 -bottom-40 size-[34rem] rounded-full bg-electric/30 blur-[120px]" />
                    <div className="absolute inset-0 bg-[linear-gradient(to_right,rgba(255,255,255,0.05)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,0.05)_1px,transparent_1px)] bg-[size:48px_48px] [mask-image:radial-gradient(ellipse_at_center,black_20%,transparent_75%)]" />
                </div>

                <div className="relative flex items-center justify-between">
                    <Link href={route('home')}>
                        <img src="/brand/logo-dark.svg" alt="Trans EV" className="h-11" />
                    </Link>
                    <Link href={route('home')} className="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-4 py-2 text-sm font-medium text-white/80 ring-1 ring-white/15 transition hover:bg-white/15 hover:text-white">
                        <ArrowLeft className="size-4" /> Back to website
                    </Link>
                </div>

                <div className="relative my-auto max-w-md py-12">
                    <h2 className="font-display text-4xl leading-tight font-extrabold">
                        Power up your <span className="bg-gradient-to-r from-volt to-electric bg-clip-text text-transparent">electric journey</span>.
                    </h2>
                    <p className="mt-4 text-lg text-white/70">One account to find chargers, book slots, pay online and keep every invoice in one place.</p>

                    <ul className="mt-10 space-y-5">
                        {BENEFITS.map((b) => (
                            <li key={b.title} className="flex gap-4">
                                <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-white/10 ring-1 ring-white/15">
                                    <b.icon className="size-5 text-volt" />
                                </span>
                                <span>
                                    <span className="block font-semibold">{b.title}</span>
                                    <span className="text-sm text-white/60">{b.text}</span>
                                </span>
                            </li>
                        ))}
                    </ul>

                    <div className="mt-12 flex items-center gap-4 rounded-2xl bg-white/[0.06] p-4 ring-1 ring-white/10 backdrop-blur">
                        <div className="flex size-12 items-center justify-center rounded-full bg-[conic-gradient(#00E5A8_0_80%,rgba(255,255,255,0.1)_80%_100%)] p-1">
                            <div className="flex size-full items-center justify-center rounded-full bg-ink">
                                <BatteryCharging className="size-5 text-volt" />
                            </div>
                        </div>
                        <div className="text-sm">
                            <p className="font-semibold">Ultra-fast DC up to 180 kW</p>
                            <p className="text-white/60">20% → 80% in about 30 minutes on most EVs</p>
                        </div>
                    </div>
                </div>

                <p className="relative flex items-center gap-2 text-xs text-white/50">
                    <ShieldCheck className="size-4" /> Secure sign-in · Your data is protected under India’s DPDP Act
                </p>
            </aside>

            {/* Form panel */}
            <main className="relative flex items-center justify-center px-4 py-10 sm:px-8">
                <div className="pointer-events-none absolute inset-x-0 top-0 h-72 bg-gradient-to-b from-volt/10 to-transparent lg:hidden" />
                <div className="relative w-full max-w-md">
                    <div className="mb-8 flex items-center justify-between lg:hidden">
                        <Link href={route('home')}>
                            <img src="/brand/logo.svg" alt="Trans EV" className="h-10 dark:hidden" />
                            <img src="/brand/logo-dark.svg" alt="Trans EV" className="hidden h-10 dark:block" />
                        </Link>
                        <Link href={route('home')} className="inline-flex items-center gap-1.5 text-sm font-medium text-muted-foreground hover:text-foreground">
                            <ArrowLeft className="size-4" /> Back to website
                        </Link>
                    </div>

                    <div className="rise-in rounded-3xl border border-border bg-card p-7 shadow-2xl shadow-black/5 sm:p-9">
                        <div className="mb-7">
                            <h1 className="font-display text-2xl font-bold text-foreground">{title}</h1>
                            {description && <p className="mt-1.5 text-sm text-muted-foreground">{description}</p>}
                        </div>
                        {children}
                    </div>

                    <Link href={route('home')} className="mx-auto mt-6 hidden w-fit items-center gap-1.5 text-sm font-medium text-muted-foreground hover:text-foreground lg:flex">
                        <ArrowLeft className="size-4" /> Back to website
                    </Link>
                    <p className="mt-4 text-center text-xs text-muted-foreground">
                        By continuing you agree to our{' '}
                        <Link href="/terms" className="font-medium text-foreground underline-offset-4 hover:underline">Terms</Link> and{' '}
                        <Link href="/privacy" className="font-medium text-foreground underline-offset-4 hover:underline">Privacy Policy</Link>.
                    </p>
                </div>
            </main>
        </div>
    );
}
