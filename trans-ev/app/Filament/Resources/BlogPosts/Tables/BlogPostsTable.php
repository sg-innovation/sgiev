<?php

namespace App\Filament\Resources\BlogPosts\Tables;

use App\Models\BlogPost;
use App\Models\Station;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\ReplicateAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class BlogPostsTable
{
    public static function statusColor(string $state): string
    {
        return match ($state) {
            'published' => 'success',
            'scheduled' => 'info',
            'pending' => 'warning',
            default => 'gray',
        };
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                ImageColumn::make('cover_image')->label('')->disk('public')->imageHeight(44)->imageWidth(78)->extraImgAttributes(['class' => 'rounded-lg object-cover'])
                    ->defaultImageUrl(asset('brand/logo-mark.svg')),
                TextColumn::make('title')->searchable()->sortable()->weight('medium')->limit(60)->wrap()
                    ->description(fn (BlogPost $r) => '/blog/'.$r->slug),
                TextColumn::make('category.name')->badge()->color('gray')->placeholder('—'),
                TextColumn::make('author.name')->toggleable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => BlogPost::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => self::statusColor($state)),
                IconColumn::make('is_featured')->label('Featured')->boolean()->toggleable(),
                TextColumn::make('published_at')->label('Date')->dateTime('d M Y, h:i A', Station::TIMEZONE)->sortable()->placeholder('—'),
                TextColumn::make('views')->numeric()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label('Last edited')->since()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(BlogPost::STATUSES),
                SelectFilter::make('blog_category_id')->label('Category')->relationship('category', 'name'),
                SelectFilter::make('author_id')->label('Author')->relationship('author', 'name'),
                TrashedFilter::make()->label('Trash'),
            ])
            ->recordActions([
                Action::make('view')->label('View')->icon('heroicon-m-arrow-top-right-on-square')->color('gray')
                    ->url(fn (BlogPost $r) => route('blog.show', $r).($r->isLive() ? '' : '?preview=1'), shouldOpenInNewTab: true)
                    ->hidden(fn (BlogPost $r) => $r->trashed()),
                EditAction::make(),
                ActionGroup::make([
                    Action::make('publish')->icon('heroicon-m-paper-airplane')->color('success')
                        ->visible(fn (BlogPost $r) => ! $r->isLive() && ! $r->trashed() && auth()->user()->can('publish', $r))
                        ->requiresConfirmation()
                        ->action(function (BlogPost $r) {
                            $r->update(['status' => 'published', 'published_at' => $r->published_at && $r->published_at->isFuture() ? $r->published_at : now()]);
                            Notification::make()->success()->title($r->status === 'scheduled' ? 'Post scheduled' : 'Post published')->send();
                        }),
                    Action::make('unpublish')->label('Move to draft')->icon('heroicon-m-eye-slash')->color('warning')
                        ->visible(fn (BlogPost $r) => in_array($r->status, ['published', 'scheduled'], true) && auth()->user()->can('publish', $r))
                        ->requiresConfirmation()
                        ->action(fn (BlogPost $r) => $r->update(['status' => 'draft'])),
                    ReplicateAction::make()->label('Duplicate')
                        ->excludeAttributes(['uuid', 'views', 'published_at'])
                        ->mutateRecordDataUsing(function (array $data) {
                            $data['title'] .= ' (copy)';
                            $data['slug'] = Str::slug($data['title']).'-'.Str::lower(Str::random(4));
                            $data['status'] = 'draft';
                            $data['is_featured'] = false;

                            return $data;
                        })
                        ->after(fn (BlogPost $replica, BlogPost $record) => $replica->tags()->sync($record->tags->pluck('id'))),
                    DeleteAction::make()->label('Move to trash'),
                    RestoreAction::make(),
                    ForceDeleteAction::make()->label('Delete permanently'),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('publishSelected')->label('Publish selected')->icon('heroicon-m-paper-airplane')
                        ->visible(fn () => auth()->user()->can('publish', BlogPost::class))
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each(fn (BlogPost $r) => $r->update(['status' => 'published', 'published_at' => $r->published_at ?? now()]))),
                    DeleteBulkAction::make()->label('Move to trash'),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make()->label('Delete permanently'),
                ]),
            ]);
    }
}
