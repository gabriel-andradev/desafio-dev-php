@extends('layouts.app')

@section('title', 'Carteira Digital • Grupo Adriano Cobuccio')

@php
    use App\Domain\Wallet\Money;

    $walletId = $summary['wallet_id'];
    $balanceCents = $summary['balance_cents'];
    $isNegative = $summary['is_negative'];
    $formattedBalance = $summary['formatted_balance'];
    $totalDepositsFormatted = Money::fromBalanceCents($summary['total_deposits_cents'])->formatBrl();
    $totalSentFormatted = Money::fromBalanceCents($summary['total_sent_cents'])->formatBrl();
    $rawCpf = (string) $user->cpf;
    $formattedUserCpf = strlen($rawCpf) === 11
        ? substr($rawCpf, 0, 3).'.'.substr($rawCpf, 3, 3).'.'.substr($rawCpf, 6, 3).'-'.substr($rawCpf, 9, 2)
        : $rawCpf;
@endphp

@section('content')
<div
    x-data="{
        activeTab: '{{ $activeTab }}',
        hideBalance: false,
        currentBalanceCents: {{ $balanceCents }},
        depositInput: '',
        transferInput: '',
        recipientInput: '{{ old('recipient', '') }}',
        filterType: 'ALL',
        reversingTxId: null,
        reversalReason: '',
        selectTab(tab) {
            this.activeTab = tab;
            const url = new URL(window.location.href);
            if (tab === 'overview') {
                url.searchParams.delete('tab');
            } else {
                url.searchParams.set('tab', tab);
            }
            window.history.replaceState(null, '', url.pathname + (url.search ? url.search : ''));
        },
        setQuickDeposit(cents) {
            this.depositInput = window.WalletFormatters.formatCurrencyInput(String(cents));
        },
        get previewDepositCents() {
            return window.WalletFormatters.parseCurrencyToCents(this.depositInput);
        },
        get projectedDepositBalanceCents() {
            return this.currentBalanceCents + this.previewDepositCents;
        },
        get previewTransferCents() {
            return window.WalletFormatters.parseCurrencyToCents(this.transferInput);
        },
        get projectedTransferBalanceCents() {
            return this.currentBalanceCents - this.previewTransferCents;
        },
        formatCents(cents) {
            return window.WalletFormatters.formatCurrencyFromCents(cents);
        }
    }"
    class="min-h-screen bg-slate-100 grid grid-cols-1 md:grid-cols-12"
