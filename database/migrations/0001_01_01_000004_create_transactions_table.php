<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('status', 20)->default('COMPLETED');
            $table->bigInteger('amount_cents');
            $table->foreignId('sender_wallet_id')->nullable()->constrained('wallets')->restrictOnDelete();
            $table->foreignId('receiver_wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->foreignId('original_transaction_id')->nullable()->unique()->constrained('transactions')->restrictOnDelete();
            $table->string('description')->nullable();
            $table->string('reversal_reason')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->index(['sender_wallet_id', 'created_at']);
            $table->index(['receiver_wallet_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};

