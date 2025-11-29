<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Changes the credentials column from json to text type because
     * the credentials are stored as encrypted strings (not valid JSON).
     *
     * MariaDB creates an implicit CHECK constraint for JSON columns that
     * validates the data is valid JSON. This constraint must be dropped
     * before changing the column type to TEXT.
     */
    public function up(): void
    {
        // Drop the implicit JSON CHECK constraint that MariaDB creates
        // The constraint is named 'credentials' on the 'voip_phones' table
        try {
            DB::statement('ALTER TABLE voip_phones DROP CONSTRAINT IF EXISTS credentials');
        } catch (\Exception $e) {
            // Constraint may not exist if running on MySQL instead of MariaDB
            // or if the constraint was already dropped
        }

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
