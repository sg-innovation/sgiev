import SocialIcons from '@/components/site/social-icons';
import { useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';
import { type SharedData, type SiteLink } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Mail, MapPin, Menu, Moon, Phone, Sun, X } from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';

/** A menu or footer link from Admin → Content → Site content → Menu & footer. Site pages use Inertia; others open normally. */
function SiteAnchor({ link, className, onClick, children }: { link: Pick<SiteLink, 'url'> & { newTab?: boolean }; className?: string; onClick?: () => void; children: ReactNode }) {
    const internal = link.url.startsWith('/') && !link.newTab;
    if (internal) {
        return <Link href={link.url} className={className} onClick={onClick}>{children}</Link>;
    }

    return (
        <a href={link.url} className={className} onClick={onClick} {...(link.newTab ? { target: '_blank', rel: 'noopener noreferrer' } : {})}>
            {children}
        </a>
    );
}

function ThemeToggle() {
    const { appearance, updateAppearance } = useAppearance();
    const isDark = appearance === 'dark' || (appearance === 'system' && typeof window !== 'undefined' && window.matchMedia('(prefers-color-scheme: dark)').matches);

    return (
        <button
            type="button"
            onClick={() => updateAppearance(isDark ? 'light' : 'dark')}
            className="flex size-9 items-center justify-center rounded-full text-foreground/70 transition hover:bg-foreground/5 hover:text-foreground"
            aria-label={isDark ? 'Switch to light mode' : 'Switch to dark mode'}
        >
            {isDark ? <Sun className="size-[18px]" /> : <Moon className="size-[18px]" />}
        </button>
    );
}

