<?php

namespace App\Filament\Widgets;

use App\Enums\BookingStatus;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\ContactMessages\ContactMessageResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Stations\StationResource;
use App\Filament\Widgets\Concerns\DashboardData;
use App\Models\Booking;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\ContactMessage;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Station;
use App\Services\GstService;
use App\Support\Tenancy;
use Filament\Widgets\Widget;

/** "Needs your attention": the work queue for the signed-in admin, by role and company. */
class ActionCentre extends Widget
{
    use DashboardData;

    protected string $view = 'filament.widgets.action-centre';

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '120s';

    /** @return list<array{label:string, hint:string, count:int, url:?string, tone:string, icon:string}> */
    public function getItems(): array
    {
        $platform = Tenancy::isPlatformAdmin();
        $companyId = Tenancy::companyId();
        $items = [];

        if (Tenancy::platformCan('partners.review')) {
            $items[] = $this->item('Partner applications', 'Review KYC and approve or request changes',
                Company::where('status', 'pending')->count(), CompanyResource::getUrl('index', ['tableFilters' => ['status' => ['value' => 'pending']]]), 'warning', 'heroicon-o-building-office-2');
            $items[] = $this->item('KYC documents to check', 'Uploaded documents waiting for verification',
                CompanyDocument::where('status', 'pending')->whereHas('company', fn ($q) => $q->where('status', '!=', 'pending'))->count(), CompanyResource::getUrl('index'), 'warning', 'heroicon-o-identification');
        }

        $stations = Tenancy::scope(Station::query());
        $items[] = $this->item($platform ? 'Stations awaiting approval' : 'Stations not live yet',
            $platform ? 'New stations and changes to approved ones' : 'Drafts, rejected, or waiting for Trans EV approval',
            $platform ? (clone $stations)->where(fn ($q) => $q->where('approval_status', 'submitted')->orWhere('needs_review', true))->count()
                : (clone $stations)->where('approval_status', '!=', 'approved')->count(),
            StationResource::getUrl('index'), 'warning', 'heroicon-o-bolt');

        // GST compliance: a station can't be invoiced without a GSTIN registered in its state.
        $gst = app(GstService::class);
        $noGstin = (clone $stations)->with('company')->where('approval_status', 'approved')->get()->filter(fn (Station $s) => ! $gst->invoiceGstin($s))->count();
        $items[] = $this->item('Stations without a state GSTIN', 'Invoices cannot be issued there: add the GSTIN in Station → Legal & contact',
            $noGstin, StationResource::getUrl('index'), 'danger', 'heroicon-o-exclamation-triangle');

        if (static::canSeeMoney()) {
            $paid = [BookingStatus::Confirmed, BookingStatus::CheckedIn, BookingStatus::Completed, BookingStatus::NoShow, BookingStatus::Cancelled];
            $items[] = $this->item('Paid bookings without an invoice', 'Usually a missing GSTIN; retried hourly',
                Tenancy::scope(Booking::query())->whereIn('status', $paid)->whereDoesntHave('invoice')
                    ->whereHas('payments', fn ($q) => $q->where('status', Payment::SUCCESS))->count(),
                BookingResource::getUrl('index'), 'danger', 'heroicon-o-document-text');
            $items[] = $this->item('Refunds in progress', 'Waiting for SabPaisa to confirm',
                Tenancy::scope(Refund::query())->where('status', Refund::INITIATED)->count(), PaymentResource::getUrl('index'), 'info', 'heroicon-o-arrow-uturn-left');
            $items[] = $this->item('Failed refunds', 'Need a manual refund by finance',
                Tenancy::scope(Refund::query())->where('status', Refund::FAILED)->count(), PaymentResource::getUrl('index'), 'danger', 'heroicon-o-x-circle');
        }

        if (! $platform && $companyId) {
            $company = Company::find($companyId);
            if ($company && ! $company->hasPaymentSetup()) {
                $items[] = $this->item('Payments not connected', 'Add your SabPaisa keys so customers can pay and book',
                    1, CompanyResource::getUrl('edit', ['record' => $company]), 'danger', 'heroicon-o-credit-card');
            }
            if ($company && \App\Services\WalletService::usesWallet($company)) {
                $svc = app(\App\Services\WalletService::class);
                $available = (float) $svc->available($company);
                $low = $available < (float) $svc->walletFor($company)->low_balance_alert;
                $items[] = $this->item($available <= 0 ? 'Wallet empty: bookings paused' : 'Trans EV wallet balance',
                    '₹'.number_format($available, 2).' available for platform fees. Recharge to keep receiving bookings.',
                    $low ? 1 : 0, \App\Filament\Pages\WalletPage::getUrl(), $available <= 0 ? 'danger' : 'warning', 'heroicon-o-wallet');
            }
            if ($company && in_array($company->status, ['pending', 'changes_requested'], true)) {
                $items[] = $this->item($company->status === 'pending' ? 'Application under review' : 'Changes requested on your application',
                    $company->review_note ?: 'Trans EV is verifying your documents',
                    1, CompanyResource::getUrl('edit', ['record' => $company]), $company->status === 'pending' ? 'info' : 'warning', 'heroicon-o-clipboard-document-check');
            }
        }

        if (auth()->user()?->isPlatformStaff()) {
            $items[] = $this->item('New customer queries', 'From the contact form',
                ContactMessage::where('status', 'new')->count(), ContactMessageResource::getUrl('index'), 'info', 'heroicon-o-inbox');
        }

        $holds = static::scopedBookings()->where('status', BookingStatus::Held)->where('hold_expires_at', '>', now())->count();
        $items[] = $this->item('Slots waiting for payment', 'Held for up to 10 minutes, then released automatically', $holds, BookingResource::getUrl('index'), 'gray', 'heroicon-o-clock');

        return collect($items)->sortByDesc(fn ($i) => [$i['count'] > 0, ['danger' => 3, 'warning' => 2, 'info' => 1][$i['tone']] ?? 0])->values()->all();
    }

    private function item(string $label, string $hint, int $count, ?string $url, string $tone, string $icon): array
    {
        return compact('label', 'hint', 'count', 'url', 'tone', 'icon');
    }
}
