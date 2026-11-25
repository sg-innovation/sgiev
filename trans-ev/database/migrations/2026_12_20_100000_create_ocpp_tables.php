<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase F: OCPP central system (1.6J and 2.0.1).
 *  - chargers: connection state, credentials (Basic auth, hashed), what the charge point reported at boot
 *  - charging_sessions: the OCPP transaction id and id tag
 *  - charging_meter_values: voltage, current and signed meter data (OCMF) when the charger sends them
 *  - ocpp_messages: every frame in and out (append-only, for audit and support)
 *  - ocpp_commands: remote commands queued by staff / the system and the charger's answer
 *  - ocpp_faults: error codes reported in StatusNotification, linked to the work order opened for them
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chargers', function (Blueprint $table) {
            $table->string('ocpp_password', 255)->nullable()->after('ocpp_protocol')->comment('Hashed Basic-auth password (security profile 1/2)');
            $table->unsignedTinyInteger('ocpp_security_profile')->default(1)->after('ocpp_password');
            $table->boolean('ocpp_connected')->default(false)->after('ocpp_security_profile');
            $table->timestamp('ocpp_connected_at')->nullable()->after('ocpp_connected');
            $table->timestamp('ocpp_disconnected_at')->nullable()->after('ocpp_connected_at');
            $table->timestamp('ocpp_boot_at')->nullable()->after('ocpp_disconnected_at');
            $table->string('ocpp_vendor', 60)->nullable()->after('ocpp_boot_at');
            $table->string('ocpp_model', 60)->nullable()->after('ocpp_vendor');
            $table->string('ocpp_firmware_status', 40)->nullable()->after('ocpp_model');
        });

        Schema::table('charging_sessions', function (Blueprint $table) {
            $table->string('ocpp_transaction_id', 64)->nullable()->after('source');
            $table->string('id_tag', 64)->nullable()->after('ocpp_transaction_id');
            $table->index(['charger_id', 'ocpp_transaction_id']);
        });

        Schema::table('charging_meter_values', function (Blueprint $table) {
            $table->decimal('voltage_v', 8, 2)->nullable()->after('power_kw');
            $table->decimal('current_a', 8, 2)->nullable()->after('voltage_v');
            $table->text('signed_data')->nullable()->after('source')->comment('Signed meter value (e.g. OCMF) as sent by the charger');
        });

        Schema::create('ocpp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('charger_id')->nullable()->constrained()->nullOnDelete();
            $table->string('identity', 64)->index();
            $table->string('direction', 3)->comment('in | out');
            $table->unsignedTinyInteger('message_type')->comment('2 CALL, 3 CALLRESULT, 4 CALLERROR');
            $table->string('unique_id', 64);
            $table->string('action', 40)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['charger_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });

        Schema::create('ocpp_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('charger_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connector_id')->nullable()->constrained()->nullOnDelete();
            $table->string('command', 30)->comment('remote_start | remote_stop | reset | unlock | availability | trigger | update_firmware | get_diagnostics');
            $table->string('action', 40)->nullable()->comment('OCPP action name actually sent');
            $table->json('params')->nullable();
            $table->string('status', 12)->default('queued')->comment('queued | sent | accepted | rejected | failed | timeout');
            $table->string('unique_id', 64)->nullable()->index();
            $table->json('response')->nullable();
            $table->string('error', 255)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('charging_sessions')->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->index(['charger_id', 'status']);
        });

        Schema::create('ocpp_faults', function (Blueprint $table) {
            $table->id();
            $table->foreignId('charger_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connector_id')->nullable()->constrained()->nullOnDelete();
            $table->string('error_code', 40);
            $table->string('info', 255)->nullable();
            $table->string('vendor_error_code', 60)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('cleared_at')->nullable();
            $table->foreignId('work_order_id')->nullable()->constrained('charger_work_orders')->nullOnDelete();
            $table->timestamps();
            $table->index(['charger_id', 'cleared_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocpp_faults');
        Schema::dropIfExists('ocpp_commands');
        Schema::dropIfExists('ocpp_messages');
        Schema::table('charging_meter_values', fn (Blueprint $table) => $table->dropColumn(['voltage_v', 'current_a', 'signed_data']));
        Schema::table('charging_sessions', function (Blueprint $table) {
            $table->dropIndex(['charger_id', 'ocpp_transaction_id']);
            $table->dropColumn(['ocpp_transaction_id', 'id_tag']);
        });
        Schema::table('chargers', fn (Blueprint $table) => $table->dropColumn(['ocpp_password', 'ocpp_security_profile', 'ocpp_connected', 'ocpp_connected_at', 'ocpp_disconnected_at', 'ocpp_boot_at', 'ocpp_vendor', 'ocpp_model', 'ocpp_firmware_status']));
    }
};
