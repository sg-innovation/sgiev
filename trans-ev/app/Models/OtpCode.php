<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A one-time login code sent by SMS (stored hashed). */
class OtpCode extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'consumed_at' => 'datetime'];
    }
}
