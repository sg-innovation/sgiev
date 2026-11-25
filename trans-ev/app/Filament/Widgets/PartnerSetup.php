<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Stations\StationResource;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\Plan;
use App\Models\Station;
use App\Services\GstService;
use App\Support\Tenancy;
use Filament\Widgets\Widget;

/** Partner companies: go-live checklist and current plan. Hidden once everything is done (plan card stays). */
class PartnerSetup extends Widget
{
    protected string $view = 'filament.widgets.partner-setup';

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $id = Tenancy::companyId();

        return ! Tenancy::isPlatformAdmin() && $id && $id > 0 && ! (Company::find($id)?->is_platform ?? true);
    }

    public function getData(): array
    {
        $company = Company::with('plan')->findOrFail(Tenancy::companyId());
        $stations = Station::where('company_id', $company->id)->with('chargers', 'tariffs')->get();
        $gst = app(GstService::class);
        $docs = $company->documents()->pluck('status', 'type');

        $steps = [
            ['Company approved by Trans EV', $company->status === 'active', CompanyResource::getUrl('edit', ['record' => $company])],
            ['KYC documents uploaded', collect(CompanyDocument::required())->every(fn ($t) => isset($docs[$t]) && $docs[$t] !== 'rejected'), CompanyResource::getUrl('edit', ['record' => $company])],
            ['Payment gateway connected (SabPaisa or Razorpay)', $company->hasPaymentSetup(), CompanyResource::getUrl('edit', ['record' => $company])],
            ...(\App\Services\WalletService::usesWallet($company) ? [['Trans EV wallet recharged', (float) app(\App\Services\WalletService::class)->walletFor($company)->balance > 0, \App\Filament\Pages\WalletPage::getUrl()]] : []),
            ['First station added with chargers and prices', $stations->contains(fn (Station $s) => $s->chargers->isNotEmpty() && $s->tariffs->isNotEmpty()), StationResource::getUrl('index')],
            ['GSTIN set for every station’s state', $stations->isNotEmpty() && $stations->every(fn (Station $s) => (bool) $gst->invoiceGstin($s)), StationResource::getUrl('index')],
            ['A station is approved and live', $stations->contains(fn (Station $s) => $s->approval_status === 'approved' && $s->is_published), StationResource::getUrl('index')],
        ];
        $plan = $company->plan ?? Plan::default();

        return [
            'steps' => $steps,
            'done' => collect($steps)->where(1, true)->count(),
            'plan' => $plan,
            'stationsUsed' => $stations->count(),
            'fees' => \App\Models\Company::feeRatesLabel($company),
            'premium' => collect(Plan::FEATURES)->except(Plan::FREE_FEATURES)->map(fn ($label, $key) => ['label' => $label, 'on' => $company->hasFeature($key)])->values(),
        ];
    }
}
