import { cn } from '@/lib/utils';

/** Trans EV logo mark (replaces the starter-kit icon everywhere it was used). */
export default function AppLogoIcon({ className }: { className?: string }) {
    return <img src="/brand/logo-mark.svg" alt="" className={cn('object-contain', className)} />;
}
