<?php

namespace App\Filament\Resources\ChargerControl\RelationManagers;

use App\Models\OcppMessage;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/** The OCPP message log for this charger (newest first). */
class MessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'ocppMessages';

    protected static ?string $title = 'OCPP log';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->poll('10s')->paginated([25, 50, 100])
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('j M, g:i:s A', 'Asia/Kolkata'),
                TextColumn::make('direction')->label('Way')->badge()->formatStateUsing(fn ($state) => $state === 'in' ? 'From charger' : 'To charger')
                    ->color(fn ($state) => $state === 'in' ? 'info' : 'gray'),
                TextColumn::make('message_type')->label('Type')->formatStateUsing(fn ($state) => OcppMessage::TYPES[$state] ?? $state),
                TextColumn::make('action')->placeholder('—')->weight('medium'),
                TextColumn::make('data')->label('Data')->limit(80)->state(fn (OcppMessage $record) => json_encode($record->payload, JSON_UNESCAPED_SLASHES)),
            ])
            ->filters([SelectFilter::make('action')->options(fn () => OcppMessage::query()->where('charger_id', $this->getOwnerRecord()->id)->whereNotNull('action')->distinct()->orderBy('action')->pluck('action', 'action'))])
            ->recordActions([Action::make('show')->label('Show')->icon('heroicon-m-code-bracket')->color('gray')->modalSubmitAction(false)
                ->modalContent(fn (OcppMessage $record) => new HtmlString('<pre class="overflow-auto rounded-lg bg-gray-950 p-3 text-xs text-gray-100">'.e(json_encode($record->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)).'</pre>'))]);
    }
}
