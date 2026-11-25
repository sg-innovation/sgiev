<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E (API) and account security:
 *  - users: authenticator-app 2FA (secret and hashed recovery codes, encrypted), shared by the website and admin
 *  - otp_codes: one-time codes sent by SMS for mobile login (hashed, short-lived, limited attempts)
 *  - personal_access_tokens: Sanctum API tokens for the mobile apps
 *  - api_developers: people given access to the developer portal with a 6-digit PIN (hashed)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            $table->timestamp('phone_verified_at')->nullable()->after('phone');
        });

        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 15)->index();
            $table->string('purpose', 20)->default('login');
            $table->string('code_hash', 255);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('channel', 10)->default('sms');
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });

        if (! Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function (Blueprint $table) {
                $table->id();
                $table->morphs('tokenable');
                $table->text('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
            });
        }

        Schema::create('api_developers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 255)->nullable();
            $table->string('company', 120)->nullable();
            $table->string('pin_hash', 255);
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->foreignId('test_user_id')->nullable()->constrained('users')->nullOnDelete()->comment('Sandbox customer used for test tokens');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_developers');
        Schema::dropIfExists('otp_codes');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'phone_verified_at']));
    }
};
