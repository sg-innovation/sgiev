<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Station;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')->label('Export CSV')->icon('heroicon-m-arrow-down-tray')->color('gray')
                ->visible(fn () => auth()->user()->can('users.manage'))
                ->action(function (): StreamedResponse {
                    activity()->causedBy(auth()->user())->log('customers exported');
                    $query = $this->getFilteredSortedTableQuery();

                    return response()->streamDownload(function () use ($query) {
                        $out = fopen('php://output', 'w');
                        fputcsv($out, ['Name', 'Email', 'Phone', 'Vehicles', 'Paid bookings', 'Spent (INR)', 'Last booking', 'Verified', 'Status', 'Joined']);
                        $query->chunk(500, function ($rows) use ($out) {
                            foreach ($rows as $u) {
                                fputcsv($out, array_map([self::class, 'safeCell'], [
                                    $u->name, $u->email, $u->phone, $u->vehicles_count, $u->bookings_count, number_format((float) $u->spent, 2, '.', ''),
                                    $u->last_booking_at ? \Illuminate\Support\Carbon::parse($u->last_booking_at)->tz(Station::TIMEZONE)->format('Y-m-d H:i') : '',
                                    $u->email_verified_at ? 'yes' : 'no', $u->blocked_at ? 'blocked' : 'active', $u->created_at?->format('Y-m-d'),
                                ]));
                            }
                        });
                        fclose($out);
                    }, 'customers-'.now(Station::TIMEZONE)->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
                }),
        ];
    }

    /** Neutralise spreadsheet formula injection (=, +, -, @ at the start of a cell). */
    public static function safeCell(mixed $v): string
    {
        $v = (string) $v;

        return $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$v : $v;
    }
}
