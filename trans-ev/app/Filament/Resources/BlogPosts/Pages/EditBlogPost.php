<?php

namespace App\Filament\Resources\BlogPosts\Pages;

use App\Filament\Resources\BlogPosts\BlogPostResource;
use App\Models\BlogPost;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditBlogPost extends EditRecord
{
    protected static string $resource = BlogPostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')->label(fn (BlogPost $record) => $record->isLive() ? 'View post' : 'Preview')
                ->icon('heroicon-m-arrow-top-right-on-square')->color('gray')
                ->url(fn (BlogPost $record) => route('blog.show', $record).($record->isLive() ? '' : '?preview=1'), shouldOpenInNewTab: true),
            DeleteAction::make()->label('Move to trash'),
            ForceDeleteAction::make()->label('Delete permanently'),
            RestoreAction::make(),
        ];
    }
}
