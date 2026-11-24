<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chargers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name')->nullable();
            $table->string('oem')->nullable();
            $table->string('type', 2); // AC / DC
            $table->decimal('power_kw', 6, 2);
            $table->json('connectors');
            $table->string('status', 20)->default('online');
            $table->string('status_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['station_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chargers');
    }
};
