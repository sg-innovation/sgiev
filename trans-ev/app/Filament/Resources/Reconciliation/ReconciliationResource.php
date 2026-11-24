<?php

namespace App\Filament\Resources\Reconciliation;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Company;
use App\Models\ReconciliationException;
use App\Models\User;
use App\Policies\ReconciliationExceptionPolicy;
use App\Services\ReconciliationService;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/**
 * B8 exceptions queue (Billing & GST → Reconciliation): every mismatch found by the daily reconciliation, with a
 * one-click fix where one is safe, assignment to a team member, and resolve / ignore with a note (audited).
 */
class ReconciliationResource extends Resource
{
    protected static ?string $model = ReconciliationException::class;

    protected static ?string $slug = 'reconciliation';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Billing & GST';

    protected static ?string $navigationLabel = 'Reconciliation';

    protected static ?string $modelLabel = 'reconciliation exception';

    protected static ?int $navigationSort = 6;

    public static function getNavigationBadge(): ?string
    {
        $n = static::getEloquentQuery()->where('status', ReconciliationException::OPEN)->whereIn('severity', ['critical', 'high'])->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getEloquentQuery(): Builder
    {
        $q = parent::getEloquentQuery()->with(['company', 'booking', 'payment', 'refund', 'assignee', 'resolver']);

        return Tenancy::isPlatformAdmin() ? $q : Tenancy::scope($q);
    }

    private static function manages(): bool
    {
        return auth()->check() && ReconciliationExceptionPolicy::manages(auth()->user());
    }

    /** Trans EV staff who can work the queue. */
    public static function agents(): array
    {
        $platform = Company::query()->where('is_platform', true)->value('id');

        return User::query()->where('company_id', $platform)->orderBy('name')->get()
            ->filter(fn (User $u) => $u->can('reconciliation.manage'))->pluck('name', 'id')->all();
    }

    public static function table(Table $table): Table
    {
        $sev = fn (string $state) => match ($state) { 'critical' => 'danger', 'high' => 'warning', 'medium' => 'info', default => 'gray' };

        return $table
            ->defaultSort(fn (Builder $query) => $query->orderByRaw("FIELD(severity, 'critical', 'high', 'medium', 'low')")->orderByDesc('first_seen_at'))
            ->columns([
                TextColumn::make('severity')->badge()->color($sev)->formatStateUsing(fn (string $state) => ReconciliationException::SEVERITIES[$state] ?? $state),
                TextColumn::make('type')->label('Problem')->weight('semibold')->wrap()
                    ->formatStateUsing(fn (string $state) => ReconciliationService::label($state))
                    ->description(fn (ReconciliationException $r) => $r->summary)
                    ->extraHeaderAttributes(['style' => 'min-width: 26rem'])->extraCellAttributes(['style' => 'min-width: 26rem']),
                TextColumn::make('booking.code')->label('Booking')->fontFamily('mono')->placeholder('—')->searchable()
                    ->url(fn (ReconciliationException $r) => $r->booking ? BookingResource::getUrl('view', ['record' => $r->booking]) : null)
                    ->description(fn (ReconciliationException $r) => $r->payment?->merchant_txn_id),
                TextColumn::make('company.trade_name')->label('Operator')->placeholder('—')->visible(fn () => Tenancy::isPlatformAdmin()),
                TextColumn::make('expected_amount')->label('Expected')->money('INR')->alignEnd()->placeholder('—'),
                TextColumn::make('actual_amount')->label('Found')->money('INR')->alignEnd()->placeholder('—')->toggleable(),
                TextColumn::make('first_seen_at')->label('First seen')->since('Asia/Kolkata')->sortable()
                    ->description(fn (ReconciliationException $r) => $r->occurrences > 1 ? "Seen in {$r->occurrences} runs" : 'New'),
                TextColumn::make('assignee.name')->label('Assigned')->placeholder('Unassigned')->visible(fn () => static::manages()),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => ReconciliationException::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) { 'open' => 'danger', 'resolved' => 'success', default => 'gray' })
                    ->description(fn (ReconciliationException $r) => $r->status !== 'open' ? str($r->resolution_note)->limit(60) : null),
            ])
            ->filters([
                SelectFilter::make('severity')->options(ReconciliationException::SEVERITIES)->multiple(),
                SelectFilter::make('type')->label('Problem')->options(collect(ReconciliationService::TYPES)->map(fn ($t) => $t['label'])->all())->multiple(),
                SelectFilter::make('company_id')->label('Operator')->visible(fn () => Tenancy::isPlatformAdmin())
                    ->options(fn () => Company::query()->orderBy('trade_name')->pluck('trade_name', 'id')->all())->searchable(),
                SelectFilter::make('assigned_to')->label('Assigned to')->options(fn () => static::agents())->visible(fn () => static::manages()),
                TernaryFilter::make('fixable')->label('One-click fix available')
                    ->queries(
                        true: fn (Builder $query) => $query->whereIn('type', array_keys(array_filter(ReconciliationService::TYPES, fn ($t) => $t['fix']))),
                        false: fn (Builder $query) => $query->whereNotIn('type', array_keys(array_filter(ReconciliationService::TYPES, fn ($t) => $t['fix']))),
                    ),
            ])
            ->recordActions([
                Action::make('fix')->label('Fix')->icon('heroicon-m-wrench-screwdriver')->color('success')->button()
                    ->visible(fn (ReconciliationException $r) => static::manages() && ReconciliationService::canFix($r))
                    ->requiresConfirmation()
                    ->modalDescription(fn (ReconciliationException $r) => match ($r->type) {
                        'payment_pending' => 'Ask the gateway for the final status of this payment and apply it.',
                        'refund_stuck' => 'Ask the gateway for the status of pending refunds.',
                        'invoice_missing' => 'Issue the GST invoice for this booking now.',
                        'credit_note_missing' => 'Issue the credit note for this refund now.',
                        'wallet_fee_missing' => 'Take the platform fee for this booking from the operator’s wallet.',
                        'wallet_recharge_missing' => 'Credit this recharge to the operator’s wallet.',
                        default => null,
                    })
                    ->action(function (ReconciliationException $r) {
                        try {
                            $ok = app(ReconciliationService::class)->fix($r);
                        } catch (\Throwable $e) {
                            Notification::make()->danger()->title('Could not fix')->body($e->getMessage())->send();

                            return;
                        }
                        activity()->performedOn($r)->causedBy(auth()->user())->log('reconciliation fix applied');
                        $ok ? Notification::make()->success()->title('Fixed and cleared')->send()
                            : Notification::make()->warning()->title('Still found after the fix')->body($r->fresh()->summary)->send();
                    }),
                ActionGroup::make([
                    Action::make('recheck')->label('Re-check now')->icon('heroicon-m-arrow-path')
                        ->visible(fn (ReconciliationException $r) => static::manages() && $r->status === 'open')
                        ->action(fn (ReconciliationException $r) => app(ReconciliationService::class)->recheck($r)
                            ? Notification::make()->success()->title('No longer found: cleared')->send()
                            : Notification::make()->warning()->title('Still found')->send()),
                    Action::make('assign')->label('Assign')->icon('heroicon-m-user-plus')
                        ->visible(fn (ReconciliationException $r) => static::manages() && $r->status === 'open')
                        ->fillForm(fn (ReconciliationException $r) => ['assigned_to' => $r->assigned_to])
                        ->schema([Select::make('assigned_to')->label('Team member')->options(fn () => static::agents())->placeholder('Unassigned')])
                        ->action(fn (ReconciliationException $r, array $data) => $r->update(['assigned_to' => $data['assigned_to'] ?: null])),
                    Action::make('resolve')->label('Mark resolved')->icon('heroicon-m-check-circle')->color('success')
                        ->visible(fn (ReconciliationException $r) => static::manages() && $r->status === 'open')
                        ->modalDescription('Record what was done (e.g. refunded at the gateway, UTR, corrected manually). If the next run still finds the problem, it reopens.')
                        ->schema([Textarea::make('note')->label('What was done')->required()->maxLength(1000)])
                        ->action(fn (ReconciliationException $r, array $data) => static::close($r, ReconciliationException::RESOLVED, $data['note'])),
                    Action::make('ignore')->label('Ignore')->icon('heroicon-m-eye-slash')->color('gray')
                        ->visible(fn (ReconciliationException $r) => static::manages() && $r->status === 'open')
                        ->modalDescription('Ignored exceptions stay closed even if later runs find them again.')
                        ->schema([Textarea::make('note')->label('Why')->required()->maxLength(1000)])
                        ->action(fn (ReconciliationException $r, array $data) => static::close($r, ReconciliationException::IGNORED, $data['note'])),
                    Action::make('reopen')->label('Reopen')->icon('heroicon-m-arrow-uturn-left')
                        ->visible(fn (ReconciliationException $r) => static::manages() && $r->status !== 'open')
                        ->action(fn (ReconciliationException $r) => $r->update(['status' => 'open', 'resolved_at' => null, 'resolved_by' => null])),
                    Action::make('details')->label('Details')->icon('heroicon-m-information-circle')->color('gray')
                        ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                        ->modalHeading(fn (ReconciliationException $r) => ReconciliationService::label($r->type))
                        ->modalContent(fn (ReconciliationException $r) => view('filament.reconciliation.details', ['ex' => $r])),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('assignMany')->label('Assign')->icon('heroicon-m-user-plus')->visible(fn () => static::manages())
                        ->schema([Select::make('assigned_to')->label('Team member')->options(fn () => static::agents())->required()])
                        ->action(fn (Collection $records, array $data) => $records->each->update(['assigned_to' => $data['assigned_to']])),
                    BulkAction::make('resolveMany')->label('Mark resolved')->icon('heroicon-m-check-circle')->visible(fn () => static::manages())
                        ->schema([Textarea::make('note')->label('What was done')->required()->maxLength(1000)])
                        ->action(fn (Collection $records, array $data) => $records->where('status', 'open')->each(fn ($r) => static::close($r, ReconciliationException::RESOLVED, $data['note']))),
                    BulkAction::make('fixMany')->label('Apply fixes')->icon('heroicon-m-wrench-screwdriver')->visible(fn () => static::manages())
                        ->requiresConfirmation()->modalDescription('Runs the one-click fix on each selected exception that has one.')
                        ->action(function (Collection $records) {
                            $svc = app(ReconciliationService::class);
                            $done = $records->filter(fn ($r) => ReconciliationService::canFix($r))->filter(function ($r) use ($svc) {
                                try {
                                    return $svc->fix($r);
                                } catch (\Throwable) {
                                    return false;
                                }
                            })->count();
                            Notification::make()->success()->title("{$done} exceptions fixed and cleared")->send();
                        }),
                ]),
            ])
            ->emptyStateIcon('heroicon-o-check-badge')
            ->emptyStateHeading('Everything reconciles')
            ->emptyStateDescription('Payments, bookings, refunds, GST documents and wallets match. Reconciliation runs every day.');
    }

    public static function close(ReconciliationException $r, string $status, string $note): void
    {
        $r->update(['status' => $status, 'resolution_note' => $note, 'resolved_by' => auth()->id(), 'resolved_at' => now()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListReconciliationExceptions::route('/')];
    }
}
