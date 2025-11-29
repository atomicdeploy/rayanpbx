<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Changes the credentials column from json to text type because
     * the credentials are stored as encrypted strings (not valid JSON).
     *
     * MariaDB 10.4+ creates an implicit CHECK constraint for JSON columns.
     * When a column is defined as JSON, MariaDB automatically adds a constraint
     * named after the column (e.g., 'credentials') that validates the data is
     * valid JSON using the JSON_VALID() function. This constraint must be dropped
     * before changing the column type to TEXT, otherwise encrypted values will
     * fail validation.
     *
     * MySQL does not have this behavior - it stores JSON as a native type without
     * CHECK constraints, so this DROP CONSTRAINT will be a no-op on MySQL.
     */
    public function up(): void
    {
        // Drop the implicit JSON CHECK constraint that MariaDB creates.
        // MariaDB names JSON constraints after the column name (e.g., 'credentials').
        // The 'IF EXISTS' clause ensures this works even if:
        // - Running on MySQL (which doesn't create these constraints)
        // - The constraint was already dropped
        // - The database version doesn't support IF EXISTS (older MariaDB)
        try {
            DB::statement('ALTER TABLE voip_phones DROP CONSTRAINT IF EXISTS credentials');
        } catch (QueryException $e) {
            // Log the specific error for debugging but don't fail the migration.
            // This handles cases where:
            // - The database doesn't support 'DROP CONSTRAINT IF EXISTS' syntax
            // - Running on MySQL which doesn't have implicit JSON constraints
            // - The constraint doesn't exist
            Log::debug('Could not drop credentials constraint (may not exist): ' . $e->getMessage());
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
