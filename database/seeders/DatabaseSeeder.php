<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Wallet\TransactionStatus;
use App\Domain\Wallet\TransactionType;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds demonstration users, wallets, and ledger history for technical review.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Populates the database with positive and negative balance accounts and sample ledger records.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $gabriel = User::updateOrCreate(
                ['email' => 'gabriel@cobuccio.com'],
                [
                    'name' => 'Gabriel Cobuccio',
                    'cpf' => '11122233344',
                    'password' => 'password123',
                ],
            );

            $bruno = User::updateOrCreate(
                ['email' => 'bruno@cobuccio.com'],
                [
                    'name' => 'Bruno Costa',
                    'cpf' => '55566677788',
                    'password' => 'password123',
                ],
            );

            $mariana = User::updateOrCreate(
                ['email' => 'mariana@cobuccio.com'],
                [
                    'name' => 'Mariana Souza',
                    'cpf' => '99988877766',
                    'password' => 'password123',
                ],
            );

            $gabrielWallet = Wallet::updateOrCreate(
                ['user_id' => $gabriel->id],
                ['balance_cents' => 145000],
            );

            $brunoWallet = Wallet::updateOrCreate(
                ['user_id' => $bruno->id],
                ['balance_cents' => 80000],
            );

            $marianaWallet = Wallet::updateOrCreate(
                ['user_id' => $mariana->id],
                ['balance_cents' => -25000],
            );

            if (Transaction::count() > 0) {
                return;
            }

            Transaction::create([
                'type' => TransactionType::DEPOSIT->value,
                'status' => TransactionStatus::COMPLETED->value,
                'amount_cents' => 200000,
                'sender_wallet_id' => null,
                'receiver_wallet_id' => $gabrielWallet->id,
                'description' => 'Aporte inicial em conta',
            ]);

            Transaction::create([
                'type' => TransactionType::TRANSFER->value,
                'status' => TransactionStatus::COMPLETED->value,
                'amount_cents' => 55000,
                'sender_wallet_id' => $gabrielWallet->id,
                'receiver_wallet_id' => $brunoWallet->id,
                'description' => 'Transferência entre contas',
            ]);

            Transaction::create([
                'type' => TransactionType::DEPOSIT->value,
                'status' => TransactionStatus::COMPLETED->value,
                'amount_cents' => 25000,
                'sender_wallet_id' => null,
                'receiver_wallet_id' => $brunoWallet->id,
                'description' => 'Depósito direto',
            ]);

            $reversedOriginal = Transaction::create([
                'type' => TransactionType::DEPOSIT->value,
                'status' => TransactionStatus::REVERSED->value,
                'amount_cents' => 25000,
                'sender_wallet_id' => null,
                'receiver_wallet_id' => $marianaWallet->id,
                'description' => 'Depósito revertido após utilização parcial',
                'reversal_reason' => 'Inconsistência operacional de liquidação',
                'reversed_at' => now(),
            ]);

            Transaction::create([
                'type' => TransactionType::REVERSAL->value,
                'status' => TransactionStatus::COMPLETED->value,
                'amount_cents' => 25000,
                'sender_wallet_id' => $marianaWallet->id,
                'receiver_wallet_id' => $marianaWallet->id,
                'original_transaction_id' => $reversedOriginal->id,
                'description' => 'Estorno da operação #'.$reversedOriginal->id,
                'reversal_reason' => 'Inconsistência operacional de liquidação',
            ]);
        });
    }
}
