<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cancellation & no-show policies (SGI EV B3). Resolution: station policy → operator policy → Trans EV default.
 * The policy in force is copied onto each booking when the slot is reserved, so a later change never affects
 * bookings already made. The previous platform-wide refund tiers (business rule) become the Trans EV default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cancellation_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();   // null = Trans EV default
            $table->foreignId('station_id')->nullable()->constrained()->restrictOnDelete();   // null = whole company
            $table->string('name', 120);
            $table->json('tiers');                     // [{min_minutes_before, refund_percent, fee, label}]
            $table->unsignedTinyInteger('no_show_refund_percent')->default(0);
            $table->string('customer_note', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'station_id', 'is_active']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('cancellation_policy_id')->nullable()->after('rule_set_id')->constrained()->nullOnDelete();
            $table->json('cancellation_policy')->nullable()->after('cancellation_policy_id');   // snapshot shown and agreed at checkout
            $table->timestamp('no_show_at')->nullable()->after('cancelled_at');
        });

        // The platform default starts from the current refund tiers.
        $raw = DB::table('system_settings')->where('key', 'rule.refund.tiers')->value('value');
        $tiers = $raw ? json_decode($raw, true) : null;
        $tiers = $tiers ?: [
            ['min_minutes_before' => 120, 'refund_percent' => 100, 'label' => 'Full refund (more than 2 hours before)'],
            ['min_minutes_before' => 30, 'refund_percent' => 50, 'label' => '50% refund (30 minutes to 2 hours before)'],
            ['min_minutes_before' => 0, 'refund_percent' => 0, 'label' => 'No refund (less than 30 minutes before)'],
        ];
        DB::table('cancellation_policies')->insert([
            'company_id' => null, 'station_id' => null, 'name' => 'Trans EV standard policy',
            'tiers' => json_encode(array_map(fn ($t) => $t + ['fee' => 0], $tiers)), 'no_show_refund_percent' => 0,
            'customer_note' => null, 'version' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('system_settings')->where('key', 'rule.refund.tiers')->delete();
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancellation_policy_id');
            $table->dropColumn(['cancellation_policy', 'no_show_at']);
        });
        Schema::dropIfExists('cancellation_policies');
    }
};
