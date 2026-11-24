<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** C5: reminders, "running late" notices and automatic no-shows (with a record of each no-show and its refund). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->json('reminders_sent')->nullable()->after('checked_in_at');
            $table->timestamp('late_notice_at')->nullable()->after('reminders_sent');
            $table->timestamp('late_eta_at')->nullable()->after('late_notice_at');
            $table->string('late_note', 160)->nullable()->after('late_eta_at');
        });

        Schema::create('no_show_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('station_id')->constrained()->restrictOnDelete();
            $table->foreignId('connector_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 10); // auto, staff
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('marked_at');
            $table->unsignedSmallInteger('grace_minutes');
            $table->timestamp('late_notice_at')->nullable();
            $table->timestamp('late_eta_at')->nullable();
            $table->unsignedTinyInteger('refund_percent');
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('refund_amount', 12, 2)->default(0);
            $table->decimal('retained_amount', 12, 2)->default(0);
            $table->foreignId('refund_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['station_id', 'marked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('no_show_records');
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['reminders_sent', 'late_notice_at', 'late_eta_at', 'late_note']);
        });
    }
};
