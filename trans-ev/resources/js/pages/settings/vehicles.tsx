import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type Option } from '@/types/station';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { BatteryCharging, Car, CheckCircle2, LoaderCircle, Pencil, Plug, Plus, Star, Trash2, X } from 'lucide-react';
import { type FormEvent, useState } from 'react';

interface Vehicle {
    id: number;
    make: string;
    model: string;
    registration_no: string;
    battery_kwh: number;
    connector: string;
    connectorLabel: string;
    is_default: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'My vehicles', href: '/settings/vehicles' }];

const POPULAR = [
    { make: 'Tata', model: 'Nexon EV', battery_kwh: 40.5, connector: 'ccs2' },
    { make: 'Tata', model: 'Punch EV', battery_kwh: 35, connector: 'ccs2' },
    { make: 'MG', model: 'ZS EV', battery_kwh: 50.3, connector: 'ccs2' },
    { make: 'Mahindra', model: 'XUV400', battery_kwh: 39.4, connector: 'ccs2' },
    { make: 'Hyundai', model: 'Creta Electric', battery_kwh: 51.4, connector: 'ccs2' },
    { make: 'Ather', model: '450X', battery_kwh: 3.7, connector: 'type6' },
];

const inputCls = 'h-11 w-full rounded-xl border border-input bg-background px-3.5 text-sm text-foreground outline-none transition focus:border-volt focus:ring-4 focus:ring-volt/15';

