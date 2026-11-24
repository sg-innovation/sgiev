<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Admin-managed lists & options (KYC document types, amenities, parking types, EV models…). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lookups', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            $table->string('value', 80);              // stable key stored on records
            $table->string('label', 120);
            $table->string('description', 255)->nullable();
            $table->json('meta')->nullable();         // type-specific extras (e.g. required, battery_kwh)
            $table->unsignedSmallInteger('sort')->default(10);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['type', 'value']);
            $table->index(['type', 'is_active', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lookups');
    }
};
