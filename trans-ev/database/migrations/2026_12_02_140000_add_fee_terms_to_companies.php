<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Commission terms negotiated with an operator at onboarding (rates live in commission_percent / per_booking_fee). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('fee_terms_note', 500)->nullable()->after('per_booking_fee');
            $table->timestamp('fee_terms_agreed_at')->nullable()->after('fee_terms_note');
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['fee_terms_note', 'fee_terms_agreed_at']));
    }
};
