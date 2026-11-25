<?php

namespace App\Filament\Resources\ChargerControl\RelationManagers;

use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\OcppFault;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Error codes the charger reported, open until it reports the connector healthy again. */
class FaultsRelationManager extends RelationManager
{
    protected static string $relationship = 'ocppFaults';

    protected static ?string $title = 'Faults';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table->defaultSort('occurred_at', 'desc')
            ->columns([
                TextColumn::make('occurred_at')->label('When')->dateTime('j M Y, g:i A', 'Asia/Kolkata'),
                TextColumn::make('connector.connector_no')->label('Connector')->placeholder('Charger'),
                TextColumn::make('error_code')->label('Error')->weight('semibold')
                    ->description(fn (OcppFault $record) => trim(($record->info ?? '').' '.($record->vendor_error_code ? '('.$record->vendor_error_code.')' : '')) ?: null),
                TextColumn::make('cleared_at')->label('Cleared')->dateTime('j M, g:i A', 'Asia/Kolkata')->placeholder('Still open'),
                TextColumn::make('workOrder.number')->label('Work order')->placeholder('—')
                    ->url(fn (OcppFault $record) => $record->work_order_id ? WorkOrderResource::getUrl('view', ['record' => $record->work_order_id]) : null),
            ]);
    }
}
