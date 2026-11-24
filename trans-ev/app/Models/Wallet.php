<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Prepaid balance an operator keeps with Trans EV (wallet billing mode). Change only through WalletService. */
class Wallet extends Model
{
    protected $fillable = ['company_id', 'low_balance_alert'];

    protected function casts(): array
    {
        return ['balance' => 'decimal:2', 'low_balance_alert' => 'decimal:2', 'low_balance_alerted_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class)->orderByDesc('id');
    }
}
