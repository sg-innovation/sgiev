<?php

namespace App\Http\Resources\Api;

use App\Services\Auth\TwoFactorService;
use App\Services\Messaging\Channels;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'city' => $this->city,
            'avatar_url' => $this->avatarUrl(),
            'email_verified' => $this->email_verified_at !== null,
            'phone_verified' => $this->phone_verified_at !== null,
            'two_factor_enabled' => TwoFactorService::enabled($this->resource),
            'notifications' => Channels::prefs($this->resource),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
