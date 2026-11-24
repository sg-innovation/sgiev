<?php

namespace App\Filament\Pages;

use App\Enums\Role as RoleEnum;
use App\Support\RoleRegistry;
use App\Support\Tenancy;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use UnitEnum;

/**
 * Roles & permissions. Everyone with staff-management or audit rights can see the matrix; only platform
 * super admins can create custom roles and change which permissions a role has. Every change is audit-logged.
 * Permissions themselves are defined in code (each one is enforced by a policy), so admins combine them into roles.
 */
class RolesMatrix extends Page
{
    protected string $view = 'filament.pages.roles-matrix';

    protected static ?string $slug = 'roles-matrix';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Roles & permissions';

    protected static ?string $title = 'Roles & permissions';

    protected static ?int $navigationSort = 3;

    /** Roles whose permissions can never be edited here. */
    private const LOCKED = [RoleEnum::SuperAdmin->value, RoleEnum::Customer->value];

    /** Icons for permission groups (presentation only; labels come from the database). */
    private const ICONS = [
        'stations' => 'heroicon-o-bolt', 'tariffs' => 'heroicon-o-currency-rupee', 'rules' => 'heroicon-o-document-check',
        'bookings' => 'heroicon-o-calendar-days', 'payments' => 'heroicon-o-credit-card', 'coupons' => 'heroicon-o-ticket',
        'invoices' => 'heroicon-o-document-text', 'cancellation' => 'heroicon-o-arrow-uturn-left', 'wallet' => 'heroicon-o-wallet', 'tickets' => 'heroicon-o-lifebuoy', 'company' => 'heroicon-o-building-office-2',
        'users' => 'heroicon-o-users', 'support' => 'heroicon-o-inbox', 'blog' => 'heroicon-o-newspaper', 'audit' => 'heroicon-o-shield-check',
        'platform' => 'heroicon-o-globe-asia-australia', 'partners' => 'heroicon-o-clipboard-document-check', 'plans' => 'heroicon-o-sparkles',
        'integrations' => 'heroicon-o-key', 'roles' => 'heroicon-o-identification', 'customers' => 'heroicon-o-user-group',
        'settings' => 'heroicon-o-adjustments-horizontal', 'content' => 'heroicon-o-document-duplicate', 'lookups' => 'heroicon-o-list-bullet', 'platform_fees' => 'heroicon-o-banknotes',
    ];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && (Tenancy::platformCan('roles.manage', $user) || $user->can('users.manage') || $user->can('audit.view'));
    }

    public function canManage(): bool
    {
        return Tenancy::platformCan('roles.manage');
    }

    /** @return list<array{name:string,label:string,description:?string,system:bool,locked:bool,users:int,perms:list<string>}> */
    public function getRoles(): array
    {
        $counts = DB::table('model_has_roles')->select('role_id', DB::raw('count(*) as n'))->groupBy('role_id')->pluck('n', 'role_id');
        $order = array_flip([...array_diff(RoleEnum::staff(), array_keys(RoleRegistry::custom())), ...array_keys(RoleRegistry::custom())]);

        return Role::query()->where('guard_name', 'web')->where('name', '!=', RoleEnum::Customer->value)->with('permissions')->get()
            ->sortBy(fn (Role $r) => $order[$r->name] ?? 999)
            ->map(fn (Role $r) => [
                'name' => $r->name,
                'label' => $r->label ?: Str::headline($r->name),
                'description' => $r->description,
                'system' => (bool) $r->is_system,
                'locked' => in_array($r->name, self::LOCKED, true),
                'users' => (int) ($counts[$r->id] ?? 0),
                'perms' => $r->permissions->pluck('name')->all(),
            ])->values()->all();
    }

    /** @return array<string, array{label:string, icon:string, platform:bool, perms: array<string,string>}> */
    public function getAreas(): array
    {
        $areas = [];
        $rows = Permission::query()->where('guard_name', 'web')->orderBy('sort')->orderBy('name')->get();
        foreach ($rows as $p) {
            $key = $p->group ?: Str::before($p->name, '.');
            $areas[$key] ??= [
                'label' => ($p->group_label ?: Str::headline($key)).($p->platform_only ? ' (Trans EV only)' : ''),
                'icon' => self::ICONS[$key] ?? 'heroicon-o-key',
                'platform' => (bool) $p->platform_only,
                'perms' => [],
            ];
            $areas[$key]['perms'][$p->name] = $p->label ?: Str::headline(Str::after($p->name, '.'));
        }

        return $areas;
    }

    /** Permission editor: one checkbox group per area. */
    private function permissionFields(): array
    {
        return collect($this->getAreas())->map(fn (array $area, string $key) => Section::make($area['label'])->icon($area['icon'])->compact()->schema([
            CheckboxList::make("perm_{$key}")->hiddenLabel()->options($area['perms'])->columns(2)->bulkToggleable(),
        ]))->values()->all();
    }

    private function collectPermissions(array $data): array
    {
        $valid = Permission::query()->where('guard_name', 'web')->pluck('name')->all();

        return collect($data)->filter(fn ($v, $k) => str_starts_with((string) $k, 'perm_'))->flatten()
            ->filter(fn ($p) => in_array($p, $valid, true))->unique()->values()->all();
    }

    private function fillPermissions(array $perms): array
    {
        return collect($this->getAreas())->mapWithKeys(fn (array $area, string $key) => [
            "perm_{$key}" => array_values(array_intersect(array_keys($area['perms']), $perms)),
        ])->all();
    }

    private function afterChange(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        RoleRegistry::flush();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createRole')->label('New role')->icon('heroicon-m-plus')
                ->visible(fn () => $this->canManage())
                ->modalHeading('Create a staff role')
                ->modalDescription('Custom roles work inside each company and see all of that company’s data, limited to the permissions you tick.')
                ->modalWidth('4xl')
                ->schema(fn () => [
                    TextInput::make('label')->label('Role name')->required()->maxLength(60)->notRegex('/[<>]/')->placeholder('e.g. Site supervisor'),
                    Textarea::make('description')->rows(2)->maxLength(255)->placeholder('What is this role for?'),
                    Select::make('copy_from')->label('Start from')->placeholder('No permissions')->live()
                        ->options(fn () => collect($this->getRoles())->reject(fn ($r) => $r['name'] === RoleEnum::SuperAdmin->value)->pluck('label', 'name')->all())
                        ->afterStateUpdated(function ($state, $set) {
                            $perms = $state ? Role::findByName($state, 'web')->permissions->pluck('name')->all() : [];
                            foreach ($this->fillPermissions($perms) as $k => $v) {
                                $set($k, $v);
                            }
                        }),
                    ...$this->permissionFields(),
                ])
                ->action(function (array $data) {
                    abort_unless($this->canManage(), 403);
                    $name = Str::slug($data['label'], '_');
                    if ($name === '' || Role::query()->where('name', $name)->exists() || in_array($name, array_column(RoleEnum::cases(), 'value'), true)) {
                        throw ValidationException::withMessages(['mountedActions.0.data.label' => 'A role with this name already exists.']);
                    }
                    $role = Role::create(['name' => $name, 'guard_name' => 'web']);
                    $role->forceFill(['label' => trim($data['label']), 'description' => $data['description'] ?? null, 'is_system' => false])->save();
                    $perms = $this->collectPermissions($data);
                    $role->syncPermissions($perms);
                    $this->afterChange();
                    activity()->causedBy(auth()->user())->withProperties(['role' => $name, 'permissions' => $perms])->log('role created');
                    Notification::make()->success()->title("Role “{$role->label}” created")->body('You can now give it to staff in Settings → Staff.')->send();
                }),
        ];
    }

    public function editRoleAction(): Action
    {
        return Action::make('editRole')->label('Edit')->icon('heroicon-m-pencil-square')->color('gray')->size('sm')->link()
            ->visible(fn () => $this->canManage())
            ->modalHeading(fn (array $arguments) => 'Edit role: '.(Role::findByName($arguments['role'], 'web')->label ?? $arguments['role']))
            ->modalDescription(fn (array $arguments) => Role::findByName($arguments['role'], 'web')->is_system
                ? 'Built-in role: you can change its permissions but not its name. Changes apply immediately to everyone with this role in every company.'
                : 'Changes apply immediately to everyone with this role in every company.')
            ->modalWidth('4xl')
            ->fillForm(function (array $arguments) {
                $role = Role::findByName($arguments['role'], 'web');

                return ['label' => $role->label, 'description' => $role->description] + $this->fillPermissions($role->permissions->pluck('name')->all());
            })
            ->schema(fn (array $arguments) => [
                TextInput::make('label')->label('Role name')->required()->maxLength(60)->notRegex('/[<>]/')
                    ->disabled(fn () => (bool) Role::findByName($arguments['role'], 'web')->is_system),
                Textarea::make('description')->rows(2)->maxLength(255),
                ...$this->permissionFields(),
            ])
            ->action(function (array $data, array $arguments) {
                abort_unless($this->canManage(), 403);
                abort_if(in_array($arguments['role'], self::LOCKED, true), 403);
                $role = Role::findByName($arguments['role'], 'web');
                $before = $role->permissions->pluck('name')->all();
                $perms = $this->collectPermissions($data);

                DB::transaction(function () use ($role, $data, $perms) {
                    $role->forceFill(array_filter([
                        'label' => $role->is_system ? null : trim((string) ($data['label'] ?? $role->label)),
                        'description' => $data['description'] ?? null,
                    ], fn ($v, $k) => $k === 'description' || $v !== null, ARRAY_FILTER_USE_BOTH))->save();
                    $role->syncPermissions($perms);
                });
                $this->afterChange();
                activity()->causedBy(auth()->user())->withProperties([
                    'role' => $role->name,
                    'granted' => array_values(array_diff($perms, $before)),
                    'revoked' => array_values(array_diff($before, $perms)),
                ])->log('role permissions changed');
                Notification::make()->success()->title('Role updated')->send();
            });
    }

    public function deleteRoleAction(): Action
    {
        return Action::make('deleteRole')->label('Delete')->icon('heroicon-m-trash')->color('danger')->size('sm')->link()
            ->visible(fn () => $this->canManage())
            ->requiresConfirmation()
            ->modalDescription('Only custom roles with no staff can be deleted.')
            ->action(function (array $arguments) {
                abort_unless($this->canManage(), 403);
                $role = Role::findByName($arguments['role'], 'web');
                if ($role->is_system) {
                    Notification::make()->danger()->title('Built-in roles cannot be deleted')->send();

                    return;
                }
                if (DB::table('model_has_roles')->where('role_id', $role->id)->exists()) {
                    Notification::make()->danger()->title('Move its staff to another role first')->send();

                    return;
                }
                activity()->causedBy(auth()->user())->withProperties(['role' => $role->name, 'permissions' => $role->permissions->pluck('name')->all()])->log('role deleted');
                $role->delete();
                $this->afterChange();
                Notification::make()->success()->title('Role deleted')->send();
            });
    }
}
