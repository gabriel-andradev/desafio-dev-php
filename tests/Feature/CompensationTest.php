<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Wallet\ReversalSource;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * Internal compensation of technical inconsistencies (`php artisan wallet:compensate`).
 */
class CompensationTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_compensate_a_transfer_outside_the_user_windows(): void
    {
        [$sender, $senderWallet] = $this->userWithWallet('a@test.com', '11111111111', 10_000);
        [, $receiverWallet] = $this->userWithWallet('b@test.com', '22222222222', 0);

        $id = $this->actingAs($sender)->postJson('/wallet/transfer', ['recipient' => 'b@test.com', 'amount_cents' => 3_000])
            ->assertCreated()->json('transaction.id');

        // Two days later: far outside the 15 min / 24 h user windows.
        $this->travel(2)->days();

        $this->compensate([
            'transaction' => $id,
            '--reason' => 'Crédito duplicado identificado na conciliação',
            '--operator' => 'ops.conciliacao',
        ])->assertSuccessful();

        $this->assertSame(10_000, $senderWallet->fresh()?->balance_cents);
        $this->assertSame(0, $receiverWallet->fresh()?->balance_cents);

        $original = Transaction::findOrFail((int) $id);
        $this->assertSame('REVERSED', $original->status);

        $reversal = Transaction::where('original_transaction_id', $id)->firstOrFail();
        $this->assertSame('REVERSAL', $reversal->type);
        $this->assertSame(ReversalSource::COMPENSATION->value, $reversal->reversal_source);
        $this->assertSame('operator:ops.conciliacao', $reversal->reversal_actor);
        $this->assertStringContainsString('Crédito duplicado', (string) $reversal->reversal_reason);
    }

    public function test_the_same_transaction_cannot_be_compensated_twice(): void
    {
        [$user] = $this->userWithWallet('a@test.com', '11111111111', 0);
        $id = $this->actingAs($user)->postJson('/wallet/deposit', ['amount_cents' => 5_000])->assertCreated()->json('transaction.id');

        $arguments = ['transaction' => $id, '--reason' => 'Depósito sem lastro na conciliação', '--operator' => 'ops'];

        $this->compensate($arguments)->assertSuccessful();
        $this->compensate($arguments)
            ->expectsOutputToContain('já foi revertida')
            ->assertFailed();

        $this->assertSame(1, Transaction::where('type', 'REVERSAL')->count());
    }

    public function test_compensation_requires_justification_and_operator(): void
    {
        [$user, $wallet] = $this->userWithWallet('a@test.com', '11111111111', 0);
        $id = $this->actingAs($user)->postJson('/wallet/deposit', ['amount_cents' => 5_000])->assertCreated()->json('transaction.id');

        $this->compensate(['transaction' => $id, '--reason' => 'curto', '--operator' => 'ops'])->assertFailed();
        $this->compensate(['transaction' => $id, '--reason' => 'Justificativa suficiente'])->assertFailed();

        $this->assertSame(5_000, $wallet->fresh()?->balance_cents);
        $this->assertSame(0, Transaction::where('type', 'REVERSAL')->count());
    }

    public function test_user_reversals_are_audited_as_user_source(): void
    {
        [$user] = $this->userWithWallet('a@test.com', '11111111111', 0);
        $id = $this->actingAs($user)->postJson('/wallet/deposit', ['amount_cents' => 5_000])->assertCreated()->json('transaction.id');

        $this->actingAs($user)->postJson("/wallet/transactions/{$id}/reverse")->assertOk();

        $reversal = Transaction::where('original_transaction_id', $id)->firstOrFail();
        $this->assertSame(ReversalSource::USER->value, $reversal->reversal_source);
        $this->assertSame('user:'.$user->id, $reversal->reversal_actor);
    }

    public function test_there_is_no_http_route_for_compensation(): void
    {
        $compensationRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_contains($route->uri(), 'compensat'));

        $this->assertCount(0, $compensationRoutes);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function compensate(array $arguments): PendingCommand
    {
        $command = $this->artisan('wallet:compensate', $arguments);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    /**
     * @return array{0: User, 1: Wallet}
     */
    private function userWithWallet(string $email, string $cpf, int $balanceCents): array
    {
        $user = User::factory()->create(['email' => $email, 'cpf' => $cpf]);
        $wallet = Wallet::create(['user_id' => $user->id, 'balance_cents' => $balanceCents]);

        return [$user, $wallet];
    }
}
