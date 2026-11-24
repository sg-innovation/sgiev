<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Website pages edited in admin (Terms, Privacy, Refund policy…), with an append-only version history. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('title', 160);
            $table->string('intro', 300)->nullable();
            $table->longText('body');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_published')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('page_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('title', 160);
            $table->string('intro', 300)->nullable();
            $table->longText('body');
            $table->string('change_note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['page_id', 'version']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS `page_versions_no_update`');
            DB::unprepared("CREATE TRIGGER `page_versions_no_update` BEFORE UPDATE ON `page_versions` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'page_versions is append-only'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS `page_versions_no_update`');
        }
        Schema::dropIfExists('page_versions');
        Schema::dropIfExists('pages');
    }
};
