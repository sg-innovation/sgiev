<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Connectors (guns/sockets) as separate bookable units with their own status (SGI EV SRS §5.3, §7).
 * Admin availability (online/offline/maintenance) is kept separate from the live, OCPP-style status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chargers', function (Blueprint $table) {
            $table->string('serial_no', 60)->nullable()->after('oem');
            $table->string('ocpp_identity', 100)->nullable()->unique()->after('serial_no'); // chargeBoxId / station identity
            $table->string('ocpp_protocol', 10)->nullable()->after('ocpp_identity');        // 1.6J, 2.0.1 or null (manual)
            $table->timestamp('last_heartbeat_at')->nullable();
        });

        Schema::create('connectors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('charger_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('connector_no');                  // OCPP connectorId (1..n)
            $table->string('type', 20);                                   // ConnectorType
            $table->decimal('max_kw', 6, 2)->nullable();                  // null = charger power
            $table->string('status', 20)->default('online');              // admin: online / offline / maintenance
            $table->string('live_status', 20)->default('available');      // OCPP-style live status
            $table->timestamp('live_status_at')->nullable();
            $table->string('live_note')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['charger_id', 'connector_no']);
        });

        Schema::create('connector_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connector_id')->constrained()->restrictOnDelete();
            $table->string('field', 12);              // live or admin
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->string('source', 20);             // admin, ocpp, system, booking
            $table->string('note')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['connector_id', 'created_at']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('connector_id')->nullable()->after('charger_id')->constrained()->restrictOnDelete();
            $table->index(['connector_id', 'status', 'start_at', 'blocked_until']);
        });

        // Backfill: one connector per entry in the old JSON list, then attach existing bookings.
        DB::table('chargers')->orderBy('id')->get(['id', 'connectors', 'status'])->each(function ($c) {
            foreach (array_values(json_decode($c->connectors ?? '[]', true) ?: []) as $i => $type) {
                DB::table('connectors')->insert([
                    'charger_id' => $c->id, 'connector_no' => $i + 1, 'type' => $type, 'status' => $c->status,
                    'live_status' => 'available', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
        DB::statement('UPDATE bookings b JOIN connectors k ON k.charger_id = b.charger_id AND k.type = b.connector SET b.connector_id = k.id WHERE b.connector_id IS NULL');
        DB::statement('UPDATE bookings b JOIN connectors k ON k.charger_id = b.charger_id AND k.connector_no = 1 SET b.connector_id = k.id WHERE b.connector_id IS NULL');

        if (DB::getDriverName() === 'mysql') {
            foreach (['UPDATE', 'DELETE'] as $op) {
                $name = 'connector_status_history_no_'.strtolower($op);
                DB::unprepared("DROP TRIGGER IF EXISTS `{$name}`");
                DB::unprepared("CREATE TRIGGER `{$name}` BEFORE {$op} ON `connector_status_history` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'connector_status_history is append-only'");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS `connector_status_history_no_update`');
            DB::unprepared('DROP TRIGGER IF EXISTS `connector_status_history_no_delete`');
        }
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['connector_id', 'status', 'start_at', 'blocked_until']);
            $table->dropConstrainedForeignId('connector_id');
        });
        Schema::dropIfExists('connector_status_history');
        Schema::dropIfExists('connectors');
        Schema::table('chargers', fn (Blueprint $table) => $table->dropColumn(['serial_no', 'ocpp_identity', 'ocpp_protocol', 'last_heartbeat_at']));
    }
};
