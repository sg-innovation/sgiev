<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use Filament\Resources\Pages\ViewRecord;

class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [CustomerResource::blockAction(), CustomerResource::unblockAction()];
    }

    /** Load with the scoped counts (bookings, spend) used by the infolist. */
    protected function resolveRecord(int|string $key): \Illuminate\Database\Eloquent\Model
    {
        return CustomerResource::getEloquentQuery()->whereKey($key)->firstOrFail();
    }
}
