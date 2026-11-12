<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Wallet\Exceptions\DomainRuleException;
use App\Services\WalletService;
use Illuminate\Console\Command;

/**
 * Internal compensation of a technical inconsistency. There is deliberately no HTTP route for this:
 * running it requires shell access to the server, which is the trusted origin.
 */
class CompensateTransaction extends Command
{
    protected $signature = 'wallet:compensate
        {transaction : ID da transação original (DEPOSIT ou TRANSFER)}
        {--reason= : Justificativa (obrigatória, mínimo 10 caracteres)}
        {--operator= : Responsável pela compensação (obrigatório)}';

    protected $description = 'Compensa (estorna) uma operação por inconsistência técnica, sem as janelas de prazo do usuário.';

    public function handle(WalletService $walletService): int
    {
        try {
            $reversal = $walletService->compensateTransaction(
                transactionId: (int) $this->argument('transaction'),
                reason: (string) $this->option('reason'),
                operator: (string) $this->option('operator'),
            );
        } catch (DomainRuleException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Compensação registrada: lançamento #{$reversal->id} (origem COMPENSATION, {$reversal->reversal_actor}).");

        return self::SUCCESS;
    }
}
