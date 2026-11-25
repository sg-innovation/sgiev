<?php

namespace App\Filament\Resources\Stations\Actions;

use App\Enums\StationStatus;
use App\Models\Charger;
use App\Models\Station;
use App\Services\StationStatusService;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use InvalidArgumentException;

/** "Set status" action for a station or charger: Online / Offline / Maintenance with reason and auto-revert time. */
class ChangeStatusAction
{
    public static function make(string $name = 'changeStatus'): Action
    {
        return Action::make($name)
            ->label('Set status')
            ->icon('heroicon-m-power')
            ->color('gray')
            ->modalHeading(fn (Station|Charger $record) => 'Change status: '.($record->name ?? $record->code))
            ->modalWidth('lg')
            ->fillForm(fn (Station|Charger $record) => [
                'status' => $record->status?->value,
                'reason' => $record->status_reason,
            ])
            ->schema(fn (Station|Charger $record) => [
                ToggleButtons::make('status')
                    ->options(StationStatus::class)
                    ->inline()
                    ->required()
                    ->live(),
                Textarea::make('reason')
                    ->label('Reason (shown to customers)')
                    ->rows(2)
                    ->maxLength(255)
                    ->required(fn (Get $get) => $get('status') !== StationStatus::Online->value)
                    ->hidden(fn (Get $get) => $get('status') === StationStatus::Online->value),
                DateTimePicker::make('until')
                    ->label('Automatically back online at (optional)')
                    ->seconds(false)
                    ->timezone(Station::TIMEZONE)
                    ->minDate(now())
                    ->visible(fn (Get $get) => $record instanceof Station && $get('status') !== StationStatus::Online->value),
            ])
            ->authorize(fn (Station|Charger $record) => auth()->user()?->can('changeStatus', $record instanceof Charger ? $record->station : $record))
            ->action(function (Station|Charger $record, array $data) {
                try {
                    app(StationStatusService::class)->change(
                        $record,
                        $data['status'] instanceof StationStatus ? $data['status'] : StationStatus::from($data['status']),
                        $data['reason'] ?? null,
                        filled($data['until'] ?? null) ? \Illuminate\Support\Carbon::parse($data['until']) : null,
                    );
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Status updated')->send();
            });
    }
}
