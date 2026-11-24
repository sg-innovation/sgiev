import { useForm } from '@inertiajs/react';
import { Clock, LoaderCircle, Send } from 'lucide-react';
import { useState } from 'react';

/** "I'm running late": tells the station the customer's ETA; the slot is held a little longer, up to a limit. */
export default function RunningLate({ code, late }: { code: string; late: { holdUntil: string; eta: string | null; maxExtra: number } }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ minutes: 10, note: '' });

    return (
        <section className="rounded-3xl border border-amber-500/30 bg-amber-500/5 p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex gap-3">
                    <Clock className="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-300" />
                    <div>
                        <p className="font-semibold text-foreground">{late.eta ? `The station expects you at ${late.eta}` : 'Running late?'}</p>
                        <p className="text-sm text-muted-foreground">
                            Your slot is held until <strong className="text-foreground">{late.holdUntil}</strong>. Let the station know and we can hold it up to {late.maxExtra} minutes longer.
                        </p>
                    </div>
                </div>
                {!open && (
                    <button type="button" onClick={() => setOpen(true)} className="rounded-full border border-amber-500/50 px-4 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-500/10 dark:text-amber-200">
                        {late.eta ? 'Update ETA' : 'I’m running late'}
                    </button>
                )}
            </div>
            {open && (
                <form
                    className="mt-4 space-y-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/account/bookings/${code}/running-late`, { preserveScroll: true, onSuccess: () => setOpen(false) });
                    }}
                >
                    <fieldset>
                        <legend className="text-sm font-medium text-foreground">I’ll be there in</legend>
                        <div className="mt-2 flex flex-wrap gap-2">
                            {[5, 10, 15, 20, 30].map((m) => (
                                <button key={m} type="button" onClick={() => form.setData('minutes', m)}
                                    className={form.data.minutes === m ? 'h-10 rounded-full bg-foreground px-4 text-sm font-semibold text-background' : 'h-10 rounded-full border border-border px-4 text-sm font-semibold text-foreground'}>
                                    {m} min
                                </button>
                            ))}
                        </div>
                    </fieldset>
                    <label className="block text-sm font-medium text-foreground">
                        Message to the station <span className="font-normal text-muted-foreground">optional</span>
                        <input value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} maxLength={160} placeholder="Stuck in traffic near the toll plaza"
                            className="mt-1 h-11 w-full rounded-xl border border-border bg-background px-3 font-normal" />
                    </label>
                    {form.errors.minutes && <p className="text-sm text-red-600 dark:text-red-400">{form.errors.minutes}</p>}
                    <div className="flex gap-2">
                        <button type="submit" disabled={form.processing} className="inline-flex items-center gap-2 rounded-full bg-foreground px-5 py-2.5 text-sm font-semibold text-background disabled:opacity-60">
                            {form.processing ? <LoaderCircle className="size-4 animate-spin" /> : <Send className="size-4" />} Tell the station
                        </button>
                        <button type="button" onClick={() => setOpen(false)} className="rounded-full px-4 py-2.5 text-sm font-semibold text-muted-foreground hover:text-foreground">
                            Cancel
                        </button>
                    </div>
                </form>
            )}
        </section>
    );
}
