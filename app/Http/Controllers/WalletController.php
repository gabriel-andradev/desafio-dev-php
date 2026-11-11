<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Wallet\Exceptions\DomainRuleException;
use App\Http\Requests\DepositRequest;
use App\Http\Requests\ReverseTransactionRequest;
use App\Http\Requests\TransferRequest;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View as ViewFacade;

/**
 * Handles wallet dashboard views, deposits, peer-to-peer transfers, and transaction reversals.
 */
class WalletController extends Controller
{
    private const VALID_TABS = ['overview', 'deposit', 'transfer', 'statement'];

    public function __construct(
        private readonly WalletService $walletService,
    ) {}

    /**
     * Displays the user's wallet dashboard or returns the JSON summary payload.
     */
    public function dashboard(Request $request): View|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $summary = $this->walletService->getWalletSummary($user);

        $requestedTab = (string) $request->query('tab', 'overview');
        $activeTab = in_array($requestedTab, self::VALID_TABS, true) ? $requestedTab : 'overview';

        if ($request->expectsJson() || ! ViewFacade::exists('wallet.dashboard')) {
            return response()->json([
                'active_tab' => $activeTab,
                'summary' => $summary,
            ]);
        }

        return view('wallet.dashboard', [
            'user' => $user,
            'summary' => $summary,
            'activeTab' => $activeTab,
        ]);
    }

    /**
     * Processes a deposit into the authenticated user's wallet.
     */
    public function deposit(DepositRequest $request): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        try {
            $transaction = $this->walletService->deposit(
                user: $user,
                amountCents: (int) $validated['amount_cents'],
                description: $validated['description'] ?? null,
                idempotencyKey: $validated['idempotency_key'] ?? null,
            );
        } catch (DomainRuleException $exception) {
            return $this->handleDomainException($request, $exception, 'deposit');
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Depósito realizado com sucesso.',
                'transaction' => $transaction,
                'idempotent_replay' => ! $transaction->wasRecentlyCreated,
            ], $transaction->wasRecentlyCreated ? 201 : 200);
        }

        return redirect()
            ->route('wallet.dashboard', ['tab' => 'overview'])
            ->with('status', 'Depósito realizado com sucesso.');
    }

    /**
     * Processes a peer-to-peer transfer from the authenticated user to a recipient.
     */
    public function transfer(TransferRequest $request): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        try {
            $transaction = $this->walletService->transfer(
                sender: $user,
                recipientIdentifier: (string) $validated['recipient'],
                amountCents: (int) $validated['amount_cents'],
                description: $validated['description'] ?? null,
                idempotencyKey: $validated['idempotency_key'] ?? null,
            );
        } catch (DomainRuleException $exception) {
            return $this->handleDomainException($request, $exception, 'transfer');
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Transferência realizada com sucesso.',
                'transaction' => $transaction,
                'idempotent_replay' => ! $transaction->wasRecentlyCreated,
            ], $transaction->wasRecentlyCreated ? 201 : 200);
        }

        return redirect()
            ->route('wallet.dashboard', ['tab' => 'statement'])
            ->with('status', 'Transferência realizada com sucesso.');
    }

    /**
     * Reverses a completed deposit or transfer transaction.
     */
    public function reverse(ReverseTransactionRequest $request, int $transaction): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();

        try {
            $reversal = $this->walletService->reverseTransaction(
                actor: $user,
                transactionId: $transaction,
                reason: $validated['reason'] ?? null,
            );
        } catch (DomainRuleException $exception) {
            return $this->handleDomainException($request, $exception, 'statement');
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Operação revertida com sucesso.',
                'reversal' => $reversal,
            ]);
        }

        return redirect()
            ->route('wallet.dashboard', ['tab' => 'statement'])
            ->with('status', 'Operação revertida com sucesso.');
    }

    /**
     * Formats domain rule exceptions into JSON or session error redirects.
     */
    private function handleDomainException(
        Request $request,
        DomainRuleException $exception,
        string $targetTab,
    ): RedirectResponse|JsonResponse {
        $statusCode = $exception->getCode() >= 400 && $exception->getCode() < 600
            ? $exception->getCode()
            : 422;

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], $statusCode);
        }

        return redirect()
            ->route('wallet.dashboard', ['tab' => $targetTab])
            ->withInput()
            ->withErrors(['wallet' => $exception->getMessage()]);
    }
}
