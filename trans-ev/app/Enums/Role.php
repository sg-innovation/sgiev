<?php

namespace App\Enums;

/**
 * Roles (spatie/laravel-permission). See IMPLEMENTATION_PLAN.md section 11.1.
 * SuperAdmin is a platform role (Trans EV); every other staff role works inside one company.
 */
enum Role: string
{
    case SuperAdmin = 'super_admin';         // platform: all companies, onboarding, blog, platform settings
    case CompanyOwner = 'company_owner';     // company: everything for their own company incl. staff and payment setup
    case OperationsAdmin = 'operations_admin';
    case Finance = 'finance';
    case StationManager = 'station_manager';
    case Auditor = 'auditor';
    case Customer = 'customer';

    /** @return list<string> */
    public static function staff(): array
    {
        return [
            self::SuperAdmin->value,
            self::CompanyOwner->value,
            self::OperationsAdmin->value,
            self::Finance->value,
            self::StationManager->value,
            self::Auditor->value,
            ...array_keys(\App\Support\RoleRegistry::custom()),
        ];
    }

    /** Roles a company owner may give to their own staff. */
    public static function assignableByCompany(): array
    {
        return [
            self::CompanyOwner->value => 'Company owner',
            self::OperationsAdmin->value => 'Operations admin',
            self::Finance->value => 'Finance',
            self::StationManager->value => 'Station manager',
            self::Auditor->value => 'Auditor (read-only)',
        ] + \App\Support\RoleRegistry::custom();
    }

    /** Roles that see every station of their company (station managers see only assigned ones). */
    public static function companyWide(): array
    {
        return [self::SuperAdmin->value, self::CompanyOwner->value, self::OperationsAdmin->value, self::Finance->value, self::Auditor->value, ...array_keys(\App\Support\RoleRegistry::custom())];
    }
}
