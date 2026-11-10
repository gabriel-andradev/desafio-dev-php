<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration tests for user registration, atomic wallet creation, and session authentication.
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Verifies that the guest authentication Blade views render properly.
     */
    public function test_login_and_register_views_render_successfully(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('GRUPO ADRIANO COBUCCIO')
            ->assertSee('Acesse sua Carteira Digital');

        $this->get('/register')
            ->assertOk()
            ->assertSee('Abra sua Conta Financeira');
    }

    /**
     * Verifies that registering a new user creates a zero-balance wallet in the same transaction.
     */
    public function test_user_registration_provisions_zero_balance_wallet_atomically(): void
    {
        $response = $this->postJson('/register', [
            'name' => 'Gabriel Cobuccio',
            'email' => 'gabriel@cobuccio.com',
            'cpf' => '123.456.789-01',
            'password' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.email', 'gabriel@cobuccio.com')
            ->assertJsonPath('user.cpf', '12345678901');

        $this->assertAuthenticated();

        $user = User::where('email', 'gabriel@cobuccio.com')->firstOrFail();
        $wallet = Wallet::where('user_id', $user->id)->first();

        $this->assertNotNull($wallet);
        $this->assertSame(0, $wallet->balance_cents);
    }

    /**
     * Verifies that formatted CPFs are normalized to 11 digits and duplicates are rejected.
     */
    public function test_registration_normalizes_formatted_cpf_and_rejects_duplicates(): void
    {
        $this->postJson('/register', [
            'name' => 'Primeiro Usuário',
            'email' => 'primeiro@cobuccio.com',
            'cpf' => '111.222.333-44',
            'password' => 'password123',
        ])->assertCreated();

        $this->postJson('/logout');

        $duplicateResponse = $this->postJson('/register', [
            'name' => 'Segundo Usuário',
            'email' => 'segundo@cobuccio.com',
            'cpf' => '11122233344',
            'password' => 'password123',
        ]);

        $duplicateResponse->assertUnprocessable()
            ->assertJsonValidationErrors(['cpf']);
    }

    /**
     * Verifies that a registered user can authenticate and terminate their session.
     */
    public function test_user_can_login_and_logout_securely(): void
    {
        $user = User::factory()->create([
            'email' => 'bruno@cobuccio.com',
            'cpf' => '99988877766',
            'password' => 'secret1234',
        ]);

        Wallet::create([
            'user_id' => $user->id,
            'balance_cents' => 0,
        ]);

        $loginResponse = $this->postJson('/login', [
            'email' => 'BRUNO@cobuccio.com',
            'password' => 'secret1234',
        ]);

        $loginResponse->assertOk()
            ->assertJsonPath('user.id', $user->id);

        $this->assertAuthenticatedAs($user);

        $this->postJson('/logout')->assertOk();
        $this->assertGuest();
    }

    /**
     * Verifies that invalid credentials return a generic message to prevent user enumeration.
     */
    public function test_login_rejects_invalid_credentials_with_generic_message(): void
    {
        User::factory()->create([
            'email' => 'mariana@cobuccio.com',
            'password' => 'correct-password',
        ]);

        $response = $this->postJson('/login', [
            'email' => 'mariana@cobuccio.com',
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertGuest();
    }
}
