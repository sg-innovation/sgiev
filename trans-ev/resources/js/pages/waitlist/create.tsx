import SiteLayout from '@/layouts/site-layout';
import { cn } from '@/lib/utils';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Hourglass, LoaderCircle } from 'lucide-react';

interface Opt { value: string; label: string }
interface Vehicle { make: string; model: string; registration_no: string; battery_kwh: number; connector: string }
interface Props {
    station: { name: string; slug: string; city: string; stateCode: string };
    dates: Opt[];
    durations: number[];
    connectors: Opt[];
    vehicles: Vehicle[];
    customer: { name: string; email: string; phone: string | null };
    states: Opt[];
    rules: { id: number; version: number; html: string } | null;
    claimMinutes: number;
    prefill: { date: string | null; duration: number };
}

const TIMES = Array.from({ length: 48 }, (_, i) => `${String(Math.floor(i / 2)).padStart(2, '0')}:${i % 2 ? '30' : '00'}`);
const label = (t: string) => {
    const [h, m] = t.split(':').map(Number);
    return `${((h + 11) % 12) + 1}:${String(m).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`;
};

/** C7: join a station's waiting list for a day and time window. */
export default function WaitlistCreate(p: Props) {
    const v = p.vehicles[0];
    const form = useForm({
        date: p.prefill.date && p.dates.some((d) => d.value === p.prefill.date) ? p.prefill.date : (p.dates[0]?.value ?? ''),
        window_from: '09:00', window_to: '18:00',
        duration: p.durations.includes(p.prefill.duration) ? p.prefill.duration : (p.durations[0] ?? 60),
        connector: v?.connector && p.connectors.some((c) => c.value === v.connector) ? v.connector : (p.connectors[0]?.value ?? ''),
        make: v?.make ?? '', model: v?.model ?? '', registration_no: v?.registration_no ?? '', battery_kwh: v ? String(v.battery_kwh) : '',
        battery_from: 20, battery_to: 80,
        name: p.customer.name, email: p.customer.email, phone: p.customer.phone ?? '', gstin: '', state_code: p.station.stateCode,
        accept_rules: false as boolean,
    });
    const input = 'mt-1 h-11 w-full rounded-xl border border-border bg-background px-3 text-foreground';
    const err = (k: keyof typeof form.data) => form.errors[k] && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{form.errors[k]}</p>;

    return (
        <SiteLayout>
            <Head title={`Waiting list · ${p.station.name}`} />
            <div className="mx-auto w-full max-w-2xl px-4 py-10">
                <Link href={`/book/${p.station.slug}`} className="inline-flex items-center gap-1.5 text-sm font-semibold text-muted-foreground hover:text-foreground">
                    <ArrowLeft className="size-4" /> Back to booking
                </Link>
                <div className="mt-4 flex items-start gap-3">
                    <div className="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-electric/15 text-electric"><Hourglass className="size-6" /></div>
                    <div>
                        <h1 className="font-display text-2xl font-bold text-foreground">Join the waiting list</h1>
                        <p className="text-sm text-muted-foreground">
                            {p.station.name}, {p.station.city}. If a slot that suits you frees up, we hold it for you for {p.claimMinutes} minutes and email you. Pay in time to confirm it.
                        </p>
                    </div>
                </div>

                <form className="mt-8 space-y-8" onSubmit={(e) => { e.preventDefault(); form.post(`/stations/${p.station.slug}/waitlist`); }}>
                    <section className="space-y-4 rounded-3xl border border-border bg-card p-5">
                        <h2 className="font-semibold text-foreground">When</h2>
                        <div className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1">
                            {p.dates.map((d) => (
                                <button key={d.value} type="button" onClick={() => form.setData('date', d.value)}
                                    className={cn('h-10 shrink-0 rounded-full border px-4 text-sm font-semibold', form.data.date === d.value ? 'border-foreground bg-foreground text-background' : 'border-border text-foreground')}>
                                    {d.label}
                                </button>
                            ))}
                        </div>
                        {err('date')}
                        <div className="grid grid-cols-3 gap-3">
                            <label className="text-sm font-medium text-foreground">Earliest start
                                <select value={form.data.window_from} onChange={(e) => form.setData('window_from', e.target.value)} className={input}>{TIMES.map((t) => <option key={t} value={t}>{label(t)}</option>)}</select>
                            </label>
                            <label className="text-sm font-medium text-foreground">Latest start
                                <select value={form.data.window_to} onChange={(e) => form.setData('window_to', e.target.value)} className={input}>{TIMES.map((t) => <option key={t} value={t}>{label(t)}</option>)}</select>
                            </label>
                            <label className="text-sm font-medium text-foreground">Length
                                <select value={form.data.duration} onChange={(e) => form.setData('duration', Number(e.target.value))} className={input}>{p.durations.map((d) => <option key={d} value={d}>{d} min</option>)}</select>
                            </label>
                        </div>
                        {err('window_to')}{err('duration')}
                    </section>

                    <section className="space-y-4 rounded-3xl border border-border bg-card p-5">
                        <h2 className="font-semibold text-foreground">Vehicle</h2>
                        {p.vehicles.length > 1 && (
                            <div className="flex flex-wrap gap-2">
                                {p.vehicles.map((x) => (
                                    <button key={x.registration_no} type="button" onClick={() => form.setData({ ...form.data, make: x.make, model: x.model, registration_no: x.registration_no, battery_kwh: String(x.battery_kwh), connector: x.connector })}
                                        className={cn('rounded-full border px-3 py-1.5 text-sm', form.data.registration_no === x.registration_no ? 'border-foreground font-semibold text-foreground' : 'border-border text-muted-foreground')}>
                                        {x.make} {x.model} · {x.registration_no}
                                    </button>
                                ))}
                            </div>
                        )}
                        <div className="grid grid-cols-2 gap-3">
                            <label className="text-sm font-medium text-foreground">Make<input value={form.data.make} onChange={(e) => form.setData('make', e.target.value)} className={input} />{err('make')}</label>
                            <label className="text-sm font-medium text-foreground">Model<input value={form.data.model} onChange={(e) => form.setData('model', e.target.value)} className={input} />{err('model')}</label>
                            <label className="text-sm font-medium text-foreground">Number plate<input value={form.data.registration_no} onChange={(e) => form.setData('registration_no', e.target.value.toUpperCase())} className={cn(input, 'font-mono')} />{err('registration_no')}</label>
                            <label className="text-sm font-medium text-foreground">Battery (kWh)<input inputMode="decimal" value={form.data.battery_kwh} onChange={(e) => form.setData('battery_kwh', e.target.value)} className={input} />{err('battery_kwh')}</label>
                            <label className="text-sm font-medium text-foreground">Connector
                                <select value={form.data.connector} onChange={(e) => form.setData('connector', e.target.value)} className={input}>{p.connectors.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}</select>
                                {err('connector')}
                            </label>
                            <div className="grid grid-cols-2 gap-2">
                                <label className="text-sm font-medium text-foreground">Now %<input inputMode="numeric" value={form.data.battery_from} onChange={(e) => form.setData('battery_from', Number(e.target.value.replace(/\D/g, '')))} className={input} /></label>
                                <label className="text-sm font-medium text-foreground">Target %<input inputMode="numeric" value={form.data.battery_to} onChange={(e) => form.setData('battery_to', Number(e.target.value.replace(/\D/g, '')))} className={input} /></label>
                            </div>
                        </div>
                        {err('battery_to')}
                    </section>

                    <section className="space-y-4 rounded-3xl border border-border bg-card p-5">
                        <h2 className="font-semibold text-foreground">Your details</h2>
                        <div className="grid grid-cols-2 gap-3">
                            <label className="text-sm font-medium text-foreground">Name<input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} className={input} />{err('name')}</label>
                            <label className="text-sm font-medium text-foreground">Mobile<input inputMode="numeric" maxLength={10} value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value.replace(/\D/g, ''))} className={input} />{err('phone')}</label>
                            <label className="text-sm font-medium text-foreground">Email<input type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} className={input} />{err('email')}</label>
                            <label className="text-sm font-medium text-foreground">State (for GST)
                                <select value={form.data.state_code} onChange={(e) => form.setData('state_code', e.target.value)} className={input}>{p.states.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}</select>
                            </label>
                            <label className="col-span-2 text-sm font-medium text-foreground">GSTIN <span className="font-normal text-muted-foreground">for business invoices, optional</span>
                                <input value={form.data.gstin} onChange={(e) => form.setData('gstin', e.target.value.toUpperCase())} maxLength={15} className={cn(input, 'font-mono')} />{err('gstin')}
                            </label>
                        </div>
                    </section>

                    {p.rules && (
                        <section className="rounded-3xl border border-border bg-card p-5">
                            <h2 className="font-semibold text-foreground">Station rules (v{p.rules.version})</h2>
                            <div className="prose prose-sm mt-3 max-h-48 overflow-y-auto text-muted-foreground dark:prose-invert" dangerouslySetInnerHTML={{ __html: p.rules.html }} />
                            <label className="mt-4 flex items-start gap-3 text-sm text-foreground">
                                <input type="checkbox" checked={form.data.accept_rules} onChange={(e) => form.setData('accept_rules', e.target.checked)} className="mt-0.5 size-5" />
                                I have read and accept the station rules.
                            </label>
                            {err('accept_rules')}
                        </section>
                    )}

                    <button type="submit" disabled={form.processing} className="flex h-12 w-full items-center justify-center gap-2 rounded-full bg-gradient-to-r from-volt-600 to-electric font-semibold text-white disabled:opacity-60">
                        {form.processing ? <LoaderCircle className="size-5 animate-spin" /> : <Hourglass className="size-5" />} Join the waiting list
                    </button>
                </form>
            </div>
        </SiteLayout>
    );
}
