import { CheckCircle2, Info, QrCode, RefreshCw, ShieldCheck } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

interface Pass {
    available: boolean;
    checkedIn?: boolean;
    checkedInAt?: string | null;
    qr?: string;
    expiresAt?: string;
    blocker?: string | null;
}

/**
 * Self-refreshing check-in QR. The code inside is valid only for a few minutes, so a screenshot or a forwarded
 * picture stops working; this card fetches a fresh one before it expires.
 */
export default function CheckInPass({ code, chargerCode }: { code: string; chargerCode: string }) {
    const [pass, setPass] = useState<Pass | null>(null);
    const [left, setLeft] = useState(0);
    const [failed, setFailed] = useState(false);

    const load = useCallback(async () => {
        try {
            const r = await fetch(`/account/bookings/${code}/pass`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!r.ok) throw new Error(String(r.status));
            setPass(await r.json());
            setFailed(false);
        } catch {
            setFailed(true);
        }
    }, [code]);

    useEffect(() => {
        load();
    }, [load]);

    useEffect(() => {
        if (!pass?.expiresAt) return;
        const tick = () => {
            const ms = new Date(pass.expiresAt!).getTime() - Date.now();
            setLeft(Math.max(0, Math.round(ms / 1000)));
            if (ms < 15_000) load(); // refresh shortly before expiry
        };
        tick();
        const t = setInterval(tick, 1000);
        return () => clearInterval(t);
    }, [pass?.expiresAt, load]);

    // Pick up a check-in done by staff while this page is open.
    useEffect(() => {
        if (!pass?.available || pass.checkedIn) return;
        const t = setInterval(load, 20_000);
        return () => clearInterval(t);
    }, [pass?.available, pass?.checkedIn, load]);

    if (!pass?.available && !failed) return null;

    if (pass?.checkedIn) {
        return (
            <section className="flex items-center gap-4 rounded-3xl border border-emerald-500/30 bg-emerald-500/10 p-6">
                <CheckCircle2 className="size-10 shrink-0 text-emerald-600 dark:text-emerald-400" />
                <div>
                    <h2 className="font-display text-lg font-semibold text-foreground">You’re checked in</h2>
                    <p className="text-sm text-muted-foreground">
                        Checked in at {pass.checkedInAt}. Plug in at charger <span className="font-mono font-semibold text-foreground">{chargerCode}</span> and start charging.
                    </p>
                </div>
            </section>
        );
    }

    const mm = String(Math.floor(left / 60)).padStart(1, '0');
    const ss = String(left % 60).padStart(2, '0');

    return (
        <section className="overflow-hidden rounded-3xl border border-border bg-card">
            <div className="grid gap-6 p-6 sm:grid-cols-[220px_1fr] sm:items-center">
                <div className="mx-auto w-full max-w-[220px]">
                    <div className="relative aspect-square rounded-2xl bg-white p-3 shadow-inner ring-1 ring-black/5">
                        {pass?.qr ? (
                            <img src={pass.qr} alt={`Check-in QR for booking ${code}`} className="size-full [image-rendering:pixelated]" />
                        ) : (
                            <div className="flex size-full items-center justify-center text-slate-400">
                                <RefreshCw className="size-6 animate-spin" />
                            </div>
                        )}
                    </div>
                    {pass?.qr && (
                        <p className="mt-2 flex items-center justify-center gap-1.5 text-xs text-muted-foreground tabular-nums">
                            <RefreshCw className="size-3.5" /> Refreshes in {mm}:{ss}
                        </p>
                    )}
                </div>
                <div>
                    <h2 className="flex items-center gap-2 font-display text-lg font-semibold text-foreground">
                        <QrCode className="size-5 text-volt-600 dark:text-volt" /> Check-in pass
                    </h2>
                    <p className="mt-2 text-sm text-muted-foreground">
                        At the station, show this QR to the staff, or scan the QR sticker on charger{' '}
                        <span className="font-mono font-semibold text-foreground">{chargerCode}</span> with your phone camera.
                    </p>
                    {pass?.blocker && (
                        <p className="mt-3 flex gap-2 rounded-2xl bg-muted p-3 text-sm text-foreground">
                            <Info className="size-4 shrink-0 text-electric" /> {pass.blocker}
                        </p>
                    )}
                    {failed && <p className="mt-3 text-sm text-red-700 dark:text-red-300">Couldn’t load the pass. Check your connection; it retries automatically.</p>}
                    <p className="mt-3 flex items-center gap-1.5 text-xs text-muted-foreground">
                        <ShieldCheck className="size-4 text-volt-600" /> The code changes every few minutes, so screenshots won’t work. Keep this page open.
                    </p>
                </div>
            </div>
        </section>
    );
}
