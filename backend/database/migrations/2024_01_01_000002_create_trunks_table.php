<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trunks', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('type')->default('peer');
            $table->string('host');
            $table->integer('port')->default(5060);
            $table->string('username')->nullable();
            $table->string('secret')->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('transport')->default('udp');
            $table->json('codecs')->nullable();
            $table->string('context')->default('from-trunk');
            $table->integer('priority')->default(1);
            $table->string('prefix')->default('9');
            $table->integer('strip_digits')->default(1);
            $table->integer('max_channels')->default(10);
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->index('name');
            $table->index('enabled');
            $table->index('priority');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trunks');
    }
};
