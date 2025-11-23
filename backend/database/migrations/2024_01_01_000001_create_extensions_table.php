<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Set UTF8MB4 collation for database
        DB::statement('ALTER DATABASE ' . DB::getDatabaseName() . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        
        Schema::create('extensions', function (Blueprint $table) {
            $table->id();
            $table->string('extension_number', 20)->unique()->charset('utf8mb4')->collation('utf8mb4_unicode_ci');
            $table->string('name')->charset('utf8mb4')->collation('utf8mb4_unicode_ci');
            $table->string('email')->nullable()->charset('utf8mb4')->collation('utf8mb4_unicode_ci');
            $table->string('secret');
            $table->boolean('enabled')->default(true);
            $table->string('context')->default('from-internal');
            $table->string('transport')->default('udp');
            $table->json('codecs')->nullable();
            $table->integer('max_contacts')->default(1);
            $table->string('caller_id')->nullable()->charset('utf8mb4')->collation('utf8mb4_unicode_ci');
            $table->boolean('voicemail_enabled')->default(false);
            $table->text('notes')->nullable()->charset('utf8mb4')->collation('utf8mb4_unicode_ci');
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
