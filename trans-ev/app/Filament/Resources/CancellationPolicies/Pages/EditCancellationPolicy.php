<?php

namespace App\Filament\Resources\CancellationPolicies\Pages;

use App\Filament\Resources\CancellationPolicies\CancellationPolicyResource;
use Filament\Resources\Pages\EditRecord;

class EditCancellationPolicy extends EditRecord
{
    protected static string $resource = CancellationPolicyResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['company_id'], $data['station_id']); // scope is fixed once created

        return CancellationPolicyResource::validated($data, $this->record);
    }

    protected function afterSave(): void
    {
        CancellationPolicyResource::deactivateOthers($this->record);
    }
}
