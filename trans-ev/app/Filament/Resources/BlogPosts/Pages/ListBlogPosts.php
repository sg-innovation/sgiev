<?php

namespace App\Filament\Resources\BlogPosts\Pages;

use App\Filament\Resources\BlogPosts\BlogPostResource;
use App\Models\BlogPost;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListBlogPosts extends ListRecords
{
    protected static string $resource = BlogPostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add new post')->icon('heroicon-m-plus'),
        ];
    }

    /** WordPress-style status views. */
    public function getTabs(): array
    {
        $count = fn (?string $status = null) => BlogPost::query()->when($status, fn ($q) => $q->where('status', $status))->count();

        return [
            'all' => Tab::make('All')->badge($count()),
            'published' => Tab::make('Published')->badge($count('published'))->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'published')),
            'scheduled' => Tab::make('Scheduled')->badge($count('scheduled'))->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'scheduled')),
            'pending' => Tab::make('Pending review')->badge($count('pending'))->badgeColor('warning')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending')),
            'draft' => Tab::make('Drafts')->badge($count('draft'))->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'draft')),
            'trash' => Tab::make('Trash')->badge(BlogPost::onlyTrashed()->count())->modifyQueryUsing(fn (Builder $query) => $query->onlyTrashed()),
        ];
    }
}
