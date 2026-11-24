<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** C1: QR check-in. Booking passes are signed, short-lived tokens; each charger gets a rotatable sticker key. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('check_in_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('station_id')->constrained()->restrictOnDelete();
            $table->foreignId('charger_id')->constrained()->restrictOnDelete();
            $table->foreignId('connector_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 20); // staff_scan, charger_scan, manual
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_in_at');
            $table->integer('late_minutes')->default(0); // negative = early
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();
            $table->index(['station_id', 'checked_in_at']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('checked_in_at')->nullable()->after('confirmed_at');
        });

        Schema::table('chargers', function (Blueprint $table) {
            $table->string('qr_key', 40)->nullable()->unique()->after('code');
        });
        DB::table('chargers')->whereNull('qr_key')->orderBy('id')->pluck('id')
            ->each(fn ($id) => DB::table('chargers')->where('id', $id)->update(['qr_key' => Str::lower(Str::random(24))]));
    }

    public function down(): void
    {
        Schema::table('chargers', fn (Blueprint $table) => $table->dropColumn('qr_key'));
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn('checked_in_at'));
        Schema::dropIfExists('check_in_records');
    }
};
