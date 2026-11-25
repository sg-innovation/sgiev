import { Head, useForm } from '@inertiajs/react';
import { ArrowRight, LoaderCircle, Mail, Smartphone } from 'lucide-react';
import { FormEventHandler, useEffect, useState } from 'react';

import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { cn } from '@/lib/utils';

interface LoginProps {
    status?: string;
    canResetPassword: boolean;
}

const submitCls = 'mt-2 h-12 w-full rounded-xl bg-gradient-to-r from-volt-600 to-electric text-base font-semibold text-white shadow-lg shadow-volt/25 hover:brightness-110';

function xsrf(): string {
    return decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
}

/** "Log in with mobile": SMS code; a new number also gives name and email (creates the account). */
function MobileLogin() {
    const form = useForm({ phone: '', code: '', name: '', email: '', remember: true as boolean });
    const [sent, setSent] = useState<{ registered: boolean; debug?: string } | null>(null);
    const [sending, setSending] = useState(false);
    const [sendError, setSendError] = useState<string | null>(null);
    const [wait, setWait] = useState(0);

    useEffect(() => {
        if (wait <= 0) return;
        const t = setTimeout(() => setWait(wait - 1), 1000);
        return () => clearTimeout(t);
    }, [wait]);

    const send = async () => {
        setSending(true);
        setSendError(null);
        try {
            const r = await fetch(route('login.otp.send'), { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() }, body: JSON.stringify({ phone: form.data.phone }) });
            const j = await r.json();
            if (!r.ok) {
                setSendError(j.errors?.phone?.[0] ?? j.message ?? 'Could not send the code.');
            } else {
                setSent({ registered: j.is_registered, debug: j.debug_code });
                setWait(j.resend_in ?? 30);
            }
        } catch {
            setSendError('Could not send the code. Check your connection.');
        } finally {
            setSending(false);
        }
    };

    const verify: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('login.otp.verify'), { onFinish: () => form.reset('code') });
    };

    return (
        <form className="grid gap-6" onSubmit={verify}>
            <div className="grid gap-2">
                <Label htmlFor="phone">Mobile number</Label>
                <div className="relative flex gap-2">
                    <span className="flex h-10 items-center rounded-md border border-input px-3 text-sm text-muted-foreground">+91</span>
                    <Input id="phone" type="tel" inputMode="numeric" autoComplete="tel-national" placeholder="98765 43210" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} disabled={!!sent} required />
                </div>
                <InputError message={sendError ?? form.errors.phone} />
            </div>
            {!sent ? (
                <Button type="button" className={submitCls} onClick={send} disabled={sending || form.data.phone.replace(/\D/g, '').length < 10}>
                    {sending ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <Smartphone className="size-4" />} Send login code
                </Button>
            ) : (
                <>
                    <div className="grid gap-2">
                        <div className="flex items-center">
                            <Label htmlFor="code">6-digit code</Label>
                            <button type="button" onClick={() => { setSent(null); form.reset('code'); }} className="ml-auto text-sm text-muted-foreground hover:text-foreground">Change number</button>
                        </div>
                        <Input id="code" inputMode="numeric" autoComplete="one-time-code" maxLength={6} placeholder="••••••" value={form.data.code} onChange={(e) => form.setData('code', e.target.value.replace(/\D/g, ''))} autoFocus required className="text-center text-lg tracking-[0.5em]" />
                        <InputError message={form.errors.code} />
                        <p className="text-xs text-muted-foreground">
                            Sent by SMS.{' '}
                            {wait > 0 ? `You can ask for a new code in ${wait} s.` : <button type="button" onClick={send} className="font-medium text-foreground underline">Send a new code</button>}
                            {sent.debug && <span className="ml-1 rounded bg-amber-500/15 px-1.5 text-amber-700 dark:text-amber-300">Test code: {sent.debug}</span>}
                        </p>
                    </div>
                    {!sent.registered && (
                        <div className="grid gap-4 rounded-xl border border-dashed border-border p-4">
                            <p className="text-sm text-muted-foreground">New to Trans EV? Tell us who you are to create your account.</p>
                            <div className="grid gap-2"><Label htmlFor="name">Your name</Label><Input id="name" autoComplete="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /><InputError message={form.errors.name} /></div>
                            <div className="grid gap-2"><Label htmlFor="otp-email">Email (for receipts and invoices)</Label><Input id="otp-email" type="email" autoComplete="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} /><InputError message={form.errors.email} /></div>
                        </div>
                    )}
                    <div className="flex items-center space-x-3">
                        <Checkbox id="otp-remember" checked={form.data.remember} onCheckedChange={(v) => form.setData('remember', v === true)} />
                        <Label htmlFor="otp-remember">Remember me</Label>
                    </div>
                    <Button type="submit" className={submitCls} disabled={form.processing || form.data.code.length !== 6}>
                        {form.processing ? <LoaderCircle className="h-4 w-4 animate-spin" /> : null}
                        {sent.registered ? 'Log in' : 'Create account'}
                        {!form.processing && <ArrowRight className="size-4" />}
                    </Button>
                </>
            )}
        </form>
    );
}

