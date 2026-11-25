import { cn } from '@/lib/utils';
import { router, usePage } from '@inertiajs/react';
import { LoaderCircle, MessageSquareReply, Star } from 'lucide-react';
import { useState } from 'react';

/** Read-only stars (half stars rounded to the nearest half). */
export function Stars({ value, size = 'size-4', className }: { value: number; size?: string; className?: string }) {
    return (
        <span className={cn('inline-flex items-center gap-0.5', className)} role="img" aria-label={`${value.toFixed(1)} out of 5`}>
            {[1, 2, 3, 4, 5].map((i) => {
                const fill = Math.max(0, Math.min(1, value - (i - 1)));
                return (
                    <span key={i} className={cn('relative inline-block', size)}>
                        <Star className={cn('absolute inset-0 text-muted-foreground/30', size)} />
                        <span className="absolute inset-0 overflow-hidden" style={{ width: `${Math.round(fill * 2) * 50}%` }}>
                            <Star className={cn('fill-amber-400 text-amber-400', size)} />
                        </span>
                    </span>
                );
            })}
        </span>
    );
}

export function RatingBadge({ rating, className }: { rating: { avg: number; count: number } | null; className?: string }) {
    if (!rating) return null;
    return (
        <span className={cn('inline-flex items-center gap-1 text-sm', className)}>
            <Star className="size-4 fill-amber-400 text-amber-400" />
            <span className="font-semibold">{rating.avg.toFixed(1)}</span>
            <span className="opacity-70">({rating.count})</span>
        </span>
    );
}

/** Interactive 1–5 star picker. */
function StarInput({ value, onChange, label }: { value: number; onChange: (v: number) => void; label: string }) {
    const [hover, setHover] = useState(0);
    const words = ['', 'Poor', 'Below average', 'Okay', 'Good', 'Excellent'];
    return (
        <div>
            <p className="text-sm font-medium text-foreground">{label}</p>
            <div className="mt-1 flex items-center gap-1" onMouseLeave={() => setHover(0)} role="radiogroup" aria-label={label}>
                {[1, 2, 3, 4, 5].map((i) => (
                    <button key={i} type="button" role="radio" aria-checked={value === i} aria-label={`${i} star${i > 1 ? 's' : ''}`}
                        onMouseEnter={() => setHover(i)} onClick={() => onChange(i)} className="rounded p-0.5 focus-visible:ring-2 focus-visible:ring-electric">
                        <Star className={cn('size-8 transition', (hover || value) >= i ? 'fill-amber-400 text-amber-400' : 'text-muted-foreground/40')} />
                    </button>
                ))}
                <span className="ml-2 text-sm text-muted-foreground">{words[hover || value]}</span>
            </div>
        </div>
    );
}

export interface ReviewInfo {
    canEdit: boolean;
    tagOptions: { value: string; label: string }[];
    existing: { stationRating: number; chargerRating: number | null; tags: string[]; body: string | null; status: string; statusLabel: string; reply: string | null } | null;
}

