import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle, ShieldCheck } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

/** Second step of sign-in for accounts with an authenticator app. */
export default function TwoFactorChallenge() {
    const [recovery, setRecovery] = useState(false);
    const form = useForm({ code: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('two-factor.verify'), { onFinish: () => form.reset('code') });
    };

    return (
        <AuthLayout title="Two-step login" description={recovery ? 'Enter one of the recovery codes you saved when you turned on two-step login.' : 'Open your authenticator app (Google Authenticator, Microsoft Authenticator…) and enter the 6-digit code for Trans EV.'}>
            <Head title="Two-step login" />
            <form className="grid gap-6" onSubmit={submit}>
                <div className="grid gap-2">
                    <Label htmlFor="code">{recovery ? 'Recovery code' : 'Authentication code'}</Label>
                    <Input id="code" autoFocus autoComplete="one-time-code" inputMode={recovery ? 'text' : 'numeric'} maxLength={recovery ? 20 : 6}
                        placeholder={recovery ? 'abcde-12345' : '••••••'} value={form.data.code}
                        onChange={(e) => form.setData('code', recovery ? e.target.value : e.target.value.replace(/\D/g, ''))}
                        className={recovery ? '' : 'text-center text-lg tracking-[0.5em]'} />
                    <InputError message={form.errors.code} />
                </div>
                <Button type="submit" className="h-12 w-full rounded-xl" disabled={form.processing || (!recovery && form.data.code.length !== 6)}>
                    {form.processing ? <LoaderCircle className="h-4 w-4 animate-spin" /> : <ShieldCheck className="size-4" />} Continue
                </Button>
                <div className="flex justify-between text-sm">
                    <button type="button" onClick={() => { setRecovery(!recovery); form.reset('code'); }} className="text-muted-foreground underline hover:text-foreground">
                        {recovery ? 'Use the app code instead' : 'Lost your phone? Use a recovery code'}
                    </button>
                    <TextLink href={route('login')}>Start again</TextLink>
                </div>
            </form>
        </AuthLayout>
    );
}
