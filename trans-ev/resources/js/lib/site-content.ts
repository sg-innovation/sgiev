import {
    AlertTriangle,
    BadgeIndianRupee,
    BatteryCharging,
    Building2,
    CalendarCheck,
    Car,
    Clock,
    CreditCard,
    FileText,
    Flame,
    Gauge,
    Handshake,
    HeartHandshake,
    Info,
    Leaf,
    MapPinned,
    Plug,
    Power,
    Receipt,
    ShieldCheck,
    Smartphone,
    Target,
    Users,
    Wifi,
    Zap,
    type LucideIcon,
} from 'lucide-react';

/** Icon names editors can pick in Admin → Content → Site content (see App\Support\SiteContent::ICONS). */
export const ICONS: Record<string, LucideIcon> = {
    zap: Zap,
    'map-pinned': MapPinned,
    'calendar-check': CalendarCheck,
    'credit-card': CreditCard,
    receipt: Receipt,
    gauge: Gauge,
    'badge-rupee': BadgeIndianRupee,
    'file-text': FileText,
    'shield-check': ShieldCheck,
    leaf: Leaf,
    smartphone: Smartphone,
    building: Building2,
    wifi: Wifi,
    target: Target,
    'heart-handshake': HeartHandshake,
    users: Users,
    handshake: Handshake,
    power: Power,
    plug: Plug,
    flame: Flame,
    'alert-triangle': AlertTriangle,
    info: Info,
    clock: Clock,
    car: Car,
    battery: BatteryCharging,
};

export const icon = (name?: string | null): LucideIcon => ICONS[name ?? ''] ?? Zap;

/** Fill {placeholders} in editor text with live values. Unknown placeholders are left as-is. */
export function fill(text: string | null | undefined, vars: Record<string, string | number>): string {
    return (text ?? '').replace(/\{([a-z_]+)\}/g, (m, k: string) => (k in vars ? String(vars[k]) : m));
}

// Loose shapes for editor-managed blocks (fields are defined server-side).
export type Item = Record<string, string>;
export type Block = Record<string, string | string[] | Item[] | undefined> & { items?: Item[] };
export type SiteSections = Record<string, Block | undefined>;

export const str = (b: Block | undefined, k: string): string => (typeof b?.[k] === 'string' ? (b[k] as string) : '');
export const list = (b: Block | undefined, k: string): string[] => (Array.isArray(b?.[k]) ? (b![k] as string[]).filter((x) => typeof x === 'string') : []);
export const items = (b: Block | undefined, k = 'items'): Item[] => (Array.isArray(b?.[k]) ? (b![k] as Item[]).filter((x) => x && typeof x === 'object') : []);
