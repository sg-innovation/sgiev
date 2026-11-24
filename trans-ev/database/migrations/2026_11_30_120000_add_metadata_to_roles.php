<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Display name, description and a system flag for roles, so super admins can add custom staff roles. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('label', 80)->nullable()->after('name');
            $table->string('description', 255)->nullable()->after('label');
            $table->boolean('is_system')->default(false)->after('description');
        });

        DB::table('roles')->whereIn('name', ['super_admin', 'company_owner', 'operations_admin', 'finance', 'station_manager', 'auditor', 'customer'])
            ->update(['is_system' => true]);
    }

    public function down(): void
    {
        Schema::table('roles', fn (Blueprint $table) => $table->dropColumn(['label', 'description', 'is_system']));
    }
};
