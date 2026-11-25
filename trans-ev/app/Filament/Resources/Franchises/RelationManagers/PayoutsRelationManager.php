<?php

namespace App\Filament\Resources\Franchises\RelationManagers;

use App\Filament\Resources\Franchises\FranchiseResource;
use App\Models\FranchisePayout;
use App\Services\FranchiseService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/** Monthly commission payouts of a franchise: draft → approved → paid (with the bank reference). */
class PayoutsRelationManager extends RelationManager
{
    protected static string $relationship = 'payouts';

    protected static ?string $title = 'Commission payouts';

    public function isReadOnly(): bool
    {
        return ! FranchiseResource::canManage();
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table->defaultSort('period_start', 'desc')
            ->columns([
                TextColumn::make('period_start')->label('Month')->date('M Y'),
                TextColumn::make('stations_count')->label('Stations')->alignEnd(),
                TextColumn::make('energy_kwh')->label('kWh')->numeric(1)->alignEnd(),
                TextColumn::make('net_sales')->label('Net sales')->money('INR')->alignEnd(),
                TextColumn::make('commission_percent')->label('Rate')->suffix('%')->alignEnd(),
                TextColumn::make('commission_amount')->label('Commission')->money('INR')->alignEnd()->weight('semibold'),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => FranchisePayout::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => ['paid' => 'success', 'approved' => 'info', 'draft' => 'warning'][$state] ?? 'gray')
                    ->description(fn (FranchisePayout $record) => $record->payment_reference ? 'Ref '.$record->payment_reference : null),
            ])
            ->recordActions([
                Action::make('lines')->label('Stations')->icon('heroicon-m-list-bullet')->color('gray')->modalSubmitAction(false)
                    ->modalContent(fn (FranchisePayout $record) => new HtmlString('<table class="w-full text-sm"><thead><tr class="text-gray-500"><th class="text-start">Station</th><th class="text-end">kWh</th><th class="text-end">Net sales</th><th class="text-end">Commission</th></tr></thead><tbody>'
                        .collect($record->lines ?? [])->map(fn ($l) => '<tr class="border-t border-gray-200 dark:border-white/10"><td class="py-1">'.e($l['station']).'</td><td class="text-end">'.e($l['kwh']).'</td><td class="text-end">₹'.number_format((float) $l['net_sales'], 2).'</td><td class="text-end">₹'.number_format((float) $l['commission'], 2).'</td></tr>')->implode('')
                        .'</tbody></table>')),
                Action::make('approve')->label('Approve')->icon('heroicon-m-check')->color('success')->requiresConfirmation()
                    ->visible(fn (FranchisePayout $record) => FranchiseResource::canManage() && $record->status === 'draft')
                    ->action(fn (FranchisePayout $record) => app(FranchiseService::class)->approvePayout($record, auth()->user())),
                Action::make('paid')->label('Mark paid')->icon('heroicon-m-banknotes')
                    ->visible(fn (FranchisePayout $record) => FranchiseResource::canManage() && $record->status === 'approved')
                    ->schema([TextInput::make('reference')->label('Bank reference (UTR)')->required()->maxLength(60)])
                    ->action(function (FranchisePayout $record, array $data) {
                        app(FranchiseService::class)->markPaid($record, auth()->user(), $data['reference']);
                        Notification::make()->success()->title('Marked paid; the franchise has been emailed')->send();
                    }),
            ])
            ->emptyStateHeading('No payouts yet')
            ->emptyStateDescription('Drafts are made on the 1st of each month for the month before, or with “Draft payout” above.');
    }
}