>
    <aside class="md:col-span-3 lg:col-span-2 bg-brand-dark text-white p-5 flex flex-col justify-between border-r border-brand-gold/20">
        <div class="space-y-6">
            <div class="flex items-center gap-3 pb-4 border-b border-white/10">
                <div class="w-9 h-9 rounded-lg bg-brand-navy border border-brand-gold flex items-center justify-center font-serif font-bold text-brand-gold text-sm shrink-0">
                    AC
                </div>
                <div class="truncate">
                    <div class="text-xs font-bold tracking-wide text-white">
                        COBUCCIO
                    </div>
                    <div class="text-xs text-brand-gold">Brasil Card Wallet</div>
                </div>
            </div>

            <nav aria-label="Módulos da Carteira" class="space-y-1.5 text-xs">
                <div class="text-slate-400 uppercase tracking-wider px-2.5 pb-1 font-semibold">
                    Módulos
                </div>

                <button
                    type="button"
                    @click="selectTab('overview')"
                    :class="activeTab === 'overview' ? 'bg-brand-navy text-brand-gold border border-brand-gold/40' : 'text-slate-300 hover:bg-white/5'"
                    class="w-full text-left px-3 py-2.5 rounded-lg font-medium flex items-center gap-2.5 transition cursor-pointer"
                >
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="7" height="7" rx="1"></rect>
                        <rect x="14" y="3" width="7" height="7" rx="1"></rect>
                        <rect x="14" y="14" width="7" height="7" rx="1"></rect>
                        <rect x="3" y="14" width="7" height="7" rx="1"></rect>
                    </svg>
                    <span>Visão Geral</span>
                </button>

                <button
                    type="button"
                    @click="selectTab('deposit')"
                    :class="activeTab === 'deposit' ? 'bg-brand-navy text-brand-gold border border-brand-gold/40' : 'text-slate-300 hover:bg-white/5'"
                    class="w-full text-left px-3 py-2.5 rounded-lg font-medium flex items-center gap-2.5 transition cursor-pointer"
                >
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="16"></line>
                        <line x1="8" y1="12" x2="16" y2="12"></line>
                    </svg>
                    <span>Depositar</span>
                </button>

                <button
                    type="button"
                    @click="selectTab('transfer')"
                    :class="activeTab === 'transfer' ? 'bg-brand-navy text-brand-gold border border-brand-gold/40' : 'text-slate-300 hover:bg-white/5'"
                    class="w-full text-left px-3 py-2.5 rounded-lg font-medium flex items-center gap-2.5 transition cursor-pointer"
                >
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 16V4m0 0L3 8m4-4 4 4m6 0v12m0 0 4-4m-4 4-4-4" />
                    </svg>
                    <span>Transferir</span>
                </button>

                <button
                    type="button"
                    @click="selectTab('statement')"
                    :class="activeTab === 'statement' ? 'bg-brand-navy text-brand-gold border border-brand-gold/40' : 'text-slate-300 hover:bg-white/5'"
                    class="w-full text-left px-3 py-2.5 rounded-lg font-medium flex items-center gap-2.5 transition cursor-pointer"
                >
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z" />
                    </svg>
                    <span>Extrato & Estornos</span>
                </button>
            </nav>
        </div>

        <div class="pt-4 border-t border-white/10 space-y-3 text-xs">
            <div class="p-3 rounded-lg bg-brand-navy/60 border border-white/10 space-y-1">
                <div class="font-bold text-white truncate">{{ $user->name }}</div>
                <div class="text-slate-400 truncate">{{ $user->email }}</div>
                <div class="text-brand-gold font-mono text-[11px]">CPF: {{ $formattedUserCpf }}</div>
            </div>

            <form method="POST" action="{{ route('auth.logout') }}">
                @csrf
                <button
                    type="submit"
                    class="w-full py-2 px-3 rounded-lg bg-white/5 hover:bg-rose-500/20 text-slate-300 hover:text-rose-200 border border-white/10 font-medium flex items-center justify-center gap-2 transition cursor-pointer"
                >
                    <span>Sair da Conta</span>
                </button>
            </form>
        </div>
    </aside>

    <main class="md:col-span-9 lg:col-span-10 p-6 sm:p-8 space-y-6">
        <header class="flex flex-wrap items-center justify-between gap-4 bg-white p-5 rounded-xl border border-slate-200 shadow-sm">
            <div>
                <h1 class="text-lg font-bold text-slate-900">
                    Olá, {{ $user->name }}
                </h1>
                <p class="text-xs text-slate-500">
                    Carteira Digital #{{ $walletId }} • Operações protegidas com isolamento ACID e bloqueio pessimista
                </p>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="button"
                    @click="hideBalance = !hideBalance"
                    class="px-3.5 py-2 rounded-lg border border-slate-200 bg-slate-50 hover:bg-slate-100 text-xs font-semibold text-slate-700 transition cursor-pointer"
                >
                    <span x-text="hideBalance ? 'Mostrar Valores' : 'Ocultar Valores'"></span>
                </button>
            </div>
        </header>

        @if (session('status'))
            <div role="status" class="p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-medium flex items-center justify-between">
                <span>✓ {{ session('status') }}</span>
            </div>
        @endif

        @if ($errors->any())
            <div role="alert" class="p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-medium">
                <span>⚠ {{ $errors->first() }}</span>
            </div>
        @endif

        @if ($isNegative)
            <div class="p-4 rounded-xl bg-rose-50 border border-rose-300 flex flex-wrap items-center justify-between gap-4">
                <div class="space-y-1">
                    <div class="text-xs font-bold text-rose-800 uppercase tracking-wide">
                        Alerta de Saldo Devedor ({{ $formattedBalance }})
                    </div>
                    <p class="text-xs text-rose-700">
                        Sua carteira está com saldo negativo. Ao realizar um depósito, o valor abaterá automaticamente a dívida pendente e acrescentará o saldo residual.
                    </p>
                </div>

                <button
                    type="button"
                    @click="selectTab('deposit')"
                    class="px-4 py-2 rounded-lg bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold transition cursor-pointer"
                >
                    Cobrir Saldo Agora
                </button>
            </div>
        @endif

        <section x-show="activeTab === 'overview'" class="space-y-6">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="p-5 rounded-xl bg-brand-dark text-white border border-brand-gold/30 shadow-sm space-y-2">
                    <div class="text-xs text-slate-300 font-medium">
                        Saldo Disponível em Conta
                    </div>
                    <div class="text-2xl font-bold font-mono {{ $isNegative ? 'text-rose-400' : 'text-brand-gold' }}">
                        <span x-show="!hideBalance">{{ $formattedBalance }}</span>
                        <span x-show="hideBalance">R$ ••••••</span>
                    </div>
                </div>

                <div class="p-5 rounded-xl bg-white border border-slate-200 shadow-sm space-y-2">
                    <div class="text-xs text-slate-500 font-medium">
                        Total de Depósitos Ativos
                    </div>
                    <div class="text-xl font-bold font-mono text-emerald-600">
                        <span x-show="!hideBalance">+ {{ $totalDepositsFormatted }}</span>
                        <span x-show="hideBalance">R$ ••••••</span>
                    </div>
                </div>

                <div class="p-5 rounded-xl bg-white border border-slate-200 shadow-sm space-y-2">
                    <div class="text-xs text-slate-500 font-medium">
                        Total de Transferências Enviadas
                    </div>
                    <div class="text-xl font-bold font-mono text-rose-600">
                        <span x-show="!hideBalance">- {{ $totalSentFormatted }}</span>
                        <span x-show="hideBalance">R$ ••••••</span>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <button
                    type="button"
                    @click="selectTab('deposit')"
                    class="p-5 rounded-xl bg-white hover:border-brand-gold border border-slate-200 shadow-sm text-left space-y-1 transition cursor-pointer"
                >
                    <div class="text-sm font-bold text-slate-900">Realizar Depósito</div>
                    <p class="text-xs text-slate-500">Adicione saldo imediato ou quite pendências de saldo negativo.</p>
                </button>

                <button
                    type="button"
                    @click="selectTab('transfer')"
                    class="p-5 rounded-xl bg-white hover:border-brand-gold border border-slate-200 shadow-sm text-left space-y-1 transition cursor-pointer"
                >
                    <div class="text-sm font-bold text-slate-900">Transferência P2P</div>
                    <p class="text-xs text-slate-500">Envie valores por CPF ou e-mail com validação prévia de saldo.</p>
                </button>

                <button
                    type="button"
                    @click="selectTab('statement')"
                    class="p-5 rounded-xl bg-white hover:border-brand-gold border border-slate-200 shadow-sm text-left space-y-1 transition cursor-pointer"
                >
                    <div class="text-sm font-bold text-slate-900">Extrato & Reversões</div>
                    <p class="text-xs text-slate-500">Consulte o livro-razão (Ledger) e solicite estorno de operações.</p>
                </button>
            </div>

            <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-bold text-slate-900">Últimas Movimentações</h2>
                    <button
                        type="button"
                        @click="selectTab('statement')"
                        class="text-xs font-semibold text-brand-navy hover:underline cursor-pointer"
                    >
                        Ver extrato completo →
                    </button>
                </div>

                @if ($summary['transactions']->isEmpty())
                    <p class="text-xs text-slate-500 py-4">Nenhuma movimentação registrada até o momento.</p>
                @else
                    <div class="divide-y divide-slate-100 text-xs">
                        @foreach ($summary['transactions']->take(4) as $tx)
                            @php
                                $isIncoming = $tx->receiver_wallet_id === $walletId && $tx->sender_wallet_id !== $walletId;
                                if ($tx->type === 'DEPOSIT') {
                                    $isIncoming = true;
                                }
                                $isReversed = $tx->status === 'REVERSED';
                                $txMoney = Money::fromBalanceCents($tx->amount_cents)->formatBrl();
                            @endphp
                            <div class="py-3 flex items-center justify-between">
                                <div>
                                    <span class="font-semibold {{ $isReversed ? 'line-through text-slate-400' : 'text-slate-800' }}">
                                        {{ $tx->description ?: $tx->type }}
                                    </span>
                                    <span class="text-slate-400 ml-2">
                                        {{ $tx->created_at?->format('d/m/Y H:i') }}
                                    </span>
                                </div>
                                <span class="font-mono font-bold {{ $isReversed ? 'line-through text-slate-400' : ($isIncoming ? 'text-emerald-600' : 'text-rose-600') }}">
                                    {{ $isIncoming ? '+' : '-' }} {{ $txMoney }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>

        <section x-show="activeTab === 'deposit'" class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            <div class="lg:col-span-7 bg-white rounded-xl border border-slate-200 shadow-sm p-6 space-y-5">
                <div>
                    <h2 class="text-base font-bold text-slate-900">Depósito em Conta</h2>
                    <p class="text-xs text-slate-500">
                        O valor depositado entra imediatamente na carteira. Caso seu saldo esteja negativo, o depósito abate a dívida e soma a diferença.
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button type="button" @click="setQuickDeposit(5000)" class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-xs font-semibold text-slate-700 cursor-pointer">+ R$ 50,00</button>
                    <button type="button" @click="setQuickDeposit(10000)" class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-xs font-semibold text-slate-700 cursor-pointer">+ R$ 100,00</button>
                    <button type="button" @click="setQuickDeposit(25000)" class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-xs font-semibold text-slate-700 cursor-pointer">+ R$ 250,00</button>
                    <button type="button" @click="setQuickDeposit(50000)" class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-xs font-semibold text-slate-700 cursor-pointer">+ R$ 500,00</button>
                </div>

                <form method="POST" action="{{ route('wallet.deposit') }}" class="space-y-4">
                    @csrf

                    <div>
                        <label for="deposit_amount" class="block text-xs font-semibold text-slate-700 mb-1.5">
                            Valor do Depósito (R$)
                        </label>
                        <input
                            id="deposit_amount"
                            name="amount"
                            type="text"
                            inputmode="numeric"
                            required
                            x-model="depositInput"
                            @input="depositInput = window.WalletFormatters.formatCurrencyInput($event.target.value)"
                            placeholder="0,00"
                            class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-slate-900 font-mono text-base focus:outline-none focus:border-brand-navy"
                        >
                    </div>

                    <div>
                        <label for="deposit_description" class="block text-xs font-semibold text-slate-700 mb-1.5">
                            Descrição (opcional)
                        </label>
                        <input
                            id="deposit_description"
                            name="description"
                            type="text"
                            maxlength="160"
                            placeholder="Ex: Aporte de capital / Cobertura de conta"
                            class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-slate-900 text-xs focus:outline-none focus:border-brand-navy"
                        >
                    </div>

                    <button
                        type="submit"
                        class="w-full py-3 px-4 rounded-lg bg-brand-navy hover:bg-brand-dark text-brand-gold font-bold text-xs uppercase tracking-wider transition cursor-pointer"
                    >
                        Confirmar Depósito
                    </button>
                </form>
            </div>

            <div class="lg:col-span-5 bg-brand-dark text-white rounded-xl border border-brand-gold/30 p-6 space-y-5">
                <h3 class="text-sm font-bold text-brand-gold">Simulador Contábil de Depósito</h3>

                <div class="space-y-3 text-xs border-b border-white/10 pb-4">
                    <div class="flex justify-between">
                        <span class="text-slate-300">Saldo Atual:</span>
                        <span class="font-mono font-semibold {{ $isNegative ? 'text-rose-400' : 'text-white' }}">
                            {{ $formattedBalance }}
                        </span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-300">Valor do Depósito:</span>
                        <span class="font-mono font-semibold text-emerald-400" x-text="'+ ' + formatCents(previewDepositCents)"></span>
                    </div>
                </div>

                <div>
                    <div class="text-xs text-slate-300">Saldo Previsto Após Confirmação:</div>
                    <div
                        class="text-xl font-bold font-mono mt-1"
                        :class="projectedDepositBalanceCents < 0 ? 'text-rose-400' : 'text-brand-gold'"
                        x-text="formatCents(projectedDepositBalanceCents)"
                    ></div>
                </div>
            </div>
        </section>

        <section x-show="activeTab === 'transfer'" class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            <div class="lg:col-span-7 bg-white rounded-xl border border-slate-200 shadow-sm p-6 space-y-5">
                <div>
                    <h2 class="text-base font-bold text-slate-900">Transferência entre Carteiras (P2P)</h2>
                    <p class="text-xs text-slate-500">
                        Informe o e-mail ou CPF do destinatário. O saldo é validado atomicamente com bloqueio pessimista antes da efetivação.
                    </p>
                </div>

                @if ($summary['recipients']->isNotEmpty())
                    <div class="space-y-1.5">
                        <div class="text-xs font-semibold text-slate-600">Contatos Rápidos Cadastrados:</div>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($summary['recipients'] as $participant)
                                <button
                                    type="button"
                                    @click="recipientInput = '{{ $participant->email }}'"
                                    class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-brand-navy hover:text-white text-xs font-medium text-slate-700 transition cursor-pointer"
                                >
                                    {{ $participant->name }} ({{ $participant->email }})
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif

                <form method="POST" action="{{ route('wallet.transfer') }}" class="space-y-4">
                    @csrf

                    <div>
                        <label for="transfer_recipient" class="block text-xs font-semibold text-slate-700 mb-1.5">
                            Destinatário (E-mail ou CPF)
                        </label>
                        <input
                            id="transfer_recipient"
                            name="recipient"
                            type="text"
                            required
                            x-model="recipientInput"
                            @input="recipientInput = window.WalletFormatters.formatRecipientInput($event.target.value)"
                            placeholder="nome@cobuccio.com ou 000.000.000-00"
                            class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-slate-900 text-xs font-mono focus:outline-none focus:border-brand-navy"
                        >
                    </div>

                    <div>
                        <label for="transfer_amount" class="block text-xs font-semibold text-slate-700 mb-1.5">
                            Valor da Transferência (R$)
                        </label>
                        <input
                            id="transfer_amount"
                            name="amount"
                            type="text"
                            inputmode="numeric"
                            required
                            x-model="transferInput"
                            @input="transferInput = window.WalletFormatters.formatCurrencyInput($event.target.value)"
                            placeholder="0,00"
                            class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-slate-900 font-mono text-base focus:outline-none focus:border-brand-navy"
                        >
                    </div>

                    <div>
                        <label for="transfer_description" class="block text-xs font-semibold text-slate-700 mb-1.5">
                            Descrição (opcional)
                        </label>
                        <input
                            id="transfer_description"
                            name="description"
                            type="text"
                            maxlength="160"
                            placeholder="Ex: Pagamento de serviço"
                            class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-slate-900 text-xs focus:outline-none focus:border-brand-navy"
                        >
                    </div>

                    <button
                        type="submit"
                        class="w-full py-3 px-4 rounded-lg bg-brand-navy hover:bg-brand-dark text-brand-gold font-bold text-xs uppercase tracking-wider transition cursor-pointer"
                    >
                        Enviar Transferência Agora
                    </button>
                </form>
            </div>

            <div class="lg:col-span-5 bg-brand-dark text-white rounded-xl border border-brand-gold/30 p-6 space-y-5">
                <h3 class="text-sm font-bold text-brand-gold">Resumo da Transferência</h3>

                <div class="space-y-3 text-xs border-b border-white/10 pb-4">
                    <div class="flex justify-between">
                        <span class="text-slate-300">Saldo Disponível:</span>
                        <span class="font-mono font-semibold {{ $isNegative ? 'text-rose-400' : 'text-brand-gold' }}">
                            {{ $formattedBalance }}
                        </span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-300">Valor a Transferir:</span>
                        <span class="font-mono font-semibold text-rose-400" x-text="'- ' + formatCents(previewTransferCents)"></span>
                    </div>
                </div>

                <div>
                    <div class="text-xs text-slate-300">Saldo Projetado Após Envio:</div>
                    <div
                        class="text-xl font-bold font-mono mt-1"
                        :class="projectedTransferBalanceCents < 0 ? 'text-rose-400' : 'text-brand-gold'"
                        x-text="formatCents(projectedTransferBalanceCents)"
                    ></div>
                </div>
            </div>
        </section>

        <section x-show="activeTab === 'statement'" class="bg-white rounded-xl border border-slate-200 shadow-sm p-6 space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-base font-bold text-slate-900">Extrato Contábil (Ledger) & Reversões</h2>
                    <p class="text-xs text-slate-500">
                        Histórico imutável de operações. Depósitos e transferências concluídos podem ser revertidos com rastreabilidade.
                    </p>
                </div>

                <div class="flex flex-wrap gap-1.5 text-xs">
                    <button type="button" @click="filterType = 'ALL'" :class="filterType === 'ALL' ? 'bg-brand-navy text-white' : 'bg-slate-100 text-slate-700'" class="px-3 py-1.5 rounded-lg font-semibold cursor-pointer">Todas</button>
                    <button type="button" @click="filterType = 'DEPOSIT'" :class="filterType === 'DEPOSIT' ? 'bg-brand-navy text-white' : 'bg-slate-100 text-slate-700'" class="px-3 py-1.5 rounded-lg font-semibold cursor-pointer">Depósitos</button>
                    <button type="button" @click="filterType = 'TRANSFER'" :class="filterType === 'TRANSFER' ? 'bg-brand-navy text-white' : 'bg-slate-100 text-slate-700'" class="px-3 py-1.5 rounded-lg font-semibold cursor-pointer">Transferências</button>
                    <button type="button" @click="filterType = 'REVERSAL'" :class="filterType === 'REVERSAL' ? 'bg-brand-navy text-white' : 'bg-slate-100 text-slate-700'" class="px-3 py-1.5 rounded-lg font-semibold cursor-pointer">Estornos</button>
                </div>
            </div>

            @if ($summary['transactions']->isEmpty())
                <p class="text-xs text-slate-500 py-6 text-center">Nenhuma transação encontrada para esta carteira.</p>
            @else
                <div class="divide-y divide-slate-200">
                    @foreach ($summary['transactions'] as $tx)
                        @php
                            $isIncoming = $tx->receiver_wallet_id === $walletId && $tx->sender_wallet_id !== $walletId;
                            if ($tx->type === 'DEPOSIT') {
                                $isIncoming = true;
                            }
                            $isReversed = $tx->status === 'REVERSED';
                            $canReverse = $tx->status === 'COMPLETED' && in_array($tx->type, ['DEPOSIT', 'TRANSFER'], true);
                            $txMoney = Money::fromBalanceCents($tx->amount_cents)->formatBrl();
                            $senderName = $tx->senderWallet?->user?->name ?? 'Sistema';
                            $receiverName = $tx->receiverWallet?->user?->name ?? 'Usuário';
                        @endphp

                        <div
                            x-show="filterType === 'ALL' || filterType === '{{ $tx->type }}'"
                            class="py-4 flex flex-wrap items-center justify-between gap-4"
                        >
                            <div class="space-y-1">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-mono text-slate-400">#{{ $tx->id }}</span>
                                    <span class="text-sm font-bold {{ $isReversed ? 'line-through text-slate-400' : 'text-slate-900' }}">
                                        {{ $tx->description ?: $tx->type }}
                                    </span>
                                    @if ($isReversed)
                                        <span class="px-2 py-0.5 rounded bg-amber-100 text-amber-800 text-[11px] font-semibold">
                                            Revertida
                                        </span>
                                    @elseif ($tx->type === 'REVERSAL')
                                        <span class="px-2 py-0.5 rounded bg-sky-100 text-sky-800 text-[11px] font-semibold">
                                            Contra-lançamento
                                        </span>
                                    @else
                                        <span class="px-2 py-0.5 rounded bg-emerald-100 text-emerald-800 text-[11px] font-semibold">
                                            Concluída
                                        </span>
                                    @endif
                                </div>

                                <div class="text-xs text-slate-500 flex flex-wrap items-center gap-2">
                                    <span>{{ $tx->created_at?->format('d/m/Y H:i') }}</span>
                                    <span>•</span>
                                    <span>
                                        @if ($tx->type === 'DEPOSIT')
                                            Depósito Direto em Conta
                                        @elseif ($tx->type === 'REVERSAL')
                                            Estorno Contábil Referente à Operação #{{ $tx->original_transaction_id }}
                                        @elseif ($isIncoming)
                                            Recebido de {{ $senderName }}
                                        @else
                                            Enviado para {{ $receiverName }}
                                        @endif
                                    </span>
                                </div>

                                @if ($tx->reversal_reason)
                                    <div class="text-xs text-amber-700 font-medium">
                                        Motivo do estorno: {{ $tx->reversal_reason }}
                                    </div>
                                @endif
                            </div>

                            <div class="flex items-center gap-3">
                                <span class="font-mono text-sm font-bold {{ $isReversed ? 'line-through text-slate-400' : ($isIncoming ? 'text-emerald-600' : 'text-rose-600') }}">
                                    {{ $isIncoming ? '+' : '-' }} {{ $txMoney }}
                                </span>

                                @if ($canReverse)
                                    <button
                                        type="button"
                                        @click="reversingTxId = (reversingTxId === {{ $tx->id }} ? null : {{ $tx->id }})"
                                        class="px-3 py-1.5 rounded-lg border border-amber-300 bg-amber-50 hover:bg-amber-100 text-amber-900 text-xs font-semibold transition cursor-pointer"
                                    >
                                        Reverter
                                    </button>
                                @endif
                            </div>

                            @if ($canReverse)
                                <div
                                    x-show="reversingTxId === {{ $tx->id }}"
                                    class="w-full mt-2 p-4 rounded-xl bg-slate-50 border border-slate-200"
                                >
                                    <form method="POST" action="{{ route('wallet.reverse', ['transaction' => $tx->id]) }}" class="flex flex-wrap items-center gap-3">
                                        @csrf
                                        <input
                                            type="text"
                                            name="reason"
                                            placeholder="Informe o motivo da reversão (opcional)"
                                            class="flex-1 min-w-55 px-3 py-2 rounded-lg border border-slate-300 text-xs text-slate-900"
                                        >
                                        <button
                                            type="submit"
                                            class="px-4 py-2 rounded-lg bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold cursor-pointer"
                                        >
                                            Confirmar Reversão #{{ $tx->id }}
                                        </button>
                                        <button
                                            type="button"
                                            @click="reversingTxId = null"
                                            class="px-3 py-2 rounded-lg bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-semibold cursor-pointer"
                                        >
                                            Cancelar
                                        </button>
                                    </form>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    </main>
</div>
@endsection

