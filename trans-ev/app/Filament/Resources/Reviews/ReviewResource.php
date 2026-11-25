<?php

namespace App\Filament\Resources\Reviews;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Review;
use App\Models\Station;
use App\Services\ReviewService;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Network → Ratings & reviews (D3). Operators see their stations' reviews and reply publicly (reviews.reply);
 * Trans EV approves or hides written reviews (reviews.moderate).
 */
class ReviewResource extends Resource
{
    protected static ?string $model = Review::class;

    protected static ?string $slug = 'reviews';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static string|UnitEnum|null $navigationGroup = 'Network';

    protected static ?string $navigationLabel = 'Ratings & reviews';

    protected static ?string $modelLabel = 'review';

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('reviews.view') || Tenancy::platformCan('reviews.moderate');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    private static function moderator(): bool
    {
        return Tenancy::platformCan('reviews.moderate');
    }

    public static function getNavigationBadge(): ?string
    {
        $n = static::moderator() ? static::getEloquentQuery()->where('status', 'pending')->count() : 0;

        return $n ? (string) $n : null;
    }

    public static function getEloquentQuery(): Builder
    {
        $q = parent::getEloquentQuery()->with(['station', 'charger', 'user', 'booking']);

        return Tenancy::isPlatformAdmin() ? $q : Tenancy::scope($q);
    }

    public static function table(Table $table): Table
    {
        $tags = ReviewService::tags();

        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('station_rating')->label('Rating')->formatStateUsing(fn ($state) => str_repeat('★', (int) $state).str_repeat('☆', 5 - (int) $state))->color('warning')
                    ->description(fn (Review $record) => $record->charger_rating ? 'Charger '.$record->charger_rating.'/5' : null)->sortable(),
                TextColumn::make('body')->label('Review')->wrap()->limit(160)->placeholder('Stars only')
                    ->description(fn (Review $record) => collect($record->tags ?? [])->map(fn ($t) => $tags[$t] ?? $t)->implode(' · ') ?: null),
                TextColumn::make('station.name')->label('Station')->description(fn (Review $record) => $record->charger?->code)->searchable(),
                TextColumn::make('reviewer')->label('By')->state(fn (Review $record) => $record->displayName())
                    ->description(fn (Review $record) => $record->booking?->code)
                    ->url(fn (Review $record) => $record->booking ? BookingResource::getUrl('view', ['record' => $record->booking]) : null),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => Review::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) { 'published' => 'success', 'pending' => 'warning', default => 'gray' })
                    ->description(fn (Review $record) => $record->moderation_note),
                TextColumn::make('owner_reply')->label('Your reply')->wrap()->limit(100)->placeholder('No reply yet'),
                TextColumn::make('created_at')->label('Written')->since(Station::TIMEZONE)->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(Review::STATUSES),
                SelectFilter::make('station_rating')->label('Stars')->options([5 => '5 ★', 4 => '4 ★', 3 => '3 ★', 2 => '2 ★', 1 => '1 ★']),
                SelectFilter::make('station_id')->label('Station')->searchable()
                    ->options(fn () => (Tenancy::isPlatformAdmin() ? Station::query() : Tenancy::scope(Station::query()))->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->recordActions([
                Action::make('approve')->label('Publish')->icon('heroicon-m-check')->color('success')
                    ->visible(fn (Review $record) => static::moderator() && $record->status !== 'published')
                    ->action(function (Review $record) {
                        app(ReviewService::class)->moderate($record, true, null, auth()->user());
                        Notification::make()->success()->title('Review published')->send();
                    }),
                Action::make('hide')->label('Hide')->icon('heroicon-m-eye-slash')->color('gray')
                    ->visible(fn (Review $record) => static::moderator() && $record->status !== 'rejected')
                    ->schema([Textarea::make('note')->label('Reason (kept internally)')->required()->maxLength(255)
                        ->placeholder('Personal information / abusive language / not about this station')])
                    ->action(function (Review $record, array $data) {
                        app(ReviewService::class)->moderate($record, false, $data['note'], auth()->user());
                        Notification::make()->success()->title('Review hidden')->send();
                    }),
                Action::make('reply')->label(fn (Review $record) => $record->owner_reply ? 'Edit reply' : 'Reply')->icon('heroicon-m-chat-bubble-left')
                    ->visible(fn (Review $record) => auth()->user()->can('reviews.reply') && (Tenancy::isPlatformAdmin() || Tenancy::owns($record->company_id)))
                    ->fillForm(fn (Review $record) => ['reply' => $record->owner_reply])
                    ->modalDescription('Shown publicly under the review on the station page. The customer is emailed.')
                    ->schema([Textarea::make('reply')->label('Public reply')->rows(4)->maxLength(1000)])
                    ->action(function (Review $record, array $data) {
                        app(ReviewService::class)->reply($record, (string) ($data['reply'] ?? ''), auth()->user());
                        Notification::make()->success()->title('Reply saved')->send();
                    }),
            ])
            ->emptyStateIcon('heroicon-o-star')
            ->emptyStateHeading('No reviews yet')
            ->emptyStateDescription('Customers can rate a station after a completed charge.');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListReviews::route('/')];
    }
}
