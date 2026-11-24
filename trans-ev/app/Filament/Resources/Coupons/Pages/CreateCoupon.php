<?php

namespace App\Filament\Resources\Coupons\Pages;

use App\Filament\Resources\Coupons\CouponResource;
use App\Support\Tenancy;
use Filament\Resources\Pages\CreateRecord;

class CreateCoupon extends CreateRecord
{
    protected static string $resource = CouponResource::class;

    /** Company staff can only create coupons funded by (and valid at) their own company. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! Tenancy::isPlatformAdmin()) {
            $data['company_id'] = Tenancy::companyId();
        }

        return CouponResource::sanitise($data);
    }
}
