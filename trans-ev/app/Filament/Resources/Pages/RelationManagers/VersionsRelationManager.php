<?php

namespace App\Filament\Resources\Pages\RelationManagers;

use App\Models\PageVersion;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/** Every published version of a page (append-only). A version can be restored, which publishes it as a new version. */
class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'History';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('version', 'desc')
            ->columns([
                TextColumn::make('version')->prefix('v')->weight('semibold'),
                TextColumn::make('title'),
                TextColumn::make('change_note')->label('Note')->placeholder('—')->wrap(),
                TextColumn::make('author.name')->label('By')->placeholder('System'),
                TextColumn::make('created_at')->label('Published')->dateTime('d M Y, h:i A', \App\Models\Station::TIMEZONE),
            ])
            ->recordActions([
                Action::make('preview')->icon('heroicon-m-eye')->color('gray')->modalSubmitAction(false)->modalWidth('4xl')
                    ->modalContent(fn (PageVersion $record) => new HtmlString('<div class="prose max-w-none dark:prose-invert">'.\App\Support\PublicHtml::clean($record->body).'</div>')),
                Action::make('restore')->icon('heroicon-m-arrow-uturn-left')->requiresConfirmation()
                    ->visible(fn (PageVersion $record) => $record->version !== $this->getOwnerRecord()->version)
                    ->action(function (PageVersion $record) {
                        $page = $this->getOwnerRecord();
                        $page->changeNote = 'Restored from v'.$record->version;
                        $page->update(['title' => $record->title, 'intro' => $record->intro, 'body' => $record->body]);
                        Notification::make()->success()->title('Restored as v'.$page->version)->send();
                    }),
            ]);
    }
}
