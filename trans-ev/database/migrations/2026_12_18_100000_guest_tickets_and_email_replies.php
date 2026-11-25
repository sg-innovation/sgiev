<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support as one threaded system: every contact-form query becomes a ticket. Guests (no account) are the requester
 * by name and email and follow the thread through a signed private link; replies can also arrive by email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('requester_id')->nullable()->change();
            $table->string('guest_name', 120)->nullable()->after('requester_company_id');
            $table->string('guest_email', 255)->nullable()->after('guest_name');
            $table->string('guest_phone', 15)->nullable()->after('guest_email');
            $table->unsignedSmallInteger('guest_link_version')->default(1)->after('guest_phone')->comment('Bump to cancel old private links');
            $table->index('guest_email');
        });
        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->string('via', 10)->default('web')->after('event')->comment('web | guest | email');
            $table->string('author_name', 120)->nullable()->after('via')->comment('For guest and email messages without an account');
            $table->string('author_email', 255)->nullable()->after('author_name');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_messages', fn (Blueprint $table) => $table->dropColumn(['via', 'author_name', 'author_email']));
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropIndex(['guest_email']);
            $table->dropColumn(['guest_name', 'guest_email', 'guest_phone', 'guest_link_version']);
        });
    }
};
