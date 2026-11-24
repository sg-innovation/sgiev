import { cn } from '@/lib/utils';
import { Check } from 'lucide-react';

const CHECKS = [
    { label: '10+ characters', test: (p: string) => p.length >= 10 },
    { label: 'Upper & lower case', test: (p: string) => /[a-z]/.test(p) && /[A-Z]/.test(p) },
    { label: 'A number', test: (p: string) => /\d/.test(p) },
    { label: 'A symbol', test: (p: string) => /[^A-Za-z0-9]/.test(p) },
];

const LEVELS = ['bg-red-500', 'bg-orange-500', 'bg-amber-500', 'bg-lime-500', 'bg-emerald-500'];

/** Live password-strength hint matching the server rule (Password::defaults()). */
export default function PasswordStrength({ password }: { password: string }) {
    if (!password) return null;
    const passed = CHECKS.filter((c) => c.test(password)).length;

    return (
        <div className="space-y-2">
            <div className="flex gap-1">
                {CHECKS.map((_, i) => (
                    <span key={i} className={cn('h-1.5 flex-1 rounded-full bg-muted transition-colors', i < passed && LEVELS[passed])} />
                ))}
            </div>
            <ul className="grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                {CHECKS.map((c) => {
                    const ok = c.test(password);
                    return (
                        <li key={c.label} className={cn('flex items-center gap-1.5', ok ? 'text-emerald-600 dark:text-emerald-400' : 'text-muted-foreground')}>
                            <Check className={cn('size-3.5', !ok && 'opacity-30')} /> {c.label}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}
