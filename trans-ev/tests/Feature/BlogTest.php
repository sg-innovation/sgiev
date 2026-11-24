<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\BlogPost;
use App\Models\User;
use Database\Seeders\BlogSeeder;
use Database\Seeders\CompanySeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, CompanySeeder::class, BlogSeeder::class]);
    }

    public function test_blog_pages_render(): void
    {
        $this->get('/blog')->assertOk();
        $this->get('/blog?q=charging')->assertOk();
        $this->get('/blog/category/guides')->assertOk();
        $this->get('/blog/tag/gst')->assertOk();
        $this->get('/blog/ac-vs-dc-charging-explained')->assertOk();
        $this->get('/blog/does-not-exist')->assertNotFound();
    }

    public function test_drafts_and_scheduled_posts_are_hidden_from_the_public(): void
    {
        $draft = BlogPost::create(['title' => 'Secret draft', 'content' => '<p>Not yet</p>', 'status' => 'draft']);
        $scheduled = BlogPost::create(['title' => 'Future post', 'content' => '<p>Later</p>', 'status' => 'published', 'published_at' => now()->addDay()]);

        $this->assertSame('scheduled', $scheduled->status);
        $this->get('/blog/'.$draft->slug)->assertNotFound();
        $this->get('/blog/'.$scheduled->slug)->assertNotFound();

        $editor = User::factory()->create(['company_id' => \App\Models\Company::where('is_platform', true)->value('id')]);
        $editor->assignRole(Role::OperationsAdmin->value);
        $this->actingAs($editor)->get('/blog/'.$draft->slug.'?preview=1')->assertOk();
    }

    public function test_revisions_are_kept_and_reading_time_computed(): void
    {
        $post = BlogPost::where('slug', 'ev-charging-cost-india-guide')->first();
        $before = $post->revisions()->count();
        $post->update(['content' => $post->content.'<p>An extra paragraph.</p>']);
        $this->assertSame($before + 1, $post->revisions()->count());
        $this->assertGreaterThanOrEqual(1, $post->reading_minutes);

        $post->update(['is_featured' => false]); // non-text change: no new revision
        $this->assertSame($before + 1, $post->revisions()->count());
    }

    public function test_post_html_is_sanitised(): void
    {
        $post = BlogPost::create(['title' => 'XSS test', 'content' => '<p>Hello</p><script>alert(1)</script><img src=x onerror=alert(1)>', 'status' => 'published']);
        $res = $this->get('/blog/'.$post->slug)->assertOk();
        $this->assertStringNotContainsString('<script>alert(1)', $res->getContent());
        $this->assertStringNotContainsString('onerror', $res->getContent());
    }

    public function test_blog_admin_permissions(): void
    {
        $ops = User::factory()->create(['company_id' => \App\Models\Company::where('is_platform', true)->value('id')]);
        $ops->assignRole(Role::OperationsAdmin->value);
        $this->actingAs($ops)->get('/admin/blog-posts')->assertOk();
        foreach (['published', 'scheduled', 'pending', 'draft', 'trash'] as $tab) {
            \Livewire\Livewire::test(\App\Filament\Resources\BlogPosts\Pages\ListBlogPosts::class, ['activeTab' => $tab])->assertOk();
        }
        $this->actingAs($ops)->get('/admin/blog-posts/create')->assertOk();
        $this->actingAs($ops)->get('/admin/blog-posts/'.BlogPost::first()->slug.'/edit')->assertOk();
        $this->actingAs($ops)->get('/admin/blog-categories')->assertOk();
        $this->actingAs($ops)->get('/admin/blog-tags')->assertOk();

        $finance = User::factory()->create(['company_id' => \App\Models\Company::where('is_platform', true)->value('id')]);
        $finance->assignRole(Role::Finance->value);
        $this->actingAs($finance)->get('/admin/blog-posts')->assertForbidden();

        $this->assertTrue($ops->can('publish', BlogPost::class));
        $this->assertFalse($finance->can('publish', BlogPost::class));
    }
}
