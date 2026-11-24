import { type StationCard } from '@/types/station';
import maplibregl from 'maplibre-gl';
import { type Map as MapLibreMap, type Marker } from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { useEffect, useRef } from 'react';
import { STATUS_STYLES, inr, stationState } from './station-bits';

const MARKER_COLORS = { online: '#00B386', offline: '#EF4444', maintenance: '#F59E0B', closed: '#94A3B8' } as const;
const INDIA_CENTER: [number, number] = [78.9629, 22.5937];

function markerElement(station: StationCard) {
    const state = stationState(station);
    const wrap = document.createElement('div');
    const el = document.createElement('button');
    wrap.appendChild(el);
    el.type = 'button';
    el.setAttribute('aria-label', station.name);
    el.className = 'tev-marker';
    el.style.cssText = `width:34px;height:34px;border-radius:9999px;display:flex;align-items:center;justify-content:center;
        background:${MARKER_COLORS[state]};border:3px solid white;box-shadow:0 4px 14px rgb(0 0 0 / .25);cursor:pointer;transition:transform .15s`;
    el.innerHTML = `<svg width="16" height="16" viewBox="0 0 24 24" fill="white"><path d="M13 2 4 14h7l-1 8 9-12h-7z"/></svg>`;
    if (state === 'online') el.classList.add('animate-charge-pulse');
    return wrap;
}

function popupHtml(s: StationCard) {
    const state = stationState(s);
    const esc = (v: string) => v.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!);
    return `<a href="/stations/${encodeURIComponent(s.slug)}" style="display:block;padding:12px 14px;min-width:220px;text-decoration:none;color:inherit">
        <div style="font-weight:600;font-size:14px;margin-bottom:2px">${esc(s.name)}</div>
        <div style="font-size:12px;opacity:.7;margin-bottom:8px">${esc(s.address)}</div>
        <div style="display:flex;gap:10px;align-items:center;font-size:12px">
            <span style="color:${MARKER_COLORS[state]};font-weight:600">● ${STATUS_STYLES[state].label}</span>
            <span>⚡ ${s.maxPowerKw} kW</span>
            ${s.fromRate !== null ? `<span>${inr(s.fromRate, 0)}/kWh</span>` : ''}
            ${s.distanceKm !== null ? `<span>${s.distanceKm.toFixed(1)} km</span>` : ''}
        </div></a>`;
}

interface Props {
    stations: StationCard[];
    styleUrl: string;
    userLocation?: { lat: number; lng: number } | null;
    highlighted?: string | null;
    className?: string;
    single?: boolean;
}

export default function StationMap({ stations, styleUrl, userLocation, highlighted, className, single = false }: Props) {
    const container = useRef<HTMLDivElement>(null);
    const map = useRef<MapLibreMap | null>(null);
    const markers = useRef<Map<string, Marker>>(new Map());
    const userMarker = useRef<Marker | null>(null);

    useEffect(() => {
        if (!container.current) return;
        const instance = new maplibregl.Map({
            container: container.current,
            style: styleUrl,
            center: INDIA_CENTER,
            zoom: 4,
            attributionControl: { compact: true },
            cooperativeGestures: single,
        });
        map.current = instance;
        instance.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
        if (!single) {
            instance.addControl(new maplibregl.GeolocateControl({ positionOptions: { enableHighAccuracy: true }, trackUserLocation: false }), 'top-right');
        }
        return () => {
            instance.remove();
            map.current = null;
        };
    }, [styleUrl, single]);

    // Sync markers with the station list and fit the view.
    useEffect(() => {
        const m = map.current;
        if (!m) return;
        markers.current.forEach((mk) => mk.remove());
        markers.current.clear();

        const bounds = new maplibregl.LngLatBounds();
        stations.forEach((s) => {
            const marker = new maplibregl.Marker({ element: markerElement(s) })
                .setLngLat([s.lng, s.lat])
                .setPopup(new maplibregl.Popup({ offset: 22, closeButton: false }).setHTML(popupHtml(s)))
                .addTo(m);
            markers.current.set(s.slug, marker);
            bounds.extend([s.lng, s.lat]);
        });
        if (userLocation) bounds.extend([userLocation.lng, userLocation.lat]);

        if (!bounds.isEmpty()) {
            if (single || stations.length === 1) {
                m.jumpTo({ center: bounds.getCenter(), zoom: 14 });
            } else {
                m.fitBounds(bounds, { padding: 60, maxZoom: 13, duration: 600 });
            }
        }
    }, [stations, userLocation, single]);

    useEffect(() => {
        const m = map.current;
        if (!m) return;
        userMarker.current?.remove();
        if (userLocation) {
            const el = document.createElement('div');
            el.style.cssText = 'width:18px;height:18px;border-radius:9999px;background:#0077FF;border:3px solid white;box-shadow:0 0 0 6px rgb(0 119 255 / .25)';
            userMarker.current = new maplibregl.Marker({ element: el }).setLngLat([userLocation.lng, userLocation.lat]).addTo(m);
        }
    }, [userLocation]);

    useEffect(() => {
        markers.current.forEach((mk, slug) => {
            const inner = mk.getElement().firstElementChild as HTMLElement | null;
            if (inner) inner.style.transform = slug === highlighted ? 'scale(1.3)' : '';
            mk.getElement().style.zIndex = slug === highlighted ? '10' : '';
        });
    }, [highlighted]);

    return <div ref={container} className={className} />;
}
