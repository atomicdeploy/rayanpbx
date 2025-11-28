<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Changes the credentials column from json to text type because
     * the credentials are stored as encrypted strings (not valid JSON).
     */
    public function up(): void
    {
        Schema::table('voip_phones', function (Blueprint $table) {
            $table->text('credentials')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('voip_phones', function (Blueprint $table) {
            $table->json('credentials')->nullable()->change();
        });
    }
};
