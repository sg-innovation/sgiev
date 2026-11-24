<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase A3–A6: partner self-registration with KYC, company and station approval,
 * station photos/parking/slot options, and plans.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('slug', 60)->unique();
            $table->string('description', 300)->nullable();
            $table->decimal('price_monthly', 10, 2)->default(0);
            $table->json('features');                 // feature keys enabled on this plan
            $table->unsignedSmallInteger('max_stations')->nullable(); // null = unlimited
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('plan_id')->nullable()->after('status')->constrained()->nullOnDelete();
            $table->string('review_note', 500)->nullable()->after('plan_id');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('terms_version', 20)->nullable();
        });
        // Company statuses now: pending (awaiting review), changes_requested, active, rejected, suspended.

        Schema::create('company_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);              // gst_certificate, pan_card, address_proof, cancelled_cheque, other
            $table->string('disk', 20)->default('local');
            $table->string('path');                  // private storage, never public
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->char('sha256', 64);
            $table->string('status', 20)->default('pending'); // pending, verified, rejected
            $table->string('review_note', 300)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('stations', function (Blueprint $table) {
            $table->string('approval_status', 20)->default('draft')->after('is_published'); // draft, submitted, approved, rejected
            $table->string('approval_note', 500)->nullable()->after('approval_status');
            $table->boolean('needs_review')->default(false)->after('approval_note');        // approved, but key details changed since
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('parking_available')->default(true);
            $table->string('parking_type', 30)->nullable();     // free, paid, customers_only
            $table->string('parking_note', 200)->nullable();
            $table->json('allowed_durations')->nullable();      // booking lengths offered, in minutes
        });

        // Everything that exists today was set up by the platform: treat as approved.
        DB::table('stations')->update(['approval_status' => 'approved', 'approved_at' => now()]);
        DB::table('companies')->whereNull('submitted_at')->update(['submitted_at' => now(), 'reviewed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['approval_status', 'approval_note', 'needs_review', 'submitted_at', 'approved_at', 'parking_available', 'parking_type', 'parking_note', 'allowed_durations']);
        });
        Schema::dropIfExists('company_documents');
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropConstrainedForeignId('plan_id');
            $table->dropColumn(['review_note', 'submitted_at', 'reviewed_at', 'terms_accepted_at', 'terms_version']);
        });
        Schema::dropIfExists('plans');
    }
};
