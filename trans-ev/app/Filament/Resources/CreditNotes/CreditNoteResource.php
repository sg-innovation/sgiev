<?php

namespace App\Filament\Resources\CreditNotes;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\CreditNote;
use App\Models\Station;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Register of GST credit notes (issued automatically when refunds succeed). */
class CreditNoteResource extends Resource
{
    protected static ?string $model = CreditNote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static string|UnitEnum|null $navigationGroup = 'Billing & GST';

    protected static ?string $navigationLabel = 'Credit & debit notes';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        return Tenancy::scope(parent::getEloquentQuery()->with(['invoice', 'booking', 'company']));
    }

    public static function table(Table $table): Table
    {
        $tz = Station::TIMEZONE;

        return $table
            ->defaultSort('issued_at', 'desc')
            ->columns([
                TextColumn::make('note_type')->label('Type')->badge()->formatStateUsing(fn (string $state) => $state === 'debit' ? 'Debit' : 'Credit')->color(fn (string $state) => $state === 'debit' ? 'warning' : 'success'),
                TextColumn::make('number')->label('Note no.')->fontFamily('mono')->weight('semibold')->searchable()->copyable()
                    ->description(fn (CreditNote $record) => $record->issued_at->setTimezone($tz)->format('d M Y, h:i A')),
                TextColumn::make('invoice.number')->label('Against invoice')->fontFamily('mono')->searchable(),
                TextColumn::make('reason')->wrap()->limit(60)->color('gray'),
                TextColumn::make('company.trade_name')->label('Operator')->visible(fn () => Tenancy::isPlatformAdmin()),
                TextColumn::make('taxable_amount')->label('Taxable')->money('INR')->alignEnd()->summarize(Sum::make()->money('INR')->label('')),
                TextColumn::make('total_tax')->label('GST')->money('INR')->alignEnd()->summarize(Sum::make()->money('INR')->label('')),
                TextColumn::make('total')->label('Total')->money('INR')->alignEnd()->weight('semibold')->summarize(Sum::make()->money('INR')->label('')),
                TextColumn::make('booking.code')->label('Booking')->fontFamily('mono')
                    ->url(fn (CreditNote $record) => BookingResource::getUrl('view', ['record' => $record->booking])),
            ])
            ->filters([
                SelectFilter::make('note_type')->label('Type')->options(['credit' => 'Credit notes', 'debit' => 'Debit notes']),
                SelectFilter::make('supplier_gstin')->label('GSTIN')
                    ->options(fn () => Tenancy::scope(CreditNote::query())->distinct()->pluck('supplier_gstin', 'supplier_gstin')->all()),
            ])
            ->recordActions([
                Action::make('pdf')->label('PDF')->icon('heroicon-m-arrow-down-tray')->color('gray')
                    ->url(fn (CreditNote $record) => route('credit-notes.show', $record), shouldOpenInNewTab: true),
            ])
            ->emptyStateHeading('No credit or debit notes')
            ->emptyStateDescription('Credit notes are issued when refunds succeed; debit notes when a balance is collected after the final bill.');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCreditNotes::route('/')];
    }
}
