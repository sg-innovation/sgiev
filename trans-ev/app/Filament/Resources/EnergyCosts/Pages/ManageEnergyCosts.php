<?php

namespace App\Filament\Resources\EnergyCosts\Pages;

use App\Filament\Resources\EnergyCosts\EnergyCostResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageEnergyCosts extends ManageRecords
{
    protected static string $resource = EnergyCostResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add electricity cost')
            ->mutateDataUsing(fn (array $data) => $data + ['created_by' => auth()->id()])];
    }

    public function getSubheading(): ?string
    {
        return 'Used for the gross-profit estimate in Business reports: energy delivered × cost per kWh, plus fixed charges.';
    }
}
