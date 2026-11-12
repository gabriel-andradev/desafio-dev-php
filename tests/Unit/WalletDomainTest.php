<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Wallet\Exceptions\DomainRuleException;
use App\Domain\Wallet\Money;
use App\Domain\Wallet\ReversalState;
use App\Domain\Wallet\TransactionDomainRules;
use App\Domain\Wallet\TransactionStatus;
use App\Domain\Wallet\TransactionType;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Money value object and pure wallet domain rules.
 */
class WalletDomainTest extends TestCase
{
    public function test_money_creates_valid_positive_amount_in_cents(): void
    {
        $money = Money::fromCents(15050);

        $this->assertSame(15050, $money->cents());
        $this->assertFalse($money->isNegative());
        $this->assertSame('R$ 150,50', $money->formatBrl());
    }

    public function test_money_rejects_zero_or_negative_transaction_amounts(): void
    {
        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessage('O valor da operação deve ser maior que zero.');

        Money::fromCents(0);
    }

    public function test_money_parses_brazilian_currency_strings_into_exact_cents(): void
    {
        $this->assertSame(25000, Money::fromBrlInput('250,00')->cents());
        $this->assertSame(145075, Money::fromBrlInput('R$ 1.450,75')->cents());
        $this->assertSame(1000, Money::fromBrlInput('10')->cents());
    }

    public function test_money_formats_negative_balance_properly(): void
    {
        $negativeBalance = Money::fromBalanceCents(-25000);

        $this->assertTrue($negativeBalance->isNegative());
        $this->assertSame('-R$ 250,00', $negativeBalance->formatBrl());
    }

    public function test_transfer_blocks_when_sender_has_insufficient_balance(): void
    {
        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessage('Saldo insuficiente para realizar a transferência.');

        TransactionDomainRules::assertTransferAllowed(
            senderWalletId: 1,
            receiverWalletId: 2,
            senderBalanceCents: 5000,
            transferAmount: Money::fromCents(5001),
        );
    }

    public function test_transfer_blocks_when_sender_balance_is_negative(): void
    {
        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessage('Saldo insuficiente para realizar a transferência.');

        TransactionDomainRules::assertTransferAllowed(
            senderWalletId: 1,
            receiverWalletId: 2,
            senderBalanceCents: -1000,
            transferAmount: Money::fromCents(500),
        );
    }

    public function test_transfer_blocks_self_transfer_to_same_wallet(): void
    {
        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessage('Não é permitido transferir para a própria carteira.');

        TransactionDomainRules::assertTransferAllowed(
            senderWalletId: 10,
            receiverWalletId: 10,
            senderBalanceCents: 50000,
            transferAmount: Money::fromCents(1000),
        );
    }

    public function test_reversal_blocks_already_reversed_transaction(): void
    {
        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessage('Esta operação já foi revertida anteriormente.');

        TransactionDomainRules::assertReversalAllowed(
            type: TransactionType::TRANSFER->value,
            status: TransactionStatus::REVERSED->value,
            actorWalletId: 1,
            senderWalletId: 1,
            receiverWalletId: 2,
        );
    }

    public function test_reversal_blocks_reversing_a_reversal_entry(): void
    {
        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessage('Contra-lançamentos de estorno não podem ser revertidos.');

        TransactionDomainRules::assertReversalAllowed(
            type: TransactionType::REVERSAL->value,
            status: TransactionStatus::COMPLETED->value,
            actorWalletId: 1,
            senderWalletId: 2,
            receiverWalletId: 1,
        );
    }

    public function test_reversal_blocks_unrelated_wallet_from_reversing(): void
    {
        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessage('Você não tem permissão para reverter esta transação.');

        TransactionDomainRules::assertReversalAllowed(
            type: TransactionType::TRANSFER->value,
            status: TransactionStatus::COMPLETED->value,
            actorWalletId: 99,
            senderWalletId: 1,
            receiverWalletId: 2,
        );
    }

    public function test_reversal_allows_sender_within_fifteen_minute_window(): void
    {
        $createdAt = new DateTimeImmutable('-10 minutes');

        TransactionDomainRules::assertReversalAllowed(
            type: TransactionType::TRANSFER->value,
            status: TransactionStatus::COMPLETED->value,
            actorWalletId: 1,
            senderWalletId: 1,
            receiverWalletId: 2,
            createdAt: $createdAt,
        );

        // Reaching this line means no DomainRuleException was thrown.
        $this->addToAssertionCount(1);
    }

