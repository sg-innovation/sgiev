<?php

namespace App\Filament\Pages;

use App\Enums\BookingStatus;
use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use App\Models\CheckInRecord;
use App\Models\Station;
use App\Services\CheckInService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Bookings & Payments → Check-in desk (C1). Today's arrivals for the stations this user looks after.
 * A USB / phone QR scanner types the scanned link into the box (or staff type the booking code) to check the customer in.
 */
class CheckInDesk extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.check-in-desk';

    protected static ?string $slug = 'check-in';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static string|UnitEnum|null $navigationGroup = 'Bookings & Payments';

    protected static ?string $navigationLabel = 'Check-in desk';

    protected static ?string $title = 'Check-in desk';

    protected static ?int $navigationSort = 2;

    public string $scan = '';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('bookings.manage');
    }

    public function getSubheading(): ?string
    {
        return 'Scan the customer’s QR (or type the booking code) to check them in. The QR on the customer’s phone refreshes every few minutes.';
    }

    public function submitScan(): void
    {
        $input = trim($this->scan);
        $this->scan = '';
        if ($input === '') {
            return;
        }
        $svc = app(CheckInService::class);
        try {
            $booking = $svc->resolve($input);
            if (! auth()->user()->can('checkIn', $booking)) {
                throw ValidationException::withMessages(['token' => "Booking {$booking->code} is not at one of your stations."]);
            }
            $already = $booking->status === BookingStatus::CheckedIn;
            $scanned = str_contains($input, '/checkin/') || substr_count($input, '.') === 2;
            $svc->checkIn($booking, $scanned ? 'staff_scan' : 'manual', auth()->user());
            $snap = $booking->vehicle_snapshot ?? [];
            Notification::make()->success()->title($already ? "{$booking->code} was already checked in" : "{$booking->code} checked in")
                ->body(collect([$booking->customer_snapshot['name'] ?? null, $snap['registration_no'] ?? null, 'charger '.$booking->charger?->code])->filter()->implode(' · '))->send();
        } catch (ValidationException $e) {
            Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();
        }
        $this->dispatch('scan-done');
    }

    public function stats(): array
    {
        $q = $this->todayQuery();

        return [
            'expected' => (clone $q)->whereIn('status', [BookingStatus::Confirmed, BookingStatus::CheckedIn, BookingStatus::Completed, BookingStatus::NoShow])->count(),
            'checked' => (clone $q)->whereNotNull('checked_in_at')->count(),
            'waiting' => (clone $q)->where('status', BookingStatus::Confirmed)->where('end_at', '>', now())->count(),
            'late' => (clone $q)->where('status', BookingStatus::Confirmed)->where('start_at', '<', now())->where('end_at', '>', now())->count(),
        ];
    }

    private function todayQuery(): Builder
    {
        $tz = Station::TIMEZONE;

        return BookingResource::getEloquentQuery()->whereBetween('start_at', [now($tz)->startOfDay()->utc(), now($tz)->endOfDay()->utc()]);
    }

    public function table(Table $table): Table
    {
        $tz = Station::TIMEZONE;
        $svc = app(CheckInService::class);

        return $table
            ->query(fn () => $this->todayQuery()->whereIn('status', [BookingStatus::Confirmed, BookingStatus::CheckedIn, BookingStatus::Completed, BookingStatus::NoShow])->with('checkIn.staff'))
            ->defaultSort('start_at')
            ->poll('30s')
            ->columns([
                TextColumn::make('start_at')->label('Slot')->weight('semibold')
                    ->formatStateUsing(fn (Booking $record) => $record->start_at->timezone($tz)->format('g:i A').' – '.$record->end_at->timezone($tz)->format('g:i A'))
                    ->description(fn (Booking $record) => $record->status === BookingStatus::Confirmed && $record->start_at->isPast() && $record->end_at->isFuture()
                        ? 'Late by '.(int) $record->start_at->diffInMinutes(now(), true).' min' : null),
                TextColumn::make('code')->label('Booking')->fontFamily('mono')->searchable()
                    ->url(fn (Booking $record) => BookingResource::getUrl('view', ['record' => $record])),
                TextColumn::make('customer_snapshot.name')->label('Customer')->description(fn (Booking $record) => $record->customer_snapshot['phone'] ?? null),
                TextColumn::make('vehicle_snapshot.registration_no')->label('Vehicle')->fontFamily('mono')->weight('bold')
                    ->description(fn (Booking $record) => trim(($record->vehicle_snapshot['make'] ?? '').' '.($record->vehicle_snapshot['model'] ?? ''))),
                TextColumn::make('charger.code')->label('Charger')->description(fn (Booking $record) => $record->station?->name),
                TextColumn::make('status')->badge()
                    ->description(fn (Booking $record) => $record->checkIn
                        ? 'In at '.$record->checkIn->checked_in_at->timezone($tz)->format('g:i A').' · '.(CheckInRecord::METHODS[$record->checkIn->method] ?? $record->checkIn->method) : null),
            ])
            ->filters([
                SelectFilter::make('station_id')->label('Station')
                    ->options(fn () => Station::query()->whereIn('id', $this->todayQuery()->select('station_id'))->pluck('name', 'id')->all()),
                SelectFilter::make('status')->options(['confirmed' => 'Waiting', 'checked_in' => 'Checked in', 'completed' => 'Completed', 'no_show' => 'No-show']),
            ])
            ->recordActions([
                Action::make('checkIn')->label('Check in')->icon('heroicon-m-check-circle')->color('success')->button()
                    ->visible(fn (Booking $record) => $record->status === BookingStatus::Confirmed && auth()->user()->can('checkIn', $record))
                    ->disabled(fn (Booking $record) => $svc->blocker($record) !== null)
                    ->tooltip(fn (Booking $record) => $svc->blocker($record))
                    ->requiresConfirmation()
                    ->modalHeading(fn (Booking $record) => "Check in {$record->code}?")
                    ->modalDescription(fn (Booking $record) => 'Vehicle '.($record->vehicle_snapshot['registration_no'] ?? '—').' at charger '.$record->charger?->code.'.')
                    ->action(function (Booking $record) use ($svc) {
                        try {
                            $svc->checkIn($record, 'manual', auth()->user());
                            Notification::make()->success()->title("{$record->code} checked in")->send();
                        } catch (ValidationException $e) {
                            Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();
                        }
                    }),
            ])
            ->emptyStateIcon('heroicon-o-calendar')
            ->emptyStateHeading('No arrivals today')
            ->emptyStateDescription('Paid bookings for today appear here.');
    }
}
