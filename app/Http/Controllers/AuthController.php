<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Handles user registration, session authentication, and logout flows.
 */
class AuthController extends Controller
{
    /**
     * Displays the login screen.
     */
    public function showLogin(): View
    {
        return view('auth.login');
    }

    /**
     * Displays the registration screen.
     */
    public function showRegister(): View
    {
        return view('auth.register');
    }

    /**
     * Registers a new user and provisions an initial zero-balance wallet atomically.
     */
    public function register(RegisterRequest $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validated();

        $user = DB::transaction(function () use ($validated): User {
            $newUser = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'cpf' => $validated['cpf'],
                'password' => $validated['password'],
            ]);

            Wallet::create([
                'user_id' => $newUser->id,
                'balance_cents' => 0,
            ]);

            return $newUser;
        });

        Auth::login($user);
        $request->session()->regenerate();

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Conta criada com sucesso.',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'cpf' => $user->cpf,
                ],
            ], 201);
        }

        return redirect()->route('wallet.dashboard');
    }

    /**
     * Authenticates a user and regenerates the session identifier to prevent session fixation.
     *
     * @throws ValidationException
     */
    public function login(LoginRequest $request): RedirectResponse|JsonResponse
    {
        $credentials = $request->only('email', 'password');

        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => 'Credenciais inválidas. Verifique seu e-mail e senha.',
            ]);
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::user();

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Autenticação realizada com sucesso.',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'cpf' => $user->cpf,
                ],
            ]);
        }

        return redirect()->intended(route('wallet.dashboard'));
    }

    /**
     * Terminates the authenticated session and rotates the CSRF token.
     */
    public function logout(Request $request): RedirectResponse|JsonResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Sessão encerrada com sucesso.']);
        }

        return redirect()->route('login');
    }
}
