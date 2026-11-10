<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Provides application and database health status for container orchestration and monitoring.
 */
class HealthController extends Controller
{
    /**
     * Checks database connectivity and returns a structured health payload.
     */
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('SELECT 1');

            return response()->json([
                'status' => 'ok',
                'service' => 'cobuccio-wallet-php',
                'database' => 'connected',
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (Throwable) {
            return response()->json([
                'status' => 'error',
                'service' => 'cobuccio-wallet-php',
                'database' => 'disconnected',
                'timestamp' => now()->toIso8601String(),
            ], 503);
        }
    }
}

