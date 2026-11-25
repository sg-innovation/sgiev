import TicketThread, { type ThreadTicket } from '@/components/support/ticket-thread';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';

interface Props {
    ticket: ThreadTicket;
    limits: { files: number; kb: number };
    replyByEmail: boolean;
}

export default function SupportShow({ ticket: t, limits, replyByEmail }: Props) {
    const { flash } = usePage<SharedData & { flash?: { success?: string; error?: string } }>().props;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Support tickets', href: '/account/support' },
        { title: t.number, href: `/account/support/${t.uuid}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${t.number} · ${t.subject}`} />
            <TicketThread
                ticket={t}
                limits={limits}
                flash={flash}
                replyByEmail={replyByEmail}
                back={{ href: '/account/support', label: 'All tickets' }}
                urls={{ reply: `/account/support/${t.uuid}/reply`, status: `/account/support/${t.uuid}/status`, newTicket: '/account/support' }}
            />
        </AppLayout>
    );
}
