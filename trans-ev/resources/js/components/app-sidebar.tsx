import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarInput, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Bell, CalendarClock, Car, Globe, Hourglass, LayoutGrid, LifeBuoy, MapPinned, Search, UserCog, X } from 'lucide-react';

const mainNavItems: NavItem[] = [
    { title: 'My account', url: '/dashboard', icon: LayoutGrid },
    { title: 'My bookings', url: '/account/bookings', icon: CalendarClock },
    { title: 'Waiting lists', url: '/account/waitlist', icon: Hourglass },
    { title: 'My vehicles', url: '/settings/vehicles', icon: Car },
    { title: 'Find & book', url: '/stations', icon: MapPinned },
    { title: 'Profile & security', url: '/settings/profile', icon: UserCog },
    { title: 'Notifications', url: '/settings/notifications', icon: Bell },
];

const footerNavItems: NavItem[] = [
    { title: 'Support tickets', url: '/account/support', icon: LifeBuoy },
    { title: 'Back to website', url: '/', icon: Globe },
];

const norm = (s: string) => s.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, ' ').trim();
const matches = (item: NavItem, q: string) => norm(q).split(' ').filter(Boolean).every((t) => norm(item.title).includes(t));

export function AppSidebar() {
    const [q, setQ] = useState('');
    const input = useRef<HTMLInputElement>(null);
    const main = q ? mainNavItems.filter((i) => matches(i, q)) : mainNavItems;
    const footer = q ? footerNavItems.filter((i) => matches(i, q)) : footerNavItems;

    // "/" focuses the menu search from anywhere (except while typing in a field).
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            const t = e.target as HTMLElement | null;
            if (e.key === '/' && !e.ctrlKey && !e.metaKey && !['INPUT', 'TEXTAREA', 'SELECT'].includes(t?.tagName ?? '') && !t?.isContentEditable) {
                e.preventDefault();
                input.current?.focus();
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <img src="/brand/logo-mark.svg" alt="" className="size-8" />
                                <div className="ml-1 grid flex-1 text-left text-sm">
                                    <span className="truncate font-display leading-none font-bold">Trans EV</span>
                                    <span className="truncate text-xs text-muted-foreground">My account</span>
                                </div>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                <div className="relative px-2 group-data-[collapsible=icon]:hidden">
                    <label htmlFor="app-sidebar-search" className="sr-only">Search the menu</label>
                    <Search className="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-muted-foreground" />
                    <SidebarInput
                        id="app-sidebar-search"
                        ref={input}
                        type="search"
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Escape') {
                                setQ('');
                                e.currentTarget.blur();
                            }
                            if (e.key === 'Enter') {
                                const first = [...main, ...footer][0];
                                if (first) router.visit(first.url);
                            }
                        }}
                        placeholder="Search menu"
                        autoComplete="off"
                        className="h-9 pr-8 pl-8 [&::-webkit-search-cancel-button]:hidden"
                    />
                    {q ? (
                        <button type="button" aria-label="Clear search" onClick={() => { setQ(''); input.current?.focus(); }} className="absolute top-1/2 right-4 -translate-y-1/2 text-muted-foreground hover:text-foreground">
                            <X className="size-4" />
                        </button>
                    ) : (
                        <kbd className="pointer-events-none absolute top-1/2 right-4 -translate-y-1/2 rounded border border-border px-1.5 text-[11px] text-muted-foreground">/</kbd>
                    )}
                </div>
            </SidebarHeader>

            <SidebarContent>
                {main.length > 0 && <NavMain items={main} />}
                {q && main.length === 0 && footer.length === 0 && (
                    <p className="px-4 py-2 text-xs text-muted-foreground group-data-[collapsible=icon]:hidden">No menu items match “{q}”.</p>
                )}
            </SidebarContent>

            <SidebarFooter>
                {footer.length > 0 && <NavFooter items={footer} className="mt-auto" />}
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
