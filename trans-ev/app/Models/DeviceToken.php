<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A mobile app install that can receive push notifications (registered by the apps). */
class DeviceToken extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
