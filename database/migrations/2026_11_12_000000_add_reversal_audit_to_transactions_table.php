<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for REVERSAL entries: whether it came from the user or an internal compensation,
 * and who ran it ("user:<id>" or the operator name). Additive and nullable: existing rows stay valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('reversal_source', 20)->nullable();
            $table->string('reversal_actor', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['reversal_source', 'reversal_actor']);
        });
    }
};
