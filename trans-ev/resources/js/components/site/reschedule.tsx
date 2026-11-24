import { cn } from '@/lib/utils';
import { router, usePage } from '@inertiajs/react';
import { CalendarClock, LoaderCircle, RefreshCw } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Option {
    connectorId: number;
    label: string;
    powerKw: number;
    slots: { start: string; label: string; current: boolean }[];
}
export interface RescheduleInfo {
    blocker: string | null;
    remaining: number;
    noticeMinutes: number;
    dates: { value: string; label: string }[];
}

/** C6: move a paid booking to another time or charger at the same station (same length, within policy). */
export default function Reschedule({ code, info }: { code: string; info: RescheduleInfo }) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [open, setOpen] = useState(false);
    const [date, setDate] = useState(info.dates[0]?.value ?? '');
    const [options, setOptions] = useState<Option[] | null>(null);
    const [loading, setLoading] = useState(false);
    const [pick, setPick] = useState<{ connectorId: number; start: string } | null>(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (!open || !date) return;
        setLoading(true);
        setPick(null);
        fetch(`/account/bookings/${code}/reschedule?date=${date}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => r.json())
            .then((j) => setOptions(j.connectors ?? []))
            .catch(() => setOptions([]))
            .finally(() => setLoading(false));
    }, [open, date, code]);

    const save = () =>
        pick && router.post(`/account/bookings/${code}/reschedule`, { connector_id: pick.connectorId, start: pick.start }, {
            preserveScroll: true, onStart: () => setSaving(true), onFinish: () => setSaving(false), onSuccess: () => setOpen(false),
        });

    return (
        <section className="rounded-3xl border border-border bg-card p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex gap-3">
                    <CalendarClock className="mt-0.5 size-5 shrink-0 text-electric" />
                    <div>
                        <p className="font-semibold text-foreground">Need a different time?</p>
                        <p className="text-sm text-muted-foreground">
                            {info.blocker ??
                                `Move this booking to another slot or charger at this station, up to ${info.noticeMinutes} minutes before it starts. ${info.remaining} change${info.remaining === 1 ? '' : 's'} left.`}
                        </p>
                    </div>
                </div>
                {!info.blocker && !open && (
                    <button type="button" onClick={() => setOpen(true)} className="rounded-full border border-border px-4 py-2 text-sm font-semibold text-foreground hover:border-electric hover:text-electric">
                        Change time
                    </button>
                )}
            </div>

            {open && (
                <div className="mt-4 space-y-4">
                    <div className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1">
                        {info.dates.map((d) => (
                            <button key={d.value} type="button" onClick={() => setDate(d.value)}
                                className={cn('h-10 shrink-0 rounded-full border px-4 text-sm font-semibold', date === d.value ? 'border-foreground bg-foreground text-background' : 'border-border text-foreground')}>
                                {d.label}
                            </button>
                        ))}
                    </div>

                    {loading ? (
                        <p className="flex items-center gap-2 text-sm text-muted-foreground">
                            <RefreshCw className="size-4 animate-spin" /> Finding free slots…
                        </p>
                    ) : options && options.length === 0 ? (
                        <p className="rounded-2xl bg-muted p-4 text-sm text-muted-foreground">No free slots of this length on that day. Try another date.</p>
                    ) : (
                        options?.map((o) => (
                            <div key={o.connectorId}>
                                <p className="text-sm font-medium text-foreground">
                                    {o.label} <span className="font-normal text-muted-foreground">· {o.powerKw} kW</span>
                                </p>
                                <div className="mt-2 flex flex-wrap gap-2">
                                    {o.slots.map((s) => {
                                        const chosen = pick?.connectorId === o.connectorId && pick.start === s.start;
                                        return (
                                            <button key={s.start} type="button" disabled={s.current} onClick={() => setPick({ connectorId: o.connectorId, start: s.start })}
                                                className={cn('h-10 rounded-xl border px-3 text-sm font-semibold tabular-nums',
                                                    chosen ? 'border-electric bg-electric text-white' : s.current ? 'border-dashed border-border text-muted-foreground' : 'border-border text-foreground hover:border-electric')}>
                                                {s.label}
                                                {s.current && ' · now'}
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                        ))
                    )}

                    {(errors.start || errors.connector_id) && <p className="text-sm text-red-600 dark:text-red-400">{errors.start ?? errors.connector_id}</p>}
                    <div className="flex flex-wrap gap-2">
                        <button type="button" onClick={save} disabled={!pick || saving} className="inline-flex items-center gap-2 rounded-full bg-foreground px-5 py-2.5 text-sm font-semibold text-background disabled:opacity-50">
                            {saving && <LoaderCircle className="size-4 animate-spin" />} Move my booking
                        </button>
                        <button type="button" onClick={() => setOpen(false)} className="rounded-full px-4 py-2.5 text-sm font-semibold text-muted-foreground hover:text-foreground">
                            Keep current time
                        </button>
                    </div>
                    <p className="text-xs text-muted-foreground">Your payment and receipt stay the same. Slots that cost more than you paid can’t be chosen here.</p>
                </div>
            )}
        </section>
    );
}
