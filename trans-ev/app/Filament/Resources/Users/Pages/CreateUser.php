<?php

namespace App\Filament\Resources\Users\Pages;

use App\Enums\Role;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Support\Tenancy;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** New staff get an emailed link to set their own password (admins never choose passwords for others). */
class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $role = $this->form->getRawState()['role'] ?? null;
        if (! array_key_exists($role, UserResource::roleOptions())) {
            throw ValidationException::withMessages(['data.role' => 'You cannot assign this role.']);
        }

        $tenant = Tenancy::companyId();
        $data['company_id'] = $role === Role::SuperAdmin->value ? null : ($tenant ?? $data['company_id'] ?? null);
        $data['password'] = Str::password(32);

        /** @var User $user */
        $user = User::create(collect($data)->except('stations')->all());
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->syncRoles([$role]);

        $sent = Password::sendResetLink(['email' => $user->email]) === Password::RESET_LINK_SENT;
        Notification::make()->title($sent ? 'Invitation emailed' : 'Account created, but the invitation email could not be sent')
            ->color($sent ? 'success' : 'warning')->send();

        return $user;
    }
}
