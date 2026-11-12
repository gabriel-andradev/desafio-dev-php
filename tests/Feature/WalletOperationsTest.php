<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Wallet\TransactionStatus;
use App\Domain\Wallet\TransactionType;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration tests for wallet deposits, negative balance offset, P2P transfers, reversals, and healthcheck.
 */
class WalletOperationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Verifies that an authenticated user can deposit funds using BRL string formatting.
     */
    public function test_authenticated_user_can_deposit_money_into_wallet(): void
    {
        [$user, $wallet] = $this->createUserWithWallet(balanceCents: 10000);

        $response = $this->actingAs($user)->postJson('/wallet/deposit', [
            'amount' => '250,50',
            'description' => 'Aporte inicial',
        ]);

        $response->assertCreated()
            ->assertJsonPath('transaction.type', TransactionType::DEPOSIT->value)
            ->assertJsonPath('transaction.amount_cents', 25050);

        $this->assertSame(35050, $wallet->fresh()?->balance_cents);
    }

    /**
     * Verifies the business requirement that depositing into a negative wallet offsets the debt first.
     */
    public function test_deposit_into_negative_wallet_offsets_debt_and_adds_residual_balance(): void
    {
        [$mariana, $wallet] = $this->createUserWithWallet(balanceCents: -25000);

        $response = $this->actingAs($mariana)->postJson('/wallet/deposit', [
            'amount' => '400,00',
            'description' => 'Cobertura de saldo negativo',
        ]);

        $response->assertCreated();
        $this->assertSame(15000, $wallet->fresh()?->balance_cents);
    }

    /**
     * Verifies peer-to-peer transfers by both email address and formatted CPF.
     */
    public function test_user_can_transfer_funds_by_email_and_by_cpf(): void
    {
        [$sender, $senderWallet] = $this->createUserWithWallet(
            balanceCents: 100000,
            email: 'gabriel@cobuccio.com',
            cpf: '11122233344',
        );

        [$receiver, $receiverWallet] = $this->createUserWithWallet(
            balanceCents: 5000,
            email: 'bruno@cobuccio.com',
            cpf: '55566677788',
        );

        $this->actingAs($sender)->postJson('/wallet/transfer', [
            'recipient' => 'bruno@cobuccio.com',
            'amount' => '300,00',
            'description' => 'Pagamento por e-mail',
        ])->assertCreated();

        $this->actingAs($sender)->postJson('/wallet/transfer', [
            'recipient' => '555.666.777-88',
            'amount' => '200,00',
            'description' => 'Pagamento por CPF',
        ])->assertCreated();

        $this->assertSame(50000, $senderWallet->fresh()?->balance_cents);
        $this->assertSame(55000, $receiverWallet->fresh()?->balance_cents);
    }

    /**
     * Verifies that transfers exceeding the available balance are rejected without altering balances.
     */
    public function test_transfer_fails_when_sender_has_insufficient_balance(): void
    {
        [$sender, $senderWallet] = $this->createUserWithWallet(balanceCents: 10000);
        [$receiver, $receiverWallet] = $this->createUserWithWallet(
            balanceCents: 0,
            email: 'destino@cobuccio.com',
        );

        $response = $this->actingAs($sender)->postJson('/wallet/transfer', [
            'recipient' => 'destino@cobuccio.com',
            'amount' => '150,00',
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('message', 'Saldo insuficiente para realizar a transferência.');

        $this->assertSame(10000, $senderWallet->fresh()?->balance_cents);
        $this->assertSame(0, $receiverWallet->fresh()?->balance_cents);
    }

    /**
     * Verifies that reversing a transfer restores the sender's balance and leaves the receiver negative if funds were spent.
     */
    public function test_transfer_reversal_restores_sender_balance_and_allows_receiver_to_go_negative(): void
    {
        [$sender, $senderWallet] = $this->createUserWithWallet(balanceCents: 50000);
        [$receiver, $receiverWallet] = $this->createUserWithWallet(
            balanceCents: 0,
            email: 'mariana@cobuccio.com',
        );

        [$thirdParty] = $this->createUserWithWallet(
            balanceCents: 0,
            email: 'fornecedor@cobuccio.com',
        );

        $transferResponse = $this->actingAs($sender)->postJson('/wallet/transfer', [
            'recipient' => 'mariana@cobuccio.com',
            'amount' => '400,00',
        ])->assertCreated();

        $transactionId = (int) $transferResponse->json('transaction.id');

        $this->actingAs($receiver)->postJson('/wallet/transfer', [
            'recipient' => 'fornecedor@cobuccio.com',
            'amount' => '250,00',
        ])->assertCreated();

        $reversalResponse = $this->actingAs($sender)->postJson("/wallet/transactions/{$transactionId}/reverse", [
            'reason' => 'Solicitação de estorno por erro de digitação',
        ]);

        $reversalResponse->assertOk()
            ->assertJsonPath('reversal.type', TransactionType::REVERSAL->value)
            ->assertJsonPath('reversal.original_transaction_id', $transactionId);

        $this->assertSame(50000, $senderWallet->fresh()?->balance_cents);
        $this->assertSame(-25000, $receiverWallet->fresh()?->balance_cents);

        $originalTx = Transaction::findOrFail($transactionId);
        $this->assertSame(TransactionStatus::REVERSED->value, $originalTx->status);
        $this->assertNotNull($originalTx->reversed_at);
    }

    /**
     * Verifies that a failure between the debit/credit and the ledger insert rolls everything back.
     */
    public function test_failure_in_the_middle_of_a_transfer_rolls_back_both_balances(): void
    {
        [$sender, $senderWallet] = $this->createUserWithWallet(balanceCents: 50000);
        [, $receiverWallet] = $this->createUserWithWallet(balanceCents: 1000, email: 'bruno@cobuccio.com');

        // Balances are already updated when the ledger row is inserted: make that insert fail.
        Transaction::creating(function (): never {
            throw new \RuntimeException('Simulated failure while writing the ledger entry.');
        });

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($sender)->postJson('/wallet/transfer', [
                'recipient' => 'bruno@cobuccio.com',
                'amount' => '100,00',
            ]);
            $this->fail('The simulated failure should have been thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated failure while writing the ledger entry.', $exception->getMessage());
        }

        $this->assertSame(50000, $senderWallet->fresh()?->balance_cents);
        $this->assertSame(1000, $receiverWallet->fresh()?->balance_cents);
        $this->assertSame(0, Transaction::count());
    }

    /**
     * Verifies that the statement offers "Estornar" inside the window and explains when the window has expired.
     */
    public function test_statement_shows_reverse_button_then_expired_label(): void
    {
        [$sender] = $this->createUserWithWallet(balanceCents: 50000);
        $this->createUserWithWallet(balanceCents: 0, email: 'bruno@cobuccio.com');

        $this->actingAs($sender)->postJson('/wallet/transfer', [
            'recipient' => 'bruno@cobuccio.com',
            'amount' => '100,00',
        ])->assertCreated();

        $this->actingAs($sender)->get('/dashboard?tab=statement')
            ->assertOk()
            ->assertSee('Confirmar estorno')
            ->assertDontSee('Prazo de estorno expirado');

        $this->travel(16)->minutes();

        $this->actingAs($sender)->get('/dashboard?tab=statement')
            ->assertOk()
            ->assertSee('Prazo de estorno expirado')
            ->assertDontSee('Confirmar estorno');
    }

    /**
     * Verifies that the receiver can voluntarily return funds within the 24-hour window.
     */
    public function test_receiver_can_return_transfer_within_twenty_four_hours(): void
    {
        [$sender, $senderWallet] = $this->createUserWithWallet(balanceCents: 50000);
        [$receiver, $receiverWallet] = $this->createUserWithWallet(balanceCents: 0, email: 'bruno@cobuccio.com');

        $transferResponse = $this->actingAs($sender)->postJson('/wallet/transfer', [
            'recipient' => 'bruno@cobuccio.com',
            'amount' => '100,00',
        ])->assertCreated();

        $transactionId = (int) $transferResponse->json('transaction.id');

        $this->travel(2)->hours();

        $response = $this->actingAs($receiver)->postJson("/wallet/transactions/{$transactionId}/reverse", [
            'reason' => 'Devolução voluntária pelo recebedor',
        ]);

        $response->assertOk()
            ->assertJsonPath('reversal.type', TransactionType::REVERSAL->value)
            ->assertJsonPath('reversal.original_transaction_id', $transactionId);

        $this->assertSame(50000, $senderWallet->fresh()?->balance_cents);
        $this->assertSame(0, $receiverWallet->fresh()?->balance_cents);
    }

    /**
     * Verifies that a deposit can be reversed and that attempting a second reversal is blocked.
     */
    public function test_deposit_reversal_is_idempotent_and_blocks_duplicate_reversals(): void
    {
        [$user, $wallet] = $this->createUserWithWallet(balanceCents: 0);

        $depositResponse = $this->actingAs($user)->postJson('/wallet/deposit', [
            'amount' => '500,00',
        ])->assertCreated();

        $depositId = (int) $depositResponse->json('transaction.id');
        $this->assertSame(50000, $wallet->fresh()?->balance_cents);

        $this->actingAs($user)
            ->postJson("/wallet/transactions/{$depositId}/reverse", [
                'reason' => 'Depósito duplicado',
            ])
            ->assertOk();

        $this->assertSame(0, $wallet->fresh()->balance_cents);

        $secondAttempt = $this->actingAs($user)
            ->postJson("/wallet/transactions/{$depositId}/reverse");

        $secondAttempt->assertStatus(409)
            ->assertJsonPath('message', 'Esta operação já foi revertida anteriormente.');
    }

    /**
     * Verifies the database healthcheck endpoint.
     */
    public function test_healthcheck_endpoint_verifies_database_connection(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('database', 'connected');
    }

    /**
     * Verifies that the dashboard Blade view renders with modules, formatted balance, and negative balance warning.
     */
    public function test_dashboard_view_renders_modules_and_negative_balance_warning(): void
    {
        [$mariana] = $this->createUserWithWallet(
            balanceCents: -25000,
            email: 'mariana@cobuccio.com',
        );

        $this->actingAs($mariana)
            ->get('/dashboard?tab=deposit')
            ->assertOk()
            ->assertSee('Sua carteira está com saldo negativo (-R$ 250,00)')
            ->assertSee('Simulação do depósito')
            ->assertSee('Extrato &amp; Estornos', false);
    }

    /**
     * Verifies that retrying a deposit with the same idempotency key credits the wallet only once.
     */
    public function test_deposit_with_same_idempotency_key_is_credited_only_once(): void
    {
        [$user, $wallet] = $this->createUserWithWallet(balanceCents: 0);

        $first = $this->actingAs($user)
            ->withHeader('X-Idempotency-Key', 'dep-key-001')
            ->postJson('/wallet/deposit', ['amount' => '100,00']);

        $first->assertCreated()->assertJsonPath('idempotent_replay', false);

        $this->actingAs($user)
            ->withHeader('X-Idempotency-Key', 'dep-key-001')
            ->postJson('/wallet/deposit', ['amount' => '100,00'])
            ->assertOk()
            ->assertJsonPath('idempotent_replay', true)
            ->assertJsonPath('transaction.id', $first->json('transaction.id'));

        $this->assertSame(10000, $wallet->fresh()?->balance_cents);
        $this->assertSame(1, Transaction::where('type', TransactionType::DEPOSIT->value)->count());
    }

    /**
     * Verifies that a retried transfer form submission (hidden idempotency_key field) debits the sender only once.
     */
    public function test_transfer_with_same_idempotency_key_is_debited_only_once(): void
    {
        [$sender, $senderWallet] = $this->createUserWithWallet(balanceCents: 50000);
        [, $receiverWallet] = $this->createUserWithWallet(balanceCents: 0, email: 'bruno@cobuccio.com');

        $payload = [
            'recipient' => 'bruno@cobuccio.com',
            'amount' => '200,00',
            'idempotency_key' => 'trf-key-001',
        ];

        $this->actingAs($sender)->postJson('/wallet/transfer', $payload)->assertCreated();
        $this->actingAs($sender)->postJson('/wallet/transfer', $payload)
            ->assertOk()
            ->assertJsonPath('idempotent_replay', true);

        $this->assertSame(30000, $senderWallet->fresh()?->balance_cents);
        $this->assertSame(20000, $receiverWallet->fresh()?->balance_cents);
    }

    /**
     * Verifies that wallet operations are limited to 30 requests per minute per user.
     */
    public function test_wallet_operations_are_rate_limited(): void
    {
        [$user] = $this->createUserWithWallet(balanceCents: 0);

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->actingAs($user)
                ->postJson('/wallet/deposit', ['amount' => '1,00'])
                ->assertCreated();
        }

        $this->actingAs($user)
            ->postJson('/wallet/deposit', ['amount' => '1,00'])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Muitas operações em sequência. Aguarde um minuto e tente novamente.');
    }

    /**
     * After a successful form deposit the amount field comes back empty (no accidental second deposit);
     * after a failed one the typed value is kept so the user can correct it.
     */
    public function test_deposit_form_clears_the_amount_after_success_and_keeps_it_after_an_error(): void
    {
        [$user] = $this->createUserWithWallet(balanceCents: 0);

        $this->actingAs($user)
            ->followingRedirects()
            ->from('/dashboard?tab=deposit')
            ->post('/wallet/deposit', ['amount' => '50,00', 'idempotency_key' => 'form-key-1'])
            ->assertOk()
            ->assertSee('Depósito realizado com sucesso.')
            ->assertSee("depositInput: ''", false);

        $this->actingAs($user)
            ->followingRedirects()
            ->from('/dashboard?tab=deposit')
            ->post('/wallet/deposit', ['amount' => '2.000.000,00', 'idempotency_key' => 'form-key-2'])
            ->assertOk()
            ->assertSee("depositInput: '2.000.000,00'", false);
    }

    /**
     * Helper to provision a user and an associated wallet for testing.
     *
     * @return array{0: User, 1: Wallet}
     */
    private function createUserWithWallet(
        int $balanceCents,
        ?string $email = null,
        ?string $cpf = null,
    ): array {
        $attributes = [];
        if ($email !== null) {
            $attributes['email'] = $email;
        }
        if ($cpf !== null) {
            $attributes['cpf'] = $cpf;
        }

        $user = User::factory()->create($attributes);
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'balance_cents' => $balanceCents,
        ]);

        return [$user, $wallet];
    }
}
