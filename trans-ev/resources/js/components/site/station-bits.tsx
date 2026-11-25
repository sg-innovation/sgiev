import { cn } from '@/lib/utils';
import { type StationCard, type StationStatus } from '@/types/station';
import { Link } from '@inertiajs/react';
import { ArrowRight, BatteryCharging, Clock, MapPin, Navigation, Star, Zap } from 'lucide-react';

export const STATUS_STYLES: Record<StationStatus | 'closed', { dot: string; pill: string; label: string }> = {
    online: { dot: 'bg-emerald-500', pill: 'bg-emerald-500/10 text-emerald-700 ring-emerald-500/20 dark:text-emerald-300', label: 'Online' },
    offline: { dot: 'bg-red-500', pill: 'bg-red-500/10 text-red-700 ring-red-500/20 dark:text-red-300', label: 'Offline' },
    maintenance: { dot: 'bg-amber-500', pill: 'bg-amber-500/10 text-amber-700 ring-amber-500/20 dark:text-amber-300', label: 'Maintenance' },
    closed: { dot: 'bg-slate-400', pill: 'bg-slate-500/10 text-slate-600 ring-slate-500/20 dark:text-slate-300', label: 'Closed now' },
};

export function stationState(s: Pick<StationCard, 'status' | 'isOpen'>): StationStatus | 'closed' {
    if (s.status !== 'online') return s.status;
    return s.isOpen ? 'online' : 'closed';
}

export function StatusPill({ state, className }: { state: StationStatus | 'closed'; className?: string }) {
    const st = STATUS_STYLES[state];
    return (
        <span className={cn('inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ring-1', st.pill, className)}>
            <span className={cn('size-1.5 rounded-full', st.dot, state === 'online' && 'animate-pulse')} />
            {st.label}
        </span>
    );
}

export const inr = (n: number, digits = 2) =>
    new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', minimumFractionDigits: digits, maximumFractionDigits: digits }).format(n);

export const directionsUrl = (lat: number, lng: number) => `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}`;

export function StationCardView({ station, active, onHover }: { station: StationCard; active?: boolean; onHover?: (slug: string | null) => void }) {
    const state = stationState(station);

    return (
        <article
            onMouseEnter={() => onHover?.(station.slug)}
            onMouseLeave={() => onHover?.(null)}
            className={cn(
                'group relative rounded-2xl border border-border bg-card p-4 transition-all duration-200 hover:-translate-y-0.5 hover:border-volt/50 hover:shadow-xl hover:shadow-volt/5',
                active && 'border-volt/60 shadow-lg shadow-volt/10',
            )}
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h3 className="truncate font-display text-base font-semibold">
                        <Link href={`/stations/${station.slug}`} className="after:absolute after:inset-0">
                            {station.name}
                        </Link>
                    </h3>
                    <p className="mt-0.5 flex items-center gap-1 truncate text-sm text-muted-foreground">
                        <MapPin className="size-3.5 shrink-0" />
                        {station.address}
                    </p>
                    {station.operator?.name && !station.operator.isPlatform && (
                        <p className="mt-1 text-xs font-medium text-electric">Operated by {station.operator.name}</p>
                    )}
                    {station.rating && (
                        <p className="mt-1 flex items-center gap-1 text-xs">
                            <Star className="size-3.5 fill-amber-400 text-amber-400" />
                            <span className="font-semibold">{station.rating.avg.toFixed(1)}</span>
                            <span className="text-muted-foreground">({station.rating.count})</span>
                        </p>
                    )}
                </div>
                <StatusPill state={state} className="shrink-0" />
            </div>

            {station.statusReason && <p className="mt-2 rounded-lg bg-amber-500/10 px-2.5 py-1.5 text-xs text-amber-800 dark:text-amber-200">{station.statusReason}</p>}

            <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-sm">
                <span className="flex items-center gap-1 font-medium">
                    <Zap className="size-4 text-volt-600" />
                    Up to {station.maxPowerKw} kW
                </span>
                <span className="flex items-center gap-1 text-muted-foreground">
                    <BatteryCharging className="size-4" />
                    {station.guns ? `${station.guns.available}/${station.guns.total} guns free now` : `${station.chargersOnline}/${station.chargersTotal} chargers`}
                </span>
                <span className="flex items-center gap-1 text-muted-foreground">
                    <Clock className="size-4" />
                    {station.todayHours}
                </span>
            </div>

            <div className="mt-3 flex flex-wrap gap-1.5">
                {station.types.map((t) => (
                    <span key={t} className={cn('rounded-md px-2 py-0.5 text-xs font-semibold', t === 'DC' ? 'bg-volt/15 text-volt-600' : 'bg-electric/10 text-electric')}>
                        {t}
                    </span>
                ))}
                {station.connectors.slice(0, 4).map((c) => (
                    <span key={c.value} className="rounded-md bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                        {c.label}
                    </span>
                ))}
            </div>

            <div className="mt-4 flex items-end justify-between border-t border-border/60 pt-3">
                <div>
                    {station.fromRate !== null && (
                        <p className="text-sm">
                            <span className="text-muted-foreground">from </span>
                            <span className="font-display text-lg font-bold">{inr(station.fromRate, 0)}</span>
                            <span className="text-muted-foreground">/kWh</span>
                        </p>
                    )}
                    {station.distanceKm !== null && <p className="text-xs text-muted-foreground">{station.distanceKm.toFixed(1)} km away</p>}
                </div>
                <div className="relative z-10 flex items-center gap-2">
                    <a
                        href={directionsUrl(station.lat, station.lng)}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="flex size-9 items-center justify-center rounded-full border border-border text-muted-foreground transition hover:border-electric hover:text-electric"
                        aria-label={`Directions to ${station.name}`}
                    >
                        <Navigation className="size-4" />
                    </a>
                    <Link
                        href={station.bookable ? `/book/${station.slug}` : `/stations/${station.slug}`}
                        className={cn(
                            'flex items-center gap-1 rounded-full px-4 py-2 text-sm font-semibold transition',
                            station.bookable ? 'bg-ink text-white hover:bg-volt-600 dark:bg-volt dark:text-ink' : 'bg-muted text-muted-foreground',
                        )}
                    >
                        {station.bookable ? 'Book slot' : 'Details'}
                        <ArrowRight className="size-4 transition group-hover:translate-x-0.5" />
                    </Link>
                </div>
            </div>
        </article>
    );
}
