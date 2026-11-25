<?php

namespace App\Models;

use App\Enums\Role;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, MustVerifyEmail, \Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication, \Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasRoles, Notifiable, \Laravel\Sanctum\HasApiTokens;

    protected $fillable = [
        'company_id',
        'name',
        'email',
        'phone',
        'city',
        'gstin',
        'state_code',
        'password',
    ];

    protected $hidden = [
        'two_factor_secret',
        'two_factor_recovery_codes',
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'notification_prefs' => 'array',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'blocked_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (User $user) => $user->uuid ??= (string) Str::uuid());
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->blocked_at === null
            && $this->hasAnyRole(Role::staff())
            && ($this->hasRole(Role::SuperAdmin->value) || ($this->company !== null && $this->company->canUseAdmin()));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Staff of the company that runs the marketplace (Trans EV), or a platform super admin. */
    public function isPlatformStaff(): bool
    {
        return $this->hasRole(Role::SuperAdmin->value) || ($this->company?->is_platform ?? false);
    }

    /** Stations assigned to a station manager. */
    public function stations(): BelongsToMany
    {
        return $this->belongsToMany(Station::class)->withTimestamps();
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class)->latest();
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function isStaff(): bool
    {
        return $this->hasAnyRole(Role::staff());
    }

    /** Public URL of the profile photo (null when none). */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->avatar_path) : null;
    }

    // Authenticator-app two-step login: the same secret is used by the website, the API and the admin panel (Filament).

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->two_factor_secret;
    }

    public function saveAppAuthenticationSecret(#[\SensitiveParameter] ?string $secret): void
    {
        $this->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => $secret ? now() : null])->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->two_factor_recovery_codes;
    }

    public function saveAppAuthenticationRecoveryCodes(#[\SensitiveParameter] ?array $codes): void
    {
        $this->forceFill(['two_factor_recovery_codes' => $codes])->save();
    }
}