export default function Login({ status, canResetPassword }: LoginProps) {
    const [mode, setMode] = useState<'mobile' | 'email'>('mobile');
    const { data, setData, post, processing, errors, reset } = useForm({ email: '', password: '', remember: false as boolean });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('login'), { onFinish: () => reset('password') });
    };

    return (
        <AuthLayout title="Welcome back" description="Log in to book chargers, manage bookings and download invoices.">
            <Head title="Log in" />

            <div role="tablist" aria-label="How to log in" className="mb-6 grid grid-cols-2 rounded-xl bg-muted p-1 text-sm font-medium">
                {(['mobile', 'email'] as const).map((m) => (
                    <button key={m} type="button" role="tab" aria-selected={mode === m} onClick={() => setMode(m)}
                        className={cn('flex items-center justify-center gap-2 rounded-lg py-2 transition', mode === m ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground')}>
                        {m === 'mobile' ? <Smartphone className="size-4" /> : <Mail className="size-4" />} {m === 'mobile' ? 'Mobile' : 'Email'}
                    </button>
                ))}
            </div>

            {mode === 'mobile' ? (
                <MobileLogin />
            ) : (
                <form className="flex flex-col gap-6" onSubmit={submit}>
                    <div className="grid gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="email">Email address</Label>
                            <div className="relative">
                                <Mail className="pointer-events-none absolute top-1/2 left-3.5 z-10 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input id="email" type="email" required autoFocus tabIndex={1} autoComplete="email" value={data.email} onChange={(e) => setData('email', e.target.value)} placeholder="you@example.com" className="pl-10" />
                            </div>
                            <InputError message={errors.email} />
                        </div>

                        <div className="grid gap-2">
                            <div className="flex items-center">
                                <Label htmlFor="password">Password</Label>
                                {canResetPassword && (
                                    <TextLink href={route('password.request')} className="ml-auto text-sm" tabIndex={5}>
                                        Forgot password?
                                    </TextLink>
                                )}
                            </div>
                            <PasswordInput id="password" required tabIndex={2} autoComplete="current-password" value={data.password} onChange={(e) => setData('password', e.target.value)} placeholder="Password" />
                            <InputError message={errors.password} />
                        </div>

                        <div className="flex items-center space-x-3">
                            <Checkbox id="remember" name="remember" tabIndex={3} checked={data.remember} onCheckedChange={(v) => setData('remember', v === true)} />
                            <Label htmlFor="remember">Remember me</Label>
                        </div>

                        <Button type="submit" className={submitCls} tabIndex={4} disabled={processing}>
                            {processing ? <LoaderCircle className="h-4 w-4 animate-spin" /> : null}
                            Log in
                            {!processing && <ArrowRight className="size-4" />}
                        </Button>
                    </div>
                </form>
            )}

            <div className="mt-6 text-center text-sm text-muted-foreground">
                Don't have an account?{' '}
                <TextLink href={route('register')} tabIndex={5}>
                    Sign up
                </TextLink>
            </div>

            {status && <div className="mt-4 rounded-xl bg-emerald-500/10 p-3 text-center text-sm font-medium text-emerald-700 dark:text-emerald-300">{status}</div>}
        </AuthLayout>
    );
}
