<?php

namespace App\Filament\Resources\Users\Pages;

use App\Enums\Role;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Support\Tenancy;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /** @param User $record */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $role = $this->form->getRawState()['role'] ?? null;
        if (! array_key_exists($role, UserResource::roleOptions())) {
            throw ValidationException::withMessages(['data.role' => 'You cannot assign this role.']);
        }
        if ($record->id === auth()->id() && $role !== $record->roles->first()?->name) {
            throw ValidationException::withMessages(['data.role' => 'You cannot change your own role.']);
        }

        if (Tenancy::companyId() !== null) {
            unset($data['company_id']); // company staff cannot move people between companies
        } elseif ($role === Role::SuperAdmin->value) {
            $data['company_id'] = null;
        }

        $record->update(collect($data)->except('stations')->all());
        $record->syncRoles([$role]);
        if ($role !== Role::StationManager->value) {
            $record->stations()->detach();
        }

        return $record;
    }
}
