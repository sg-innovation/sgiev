import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, Clipboard, KeyRound, LoaderCircle, ShieldCheck, ShieldOff } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Two-step login', href: '/settings/two-factor' }];

interface Props {
    enabled: boolean;
    confirmedAt: string | null;
    recoveryLeft: number;
    required: boolean;
    recoveryCodes: string[] | null;
}

function xsrf(): string {
    return decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
}

/** Settings → Two-step login with an authenticator app (Google Authenticator, Microsoft Authenticator, Authy…). */
export default function TwoFactor({ enabled, confirmedAt, recoveryLeft, required, recoveryCodes }: Props) {
    const { flash } = usePage<{ flash: { success?: string } }>().props;
    const [setup, setSetup] = useState<{ qr: string; secret: string } | null>(null);
    const [starting, setStarting] = useState(false);
    const confirm = useForm({ code: '' });
    const pw = useForm({ password: '' });
    const [copied, setCopied] = useState(false);

    const start = async () => {
        setStarting(true);
        const r = await fetch('/settings/two-factor/setup', { method: 'POST', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() } });
        if (r.ok) setSetup(await r.json());
        setStarting(false);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Two-step login" />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Two-step login" description="After your password or mobile code, also enter a code from an authenticator app on your phone. It keeps your account safe even if someone learns your password." />

                    {flash?.success && <p className="flex items-center gap-2 rounded-xl bg-emerald-500/10 px-4 py-3 text-sm text-emerald-800 dark:text-emerald-300"><CheckCircle2 className="size-4" /> {flash.success}</p>}

                    {recoveryCodes && recoveryCodes.length > 0 && (
                        <div className="rounded-xl border border-amber-500/40 bg-amber-500/10 p-4">
                            <p className="flex items-center gap-2 font-medium text-foreground"><KeyRound className="size-4" /> Save these recovery codes now</p>
                            <p className="mt-1 text-sm text-muted-foreground">Each code works once if you lose your phone. They will not be shown again.</p>
                            <ul className="mt-3 grid grid-cols-2 gap-2 font-mono text-sm">{recoveryCodes.map((c) => <li key={c} className="rounded-md bg-background px-2 py-1">{c}</li>)}</ul>
                            <button type="button" onClick={() => { navigator.clipboard?.writeText(recoveryCodes.join('\n')); setCopied(true); }} className="mt-3 inline-flex items-center gap-1.5 text-sm font-medium text-foreground underline"><Clipboard className="size-4" /> {copied ? 'Copied' : 'Copy all'}</button>
                        </div>
                    )}

                    {enabled ? (
                        <div className="space-y-6">
                            <div className="flex items-start gap-3 rounded-xl border border-border p-4">
                                <ShieldCheck className="mt-0.5 size-5 shrink-0 text-emerald-600" />
                                <div>
                                    <p className="font-medium text-foreground">On since {confirmedAt}</p>
                                    <p className="text-sm text-muted-foreground">{recoveryLeft} recovery code{recoveryLeft === 1 ? '' : 's'} left.{required ? ' Staff accounts must keep it on.' : ''}</p>
                                </div>
                            </div>
                            <form className="grid max-w-sm gap-3" onSubmit={(e) => e.preventDefault()}>
                                <Label htmlFor="tf-password">Confirm with your password</Label>
                                <PasswordInput id="tf-password" autoComplete="current-password" value={pw.data.password} onChange={(e) => pw.setData('password', e.target.value)} />
                                <InputError message={pw.errors.password} />
                                <div className="flex flex-wrap gap-2">
                                    <Button type="button" variant="outline" disabled={pw.processing || !pw.data.password} onClick={() => pw.post('/settings/two-factor/recovery-codes', { preserveScroll: true, onFinish: () => pw.reset() })}>New recovery codes</Button>
                                    {!required && (
                                        <Button type="button" variant="destructive" disabled={pw.processing || !pw.data.password} onClick={() => pw.delete('/settings/two-factor', { preserveScroll: true, onFinish: () => pw.reset() })}>
                                            <ShieldOff className="size-4" /> Turn off
                                        </Button>
                                    )}
                                </div>
                            </form>
                        </div>
                    ) : !setup ? (
                        <div className="space-y-3">
                            {required && <p className="rounded-xl bg-amber-500/10 px-4 py-3 text-sm text-amber-800 dark:text-amber-200">Staff accounts must use two-step login.</p>}
                            <Button type="button" onClick={start} disabled={starting}>{starting ? <LoaderCircle className="size-4 animate-spin" /> : <ShieldCheck className="size-4" />} Turn on two-step login</Button>
                        </div>
                    ) : (
                        <div className="grid gap-6 md:grid-cols-[auto_1fr]">
                            <img src={setup.qr} alt="QR code to scan with your authenticator app" className="size-48 rounded-xl border border-border bg-white p-2" />
                            <div className="space-y-4">
                                <ol className="list-decimal space-y-1 pl-5 text-sm text-muted-foreground">
                                    <li>Open Google Authenticator, Microsoft Authenticator or a similar app.</li>
                                    <li>Scan this QR code, or type the key: <code className="break-all rounded bg-muted px-1.5 py-0.5 text-xs text-foreground">{setup.secret}</code></li>
                                    <li>Enter the 6-digit code the app shows.</li>
                                </ol>
                                <form className="grid max-w-xs gap-2" onSubmit={(e) => { e.preventDefault(); confirm.post('/settings/two-factor/confirm', { preserveScroll: true, onSuccess: () => setSetup(null) }); }}>
                                    <Label htmlFor="tf-code">Code from the app</Label>
                                    <Input id="tf-code" inputMode="numeric" autoComplete="one-time-code" maxLength={6} value={confirm.data.code} onChange={(e) => confirm.setData('code', e.target.value.replace(/\D/g, ''))} className="text-center tracking-[0.4em]" autoFocus />
                                    <InputError message={confirm.errors.code} />
                                    <Button type="submit" disabled={confirm.processing || confirm.data.code.length !== 6}>{confirm.processing && <LoaderCircle className="size-4 animate-spin" />} Confirm and turn on</Button>
                                </form>
                            </div>
                        </div>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
