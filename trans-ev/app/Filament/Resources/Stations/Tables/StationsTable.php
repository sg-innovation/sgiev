<?php

namespace App\Filament\Resources\Stations\Tables;

use App\Enums\StationStatus;
use App\Filament\Resources\Stations\Actions\ChangeStatusAction;
use App\Models\Station;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class StationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('chargers'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('medium')
                    ->description(fn (Station $r) => $r->code),
                TextColumn::make('city')->searchable()->sortable()
                    ->description(fn (Station $r) => $r->state),
                TextColumn::make('approval_status')->label('Approval')->badge()
                    ->formatStateUsing(fn (string $state, Station $r) => $r->needs_review ? 'Changes to review' : ucfirst($state))
                    ->color(fn (string $state, Station $r) => $r->needs_review ? 'info' : match ($state) { 'approved' => 'success', 'submitted' => 'warning', 'rejected' => 'danger', default => 'gray' })
                    ->description(fn (Station $r) => $r->approval_status === 'rejected' ? \Illuminate\Support\Str::limit((string) $r->approval_note, 40) : null),
                TextColumn::make('status')->badge()->sortable()
                    ->description(fn (Station $r) => $r->status_reason ? str($r->status_reason)->limit(40) : null),
                TextColumn::make('status_until')->label('Back online')->dateTime('d M, h:i A', Station::TIMEZONE)
                    ->placeholder('—')->toggleable(),
                TextColumn::make('open_now')->label('Now')->badge()
                    ->state(fn (Station $r) => $r->isOpenAt() ? 'Open' : 'Closed')
                    ->color(fn (string $state) => $state === 'Open' ? 'success' : 'gray'),
                TextColumn::make('chargers_count')->label('Chargers')->sortable()->alignCenter(),
                IconColumn::make('is_24x7')->label('24×7')->boolean()->alignCenter(),
                IconColumn::make('is_published')->label('Live')->boolean()->alignCenter(),
                TextColumn::make('updated_at')->dateTime('d M Y, h:i A', Station::TIMEZONE)->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(StationStatus::class),
                SelectFilter::make('approval_status')->label('Approval')->options(['draft' => 'Draft', 'submitted' => 'Submitted', 'approved' => 'Approved', 'rejected' => 'Rejected']),
                SelectFilter::make('city')->options(fn () => Station::query()->distinct()->orderBy('city')->pluck('city', 'city')->all()),
                TernaryFilter::make('is_published')->label('Visible on website'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                \Filament\Actions\Action::make('submit')->label('Submit for approval')->icon('heroicon-m-paper-airplane')->color('success')
                    ->visible(fn (Station $r) => (in_array($r->approval_status, ['draft', 'rejected'], true)) && auth()->user()->can('stations.manage') && ! \App\Support\Tenancy::isPlatformAdmin())
                    ->requiresConfirmation()
                    ->action(function (Station $r) {
                        try {
                            app(\App\Services\ApprovalService::class)->submitStation($r, auth()->user());
                            \Filament\Notifications\Notification::make()->success()->title('Submitted for approval')->send();
                        } catch (\Illuminate\Validation\ValidationException $e) {
                            \Filament\Notifications\Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();
                        }
                    }),
                \Filament\Actions\Action::make('approve')->icon('heroicon-m-check-badge')->color('success')
                    ->visible(fn (Station $r) => ($r->approval_status === 'submitted' || $r->needs_review) && \App\Support\Tenancy::platformCan('partners.review'))
                    ->requiresConfirmation()
                    ->action(function (Station $r) {
                        try {
                            app(\App\Services\ApprovalService::class)->approveStation($r, auth()->user());
                            \Filament\Notifications\Notification::make()->success()->title('Station approved')->send();
                        } catch (\Illuminate\Validation\ValidationException $e) {
                            \Filament\Notifications\Notification::make()->danger()->title(collect($e->errors())->flatten()->first())->send();
                        }
                    }),
                \Filament\Actions\Action::make('rejectStation')->label('Reject')->icon('heroicon-m-x-circle')->color('danger')
                    ->visible(fn (Station $r) => ($r->approval_status === 'submitted' || $r->needs_review) && \App\Support\Tenancy::platformCan('partners.review'))
                    ->schema([\Filament\Forms\Components\Textarea::make('note')->label('Reason (sent to the partner)')->required()->minLength(10)->maxLength(500)])
                    ->action(fn (Station $r, array $data) => app(\App\Services\ApprovalService::class)->rejectStation($r, auth()->user(), $data['note'])),
                ChangeStatusAction::make(),
                ActionGroup::make([
                    EditAction::make(),
                    DeleteAction::make()->label('Archive'),
                    RestoreAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->label('Archive selected'),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
