import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ArrowUpRight, BatteryCharging, Briefcase, Building2, Car, Clock, Lightbulb, Megaphone, Route, Wallet, Zap } from 'lucide-react';

export interface BlogCardData {
    slug: string;
    title: string;
    excerpt: string;
    cover: string | null;
    coverAlt: string;
    category: { name: string; slug: string; color: string } | null;
    author: string;
    publishedAt: string | null;
    date: string | null;
    readingMinutes: number;
}

export const CATEGORY_STYLES: Record<string, { pill: string; grad: string; icon: typeof Zap }> = {
    emerald: { pill: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300', grad: 'from-emerald-500 via-teal-500 to-sky-600', icon: Lightbulb },
    sky: { pill: 'bg-sky-500/10 text-sky-700 dark:text-sky-300', grad: 'from-sky-500 via-blue-600 to-indigo-700', icon: Zap },
    amber: { pill: 'bg-amber-500/10 text-amber-700 dark:text-amber-300', grad: 'from-amber-400 via-orange-500 to-rose-500', icon: Wallet },
    violet: { pill: 'bg-violet-500/10 text-violet-700 dark:text-violet-300', grad: 'from-violet-500 via-purple-600 to-indigo-700', icon: Briefcase },
    teal: { pill: 'bg-teal-500/10 text-teal-700 dark:text-teal-300', grad: 'from-teal-400 via-cyan-500 to-blue-600', icon: Route },
    rose: { pill: 'bg-rose-500/10 text-rose-700 dark:text-rose-300', grad: 'from-rose-500 via-pink-500 to-violet-600', icon: Megaphone },
};

const FALLBACK_ICONS = [BatteryCharging, Car, Building2];

export function categoryStyle(color?: string) {
    return CATEGORY_STYLES[color ?? 'emerald'] ?? CATEGORY_STYLES.emerald;
}

/** Cover image, or a branded gradient cover when a post has no image. */
export function BlogCover({ post, className, large }: { post: BlogCardData; className?: string; large?: boolean }) {
    const st = categoryStyle(post.category?.color);
    const Icon = st.icon ?? FALLBACK_ICONS[post.title.length % 3];

    if (post.cover) {
        return <img src={post.cover} alt={post.coverAlt} loading="lazy" className={cn('h-full w-full object-cover', className)} />;
    }
    return (
        <div className={cn('relative flex h-full w-full items-center justify-center overflow-hidden bg-gradient-to-br', st.grad, className)} role="img" aria-label={post.coverAlt}>
            <div className="absolute inset-0 bg-[radial-gradient(circle_at_20%_20%,rgba(255,255,255,0.35),transparent_45%)]" />
            <div className="absolute -right-10 -bottom-10 size-48 rounded-full bg-white/10" />
            <div className="absolute top-6 -left-8 size-24 rounded-full bg-white/10" />
            <Icon className={cn('relative text-white/90 drop-shadow-lg', large ? 'size-24' : 'size-14')} strokeWidth={1.5} />
            <img src="/brand/logo-mark.svg" alt="" className="absolute right-4 bottom-4 size-8 opacity-80" />
        </div>
    );
}

export default function BlogCard({ post }: { post: BlogCardData }) {
    const st = categoryStyle(post.category?.color);

    return (
        <article className="group relative flex flex-col overflow-hidden rounded-3xl border border-border bg-card transition duration-300 hover:-translate-y-1 hover:shadow-2xl hover:shadow-black/5">
            <div className="aspect-[16/9] overflow-hidden">
                <BlogCover post={post} className="transition duration-500 group-hover:scale-105" />
            </div>
            <div className="flex flex-1 flex-col p-6">
                <div className="flex items-center gap-3 text-xs">
                    {post.category && <span className={cn('rounded-full px-2.5 py-1 font-semibold', st.pill)}>{post.category.name}</span>}
                    <span className="flex items-center gap-1 text-muted-foreground">
                        <Clock className="size-3.5" /> {post.readingMinutes} min read
                    </span>
                </div>
                <h3 className="mt-4 font-display text-xl leading-snug font-bold text-foreground">
                    <Link href={`/blog/${post.slug}`} className="after:absolute after:inset-0">
                        {post.title}
                    </Link>
                </h3>
                <p className="mt-2 line-clamp-3 text-sm text-muted-foreground">{post.excerpt}</p>
                <div className="mt-auto flex items-center justify-between pt-5 text-xs text-muted-foreground">
                    <span>
                        {post.author} · {post.date}
                    </span>
                    <ArrowUpRight className="size-5 text-foreground/40 transition group-hover:translate-x-0.5 group-hover:-translate-y-0.5 group-hover:text-volt-600" />
                </div>
            </div>
        </article>
    );
}
