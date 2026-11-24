<?php

namespace App\Http\Controllers;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use App\Support\PublicHtml;

class BlogController extends Controller
{
    public function index(Request $request, ?BlogCategory $category = null, ?BlogTag $tag = null): Response
    {
        $q = trim((string) $request->query('q', ''));

        $posts = BlogPost::live()
            ->with(['category', 'author'])
            ->when($category, fn (Builder $b) => $b->where('blog_category_id', $category->id))
            ->when($tag, fn (Builder $b) => $b->whereHas('tags', fn ($t) => $t->whereKey($tag->id)))
            ->when($q !== '', fn (Builder $b) => $b->where(fn ($w) => $w
                ->where('title', 'like', '%'.addcslashes($q, '%_\\').'%')
                ->orWhere('excerpt', 'like', '%'.addcslashes($q, '%_\\').'%')))
            ->latest('published_at')
            ->paginate(9)
            ->withQueryString();

        $featured = (! $category && ! $tag && $q === '' && $posts->currentPage() === 1)
            ? BlogPost::live()->with(['category', 'author'])->where('is_featured', true)->latest('published_at')->first()
            : null;

        return Inertia::render('blog/index', [
            'posts' => $posts->through(fn (BlogPost $p) => self::card($p)),
            'featured' => $featured ? self::card($featured) : null,
            'categories' => BlogCategory::query()->orderBy('sort')->withCount(['posts' => fn ($q) => $q->live()])->get()
                ->map(fn ($c) => ['name' => $c->name, 'slug' => $c->slug, 'color' => $c->color, 'count' => $c->posts_count]),
            'popularTags' => BlogTag::query()->withCount(['posts' => fn ($q) => $q->live()])->orderByDesc('posts_count')->limit(12)->get()
                ->filter(fn ($t) => $t->posts_count > 0)->map(fn ($t) => ['name' => $t->name, 'slug' => $t->slug])->values(),
            'active' => [
                'category' => $category ? ['name' => $category->name, 'slug' => $category->slug, 'description' => $category->description] : null,
                'tag' => $tag ? ['name' => $tag->name, 'slug' => $tag->slug] : null,
                'q' => $q,
            ],
        ]);
    }

    public function category(Request $request, BlogCategory $category): Response
    {
        return $this->index($request, $category);
    }

    public function tag(Request $request, BlogTag $tag): Response
    {
        return $this->index($request, null, $tag);
    }

    public function show(Request $request, string $slug): Response
    {
        $post = BlogPost::query()->with(['category', 'author', 'tags'])->where('slug', $slug)->firstOrFail();

        // Drafts / scheduled posts are visible only to staff who can manage the blog (preview).
        if (! $post->isLive()) {
            abort_unless($request->user()?->can('update', $post), 404);
        } else {
            BlogPost::query()->whereKey($post->id)->increment('views');
        }

        $related = BlogPost::live()->with(['category', 'author'])->whereKeyNot($post->id)
            ->where(fn ($q) => $q->where('blog_category_id', $post->blog_category_id)
                ->orWhereHas('tags', fn ($t) => $t->whereIn('blog_tags.id', $post->tags->pluck('id'))))
            ->latest('published_at')->limit(3)->get();

        $html = PublicHtml::clean($post->content);
        [$html, $toc] = self::withHeadingAnchors($html);

        return Inertia::render('blog/show', [
            'post' => self::card($post) + [
                'content' => $html,
                'toc' => $toc,
                'tags' => $post->tags->map(fn ($t) => ['name' => $t->name, 'slug' => $t->slug]),
                'metaTitle' => PublicHtml::text($post->meta_title ?: $post->title),
                'metaDescription' => PublicHtml::text($post->meta_description ?: $post->excerpt),
                'allowIndexing' => $post->allow_indexing,
                'isPreview' => ! $post->isLive(),
                'url' => route('blog.show', $post->slug),
                'updatedAt' => $post->updated_at->toIso8601String(),
            ],
            'related' => $related->map(fn (BlogPost $p) => self::card($p)),
        ]);
    }

    public static function card(BlogPost $p): array
    {
        return [
            'slug' => $p->slug,
            'title' => $p->title,
            'excerpt' => $p->excerpt ?: Str::limit(strip_tags($p->content), 180),
            'cover' => $p->coverUrl(),
            'coverAlt' => $p->cover_alt ?: $p->title,
            'category' => $p->category ? ['name' => $p->category->name, 'slug' => $p->category->slug, 'color' => $p->category->color] : null,
            'author' => $p->author?->name ?? 'Trans EV Team',
            'publishedAt' => $p->published_at?->toIso8601String(),
            'date' => $p->published_at?->setTimezone('Asia/Kolkata')->format('j M Y'),
            'readingMinutes' => $p->reading_minutes,
        ];
    }

    /**
     * Adds id anchors to h2/h3 headings and returns a table of contents.
     *
     * @return array{0: string, 1: list<array{id: string, text: string, level: int}>}
     */
    private static function withHeadingAnchors(string $html): array
    {
        $toc = [];
        $used = [];
        $html = preg_replace_callback('/<(h[23])([^>]*)>(.*?)<\/\1>/is', function ($m) use (&$toc, &$used) {
            $text = trim(html_entity_decode(strip_tags($m[3])));
            $id = Str::slug($text) ?: 'section';
            $base = $id;
            $i = 2;
            while (isset($used[$id])) {
                $id = $base.'-'.$i++;
            }
            $used[$id] = true;
            $toc[] = ['id' => $id, 'text' => $text, 'level' => (int) substr($m[1], 1)];

            return "<{$m[1]} id=\"{$id}\">{$m[3]}</{$m[1]}>";
        }, $html) ?? $html;

        return [$html, $toc];
    }
}
