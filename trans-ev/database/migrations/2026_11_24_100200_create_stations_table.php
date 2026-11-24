<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('code', 20)->unique();
            $table->text('description')->nullable();
            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->string('landmark')->nullable();
            $table->string('city');
            $table->string('state');
            $table->string('state_code', 2);
            $table->string('pincode', 6);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            // MySQL spatial column (SRID 4326), kept in sync with latitude/longitude by the Station model.
            $table->geography('location', subtype: 'point', srid: 4326);
            $table->string('gstin', 15)->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->string('contact_email')->nullable();
            $table->string('status', 20)->default('online');
            $table->string('status_reason')->nullable();
            $table->timestamp('status_until')->nullable();
            $table->boolean('is_24x7')->default(false);
            $table->unsignedSmallInteger('slot_minutes')->default(60);
            $table->unsignedSmallInteger('buffer_minutes')->default(0);
            $table->json('amenities')->nullable();
            $table->json('photos')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->spatialIndex('location');
            $table->index(['latitude', 'longitude']);
            $table->index(['status', 'is_published']);
            $table->index('city');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stations');
    }
};