/** "Rate your charge" card on a completed booking. */
export function RateCharge({ code, chargerCode, info }: { code: string; chargerCode: string; info: ReviewInfo }) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const e = info.existing;
    const [editing, setEditing] = useState(!e);
    const [station, setStation] = useState(e?.stationRating ?? 0);
    const [charger, setCharger] = useState(e?.chargerRating ?? 0);
    const [tags, setTags] = useState<string[]>(e?.tags ?? []);
    const [body, setBody] = useState(e?.body ?? '');
    const [saving, setSaving] = useState(false);

    const submit = () =>
        router.post(`/account/bookings/${code}/review`, { station_rating: station, charger_rating: charger || null, tags, body }, {
            preserveScroll: true, onStart: () => setSaving(true), onFinish: () => setSaving(false), onSuccess: () => setEditing(false),
        });

    if (e && !editing) {
        return (
            <section className="rounded-3xl border border-border bg-card p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 className="font-display text-lg font-semibold text-foreground">Your rating</h2>
                        <div className="mt-2 flex items-center gap-2">
                            <Stars value={e.stationRating} size="size-5" />
                            <span className="text-sm text-muted-foreground">station{e.chargerRating ? ` · charger ${e.chargerRating}/5` : ''}</span>
                        </div>
                        {e.body && <p className="mt-2 text-sm text-foreground">“{e.body}”</p>}
                        <p className="mt-2 text-xs text-muted-foreground">{e.statusLabel}</p>
                    </div>
                    {info.canEdit && (
                        <button type="button" onClick={() => setEditing(true)} className="rounded-full border border-border px-4 py-2 text-sm font-semibold text-foreground hover:border-electric">
                            Edit
                        </button>
                    )}
                </div>
                {e.reply && (
                    <div className="mt-4 flex gap-2 rounded-2xl bg-muted p-3 text-sm">
                        <MessageSquareReply className="size-4 shrink-0 text-electric" />
                        <p><span className="font-semibold">Reply from the station:</span> {e.reply}</p>
                    </div>
                )}
            </section>
        );
    }

    return (
        <section className="rounded-3xl border border-amber-400/40 bg-amber-400/5 p-6">
            <h2 className="font-display text-lg font-semibold text-foreground">How was your charge?</h2>
            <p className="text-sm text-muted-foreground">Your rating helps other drivers and helps the station improve.</p>
            <div className="mt-4 grid gap-4 sm:grid-cols-2">
                <StarInput value={station} onChange={setStation} label="The station" />
                <StarInput value={charger} onChange={setCharger} label={`Charger ${chargerCode}`} />
            </div>
            {info.tagOptions.length > 0 && (
                <div className="mt-4 flex flex-wrap gap-2">
                    {info.tagOptions.map((t) => {
                        const on = tags.includes(t.value);
                        return (
                            <button key={t.value} type="button" aria-pressed={on} onClick={() => setTags(on ? tags.filter((x) => x !== t.value) : [...tags, t.value].slice(0, 6))}
                                className={cn('rounded-full border px-3 py-1.5 text-sm', on ? 'border-foreground bg-foreground text-background' : 'border-border text-foreground hover:border-foreground/40')}>
                                {t.label}
                            </button>
                        );
                    })}
                </div>
            )}
            <label className="mt-4 block text-sm font-medium text-foreground">
                Tell others more <span className="font-normal text-muted-foreground">optional</span>
                <textarea value={body} onChange={(ev) => setBody(ev.target.value)} maxLength={1000} rows={3}
                    placeholder="Was it easy to find? Did charging go smoothly?" className="mt-1 w-full rounded-xl border border-border bg-background p-3 font-normal" />
            </label>
            {errors.station_rating && <p className="mt-2 text-sm text-red-600 dark:text-red-400">{errors.station_rating}</p>}
            <div className="mt-4 flex gap-2">
                <button type="button" onClick={submit} disabled={!station || saving} className="inline-flex items-center gap-2 rounded-full bg-foreground px-5 py-2.5 text-sm font-semibold text-background disabled:opacity-50">
                    {saving && <LoaderCircle className="size-4 animate-spin" />} {e ? 'Save changes' : 'Submit rating'}
                </button>
                {e && (
                    <button type="button" onClick={() => setEditing(false)} className="rounded-full px-4 py-2.5 text-sm font-semibold text-muted-foreground hover:text-foreground">
                        Cancel
                    </button>
                )}
            </div>
            <p className="mt-2 text-xs text-muted-foreground">Shown with your first name and initial only. Written reviews are checked before they appear.</p>
        </section>
    );
}

export interface StationReviews {
    avg: number | null;
    count: number;
    distribution: { stars: number; count: number }[];
    items: { id: string; name: string; rating: number; chargerRating: number | null; charger: string | null; tags: string[]; body: string | null; date: string; reply: { text: string; by: string; date: string | null } | null }[];
}

/** Reviews section on the station page. */
export function StationReviewsSection({ reviews }: { reviews: StationReviews }) {
    return (
        <section className="rounded-2xl border border-border bg-card p-6">
            <h2 className="font-display text-xl font-semibold">Ratings &amp; reviews</h2>
            {reviews.count === 0 ? (
                <p className="mt-3 text-sm text-muted-foreground">No ratings yet. Drivers can rate the station after they charge here.</p>
            ) : (
                <>
                    <div className="mt-4 grid gap-6 sm:grid-cols-[160px_1fr] sm:items-center">
                        <div className="text-center sm:text-left">
                            <p className="font-display text-5xl font-extrabold">{reviews.avg?.toFixed(1)}</p>
                            <Stars value={reviews.avg ?? 0} />
                            <p className="mt-1 text-sm text-muted-foreground">{reviews.count} rating{reviews.count === 1 ? '' : 's'}</p>
                        </div>
                        <ul className="space-y-1.5">
                            {reviews.distribution.map((d) => (
                                <li key={d.stars} className="flex items-center gap-2 text-sm">
                                    <span className="w-8 tabular-nums text-muted-foreground">{d.stars}★</span>
                                    <span className="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                                        <span className="block h-full rounded-full bg-amber-400" style={{ width: `${reviews.count ? (d.count / reviews.count) * 100 : 0}%` }} />
                                    </span>
                                    <span className="w-8 text-right tabular-nums text-muted-foreground">{d.count}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                    <ul className="mt-6 divide-y divide-border">
                        {reviews.items.map((r) => (
                            <li key={r.id} className="py-4">
                                <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <Stars value={r.rating} />
                                    <span className="text-sm font-semibold">{r.name}</span>
                                    <span className="text-xs text-muted-foreground">{r.date}{r.charger && ` · charger ${r.charger}`}</span>
                                </div>
                                {r.tags.length > 0 && (
                                    <div className="mt-2 flex flex-wrap gap-1.5">
                                        {r.tags.map((t) => <span key={t} className="rounded-full bg-muted px-2.5 py-0.5 text-xs text-muted-foreground">{t}</span>)}
                                    </div>
                                )}
                                {r.body && <p className="mt-2 text-sm text-foreground">{r.body}</p>}
                                {r.reply && (
                                    <div className="mt-3 ml-4 border-l-2 border-electric/40 pl-3 text-sm">
                                        <p className="text-xs font-semibold text-electric">Reply from {r.reply.by}{r.reply.date && ` · ${r.reply.date}`}</p>
                                        <p className="mt-0.5 text-muted-foreground">{r.reply.text}</p>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </section>
    );
}
