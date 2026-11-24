import InputError from '@/components/input-error';
import { StatusPill, inr, stationState } from '@/components/site/station-bits';
import CancellationPolicyCard, { type PolicyView } from '@/components/site/cancellation-policy';
import SiteLayout from '@/layouts/site-layout';
import { getJson, postJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import { type Option, type StationDetail } from '@/types/station';
import { Head, Link, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    BadgePercent,
    BatteryCharging,
    CalendarDays,
    Car,
    Check,
    ChevronDown,
    CheckCircle2,
    Clock,
    FileText,
    LoaderCircle,
    Lock,
    MapPin,
    ScrollText,
    ShieldCheck,
    TicketPercent,
    User,
    Zap,
} from 'lucide-react';
import { type ReactNode, useEffect, useMemo, useRef, useState } from 'react';

interface Unit {
    id: number;
    no: number;
    type: string;
    typeLabel: string;
    powerKw: number;
    bookable: boolean;
    liveStatus: string;
    liveLabel: string;
}
interface ChargerOpt {
    units: Unit[];
    id: number;
    code: string;
    type: 'AC' | 'DC';
    powerKw: number;
    connectors: Option[];
    online: boolean;
}
interface Slot {
    start: string;
    end: string;
    label: string;
    available: boolean;
    reason: string | null;
}
interface Estimate {
    kwh: number;
    capped_by_slot: boolean;
    minutes_needed: number;
    rate_per_kwh: number;
    energy_amount: number;
    session_fee: number;
    subtotal_amount: number;
    discount_amount: number;
    coupon_code: string | null;
    coupon_headline: string | null;
    discount_funded_by: 'platform' | 'operator' | null;
    coupon_error: string | null;
    lines: { type: string; label: string; qty: number; unit: string; rate: number; amount: number; minutes?: number }[];
    time_of_day: boolean;
    parking_amount: number;
    taxable_amount: number;
    gst_rate: number;
    cgst_amount: number;
    sgst_amount: number;
    igst_amount: number;
    total_amount: number;
    intra_state: boolean;
    tariff_name: string;
    idle_fee_per_min: number;
    idle_grace_minutes: number;
}
interface Props {
    station: StationDetail;
    chargers: ChargerOpt[];
    dates: { value: string; day: string; date: string }[];
    durations: number[];
    rules: { id: number; version: number; title: string; content: string };
    connectors: Option[];
    states: Option[];
    vehicles: { id: number; make: string; model: string; registration_no: string; battery_kwh: number; connector: string }[];
    customer: { name: string; email: string; phone: string; gstin: string; state_code: string };
    holdMinutes: number;
    offers: Offer[];
    evModels: { make: string; model: string; battery: number; connector: string }[];
    cancellationPolicy: PolicyView | null;
}
interface Offer {
    code: string;
    name: string;
    headline: string;
    description: string | null;
    endsAt: string | null;
    chargerType: 'AC' | 'DC' | null;
    firstBookingOnly: boolean;
}

const STEPS = [
    { key: 'slot', label: 'Charger & time', icon: CalendarDays },
    { key: 'vehicle', label: 'Vehicle', icon: Car },
    { key: 'details', label: 'Your details', icon: User },
    { key: 'rules', label: 'Station rules', icon: ScrollText },
    { key: 'review', label: 'Review', icon: FileText },
] as const;

const fmtDuration = (m: number) => (m % 60 === 0 ? `${m / 60} hr${m > 60 ? 's' : ''}` : `${m} min`);

export default function BookingCreate({ station, chargers, dates, durations, rules, connectors, states, vehicles, customer, holdMinutes, offers = [], evModels = [], cancellationPolicy = null }: Props) {
    const [step, setStep] = useState(0);
    const [date, setDate] = useState(dates[0].value);
    const [grid, setGrid] = useState<{ charger_id: number; connector_id: number; slots: Slot[] }[] | null>(null);
    const [gridLoading, setGridLoading] = useState(false);
    const [estimate, setEstimate] = useState<Estimate | null>(null);
    const [rulesRead, setRulesRead] = useState(false);
    const rulesBox = useRef<HTMLDivElement>(null);

    const firstOnline = chargers.find((c) => c.online && c.units.some((u) => u.bookable));
    const firstUnit = firstOnline?.units.find((u) => u.bookable);
    const v0 = vehicles[0];
    const form = useForm({
        charger_id: firstOnline?.id ?? 0,
        connector_id: firstUnit?.id ?? 0,
        start: '',
        duration: durations[0],
        make: v0?.make ?? '',
        model: v0?.model ?? '',
        registration_no: v0?.registration_no ?? '',
        battery_kwh: v0?.battery_kwh ?? 40,
        connector: firstUnit?.type ?? 'ccs2',
        battery_from: 20,
        battery_to: 80,
        save_vehicle: vehicles.length === 0,
        name: customer.name,
        email: customer.email,
        phone: customer.phone,
        gstin: customer.gstin,
        state_code: customer.state_code,
        rule_set_id: rules.id,
        accept_rules: false as boolean,
        coupon_code: '',
    });
    const [couponInput, setCouponInput] = useState('');
    const [couponError, setCouponError] = useState<string | null>(null);
    const { data, setData, errors } = form;
    const charger = chargers.find((c) => c.id === data.charger_id);
    const unit = charger?.units.find((u) => u.id === data.connector_id);

    // Availability grid
    useEffect(() => {
        const ctrl = new AbortController();
        setGridLoading(true);
        getJson<{ chargers: { charger_id: number; connector_id: number; slots: Slot[] }[] }>(`/book/${station.slug}/availability?date=${date}&duration=${data.duration}`, ctrl.signal)
            .then((r) => setGrid(r.chargers))
            .catch(() => {})
            .finally(() => setGridLoading(false));
        return () => ctrl.abort();
    }, [date, data.duration, station.slug]);

    // Clear a selected slot that is no longer valid
    useEffect(() => setData('start', ''), [date, data.duration, data.connector_id]); // eslint-disable-line react-hooks/exhaustive-deps

    // Live estimate
    useEffect(() => {
        if (!data.charger_id || !data.battery_kwh || data.battery_to <= data.battery_from) return;
        const ctrl = new AbortController();
        const t = setTimeout(() => {
            postJson<Estimate>(
                `/book/${station.slug}/estimate`,
                { charger_id: data.charger_id, battery_kwh: data.battery_kwh, battery_from: data.battery_from, battery_to: data.battery_to, duration: data.duration, coupon_code: data.coupon_code || null, start: data.start || null },
                ctrl.signal,
            )
                .then((e) => {
                    setEstimate(e);
                    if (e.coupon_error) {
                        // Never submit a code the server rejected; keep the message visible.
                        setCouponError(e.coupon_error);
                        setData('coupon_code', '');
                    }
                })
                .catch(() => {});
        }, 250);
        return () => {
            clearTimeout(t);
            ctrl.abort();
        };
    }, [data.charger_id, data.battery_kwh, data.battery_from, data.battery_to, data.duration, data.coupon_code, data.start, station.slug]); // eslint-disable-line react-hooks/exhaustive-deps

    const slots = grid?.find((g) => g.connector_id === data.connector_id)?.slots ?? [];
    const selectedSlot = slots.find((s) => s.start === data.start);
    const freeCount = (id: number) => grid?.find((g) => g.connector_id === id)?.slots.filter((s) => s.available).length ?? 0;

    const stepValid = useMemo(
        () => [
            !!data.start,
            !!(data.make && data.model && data.registration_no && data.battery_kwh && unit?.type === data.connector && data.battery_to > data.battery_from),
            !!(data.name && data.email && /^[6-9]\d{9}$/.test(data.phone) && data.state_code),
            data.accept_rules,
            true,
        ],
        [data, charger],
    );

    const submit = () => {
        form.post(`/book/${station.slug}`, {
            preserveScroll: true,
            onError: (e) => {
                const keys = Object.keys(e);
                if (keys.some((k) => ['start', 'charger_id', 'connector_id', 'duration'].includes(k))) setStep(0);
                else if (keys.some((k) => ['make', 'model', 'registration_no', 'battery_kwh', 'connector', 'battery_from', 'battery_to'].includes(k))) setStep(1);
                else if (keys.some((k) => ['name', 'email', 'phone', 'gstin', 'state_code'].includes(k))) setStep(2);
                else if (keys.some((k) => ['accept_rules', 'rules', 'rule_set_id'].includes(k))) setStep(3);
                if (e.coupon_code) {
                    setCouponError(e.coupon_code);
                    setData('coupon_code', '');
                }
            },
        });
    };

    const onRulesScroll = () => {
        const el = rulesBox.current;
        if (el && el.scrollTop + el.clientHeight >= el.scrollHeight - 12) setRulesRead(true);
    };
    useEffect(() => {
        // Short rule texts that need no scrolling count as read.
        if (step === 3) requestAnimationFrame(onRulesScroll);
    }, [step]);

    const state = stationState(station);

    return (
        <SiteLayout>
            <Head title={`Book a slot – ${station.name}`} />

            {/* Header */}
            <section className="relative overflow-hidden bg-ink text-white">
                <div className="pointer-events-none absolute -top-24 -right-24 size-96 rounded-full bg-volt/25 blur-[100px]" />
                <div className="relative mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
                    <Link href={`/stations/${station.slug}`} className="inline-flex items-center gap-1.5 text-sm text-white/70 hover:text-white">
                        <ArrowLeft className="size-4" /> Back to station
                    </Link>
                    <div className="mt-3 flex flex-wrap items-center gap-3">
                        <h1 className="font-display text-2xl font-bold sm:text-3xl">Book a charging slot</h1>
                        <StatusPill state={state} className="bg-white/10 text-white ring-white/20" />
                    </div>
                    <p className="mt-1 flex items-center gap-1.5 text-white/70">
                        <MapPin className="size-4" /> {station.name} · {station.city}
                    </p>

                    {/* Stepper */}
                    <ol className="mt-6 grid grid-cols-5 gap-2">
                        {STEPS.map((s, i) => (
                            <li key={s.key}>
                                <button
                                    type="button"
                                    disabled={i > step && !stepValid.slice(0, i).every(Boolean)}
                                    onClick={() => setStep(i)}
                                    className="group w-full text-left disabled:cursor-not-allowed"
                                >
                                    <span className={cn('block h-1.5 rounded-full transition', i <= step ? 'bg-gradient-to-r from-volt to-electric' : 'bg-white/15')} />
                                    <span className={cn('mt-2 flex items-center gap-1.5 text-xs font-medium sm:text-sm', i === step ? 'text-white' : 'text-white/55')}>
                                        {i < step && stepValid[i] ? <CheckCircle2 className="size-4 text-volt" /> : <s.icon className="size-4" />}
                                        <span className="hidden sm:inline">{s.label}</span>
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ol>
                </div>
            </section>

            <div className="mx-auto grid max-w-7xl gap-8 px-4 py-8 sm:px-6 lg:grid-cols-[1fr_380px] lg:px-8">
                <div className="min-w-0">
                    {/* STEP 1: slot */}
                    {step === 0 && (
                        <Panel title="Choose charger, date and time" subtitle="Times are in IST. Your slot is held for you while you pay.">
                            <Label>Charger &amp; connector</Label>
                            <div className="space-y-3">
                                {chargers.map((c) => (
                                    <div key={c.id} className={cn('rounded-2xl border p-4 transition', data.charger_id === c.id ? 'border-volt/60 bg-volt/5' : 'border-border bg-card')}>
                                        <div className="flex items-center gap-3">
                                            <span className={cn('flex size-10 shrink-0 items-center justify-center rounded-xl', c.type === 'DC' ? 'bg-volt/15 text-volt-600' : 'bg-electric/10 text-electric')}>
                                                <Zap className="size-5" />
                                            </span>
                                            <div className="min-w-0 flex-1">
                                                <p className="font-semibold text-foreground">{c.type} · {c.powerKw} kW</p>
                                                <p className="text-xs text-muted-foreground">{c.code}{!c.online && ' · charger unavailable'}</p>
                                            </div>
                                        </div>
                                        <div className="mt-3 grid gap-2 sm:grid-cols-2">
                                            {c.units.map((u) => {
                                                const selected = data.connector_id === u.id;
                                                const ok = c.online && u.bookable;
                                                return (
                                                    <button
                                                        key={u.id}
                                                        type="button"
                                                        disabled={!ok}
                                                        onClick={() => setData((d) => ({ ...d, charger_id: c.id, connector_id: u.id, connector: u.type }))}
                                                        className={cn(
                                                            'flex items-center justify-between gap-3 rounded-xl border px-3 py-2.5 text-left text-sm transition disabled:cursor-not-allowed disabled:opacity-50',
                                                            selected ? 'border-volt bg-card ring-2 ring-volt/30' : 'border-border bg-background hover:border-volt/50',
                                                        )}
                                                    >
                                                        <span>
                                                            <span className="block font-semibold text-foreground">Gun {u.no} · {u.typeLabel}</span>
                                                            <span className="block text-xs text-muted-foreground">up to {u.powerKw} kW · now: {u.liveLabel}</span>
                                                        </span>
                                                        <span className="shrink-0 text-xs font-semibold text-volt-600">{ok ? (grid ? `${freeCount(u.id)} free` : '…') : 'Unavailable'}</span>
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>
                                ))}
                            </div>

                            <Label className="mt-7">Date</Label>
                            <div className="flex gap-2 overflow-x-auto pb-1">
                                {dates.map((d, i) => (
                                    <button
                                        key={d.value}
                                        type="button"
                                        onClick={() => setDate(d.value)}
                                        className={cn(
                                            'flex min-w-[76px] shrink-0 flex-col items-center rounded-2xl border px-3 py-2.5 transition',
                                            date === d.value ? 'border-transparent bg-ink text-white shadow-lg dark:bg-volt dark:text-ink' : 'border-border bg-card text-foreground hover:border-volt/50',
                                        )}
                                    >
                                        <span className="text-xs opacity-70">{i === 0 ? 'Today' : i === 1 ? 'Tomorrow' : d.day}</span>
                                        <span className="font-display font-semibold">{d.date}</span>
                                    </button>
                                ))}
                            </div>

                            <Label className="mt-7">Duration</Label>
                            <div className="flex flex-wrap gap-2">
                                {durations.map((m) => (
                                    <button
                                        key={m}
                                        type="button"
                                        onClick={() => setData('duration', m)}
                                        className={cn(
                                            'rounded-full border px-4 py-2 text-sm font-semibold transition',
                                            data.duration === m ? 'border-volt bg-volt/10 text-volt-600' : 'border-border bg-card text-foreground hover:border-volt/50',
                                        )}
                                    >
                                        <Clock className="mr-1.5 inline size-4" />
                                        {fmtDuration(m)}
                                    </button>
                                ))}
                            </div>

                            <Label className="mt-7">Start time</Label>
                            {gridLoading && !grid ? (
                                <div className="grid grid-cols-3 gap-2 sm:grid-cols-5 md:grid-cols-6">
                                    {Array.from({ length: 12 }).map((_, i) => (
                                        <div key={i} className="h-11 animate-pulse rounded-xl bg-muted" />
                                    ))}
                                </div>
                            ) : slots.length === 0 ? (
                                <div className="rounded-2xl border border-dashed border-border p-6 text-center text-sm text-muted-foreground">The station is closed on this day. Please choose another date.</div>
                            ) : (
                                <div className={cn('grid grid-cols-3 gap-2 transition sm:grid-cols-5 md:grid-cols-6', gridLoading && 'opacity-60')}>
                                    {slots.map((s) => (
                                        <button
                                            key={s.start}
                                            type="button"
                                            disabled={!s.available}
                                            title={s.reason ?? 'Available'}
                                            onClick={() => setData('start', s.start)}
                                            className={cn(
                                                'h-11 rounded-xl border text-sm font-semibold transition',
                                                data.start === s.start
                                                    ? 'border-transparent bg-gradient-to-r from-volt-600 to-electric text-white shadow-lg shadow-volt/25'
                                                    : s.available
                                                      ? 'border-border bg-card text-foreground hover:border-volt hover:text-volt-600'
                                                      : 'cursor-not-allowed border-transparent bg-muted text-muted-foreground/50 line-through',
                                            )}
                                        >
                                            {s.label}
                                        </button>
                                    ))}
                                </div>
                            )}
                            <InputError message={errors.start} className="mt-3" />
                        </Panel>
                    )}

                    {/* STEP 2: vehicle */}
                    {step === 1 && (
                        <Panel title="Your vehicle" subtitle="Used to check connector compatibility and estimate your charge.">
                            {vehicles.length > 0 && (
                                <>
                                    <Label>Saved vehicles</Label>
                                    <div className="mb-6 flex flex-wrap gap-2">
                                        {vehicles.map((v) => (
                                            <button
                                                key={v.id}
                                                type="button"
                                                onClick={() => setData((d) => ({ ...d, make: v.make, model: v.model, registration_no: v.registration_no, battery_kwh: v.battery_kwh, connector: v.connector, save_vehicle: false }))}
                                                className={cn('rounded-full border px-4 py-2 text-sm font-medium', data.registration_no === v.registration_no ? 'border-volt bg-volt/10 text-volt-600' : 'border-border bg-card')}
                                            >
                                                {v.make} {v.model} · {v.registration_no}
                                            </button>
                                        ))}
                                    </div>
                                </>
                            )}

                            <Label>Popular EVs</Label>
                            <div className="mb-6 flex flex-wrap gap-2">
                                {evModels.map((v) => (
                                    <button
                                        key={v.model}
                                        type="button"
                                        onClick={() => setData((d) => ({ ...d, make: v.make, model: v.model, battery_kwh: v.battery, connector: unit?.type ?? d.connector }))}
                                        className="rounded-full border border-border bg-card px-3 py-1.5 text-xs font-medium text-muted-foreground hover:border-volt/50 hover:text-foreground"
                                    >
                                        {v.make} {v.model}
                                    </button>
                                ))}
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Make" error={errors.make}>
                                    <input className={inputCls} value={data.make} onChange={(e) => setData('make', e.target.value)} placeholder="Tata" maxLength={60} />
                                </Field>
                                <Field label="Model" error={errors.model}>
                                    <input className={inputCls} value={data.model} onChange={(e) => setData('model', e.target.value)} placeholder="Nexon EV" maxLength={80} />
                                </Field>
                                <Field label="Registration number" error={errors.registration_no}>
                                    <input
                                        className={cn(inputCls, 'uppercase')}
                                        value={data.registration_no}
                                        onChange={(e) => setData('registration_no', e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 11))}
                                        placeholder="MH12AB1234"
                                    />
                                </Field>
                                <Field label="Battery capacity (kWh)" error={errors.battery_kwh}>
                                    <input type="number" min={1} max={200} step={0.1} className={inputCls} value={data.battery_kwh} onChange={(e) => setData('battery_kwh', Number(e.target.value))} />
                                </Field>
                                <Field label="Connector" error={errors.connector} className="sm:col-span-2">
                                    <div className="flex flex-wrap gap-2">
                                        {connectors.map((c) => {
                                            const supported = unit?.type === c.value;
                                            return (
                                                <button
                                                    key={c.value}
                                                    type="button"
                                                    disabled={!supported}
                                                    onClick={() => setData('connector', c.value)}
                                                    className={cn(
                                                        'rounded-xl border px-3 py-2 text-sm font-medium transition disabled:cursor-not-allowed disabled:opacity-40',
                                                        data.connector === c.value ? 'border-volt bg-volt/10 text-volt-600' : 'border-border bg-card',
                                                    )}
                                                >
                                                    {c.label}
                                                </button>
                                            );
                                        })}
                                    </div>
                                    <p className="mt-1.5 text-xs text-muted-foreground">Set by the gun you chose in step 1. To use another connector type, go back and pick a different gun.</p>
                                </Field>
                            </div>

                            <div className="mt-6 grid gap-6 rounded-2xl bg-muted/60 p-5 sm:grid-cols-2">
                                <RangeField label="Current battery" value={data.battery_from} onChange={(v) => setData('battery_from', Math.min(v, data.battery_to - 5))} />
                                <RangeField label="Charge up to" value={data.battery_to} onChange={(v) => setData('battery_to', Math.max(v, data.battery_from + 5))} />
                                <InputError message={errors.battery_to} />
                            </div>

                            <label className="mt-5 flex items-center gap-3 text-sm text-foreground">
                                <input type="checkbox" className="size-4 accent-[#00B386]" checked={data.save_vehicle} onChange={(e) => setData('save_vehicle', e.target.checked)} />
                                Save this vehicle to my account
                            </label>
                        </Panel>
                    )}

                    {/* STEP 3: details */}
                    {step === 2 && (
                        <Panel title="Your details" subtitle="These appear on your receipt and GST tax invoice.">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Full name" error={errors.name}>
                                    <input className={inputCls} value={data.name} onChange={(e) => setData('name', e.target.value)} autoComplete="name" />
                                </Field>
                                <Field label="Email" error={errors.email}>
                                    <input type="email" className={inputCls} value={data.email} onChange={(e) => setData('email', e.target.value)} autoComplete="email" />
                                </Field>
                                <Field label="Mobile number" error={errors.phone}>
                                    <div className="flex">
                                        <span className="flex items-center rounded-l-xl border border-r-0 border-input bg-muted px-3 text-sm text-muted-foreground">+91</span>
                                        <input
                                            inputMode="numeric"
                                            className={cn(inputCls, 'rounded-l-none')}
                                            value={data.phone}
                                            onChange={(e) => setData('phone', e.target.value.replace(/\D/g, '').slice(0, 10))}
                                            placeholder="98765 43210"
                                            autoComplete="tel-national"
                                        />
                                    </div>
                                </Field>
                                <Field label="State (for invoice)" error={errors.state_code}>
                                    <select className={inputCls} value={data.state_code} onChange={(e) => setData('state_code', e.target.value)}>
                                        {states.map((s) => (
                                            <option key={s.value} value={s.value}>
                                                {s.label}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                                <Field label="GSTIN (optional, for business invoice)" error={errors.gstin} className="sm:col-span-2">
                                    <input
                                        className={cn(inputCls, 'uppercase')}
                                        value={data.gstin}
                                        onChange={(e) => setData('gstin', e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 15))}
                                        placeholder="22AAAAA0000A1Z5"
                                    />
                                    <p className="mt-1.5 text-xs text-muted-foreground">Add your GSTIN to claim input tax credit. Leave empty for a personal invoice.</p>
                                </Field>
                            </div>
                        </Panel>
                    )}

                    {/* STEP 4: rules */}
                    {step === 3 && (
                        <Panel title={rules.title} subtitle={`Version ${rules.version}. Please read to the end and accept to continue.`}>
                            <div
                                ref={rulesBox}
                                onScroll={onRulesScroll}
                                className="max-h-80 overflow-y-auto rounded-2xl border border-border bg-muted/40 p-5 text-sm leading-relaxed text-foreground [&_li]:mb-2 [&_ol]:list-decimal [&_ol]:pl-5 [&_ul]:list-disc [&_ul]:pl-5"
                                dangerouslySetInnerHTML={{ __html: rules.content }}
                            />
                            {!rulesRead && <p className="mt-2 text-xs text-muted-foreground">Scroll to the end of the rules to enable acceptance.</p>}
                            <label className={cn('mt-5 flex items-start gap-3 rounded-2xl border p-4 text-sm transition', data.accept_rules ? 'border-volt bg-volt/5' : 'border-border', !rulesRead && 'opacity-50')}>
                                <input
                                    type="checkbox"
                                    className="mt-0.5 size-5 accent-[#00B386]"
                                    disabled={!rulesRead}
                                    checked={data.accept_rules}
                                    onChange={(e) => setData('accept_rules', e.target.checked)}
                                />
                                <span className="text-foreground">
                                    I have read and accept the station rules (v{rules.version}), including the idle-fee and no-show terms. My acceptance is recorded with this booking.
                                </span>
                            </label>
                            <InputError message={errors.accept_rules || (errors as Record<string, string>).rules} className="mt-2" />
                        </Panel>
                    )}

                    {/* STEP 5: review */}
                    {step === 4 && (
                        <Panel title="Review your booking" subtitle={`Your slot will be held for ${holdMinutes} minutes while you pay.`}>
                            <dl className="grid gap-4 sm:grid-cols-2">
                                <Review label="Station" value={station.name} sub={station.address} />
                                <Review label="Charger" value={`${charger?.type} · ${charger?.powerKw} kW`} sub={charger?.code} />
                                <Review
                                    label="Date & time"
                                    value={dates.find((d) => d.value === date)?.date + ' · ' + (selectedSlot?.label ?? '—')}
                                    sub={`${fmtDuration(data.duration)} slot`}
                                />
                                <Review label="Vehicle" value={`${data.make} ${data.model}`} sub={`${data.registration_no} · ${connectors.find((c) => c.value === data.connector)?.label}`} />
                                <Review label="Charge" value={`${data.battery_from}% → ${data.battery_to}%`} sub={`${data.battery_kwh} kWh battery`} />
                                <Review label="Invoice to" value={data.name} sub={data.gstin ? `GSTIN ${data.gstin}` : data.email} />
                            </dl>
                            <CancellationPolicyCard policy={cancellationPolicy} title="Cancellation policy for this booking" className="mt-6" />
                            <div className="mt-6 flex items-start gap-3 rounded-2xl bg-electric/5 p-4 text-sm text-foreground ring-1 ring-electric/20">
                                <Lock className="mt-0.5 size-5 shrink-0 text-electric" />
                                Next you will reserve the slot and continue to secure online payment. You can cancel a reserved slot any time before paying.
                            </div>
                            {Object.keys(errors).length > 0 && (
                                <div className="mt-4 rounded-xl bg-destructive/10 p-3 text-sm text-destructive">{Object.values(errors)[0]}</div>
                            )}
                        </Panel>
                    )}

                    {/* Navigation */}
                    <div className="mt-6 flex items-center justify-between">
                        <button
                            type="button"
                            onClick={() => setStep((s) => Math.max(0, s - 1))}
                            className={cn('inline-flex items-center gap-2 rounded-full px-5 py-3 font-semibold text-foreground hover:bg-muted', step === 0 && 'invisible')}
                        >
                            <ArrowLeft className="size-4" /> Back
                        </button>
                        {step < 4 ? (
                            <button
                                type="button"
                                disabled={!stepValid[step]}
                                onClick={() => setStep((s) => s + 1)}
                                className="inline-flex items-center gap-2 rounded-full bg-ink px-7 py-3 font-semibold text-white transition hover:bg-volt-600 disabled:opacity-40 dark:bg-volt dark:text-ink"
                            >
                                Continue <ArrowRight className="size-4" />
                            </button>
                        ) : (
                            <button
                                type="button"
                                disabled={form.processing || !stepValid.every(Boolean)}
                                onClick={submit}
                                className="inline-flex items-center gap-2 rounded-full bg-gradient-to-r from-volt-600 to-electric px-7 py-3.5 font-semibold text-white shadow-xl shadow-volt/30 transition hover:brightness-110 disabled:opacity-50"
                            >
                                {form.processing ? <LoaderCircle className="size-5 animate-spin" /> : <ShieldCheck className="size-5" />}
                                Reserve slot{estimate ? ` · ${inr(estimate.total_amount)}` : ''}
                            </button>
                        )}
                    </div>
                </div>

                {/* Summary */}
                <aside className="lg:sticky lg:top-24 lg:self-start">
                    <div className="overflow-hidden rounded-3xl border border-border bg-card shadow-xl shadow-black/5">
                        <div className="bg-gradient-to-br from-ink to-[#0d2a45] p-5 text-white">
                            <p className="text-xs text-white/60">Booking summary</p>
                            <p className="mt-1 font-display text-lg font-semibold">{station.name}</p>
                            <p className="text-sm text-white/70">
                                {charger ? `${charger.type} ${charger.powerKw} kW` : 'Choose a charger'}
                                {selectedSlot && ` · ${dates.find((d) => d.value === date)?.date}, ${selectedSlot.label}`}
                            </p>
                        </div>
                        <div className="space-y-3 p-5 text-sm">
                            {estimate ? (
                                <>
                                    {estimate.lines.map((l, i) => (
                                        <Row key={i} label={l.type === 'energy' ? `${l.label} · ${l.qty.toFixed(2)} kWh × ${inr(l.rate)}` : l.label} value={inr(l.amount)} />
                                    ))}
                                    {estimate.time_of_day && !data.start && (
                                        <p className="rounded-lg bg-amber-500/10 px-2.5 py-1.5 text-xs text-amber-800 dark:text-amber-200">Prices change by time of day here. Pick a slot to see your exact price.</p>
                                    )}
                                    {estimate.discount_amount > 0 && (
                                        <div className="flex items-center justify-between gap-3 rounded-lg bg-volt/10 px-2.5 py-1.5 font-medium text-volt-700 dark:text-volt">
                                            <span className="flex items-center gap-1.5"><TicketPercent className="size-4" />Discount · {estimate.coupon_code}</span>
                                            <span>− {inr(estimate.discount_amount)}</span>
                                        </div>
                                    )}
                                    <Row label="Taxable value" value={inr(estimate.taxable_amount)} muted />
                                    {estimate.intra_state ? (
                                        <>
                                            <Row label={`CGST ${estimate.gst_rate / 2}%`} value={inr(estimate.cgst_amount)} />
                                            <Row label={`SGST ${estimate.gst_rate / 2}%`} value={inr(estimate.sgst_amount)} />
                                        </>
                                    ) : (
                                        <Row label={`IGST ${estimate.gst_rate}%`} value={inr(estimate.igst_amount)} />
                                    )}
                                    <div className="flex items-end justify-between border-t border-border pt-3">
                                        <span className="font-semibold text-foreground">Total payable</span>
                                        <span className="font-display text-2xl font-extrabold text-foreground">{inr(estimate.total_amount)}</span>
                                    </div>
                                    <CouponBox
                                        offers={offers}
                                        applied={data.coupon_code}
                                        headline={estimate.coupon_headline}
                                        input={couponInput}
                                        error={couponError}
                                        onInput={(v) => {
                                            setCouponInput(v.toUpperCase().replace(/[^A-Z0-9_-]/g, ''));
                                            setCouponError(null);
                                        }}
                                        onApply={(code) => {
                                            setCouponError(null);
                                            setCouponInput(code);
                                            setData('coupon_code', code);
                                        }}
                                        onRemove={() => {
                                            setData('coupon_code', '');
                                            setCouponInput('');
                                            setCouponError(null);
                                        }}
                                    />
                                    <div className="flex gap-2 rounded-xl bg-muted/60 p-3 text-xs text-muted-foreground">
                                        <BatteryCharging className="size-4 shrink-0 text-volt-600" />
                                        <span>
                                            About {estimate.minutes_needed} min to reach {data.battery_to}%.
                                            {estimate.capped_by_slot && ' Your slot is shorter than needed, so the estimate is limited to what the charger can deliver in it.'}{' '}
                                            Idle fee {inr(estimate.idle_fee_per_min)}/min after {estimate.idle_grace_minutes} min.
                                        </span>
                                    </div>
                                </>
                            ) : (
                                <p className="text-muted-foreground">Your price estimate will appear here.</p>
                            )}
                            <ul className="space-y-1.5 pt-2 text-xs text-muted-foreground">
                                {['GST tax invoice for every booking', 'Secure UPI, card & net-banking', 'Free cancellation before payment'].map((t) => (
                                    <li key={t} className="flex items-center gap-1.5">
                                        <Check className="size-3.5 text-volt-600" /> {t}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </div>
                </aside>
            </div>
        </SiteLayout>
    );
}

const inputCls =
    'h-11 w-full rounded-xl border border-input bg-background px-3.5 text-sm text-foreground outline-none transition focus:border-volt focus:ring-4 focus:ring-volt/15';

function Panel({ title, subtitle, children }: { title: string; subtitle?: string; children: ReactNode }) {
    return (
        <section className="rise-in rounded-3xl border border-border bg-card p-6 sm:p-8">
            <h2 className="font-display text-xl font-bold text-foreground">{title}</h2>
            {subtitle && <p className="mt-1 text-sm text-muted-foreground">{subtitle}</p>}
            <div className="mt-6">{children}</div>
        </section>
    );
}

function Label({ children, className }: { children: ReactNode; className?: string }) {
    return <p className={cn('mb-3 text-sm font-semibold text-foreground', className)}>{children}</p>;
}

function Field({ label, error, children, className }: { label: string; error?: string; children: ReactNode; className?: string }) {
    return (
        <label className={cn('block', className)}>
            <span className="mb-1.5 block text-sm font-medium text-foreground">{label}</span>
            {children}
            <InputError message={error} className="mt-1.5" />
        </label>
    );
}

function RangeField({ label, value, onChange }: { label: string; value: number; onChange: (v: number) => void }) {
    return (
        <label className="block">
            <span className="flex justify-between text-sm">
                <span className="font-medium text-foreground">{label}</span>
                <span className="font-display font-bold text-volt-600">{value}%</span>
            </span>
            <input type="range" min={0} max={100} value={value} onChange={(e) => onChange(Number(e.target.value))} className="mt-2 w-full accent-[#00B386]" />
        </label>
    );
}

function Review({ label, value, sub }: { label: string; value: string; sub?: string }) {
    return (
        <div className="rounded-2xl bg-muted/50 p-4">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="mt-0.5 font-semibold text-foreground">{value}</dd>
            {sub && <dd className="text-sm text-muted-foreground">{sub}</dd>}
        </div>
    );
}

function Row({ label, value, muted }: { label: string; value: string; muted?: boolean }) {
    return (
        <div className={cn('flex justify-between gap-3', muted ? 'text-muted-foreground' : 'text-foreground')}>
            <span>{label}</span>
            <span className="font-medium">{value}</span>
        </div>
    );
}

function CouponBox({ offers, applied, headline, input, error, onInput, onApply, onRemove }: {
    offers: Offer[];
    applied: string;
    headline: string | null;
    input: string;
    error: string | null;
    onInput: (v: string) => void;
    onApply: (code: string) => void;
    onRemove: () => void;
}) {
    const [open, setOpen] = useState(offers.length > 0);

    if (applied) {
        return (
            <div className="flex items-center justify-between gap-3 rounded-xl border border-volt/40 bg-volt/5 p-3">
                <div className="flex min-w-0 items-center gap-2">
                    <BadgePercent className="size-5 shrink-0 text-volt-600" />
                    <div className="min-w-0">
                        <p className="font-mono text-sm font-bold text-foreground">{applied}</p>
                        {headline && <p className="truncate text-xs text-muted-foreground">{headline} applied</p>}
                    </div>
                </div>
                <button type="button" onClick={onRemove} className="text-xs font-semibold text-muted-foreground underline-offset-2 hover:text-foreground hover:underline">
                    Remove
                </button>
            </div>
        );
    }

    return (
        <div className="rounded-xl border border-dashed border-border p-3">
            <button type="button" onClick={() => setOpen((o) => !o)} aria-expanded={open} className="flex w-full items-center justify-between text-sm font-semibold text-foreground">
                <span className="flex items-center gap-2">
                    <TicketPercent className="size-4 text-electric" />
                    Have a coupon?
                </span>
                <ChevronDown className={cn('size-4 text-muted-foreground transition-transform', open && 'rotate-180')} />
            </button>
            {open && (
                <div className="mt-3 space-y-3">
                    <div className="flex gap-2">
                        <input
                            value={input}
                            onChange={(e) => onInput(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    if (input) onApply(input);
                                }
                            }}
                            placeholder="Enter code"
                            aria-label="Coupon code"
                            aria-invalid={!!error}
                            maxLength={40}
                            className="h-10 min-w-0 flex-1 rounded-lg border border-input bg-background px-3 font-mono text-sm text-foreground uppercase placeholder:font-sans placeholder:text-muted-foreground placeholder:normal-case focus:border-volt focus:ring-2 focus:ring-volt/30 focus:outline-none"
                        />
                        <button
                            type="button"
                            disabled={!input}
                            onClick={() => input && onApply(input)}
                            className="h-10 rounded-lg bg-foreground px-4 text-sm font-semibold text-background transition hover:opacity-90 disabled:opacity-40"
                        >
                            Apply
                        </button>
                    </div>
                    {error && (
                        <p role="alert" className="text-xs font-medium text-destructive">
                            {error}
                        </p>
                    )}
                    {offers.length > 0 && (
                        <ul className="space-y-2">
                            {offers.map((o) => (
                                <li key={o.code} className="flex items-start justify-between gap-3 rounded-lg bg-muted/50 p-2.5">
                                    <div className="min-w-0">
                                        <p className="text-sm font-semibold text-foreground">{o.headline}</p>
                                        <p className="text-xs text-muted-foreground">
                                            {o.description ?? o.name}
                                            {o.chargerType && ` · ${o.chargerType} only`}
                                            {o.firstBookingOnly && ' · first booking'}
                                            {o.endsAt && ` · till ${new Date(o.endsAt).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })}`}
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => onApply(o.code)}
                                        className="shrink-0 rounded-md border border-volt/50 px-2 py-1 font-mono text-xs font-bold text-volt-700 hover:bg-volt/10 dark:text-volt"
                                    >
                                        {o.code}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </div>
    );
}