export default function Vehicles({ vehicles, connectors, max }: { vehicles: Vehicle[]; connectors: Option[]; max: number }) {
    const { flash } = usePage<SharedData & { flash: { success?: string } }>().props;
    const [editing, setEditing] = useState<Vehicle | null>(null);
    const [open, setOpen] = useState(vehicles.length === 0);

    const form = useForm({ make: '', model: '', registration_no: '', battery_kwh: 40 as number, connector: 'ccs2', is_default: false as boolean });

    const startAdd = () => {
        setEditing(null);
        form.reset();
        form.clearErrors();
        setOpen(true);
    };
    const startEdit = (v: Vehicle) => {
        setEditing(v);
        form.setData({ make: v.make, model: v.model, registration_no: v.registration_no, battery_kwh: v.battery_kwh, connector: v.connector, is_default: v.is_default });
        form.clearErrors();
        setOpen(true);
    };
    const submit = (e: FormEvent) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: () => { setOpen(false); setEditing(null); form.reset(); } };
        if (editing) form.put(`/settings/vehicles/${editing.id}`, opts);
        else form.post('/settings/vehicles', opts);
    };
    const remove = (v: Vehicle) => {
        if (confirm(`Remove ${v.make} ${v.model} (${v.registration_no})? Past bookings are not affected.`)) {
            router.delete(`/settings/vehicles/${v.id}`, { preserveScroll: true });
        }
    };
    const makeDefault = (v: Vehicle) =>
        router.put(`/settings/vehicles/${v.id}`, { make: v.make, model: v.model, registration_no: v.registration_no, battery_kwh: v.battery_kwh, connector: v.connector, is_default: true }, { preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My vehicles" />
            <SettingsLayout>
                <div className="space-y-6">
                    <div className="flex items-start justify-between gap-4">
                        <HeadingSmall title="My vehicles" description="Saved vehicles fill in the booking form for you and help us estimate charging cost and time." />
                        {!open && vehicles.length < max && (
                            <button type="button" onClick={startAdd} className="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-gradient-to-r from-volt-600 to-electric px-4 py-2 text-sm font-semibold text-white shadow-md shadow-volt/25">
                                <Plus className="size-4" /> Add vehicle
                            </button>
                        )}
                    </div>

                    {flash?.success && (
                        <div className="flex items-center gap-2 rounded-xl bg-emerald-500/10 px-4 py-3 text-sm font-medium text-emerald-800 ring-1 ring-emerald-500/30 dark:text-emerald-200">
                            <CheckCircle2 className="size-4" /> {flash.success}
                        </div>
                    )}

                    {open && (
                        <form onSubmit={submit} className="rounded-2xl border border-border bg-card p-5" noValidate>
                            <div className="mb-4 flex items-center justify-between">
                                <p className="font-semibold text-foreground">{editing ? 'Edit vehicle' : 'Add a vehicle'}</p>
                                {vehicles.length > 0 && (
                                    <button type="button" onClick={() => setOpen(false)} className="text-muted-foreground hover:text-foreground" aria-label="Close">
                                        <X className="size-5" />
                                    </button>
                                )}
                            </div>
                            {!editing && (
                                <div className="mb-4 flex flex-wrap gap-2">
                                    {POPULAR.map((p) => (
                                        <button
                                            key={p.model}
                                            type="button"
                                            onClick={() => form.setData((d) => ({ ...d, make: p.make, model: p.model, battery_kwh: p.battery_kwh, connector: p.connector }))}
                                            className="rounded-full border border-border px-3 py-1 text-xs font-medium text-muted-foreground hover:border-volt/50 hover:text-foreground"
                                        >
                                            {p.make} {p.model}
                                        </button>
                                    ))}
                                </div>
                            )}
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Make" error={form.errors.make}>
                                    <input className={inputCls} value={form.data.make} onChange={(e) => form.setData('make', e.target.value)} maxLength={60} placeholder="Tata" />
                                </Field>
                                <Field label="Model" error={form.errors.model}>
                                    <input className={inputCls} value={form.data.model} onChange={(e) => form.setData('model', e.target.value)} maxLength={80} placeholder="Nexon EV" />
                                </Field>
                                <Field label="Registration number" error={form.errors.registration_no}>
                                    <input
                                        className={cn(inputCls, 'uppercase')}
                                        value={form.data.registration_no}
                                        onChange={(e) => form.setData('registration_no', e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 11))}
                                        placeholder="BR01AB1234"
                                    />
                                </Field>
                                <Field label="Battery capacity (kWh)" error={form.errors.battery_kwh}>
                                    <input type="number" min={1} max={200} step={0.1} className={inputCls} value={form.data.battery_kwh} onChange={(e) => form.setData('battery_kwh', Number(e.target.value))} />
                                </Field>
                                <Field label="Charging connector" error={form.errors.connector} className="sm:col-span-2">
                                    <div className="flex flex-wrap gap-2">
                                        {connectors.map((c) => (
                                            <button
                                                key={c.value}
                                                type="button"
                                                onClick={() => form.setData('connector', c.value)}
                                                className={cn('rounded-xl border px-3 py-2 text-sm font-medium', form.data.connector === c.value ? 'border-volt bg-volt/10 text-volt-600' : 'border-border text-foreground')}
                                            >
                                                {c.label}
                                            </button>
                                        ))}
                                    </div>
                                </Field>
                            </div>
                            <label className="mt-4 flex items-center gap-2 text-sm text-foreground">
                                <input type="checkbox" className="size-4 accent-[#00B386]" checked={form.data.is_default} onChange={(e) => form.setData('is_default', e.target.checked)} />
                                Use as my default vehicle when booking
                            </label>
                            <div className="mt-5 flex gap-3">
                                <button type="submit" disabled={form.processing} className="inline-flex items-center gap-2 rounded-xl bg-ink px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-60 dark:bg-volt dark:text-ink">
                                    {form.processing && <LoaderCircle className="size-4 animate-spin" />}
                                    {editing ? 'Save changes' : 'Save vehicle'}
                                </button>
                                {vehicles.length > 0 && (
                                    <button type="button" onClick={() => setOpen(false)} className="rounded-xl px-4 py-2.5 text-sm font-medium text-muted-foreground hover:bg-muted">
                                        Cancel
                                    </button>
                                )}
                            </div>
                        </form>
                    )}

                    {vehicles.length === 0 && !open ? null : (
                        <ul className="space-y-3">
                            {vehicles.map((v) => (
                                <li key={v.id} className={cn('flex flex-col gap-4 rounded-2xl border bg-card p-4 sm:flex-row sm:items-center', v.is_default ? 'border-volt/50' : 'border-border')}>
                                    <span className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-volt/10">
                                        <Car className="size-6 text-volt-600" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="flex flex-wrap items-center gap-2 font-semibold text-foreground">
                                            {v.make} {v.model}
                                            {v.is_default && <span className="rounded-full bg-volt/15 px-2 py-0.5 text-xs font-semibold text-volt-600">Default</span>}
                                        </p>
                                        <p className="mt-0.5 flex flex-wrap items-center gap-x-3 text-sm text-muted-foreground">
                                            <span className="font-mono">{v.registration_no}</span>
                                            <span className="flex items-center gap-1"><BatteryCharging className="size-3.5" />{v.battery_kwh} kWh</span>
                                            <span className="flex items-center gap-1"><Plug className="size-3.5" />{v.connectorLabel}</span>
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-1">
                                        {!v.is_default && (
                                            <IconBtn label="Make default" onClick={() => makeDefault(v)}><Star className="size-4" /></IconBtn>
                                        )}
                                        <IconBtn label="Edit" onClick={() => startEdit(v)}><Pencil className="size-4" /></IconBtn>
                                        <IconBtn label="Remove" onClick={() => remove(v)} danger><Trash2 className="size-4" /></IconBtn>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                    <p className="text-xs text-muted-foreground">You can save up to {max} vehicles.</p>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}

function Field({ label, error, children, className }: { label: string; error?: string; children: React.ReactNode; className?: string }) {
    return (
        <label className={cn('block', className)}>
            <span className="mb-1.5 block text-sm font-medium text-foreground">{label}</span>
            {children}
            <InputError message={error} className="mt-1.5" />
        </label>
    );
}

function IconBtn({ label, onClick, children, danger }: { label: string; onClick: () => void; children: React.ReactNode; danger?: boolean }) {
    return (
        <button
            type="button"
            onClick={onClick}
            title={label}
            aria-label={label}
            className={cn('flex size-9 items-center justify-center rounded-full border border-border text-muted-foreground transition', danger ? 'hover:border-red-500/50 hover:text-red-600' : 'hover:border-volt/50 hover:text-foreground')}
        >
            {children}
        </button>
    );
}
