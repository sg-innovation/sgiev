<?php

namespace App\Filament\Resources\ApiDevelopers\Pages;

use App\Filament\Resources\ApiDevelopers\ApiDeveloperResource;
use App\Models\ApiDeveloper;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Hash;

class ManageApiDevelopers extends ManageRecords
{
    protected static string $resource = ApiDeveloperResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add developer')
                ->mutateDataUsing(fn (array $data) => ApiDeveloperResource::hashPin($data) + ['created_by' => auth()->id()]),
        ];
    }

    public function getSubheading(): ?string
    {
        return 'People who can open the developer portal ('.url('/developers').') with a 6-digit PIN: API documentation, OpenAPI file and a live console.';
    }
}
