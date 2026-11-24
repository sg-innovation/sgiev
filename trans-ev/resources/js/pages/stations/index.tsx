import { StationCardView } from '@/components/site/station-bits';
import StationMap from '@/components/site/station-map';
import { useGeolocation } from '@/hooks/use-geolocation';
import SiteLayout from '@/layouts/site-layout';
import { cn } from '@/lib/utils';
import { type Option, type StationCard } from '@/types/station';
import { Head } from '@inertiajs/react';
import { AlertCircle, List, LoaderCircle, LocateFixed, Map as MapIcon, Search, SlidersHorizontal, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

interface Props {
    filters: { types: Option[]; connectors: Option[]; cities: string[] };
    mapStyle: string;
}

interface Query {
    q: string;
    type: string;
    connector: string;
    min_kw: string;
    open_now: boolean;
    available: boolean;
    radius: string;
}

const EMPTY: Query = { q: '', type: '', connector: '', min_kw: '', open_now: false, available: false, radius: '' };

export default function StationsIndex({ filters, mapStyle }: Props) {
    const { state: geo, locate } = useGeolocation();
    const initial = typeof window !== 'undefined' ? new URLSearchParams(window.location.search) : new URLSearchParams();
    const [query, setQuery] = useState<Query>({ ...EMPTY, q: initial.get('q') ?? '' });
    const [search, setSearch] = useState(initial.get('q') ?? '');
    const [stations, setStations] = useState<StationCard[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [view, setView] = useState<'map' | 'list'>('map');
    const [showFilters, setShowFilters] = useState(false);
    const [hovered, setHovered] = useState<string | null>(null);

    // "Near me" links from the home page start locating straight away.
    useEffect(() => {
        if (initial.get('near') === '1') locate();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const location = geo.status === 'ok' ? { lat: geo.lat, lng: geo.lng } : null;

    // Debounce the free-text search box.
    useEffect(() => {
        const t = setTimeout(() => setQuery((q) => ({ ...q, q: search })), 350);
        return () => clearTimeout(t);
    }, [search]);

    useEffect(() => {
        const params = new URLSearchParams();
        if (location) {
            params.set('lat', location.lat.toFixed(6));
            params.set('lng', location.lng.toFixed(6));
            if (query.radius) params.set('radius', query.radius);
        }
        Object.entries(query).forEach(([k, v]) => {
            if (k === 'radius') return;
            if (typeof v === 'boolean') {
                if (v) params.set(k, '1');
            } else if (v) params.set(k, v);
        });

        const controller = new AbortController();
        setLoading(true);
        setError(null);
        fetch(`/stations/search?${params}`, { headers: { Accept: 'application/json' }, signal: controller.signal })
            .then((r) => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
            .then((json) => setStations(json.data))
            .catch((e) => e.name !== 'AbortError' && setError('Could not load stations. Please try again.'))
            .finally(() => setLoading(false));
        return () => controller.abort();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [query, location?.lat, location?.lng]);

    const activeFilters = useMemo(
        () => [query.type, query.connector, query.min_kw, query.open_now, query.available, query.radius].filter(Boolean).length,
        [query],
    );
    const nearest = location ? stations[0] : null;

    return (
        <SiteLayout hideFooter>
            <Head title="Find EV charging stations near you" />

            <div className="flex h-[calc(100dvh-4rem)] flex-col">
                {/* Search bar */}
                <div className="z-20 border-b border-border bg-background/95 px-4 py-3 backdrop-blur sm:px-6">
                    <div className="mx-auto flex max-w-7xl flex-wrap items-center gap-2">
                        <div className="relative min-w-0 flex-1">
                            <Search className="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted-foreground" />
                            <input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search city, area, station or PIN code"
                                className="h-11 w-full rounded-full border border-input bg-card pr-10 pl-10 text-sm outline-none focus:border-volt focus:ring-2 focus:ring-volt/30"
                                list="cities"
                            />
                            <datalist id="cities">{filters.cities.map((c) => <option key={c} value={c} />)}</datalist>
                            {search && (
                                <button onClick={() => setSearch('')} className="absolute top-1/2 right-3 -translate-y-1/2 text-muted-foreground" aria-label="Clear search">
                                    <X className="size-4" />
                                </button>
                            )}
                        </div>

                        <button
                            onClick={locate}
                            disabled={geo.status === 'locating'}
                            className="flex h-11 items-center gap-2 rounded-full bg-gradient-to-r from-volt to-electric px-4 text-sm font-semibold text-white shadow-md shadow-volt/20 transition hover:brightness-110 disabled:opacity-70"
                        >
                            {geo.status === 'locating' ? <LoaderCircle className="size-4 animate-spin" /> : <LocateFixed className="size-4" />}
                            <span className="hidden sm:inline">{location ? 'Update location' : 'Near me'}</span>
                        </button>

                        <button
                            onClick={() => setShowFilters((v) => !v)}
                            className={cn('relative flex h-11 items-center gap-2 rounded-full border border-input bg-card px-4 text-sm font-medium', showFilters && 'border-volt')}
                        >
                            <SlidersHorizontal className="size-4" />
                            <span className="hidden sm:inline">Filters</span>
                            {activeFilters > 0 && <span className="flex size-5 items-center justify-center rounded-full bg-volt text-[11px] font-bold text-ink">{activeFilters}</span>}
                        </button>

                        <div className="flex h-11 rounded-full border border-input bg-card p-1 lg:hidden">
                            {(['map', 'list'] as const).map((v) => (
                                <button
                                    key={v}
                                    onClick={() => setView(v)}
                                    className={cn('flex items-center gap-1.5 rounded-full px-3 text-sm font-medium capitalize', view === v && 'bg-ink text-white dark:bg-volt dark:text-ink')}
                                >
                                    {v === 'map' ? <MapIcon className="size-4" /> : <List className="size-4" />}
                                    {v}
                                </button>
                            ))}
                        </div>
                    </div>

                    {showFilters && (
                        <div className="mx-auto mt-3 grid max-w-7xl grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                            <FilterSelect label="Charger type" value={query.type} onChange={(v) => setQuery({ ...query, type: v })} options={filters.types} />
                            <FilterSelect label="Connector" value={query.connector} onChange={(v) => setQuery({ ...query, connector: v })} options={filters.connectors} />
                            <FilterSelect
                                label="Min. power"
                                value={query.min_kw}
                                onChange={(v) => setQuery({ ...query, min_kw: v })}
                                options={['7', '22', '30', '60', '120'].map((k) => ({ value: k, label: `${k}+ kW` }))}
                            />
                            <FilterSelect
                                label={location ? 'Distance' : 'Distance (use Near me)'}
                                value={query.radius}
                                onChange={(v) => setQuery({ ...query, radius: v })}
                                options={['5', '10', '25', '50', '100'].map((k) => ({ value: k, label: `Within ${k} km` }))}
                                disabled={!location}
                            />
                            <FilterToggle label="Open now" checked={query.open_now} onChange={(v) => setQuery({ ...query, open_now: v })} />
                            <FilterToggle label="Available to book" checked={query.available} onChange={(v) => setQuery({ ...query, available: v })} />
                            {activeFilters > 0 && (
                                <button onClick={() => setQuery({ ...EMPTY, q: query.q })} className="col-span-full justify-self-start text-sm font-medium text-volt-600 hover:underline">
                                    Clear all filters
                                </button>
                            )}
                        </div>
                    )}
                </div>

                <div className="relative flex min-h-0 flex-1">
                    {/* List */}
                    <aside className={cn('w-full overflow-y-auto bg-muted/30 p-4 lg:block lg:w-[440px] lg:border-r lg:border-border', view === 'list' ? 'block' : 'hidden')}>
                        {geo.status === 'error' && (
                            <div className="mb-3 flex gap-2 rounded-xl border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-900 dark:text-amber-100">
                                <AlertCircle className="mt-0.5 size-4 shrink-0" />
                                {geo.message}
                            </div>
                        )}
                        {geo.status === 'idle' && (
                            <button
                                onClick={locate}
                                className="mb-3 flex w-full items-center gap-3 rounded-xl border border-dashed border-volt/50 bg-volt/5 p-3 text-left text-sm transition hover:bg-volt/10"
                            >
                                <LocateFixed className="size-5 text-volt-600" />
                                <span>
                                    <strong className="block">Find the nearest charger</strong>
                                    <span className="text-muted-foreground">Share your location to sort stations by distance.</span>
                                </span>
                            </button>
                        )}
                        {nearest && (
                            <p className="mb-3 text-sm text-muted-foreground">
                                Nearest: <strong className="text-foreground">{nearest.name}</strong> · {nearest.distanceKm?.toFixed(1)} km
                            </p>
                        )}

                        <div className="mb-2 flex items-center justify-between text-sm">
                            <span className="font-medium">{loading ? 'Searching…' : `${stations.length} station${stations.length === 1 ? '' : 's'}`}</span>
                            {location && <span className="text-xs text-muted-foreground">Sorted by distance</span>}
                        </div>

                        {error && <p className="rounded-xl bg-destructive/10 p-3 text-sm text-destructive">{error}</p>}

                        <div className="space-y-3">
                            {loading && stations.length === 0
                                ? Array.from({ length: 4 }).map((_, i) => <div key={i} className="h-48 animate-pulse rounded-2xl bg-muted" />)
                                : stations.map((s) => <StationCardView key={s.slug} station={s} active={hovered === s.slug} onHover={setHovered} />)}
                            {!loading && !error && stations.length === 0 && (
                                <div className="rounded-2xl border border-border bg-card p-8 text-center">
                                    <p className="font-display font-semibold">No stations match</p>
                                    <p className="mt-1 text-sm text-muted-foreground">Try another area or clear some filters.</p>
                                </div>
                            )}
                        </div>
                    </aside>

                    {/* Map */}
                    <section className={cn('relative min-h-0 flex-1 lg:block', view === 'map' ? 'block' : 'hidden')}>
                        <div className="absolute inset-0">
                            <StationMap stations={stations} styleUrl={mapStyle} userLocation={location} highlighted={hovered} className="h-full w-full" />
                        </div>
                        <div className="pointer-events-none absolute bottom-4 left-4 flex flex-wrap gap-2 rounded-xl bg-background/90 px-3 py-2 text-xs shadow-lg backdrop-blur">
                            {[
                                ['#00B386', 'Online'],
                                ['#F59E0B', 'Maintenance'],
                                ['#EF4444', 'Offline'],
                                ['#94A3B8', 'Closed now'],
                            ].map(([c, l]) => (
                                <span key={l} className="flex items-center gap-1.5">
                                    <span className="size-2.5 rounded-full" style={{ background: c }} />
                                    {l}
                                </span>
                            ))}
                        </div>
                    </section>
                </div>
            </div>
        </SiteLayout>
    );
}

function FilterSelect({ label, value, onChange, options, disabled }: { label: string; value: string; onChange: (v: string) => void; options: Option[]; disabled?: boolean }) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs font-medium text-muted-foreground">{label}</span>
            <select
                value={value}
                disabled={disabled}
                onChange={(e) => onChange(e.target.value)}
                className="h-10 w-full rounded-xl border border-input bg-card px-3 text-sm outline-none focus:border-volt disabled:opacity-50"
            >
                <option value="">Any</option>
                {options.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </select>
        </label>
    );
}

function FilterToggle({ label, checked, onChange }: { label: string; checked: boolean; onChange: (v: boolean) => void }) {
    return (
        <label className="flex h-10 cursor-pointer items-center gap-2 self-end rounded-xl border border-input bg-card px-3 text-sm">
            <input type="checkbox" checked={checked} onChange={(e) => onChange(e.target.checked)} className="size-4 accent-[#00B386]" />
            {label}
        </label>
    );
}
