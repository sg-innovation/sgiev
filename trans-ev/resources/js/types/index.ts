import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
    isStaff: boolean;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
}

export interface Brand {
    name: string;
    legalName: string | null;
    email: string | null;
    phone: string | null;
    address: string | null;
    gstin: string | null;
    whatsapp: string | null;
    social: Partial<Record<'facebook' | 'instagram' | 'x' | 'linkedin' | 'youtube' | 'whatsapp', string>>;
}

export interface SharedData {
    name: string;
    quote?: { message: string; author: string };
    auth: Auth;
    brand: Brand;
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}
