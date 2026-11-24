import BlogCard, { BlogCover, categoryStyle, type BlogCardData } from '@/components/site/blog-card';
import SiteLayout from '@/layouts/site-layout';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Check, Clock, Eye, Hash, Link2, LocateFixed, MessageCircle } from 'lucide-react';
import { useState } from 'react';

interface Post extends BlogCardData {
    content: string;
    toc: { id: string; text: string; level: number }[];
    tags: { name: string; slug: string }[];
    metaTitle: string;
    metaDescription: string;
    allowIndexing: boolean;
    isPreview: boolean;
    url: string;
    updatedAt: string;
}

export default function BlogShow({ post, related }: { post: Post; related: BlogCardData[] }) {
    const { brand } = usePage<SharedData>().props;
    const [copied, setCopied] = useState(false);
    const st = categoryStyle(post.category?.color);
    const share = encodeURIComponent(post.url);
    const text = encodeURIComponent(post.title);

    const jsonLd = {
        '@context': 'https://schema.org',
        '@type': 'BlogPosting',
        headline: post.title,
        description: post.metaDescription,
        datePublished: post.publishedAt,
        dateModified: post.updatedAt,
        author: { '@type': 'Person', name: post.author },
        publisher: { '@type': 'Organization', name: brand.legalName ?? 'Trans EV', logo: { '@type': 'ImageObject', url: '/brand/logo.svg' } },
        mainEntityOfPage: post.url,
        ...(post.cover ? { image: post.cover } : {}),
    };

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(post.url);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            /* clipboard unavailable */
        }
    };

    return (
        <SiteLayout>
            <Head title={post.metaTitle}>
                <meta name="description" content={post.metaDescription} />
                {!post.allowIndexing || post.isPreview ? <meta name="robots" content="noindex" /> : null}
                <meta property="og:type" content="article" />
                <meta property="og:title" content={post.metaTitle} />
                <meta property="og:description" content={post.metaDescription} />
                {post.cover && <meta property="og:image" content={post.cover} />}
                <link rel="canonical" href={post.url} />
                <script type="application/ld+json">{JSON.stringify(jsonLd)}</script>
            </Head>

            {post.isPreview && (
                <div className="bg-amber-400 px-4 py-2 text-center text-sm font-semibold text-ink">
                    <Eye className="mr-1.5 inline size-4" /> Preview: this post is not published yet and is only visible to blog editors.
                </div>
            )}

            <header className="relative overflow-hidden bg-ink text-white">
                <div className="pointer-events-none absolute -top-32 -right-24 size-96 rounded-full bg-volt/20 blur-[100px]" />
                <div className="relative mx-auto max-w-4xl px-4 pt-12 pb-40 text-center sm:px-6">
                    <Link href="/blog" className="inline-flex items-center gap-1.5 text-sm text-white/70 hover:text-white">
                        <ArrowLeft className="size-4" /> All articles
                    </Link>
                    {post.category && (
                        <div className="mt-6">
                            <Link href={`/blog/category/${post.category.slug}`} className="rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-white ring-1 ring-white/20 hover:bg-white/15">
                                {post.category.name}
                            </Link>
                        </div>
                    )}
                    <h1 className="mt-5 font-display text-3xl leading-tight font-extrabold tracking-tight sm:text-5xl">{post.title}</h1>
                    <p className="mx-auto mt-5 max-w-2xl text-lg text-white/70">{post.excerpt}</p>
                    <p className="mt-6 flex flex-wrap items-center justify-center gap-x-3 gap-y-1 text-sm text-white/60">
                        <span className="font-medium text-white/85">{post.author}</span>·<span>{post.date}</span>·
                        <span className="flex items-center gap-1"><Clock className="size-4" /> {post.readingMinutes} min read</span>
                    </p>
                </div>
            </header>

            <div className="mx-auto -mt-28 max-w-5xl px-4 sm:px-6">
                <div className="aspect-[16/8] overflow-hidden rounded-[2rem] shadow-2xl ring-1 ring-black/5">
                    <BlogCover post={post} large />
                </div>
            </div>

            <div className="mx-auto grid max-w-6xl gap-12 px-4 py-14 sm:px-6 lg:grid-cols-[1fr_240px]">
                <article className="min-w-0">
                    <div className="blog-prose" dangerouslySetInnerHTML={{ __html: post.content }} />

                    {post.tags.length > 0 && (
                        <div className="mt-12 flex flex-wrap gap-2 border-t border-border pt-8">
                            {post.tags.map((t) => (
                                <Link key={t.slug} href={`/blog/tag/${t.slug}`} className="inline-flex items-center gap-1 rounded-full border border-border px-3 py-1.5 text-sm text-muted-foreground hover:border-volt/50 hover:text-foreground">
                                    <Hash className="size-3.5" /> {t.name}
                                </Link>
                            ))}
                        </div>
                    )}

                    <div className="mt-10 overflow-hidden rounded-3xl bg-gradient-to-r from-volt-600 to-electric p-8 text-white">
                        <h2 className="font-display text-2xl font-bold">Ready to charge?</h2>
                        <p className="mt-2 text-white/85">Find the nearest Trans EV station and book your slot in under a minute.</p>
                        <Link href="/stations?near=1" className="mt-5 inline-flex items-center gap-2 rounded-full bg-white px-6 py-3 font-semibold text-ink">
                            <LocateFixed className="size-5" /> Chargers near me
                        </Link>
                    </div>
                </article>

                <aside className="space-y-6 lg:sticky lg:top-24 lg:self-start">
                    {post.toc.length > 1 && (
                        <nav className="rounded-2xl border border-border bg-card p-5" aria-label="Contents">
                            <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">In this article</p>
                            <ol className="mt-3 space-y-1.5 text-sm">
                                {post.toc.map((h) => (
                                    <li key={h.id} className={cn(h.level === 3 && 'pl-3')}>
                                        <a href={`#${h.id}`} className="text-muted-foreground transition hover:text-volt-600">
                                            {h.text}
                                        </a>
                                    </li>
                                ))}
                            </ol>
                        </nav>
                    )}
                    <div className="rounded-2xl border border-border bg-card p-5">
                        <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">Share</p>
                        <div className="mt-3 flex flex-wrap gap-2">
                            <ShareLink href={`https://wa.me/?text=${text}%20${share}`} label="WhatsApp"><MessageCircle className="size-4" /></ShareLink>
                            <ShareLink href={`https://x.com/intent/tweet?url=${share}&text=${text}`} label="X">
                                <svg viewBox="0 0 24 24" className="size-4" fill="currentColor" aria-hidden="true"><path d="M18.901 1.153h3.68l-8.04 9.19L24 22.846h-7.406l-5.8-7.584-6.638 7.584H.474l8.6-9.83L0 1.154h7.594l5.243 6.932ZM17.61 20.644h2.039L6.486 3.24H4.298Z" /></svg>
                            </ShareLink>
                            <ShareLink href={`https://www.linkedin.com/sharing/share-offsite/?url=${share}`} label="LinkedIn"><span className="text-xs font-bold">in</span></ShareLink>
                            <ShareLink href={`https://www.facebook.com/sharer/sharer.php?u=${share}`} label="Facebook"><span className="text-sm font-bold">f</span></ShareLink>
                            <button type="button" onClick={copy} className="flex size-10 items-center justify-center rounded-full border border-border text-muted-foreground transition hover:border-volt hover:text-volt-600" aria-label="Copy link">
                                {copied ? <Check className="size-4 text-volt-600" /> : <Link2 className="size-4" />}
                            </button>
                        </div>
                    </div>
                    {post.category && (
                        <Link href={`/blog/category/${post.category.slug}`} className={cn('block rounded-2xl p-5 text-sm font-semibold', st.pill)}>
                            More in {post.category.name} →
                        </Link>
                    )}
                </aside>
            </div>

            {related.length > 0 && (
                <section className="border-t border-border bg-muted/40 py-16">
                    <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                        <h2 className="font-display text-2xl font-bold text-foreground">Keep reading</h2>
                        <div className="mt-8 grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                            {related.map((p) => (
                                <BlogCard key={p.slug} post={p} />
                            ))}
                        </div>
                    </div>
                </section>
            )}
        </SiteLayout>
    );
}

function ShareLink({ href, label, children }: { href: string; label: string; children: React.ReactNode }) {
    return (
        <a href={href} target="_blank" rel="noopener noreferrer" aria-label={`Share on ${label}`} className="flex size-10 items-center justify-center rounded-full border border-border text-muted-foreground transition hover:border-volt hover:text-volt-600">
            {children}
        </a>
    );
}
