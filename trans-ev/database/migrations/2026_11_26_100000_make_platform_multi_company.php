<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS marketplace: many charging companies (operators) on one platform.
 * Each company owns its stations, rules, bookings, GSTIN and its own SabPaisa merchant account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('slug', 80)->nullable()->unique()->after('trade_name');
            $table->boolean('is_platform')->default(false)->after('slug'); // the company that runs the marketplace
            $table->string('status', 20)->default('active')->after('is_platform'); // active, suspended
            $table->string('logo_path')->nullable();
            $table->string('support_phone', 20)->nullable();
            $table->string('support_email')->nullable();

            // Each operator is paid directly into its own SabPaisa merchant account.
            $table->string('sabpaisa_env', 12)->default('staging');
            $table->string('sabpaisa_merchant_id', 50)->nullable();
            $table->text('sabpaisa_api_key')->nullable();        // encrypted
            $table->text('sabpaisa_secret_key')->nullable();     // encrypted
            $table->text('sabpaisa_webhook_secret')->nullable(); // encrypted
            $table->timestamp('onboarded_at')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            // Staff belong to one company. Customers and platform admins have no company.
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->index(['company_id', 'start_at']);
        });

        Schema::table('rule_sets', function (Blueprint $table) {
            // null company + null station = platform-wide default rules
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->index('station_id'); // keeps the station FK indexed once the unique key is dropped
            $table->dropUnique(['station_id', 'version']);
            $table->index(['company_id', 'station_id', 'version']);
        });

        // Backfill existing rows.
        DB::table('companies')->orderBy('id')->limit(1)->update(['is_platform' => true]);
        DB::table('companies')->whereNull('slug')->orderBy('id')->get(['id', 'trade_name'])->each(fn ($c) => DB::table('companies')->where('id', $c->id)->update([
            'slug' => \Illuminate\Support\Str::slug($c->trade_name).'-'.$c->id,
            'onboarded_at' => now(),
        ]));
        DB::statement('UPDATE bookings b JOIN stations s ON s.id = b.station_id SET b.company_id = s.company_id WHERE b.company_id IS NULL');
        $platform = DB::table('companies')->where('is_platform', true)->value('id');
        if ($platform) {
            // Existing staff accounts belonged to the original (platform) company.
            DB::table('users')->whereIn('id', DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->whereIn('roles.name', ['operations_admin', 'finance', 'station_manager', 'auditor'])->pluck('model_has_roles.model_id'))
                ->update(['company_id' => $platform]);
        }
    }

    public function down(): void
    {
        Schema::table('rule_sets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropIndex(['company_id', 'station_id', 'version']);
            $table->unique(['station_id', 'version']);
            $table->dropIndex(['station_id']);
        });
        Schema::table('bookings', fn (Blueprint $table) => $table->dropConstrainedForeignId('company_id'));
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('company_id'));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn([
            'slug', 'is_platform', 'status', 'logo_path', 'support_phone', 'support_email',
            'sabpaisa_env', 'sabpaisa_merchant_id', 'sabpaisa_api_key', 'sabpaisa_secret_key', 'sabpaisa_webhook_secret', 'onboarded_at',
        ]));
    }
};
