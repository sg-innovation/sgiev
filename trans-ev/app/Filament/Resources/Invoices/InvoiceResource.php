<?php

namespace App\Filament\Resources\Invoices;

use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Models\Invoice;
use App\Models\Station;
use App\Services\GstDocumentPdf;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;
use ZipArchive;

/** Register of GST tax invoices. Companies only ever see their own. */
class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Billing & GST';

    protected static ?string $navigationLabel = 'Tax invoices';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getEloquentQuery(): Builder
    {
        return Tenancy::scope(parent::getEloquentQuery()->with(['company', 'station', 'booking'])->withSum('creditNotes as credited', 'total'));
    }

    public static function table(Table $table): Table
    {
        $tz = Station::TIMEZONE;

        return $table
            ->defaultSort('issued_at', 'desc')
            ->columns([
                TextColumn::make('number')->label('Invoice no.')->fontFamily('mono')->weight('semibold')->searchable()->copyable()
                    ->description(fn (Invoice $record) => $record->issued_at->setTimezone($tz)->format('d M Y, h:i A')),
                TextColumn::make('recipient.name')->label('Customer')->searchable(query: fn (Builder $query, string $search) => $query->where('recipient->name', 'like', "%{$search}%"))
                    ->description(fn (Invoice $record) => $record->recipient_gstin),
                TextColumn::make('supply_type')->label('Type')->badge()->color(fn (string $state) => $state === 'B2B' ? 'info' : 'gray'),
                TextColumn::make('station.name')->label('Station')->description(fn (Invoice $record) => $record->supplier_gstin)->toggleable(),
                TextColumn::make('company.trade_name')->label('Operator')->visible(fn () => Tenancy::isPlatformAdmin())->toggleable(),
                TextColumn::make('taxable_amount')->label('Taxable')->money('INR')->alignEnd()->summarize(Sum::make()->money('INR')->label('')),
                TextColumn::make('total_tax')->label('GST')->money('INR')->alignEnd()->summarize(Sum::make()->money('INR')->label('')),
                TextColumn::make('total')->label('Total')->money('INR')->alignEnd()->weight('semibold')->summarize(Sum::make()->money('INR')->label('')),
                TextColumn::make('credited')->label('Credited')->money('INR')->alignEnd()->placeholder('—')->color('danger'),
                TextColumn::make('booking.code')->label('Booking')->fontFamily('mono')->toggleable(isToggledHiddenByDefault: true)
                    ->url(fn (Invoice $record) => BookingResource::getUrl('view', ['record' => $record->booking])),
            ])
            ->filters([
                Filter::make('period')->schema([
                    DatePicker::make('from')->native(false),
                    DatePicker::make('until')->native(false),
                ])->columns(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->where('issued_at', '>=', \Illuminate\Support\Carbon::parse($d, $tz)->startOfDay()->utc()))
                        ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->where('issued_at', '<=', \Illuminate\Support\Carbon::parse($d, $tz)->endOfDay()->utc()))),
                SelectFilter::make('supply_type')->label('Type')->options(['B2B' => 'B2B (customer GSTIN)', 'B2C' => 'B2C']),
                SelectFilter::make('supplier_gstin')->label('GSTIN')
                    ->options(fn () => Tenancy::scope(Invoice::query())->distinct()->orderBy('supplier_gstin')->pluck('supplier_gstin', 'supplier_gstin')->all()),
                SelectFilter::make('station_id')->label('Station')->searchable()
                    ->options(fn () => Tenancy::scope(Station::query())->orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('company_id')->label('Operator')->relationship('company', 'trade_name')->visible(fn () => Tenancy::isPlatformAdmin()),
            ])
            ->recordActions([
                Action::make('pdf')->label('PDF')->icon('heroicon-m-arrow-down-tray')->color('gray')
                    ->url(fn (Invoice $record) => route('bookings.invoice', $record->booking), shouldOpenInNewTab: true),
            ])
            ->toolbarActions([
                BulkAction::make('zip')->label('Download PDFs (ZIP)')->icon('heroicon-m-archive-box-arrow-down')
                    ->visible(fn () => auth()->user()->can('invoices.export'))
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records) {
                        if ($records->count() > 300) {
                            Notification::make()->warning()->title('Select up to 300 invoices at a time')->send();

                            return null;
                        }

                        return static::zip($records);
                    }),
            ])
            ->emptyStateHeading('No invoices yet')
            ->emptyStateDescription('A GST tax invoice is issued automatically when a booking is paid.');
    }

    public static function zip(Collection $records): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $records = $records->filter(fn (Invoice $i) => auth()->user()->can('view', $i));
        $path = tempnam(sys_get_temp_dir(), 'inv').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $pdf = app(GstDocumentPdf::class);
        foreach ($records as $invoice) {
            $zip->addFromString($invoice->filename(), $pdf->render($invoice));
            foreach ($invoice->creditNotes as $note) {
                $zip->addFromString($note->filename(), $pdf->render($note));
            }
        }
        $zip->close();
        activity()->causedBy(auth()->user())->withProperties(['invoices' => $records->pluck('number')->all()])->log('invoices downloaded (zip)');

        return response()->download($path, 'invoices-'.now(Station::TIMEZONE)->format('Ymd-His').'.zip')->deleteFileAfterSend();
    }

    public static function getPages(): array
    {
        return ['index' => ListInvoices::route('/')];
    }
}
