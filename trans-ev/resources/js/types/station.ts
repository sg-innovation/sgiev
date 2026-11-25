export type StationStatus = 'online' | 'offline' | 'maintenance';

export interface Option {
    value: string;
    label: string;
}

export interface StationCard {
    slug: string;
    name: string;
    code: string;
    operator: { name: string | null; isPlatform: boolean };
    address: string;
    city: string;
    state: string;
    lat: number;
    lng: number;
    distanceKm: number | null;
    status: StationStatus;
    statusLabel: string;
    statusReason: string | null;
    statusUntil: string | null;
    isOpen: boolean;
    is24x7: boolean;
    todayHours: string | null;
    bookable: boolean;
    chargersTotal: number;
    chargersOnline: number;
    guns: { total: number; available: number; busy: number; reserved: number; down: number };
    maxPowerKw: number;
    types: ('AC' | 'DC')[];
    connectors: Option[];
    fromRate: number | null;
    amenities: string[];
    rating: { avg: number; count: number } | null;
}

export interface StationTariff {
    name: string;
    appliesTo: string;
    ratePerKwh: number;
    ratePerKwhInclGst: number;
    sessionFee: number;
    idleFeePerMin: number;
    idleGraceMinutes: number;
    gstRate: number;
    sacCode: string;
    bands: { name: string; days: string; from: string; to: string; rate: number }[];
    parkingFee: number;
    parkingFeePerHour: number;
}

export interface StationDetail extends StationCard {
    description: string | null;
    landmark: string | null;
    fullAddress: string;
    phone: string | null;
    email: string | null;
    operatorLegalName: string | null;
    slotMinutes: number;
    photos: string[];
    parking: { type: string | null; note: string | null } | null;
    hours: { day: string; weekday: number; closed: boolean; opens: string | null; closes: string | null }[];
    holidays: { date: string; label: string | null; closed: boolean; opens: string | null; closes: string | null }[];
    chargers: {
        code: string; name: string | null; electrical: string | null; standard: string | null; type: 'AC' | 'DC'; powerKw: number; connectors: string[]; status: StationStatus; statusLabel: string;
        units: { no: number; type: string; live: string; liveLabel: string }[];
    }[];
    tariffs: StationTariff[];
}
