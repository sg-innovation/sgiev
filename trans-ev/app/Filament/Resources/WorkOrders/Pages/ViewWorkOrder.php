<?php

namespace App\Filament\Resources\WorkOrders\Pages;

use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\ChargerWorkOrder;
use App\Models\User;
use App\Services\WorkOrderService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/** @property ChargerWorkOrder $record */
class ViewWorkOrder extends ViewRecord
{
    protected static string $resource = WorkOrderResource::class;

    private function svc(): WorkOrderService
    {
        return app(WorkOrderService::class);
    }

    private function canManage(): bool
    {
        return auth()->user()->can('update', $this->record) && $this->record->isActive();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('start')->label('Start work')->icon('heroicon-m-play')
                ->visible(fn () => $this->canManage() && $this->record->status !== 'in_progress')
                ->action(function () {
                    $this->svc()->start($this->record, auth()->user());
                    Notification::make()->success()->title('Work started')->send();
                }),
            Action::make('complete')->label('Mark done')->icon('heroicon-m-check-circle')->color('success')
                ->visible(fn () => $this->canManage())
                ->schema([
                    Textarea::make('resolution')->label('What was done')->required()->minLength(5)->maxLength(3000)->rows(4),
                    TextInput::make('labour_cost')->label('Labour cost')->prefix('₹')->numeric()->minValue(0),
                    TextInput::make('parts_cost')->label('Parts cost')->prefix('₹')->numeric()->minValue(0),
                    Textarea::make('parts_used')->label('Parts used')->rows(2)->maxLength(1000),
                ])
                ->action(function (array $data) {
                    $this->svc()->complete($this->record, $data, auth()->user());
                    Notification::make()->success()->title('Work order done')->body($this->record->fresh()->took_offline ? 'The charger is back online if no other work order needs it out of service.' : null)->send();
                }),
            Action::make('assign')->label(fn () => $this->record->assigned_to ? 'Reassign' : 'Assign')->icon('heroicon-m-user-plus')->color('gray')
                ->visible(fn () => $this->canManage())
                ->schema([Select::make('user')->label('Assign to')->options(fn () => WorkOrderResource::assignees($this->record->station_id))->searchable()->required()])
                ->action(function (array $data) {
                    $this->svc()->assign($this->record, User::query()->findOrFail($data['user']), auth()->user());
                    Notification::make()->success()->title('Assigned')->send();
                }),
            Action::make('hold')->label('Put on hold')->icon('heroicon-m-pause')->color('gray')
                ->visible(fn () => $this->canManage() && $this->record->status !== 'on_hold')
                ->schema([TextInput::make('note')->label('Why (e.g. waiting for parts)')->maxLength(200)])
                ->action(fn (array $data) => $this->svc()->hold($this->record, auth()->user(), $data['note'] ?? null)),
            Action::make('cancel')->label('Cancel')->icon('heroicon-m-x-mark')->color('danger')
                ->visible(fn () => $this->canManage())
                ->schema([TextInput::make('reason')->required()->maxLength(200)])
                ->action(function (array $data) {
                    $this->svc()->cancel($this->record, auth()->user(), $data['reason']);
                    Notification::make()->success()->title('Work order cancelled')->send();
                }),
        ];
    }
}
