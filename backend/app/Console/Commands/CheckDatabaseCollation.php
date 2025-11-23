<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckDatabaseCollation extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:check-collation {--fix : Automatically fix the collation if it is incorrect}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check and optionally fix the database collation to utf8mb4_unicode_ci';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $databaseName = DB::getDatabaseName();
        
        $this->info("Checking collation for database: {$databaseName}");
        
        // Get current database collation
        $result = DB::select("SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME 
                              FROM INFORMATION_SCHEMA.SCHEMATA 
                              WHERE SCHEMA_NAME = ?", [$databaseName]);
        
        if (empty($result)) {
            $this->error("Could not retrieve database collation information.");
            return 1;
        }
        
        $currentCharset = $result[0]->DEFAULT_CHARACTER_SET_NAME;
        $currentCollation = $result[0]->DEFAULT_COLLATION_NAME;
        
        $this->line("Current charset: {$currentCharset}");
        $this->line("Current collation: {$currentCollation}");
        
        $expectedCharset = 'utf8mb4';
        $expectedCollation = 'utf8mb4_unicode_ci';
        
        if ($currentCharset === $expectedCharset && $currentCollation === $expectedCollation) {
            $this->info("✓ Database collation is correct!");
            return 0;
        }
        
        $this->warn("Database collation needs to be updated.");
        $this->warn("Expected: {$expectedCharset} / {$expectedCollation}");
        $this->warn("Current:  {$currentCharset} / {$currentCollation}");
        
        if ($this->option('fix')) {
            $this->info("Attempting to fix database collation...");
            
            try {
                DB::statement("ALTER DATABASE `{$databaseName}` CHARACTER SET {$expectedCharset} COLLATE {$expectedCollation}");
                $this->info("✓ Database collation updated successfully!");
                return 0;
            } catch (\Exception $e) {
                $this->error("Failed to update database collation: " . $e->getMessage());
                return 1;
            }
        } else {
            $this->line("");
            $this->info("To fix the collation, run:");
            $this->line("  php artisan db:check-collation --fix");
            return 1;
        }
    }
}
