<?php

namespace App\Filament\Resources\Franchises\Pages;

use App\Filament\Resources\Franchises\FranchiseResource;
use App\Models\Franchise;
use App\Services\FranchiseService;
use App\Services\Reports\ReportFilters;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/** @property Franchise $record */
class EditFranchise extends EditRecord
{
    protected static string $resource = FranchiseResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $data + [
            'station_ids' => $this->record->stations()->pluck('stations.id')->all(),
            'manager_ids' => $this->record->managers()->pluck('users.id')->all(),
        ];
    }

    protected function afterSave(): void
    {
        $state = $this->form->getRawState();
        $svc = app(FranchiseService::class);
        $svc->syncStations($this->record, array_map('intval', $state['station_ids'] ?? []));
        $svc->syncManagers($this->record, array_map('intval', $state['manager_ids'] ?? []));
    }

    protected function getFormActions(): array
    {
        return FranchiseResource::canManage() ? parent::getFormActions() : [];
    }

    protected function getHeaderActions(): array
    {
        $svc = fn () => app(FranchiseService::class);

        return [
            Action::make('reports')->label('Franchise report')->icon('heroicon-m-chart-bar-square')->color('gray')
                ->url(fn () => \App\Filament\Pages\Reports::getUrl(['franchise' => $this->record->id, 'tab' => 'stations'])),
            Action::make('approve')->label('Approve')->icon('heroicon-m-check-badge')->color('success')
                ->visible(fn () => FranchiseResource::canManage() && in_array($this->record->status, ['pending', 'rejected'], true))
                ->schema([
                    TextInput::make('commission_percent')->label('Agreed commission on net sales')->suffix('%')->numeric()->minValue(0)->maxValue(50)->required()->default(fn () => $this->record->commission_percent),
                    TextInput::make('note')->label('Note (internal)')->maxLength(300),
                ])
                ->action(function (array $data) use ($svc) {
                    $svc()->approve($this->record, auth()->user(), (string) $data['commission_percent'], $data['note'] ?? null);
                    Notification::make()->success()->title('Franchise approved')->body('The contact has been emailed. Add its stations and regional managers below.')->send();
                    $this->refreshFormData(['commission_percent', 'status_label', 'review_note']);
                }),
            Action::make('reject')->label('Reject')->icon('heroicon-m-x-circle')->color('danger')
                ->visible(fn () => FranchiseResource::canManage() && $this->record->status === 'pending')
                ->schema([TextInput::make('reason')->label('Reason (emailed to the applicant)')->required()->maxLength(300)])
                ->action(function (array $data) use ($svc) {
                    $svc()->reject($this->record, auth()->user(), $data['reason']);
                    Notification::make()->success()->title('Application rejected')->send();
                    $this->refreshFormData(['status_label', 'review_note']);
                }),
            Action::make('suspend')->label(fn () => $this->record->status === 'suspended' ? 'Reactivate' : 'Suspend')->icon('heroicon-m-pause-circle')->color('gray')
                ->visible(fn () => FranchiseResource::canManage() && in_array($this->record->status, ['active', 'suspended'], true))
                ->requiresConfirmation()
                ->modalDescription(fn () => $this->record->status === 'suspended' ? 'Regional managers get access again and payouts resume.' : 'Regional managers lose access to the stations and no new payouts are drafted.')
                ->action(function () use ($svc) {
                    $svc()->setStatus($this->record, $this->record->status === 'suspended' ? 'active' : 'suspended', auth()->user());
                    $this->refreshFormData(['status_label']);
                }),
            Action::make('payout')->label('Draft payout')->icon('heroicon-m-banknotes')->color('gray')
                ->visible(fn () => FranchiseResource::canManage() && $this->record->isActive())
                ->schema([TextInput::make('month')->label('Month')->type('month')->required()->default(fn () => CarbonImmutable::now(ReportFilters::TZ)->subMonthNoOverflow()->format('Y-m'))])
                ->action(function (array $data) use ($svc) {
                    $p = $svc()->generatePayout($this->record, CarbonImmutable::createFromFormat('!Y-m', $data['month'], ReportFilters::TZ));
                    Notification::make()->success()->title($p?->status === 'draft' ? 'Payout drafted: ₹'.number_format((float) $p->commission_amount, 2) : 'That month is already approved or paid')->send();
                }),
        ];
    }
}
