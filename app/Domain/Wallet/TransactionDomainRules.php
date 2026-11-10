<?php

declare(strict_types=1);

namespace App\Domain\Wallet;

use App\Domain\Wallet\Exceptions\DomainRuleException;

final class TransactionDomainRules
{
    /**
     * Calcula o novo saldo após um depósito.
     * Se a conta estiver negativa, a soma algébrica em centavos abate a dívida
     * automaticamente e mantém o excedente como saldo positivo.
     */
    public static function calculateBalanceAfterDeposit(int $currentBalanceCents, Money $depositAmount): int
    {
        return $currentBalanceCents + $depositAmount->cents();
    }

    public static function assertTransferAllowed(
        int $senderWalletId,
        int $receiverWalletId,
        int $senderBalanceCents,
        Money $transferAmount,
    ): void {
        if ($senderWalletId === $receiverWalletId) {
            throw new DomainRuleException('Não é permitido transferir para a própria carteira.');
        }

        if ($senderBalanceCents < $transferAmount->cents()) {
            throw new DomainRuleException('Saldo insuficiente para realizar a transferência.');
        }
    }

    public static function assertReversalAllowed(
        string $type,
        string $status,
        int $actorWalletId,
        ?int $senderWalletId,
        int $receiverWalletId,
    ): void {
        if ($status === TransactionStatus::REVERSED->value) {
            throw new DomainRuleException('Esta operação já foi revertida anteriormente.');
        }

        if ($type === TransactionType::REVERSAL->value) {
            throw new DomainRuleException('Contra-lançamentos de estorno não podem ser revertidos.');
        }

        $isParticipant = ($senderWalletId !== null && $actorWalletId === $senderWalletId)
            || $actorWalletId === $receiverWalletId;

        if (! $isParticipant) {
            throw new DomainRuleException('Você não tem permissão para reverter esta transação.', 403);
        }
    }
}

