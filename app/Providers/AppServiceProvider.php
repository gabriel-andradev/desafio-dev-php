<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Mirrors the Node API limits: 10 auth attempts and 30 wallet operations per minute.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('auth', function (Request $request): Limit {
            return Limit::perMinute(10)
                ->by(strtolower((string) $request->input('email')).'|'.$request->ip())
                ->response(fn (Request $request, array $headers): Response => $this->tooManyAttempts(
                    $request,
                    $headers,
                    'Muitas tentativas de acesso. Aguarde um minuto e tente novamente.',
                    'email',
                ));
        });

        RateLimiter::for('wallet', function (Request $request): Limit {
            return Limit::perMinute(30)
                ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip()))
                ->response(fn (Request $request, array $headers): Response => $this->tooManyAttempts(
                    $request,
                    $headers,
                    'Muitas operações em sequência. Aguarde um minuto e tente novamente.',
                    'wallet',
                ));
        });
    }

    /**
     * Returns a 429 JSON payload for API clients, or redirects web forms back with a validation-style error.
     *
     * @param  array<string, string|int>  $headers
     */
    private function tooManyAttempts(Request $request, array $headers, string $message, string $errorKey): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 429, $headers);
        }

        return back()
            ->withInput($request->except(['password', 'password_confirmation']))
            ->withErrors([$errorKey => $message])
            ->withHeaders($headers);
    }
}
