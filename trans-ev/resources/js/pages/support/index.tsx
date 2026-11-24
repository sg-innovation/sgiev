import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { ChevronRight, LifeBuoy, LoaderCircle, MessageSquarePlus, Paperclip, X } from 'lucide-react';
import { type FormEvent, useState } from 'react';

export interface TicketSummary {
    uuid: string;
    number: string;
    subject: string;
    status: string;
    statusLabel: string;
    category: string | null;
    priority: string | null;
    priorityColor: string | null;
    createdAt: string;
    updatedAt: string;
    awaitingYou: boolean;
    rating: number | null;
}

interface Props {
    tickets: { data: TicketSummary[]; links: { url: string | null; label: string; active: boolean }[] };
    categories: { id: number; name: string; description: string | null; requiresBooking: boolean; defaultPriorityId: number | null }[];
    priorities: { id: number; name: string; response: string }[];
    bookings: { id: number; label: string }[];
    preselectBooking: number | null;
    limits: { files: number; kb: number };
}

export const STATUS_TONE: Record<string, string> = {
    open: 'bg-electric/10 text-electric',
    in_progress: 'bg-violet-500/10 text-violet-700 dark:text-violet-300',
    waiting_on_customer: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    resolved: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    closed: 'bg-muted text-muted-foreground',
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'My account', href: '/dashboard' },
    { title: 'Support tickets', href: '/account/support' },
];

const inputCls =
    'w-full rounded-xl border border-input bg-background px-3 py-2.5 text-sm text-foreground placeholder:text-muted-foreground focus:border-volt focus:ring-2 focus:ring-volt/30 focus:outline-none';