export function SiteHeader({ transparent = false }: { transparent?: boolean }) {
    const { auth, layout, url } = { ...usePage<SharedData>().props, url: usePage().url };
    const [open, setOpen] = useState(false);
    const [scrolled, setScrolled] = useState(false);

    useEffect(() => {
        const onScroll = () => setScrolled(window.scrollY > 12);
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    const solid = !transparent || scrolled || open;

    return (
        <header
            className={cn(
                'fixed inset-x-0 top-0 z-50 transition-all duration-300',
                solid ? 'border-b border-border/60 bg-background/80 shadow-sm backdrop-blur-xl' : 'bg-transparent text-white [&_a]:text-white/80 [&_a:hover]:text-white [&_button]:text-white/80',
            )}
        >
            <div className="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
                <Link href="/" className="flex items-center gap-2" aria-label="Trans EV home">
                    <img src="/brand/logo.svg" alt="Trans EV" className={cn('h-9', solid ? 'dark:hidden' : 'hidden')} />
                    <img src="/brand/logo-dark.svg" alt="Trans EV" className={cn('h-9', solid ? 'hidden dark:block' : 'block')} />
                </Link>

                <nav className="hidden items-center gap-1 md:flex">
                    {layout.menu.map((item, i) => (
                        <SiteAnchor
                            key={i}
                            link={item}
                            className={cn(
                                'rounded-full px-4 py-2 text-sm font-medium text-foreground/70 transition hover:bg-foreground/5 hover:text-foreground',
                                item.url.length > 1 && url.startsWith(item.url) && 'bg-foreground/5 text-foreground',
                            )}
                        >
                            {item.label}
                        </SiteAnchor>
                    ))}
                </nav>

                <div className="hidden items-center gap-2 md:flex">
                    <ThemeToggle />
                    {auth.user ? (
                        <Link href={auth.isStaff ? '/admin' : '/dashboard'} className="rounded-full px-4 py-2 text-sm font-medium hover:bg-foreground/5">
                            {auth.isStaff ? 'Admin' : 'My account'}
                        </Link>
                    ) : (
                        <Link href={route('login')} className="rounded-full px-4 py-2 text-sm font-medium hover:bg-foreground/5">
                            Log in
                        </Link>
                    )}
                    {layout.button && (
                        <SiteAnchor
                            link={layout.button}
                            className="rounded-full bg-gradient-to-r from-volt to-electric px-5 py-2 text-sm font-semibold text-white shadow-lg shadow-volt/25 transition hover:shadow-volt/40 hover:brightness-110"
                        >
                            {layout.button.label}
                        </SiteAnchor>
                    )}
                </div>

                <div className="flex items-center gap-1 md:hidden">
                    <ThemeToggle />
                    <button type="button" className="flex size-10 items-center justify-center rounded-full" onClick={() => setOpen(!open)} aria-label="Menu" aria-expanded={open}>
                        {open ? <X className="size-5" /> : <Menu className="size-5" />}
                    </button>
                </div>
            </div>

            {open && (
                <nav className="max-h-[calc(100svh-4rem)] overflow-y-auto border-t border-border/60 bg-background px-4 pb-6 text-foreground shadow-lg md:hidden [&_a]:text-foreground" aria-label="Menu">
                    {layout.menu.map((item, i) => (
                        <SiteAnchor key={i} link={item} className="block border-b border-border/40 py-3 text-base font-medium" onClick={() => setOpen(false)}>
                            {item.label}
                        </SiteAnchor>
                    ))}
                    <div className="mt-4 flex gap-3">
                        <Link href={auth.user ? (auth.isStaff ? '/admin' : '/dashboard') : route('login')} className="flex-1 rounded-full border border-border py-2.5 text-center text-sm font-medium">
                            {auth.user ? 'My account' : 'Log in'}
                        </Link>
                        {layout.button && (
                            <SiteAnchor link={layout.button} className="flex-1 rounded-full bg-gradient-to-r from-volt to-electric py-2.5 text-center text-sm font-semibold text-white" onClick={() => setOpen(false)}>
                                {layout.button.label}
                            </SiteAnchor>
                        )}
                    </div>
                </nav>
            )}
        </header>
    );
}

export function SiteFooter() {
    const { brand, layout } = usePage<SharedData>().props;
    const year = new Date().getFullYear();

    return (
        <footer className="relative overflow-hidden bg-ink text-white">
            <div className="pointer-events-none absolute -top-40 left-1/2 h-80 w-[60rem] -translate-x-1/2 rounded-full bg-gradient-to-r from-volt/20 to-electric/20 blur-3xl" />
            <div className="relative mx-auto grid max-w-7xl gap-10 px-4 py-14 sm:px-6 md:grid-cols-2 lg:auto-cols-fr lg:grid-flow-col lg:grid-cols-none lg:px-8">
                <div className="space-y-4">
                    <img src="/brand/logo-dark.svg" alt="Trans EV" className="h-10" />
                    {layout.footerText && <p className="max-w-xs text-sm text-white/60">{layout.footerText}</p>}
                    <SocialIcons links={brand.social} />
                </div>

                {layout.columns.map((col, i) => (
                    <div key={i}>
                        {col.title && <h3 className="font-display text-sm font-semibold tracking-wide text-white/90 uppercase">{col.title}</h3>}
                        <ul className="mt-4 space-y-2.5 text-sm text-white/60">
                            {col.links.map((link, j) => (
                                <li key={j}>
                                    <SiteAnchor link={link} className="hover:text-volt">{link.label}</SiteAnchor>
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}

                <div>
                    <h3 className="font-display text-sm font-semibold tracking-wide text-white/90 uppercase">{layout.contactTitle || 'Get in touch'}</h3>
                    <ul className="mt-4 space-y-3 text-sm text-white/60">
                        {brand.address && (
                            <li className="flex gap-2.5"><MapPin className="mt-0.5 size-4 shrink-0 text-volt" />{brand.address}</li>
                        )}
                        {brand.phone && (
                            <li className="flex gap-2.5"><Phone className="mt-0.5 size-4 shrink-0 text-volt" /><a href={`tel:${brand.phone.replace(/\s/g, '')}`} className="hover:text-white">{brand.phone}</a></li>
                        )}
                        {brand.email && (
                            <li className="flex gap-2.5"><Mail className="mt-0.5 size-4 shrink-0 text-volt" /><a href={`mailto:${brand.email}`} className="hover:text-white">{brand.email}</a></li>
                        )}
                    </ul>
                </div>
            </div>
            <div className="relative border-t border-white/10">
                <div className="mx-auto flex max-w-7xl flex-col gap-2 px-4 py-5 text-xs text-white/50 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
                    <p>© {year} {brand.legalName ?? 'Trans EV'}. All rights reserved.</p>
                    {layout.bottomLine && <p>{layout.bottomLine}</p>}
                </div>
            </div>
        </footer>
    );
}

export default function SiteLayout({ children, transparentHeader = false, hideFooter = false }: { children: ReactNode; transparentHeader?: boolean; hideFooter?: boolean }) {
    return (
        <div className="flex min-h-screen flex-col overflow-x-clip bg-background text-foreground">
            <SiteHeader transparent={transparentHeader} />
            <main className={cn('flex-1', !transparentHeader && 'pt-16')}>{children}</main>
            {!hideFooter && <SiteFooter />}
        </div>
    );
}
