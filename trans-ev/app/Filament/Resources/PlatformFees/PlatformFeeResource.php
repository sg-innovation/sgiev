<?php

namespace App\Filament\Resources\PlatformFees;

use App\Models\PlatformFeeStatement;
use App\Models\Station;
use App\Services\PlatformFeeService;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Monthly platform-fee statements. Trans EV staff with platform_fees.manage generate, issue and record payments;
 * operators with payments.view see their own issued invoices.
 */
class PlatformFeeResource extends Resource
{
    protected static ?string $model = PlatformFeeStatement::class;

    protected static ?string $slug = 'platform-fees';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Billing & GST';

    protected static ?string $navigationLabel = 'Platform fees';

    protected static ?string $modelLabel = 'platform-fee statement';

    protected static ?int $navigationSort = 3;

    public static function manager(): bool
    {
        return Tenancy::platformCan('platform_fees.manage') && Tenancy::isPlatformAdmin();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('company');

        return static::manager() ? $query : $query->where('company_id', Tenancy::companyId() ?? -1)->whereIn('status', ['issued', 'paid']);
    }

    public static function getViewAnyAuthorizationResponse(): Response
    {
        $user = auth()->user();
        if (! $user) {
            return Response::deny();
        }
        $company = $user->company;

        return static::manager() || ($user->can('payments.view') && $company && ! $company->is_platform) ? Response::allow() : Response::deny();
    }

    public static function getCreateAuthorizationResponse(): Response
    {
        return Response::deny();
    }

    public static function getEditAuthorizationResponse(Model $record): Response
    {
        return Response::deny();
    }

    public static function getDeleteAuthorizationResponse(Model $record): Response
    {
        return Response::deny();
    }

    public static function getDeleteAnyAuthorizationResponse(): Response
    {
        return Response::deny();
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::manager()) {
            return null;
        }
        $n = PlatformFeeStatement::query()->where('status', 'draft')->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Draft statements waiting to be issued';
    }

    public static function table(Table $table): Table
    {
        $svc = fn () => app(PlatformFeeService::class);

        return $table
            ->defaultSort('period_start', 'desc')
            ->columns([
                TextColumn::make('period_start')->label('Month')->date('M Y')->sortable()
                    ->description(fn (PlatformFeeStatement $r) => $r->number),
                TextColumn::make('company.trade_name')->label('Operator')->searchable()->visible(fn () => static::manager()),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => PlatformFeeStatement::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) { 'paid' => 'success', 'issued' => 'warning', 'void' => 'gray', default => 'info' })
                    ->description(fn (PlatformFeeStatement $r) => $r->status === 'issued' && $r->due_on?->isPast() ? 'Overdue' : null),
                TextColumn::make('bookings_count')->label('Bookings')->alignCenter(),
                TextColumn::make('sales_taxable')->label('Net sales')->money('INR')->alignEnd(),
                TextColumn::make('total')->label('Fees (incl. GST)')->money('INR')->alignEnd()->summarize(Sum::make()->money('INR')->label('')),
                TextColumn::make('reimbursement_amount')->label('Coupon credit')->money('INR')->alignEnd()->color('success'),
                TextColumn::make('net_payable')->label('Net payable')->money('INR')->alignEnd()->weight('semibold')->summarize(Sum::make()->money('INR')->label('')),
                TextColumn::make('due_on')->label('Due')->date('d M Y')->placeholder('—'),
                TextColumn::make('paid_amount')->label('Paid')->money('INR')->placeholder('—')->description(fn (PlatformFeeStatement $r) => $r->payment_reference),
            ])
            ->filters([
                SelectFilter::make('status')->options(PlatformFeeStatement::STATUSES),
                SelectFilter::make('company_id')->label('Operator')->relationship('company', 'trade_name')->visible(fn () => static::manager()),
            ])
            ->headerActions([
                Action::make('generate')->label('Generate statements')->icon('heroicon-m-calculator')
                    ->visible(fn () => static::manager())
                    ->modalDescription('Creates or refreshes draft statements for every operator for the chosen month. Issued statements are not changed.')
                    ->schema([
                        \Filament\Forms\Components\Select::make('month')->required()
                            ->options(fn () => collect(range(1, 12))->mapWithKeys(fn ($i) => [($d = now(Station::TIMEZONE)->startOfMonth()->subMonths($i))->format('Y-m') => $d->format('F Y')])
                                ->prepend(now(Station::TIMEZONE)->format('F Y').' (so far)', now(Station::TIMEZONE)->format('Y-m'))->all())
                            ->default(now(Station::TIMEZONE)->subMonthNoOverflow()->format('Y-m')),
                    ])
                    ->action(function (array $data) use ($svc) {
                        $n = $svc()->generateAll(\Illuminate\Support\Carbon::createFromFormat('Y-m', $data['month'], Station::TIMEZONE))->count();
                        Notification::make()->success()->title("{$n} draft statements ready")->send();
                    }),
            ])
            ->recordActions([
                Action::make('pdf')->label('PDF')->icon('heroicon-m-arrow-down-tray')->color('gray')
                    ->url(fn (PlatformFeeStatement $r) => route('platform-fees.pdf', $r), shouldOpenInNewTab: true),
                Action::make('issue')->icon('heroicon-m-paper-airplane')->color('success')->requiresConfirmation()
                    ->modalDescription('Numbers the statement as a Trans EV tax invoice, locks the amounts and emails it to the operator.')
                    ->visible(fn (PlatformFeeStatement $r) => static::manager() && $r->status === 'draft')
                    ->action(function (PlatformFeeStatement $r) use ($svc) {
                        try {
                            $s = $svc()->issue($r, auth()->user());
                            Notification::make()->success()->title("Issued {$s->number}")->send();
                        } catch (ValidationException $e) {
                            Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();
                        }
                    }),
                Action::make('refresh')->label('Recalculate')->icon('heroicon-m-arrow-path')->color('gray')
                    ->visible(fn (PlatformFeeStatement $r) => static::manager() && $r->status === 'draft')
                    ->action(fn (PlatformFeeStatement $r) => $svc()->generate($r->company, $r->period_start)),
                Action::make('payment')->label('Record payment')->icon('heroicon-m-banknotes')->color('primary')
                    ->visible(fn (PlatformFeeStatement $r) => static::manager() && $r->status === 'issued')
                    ->schema(fn (PlatformFeeStatement $r) => [
                        TextInput::make('amount')->numeric()->required()->minValue(0.01)->prefix('₹')->default(bcsub((string) $r->net_payable, (string) ($r->paid_amount ?? 0), 2)),
                        TextInput::make('reference')->label('UTR / reference')->required()->maxLength(60),
                    ])
                    ->action(fn (PlatformFeeStatement $r, array $data) => $svc()->recordPayment($r, (string) $data['amount'], $data['reference'], auth()->user())),
                Action::make('void')->icon('heroicon-m-x-circle')->color('danger')
                    ->visible(fn (PlatformFeeStatement $r) => static::manager() && $r->status === 'draft')
                    ->schema([Textarea::make('reason')->required()->minLength(5)->maxLength(300)])
                    ->action(fn (PlatformFeeStatement $r, array $data) => $svc()->void($r, auth()->user(), $data['reason'])),
            ])
            ->emptyStateHeading('No platform-fee statements yet')
            ->emptyStateDescription('Statements are generated on the 1st of each month for the previous month.');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPlatformFees::route('/')];
    }
}
