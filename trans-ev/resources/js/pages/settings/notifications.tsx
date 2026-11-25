import HeadingSmall from '@/components/heading-small';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Bell, CalendarPlus, CheckCircle2, LoaderCircle, Mail, MessageCircle, Smartphone } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Notifications', href: '/settings/notifications' }];

interface Props {
    prefs: { sms: boolean; whatsapp: boolean; push: boolean; operator_bookings?: boolean };
    isStaff: boolean;
    phone: string | null;
    email: string;
    devices: number;
}

export default function Notifications({ prefs, isStaff, phone, email, devices }: Props) {
    const { flash } = usePage<{ flash: { success?: string } }>().props;
    const form = useForm({ ...prefs });

    const rows = [
        { key: 'sms' as const, icon: Smartphone, title: 'SMS', desc: phone ? `To +91 ${phone}. Booking confirmations, reminders and offers from the waiting list.` : 'Add your mobile number in Profile to get SMS.', disabled: !phone },
        { key: 'whatsapp' as const, icon: MessageCircle, title: 'WhatsApp', desc: phone ? `To +91 ${phone}. We only message you on WhatsApp if you turn this on.` : 'Add your mobile number in Profile to get WhatsApp messages.', disabled: !phone },
        { key: 'push' as const, icon: Bell, title: 'App notifications', desc: devices ? `On ${devices} device${devices > 1 ? 's' : ''} with the Trans EV app.` : 'Install the Trans EV app to get notifications on your phone.', disabled: false },
        ...(isStaff
            ? [{ key: 'operator_bookings' as const, icon: CalendarPlus, title: 'New bookings at my stations', desc: 'Tell me when a customer books and pays at a station I look after (on the channels above).', disabled: false }]
            : []),
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Notifications" />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Notifications" description="Choose how we keep you updated about bookings, charging and support." />

                    <div className="flex items-start gap-3 rounded-xl border border-border p-4">
                        <Mail className="mt-0.5 size-5 shrink-0 text-muted-foreground" />
                        <div>
                            <p className="font-medium text-foreground">Email · always on</p>
                            <p className="text-sm text-muted-foreground">Receipts, GST invoices and booking changes go to {email}.</p>
                        </div>
                    </div>

                    <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); form.put('/settings/notifications', { preserveScroll: true }); }}>
                        {rows.map((r) => (
                            <label key={r.key} className={cn('flex cursor-pointer items-start justify-between gap-4 rounded-xl border border-border p-4', r.disabled && 'cursor-not-allowed opacity-60')}>
                                <span className="flex items-start gap-3">
                                    <r.icon className="mt-0.5 size-5 shrink-0 text-muted-foreground" />
                                    <span>
                                        <span className="block font-medium text-foreground">{r.title}</span>
                                        <span className="block text-sm text-muted-foreground">{r.desc}</span>
                                    </span>
                                </span>
                                <input type="checkbox" role="switch" disabled={r.disabled} checked={!!form.data[r.key]} onChange={(e) => form.setData(r.key, e.target.checked)} className="mt-1 size-5 shrink-0" aria-label={`${r.title} notifications`} />
                            </label>
                        ))}
                        {!phone && (
                            <p className="text-sm text-muted-foreground">
                                <Link href="/settings/profile" className="font-semibold text-electric hover:underline">Add your mobile number</Link> to turn on SMS and WhatsApp.
                            </p>
                        )}
                        <div className="flex items-center gap-3">
                            <button type="submit" disabled={form.processing || !form.isDirty} className="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-50">
                                {form.processing && <LoaderCircle className="size-4 animate-spin" />} Save
                            </button>
                            {flash?.success && !form.isDirty && <span className="flex items-center gap-1 text-sm text-emerald-700 dark:text-emerald-300"><CheckCircle2 className="size-4" /> {flash.success}</span>}
                        </div>
                    </form>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
