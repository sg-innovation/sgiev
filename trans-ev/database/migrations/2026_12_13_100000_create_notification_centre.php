<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D1 notification centre: every message sent on any channel is logged; customers choose SMS / WhatsApp / push;
 * mobile app devices register push tokens (used by the apps in Phase E).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('template', 80)->index();
            $table->string('channel', 10)->index();      // mail, sms, whatsapp, push
            $table->string('recipient', 255)->nullable(); // email, phone or device count
            $table->string('status', 10)->index();       // sent, simulated, skipped, failed
            $table->string('provider', 20)->nullable();   // smtp, msg91, meta, fcm, log
            $table->string('provider_message_id', 120)->nullable();
            $table->string('preview', 255)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 255)->unique();
            $table->string('platform', 10); // android, ios, web
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_prefs')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('notification_prefs'));
        Schema::dropIfExists('device_tokens');
        Schema::dropIfExists('notification_deliveries');
    }
};
