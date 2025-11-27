<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extensions', function (Blueprint $table) {
            $table->id();
            $table->string('extension_number', 20)->unique();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('secret');
            $table->boolean('enabled')->default(true);
            $table->string('context')->default('from-internal');
            $table->string('transport')->default('udp');
            $table->json('codecs')->nullable();
            $table->integer('max_contacts')->default(1);
            $table->string('caller_id')->nullable();
            $table->boolean('voicemail_enabled')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->index('extension_number');
            $table->index('enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extensions');
    }
};
