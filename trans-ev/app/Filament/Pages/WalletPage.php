<?php

namespace App\Filament\Pages;

use App\Models\Company;
use App\Models\Station;
use App\Models\WalletTransaction;
use App\Services\Payments\PaymentService;
use App\Services\WalletService;
use App\Support\BusinessRules;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Billing & GST → Trans EV wallet. Operators see their prepaid balance, recharge online and see every movement;
 * Trans EV staff (platform_fees.manage) can open any operator's wallet and add or correct funds.
 */
class WalletPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.wallet';

    protected static ?string $slug = 'wallet';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static string|UnitEnum|null $navigationGroup = 'Billing & GST';

    protected static ?string $navigationLabel = 'Trans EV wallet';

    protected static ?string $title = 'Trans EV wallet';

    protected static ?int $navigationSort = 4;

    #[Url]
    public ?int $company = null;

    public static function manager(): bool
    {
        return Tenancy::platformCan('platform_fees.manage') && Tenancy::isPlatformAdmin();
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }
        if (static::manager()) {
            return true;
        }
        $c = $user->company;

        return $user->can('wallet.view') && $c && ! $c->is_platform;
    }

    public function mount(): void
    {
        if (! static::manager()) {
            $this->company = auth()->user()->company_id;
        }
        $this->company ??= Company::query()->where('is_platform', false)->where('billing_mode', 'wallet')->orderBy('trade_name')->value('id');
    }

    public function operator(): ?Company
    {
        if (! static::manager() && $this->company !== auth()->user()->company_id) {
            abort(403);
        }

        return $this->company ? Company::query()->with('plan')->find($this->company) : null;
    }

    public function summary(): array
    {
        $c = $this->operator();
        if (! $c) {
            return [];
        }
        $svc = app(WalletService::class);
        $wallet = $svc->walletFor($c);
        $reserved = $svc->reserved($c);
        $sample = $svc->feeFor($c, '500.00');

        return [
            'company' => $c, 'wallet' => $wallet, 'mode' => $c->billing_mode,
            'balance' => (float) $wallet->balance, 'reserved' => (float) $reserved, 'available' => (float) bcsub((string) $wallet->balance, $reserved, 2),
            'rates' => Company::feeRatesLabel($c), 'sample' => (float) $sample['total'],
            'month' => (float) WalletTransaction::query()->where('company_id', $c->id)->where('type', 'platform_fee')->where('created_at', '>=', now(Station::TIMEZONE)->startOfMonth()->utc())->sum('amount') * -1,
            'operators' => static::manager() ? Company::query()->where('is_platform', false)->orderBy('trade_name')->get(['id', 'trade_name', 'billing_mode']) : collect(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => WalletTransaction::query()->where('company_id', $this->company ?? -1)->with(['booking', 'creator']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('d M Y, h:i A', Station::TIMEZONE),
                TextColumn::make('type')->badge()->formatStateUsing(fn (string $state) => WalletTransaction::TYPES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) { 'recharge', 'manual_credit', 'fee_reversal' => 'success', 'coupon_credit' => 'info', 'platform_fee', 'subscription' => 'warning', default => 'gray' }),
                TextColumn::make('description')->wrap()->description(fn (WalletTransaction $r) => $r->reference),
                TextColumn::make('amount')->alignEnd()->weight('semibold')
                    ->formatStateUsing(fn ($state) => ((float) $state >= 0 ? '+ ' : '− ').'₹'.number_format(abs((float) $state), 2))
                    ->color(fn ($state) => (float) $state >= 0 ? 'success' : 'danger'),
                TextColumn::make('balance_after')->label('Balance')->money('INR')->alignEnd(),
            ])
            ->filters([SelectFilter::make('type')->options(WalletTransaction::TYPES)])
            ->emptyStateHeading('No wallet activity yet')
            ->emptyStateDescription('Recharges, platform fees taken on bookings and refunds of fees appear here.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recharge')->label('Recharge wallet')->icon('heroicon-m-plus-circle')
                ->visible(fn () => ! static::manager() && auth()->user()->can('wallet.recharge'))
                ->modalDescription('Pay online to Trans EV. The balance is used to cover the platform fee on each booking.')
                ->schema([
                    ToggleButtons::make('preset')->label('Quick amounts')->inline()->live()
                        ->options(['1000' => '₹1,000', '2500' => '₹2,500', '5000' => '₹5,000', '10000' => '₹10,000'])
                        ->afterStateUpdated(fn ($state, $set) => $set('amount', $state)),
                    TextInput::make('amount')->numeric()->required()->prefix('₹')->minValue(fn () => BusinessRules::int('wallet.min_recharge'))->maxValue(1000000)
                        ->helperText(fn () => 'Minimum ₹'.number_format(BusinessRules::int('wallet.min_recharge'))),
                ])
                ->action(function (array $data) {
                    try {
                        $url = app(PaymentService::class)->startRecharge(auth()->user()->company, (string) $data['amount'], auth()->user());
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();

                        return;
                    }
                    $this->redirect($url);
                }),
            Action::make('adjust')->label('Add or correct funds')->icon('heroicon-m-adjustments-horizontal')->color('gray')
                ->visible(fn () => static::manager() && $this->company)
                ->modalDescription('Use a positive amount for money received (e.g. bank transfer) and a negative amount for a correction.')
                ->schema([
                    TextInput::make('amount')->numeric()->required()->prefix('₹')->minValue(-1000000)->maxValue(1000000),
                    TextInput::make('reason')->required()->maxLength(200)->placeholder('Bank transfer received'),
                    TextInput::make('reference')->label('UTR / reference')->required()->maxLength(80),
                ])
                ->action(function (array $data) {
                    $c = $this->operator();
                    app(WalletService::class)->adjust($c, number_format((float) $data['amount'], 2, '.', ''), $data['reason'], $data['reference'], auth()->user());
                    activity()->performedOn($c)->causedBy(auth()->user())->withProperties($data)->log('wallet adjusted');
                    Notification::make()->success()->title('Wallet updated')->send();
                }),
            Action::make('alert')->label('Low-balance alert')->icon('heroicon-m-bell')->color('gray')
                ->visible(fn () => $this->company && (static::manager() || auth()->user()->can('wallet.recharge')))
                ->fillForm(fn () => ['low_balance_alert' => (float) app(WalletService::class)->walletFor($this->operator())->low_balance_alert])
                ->schema([TextInput::make('low_balance_alert')->label('Email me when the balance falls below')->numeric()->minValue(0)->maxValue(1000000)->prefix('₹')->required()])
                ->action(function (array $data) {
                    app(WalletService::class)->walletFor($this->operator())->update(['low_balance_alert' => $data['low_balance_alert']]);
                    Notification::make()->success()->title('Alert updated')->send();
                }),
        ];
    }
}
