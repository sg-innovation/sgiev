<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D3: ratings and reviews of a station and its charger, only from a customer's own completed booking. Written reviews
 * can be moderated; the operator can reply publicly. Averages are kept on stations and chargers for search and pages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->foreignId('charger_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('station_rating');          // 1–5
            $table->unsignedTinyInteger('charger_rating')->nullable(); // 1–5
            $table->json('tags')->nullable();                       // quick tags: clean, easy_to_find, fast_charging…
            $table->text('body')->nullable();
            $table->string('status', 10)->default('published')->index(); // pending, published, rejected
            $table->string('moderation_note', 255)->nullable();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->text('owner_reply')->nullable();
            $table->foreignId('owner_replied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('owner_replied_at')->nullable();
            $table->timestamps();
            $table->index(['station_id', 'status', 'created_at']);
        });

        Schema::table('stations', function (Blueprint $table) {
            $table->decimal('rating_avg', 3, 2)->nullable();
            $table->unsignedInteger('rating_count')->default(0);
        });
        Schema::table('chargers', function (Blueprint $table) {
            $table->decimal('rating_avg', 3, 2)->nullable();
            $table->unsignedInteger('rating_count')->default(0);
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->timestamp('review_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn('review_requested_at'));
        Schema::table('chargers', fn (Blueprint $table) => $table->dropColumn(['rating_avg', 'rating_count']));
        Schema::table('stations', fn (Blueprint $table) => $table->dropColumn(['rating_avg', 'rating_count']));
        Schema::dropIfExists('reviews');
    }
};
