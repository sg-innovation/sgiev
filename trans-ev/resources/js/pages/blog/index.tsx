import BlogCard, { BlogCover, categoryStyle, type BlogCardData } from '@/components/site/blog-card';
import SiteLayout from '@/layouts/site-layout';
import { cn } from '@/lib/utils';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowRight, Clock, Hash, Newspaper, Search, X } from 'lucide-react';
import { type FormEvent, useState } from 'react';

interface Props {
    posts: { data: BlogCardData[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
    featured: BlogCardData | null;
    categories: { name: string; slug: string; color: string; count: number }[];
    popularTags: { name: string; slug: string }[];
    active: { category: { name: string; slug: string; description: string | null } | null; tag: { name: string; slug: string } | null; q: string };
}

export default function BlogIndex({ posts, featured, categories, popularTags, active }: Props) {
    const [q, setQ] = useState(active.q);
    const base = active.category ? `/blog/category/${active.category.slug}` : active.tag ? `/blog/tag/${active.tag.slug}` : '/blog';

    const search = (e: FormEvent) => {
        e.preventDefault();
        router.get(base, q ? { q } : {}, { preserveState: true, preserveScroll: true });
    };

    const heading = active.category?.name ?? (active.tag ? `#${active.tag.name}` : 'The Trans EV Blog');
    const sub = active.category?.description ?? (active.tag ? `Articles tagged “${active.tag.name}”.` : 'Guides, tips and news to help you charge smarter and drive further.');
    const list = featured ? posts.data.filter((p) => p.slug !== featured.slug) : posts.data;

    return (
        <SiteLayout>
            <Head title={active.category ? `${active.category.name} – Blog` : active.tag ? `#${active.tag.name} – Blog` : 'Blog – EV charging guides & news'}>
                <meta name="description" content={sub} />
            </Head>

            <section className="relative overflow-hidden bg-ink text-white">
                <div className="pointer-events-none absolute -top-32 -right-24 size-96 rounded-full bg-volt/25 blur-[100px]" />
                <div className="pointer-events-none absolute -bottom-40 left-10 size-96 rounded-full bg-electric/25 blur-[100px]" />
                <div className="relative mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
                    <span className="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-volt ring-1 ring-white/15">
                        <Newspaper className="size-3.5" /> Blog
                    </span>
                    <h1 className="mt-5 max-w-3xl font-display text-4xl font-extrabold tracking-tight sm:text-5xl">{heading}</h1>
                    <p className="mt-4 max-w-2xl text-lg text-white/70">{sub}</p>
                    <form onSubmit={search} className="mt-8 flex max-w-xl items-center gap-2 rounded-2xl bg-white/10 p-2 ring-1 ring-white/15 backdrop-blur">
                        <Search className="ml-2 size-5 shrink-0 text-white/50" />
                        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search articles" className="h-11 flex-1 bg-transparent text-white placeholder:text-white/50 focus:outline-none" aria-label="Search articles" />
                        {active.q && (
                            <button type="button" onClick={() => router.get(base)} className="p-2 text-white/60 hover:text-white" aria-label="Clear search">
                                <X className="size-4" />
                            </button>
                        )}
                        <button type="submit" className="h-11 rounded-xl bg-white px-5 font-semibold text-ink">Search</button>
                    </form>
                </div>
            </section>

            {/* Category bar */}
            <div className="sticky top-16 z-30 border-b border-border bg-background/85 backdrop-blur-xl">
                <nav className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8" aria-label="Blog categories">
                    <ul className="-mb-px flex gap-1 overflow-x-auto [scrollbar-width:none]">
                        <li className="shrink-0">
                            <Link href="/blog" className={cn('block border-b-2 px-3 py-4 text-sm font-medium whitespace-nowrap', !active.category && !active.tag ? 'border-volt text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground')}>
                                All articles
                            </Link>
                        </li>
                        {categories.map((c) => (
                            <li key={c.slug} className="shrink-0">
                                <Link
                                    href={`/blog/category/${c.slug}`}
                                    className={cn('flex items-center gap-1.5 border-b-2 px-3 py-4 text-sm font-medium whitespace-nowrap', active.category?.slug === c.slug ? 'border-volt text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground')}
                                >
                                    {c.name}
                                    <span className="rounded-full bg-muted px-1.5 text-[11px]">{c.count}</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </nav>
            </div>

            <div className="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
                {/* Featured */}
                {featured && (
                    <Link href={`/blog/${featured.slug}`} className="group mb-14 grid overflow-hidden rounded-[2rem] border border-border bg-card transition hover:shadow-2xl lg:grid-cols-2">
                        <div className="aspect-[16/10] overflow-hidden lg:aspect-auto">
                            <BlogCover post={featured} large className="transition duration-500 group-hover:scale-105" />
                        </div>
                        <div className="flex flex-col justify-center p-8 sm:p-12">
                            <div className="flex items-center gap-3 text-xs">
                                <span className="rounded-full bg-volt/15 px-2.5 py-1 font-semibold text-volt-600">Featured</span>
                                {featured.category && <span className={cn('rounded-full px-2.5 py-1 font-semibold', categoryStyle(featured.category.color).pill)}>{featured.category.name}</span>}
                            </div>
                            <h2 className="mt-5 font-display text-3xl leading-tight font-extrabold text-foreground sm:text-4xl">{featured.title}</h2>
                            <p className="mt-4 text-lg text-muted-foreground">{featured.excerpt}</p>
                            <div className="mt-8 flex items-center justify-between">
                                <span className="flex items-center gap-2 text-sm text-muted-foreground">
                                    {featured.author} · {featured.date} · <Clock className="size-4" /> {featured.readingMinutes} min
                                </span>
                                <span className="inline-flex items-center gap-1.5 font-semibold text-volt-600">
                                    Read article <ArrowRight className="size-4 transition group-hover:translate-x-1" />
                                </span>
                            </div>
                        </div>
                    </Link>
                )}

                {active.q && (
                    <p className="mb-6 text-muted-foreground">
                        {posts.total} result{posts.total === 1 ? '' : 's'} for <strong className="text-foreground">“{active.q}”</strong>
                    </p>
                )}

                {list.length === 0 ? (
                    <div className="rounded-3xl border border-dashed border-border p-16 text-center">
                        <Newspaper className="mx-auto size-10 text-muted-foreground" />
                        <p className="mt-4 font-display text-lg font-semibold text-foreground">No articles found</p>
                        <Link href="/blog" className="mt-3 inline-block font-semibold text-volt-600 hover:underline">See all articles</Link>
                    </div>
                ) : (
                    <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                        {list.map((p) => (
                            <BlogCard key={p.slug} post={p} />
                        ))}
                    </div>
                )}

                {posts.links.length > 3 && (
                    <nav className="mt-12 flex flex-wrap justify-center gap-1" aria-label="Pagination">
                        {posts.links.map((l, i) => (
                            <Link
                                key={i}
                                href={l.url ?? '#'}
                                preserveScroll
                                className={cn('min-w-10 rounded-xl px-3 py-2 text-center text-sm font-medium', l.active ? 'bg-ink text-white dark:bg-volt dark:text-ink' : 'text-muted-foreground hover:bg-muted', !l.url && 'pointer-events-none opacity-40')}
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ))}
                    </nav>
                )}

                {popularTags.length > 0 && (
                    <section className="mt-16 rounded-3xl border border-border bg-card p-8">
                        <h2 className="font-display text-lg font-semibold text-foreground">Popular topics</h2>
                        <div className="mt-4 flex flex-wrap gap-2">
                            {popularTags.map((t) => (
                                <Link
                                    key={t.slug}
                                    href={`/blog/tag/${t.slug}`}
                                    className={cn('inline-flex items-center gap-1 rounded-full border px-3.5 py-1.5 text-sm transition', active.tag?.slug === t.slug ? 'border-volt bg-volt/10 text-volt-600' : 'border-border text-muted-foreground hover:border-volt/50 hover:text-foreground')}
                                >
                                    <Hash className="size-3.5" /> {t.name}
                                </Link>
                            ))}
                        </div>
                    </section>
                )}
            </div>
        </SiteLayout>
    );
}
