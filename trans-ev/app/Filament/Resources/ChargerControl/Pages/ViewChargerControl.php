<?php

namespace App\Filament\Resources\ChargerControl\Pages;

use App\Filament\Resources\ChargerControl\ChargerControlResource;
use Filament\Resources\Pages\ViewRecord;

class ViewChargerControl extends ViewRecord
{
    protected static string $resource = ChargerControlResource::class;

    protected function getHeaderActions(): array
    {
        return ChargerControlResource::actions();
    }
}
