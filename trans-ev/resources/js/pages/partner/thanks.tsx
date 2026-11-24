import SiteLayout from '@/layouts/site-layout';
import { Head, Link } from '@inertiajs/react';
import { ArrowRight, BadgeCheck, Mail, PlugZap } from 'lucide-react';

export default function PartnerThanks({ email }: { email: string | null }) {
    return (
        <SiteLayout>
            <Head title="Application received" />
            <section className="mx-auto max-w-2xl px-4 py-20 text-center sm:px-6">
                <div className="mx-auto flex size-16 items-center justify-center rounded-2xl bg-gradient-to-br from-volt to-electric shadow-lg shadow-volt/30">
                    <BadgeCheck className="size-8 text-white" />
                </div>
                <h1 className="mt-6 font-display text-3xl font-extrabold text-foreground sm:text-4xl">Application received</h1>
                <p className="mt-3 text-lg text-muted-foreground">Thank you for partnering with Trans EV. Our team will verify your documents within 2 working days.</p>
                <div className="mt-10 grid gap-4 text-left sm:grid-cols-2">
                    <div className="rounded-2xl border border-border bg-card p-5">
                        <Mail className="size-6 text-electric" />
                        <p className="mt-3 font-semibold text-foreground">Verify your email</p>
                        <p className="text-sm text-muted-foreground">We sent a link{email ? ` to ${email}` : ''}. You'll also hear from us once your account is reviewed.</p>
                    </div>
                    <div className="rounded-2xl border border-border bg-card p-5">
                        <PlugZap className="size-6 text-volt-600" />
                        <p className="mt-3 font-semibold text-foreground">Add your stations now</p>
                        <p className="text-sm text-muted-foreground">Log in to your partner dashboard to add stations, chargers and prices. They go live after approval.</p>
                    </div>
                </div>
                <a href="/admin/login" className="mt-10 inline-flex items-center gap-2 rounded-full bg-gradient-to-r from-volt-600 to-electric px-7 py-3.5 font-semibold text-white shadow-lg shadow-volt/25">
                    Open partner dashboard <ArrowRight className="size-4" />
                </a>
                <p className="mt-4 text-sm text-muted-foreground">
                    Questions? <Link href="/contact" className="font-medium text-foreground underline">Contact us</Link>
                </p>
            </section>
        </SiteLayout>
    );
}
