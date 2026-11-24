<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Permission labels, grouping and the platform-only flag live in the database (shown on Roles & permissions). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->string('group', 40)->nullable()->after('name');
            $table->string('group_label', 80)->nullable()->after('group');
            $table->string('label', 160)->nullable()->after('group_label');
            $table->boolean('platform_only')->default(false)->after('label');
            $table->unsignedSmallInteger('sort')->default(100)->after('platform_only');
        });
    }

    public function down(): void
    {
        Schema::table('permissions', fn (Blueprint $table) => $table->dropColumn(['group', 'group_label', 'label', 'platform_only', 'sort']));
    }
};
