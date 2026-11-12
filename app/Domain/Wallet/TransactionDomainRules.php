<?php

declare(strict_types=1);

namespace App\Domain\Wallet;

use App\Domain\Wallet\Exceptions\DomainRuleException;
use DateTimeInterface;

/**
 * Encapsulates pure domain rules and invariants for wallet deposits, transfers, and reversals.
 */
final class TransactionDomainRules
{
    public const SENDER_REVERSAL_WINDOW_MINUTES = 15;

    public const RECEIVER_REVERSAL_WINDOW_MINUTES = 1440;

    public const DEPOSIT_REVERSAL_WINDOW_MINUTES = 1440;

    /**
     * Validates that a peer-to-peer transfer satisfies balance and participant invariants.
     *
     * @throws DomainRuleException
     */
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

    /** Minimum length of the justification required for an internal compensation. */
    public const COMPENSATION_REASON_MIN_LENGTH = 10;

    /**
     * Validates an internal compensation of a technical inconsistency.
     * Unlike a user reversal there is no time window and no participant check: the trusted origin is
     * the operator running the command on the server. It still requires a justification and an operator,
     * and can never touch a reversal entry or something already reversed.
     *
     * @throws DomainRuleException
     */
    public static function assertCompensationAllowed(string $type, string $status, string $reason, string $operator): void
    {
        if ($status === TransactionStatus::REVERSED->value) {
            throw new DomainRuleException('Esta operação já foi revertida anteriormente.', 409);
        }

        if ($type === TransactionType::REVERSAL->value) {
            throw new DomainRuleException('Contra-lançamentos de estorno não podem ser revertidos.');
        }

        if (mb_strlen(trim($reason)) < self::COMPENSATION_REASON_MIN_LENGTH) {
            throw new DomainRuleException('Informe uma justificativa com pelo menos '.self::COMPENSATION_REASON_MIN_LENGTH.' caracteres.');
        }

        if (trim($operator) === '') {
            throw new DomainRuleException('Informe o responsável pela compensação.');
        }
    }

    /**
     * Classifies a transaction for the statement: reversible now, reversible only if it were not too late, or never.
     */
    public static function reversalState(
        string $type,
        string $status,
        int $actorWalletId,
        ?int $senderWalletId,
        int $receiverWalletId,
        ?DateTimeInterface $createdAt,
    ): ReversalState {
        try {
            self::assertReversalAllowed($type, $status, $actorWalletId, $senderWalletId, $receiverWalletId, $createdAt);

            return ReversalState::AVAILABLE;
        } catch (DomainRuleException) {
            // Fall through: check whether only the time window blocked it.
        }

        try {
            self::assertReversalAllowed($type, $status, $actorWalletId, $senderWalletId, $receiverWalletId, null);

            return ReversalState::EXPIRED;
        } catch (DomainRuleException) {
            return ReversalState::UNAVAILABLE;
        }
    }

    /**
     * Validates that a transaction is eligible for reversal by the requesting participant within allowed time windows.
     *
     * @throws DomainRuleException
     */
    public static function assertReversalAllowed(
        string $type,
        string $status,
        int $actorWalletId,
        ?int $senderWalletId,
        int $receiverWalletId,
        ?DateTimeInterface $createdAt = null,
    ): void {
        // Permission first: someone outside the operation must get 403 without learning its state
        // (reversed or not), the same order as the Node version.
        $isSender = $senderWalletId !== null && $actorWalletId === $senderWalletId;
        $isReceiver = $actorWalletId === $receiverWalletId;

        if ($type === TransactionType::DEPOSIT->value && ! $isReceiver) {
            throw new DomainRuleException('Apenas o titular da carteira pode solicitar o cancelamento do depósito.', 403);
        }

        if (! $isSender && ! $isReceiver) {
            throw new DomainRuleException('Você não tem permissão para reverter esta transação.', 403);
        }

        if ($type === TransactionType::REVERSAL->value) {
            throw new DomainRuleException('Contra-lançamentos de estorno não podem ser revertidos.');
        }

        if ($status === TransactionStatus::REVERSED->value) {
            throw new DomainRuleException('Esta operação já foi revertida anteriormente.', 409);
        }

        if ($createdAt !== null) {
            $currentTimestamp = function_exists('now') ? now()->getTimestamp() : time();
            $ageInMinutes = (int) max(0, floor(($currentTimestamp - $createdAt->getTimestamp()) / 60));

            if ($type === TransactionType::TRANSFER->value) {
                if ($isSender && $ageInMinutes > self::SENDER_REVERSAL_WINDOW_MINUTES) {
                    throw new DomainRuleException('O prazo de arrependimento do remetente (15 minutos) expirou.');
                }

                if ($isReceiver && $ageInMinutes > self::RECEIVER_REVERSAL_WINDOW_MINUTES) {
                    throw new DomainRuleException('O prazo limite para devolução voluntária (24 horas) expirou.');
                }
            }

            if ($type === TransactionType::DEPOSIT->value && $ageInMinutes > self::DEPOSIT_REVERSAL_WINDOW_MINUTES) {
                throw new DomainRuleException('O prazo limite para cancelamento do depósito (24 horas) expirou.');
            }
        }
    }
}
