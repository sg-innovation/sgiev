<?php

namespace App\Filament\Resources\Companies\Tables;

use App\Models\Company;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CompaniesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (\Illuminate\Database\Eloquent\Builder $query) => $query->withCount(['stations', 'users']))
            ->defaultSort('is_platform', 'desc')
            ->columns([
                TextColumn::make('trade_name')->label('Company')->weight('medium')->searchable(['trade_name', 'legal_name'])
                    ->description(fn (Company $record) => $record->legal_name),
                TextColumn::make('is_platform')->label('')->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Platform' : null)->color('info'),
                TextColumn::make('gstin')->label('GSTIN')->placeholder('Not set')->description(fn (Company $record) => $record->state),
                TextColumn::make('stations_count')->label('Stations')->alignCenter()->sortable(),
                TextColumn::make('users_count')->label('Staff')->alignCenter(),
                IconColumn::make('payments')->label('SabPaisa')->alignCenter()
                    ->state(fn (Company $record) => $record->hasPaymentSetup())->boolean()
                    ->tooltip(fn (Company $record) => $record->hasPaymentSetup() ? 'Payments set up ('.($record->payment_gateway === 'razorpay' ? 'Razorpay '.(str_starts_with((string) $record->razorpay_key_id, 'rzp_live_') ? 'live' : 'test') : 'SabPaisa '.$record->sabpaisa_env).')' : 'Payment account not connected: customers cannot pay for this company’s stations'),
                TextColumn::make('status')->badge()
                    ->formatStateUsing(fn (string $state) => Company::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) { 'active' => 'success', 'pending' => 'warning', 'changes_requested' => 'info', default => 'danger' })
                    ->description(fn (Company $record) => $record->status !== 'active' ? \Illuminate\Support\Str::limit((string) $record->review_note, 40) : null),
                TextColumn::make('plan.name')->label('Plan')->badge()->color('gray')->placeholder('—'),
                TextColumn::make('onboarded_at')->label('Onboarded')->date('d M Y')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(Company::STATUSES),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('approve')->icon('heroicon-m-check-badge')->color('success')
                    ->visible(fn (Company $record) => in_array($record->status, ['pending', 'changes_requested'], true) && \App\Support\Tenancy::platformCan('partners.review'))
                    ->requiresConfirmation()
                    ->modalDescription('Pending KYC documents are marked verified and the owner is notified. Stations still need their own approval. Set the commission agreed with this partner (leave blank to use the plan rate).')
                    ->fillForm(fn (Company $record) => ['plan_id' => $record->plan_id ?? \App\Models\Plan::default()?->id, 'commission_percent' => $record->commission_percent, 'per_booking_fee' => $record->per_booking_fee, 'fee_terms_note' => $record->fee_terms_note, 'billing_mode' => $record->billing_mode])
                    ->schema([
                        \Filament\Forms\Components\Select::make('plan_id')->label('Plan')->required()
                            ->options(fn () => \App\Models\Plan::query()->where('is_active', true)->orderBy('sort')->get()->mapWithKeys(fn ($p) => [$p->id => $p->name.' · '.rtrim(rtrim((string) $p->commission_percent, '0'), '.').'% commission'.((float) $p->per_booking_fee > 0 ? ' + ₹'.$p->per_booking_fee.'/booking' : '')])->all()),
                        \Filament\Forms\Components\TextInput::make('commission_percent')->label('Agreed commission on each booking')->numeric()->minValue(0)->maxValue(50)->suffix('%')->placeholder('Plan rate'),
                        \Filament\Forms\Components\TextInput::make('per_booking_fee')->label('Agreed fixed fee per booking')->numeric()->minValue(0)->maxValue(1000)->prefix('₹')->placeholder('Plan rate'),
                        \Filament\Forms\Components\Radio::make('billing_mode')->label('How platform fees are collected')->required()->inline()
                            ->options(['wallet' => 'Prepaid wallet (fee taken on every booking)', 'invoice' => 'Monthly invoice']),
                        Textarea::make('fee_terms_note')->label('Terms agreed (for the record)')->rows(2)->maxLength(500)->placeholder('e.g. 2% for the first 6 months, then plan rate'),
                    ])
                    ->action(function (Company $record, array $data) {
                        try {
                            app(\App\Services\ApprovalService::class)->approveCompany($record, auth()->user(), $data);
                            Notification::make()->success()->title("{$record->trade_name} approved")->send();
                        } catch (\Illuminate\Validation\ValidationException $e) {
                            Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();
                        }
                    }),
                Action::make('requestChanges')->label('Request changes')->icon('heroicon-m-pencil-square')->color('info')
                    ->visible(fn (Company $record) => $record->status === 'pending' && \App\Support\Tenancy::platformCan('partners.review'))
                    ->schema([Textarea::make('note')->label('What should the partner fix?')->required()->minLength(10)->maxLength(500)])
                    ->action(fn (Company $record, array $data) => app(\App\Services\ApprovalService::class)->requestCompanyChanges($record, auth()->user(), $data['note'])),
                Action::make('reject')->icon('heroicon-m-x-circle')->color('danger')
                    ->visible(fn (Company $record) => in_array($record->status, ['pending', 'changes_requested'], true) && \App\Support\Tenancy::platformCan('partners.review'))
                    ->requiresConfirmation()
                    ->schema([Textarea::make('note')->label('Reason (sent to the partner)')->required()->minLength(10)->maxLength(500)])
                    ->action(fn (Company $record, array $data) => app(\App\Services\ApprovalService::class)->rejectCompany($record, auth()->user(), $data['note'])),
                Action::make('suspend')->icon('heroicon-m-pause-circle')->color('danger')
                    ->visible(fn (Company $record) => $record->status === 'active' && auth()->user()->can('suspend', $record))
                    ->requiresConfirmation()
                    ->modalDescription('The company’s stations disappear from the marketplace, new bookings stop and its staff can no longer log in. Existing records are kept.')
                    ->schema([Textarea::make('reason')->required()->minLength(5)])
                    ->action(function (Company $record, array $data) {
                        $record->forceFill(['status' => 'suspended'])->save();
                        activity()->performedOn($record)->withProperties(['reason' => $data['reason']])->log('suspended');
                        Notification::make()->warning()->title("{$record->trade_name} suspended")->send();
                    }),
                Action::make('reactivate')->icon('heroicon-m-play-circle')->color('success')
                    ->visible(fn (Company $record) => $record->status !== 'active' && auth()->user()->can('suspend', $record))
                    ->requiresConfirmation()
                    ->action(function (Company $record) {
                        $record->forceFill(['status' => 'active'])->save();
                        activity()->performedOn($record)->log('reactivated');
                        Notification::make()->success()->title("{$record->trade_name} reactivated")->send();
                    }),
            ]);
    }
}
