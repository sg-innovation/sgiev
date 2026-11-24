<?php

namespace App\Filament\Resources\CancellationPolicies\Pages;

use App\Filament\Resources\CancellationPolicies\CancellationPolicyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCancellationPolicy extends CreateRecord
{
    protected static string $resource = CancellationPolicyResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return CancellationPolicyResource::validated($data);
    }

    protected function afterCreate(): void
    {
        CancellationPolicyResource::deactivateOthers($this->record);
    }
}
