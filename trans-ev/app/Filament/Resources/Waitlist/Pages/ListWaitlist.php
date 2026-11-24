<?php

namespace App\Filament\Resources\Waitlist\Pages;

use App\Filament\Resources\Waitlist\WaitlistResource;
use Filament\Resources\Pages\ListRecords;

class ListWaitlist extends ListRecords
{
    protected static string $resource = WaitlistResource::class;

    protected static ?string $title = 'Waiting list';

    public function getBreadcrumbs(): array
    {
        return [];
    }
}
