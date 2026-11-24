import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import PasswordStrength from '@/components/password-strength';
import SiteLayout from '@/layouts/site-layout';
import { cn } from '@/lib/utils';
import { type Option } from '@/types/station';
import { Head, Link, useForm } from '@inertiajs/react';
import { BadgeCheck, Banknote, Building2, Check, FileUp, LoaderCircle, Lock, MapPinned, Rocket, ShieldCheck, UserRound, Zap } from 'lucide-react';
import { type FormEvent, type ReactNode } from 'react';

interface Props {
    states: Option[];
    docTypes: { value: string; label: string; required: boolean }[];
    freeFeatures: string[];
}

const inputCls = 'h-11 w-full rounded-xl border border-input bg-background px-3.5 text-sm text-foreground outline-none transition focus:border-volt focus:ring-4 focus:ring-volt/15';

export default function PartnerRegister({ states, docTypes, freeFeatures }: Props) {
    const form = useForm({
        legal_name: '', trade_name: '', gstin: '', pan: '', address_line1: '', city: '', state_code: '10', pincode: '', expected_stations: '',
        name: '', email: '', phone: '', password: '', password_confirmation: '',
        account_name: '', account_number: '', ifsc: '', bank_name: '',
        documents: {} as Record<string, File | null>,
        terms: false as boolean,
        website_url: '',
    });
    const { data, setData, errors } = form;
    const err = errors as Record<string, string>;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/partner', { forceFormData: true, preserveScroll: true, onError: () => window.scrollTo({ top: 400, behavior: 'smooth' }) });
    };

    return (
        <SiteLayout>
            <Head title="Partner with Trans EV – list your charging station free">
                <meta name="description" content="Register your EV charging station on Trans EV. Free station management software, online bookings and payments straight to your account." />
            </Head>

            <section className="relative overflow-hidden bg-ink text-white">
                <div className="pointer-events-none absolute -top-32 -right-24 size-[28rem] rounded-full bg-volt/25 blur-[110px]" />
                <div className="pointer-events-none absolute -bottom-40 left-10 size-[28rem] rounded-full bg-electric/25 blur-[110px]" />
                <div className="relative mx-auto grid max-w-7xl gap-10 px-4 py-16 sm:px-6 lg:grid-cols-[1.1fr_1fr] lg:px-8 lg:py-20">
                    <div>
                        <span className="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-volt ring-1 ring-white/15">
                            <Zap className="size-3.5" /> For charging station owners
                        </span>
                        <h1 className="mt-5 font-display text-4xl leading-tight font-extrabold tracking-tight sm:text-5xl">
                            List your stations. <span className="bg-gradient-to-r from-volt to-electric bg-clip-text text-transparent">Get booked online.</span>
                        </h1>
                        <p className="mt-4 max-w-xl text-lg text-white/70">
                            Free management software for your chargers, online slot bookings, and customer payments straight into your own SabPaisa account.
                        </p>
                        <ol className="mt-8 grid gap-3 sm:grid-cols-3">
                            {[
                                [UserRound, 'Register', 'Business, bank and KYC details'],
                                [BadgeCheck, 'Get verified', 'We review within 2 working days'],
                                [Rocket, 'Go live', 'Add stations and start taking bookings'],
                            ].map(([I, t, d], i) => {
                                const Icon = I as typeof Zap;
                                return (
                                    <li key={t as string} className="rounded-2xl bg-white/[0.06] p-4 ring-1 ring-white/10">
                                        <div className="flex items-center gap-2 text-sm font-semibold"><span className="flex size-6 items-center justify-center rounded-full bg-volt text-xs text-ink">{i + 1}</span>{t as string}</div>
                                        <p className="mt-1.5 flex items-center gap-1.5 text-xs text-white/60"><Icon className="size-3.5" />{d as string}</p>
                                    </li>
                                );
                            })}
                        </ol>
                    </div>
                    <div className="rounded-3xl bg-white/[0.06] p-6 ring-1 ring-white/10 backdrop-blur">
                        <p className="text-sm font-semibold text-volt">Free plan includes</p>
                        <ul className="mt-4 grid gap-2.5">
                            {freeFeatures.map((f) => (
                                <li key={f} className="flex items-center gap-2.5 text-sm text-white/85"><Check className="size-4 shrink-0 text-volt" />{f}</li>
                            ))}
                        </ul>
                        <p className="mt-5 text-xs text-white/50">Premium features (advanced analytics, multi-branch, franchise, accounting sync) can be added later. Payment gateway, SMS and hardware costs are charged by those providers, and any Trans EV charge is always agreed with you first.</p>
                    </div>
                </div>
            </section>

            <form onSubmit={submit} className="mx-auto max-w-4xl space-y-6 px-4 py-12 sm:px-6" noValidate>
                {Object.keys(errors).length > 0 && (
                    <div className="rounded-2xl bg-red-500/10 p-4 text-sm font-medium text-red-800 ring-1 ring-red-500/30 dark:text-red-200">Please fix the highlighted fields below.</div>
                )}

                <Card icon={Building2} title="Business details" subtitle="As registered with GST / ROC. Shown on your customers' tax invoices.">
                    <Field label="Legal business name" error={err.legal_name} className="sm:col-span-2"><input className={inputCls} value={data.legal_name} onChange={(e) => setData('legal_name', e.target.value)} placeholder="ABC Charging Private Limited" /></Field>
                    <Field label="Brand name (shown to customers)" error={err.trade_name}><input className={inputCls} value={data.trade_name} onChange={(e) => setData('trade_name', e.target.value)} placeholder="ABC Charge" /></Field>
                    <Field label="Expected number of stations" error={err.expected_stations}><input type="number" min={1} className={inputCls} value={data.expected_stations} onChange={(e) => setData('expected_stations', e.target.value)} /></Field>
                    <Field label="GSTIN (optional)" error={err.gstin}><input className={cn(inputCls, 'uppercase')} value={data.gstin} onChange={(e) => setData('gstin', e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 15))} placeholder="10ABCDE1234F1Z5" /></Field>
                    <Field label="PAN" error={err.pan}><input className={cn(inputCls, 'uppercase')} value={data.pan} onChange={(e) => setData('pan', e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 10))} placeholder="ABCDE1234F" /></Field>
                    <Field label="Registered address" error={err.address_line1} className="sm:col-span-2"><input className={inputCls} value={data.address_line1} onChange={(e) => setData('address_line1', e.target.value)} /></Field>
                    <Field label="City" error={err.city}><input className={inputCls} value={data.city} onChange={(e) => setData('city', e.target.value)} /></Field>
                    <Field label="State" error={err.state_code}>
                        <select className={inputCls} value={data.state_code} onChange={(e) => setData('state_code', e.target.value)}>{states.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}</select>
                    </Field>
                    <Field label="PIN code" error={err.pincode}><input inputMode="numeric" className={inputCls} value={data.pincode} onChange={(e) => setData('pincode', e.target.value.replace(/\D/g, '').slice(0, 6))} /></Field>
                </Card>

                <Card icon={UserRound} title="Owner account" subtitle="You will use these details to log in to your partner dashboard.">
                    <Field label="Full name" error={err.name}><input className={inputCls} value={data.name} onChange={(e) => setData('name', e.target.value)} autoComplete="name" /></Field>
                    <Field label="Mobile number" error={err.phone}>
                        <div className="flex"><span className="flex items-center rounded-l-xl border border-r-0 border-input bg-muted px-3 text-sm text-muted-foreground">+91</span>
                            <input inputMode="numeric" className={cn(inputCls, 'rounded-l-none')} value={data.phone} onChange={(e) => setData('phone', e.target.value.replace(/\D/g, '').slice(0, 10))} autoComplete="tel-national" /></div>
                    </Field>
                    <Field label="Email" error={err.email} className="sm:col-span-2"><input type="email" className={inputCls} value={data.email} onChange={(e) => setData('email', e.target.value)} autoComplete="email" /></Field>
                    <Field label="Password" error={err.password}>
                        <PasswordInput value={data.password} onChange={(e) => setData('password', e.target.value)} autoComplete="new-password" />
                        <div className="mt-2"><PasswordStrength password={data.password} /></div>
                    </Field>
                    <Field label="Confirm password" error={err.password_confirmation}><PasswordInput value={data.password_confirmation} onChange={(e) => setData('password_confirmation', e.target.value)} autoComplete="new-password" /></Field>
                </Card>

                <Card icon={Banknote} title="Settlement bank account" subtitle="Encrypted and visible only to you and Trans EV compliance. Customer payments go to your own SabPaisa account.">
                    <Field label="Account holder name" error={err.account_name}><input className={inputCls} value={data.account_name} onChange={(e) => setData('account_name', e.target.value)} /></Field>
                    <Field label="Bank name" error={err.bank_name}><input className={inputCls} value={data.bank_name} onChange={(e) => setData('bank_name', e.target.value)} /></Field>
                    <Field label="Account number" error={err.account_number}><input inputMode="numeric" className={inputCls} value={data.account_number} onChange={(e) => setData('account_number', e.target.value.replace(/\D/g, '').slice(0, 18))} autoComplete="off" /></Field>
                    <Field label="IFSC" error={err.ifsc}><input className={cn(inputCls, 'uppercase')} value={data.ifsc} onChange={(e) => setData('ifsc', e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 11))} placeholder="SBIN0001234" /></Field>
                </Card>

                <Card icon={FileUp} title="KYC documents" subtitle="PDF, JPG or PNG, up to 5 MB each. Stored privately and used only for verification.">
                    {docTypes.map((d) => {
                        const file = data.documents[d.value];
                        return (
                            <Field key={d.value} label={`${d.label}${d.required ? '' : ' (if GST registered)'}`} error={err[`documents.${d.value}`]}>
                                <label className={cn('flex cursor-pointer items-center gap-3 rounded-xl border border-dashed px-4 py-3 text-sm transition hover:border-volt', file ? 'border-volt/60 bg-volt/5' : 'border-input')}>
                                    <FileUp className="size-5 shrink-0 text-muted-foreground" />
                                    <span className="min-w-0 flex-1 truncate text-foreground">{file ? file.name : 'Choose file'}</span>
                                    {file && <Check className="size-4 text-volt-600" />}
                                    <input type="file" accept=".pdf,.jpg,.jpeg,.png" className="sr-only" onChange={(e) => setData('documents', { ...data.documents, [d.value]: e.target.files?.[0] ?? null })} />
                                </label>
                            </Field>
                        );
                    })}
                </Card>

                <input type="text" name="website_url" value={data.website_url} onChange={(e) => setData('website_url', e.target.value)} tabIndex={-1} autoComplete="off" className="hidden" aria-hidden="true" />

                <div className="rounded-3xl border border-border bg-card p-6">
                    <label className="flex items-start gap-3 text-sm text-muted-foreground">
                        <input type="checkbox" className="mt-0.5 size-4 accent-[#00B386]" checked={data.terms} onChange={(e) => setData('terms', e.target.checked)} />
                        <span>
                            I confirm the details are correct and agree to the partner terms, the <Link href="/terms" className="font-medium text-foreground underline">Terms of Service</Link> and the{' '}
                            <Link href="/privacy" className="font-medium text-foreground underline">Privacy Policy</Link>. I understand stations go live only after verification.
                        </span>
                    </label>
                    <InputError message={err.terms} className="mt-2" />
                    <div className="mt-6 flex flex-wrap items-center justify-between gap-4">
                        <p className="flex items-center gap-2 text-xs text-muted-foreground"><Lock className="size-4" /> Bank details and documents are encrypted / stored privately. <ShieldCheck className="size-4" /> DPDP Act compliant.</p>
                        <button type="submit" disabled={form.processing} className="inline-flex h-12 items-center gap-2 rounded-xl bg-gradient-to-r from-volt-600 to-electric px-8 font-semibold text-white shadow-lg shadow-volt/25 transition hover:brightness-110 disabled:opacity-60">
                            {form.processing ? <LoaderCircle className="size-5 animate-spin" /> : <MapPinned className="size-5" />} Submit for verification
                        </button>
                    </div>
                    {form.progress && <div className="mt-4 h-1.5 overflow-hidden rounded-full bg-muted"><div className="h-full bg-gradient-to-r from-volt to-electric" style={{ width: `${form.progress.percentage}%` }} /></div>}
                </div>
            </form>
        </SiteLayout>
    );
}

function Card({ icon: Icon, title, subtitle, children }: { icon: typeof Zap; title: string; subtitle: string; children: ReactNode }) {
    return (
        <section className="rounded-3xl border border-border bg-card p-6 sm:p-8">
            <div className="flex items-start gap-3">
                <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-volt/10"><Icon className="size-5 text-volt-600" /></span>
                <div>
                    <h2 className="font-display text-lg font-bold text-foreground">{title}</h2>
                    <p className="text-sm text-muted-foreground">{subtitle}</p>
                </div>
            </div>
            <div className="mt-6 grid gap-4 sm:grid-cols-2">{children}</div>
        </section>
    );
}

function Field({ label, error, children, className }: { label: string; error?: string; children: ReactNode; className?: string }) {
    return (
        <div className={cn('block', className)}>
            <span className="mb-1.5 block text-sm font-medium text-foreground">{label}</span>
            {children}
            <InputError message={error} className="mt-1.5" />
        </div>
    );
}
