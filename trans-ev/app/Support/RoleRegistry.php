<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * Custom staff roles created by platform super admins (Admin → Roles & permissions).
 * Custom roles are company-wide staff roles: they see all of their own company's data,
 * limited by the permissions ticked for the role.
 */
final class RoleRegistry
{
    /** @var array<string,string>|null name => label */
    private static ?array $custom = null;

    /** @return array<string,string> */
    public static function custom(): array
    {
        if (self::$custom !== null) {
            return self::$custom;
        }
        try {
            if (! Schema::hasColumn('roles', 'is_system')) {
                return self::$custom = [];
            }
            self::$custom = Role::query()->where('is_system', false)->where('guard_name', 'web')->orderBy('label')
                ->get(['name', 'label'])->mapWithKeys(fn (Role $r) => [$r->name => $r->label ?: $r->name])->all();
        } catch (Throwable) {
            self::$custom = []; // database not ready (fresh install, migrations pending)
        }

        return self::$custom;
    }

    public static function flush(): void
    {
        self::$custom = null;
    }
}
