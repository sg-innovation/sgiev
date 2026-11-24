<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Enums\Role;
use App\Filament\Resources\Companies\CompanyResource;
use App\Models\Company;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/** Onboard a new charging company together with its owner account. */
class CreateCompany extends CreateRecord
{
    protected static string $resource = CompanyResource::class;

    protected static ?string $title = 'Onboard a company';

    protected function handleRecordCreation(array $data): Model
    {
        $owner = $this->form->getRawState();

        $company = DB::transaction(function () use ($data, $owner) {
            /** @var Company $company */
            $company = Company::create($data);
            $company->forceFill(['status' => 'active', 'onboarded_at' => now()])->save();

            $user = User::create([
                'company_id' => $company->id,
                'name' => $owner['owner_name'],
                'email' => strtolower($owner['owner_email']),
                'password' => Str::password(32), // unusable until they set their own via the emailed link
            ]);
            $user->assignRole(Role::CompanyOwner->value);

            return $company;
        });

        $status = Password::sendResetLink(['email' => strtolower($owner['owner_email'])]);
        Notification::make()
            ->title($status === Password::RESET_LINK_SENT ? 'Company onboarded. Invitation sent to the owner.' : 'Company onboarded, but the invitation email could not be sent.')
            ->color($status === Password::RESET_LINK_SENT ? 'success' : 'warning')
            ->send();

        return $company;
    }

    protected function getCreatedNotification(): ?Notification
    {
        return null;
    }
}
