<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('phone_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voip_phone_id')->constrained('voip_phones')->onDelete('cascade');
            $table->string('session_id', 255)->nullable(); // GrandStream session/cookie value
            $table->string('challenge', 255)->nullable(); // Challenge value for auth
            $table->text('cookies')->nullable(); // JSON-encoded cookies from phone
            $table->string('token', 255)->nullable(); // Authentication token if applicable
            $table->boolean('is_active')->default(true);
            $table->timestamp('authenticated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index('is_active');
            $table->index(['voip_phone_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('phone_sessions');
    }
};
