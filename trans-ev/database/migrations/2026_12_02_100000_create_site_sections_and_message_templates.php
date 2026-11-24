<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Website copy blocks and email/SMS message templates, both edited in admin. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_sections', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();          // e.g. home.hero
            $table->json('data');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80);                    // e.g. booking.confirmed
            $table->string('channel', 12);                // mail | sms | whatsapp
            $table->string('subject', 200)->nullable();   // mail only
            $table->text('body');                         // mail: lines separated by blank lines; sms: plain text
            $table->string('action_label', 60)->nullable();
            $table->string('dlt_template_id', 40)->nullable(); // SMS (India DLT)
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['key', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('site_sections');
    }
};
