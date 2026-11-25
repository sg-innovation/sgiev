<?php

namespace App\Filament\Resources\Franchises\Pages;

use App\Filament\Resources\Franchises\FranchiseResource;
use App\Services\FranchiseService;
use Filament\Resources\Pages\CreateRecord;

class CreateFranchise extends CreateRecord
{
    protected static string $resource = FranchiseResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Added by Trans EV directly: active straight away.
        return $data + ['status' => 'active', 'reviewed_by' => auth()->id(), 'reviewed_at' => now()];
    }

    protected function afterCreate(): void
    {
        $state = $this->form->getRawState();
        $svc = app(FranchiseService::class);
        $svc->syncStations($this->record, array_map('intval', $state['station_ids'] ?? []));
        $svc->syncManagers($this->record, array_map('intval', $state['manager_ids'] ?? []));
    }
}
