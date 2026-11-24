import InputError from '@/components/input-error';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { type Option } from '@/types/station';
import { useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, LoaderCircle, Send } from 'lucide-react';
import { type FormEvent, type ReactNode } from 'react';

const inputCls = 'h-11 w-full rounded-xl border border-input bg-background px-3.5 text-sm text-foreground outline-none transition focus:border-volt focus:ring-4 focus:ring-volt/15';

export default function ContactForm({ topics }: { topics: Option[] }) {
    const { auth, flash } = usePage<SharedData & { flash: { success?: string } }>().props;
    const form = useForm({
        topic: topics[0]?.value ?? 'other',
        name: auth.user?.name ?? '',
        email: auth.user?.email ?? '',
        phone: '',
        booking_code: '',
        subject: '',
        message: '',
        consent: false as boolean,
        website: '', // honeypot
    });
    const { data, setData, errors } = form;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/contact', {
            preserveScroll: true,
            onSuccess: () => form.reset('subject', 'message', 'booking_code', 'consent'),
        });
    };

    return (
        <form onSubmit={submit} className="rounded-3xl border border-border bg-card p-6 shadow-xl shadow-black/5 sm:p-8" noValidate>
            <h2 className="font-display text-2xl font-bold text-foreground">Send us a query</h2>
            <p className="mt-1 text-sm text-muted-foreground">We usually reply within one working day. For urgent help at a station, please call.</p>

            {flash?.success && (
                <div className="mt-5 flex gap-3 rounded-2xl bg-emerald-500/10 p-4 text-sm font-medium text-emerald-800 ring-1 ring-emerald-500/30 dark:text-emerald-200">
                    <CheckCircle2 className="size-5 shrink-0" /> {flash.success}
                </div>
            )}

            <div className="mt-6">
                <span className="mb-2 block text-sm font-medium text-foreground">What is it about?</span>
                <div className="flex flex-wrap gap-2">
                    {topics.map((t) => (
                        <button
                            key={t.value}
                            type="button"
                            onClick={() => setData('topic', t.value)}
                            className={cn(
                                'rounded-full border px-3.5 py-1.5 text-sm font-medium transition',
                                data.topic === t.value ? 'border-volt bg-volt/10 text-volt-600' : 'border-border text-muted-foreground hover:border-volt/50 hover:text-foreground',
                            )}
                        >
                            {t.label}
                        </button>
                    ))}
                </div>
                <InputError message={errors.topic} className="mt-1.5" />
            </div>

            <div className="mt-5 grid gap-4 sm:grid-cols-2">
                <Field label="Full name" error={errors.name}>
                    <input className={inputCls} value={data.name} onChange={(e) => setData('name', e.target.value)} autoComplete="name" maxLength={120} required />
                </Field>
                <Field label="Email" error={errors.email}>
                    <input type="email" className={inputCls} value={data.email} onChange={(e) => setData('email', e.target.value)} autoComplete="email" required />
                </Field>
                <Field label="Mobile number (optional)" error={errors.phone}>
                    <div className="flex">
                        <span className="flex items-center rounded-l-xl border border-r-0 border-input bg-muted px-3 text-sm text-muted-foreground">+91</span>
                        <input
                            inputMode="numeric"
                            className={cn(inputCls, 'rounded-l-none')}
                            value={data.phone}
                            onChange={(e) => setData('phone', e.target.value.replace(/\D/g, '').slice(0, 10))}
                            placeholder="98765 43210"
                            autoComplete="tel-national"
                        />
                    </div>
                </Field>
                <Field label="Booking code (if any)" error={errors.booking_code}>
                    <input className={cn(inputCls, 'uppercase')} value={data.booking_code} onChange={(e) => setData('booking_code', e.target.value.toUpperCase().slice(0, 20))} placeholder="TEV-261125-ABCDE" />
                </Field>
                <Field label="Subject" error={errors.subject} className="sm:col-span-2">
                    <input className={inputCls} value={data.subject} onChange={(e) => setData('subject', e.target.value)} maxLength={150} required />
                </Field>
                <Field label="Message" error={errors.message} className="sm:col-span-2">
                    <textarea
                        className={cn(inputCls, 'h-36 resize-y py-3')}
                        value={data.message}
                        onChange={(e) => setData('message', e.target.value)}
                        maxLength={5000}
                        placeholder="Tell us what happened, including the station and time if relevant."
                        required
                    />
                    <span className="mt-1 block text-right text-xs text-muted-foreground">{data.message.length}/5000</span>
                </Field>
            </div>

            {/* Honeypot (hidden from people, visible to bots) */}
            <input type="text" name="website" value={data.website} onChange={(e) => setData('website', e.target.value)} tabIndex={-1} autoComplete="off" className="hidden" aria-hidden="true" />

            <label className="mt-2 flex items-start gap-3 text-sm text-muted-foreground">
                <input type="checkbox" className="mt-0.5 size-4 accent-[#00B386]" checked={data.consent} onChange={(e) => setData('consent', e.target.checked)} />
                <span>I agree that Trans EV may use these details to respond to my query, as described in the Privacy Policy.</span>
            </label>
            <InputError message={errors.consent} className="mt-1.5" />

            <button
                type="submit"
                disabled={form.processing}
                className="mt-6 inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-volt-600 to-electric font-semibold text-white shadow-lg shadow-volt/25 transition hover:brightness-110 disabled:opacity-60 sm:w-auto sm:px-8"
            >
                {form.processing ? <LoaderCircle className="size-5 animate-spin" /> : <Send className="size-5" />}
                Send message
            </button>
        </form>
    );
}

function Field({ label, error, children, className }: { label: string; error?: string; children: ReactNode; className?: string }) {
    return (
        <label className={cn('block', className)}>
            <span className="mb-1.5 block text-sm font-medium text-foreground">{label}</span>
            {children}
            <InputError message={error} className="mt-1.5" />
        </label>
    );
}
