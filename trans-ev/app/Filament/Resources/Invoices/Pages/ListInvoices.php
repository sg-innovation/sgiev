<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use App\Models\Station;
use App\Services\Gstr1Export;
use App\Support\Tenancy;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('gstr1')->label('GSTR-1 export')->icon('heroicon-m-table-cells')->color('gray')
                ->visible(fn () => auth()->user()->can('invoices.export'))
                ->modalDescription('CSV working files (b2b, b2cs, cdnr, hsn, docs) in the GST offline-tool layout, for one GSTIN and one month. Review them with your accountant before filing.')
                ->schema([
                    Select::make('gstin')->label('GSTIN')->required()
                        ->options(fn () => Tenancy::scope(Invoice::query())->distinct()->orderBy('supplier_gstin')->pluck('supplier_gstin', 'supplier_gstin')->all()),
                    Select::make('month')->required()
                        ->options(fn () => collect(range(0, 17))->mapWithKeys(fn ($i) => [
                            ($d = now(Station::TIMEZONE)->startOfMonth()->subMonths($i))->format('Y-m') => $d->format('F Y'),
                        ])->all())
                        ->default(now(Station::TIMEZONE)->format('Y-m')),
                ])
                ->action(function (array $data) {
                    // Only GSTINs the user can see are offered, but check again server-side.
                    abort_unless(auth()->user()->can('invoices.export'), 403);
                    abort_unless(Tenancy::scope(Invoice::query())->where('supplier_gstin', $data['gstin'])->exists(), 403);
                    $path = app(Gstr1Export::class)->build($data['gstin'], Carbon::createFromFormat('Y-m', $data['month'], Station::TIMEZONE), sys_get_temp_dir());
                    activity()->causedBy(auth()->user())->withProperties($data)->log('gstr1 exported');

                    return response()->download($path)->deleteFileAfterSend();
                }),
        ];
    }
}
