<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add advanced PJSIP configuration options to extensions table.
     * These options allow for better customization of SIP endpoints.
     */
    public function up(): void
    {
        Schema::table('extensions', function (Blueprint $table) {
            // direct_media: Controls whether RTP should go directly between endpoints
            // Default 'no' is safer for NAT/firewall scenarios
            $table->string('direct_media', 10)->default('no')->after('max_contacts');
            
            // qualify_frequency: How often to send OPTIONS to check if endpoint is alive
            // Default 60 seconds is a good balance between responsiveness and overhead
            $table->integer('qualify_frequency')->default(60)->after('direct_media');
        });
    }

    public function down(): void
    {
        Schema::table('extensions', function (Blueprint $table) {
            $table->dropColumn(['direct_media', 'qualify_frequency']);
        });
    }
};
