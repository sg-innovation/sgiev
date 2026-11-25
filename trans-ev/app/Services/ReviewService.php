<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Charger;
use App\Models\Lookup;
use App\Models\Review;
use App\Models\Station;
use App\Models\User;
use App\Notifications\BookingNotice;
use App\Support\BusinessRules;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * D3 ratings & reviews.
 *  - Only the customer of a completed booking can review it, once, within N days (Business rules); they can edit it in that window.
 *  - Star-only reviews go live at once; written reviews wait for Trans EV moderation when Business rules say so.
 *  - The station's operator can reply publicly (reviews.reply); Trans EV moderates (reviews.moderate).
 *  - Station and charger averages are recalculated from published reviews.
 *  - A rating request goes out a while after charging finishes (scheduled).
 */
class ReviewService
{
    /** Why this user can't review this booking, or null when they can. */
    public function blocker(Booking $b, ?User $user): ?string
    {
        $days = BusinessRules::int('reviews.window_days');

        return match (true) {
            ! $user || $b->user_id !== $user->id => 'Only the customer who charged can review this booking.',
            $b->status !== BookingStatus::Completed => 'You can rate a booking after charging is complete.',
            $b->end_at->lt(now()->subDays($days)) => "Reviews can be written up to {$days} days after charging.",
            default => null,
        };
    }

    /** @return array<string, string> tag value => label (Admin → Lists & options → Review tags) */
    public static function tags(): array
    {
        return Lookup::options('review_tag');
    }

    public function submit(Booking $b, User $user, array $d): Review
    {
        if ($why = $this->blocker($b, $user)) {
            throw ValidationException::withMessages(['station_rating' => $why]);
        }
        $body = trim(strip_tags((string) ($d['body'] ?? '')));
        $tags = array_values(array_intersect((array) ($d['tags'] ?? []), array_keys(self::tags())));
        $pending = $body !== '' && BusinessRules::int('reviews.moderate_text') === 1;

        $review = DB::transaction(function () use ($b, $user, $d, $body, $tags, $pending) {
            $r = Review::query()->firstOrNew(['booking_id' => $b->id]);
            $r->fill([
                'user_id' => $user->id, 'company_id' => $b->company_id, 'station_id' => $b->station_id, 'charger_id' => $b->charger_id,
                'station_rating' => (int) $d['station_rating'], 'charger_rating' => filled($d['charger_rating'] ?? null) ? (int) $d['charger_rating'] : null,
                'tags' => $tags ?: null, 'body' => $body !== '' ? mb_substr($body, 0, 1000) : null,
                'status' => $pending ? 'pending' : 'published', 'moderation_note' => null, 'moderated_by' => null, 'moderated_at' => null,
            ])->save();
            activity()->performedOn($r)->causedBy($user)->log($r->wasRecentlyCreated ? 'review written' : 'review edited');

            return $r;
        });
        $this->recalculate($review);

        return $review;
    }

    public function moderate(Review $r, bool $publish, ?string $note, User $by): void
    {
        abort_unless(Tenancy::platformCan('reviews.moderate', $by), 403);
        $r->update(['status' => $publish ? 'published' : 'rejected', 'moderation_note' => $note ? mb_substr($note, 0, 255) : null, 'moderated_by' => $by->id, 'moderated_at' => now()]);
        $this->recalculate($r);
    }

    public function reply(Review $r, string $text, User $by): void
    {
        abort_unless($by->can('reviews.reply') && (Tenancy::isPlatformAdmin($by) || Tenancy::owns($r->company_id, $by)), 403);
        $text = trim(strip_tags($text));
        $r->update(['owner_reply' => $text !== '' ? mb_substr($text, 0, 1000) : null, 'owner_replied_by' => $text !== '' ? $by->id : null, 'owner_replied_at' => $text !== '' ? now() : null]);
        if ($text !== '' && $r->status === 'published') {
            try {
                $r->user?->notify(new BookingNotice('review.reply', $r->booking, ['reply' => mb_substr($text, 0, 300), 'operator' => (string) $r->station?->company?->trade_name]));
            } catch (\Throwable $e) {
                Log::warning('Review reply email failed', ['review' => $r->uuid, 'error' => $e->getMessage()]);
            }
        }
    }

    /** Recompute the published averages for the review's station and charger. */
    public function recalculate(Review $r): void
    {
        $s = Review::query()->where('station_id', $r->station_id)->where('status', 'published');
        Station::query()->whereKey($r->station_id)->update(['rating_avg' => (clone $s)->avg('station_rating'), 'rating_count' => (clone $s)->count()]);
        if ($r->charger_id) {
            $c = Review::query()->where('charger_id', $r->charger_id)->where('status', 'published')->whereNotNull('charger_rating');
            Charger::query()->withTrashed()->whereKey($r->charger_id)->update(['rating_avg' => (clone $c)->avg('charger_rating'), 'rating_count' => (clone $c)->count()]);
        }
    }

    /** Ask customers to rate a while after charging finished (scheduled). */
    public function sendRequests(): int
    {
        $after = BusinessRules::int('reviews.request_after_minutes');
        $n = 0;
        Booking::query()->where('status', BookingStatus::Completed)->whereNotNull('user_id')->whereNull('review_requested_at')
            ->where('end_at', '<=', now()->subMinutes($after))->where('end_at', '>', now()->subDays(2))
            ->whereDoesntHave('review')->with(['user', 'station', 'charger'])->limit(200)->get()
            ->each(function (Booking $b) use (&$n) {
                $b->forceFill(['review_requested_at' => now()])->save();
                if (! empty($b->customer_snapshot['demo'])) {
                    return;
                }
                try {
                    $b->user?->notify(new BookingNotice('review.request', $b, ['days' => (string) BusinessRules::int('reviews.window_days')]));
                    $n++;
                } catch (\Throwable $e) {
                    Log::warning('Review request failed', ['booking' => $b->code, 'error' => $e->getMessage()]);
                }
            });

        return $n;
    }

    /** Published reviews for a station page. */
    public function forStation(Station $station, int $limit = 10): array
    {
        $tags = self::tags();

        return Review::query()->where('station_id', $station->id)->where('status', 'published')->with(['user', 'charger'])->latest()->limit($limit)->get()
            ->map(fn (Review $r) => [
                'id' => $r->uuid, 'name' => $r->displayName(), 'rating' => $r->station_rating, 'chargerRating' => $r->charger_rating,
                'charger' => $r->charger?->code, 'tags' => collect($r->tags ?? [])->map(fn ($t) => $tags[$t] ?? null)->filter()->values(),
                'body' => $r->body, 'date' => $r->created_at->timezone(Station::TIMEZONE)->format('j M Y'),
                'reply' => $r->owner_reply ? ['text' => $r->owner_reply, 'by' => $station->company?->trade_name ?? 'Operator', 'date' => $r->owner_replied_at?->timezone(Station::TIMEZONE)->format('j M Y')] : null,
            ])->values()->all();
    }

    /** Count of each star rating (5 → 1) for the station's published reviews. */
    public function distribution(Station $station): array
    {
        $counts = Review::query()->where('station_id', $station->id)->where('status', 'published')->selectRaw('station_rating, count(*) c')->groupBy('station_rating')->pluck('c', 'station_rating');

        return collect([5, 4, 3, 2, 1])->map(fn ($s) => ['stars' => $s, 'count' => (int) ($counts[$s] ?? 0)])->all();
    }
}