export default function SupportIndex({ tickets, categories, priorities, bookings, preselectBooking, limits }: Props) {
    const [open, setOpen] = useState(tickets.data.length === 0 || !!preselectBooking);
    const form = useForm<{ category_id: string; priority_id: string; booking_id: string; subject: string; body: string; files: File[] }>({
        category_id: '',
        priority_id: '',
        booking_id: preselectBooking ? String(preselectBooking) : '',
        subject: '',
        body: '',
        files: [],
    });
    const category = categories.find((c) => String(c.id) === form.data.category_id);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post('/account/support', { forceFormData: true, preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Support tickets" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <section className="flex flex-wrap items-end justify-between gap-4 rounded-3xl bg-ink p-6 text-white sm:p-8">
                    <div>
                        <p className="flex items-center gap-2 text-sm text-white/60">
                            <LifeBuoy className="size-4" /> Help centre
                        </p>
                        <h1 className="mt-1 font-display text-3xl font-bold">Support tickets</h1>
                        <p className="mt-1 max-w-xl text-white/70">Raise an issue about a booking, payment, charger or invoice. We reply by email and here.</p>
                    </div>
                    <button
                        type="button"
                        onClick={() => setOpen((o) => !o)}
                        className="inline-flex items-center gap-2 rounded-full bg-gradient-to-r from-volt to-electric px-5 py-3 font-semibold text-white shadow-lg shadow-volt/30 hover:brightness-110"
                    >
                        <MessageSquarePlus className="size-5" /> {open ? 'Hide form' : 'New ticket'}
                    </button>
                </section>

                {open && (
                    <form onSubmit={submit} className="grid gap-5 rounded-3xl border border-border bg-card p-6 lg:grid-cols-2">
                        <div className="space-y-4">
                            <label className="block">
                                <span className="text-sm font-medium text-foreground">What is it about?</span>
                                <select
                                    className={cn(inputCls, 'mt-1.5')}
                                    value={form.data.category_id}
                                    onChange={(e) => {
                                        const c = categories.find((x) => String(x.id) === e.target.value);
                                        form.setData((d) => ({ ...d, category_id: e.target.value, priority_id: c?.defaultPriorityId && priorities.some((p) => p.id === c.defaultPriorityId) ? String(c.defaultPriorityId) : d.priority_id }));
                                    }}
                                    required
                                >
                                    <option value="">Choose a topic</option>
                                    {categories.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.name}
                                        </option>
                                    ))}
                                </select>
                                {category?.description && <span className="mt-1 block text-xs text-muted-foreground">{category.description}</span>}
                                {form.errors.category_id && <span className="mt-1 block text-xs text-destructive">{form.errors.category_id}</span>}
                            </label>
                            <label className="block">
                                <span className="text-sm font-medium text-foreground">
                                    Booking {category?.requiresBooking ? '' : <span className="text-muted-foreground">(optional)</span>}
                                </span>
                                <select className={cn(inputCls, 'mt-1.5')} value={form.data.booking_id} onChange={(e) => form.setData('booking_id', e.target.value)} required={!!category?.requiresBooking}>
                                    <option value="">{bookings.length ? 'Not about a specific booking' : 'You have no bookings yet'}</option>
                                    {bookings.map((b) => (
                                        <option key={b.id} value={b.id}>
                                            {b.label}
                                        </option>
                                    ))}
                                </select>
                                {form.errors.booking_id && <span className="mt-1 block text-xs text-destructive">{form.errors.booking_id}</span>}
                            </label>
                            <fieldset>
                                <legend className="text-sm font-medium text-foreground">How urgent?</legend>
                                <div className="mt-1.5 flex flex-wrap gap-2">
                                    {priorities.map((p) => (
                                        <button
                                            key={p.id}
                                            type="button"
                                            aria-pressed={form.data.priority_id === String(p.id)}
                                            onClick={() => form.setData('priority_id', String(p.id))}
                                            className={cn(
                                                'rounded-xl border px-3 py-2 text-left text-sm transition',
                                                form.data.priority_id === String(p.id) ? 'border-volt bg-volt/10 text-foreground' : 'border-border text-muted-foreground hover:border-volt/50',
                                            )}
                                        >
                                            <span className="block font-semibold">{p.name}</span>
                                            <span className="block text-xs">Reply within {p.response}</span>
                                        </button>
                                    ))}
                                </div>
                            </fieldset>
                        </div>
                        <div className="space-y-4">
                            <label className="block">
                                <span className="text-sm font-medium text-foreground">Subject</span>
                                <input className={cn(inputCls, 'mt-1.5')} value={form.data.subject} maxLength={160} onChange={(e) => form.setData('subject', e.target.value)} placeholder="e.g. Refund not received for TEV-261124-ABCDE" required />
                                {form.errors.subject && <span className="mt-1 block text-xs text-destructive">{form.errors.subject}</span>}
                            </label>
                            <label className="block">
                                <span className="text-sm font-medium text-foreground">Describe the issue</span>
                                <textarea className={cn(inputCls, 'mt-1.5 min-h-32')} value={form.data.body} maxLength={5000} onChange={(e) => form.setData('body', e.target.value)} placeholder="What happened, when, and what you expected." required />
                                {form.errors.body && <span className="mt-1 block text-xs text-destructive">{form.errors.body}</span>}
                            </label>
                            <FilePicker files={form.data.files} onChange={(f) => form.setData('files', f)} limits={limits} error={Object.entries(form.errors).find(([k]) => k.startsWith('files'))?.[1]} />
                            <div className="flex justify-end">
                                <button type="submit" disabled={form.processing} className="inline-flex items-center gap-2 rounded-full bg-foreground px-6 py-3 text-sm font-semibold text-background hover:opacity-90 disabled:opacity-50">
                                    {form.processing && <LoaderCircle className="size-4 animate-spin" />} Submit ticket
                                </button>
                            </div>
                        </div>
                    </form>
                )}

                <section className="rounded-3xl border border-border bg-card">
                    <h2 className="border-b border-border px-6 py-4 font-display text-lg font-semibold text-foreground">Your tickets</h2>
                    {tickets.data.length === 0 ? (
                        <p className="px-6 py-10 text-center text-sm text-muted-foreground">No tickets yet. If something goes wrong, raise one above and we’ll help.</p>
                    ) : (
                        <ul className="divide-y divide-border">
                            {tickets.data.map((t) => (
                                <li key={t.uuid}>
                                    <Link href={`/account/support/${t.uuid}`} className="flex items-center gap-4 px-6 py-4 transition hover:bg-muted/40">
                                        <span className="min-w-0 flex-1">
                                            <span className="flex flex-wrap items-center gap-2">
                                                <span className="font-mono text-xs text-muted-foreground">{t.number}</span>
                                                <span className={cn('rounded-full px-2 py-0.5 text-xs font-semibold', STATUS_TONE[t.status])}>{t.statusLabel}</span>
                                                {t.awaitingYou && <span className="text-xs font-semibold text-amber-600 dark:text-amber-300">Reply needed</span>}
                                            </span>
                                            <span className="mt-1 block truncate font-semibold text-foreground">{t.subject}</span>
                                            <span className="block text-xs text-muted-foreground">
                                                {t.category} · {t.priority} · updated {t.updatedAt}
                                            </span>
                                        </span>
                                        <ChevronRight className="size-5 text-muted-foreground" />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}

export function FilePicker({ files, onChange, limits, error }: { files: File[]; onChange: (f: File[]) => void; limits: { files: number; kb: number }; error?: string }) {
    return (
        <div>
            <label className="inline-flex cursor-pointer items-center gap-2 rounded-xl border border-dashed border-border px-3 py-2 text-sm text-muted-foreground hover:border-volt/50 hover:text-foreground">
                <Paperclip className="size-4" /> Attach photos or PDF
                <input
                    type="file"
                    className="sr-only"
                    multiple
                    accept=".jpg,.jpeg,.png,.pdf"
                    onChange={(e) => onChange([...files, ...Array.from(e.target.files ?? [])].slice(0, limits.files))}
                />
            </label>
            <span className="ml-2 text-xs text-muted-foreground">
                Up to {limits.files} files, {Math.round(limits.kb / 1024)} MB each
            </span>
            {files.length > 0 && (
                <ul className="mt-2 flex flex-wrap gap-2">
                    {files.map((f, i) => (
                        <li key={i} className="flex items-center gap-1.5 rounded-lg bg-muted px-2 py-1 text-xs">
                            {f.name}
                            <button type="button" aria-label={`Remove ${f.name}`} onClick={() => onChange(files.filter((_, j) => j !== i))}>
                                <X className="size-3.5" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            {error && <span className="mt-1 block text-xs text-destructive">{error}</span>}
        </div>
    );
}
