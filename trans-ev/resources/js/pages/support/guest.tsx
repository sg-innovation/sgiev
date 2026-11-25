import TicketThread, { type ThreadTicket } from '@/components/support/ticket-thread';
import SiteLayout from '@/layouts/site-layout';
import { type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { LockKeyhole } from 'lucide-react';

interface Props {
    ticket: ThreadTicket;
    limits: { files: number; kb: number };
    urls: { reply: string; status: string };
    guestName: string;
    replyByEmail: boolean;
}

/** A guest's support ticket, opened from the private link in their email (no account needed). */
export default function SupportGuest({ ticket: t, limits, urls, guestName, replyByEmail }: Props) {
    const { flash } = usePage<SharedData & { flash?: { success?: string; error?: string } }>().props;

    return (
        <SiteLayout>
            <Head title={`${t.number} · ${t.subject}`}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <div className="pt-20 md:pt-24">
                <p className="mx-auto flex max-w-4xl items-center gap-2 px-4 text-xs text-muted-foreground md:px-6">
                    <LockKeyhole className="size-3.5" /> Private link for {guestName}. Keep it to yourself: anyone with this link can read and reply.
                </p>
                <TicketThread ticket={t} limits={limits} flash={flash} replyByEmail={replyByEmail} urls={{ ...urls, newTicket: '/contact' }} />
            </div>
        </SiteLayout>
    );
}
