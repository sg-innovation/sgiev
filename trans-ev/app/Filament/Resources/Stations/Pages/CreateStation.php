<?php

namespace App\Filament\Resources\Stations\Pages;

use App\Filament\Resources\Stations\StationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateStation extends CreateRecord
{
    protected static string $resource = StationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $tenant = \App\Support\Tenancy::companyId();
        if ($tenant !== null) {
            $data['company_id'] = $tenant; // company staff can only create stations for their own company
        }

        return $data;
    }
}
