import InputError from '@/components/input-error';
import SiteLayout from '@/layouts/site-layout';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { BadgeIndianRupee, CheckCircle2, ChartNoAxesCombined, Handshake, LoaderCircle, MapPinned, Network, Send, UsersRound } from 'lucide-react';
import { type FormEvent, type ReactNode } from 'react';

const inputCls = 'h-11 w-full rounded-xl border border-input bg-background px-3.5 text-sm text-foreground outline-none transition focus:border-volt focus:ring-4 focus:ring-volt/15';

function Field({ label, error, children, className, hint }: { label: string; error?: string; children: ReactNode; className?: string; hint?: string }) {
    return (
        <label className={cn('grid gap-1.5', className)}>
            <span className="text-sm font-medium text-foreground">{label}</span>
            {children}
            {hint && <span className="text-xs text-muted-foreground">{hint}</span>}
            <InputError message={error} />
        </label>
    );
}

/** G3: "Become a franchise partner" – what a Trans EV franchise is, and the application form. */
export default function Franchise({ states }: { states: string[] }) {
    const { flash } = usePage<SharedData & { flash?: { success?: string } }>().props;
    const form = useForm({ name: '', legal_name: '', contact_name: '', email: '', phone: '', city: '', state: '', region: '', gstin: '', message: '', consent: false as boolean, website: '' });
    const { data, setData, errors } = form;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/franchise', { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <SiteLayout>
            <Head title="Become a Trans EV franchise partner">
                <meta name="description" content="Run Trans EV charging in your region: manage a group of stations, earn a commission on every charge, with live reports and a dedicated regional dashboard." />
            </Head>

            <section className="relative overflow-hidden bg-ink text-white">
                <div className="pointer-events-none absolute -top-32 -right-24 size-[28rem] rounded-full bg-volt/25 blur-[110px]" />
                <div className="pointer-events-none absolute -bottom-40 left-10 size-[28rem] rounded-full bg-electric/25 blur-[110px]" />
                <div className="relative mx-auto max-w-7xl px-4 pt-28 pb-16 sm:px-6 lg:px-8 lg:pt-32 lg:pb-20">
                    <span className="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-volt ring-1 ring-white/15">
                        <Handshake className="size-3.5" /> Franchise partners
                    </span>
                    <h1 className="mt-5 max-w-3xl font-display text-4xl leading-tight font-extrabold tracking-tight sm:text-5xl">
                        Grow EV charging in your region. <span className="bg-gradient-to-r from-volt to-electric bg-clip-text text-transparent">Earn on every charge.</span>
                    </h1>
                    <p className="mt-4 max-w-2xl text-lg text-white/70">
                        Trans EV franchise partners bring stations onto the network in their territory, look after them with our tools, and earn a commission on the net sales of every station they manage.
                    </p>
                    <div className="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {[
                            [MapPinned, 'Your territory', 'A city or region where you sign up and support stations.'],
                            [BadgeIndianRupee, 'Monthly commission', 'An agreed share of net sales (excl. GST), paid every month with a statement.'],
                            [ChartNoAxesCombined, 'Live reports', 'Revenue, sessions, energy and uptime for your stations, with PDF and Excel downloads.'],
                            [UsersRound, 'Regional dashboard', 'Logins for your regional managers to follow bookings, reviews and maintenance.'],
                        ].map(([I, t, d]) => {
                            const Icon = I as typeof Network;
                            return (
                                <div key={t as string} className="rounded-2xl bg-white/[0.06] p-5 ring-1 ring-white/10">
                                    <Icon className="size-6 text-volt" />
                                    <p className="mt-3 font-semibold">{t as string}</p>
                                    <p className="mt-1 text-sm text-white/60">{d as string}</p>
                                </div>
                            );
                        })}
                    </div>
                </div>
            </section>

            <section className="mx-auto max-w-3xl px-4 py-12 sm:px-6">
                {flash?.success ? (
                    <div className="rounded-3xl border border-emerald-500/30 bg-emerald-500/10 p-8 text-center">
                        <CheckCircle2 className="mx-auto size-10 text-emerald-600" />
                        <p className="mt-3 text-lg font-semibold text-foreground">{flash.success}</p>
                    </div>
                ) : (
                    <form onSubmit={submit} className="space-y-6 rounded-3xl border border-border bg-card p-6 sm:p-8" noValidate>
                        <div>
                            <h2 className="font-display text-2xl font-bold text-foreground">Apply to become a franchise partner</h2>
                            <p className="mt-1 text-sm text-muted-foreground">Tell us about your business and the area you want to cover. Our partnerships team replies within a few working days.</p>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Business name" error={errors.name}><input className={inputCls} value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="Pune EV Partners" /></Field>
                            <Field label="Legal name (optional)" error={errors.legal_name}><input className={inputCls} value={data.legal_name} onChange={(e) => setData('legal_name', e.target.value)} /></Field>
                            <Field label="Your name" error={errors.contact_name}><input className={inputCls} value={data.contact_name} onChange={(e) => setData('contact_name', e.target.value)} autoComplete="name" /></Field>
                            <Field label="Email" error={errors.email}><input type="email" className={inputCls} value={data.email} onChange={(e) => setData('email', e.target.value)} autoComplete="email" /></Field>
                            <Field label="Mobile number" error={errors.phone}><input type="tel" inputMode="numeric" className={inputCls} value={data.phone} onChange={(e) => setData('phone', e.target.value)} placeholder="10-digit mobile" autoComplete="tel-national" /></Field>
                            <Field label="GSTIN (optional)" error={errors.gstin}><input className={cn(inputCls, 'uppercase')} value={data.gstin} onChange={(e) => setData('gstin', e.target.value)} maxLength={15} /></Field>
                            <Field label="City" error={errors.city}><input className={inputCls} value={data.city} onChange={(e) => setData('city', e.target.value)} autoComplete="address-level2" /></Field>
                            <Field label="State" error={errors.state}>
                                <select className={inputCls} value={data.state} onChange={(e) => setData('state', e.target.value)}>
                                    <option value="">Choose a state</option>
                                    {states.map((s) => <option key={s} value={s}>{s}</option>)}
                                </select>
                            </Field>
                            <Field label="Territory you want to cover" error={errors.region} className="sm:col-span-2" hint="For example: Pune city and PCMC, or the Mumbai–Pune expressway.">
                                <input className={inputCls} value={data.region} onChange={(e) => setData('region', e.target.value)} />
                            </Field>
                            <Field label="About you (optional)" error={errors.message} className="sm:col-span-2" hint="Experience, sites you have in mind, how many stations you plan in the first year.">
                                <textarea className={cn(inputCls, 'h-28 py-2.5')} value={data.message} onChange={(e) => setData('message', e.target.value)} maxLength={3000} />
                            </Field>
                        </div>
                        <input type="text" name="website" tabIndex={-1} autoComplete="off" className="hidden" value={data.website} onChange={(e) => setData('website', e.target.value)} aria-hidden="true" />
                        <label className="flex items-start gap-3 text-sm text-muted-foreground">
                            <input type="checkbox" className="mt-0.5 size-4" checked={data.consent} onChange={(e) => setData('consent', e.target.checked)} />
                            <span>Trans EV may contact me by email and phone about this application.</span>
                        </label>
                        <InputError message={errors.consent} />
                        <button type="submit" disabled={form.processing} className="inline-flex h-12 items-center gap-2 rounded-full bg-foreground px-6 text-sm font-semibold text-background disabled:opacity-50">
                            {form.processing ? <LoaderCircle className="size-4 animate-spin" /> : <Send className="size-4" />} Send application
                        </button>
                    </form>
                )}
            </section>
        </SiteLayout>
    );
}
