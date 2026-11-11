<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Service responsible for user registration, wallet provisioning, and authentication flows.
 */
class AuthService
{
    /**
     * Registers a new user and provisions an initial zero-balance wallet atomically.
     *
     * @param  array{name: string, email: string, cpf: string, password: string}  $data
     */
    public function register(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'cpf' => $data['cpf'],
                'password' => $data['password'],
            ]);

            Wallet::create([
                'user_id' => $user->id,
                'balance_cents' => 0,
            ]);

            return $user;
        });
    }

    /**
     * Attempts to authenticate a user using the provided credentials.
     *
     * @param  array{email: string, password: string}  $credentials
     */
    public function attemptLogin(array $credentials): bool
    {
        return Auth::attempt($credentials);
    }

    /**
     * Logs the current user out of the application.
     */
    public function logout(): void
    {
        Auth::logout();
    }
}
