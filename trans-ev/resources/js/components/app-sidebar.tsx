import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/react';
import { CalendarClock, Car, Globe, Hourglass, LayoutGrid, LifeBuoy, MapPinned, UserCog } from 'lucide-react';

const mainNavItems: NavItem[] = [
    { title: 'My account', url: '/dashboard', icon: LayoutGrid },
    { title: 'My bookings', url: '/account/bookings', icon: CalendarClock },
    { title: 'Waiting lists', url: '/account/waitlist', icon: Hourglass },
    { title: 'My vehicles', url: '/settings/vehicles', icon: Car },
    { title: 'Find & book', url: '/stations', icon: MapPinned },
    { title: 'Profile & security', url: '/settings/profile', icon: UserCog },
];

const footerNavItems: NavItem[] = [
    { title: 'Support tickets', url: '/account/support', icon: LifeBuoy },
    { title: 'Back to website', url: '/', icon: Globe },
];

export function AppSidebar() {
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
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
