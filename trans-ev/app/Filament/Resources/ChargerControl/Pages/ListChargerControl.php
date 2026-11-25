<?php

namespace App\Filament\Resources\ChargerControl\Pages;

use App\Filament\Resources\ChargerControl\ChargerControlResource;
use Filament\Resources\Pages\ListRecords;

class ListChargerControl extends ListRecords
{
    protected static string $resource = ChargerControlResource::class;

    public function getSubheading(): ?string
    {
        return 'OCPP connection of every charger, and remote control. Chargers without OCPP keep working with the Staff Console.';
    }
}
