<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dialplan_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name')->charset('utf8mb4')->collation('utf8mb4_unicode_ci');
            $table->string('context')->default('from-internal')->charset('utf8mb4')->collation('utf8mb4_unicode_ci');
            $table->string('pattern')->charset('utf8mb4')->collation('utf8mb4_unicode_ci'); // e.g., _1XX, 101, _9X.
            $table->integer('priority')->default(1);
            $table->string('app')->default('Dial')->charset('utf8mb4')->collation('utf8mb4_unicode_ci'); // Dial, NoOp, Hangup, etc.
            $table->text('app_data')->nullable()->charset('utf8mb4')->collation('utf8mb4_unicode_ci'); // e.g., PJSIP/${EXTEN},30
            $table->boolean('enabled')->default(true);
            $table->enum('rule_type', ['internal', 'outbound', 'inbound', 'pattern', 'custom'])->default('pattern');
            $table->text('description')->nullable()->charset('utf8mb4')->collation('utf8mb4_unicode_ci');
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('context');
            $table->index('enabled');
            $table->index(['context', 'pattern']);
            $table->index('rule_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dialplan_rules');
    }
};
