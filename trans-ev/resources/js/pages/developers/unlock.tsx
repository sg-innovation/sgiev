import InputError from '@/components/input-error';
import { Head, useForm } from '@inertiajs/react';
import { Code2, LoaderCircle, LockKeyhole } from 'lucide-react';
import { type FormEvent, useRef } from 'react';

/** Developer portal entry: the 6-digit PIN Trans EV gave the developer. */
export default function DeveloperUnlock() {
    const form = useForm({ pin: '' });
    const boxes = useRef<(HTMLInputElement | null)[]>([]);
    const digits = form.data.pin.padEnd(6, ' ').slice(0, 6).split('');

    const setDigit = (i: number, v: string) => {
        const clean = v.replace(/\D/g, '');
        if (clean.length > 1) {
            form.setData('pin', clean.slice(0, 6));
            boxes.current[Math.min(5, clean.length)]?.focus();
            return;
        }
        const next = digits.map((d, j) => (j === i ? clean || ' ' : d)).join('').replace(/\s+$/, '');
        form.setData('pin', next.replace(/ /g, ''));
        if (clean && i < 5) boxes.current[i + 1]?.focus();
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/developers/unlock', { onError: () => form.reset('pin') });
    };

    return (
        <div className="flex min-h-svh items-center justify-center bg-[radial-gradient(ellipse_at_top,var(--color-electric)/12%,transparent_60%)] bg-background p-6">
            <Head title="Developer portal">
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <form onSubmit={submit} className="w-full max-w-sm rounded-3xl border border-border bg-card p-8 shadow-sm">
                <div className="flex size-12 items-center justify-center rounded-2xl bg-electric/10 text-electric">
                    <Code2 className="size-6" />
                </div>
                <h1 className="mt-5 font-display text-2xl font-bold text-foreground">Developer portal</h1>
                <p className="mt-1 text-sm text-muted-foreground">API documentation and a live console for the Trans EV apps. Enter the 6-digit PIN you were given.</p>
                <fieldset className="mt-6">
                    <legend className="sr-only">PIN</legend>
                    <div className="flex justify-between gap-2" onPaste={(e) => { e.preventDefault(); setDigit(0, e.clipboardData.getData('text')); }}>
                        {digits.map((d, i) => (
                            <input
                                key={i}
                                ref={(el) => { boxes.current[i] = el; }}
                                type="password"
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                maxLength={6}
                                aria-label={`PIN digit ${i + 1}`}
                                value={d.trim()}
                                autoFocus={i === 0}
                                onChange={(e) => setDigit(i, e.target.value)}
                                onKeyDown={(e) => { if (e.key === 'Backspace' && !d.trim() && i > 0) boxes.current[i - 1]?.focus(); }}
                                className="h-14 w-11 rounded-xl border border-input bg-background text-center text-xl font-semibold text-foreground outline-none focus:border-electric focus:ring-4 focus:ring-electric/15"
                            />
                        ))}
                    </div>
                    <InputError className="mt-3" message={form.errors.pin} />
                </fieldset>
                <button type="submit" disabled={form.processing || form.data.pin.length !== 6} className="mt-6 inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-foreground text-sm font-semibold text-background disabled:opacity-50">
                    {form.processing ? <LoaderCircle className="size-4 animate-spin" /> : <LockKeyhole className="size-4" />} Open the portal
                </button>
                <p className="mt-4 text-center text-xs text-muted-foreground">Access is personal and expires after a working day.</p>
            </form>
        </div>
    );
}
