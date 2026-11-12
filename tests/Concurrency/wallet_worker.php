<?php

declare(strict_types=1);

/*
 * Worker used by WalletConcurrencyTest: each invocation is a separate PHP process (a real concurrent
 * client) running against the same SQLite file passed in DB_DATABASE.
 *
 * Usage: php wallet_worker.php <setup|transfer|deposit|inspect> [startAtUnixFloat] [idempotencyKey]
 */

use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? '';
$startAt = isset($argv[2]) ? (float) $argv[2] : 0.0;
$idempotencyKey = $argv[3] ?? null;

// Barrier: every worker waits for the same instant so the operations really overlap.
while ($startAt > 0 && microtime(true) < $startAt) {
    usleep(500);
}

try {
    $result = match ($mode) {
        'setup' => (function (): array {
            Artisan::call('migrate', ['--force' => true]);
            $sender = User::factory()->create(['email' => 'sender@test.com', 'cpf' => '11111111111']);
            $receiver = User::factory()->create(['email' => 'receiver@test.com', 'cpf' => '22222222222']);
            Wallet::create(['user_id' => $sender->id, 'balance_cents' => 10000]);
            Wallet::create(['user_id' => $receiver->id, 'balance_cents' => 0]);

            return ['ok' => true];
        })(),
        'transfer' => (function () use ($app): array {
            $transaction = $app->make(WalletService::class)->transfer(
                sender: User::where('email', 'sender@test.com')->firstOrFail(),
                recipientIdentifier: 'receiver@test.com',
                amountCents: 8000,
            );

            return ['ok' => true, 'id' => $transaction->id];
        })(),
        'deposit' => (function () use ($app, $idempotencyKey): array {
            $transaction = $app->make(WalletService::class)->deposit(
                user: User::where('email', 'receiver@test.com')->firstOrFail(),
                amountCents: 5000,
                idempotencyKey: $idempotencyKey,
            );

            return ['ok' => true, 'id' => $transaction->id];
        })(),
        'inspect' => [
            'ok' => true,
            'sender' => (int) Wallet::whereHas('user', fn ($q) => $q->where('email', 'sender@test.com'))->value('balance_cents'),
            'receiver' => (int) Wallet::whereHas('user', fn ($q) => $q->where('email', 'receiver@test.com'))->value('balance_cents'),
            'transfers' => Transaction::where('type', 'TRANSFER')->count(),
            'deposits' => Transaction::where('type', 'DEPOSIT')->count(),
        ],
        default => throw new InvalidArgumentException("Unknown mode: {$mode}"),
    };
} catch (Throwable $exception) {
    $result = ['ok' => false, 'error' => $exception::class.': '.$exception->getMessage()];
}

echo json_encode($result);
