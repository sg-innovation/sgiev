<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
            $table->string('phone', 20)->nullable()->after('email');
            $table->string('gstin', 15)->nullable()->after('phone');
            $table->string('state_code', 2)->nullable()->after('gstin');
            $table->timestamp('blocked_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['uuid', 'phone', 'gstin', 'state_code', 'blocked_at', 'last_login_at']);
        });
    }
};
