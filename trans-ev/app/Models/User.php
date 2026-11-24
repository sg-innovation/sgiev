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

class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'company_id',
        'name',
        'email',
        'phone',
        'gstin',
        'state_code',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'notification_prefs' => 'array',
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
}
