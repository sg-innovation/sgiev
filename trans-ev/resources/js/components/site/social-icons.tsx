import { type Brand } from '@/types';
import { Facebook, Instagram, Linkedin, Youtube } from 'lucide-react';
import { type ReactNode } from 'react';

function XIcon({ className }: { className?: string }) {
    return (
        <svg viewBox="0 0 24 24" aria-hidden="true" className={className} fill="currentColor">
            <path d="M18.901 1.153h3.68l-8.04 9.19L24 22.846h-7.406l-5.8-7.584-6.638 7.584H.474l8.6-9.83L0 1.154h7.594l5.243 6.932ZM17.61 20.644h2.039L6.486 3.24H4.298Z" />
        </svg>
    );
}

function WhatsAppIcon({ className }: { className?: string }) {
    return (
        <svg viewBox="0 0 24 24" aria-hidden="true" className={className} fill="currentColor">
            <path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.2-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6a2.7 2.7 0 0 0 1.8-1.2 2.2 2.2 0 0 0 .2-1.2c-.1-.1-.3-.2-.5-.3Z" />
        </svg>
    );
}

const NETWORKS: { key: keyof Brand['social']; label: string; icon: (c: string) => ReactNode }[] = [
    { key: 'facebook', label: 'Facebook', icon: (c) => <Facebook className={c} /> },
    { key: 'instagram', label: 'Instagram', icon: (c) => <Instagram className={c} /> },
    { key: 'x', label: 'X (Twitter)', icon: (c) => <XIcon className={c} /> },
    { key: 'linkedin', label: 'LinkedIn', icon: (c) => <Linkedin className={c} /> },
    { key: 'youtube', label: 'YouTube', icon: (c) => <Youtube className={c} /> },
    { key: 'whatsapp', label: 'WhatsApp', icon: (c) => <WhatsAppIcon className={c} /> },
];

export default function SocialIcons({ links }: { links: Brand['social'] }) {
    const items = NETWORKS.filter((n) => links?.[n.key]);
    if (items.length === 0) return null;

    return (
        <ul className="flex flex-wrap gap-2">
            {items.map((n) => (
                <li key={n.key}>
                    <a
                        href={links[n.key]}
                        target="_blank"
                        rel="noopener noreferrer"
                        aria-label={`Trans EV on ${n.label}`}
                        className="flex size-10 items-center justify-center rounded-full bg-white/10 text-white/80 ring-1 ring-white/10 transition hover:-translate-y-0.5 hover:bg-volt hover:text-ink"
                    >
                        {n.icon('size-[18px]')}
                    </a>
                </li>
            ))}
        </ul>
    );
}
