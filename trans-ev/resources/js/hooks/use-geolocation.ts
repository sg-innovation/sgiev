import { useCallback, useState } from 'react';

export type GeoState =
    | { status: 'idle' }
    | { status: 'locating' }
    | { status: 'ok'; lat: number; lng: number; accuracy: number }
    | { status: 'error'; message: string };

/** Browser GPS location. Only requested when the user asks (no prompt on page load). */
export function useGeolocation() {
    const [state, setState] = useState<GeoState>({ status: 'idle' });

    const locate = useCallback(() => {
        if (!('geolocation' in navigator)) {
            setState({ status: 'error', message: 'Location is not supported by this browser. Search by city or PIN code instead.' });
            return;
        }
        setState({ status: 'locating' });
        navigator.geolocation.getCurrentPosition(
            (pos) => setState({ status: 'ok', lat: pos.coords.latitude, lng: pos.coords.longitude, accuracy: pos.coords.accuracy }),
            (err) =>
                setState({
                    status: 'error',
                    message:
                        err.code === err.PERMISSION_DENIED
                            ? 'Location permission was denied. Allow location access or search by city or PIN code.'
                            : 'We could not get your location. Try again or search by city.',
                }),
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 },
        );
    }, []);

    return { state, locate };
}
