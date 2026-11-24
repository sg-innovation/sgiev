import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, FileText, Headset, LoaderCircle, RotateCcw, Star } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { FilePicker, STATUS_TONE, type TicketSummary } from './index';

interface Message {
    id: number;
    mine: boolean;
    author: string;
    body: string;
    at: string;
    files: { name: string; url: string }[];
}
interface Props {
    ticket: TicketSummary & {
        booking: { code: string; station: string | null } | null;
        canReply: boolean;
        canResolve: boolean;
        canReopen: boolean;
        messages: Message[];
    };
    limits: { files: number; kb: number };
}

export default function SupportShow({ ticket: t, limits }: Props) {
    const { flash } = usePage<SharedData & { flash?: { success?: string; error?: string } }>().props;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Support tickets', href: '/account/support' },
        { title: t.number, href: `/account/support/${t.uuid}` },
    ];
    const form = useForm<{ body: string; files: File[] }>({ body: '', files: [] });
    const [rating, setRating] = useState<number>(t.rating ?? 0);

    const send = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/account/support/${t.uuid}/reply`, { forceFormData: true, preserveScroll: true, onSuccess: () => form.reset() });
    };
    const setStatus = (status: string) => router.post(`/account/support/${t.uuid}/status`, { status, rating: rating || null }, { preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${t.number} · ${t.subject}`} />
            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-6 p-4 md:p-6">
                <Link href="/account/support" className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground">
                    <ArrowLeft className="size-4" /> All tickets
                </Link>

                {flash?.success && <p className="rounded-xl bg-emerald-500/10 px-4 py-3 text-sm text-emerald-800 dark:text-emerald-300">{flash.success}</p>}

                <header className="rounded-3xl border border-border bg-card p-6">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="font-mono text-sm text-muted-foreground">{t.number}</span>
                        <span className={cn('rounded-full px-2.5 py-0.5 text-xs font-semibold', STATUS_TONE[t.status])}>{t.statusLabel}</span>
                    </div>
                    <h1 className="mt-2 font-display text-2xl font-bold text-foreground">{t.subject}</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {t.category} · {t.priority} priority · opened {t.createdAt}
                        {t.booking && (
                            <>
                                {' · '}
                                <Link href={`/account/bookings/${t.booking.code}`} className="font-medium text-foreground underline-offset-2 hover:underline">
                                    {t.booking.code}
                                </Link>
                                {t.booking.station && ` (${t.booking.station})`}
                            </>
                        )}
                    </p>
                    {(t.canResolve || t.canReopen) && (
                        <div className="mt-4 flex flex-wrap gap-2">
                            {t.canResolve && (
                                <button type="button" onClick={() => setStatus('resolved')} className="inline-flex items-center gap-1.5 rounded-full border border-border px-4 py-2 text-sm font-semibold text-foreground hover:border-emerald-500">
                                    <CheckCircle2 className="size-4 text-emerald-600" /> My issue is solved
                                </button>
                            )}
                            {t.canReopen && (
                                <button type="button" onClick={() => setStatus('open')} className="inline-flex items-center gap-1.5 rounded-full border border-border px-4 py-2 text-sm font-semibold text-foreground hover:border-volt">
                                    <RotateCcw className="size-4" /> Reopen
                                </button>
                            )}
                        </div>
                    )}
                    {t.status === 'resolved' && (
                        <div className="mt-4 flex flex-wrap items-center gap-2 text-sm">
                            <span className="text-muted-foreground">How did we do?</span>
                            {[1, 2, 3, 4, 5].map((n) => (
                                <button key={n} type="button" aria-label={`${n} star${n > 1 ? 's' : ''}`} onClick={() => setRating(n)}>
                                    <Star className={cn('size-5', n <= rating ? 'fill-amber-400 text-amber-400' : 'text-muted-foreground')} />
                                </button>
                            ))}
                            {rating > 0 && rating !== t.rating && (
                                <button type="button" onClick={() => setStatus('closed')} className="ml-2 rounded-full bg-foreground px-3 py-1 text-xs font-semibold text-background">
                                    Send & close
                                </button>
                            )}
                        </div>
                    )}
                </header>

                <ol className="space-y-4">
                    {t.messages.map((m) => (
                        <li key={m.id} className={cn('flex gap-3', m.mine && 'flex-row-reverse')}>
                            <span className={cn('flex size-9 shrink-0 items-center justify-center rounded-full text-xs font-bold', m.mine ? 'bg-electric/15 text-electric' : 'bg-volt/15 text-volt-700 dark:text-volt')}>
                                {m.mine ? 'You' : <Headset className="size-4" />}
                            </span>
                            <div className={cn('max-w-[85%] rounded-2xl border px-4 py-3', m.mine ? 'border-electric/20 bg-electric/5' : 'border-border bg-card')}>
                                <p className="text-xs text-muted-foreground">
                                    <span className="font-semibold text-foreground">{m.author}</span> · {m.at}
                                </p>
                                <p className="mt-1 text-sm whitespace-pre-line text-foreground">{m.body}</p>
                                {m.files.length > 0 && (
                                    <ul className="mt-2 flex flex-wrap gap-2">
                                        {m.files.map((f) => (
                                            <li key={f.url}>
                                                <a href={f.url} target="_blank" rel="noopener" className="inline-flex items-center gap-1 rounded-lg bg-muted px-2 py-1 text-xs hover:text-foreground">
                                                    <FileText className="size-3.5" /> {f.name}
                                                </a>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        </li>
                    ))}
                </ol>

                {t.canReply ? (
                    <form onSubmit={send} className="rounded-3xl border border-border bg-card p-5">
                        <label className="block">
                            <span className="text-sm font-medium text-foreground">Add a reply</span>
                            <textarea
                                className="mt-1.5 min-h-28 w-full rounded-xl border border-input bg-background px-3 py-2.5 text-sm text-foreground focus:border-volt focus:ring-2 focus:ring-volt/30 focus:outline-none"
                                value={form.data.body}
                                maxLength={5000}
                                onChange={(e) => form.setData('body', e.target.value)}
                                required
                            />
                            {form.errors.body && <span className="mt-1 block text-xs text-destructive">{form.errors.body}</span>}
                        </label>
                        <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
                            <FilePicker files={form.data.files} onChange={(f) => form.setData('files', f)} limits={limits} error={Object.entries(form.errors).find(([k]) => k.startsWith('files'))?.[1]} />
                            <button type="submit" disabled={form.processing} className="inline-flex items-center gap-2 rounded-full bg-foreground px-5 py-2.5 text-sm font-semibold text-background disabled:opacity-50">
                                {form.processing && <LoaderCircle className="size-4 animate-spin" />} Send reply
                            </button>
                        </div>
                    </form>
                ) : (
                    <p className="rounded-2xl bg-muted/60 px-4 py-3 text-center text-sm text-muted-foreground">
                        This ticket is closed. <Link href="/account/support" className="font-semibold text-foreground underline">Open a new ticket</Link> if you still need help.
                    </p>
                )}
            </div>
        </AppLayout>
    );
}
