<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Support tickets for every user type (customers, partners, staff). Categories and priorities (with SLA
 * targets) are data managed in admin. Messages are append-only (audit trail of what was said).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_priorities', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40);
            $table->string('slug', 40)->unique();
            $table->string('color', 20)->default('gray');              // Filament colour name
            $table->unsignedInteger('first_response_minutes');          // SLA: first agent reply
            $table->unsignedInteger('resolution_minutes');              // SLA: resolved
            $table->boolean('customer_selectable')->default(true);
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort')->default(10);
            $table->timestamps();
        });

        Schema::create('ticket_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 80)->unique();
            $table->string('description', 255)->nullable();
            $table->string('audience', 10)->default('all');              // all | customer | partner
            $table->string('handled_by', 10)->default('platform');       // platform | operator (operator of the linked booking)
            $table->boolean('requires_booking')->default(false);
            $table->foreignId('default_priority_id')->nullable()->constrained('ticket_priorities')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(10);
            $table->timestamps();
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number', 20)->nullable()->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();             // company that resolves it
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('requester_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('category_id')->constrained('ticket_categories')->restrictOnDelete();
            $table->foreignId('priority_id')->constrained('ticket_priorities')->restrictOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('station_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject', 160);
            $table->string('status', 24)->default('open')->index();     // open | in_progress | waiting_on_customer | resolved | closed
            $table->string('channel', 10)->default('web');
            $table->timestamp('first_response_due_at')->nullable();
            $table->timestamp('resolution_due_at')->nullable();
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->string('last_reply_by', 10)->nullable();              // requester | agent
            $table->unsignedTinyInteger('rating')->nullable();           // 1–5 after resolution
            $table->timestamps();
            $table->index(['company_id', 'status']);
            $table->index(['assigned_to', 'status']);
            $table->index(['requester_id', 'status']);
        });

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->boolean('is_internal')->default(false);             // staff-only note
            $table->string('event', 40)->nullable();                    // system events: assigned, status changed …
            $table->json('attachments')->nullable();                    // [{path, name, mime, size, sha256}] on the private disk
            $table->timestamp('created_at')->useCurrent();
            $table->index(['ticket_id', 'id']);
        });

        if (DB::getDriverName() === 'mysql') {
            foreach (['UPDATE', 'DELETE'] as $op) {
                $name = 'ticket_messages_no_'.strtolower($op);
                DB::unprepared("DROP TRIGGER IF EXISTS `{$name}`");
                DB::unprepared("CREATE TRIGGER `{$name}` BEFORE {$op} ON `ticket_messages` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ticket_messages is append-only'");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS `ticket_messages_no_update`');
            DB::unprepared('DROP TRIGGER IF EXISTS `ticket_messages_no_delete`');
        }
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('ticket_categories');
        Schema::dropIfExists('ticket_priorities');
    }
};
