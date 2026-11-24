<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D2: SLA tracking (pause while waiting on the requester, due-soon warning, breach and escalation) and contact-form
 * messages becoming tickets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('sla_paused_at')->nullable()->after('resolution_due_at');
            $table->unsignedInteger('sla_paused_minutes')->default(0)->after('sla_paused_at');
            $table->timestamp('sla_warned_at')->nullable()->after('sla_paused_minutes');
            $table->timestamp('first_response_breached_at')->nullable()->after('sla_warned_at');
            $table->timestamp('resolution_breached_at')->nullable()->after('first_response_breached_at');
            $table->unsignedTinyInteger('escalation_level')->default(0)->after('resolution_breached_at');
            $table->timestamp('escalated_at')->nullable()->after('escalation_level');
            $table->foreignId('contact_message_id')->nullable()->after('channel')->constrained('contact_messages')->nullOnDelete();
        });
        Schema::table('ticket_categories', function (Blueprint $table) {
            $table->string('contact_topic', 30)->nullable()->after('requires_booking'); // contact-form topic that maps to this category
        });
        Schema::table('contact_messages', function (Blueprint $table) {
            $table->foreignId('ticket_id')->nullable()->after('user_id')->constrained('tickets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contact_messages', fn (Blueprint $table) => $table->dropConstrainedForeignId('ticket_id'));
        Schema::table('ticket_categories', fn (Blueprint $table) => $table->dropColumn('contact_topic'));
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contact_message_id');
            $table->dropColumn(['sla_paused_at', 'sla_paused_minutes', 'sla_warned_at', 'first_response_breached_at', 'resolution_breached_at', 'escalation_level', 'escalated_at']);
        });
    }
};
