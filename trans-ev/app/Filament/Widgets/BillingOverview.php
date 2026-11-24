<?php

namespace App\Filament\Widgets;

use App\Enums\BookingStatus;
use App\Filament\Widgets\Concerns\DashboardData;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Refund;
use App\Models\User;
use App\Support\Tenancy;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Billing & GST for the current month: invoiced value, GST (net of credit notes), refunds, discounts, customers. */
class BillingOverview extends StatsOverviewWidget
{
    use DashboardData;

    protected static ?int $sort = 2;

    protected ?string $heading = 'Billing & GST this month';

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return static::canSeeMoney();
    }

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        [$from, $to] = static::istRange('month');
        $inv = Tenancy::scope(Invoice::query())->whereBetween('issued_at', [$from, $to]);
        $cn = Tenancy::scope(CreditNote::query())->whereBetween('issued_at', [$from, $to]);

        $invTotal = (float) (clone $inv)->sum('total');
        $invTax = (float) (clone $inv)->sum('total_tax');
        $cnTotal = (float) (clone $cn)->sum('total');
        $cnTax = (float) (clone $cn)->sum('total_tax');
        $b2b = (clone $inv)->where('supply_type', 'B2B')->count();
        $count = (clone $inv)->count();

        $refunded = (float) Tenancy::scope(Refund::query())->where('status', Refund::SUCCESS)->whereBetween('refunded_at', [$from, $to])->sum('amount');
        $paid = [BookingStatus::Confirmed->value, BookingStatus::CheckedIn->value, BookingStatus::Completed->value, BookingStatus::NoShow->value];
        $discounts = static::scopedBookings()->whereIn('status', $paid)->whereBetween('confirmed_at', [$from, $to]);
        $discountTotal = (float) (clone $discounts)->sum('discount_amount');
        $couponUses = (clone $discounts)->whereNotNull('coupon_id')->count();

        if (Tenancy::isPlatformAdmin()) {
            $customers = User::query()->role(\App\Enums\Role::Customer->value)->whereBetween('created_at', [$from, $to])->count();
            $customerLabel = 'New customers';
            $customerNote = User::query()->role(\App\Enums\Role::Customer->value)->count().' registered in total';
        } else {
            $customers = static::scopedBookings()->whereIn('status', $paid)->whereBetween('start_at', [$from, $to])->distinct()->count('user_id');
            $customerLabel = 'Customers charged';
            $customerNote = 'Unique customers with a paid booking';
        }

        return [
            Stat::make('Invoiced', static::inr($invTotal))
                ->description("{$count} tax invoices · {$b2b} B2B")->descriptionIcon('heroicon-m-document-text')->color('primary'),
            Stat::make('GST payable (net)', static::inr($invTax - $cnTax))
                ->description('Invoices '.static::inr($invTax).' − credit notes '.static::inr($cnTax))->descriptionIcon('heroicon-m-building-library')->color('warning'),
            Stat::make('Refunded', static::inr($refunded))
                ->description('Credit notes issued: '.static::inr($cnTotal))->descriptionIcon('heroicon-m-arrow-uturn-left')->color($refunded > 0 ? 'danger' : 'gray'),
            Stat::make($customerLabel, number_format($customers))
                ->description($couponUses ? "{$couponUses} coupon uses · ".static::inr($discountTotal).' off' : $customerNote)
                ->descriptionIcon($couponUses ? 'heroicon-m-ticket' : 'heroicon-m-users')->color('success'),
        ];
    }
}
