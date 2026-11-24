<?php

namespace Database\Seeders;

use App\Enums\Role as RoleEnum;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Role → permission matrix from IMPLEMENTATION_PLAN.md section 11.1. */
class RolesAndPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'stations.view', 'stations.manage', 'stations.status', 'stations.hours',
        'tariffs.view', 'tariffs.manage',
        'rules.view', 'rules.manage',
        'company.view', 'company.manage',
        'users.view', 'users.manage',
        'bookings.view', 'bookings.manage',
        'blog.view', 'blog.manage', 'blog.publish',
        'support.view', 'support.manage',
        'payments.view', 'payments.refund',
        'audit.view',
        'coupons.view', 'coupons.manage',
        'invoices.export', 'cancellation.manage', 'wallet.view', 'wallet.recharge',
        'tickets.view', 'tickets.reply', 'tickets.assign', 'tickets.manage',
        'reconciliation.view', 'sessions.manage', 'walkins.manage',
        // Platform-only (effective for Trans EV staff only; see PLATFORM_ONLY)
        'platform.all_companies', 'partners.review', 'plans.manage', 'integrations.manage', 'roles.manage', 'customers.block',
        'settings.manage', 'content.manage', 'lookups.manage', 'platform_fees.manage', 'reconciliation.manage', 'notifications.log',
    ];

    /** Initial labels and grouping stored with each permission (editable data, shown on Roles & permissions). */
    public const GROUPS = [
        'stations' => 'Stations', 'tariffs' => 'Tariffs & pricing', 'rules' => 'Station rules', 'bookings' => 'Bookings & customers',
        'payments' => 'Payments & refunds', 'coupons' => 'Coupons & offers', 'invoices' => 'Invoices & GST', 'cancellation' => 'Cancellation policies', 'wallet' => 'Trans EV wallet', 'tickets' => 'Support tickets', 'sessions' => 'Charging sessions', 'notifications' => 'Notifications', 'walkins' => 'Walk-ins', 'reconciliation' => 'Reconciliation',
        'company' => 'Company profile', 'users' => 'Staff', 'support' => 'Contact-form queries', 'blog' => 'Blog', 'audit' => 'Audit log',
        'platform' => 'Platform access', 'partners' => 'Partner review', 'plans' => 'Plans', 'integrations' => 'Integrations',
        'roles' => 'Roles', 'customers' => 'Customers', 'settings' => 'Business rules', 'content' => 'Website pages', 'lookups' => 'Lists & options', 'platform_fees' => 'Platform fees',
    ];

    public const LABELS = [
        'stations.view' => 'See stations', 'stations.manage' => 'Add and edit stations, chargers and photos',
        'stations.status' => 'Set online / offline / maintenance', 'stations.hours' => 'Edit opening hours and holidays',
        'tariffs.view' => 'See prices', 'tariffs.manage' => 'Change prices and GST rates',
        'rules.view' => 'See station rules', 'rules.manage' => 'Write and publish station rules',
        'bookings.view' => 'See bookings and customers', 'bookings.manage' => 'Cancel bookings and manage check-ins',
        'payments.view' => 'See payments, receipts and invoices', 'payments.refund' => 'Issue refunds',
        'coupons.view' => 'See coupons', 'coupons.manage' => 'Create and edit coupons',
        'invoices.export' => 'Download invoice ZIPs and GSTR-1 exports',
        'cancellation.manage' => 'Set cancellation and no-show refund policies',
        'wallet.view' => 'See the Trans EV wallet balance and history', 'wallet.recharge' => 'Recharge the Trans EV wallet',
        'tickets.view' => 'See support tickets', 'tickets.reply' => 'Reply, add internal notes and resolve tickets',
        'tickets.assign' => 'Assign tickets to agents', 'tickets.manage' => 'Manage ticket categories; change or reopen any ticket',
        'company.view' => 'See company profile', 'company.manage' => 'Edit company profile, bank and payment keys',
        'users.view' => 'See staff', 'users.manage' => 'Invite, block and change staff roles',
        'support.view' => 'Read contact-form queries', 'support.manage' => 'Reply to and close contact-form queries',
        'blog.view' => 'See blog posts', 'blog.manage' => 'Write and edit blog posts', 'blog.publish' => 'Publish blog posts',
        'audit.view' => 'Read the audit log',
        'reconciliation.view' => 'See reconciliation exceptions for their company',
        'sessions.manage' => 'Start and stop charging sessions (Staff Console)',
        'walkins.manage' => 'Start walk-in charging and take payment at the station (cash, UPI, payment link)',
        'reconciliation.manage' => 'Run reconciliation, fix, assign and resolve exceptions',
        'notifications.log' => 'See the notification delivery log (emails, SMS, WhatsApp, push)',
        'platform.all_companies' => 'See every company’s data (not just Trans EV)',
        'partners.review' => 'Approve partners, verify KYC, approve stations, suspend companies',
        'plans.manage' => 'Edit plans, features and company plans', 'integrations.manage' => 'Manage email, SMS, payment and other keys',
        'roles.manage' => 'Create roles and change permissions', 'customers.block' => 'Block and unblock customers',
        'settings.manage' => 'Change business rules (booking limits, refund tiers…)', 'content.manage' => 'Edit website pages (terms, privacy, refund…)',
        'lookups.manage' => 'Edit lists and options (KYC documents, amenities, EV models…)',
        'platform_fees.manage' => 'Generate, issue and record payment of platform-fee invoices to operators',
    ];

    /** Only take effect for Trans EV (platform) staff: a partner company can never use these. */
    public const PLATFORM_ONLY = ['platform.all_companies', 'partners.review', 'plans.manage', 'integrations.manage', 'roles.manage', 'customers.block', 'settings.manage', 'content.manage', 'lookups.manage', 'platform_fees.manage', 'reconciliation.manage', 'notifications.log',
        'blog.view', 'blog.manage', 'blog.publish', 'support.view', 'support.manage'];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $hasMeta = \Illuminate\Support\Facades\Schema::hasColumn('permissions', 'label');
        $groups = array_keys(self::GROUPS);
        foreach (self::PERMISSIONS as $i => $name) {
            $permission = Permission::findOrCreate($name, 'web');
            if ($hasMeta) {
                $group = strtok($name, '.');
                // Only fill blanks, so labels edited later in the database are kept.
                $permission->forceFill(array_filter([
                    'group' => $permission->group ?: $group,
                    'group_label' => $permission->group_label ?: (self::GROUPS[$group] ?? null),
                    'label' => $permission->label ?: (self::LABELS[$name] ?? null),
                ]) + [
                    'platform_only' => in_array($name, self::PLATFORM_ONLY, true),
                    'sort' => (array_search($group, $groups, true) ?: 0) * 10 + $i % 10,
                ])->save();
            }
        }

        $matrix = [
            RoleEnum::SuperAdmin->value => self::PERMISSIONS,
            // Blog and support permissions only take effect for staff of the platform company (see policies).
            RoleEnum::CompanyOwner->value => [
                'stations.view', 'stations.manage', 'stations.status', 'stations.hours',
                'tariffs.view', 'tariffs.manage', 'rules.view', 'rules.manage',
                'company.view', 'company.manage', 'users.view', 'users.manage',
                'bookings.view', 'bookings.manage', 'blog.view', 'blog.manage', 'blog.publish',
                'support.view', 'support.manage', 'audit.view', 'payments.view', 'payments.refund',
                'coupons.view', 'coupons.manage', 'invoices.export', 'cancellation.manage', 'wallet.view', 'wallet.recharge', 'tickets.view', 'tickets.reply', 'tickets.assign', 'tickets.manage', 'reconciliation.view', 'sessions.manage', 'walkins.manage',
            ],
            RoleEnum::OperationsAdmin->value => [
                'stations.view', 'stations.manage', 'stations.status', 'stations.hours',
                'tariffs.view', 'rules.view', 'rules.manage', 'company.view',
                'bookings.view', 'bookings.manage', 'blog.view', 'blog.manage', 'blog.publish',
                'support.view', 'support.manage', 'payments.view', 'coupons.view', 'coupons.manage', 'cancellation.manage',
                'tickets.view', 'tickets.reply', 'tickets.assign', 'sessions.manage', 'walkins.manage',
            ],
            RoleEnum::Finance->value => ['stations.view', 'tariffs.view', 'tariffs.manage', 'company.view', 'bookings.view', 'support.view', 'audit.view', 'payments.view', 'payments.refund', 'coupons.view', 'invoices.export', 'wallet.view', 'wallet.recharge', 'tickets.view', 'tickets.reply', 'reconciliation.view'],
            RoleEnum::StationManager->value => ['stations.view', 'stations.status', 'stations.hours', 'tariffs.view', 'rules.view', 'bookings.view', 'bookings.manage', 'tickets.view', 'tickets.reply', 'sessions.manage', 'walkins.manage'],
            RoleEnum::Auditor->value => ['stations.view', 'tariffs.view', 'rules.view', 'company.view', 'users.view', 'bookings.view', 'blog.view', 'support.view', 'audit.view', 'payments.view', 'coupons.view', 'tickets.view', 'reconciliation.view'],
            RoleEnum::Customer->value => [],
        ];

        $about = [
            RoleEnum::SuperAdmin->value => ['Platform super admin', 'Runs the whole Trans EV platform: every company, plans, integrations and settings.'],
            RoleEnum::CompanyOwner->value => ['Company owner', 'Everything for their own company, including staff, payments setup and refunds.'],
            RoleEnum::OperationsAdmin->value => ['Operations admin', 'Day-to-day station operations, rules, bookings and customer support.'],
            RoleEnum::Finance->value => ['Finance', 'Payments, refunds, tariffs and the audit log.'],
            RoleEnum::StationManager->value => ['Station manager', 'Only their assigned stations: status, hours and bookings.'],
            RoleEnum::Auditor->value => ['Auditor', 'Read-only access for financial and security audits.'],
            RoleEnum::Customer->value => ['Customer', 'Books and pays for charging on the website. No admin access.'],
        ];

        foreach ($matrix as $role => $permissions) {
            $model = Role::findOrCreate($role, 'web');
            $model->syncPermissions($permissions);
            if (\Illuminate\Support\Facades\Schema::hasColumn('roles', 'is_system')) {
                $model->forceFill(['label' => $about[$role][0], 'description' => $about[$role][1], 'is_system' => true])->save();
            }
        }
        \App\Support\RoleRegistry::flush();
    }
}
