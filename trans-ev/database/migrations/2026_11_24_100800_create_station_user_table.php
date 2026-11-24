<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Stations assigned to station managers (they only see and manage these). */
    public function up(): void
    {
        Schema::create('station_user', function (Blueprint $table) {
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['station_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_user');
    }
};