    public function test_reversal_blocks_sender_after_fifteen_minute_window(): void
    {
        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessage('O prazo de arrependimento do remetente (15 minutos) expirou.');

        TransactionDomainRules::assertReversalAllowed(
            type: TransactionType::TRANSFER->value,
            status: TransactionStatus::COMPLETED->value,
            actorWalletId: 1,
            senderWalletId: 1,
            receiverWalletId: 2,
            createdAt: new DateTimeImmutable('-16 minutes'),
        );
    }

    public function test_reversal_allows_receiver_to_return_funds_within_twenty_four_hours(): void
    {
        $createdAt = new DateTimeImmutable('-23 hours');

        TransactionDomainRules::assertReversalAllowed(
            type: TransactionType::TRANSFER->value,
            status: TransactionStatus::COMPLETED->value,
            actorWalletId: 2,
            senderWalletId: 1,
            receiverWalletId: 2,
            createdAt: $createdAt,
        );

        // Reaching this line means no DomainRuleException was thrown.
        $this->addToAssertionCount(1);
    }

    public function test_reversal_blocks_receiver_after_twenty_four_hours(): void
    {
        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessage('O prazo limite para devolução voluntária (24 horas) expirou.');

        TransactionDomainRules::assertReversalAllowed(
            type: TransactionType::TRANSFER->value,
            status: TransactionStatus::COMPLETED->value,
            actorWalletId: 2,
            senderWalletId: 1,
            receiverWalletId: 2,
            createdAt: new DateTimeImmutable('-25 hours'),
        );
    }

    public function test_deposit_reversal_blocks_non_owner(): void
    {
        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessage('Apenas o titular da carteira pode solicitar o cancelamento do depósito.');

        TransactionDomainRules::assertReversalAllowed(
            type: TransactionType::DEPOSIT->value,
            status: TransactionStatus::COMPLETED->value,
            actorWalletId: 99,
            senderWalletId: null,
            receiverWalletId: 1,
            createdAt: new DateTimeImmutable('-5 minutes'),
        );
    }

    public function test_deposit_reversal_allows_owner_within_twenty_four_hours(): void
    {
        $createdAt = new DateTimeImmutable('-2 hours');

        TransactionDomainRules::assertReversalAllowed(
            type: TransactionType::DEPOSIT->value,
            status: TransactionStatus::COMPLETED->value,
            actorWalletId: 1,
            senderWalletId: null,
            receiverWalletId: 1,
            createdAt: $createdAt,
        );

        // Reaching this line means no DomainRuleException was thrown.
        $this->addToAssertionCount(1);
    }

    public function test_deposit_reversal_blocks_owner_after_twenty_four_hours(): void
    {
        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessage('O prazo limite para cancelamento do depósito (24 horas) expirou.');

        TransactionDomainRules::assertReversalAllowed(
            type: TransactionType::DEPOSIT->value,
            status: TransactionStatus::COMPLETED->value,
            actorWalletId: 1,
            senderWalletId: null,
            receiverWalletId: 1,
            createdAt: new DateTimeImmutable('-25 hours'),
        );
    }

    public function test_reversal_state_is_available_inside_sender_window(): void
    {
        $this->assertSame(ReversalState::AVAILABLE, TransactionDomainRules::reversalState(
            type: TransactionType::TRANSFER->value,
            status: TransactionStatus::COMPLETED->value,
            actorWalletId: 1,
            senderWalletId: 1,
            receiverWalletId: 2,
            createdAt: new DateTimeImmutable('-10 minutes'),
        ));
    }

    public function test_reversal_state_is_expired_after_sender_window(): void
    {
        $this->assertSame(ReversalState::EXPIRED, TransactionDomainRules::reversalState(
            type: TransactionType::TRANSFER->value,
            status: TransactionStatus::COMPLETED->value,
            actorWalletId: 1,
            senderWalletId: 1,
            receiverWalletId: 2,
            createdAt: new DateTimeImmutable('-20 minutes'),
        ));
    }

    public function test_reversal_state_is_unavailable_for_reversed_or_unrelated_transactions(): void
    {
        $this->assertSame(ReversalState::UNAVAILABLE, TransactionDomainRules::reversalState(
            type: TransactionType::TRANSFER->value,
            status: TransactionStatus::REVERSED->value,
            actorWalletId: 1,
            senderWalletId: 1,
            receiverWalletId: 2,
            createdAt: new DateTimeImmutable('-5 minutes'),
        ));

        $this->assertSame(ReversalState::UNAVAILABLE, TransactionDomainRules::reversalState(
            type: TransactionType::TRANSFER->value,
            status: TransactionStatus::COMPLETED->value,
            actorWalletId: 9,
            senderWalletId: 1,
            receiverWalletId: 2,
            createdAt: new DateTimeImmutable('-5 minutes'),
        ));
    }
}
