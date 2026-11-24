import { cn } from '@/lib/utils';
import { RotateCcw } from 'lucide-react';

export interface PolicyView {
    name: string | null;
    tiers: { label: string; percent: number; fee: number; minutes: number }[];
    noShowPercent: number;
    note: string | null;
}

/** The cancellation & no-show policy that applies (or was agreed) for a booking. */
export default function CancellationPolicyCard({ policy, title = 'Cancellation policy', agreed = false, className }: { policy: PolicyView | null; title?: string; agreed?: boolean; className?: string }) {
    if (!policy) return null;
    const inr = (v: number) => `₹${v.toLocaleString('en-IN', { maximumFractionDigits: 2 })}`;

    return (
        <section className={cn('rounded-2xl border border-border bg-card p-5', className)} aria-label={title}>
            <h2 className="flex items-center gap-2 font-display font-semibold text-foreground">
                <RotateCcw className="size-4 text-electric" /> {title}
            </h2>
            {agreed && <p className="mt-1 text-xs text-muted-foreground">Agreed when you booked. Later changes by the operator do not affect this booking.</p>}
            <ul className="mt-3 space-y-2 text-sm">
                {policy.tiers.map((t, i) => (
                    <li key={i} className="flex items-start justify-between gap-3">
                        <span className="text-muted-foreground">{t.label}</span>
                        <span className={cn('shrink-0 font-semibold', t.percent === 100 && t.fee === 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-foreground')}>
                            {t.percent}%{t.fee > 0 && ` − ${inr(t.fee)}`}
                        </span>
                    </li>
                ))}
                <li className="flex items-start justify-between gap-3 border-t border-border pt-2">
                    <span className="text-muted-foreground">No-show</span>
                    <span className="shrink-0 font-semibold text-foreground">{policy.noShowPercent}%</span>
                </li>
            </ul>
            {policy.note && <p className="mt-3 text-xs text-muted-foreground">{policy.note}</p>}
            <p className="mt-3 text-xs text-muted-foreground">If the operator cancels or the charger is out of service, you get a full refund.</p>
        </section>
    );
}
