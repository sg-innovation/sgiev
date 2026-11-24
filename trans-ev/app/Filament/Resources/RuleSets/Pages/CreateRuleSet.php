<?php

namespace App\Filament\Resources\RuleSets\Pages;

use App\Filament\Resources\RuleSets\RuleSetResource;
use App\Filament\Resources\RuleSets\Schemas\RuleSetForm;
use Filament\Resources\Pages\CreateRecord;

class CreateRuleSet extends CreateRecord
{
    protected static string $resource = RuleSetResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return RuleSetForm::withVersion($data);
    }
}
