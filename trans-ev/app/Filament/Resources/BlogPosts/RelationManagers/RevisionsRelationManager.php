<?php

namespace App\Filament\Resources\BlogPosts\RelationManagers;

use App\Models\BlogPost;
use App\Models\BlogPostRevision;
use App\Models\Station;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/** Revision history with one-click restore, like WordPress revisions. */
class RevisionsRelationManager extends RelationManager
{
    protected static string $relationship = 'revisions';

    protected static ?string $title = 'Revisions';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Saved')->dateTime('d M Y, h:i:s A', Station::TIMEZONE),
                TextColumn::make('user.name')->label('By')->placeholder('System'),
                TextColumn::make('title')->limit(60),
                TextColumn::make('words')->label('Words')->state(fn (BlogPostRevision $r) => str_word_count(strip_tags($r->content))),
            ])
            ->recordActions([
                Action::make('preview')->icon('heroicon-m-eye')->color('gray')
                    ->modalHeading(fn (BlogPostRevision $r) => $r->title)
                    ->modalSubmitAction(false)
                    ->schema([
                        TextEntry::make('content')->hiddenLabel()->html()->prose()
                            ->state(fn (BlogPostRevision $r) => Str::limit(strip_tags($r->content, '<p><h2><h3><ul><ol><li><strong><em><a>'), 6000)),
                    ]),
                Action::make('restore')->icon('heroicon-m-arrow-uturn-left')->color('warning')
                    ->visible(fn () => auth()->user()?->can('update', $this->getOwnerRecord())
                        && (! $this->getOwnerRecord()->isLive() || auth()->user()->can('publish', $this->getOwnerRecord())))
                    ->requiresConfirmation()
                    ->modalDescription('The current text is kept as a revision, then this version becomes the live text.')
                    ->action(function (BlogPostRevision $r) {
                        /** @var BlogPost $post */
                        $post = $this->getOwnerRecord();
                        $post->update(['title' => $r->title, 'excerpt' => $r->excerpt, 'content' => $r->content]);
                        Notification::make()->success()->title('Revision restored')->send();
                        $this->redirect(\App\Filament\Resources\BlogPosts\BlogPostResource::getUrl('edit', ['record' => $post]));
                    }),
            ]);
    }
}
