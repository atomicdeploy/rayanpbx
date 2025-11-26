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
        Schema::create('voip_phones', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 45)->unique(); // IPv4/IPv6
            $table->string('mac', 17)->nullable()->unique(); // MAC address
            $table->string('extension', 32)->nullable(); // Associated extension
            $table->string('name', 100)->nullable(); // User-friendly name
            $table->string('vendor', 50)->default('grandstream'); // Phone vendor
            $table->string('model', 50)->nullable(); // Phone model (e.g., GXP1625)
            $table->string('firmware', 50)->nullable(); // Firmware version
            $table->string('status', 20)->default('discovered'); // online, offline, discovered, registered
            $table->string('discovery_type', 20)->nullable(); // lldp, arp, manual, sip
            $table->string('user_agent', 255)->nullable(); // SIP User-Agent string
            $table->boolean('cti_enabled')->default(false); // CTI features enabled
            $table->boolean('snmp_enabled')->default(false); // SNMP monitoring enabled
            $table->json('snmp_config')->nullable(); // SNMP configuration
            $table->json('credentials')->nullable(); // Encrypted admin credentials
            $table->json('config')->nullable(); // Additional configuration
            $table->timestamp('last_seen')->nullable(); // Last communication timestamp
            $table->timestamps();
            
            // Indexes
            $table->index('status');
            $table->index('extension');
            $table->index('vendor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('voip_phones');
    }
};
