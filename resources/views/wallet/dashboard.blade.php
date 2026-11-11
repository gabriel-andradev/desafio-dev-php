@extends('layouts.app')

{{--
    Layout, classes and copy mirror the Node/Next.js version (frontend/src/components/wallet/*),
    so both implementations of the challenge look and read the same.
--}}

@php
    use App\Domain\Wallet\Money;
    use App\Domain\Wallet\ReversalState;
    use App\Domain\Wallet\TransactionDomainRules;

    $walletId = $summary['wallet_id'];
    $balanceCents = $summary['balance_cents'];
    $isNegative = $summary['is_negative'];
    $formattedBalance = $summary['formatted_balance'];
    $totalDepositsFormatted = Money::fromBalanceCents($summary['total_deposits_cents'])->formatBrl();
    $totalSentFormatted = Money::fromBalanceCents($summary['total_sent_cents'])->formatBrl();

    $formatCpf = static function (?string $cpf): string {
        $digits = (string) $cpf;

        return strlen($digits) === 11
            ? substr($digits, 0, 3).'.'.substr($digits, 3, 3).'.'.substr($digits, 6, 3).'-'.substr($digits, 9, 2)
            : $digits;
    };

    // Money leaves the wallet whenever it is the sender (this also covers a deposit reversal, which is a debit).
    $isIncoming = static fn ($tx): bool => (int) $tx->sender_wallet_id !== $walletId;

    $recipientsForJs = $summary['recipients']->map(fn ($participant) => [
        'name' => $participant->name,
        'email' => $participant->email,
        'cpf' => $formatCpf($participant->cpf),
    ])->values();

    $navItems = [
        ['key' => 'overview', 'label' => 'Visão Geral', 'icon' => 'layout-dashboard'],
        ['key' => 'deposit', 'label' => 'Depositar', 'icon' => 'plus-circle'],
        ['key' => 'transfer', 'label' => 'Transferir', 'icon' => 'arrow-right-left'],
        ['key' => 'statement', 'label' => 'Extrato & Estornos', 'icon' => 'file-text'],
    ];

    $shortcuts = [
        ['key' => 'deposit', 'title' => 'Depositar →', 'description' => 'Adicione saldo ou quite um saldo negativo.'],
        ['key' => 'transfer', 'title' => 'Transferir →', 'description' => 'Envie dinheiro para outra conta por e-mail ou CPF.'],
        ['key' => 'statement', 'title' => 'Extrato & Estornos →', 'description' => 'Veja o histórico completo e estorne operações.'],
    ];

    $filters = [
        ['key' => 'ALL', 'label' => 'Todos'],
        ['key' => 'DEPOSIT', 'label' => 'Depósitos'],
        ['key' => 'TRANSFER', 'label' => 'Transferências'],
        ['key' => 'REVERSAL', 'label' => 'Estornos'],
    ];
@endphp

@section('content')
<div
    x-data="{
        activeTab: @js($activeTab),
        hideBalance: false,
        currentBalanceCents: {{ $balanceCents }},
        depositInput: @js(old('recipient') === null ? old('amount', '') : ''),
        transferInput: @js(old('recipient') === null ? '' : old('amount', '')),
        recipientInput: @js(old('recipient', '')),
        recipients: @js($recipientsForJs),
        typeCounts: @js($summary['transactions']->countBy('type')),
        filterType: 'ALL',
        reversingTxId: null,
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
        get depositCents() {
            return window.WalletFormatters.parseCurrencyToCents(this.depositInput);
        },
        get transferCents() {
            return window.WalletFormatters.parseCurrencyToCents(this.transferInput);
        },
        get hasInsufficientBalance() {
            return this.transferCents > this.currentBalanceCents;
        },
        get selectedContact() {
            return this.recipients.find((c) => c.email === this.recipientInput || c.cpf === this.recipientInput) || null;
        },
        get recipientHint() {
            const value = this.recipientInput;
            if (!value) return null;
            if (value.includes('@')) {
                return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)
                    ? { ok: true, text: '✓ E-mail válido' }
                    : { ok: false, warn: true, text: 'Complete o e-mail (ex: nome@dominio.com)' };
            }
            if (/^\d/.test(value)) {
                const digits = value.replace(/\D/g, '').length;
                return digits === 11
                    ? { ok: true, text: '✓ CPF completo' }
                    : { ok: false, text: `CPF: ${digits}/11 dígitos` };
            }
            return null;
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
                    <div class="text-xs font-bold tracking-wide text-white">COBUCCIO</div>
                    <div class="text-xs text-brand-gold">Brasil Card Wallet</div>
                </div>
            </div>

            <nav aria-label="Módulos da Carteira" class="space-y-1.5 text-xs">
                <div class="text-slate-400 uppercase tracking-wider px-2.5 pb-1 font-semibold">Módulos</div>

                @foreach ($navItems as $item)
                    <button
                        type="button"
                        @click="selectTab('{{ $item['key'] }}')"
                        :class="activeTab === '{{ $item['key'] }}' ? 'bg-brand-navy text-brand-gold border border-brand-gold/40' : 'text-slate-300 hover:bg-white/5'"
                        class="w-full text-left px-3 py-2.5 rounded-lg font-medium flex items-center gap-2.5 transition cursor-pointer"
                    >
                        <x-icon :name="$item['icon']" class="w-4 h-4 shrink-0" />
                        <span>{{ $item['label'] }}</span>
                    </button>
                @endforeach
            </nav>
        </div>

        <div class="pt-4 border-t border-white/10 space-y-3 text-xs">
            <div class="p-3 rounded-lg bg-brand-navy/60 border border-white/10 space-y-0.5">
                <div class="font-semibold text-white truncate">{{ $user->name }}</div>
                <div class="text-slate-400 truncate">{{ $user->email }}</div>
                <div class="text-brand-gold font-mono">CPF: {{ $formatCpf($user->cpf) }}</div>
            </div>

            <form method="POST" action="{{ route('auth.logout') }}">
                @csrf
                <button
                    type="submit"
                    class="w-full py-2 px-3 rounded-lg bg-white/5 hover:bg-rose-500/20 text-rose-300 border border-white/10 flex items-center justify-center gap-2 font-medium transition cursor-pointer"
                >
                    <x-icon name="log-out" class="w-3.5 h-3.5" />
                    <span>Sair da conta</span>
                </button>
            </form>
        </div>
    </aside>

    <main class="md:col-span-9 lg:col-span-10 p-6 sm:p-8 space-y-6">
        @if ($isNegative)
            <div role="alert" class="p-4 rounded-xl bg-rose-50 border border-rose-200 flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-start gap-3">
                    <x-icon name="alert-triangle" class="w-5 h-5 text-rose-600 shrink-0 mt-0.5" />
                    <div class="text-xs sm:text-sm">
                        <div class="font-bold text-rose-900">
                            Sua carteira está com saldo negativo ({{ $formattedBalance }})
                        </div>
                        <p class="text-rose-700 mt-0.5">
                            Faça um depósito para regularizar: o valor quita a dívida primeiro e o restante fica disponível.
                        </p>
                    </div>
                </div>

                <button
                    type="button"
                    @click="selectTab('deposit')"
                    class="px-4 py-2 rounded-lg bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold transition cursor-pointer"
                >
                    Depositar agora
                </button>
            </div>
        @endif

        {{-- ===================== Visão Geral ===================== --}}
        <section x-show="activeTab === 'overview'" x-cloak class="space-y-6">
            <header class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 pb-4">
                <div>
                    <h1 class="text-xl font-bold text-brand-navy">Visão Geral</h1>
                    <p class="text-xs sm:text-sm text-slate-600">
                        Olá, {{ $user->name }}. Acompanhe seu saldo e acesse as operações da sua carteira.
                    </p>
                </div>

                <span class="px-3 py-1 rounded-full text-xs font-semibold {{ $isNegative ? 'bg-rose-100 text-rose-800' : 'bg-emerald-100 text-emerald-800' }}">
                    {{ $isNegative ? 'Saldo negativo' : 'Conta regular' }}
                </span>
            </header>

            @include('wallet.partials.flash')

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="p-5 rounded-xl bg-brand-navy text-white border border-brand-gold/40 shadow-md space-y-2">
                    <div class="flex items-center justify-between text-xs text-slate-300">
                        <span>Saldo disponível</span>
                        <button
                            type="button"
                            @click="hideBalance = !hideBalance"
                            :aria-pressed="hideBalance.toString()"
                            class="text-brand-gold hover:underline flex items-center gap-1 cursor-pointer"
                        >
                            <span x-show="hideBalance" class="flex items-center gap-1"><x-icon name="eye" class="w-3.5 h-3.5" /> Mostrar</span>
                            <span x-show="!hideBalance" class="flex items-center gap-1"><x-icon name="eye-off" class="w-3.5 h-3.5" /> Ocultar</span>
                        </button>
                    </div>
                    <div class="text-2xl font-bold font-mono {{ $isNegative ? 'text-rose-400' : 'text-brand-gold' }}">
                        <span x-show="!hideBalance">{{ $formattedBalance }}</span>
                        <span x-show="hideBalance" x-cloak>R$ ••••••</span>
                    </div>
                </div>

                <div class="p-5 rounded-xl bg-white border border-slate-200 shadow-sm space-y-2">
                    <div class="text-xs text-slate-500 font-medium">Total depositado</div>
                    <div class="text-xl font-bold font-mono text-emerald-600">
                        <span x-show="!hideBalance">+ {{ $totalDepositsFormatted }}</span>
                        <span x-show="hideBalance" x-cloak>R$ ••••••</span>
                    </div>
                </div>

                <div class="p-5 rounded-xl bg-white border border-slate-200 shadow-sm space-y-2">
                    <div class="text-xs text-slate-500 font-medium">Total transferido</div>
                    <div class="text-xl font-bold font-mono text-rose-600">
                        <span x-show="!hideBalance">- {{ $totalSentFormatted }}</span>
                        <span x-show="hideBalance" x-cloak>R$ ••••••</span>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                @foreach ($shortcuts as $shortcut)
                    <button
                        type="button"
                        @click="selectTab('{{ $shortcut['key'] }}')"
                        class="p-4 rounded-xl bg-white border border-slate-200 hover:border-brand-navy shadow-sm text-left transition cursor-pointer"
                    >
                        <div class="text-sm font-bold text-brand-navy">{{ $shortcut['title'] }}</div>
                        <div class="text-xs text-slate-500 mt-1">{{ $shortcut['description'] }}</div>
                    </button>
                @endforeach
            </div>

            <div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 space-y-3">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-bold text-brand-navy">Últimas movimentações</h2>
                    <button
                        type="button"
                        @click="selectTab('statement')"
                        class="text-xs text-brand-navy font-semibold hover:underline cursor-pointer"
                    >
                        Ver extrato completo →
                    </button>
                </div>

                @if ($summary['transactions']->isEmpty())
                    <p class="text-xs text-slate-500 py-4 text-center">Nenhuma movimentação ainda.</p>
                @else
                    <div class="divide-y divide-slate-100 text-xs">
                        @foreach ($summary['transactions']->take(4) as $tx)
                            @php
                                $incoming = $isIncoming($tx);
                                $isReversed = $tx->status === 'REVERSED';
                            @endphp
                            <div class="py-2.5 flex items-center justify-between">
                                <div>
                                    <span class="font-semibold {{ $isReversed ? 'line-through text-slate-400' : 'text-slate-800' }}">
                                        {{ $tx->description ?: $tx->type }}
                                    </span>
                                    <span class="text-slate-400 ml-2">{{ $tx->created_at?->format('d/m/Y, H:i') }}</span>
                                </div>
                                <span class="font-mono font-bold {{ $isReversed ? 'line-through text-slate-400' : ($incoming ? 'text-emerald-600' : 'text-rose-600') }}">
                                    <span x-show="!hideBalance">{{ $incoming ? '+' : '-' }} {{ Money::fromBalanceCents($tx->amount_cents)->formatBrl() }}</span>
                                    <span x-show="hideBalance" x-cloak>R$ ••••••</span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>

        {{-- ===================== Depositar ===================== --}}
        <section x-show="activeTab === 'deposit'" x-cloak class="space-y-6">
            <header class="border-b border-slate-200 pb-4">
                <h2 class="text-lg font-bold text-brand-navy">Depositar</h2>
                <p class="text-xs sm:text-sm text-slate-600 mt-0.5">
                    O valor entra na hora. Se o saldo estiver negativo, o depósito quita a dívida primeiro e o restante fica disponível.
                </p>
            </header>

            @include('wallet.partials.flash')

            <form method="POST" action="{{ route('wallet.deposit') }}" class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">

                <div class="lg:col-span-7 bg-white p-5 rounded-xl border border-slate-200 shadow-sm space-y-4">
                    <div>
                        <label for="deposit-amount" class="block text-xs font-semibold text-slate-700 mb-1.5">
                            Valor do depósito (R$)
                        </label>
                        <input
                            id="deposit-amount"
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

                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-xs text-slate-500">Valores rápidos:</span>
                        @foreach (['50,00' => '+ R$ 50', '100,00' => '+ R$ 100', '500,00' => '+ R$ 500'] as $value => $label)
                            <button
                                type="button"
                                @click="depositInput = '{{ $value }}'"
                                class="px-3 py-1 rounded-md bg-slate-100 hover:bg-brand-navy hover:text-brand-gold text-slate-700 text-xs font-medium border border-slate-200 transition cursor-pointer"
                            >
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>

                    <div>
                        <label for="deposit-description" class="block text-xs font-semibold text-slate-700 mb-1.5">
                            Descrição (opcional)
                        </label>
                        <input
                            id="deposit-description"
                            name="description"
                            type="text"
                            maxlength="140"
                            placeholder="Ex: Aporte de capital"
                            class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-slate-900 text-sm focus:outline-none focus:border-brand-navy"
                        >
                    </div>
                </div>

                <div class="lg:col-span-5 bg-brand-navy text-white p-5 rounded-xl border border-brand-gold/40 shadow-md space-y-4">
                    <div class="text-xs uppercase tracking-wider text-brand-gold font-semibold">Simulação do depósito</div>

                    <div class="space-y-2 text-xs border-b border-white/10 pb-3">
                        <div class="flex justify-between">
                            <span class="text-slate-300">Saldo atual</span>
                            <span class="font-mono font-semibold {{ $isNegative ? 'text-rose-400' : 'text-white' }}">{{ $formattedBalance }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-300">Valor do depósito</span>
                            <span class="font-mono font-semibold text-emerald-400" x-text="'+ ' + formatCents(depositCents)">+ R$ 0,00</span>
                        </div>
                    </div>

                    <div>
                        <div class="text-xs text-slate-300">Saldo após o depósito</div>
                        <div
                            class="text-xl font-bold font-mono mt-0.5"
                            :class="currentBalanceCents + depositCents < 0 ? 'text-rose-400' : 'text-brand-gold'"
                            x-text="formatCents(currentBalanceCents + depositCents)"
                        >{{ $formattedBalance }}</div>
                    </div>

                    <button
                        type="submit"
                        :disabled="depositCents <= 0"
                        class="w-full py-2.5 px-4 rounded-lg bg-brand-gold hover:bg-brand-goldLight disabled:opacity-60 disabled:cursor-not-allowed text-brand-dark font-bold text-xs sm:text-sm flex items-center justify-center gap-2 transition cursor-pointer"
                    >
                        <x-icon name="plus-circle" class="w-4 h-4" />
                        <span>Confirmar depósito</span>
                    </button>
                </div>
            </form>
        </section>

        {{-- ===================== Transferir ===================== --}}
        <section x-show="activeTab === 'transfer'" x-cloak class="space-y-6">
            <header class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 pb-4">
                <div>
                    <h2 class="text-lg font-bold text-brand-navy">Transferir</h2>
                    <p class="text-xs sm:text-sm text-slate-600 mt-0.5">
                        Envie dinheiro para outra conta informando o e-mail ou o CPF do destinatário.
                    </p>
                </div>

                <div class="px-3.5 py-2 rounded-lg bg-brand-navy text-white border border-brand-gold/40 text-xs">
                    <span class="text-slate-300 mr-2">Saldo disponível:</span>
                    <span class="font-mono font-bold {{ $isNegative ? 'text-rose-400' : 'text-brand-gold' }}">{{ $formattedBalance }}</span>
                </div>
            </header>

            @include('wallet.partials.flash')

            <form method="POST" action="{{ route('wallet.transfer') }}" class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">

                <div class="lg:col-span-7 space-y-6">
                    <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm space-y-4">
                        <div>
                            <label for="recipientIdentifier" class="block text-xs font-semibold text-slate-700 mb-1.5">
                                Destinatário (e-mail ou CPF)
                            </label>
                            <input
                                id="recipientIdentifier"
                                name="recipient"
                                type="text"
                                required
                                x-model="recipientInput"
                                @input="recipientInput = window.WalletFormatters.formatRecipientInput($event.target.value)"
                                placeholder="Ex: bruno@cobuccio.com ou 000.000.000-00"
                                class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-slate-900 text-sm focus:outline-none focus:border-brand-navy"
                            >
                            <div class="mt-1.5 flex flex-wrap items-center justify-between gap-1 text-[11px]">
                                <span class="text-slate-500">Ou escolha um dos seus contatos abaixo.</span>
                                <template x-if="recipientHint">
                                    <span
                                        :class="recipientHint.ok ? 'text-emerald-600 font-semibold' : (recipientHint.warn ? 'text-amber-600 font-semibold' : 'text-slate-500 font-medium')"
                                        x-text="recipientHint.text"
                                    ></span>
                                </template>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label for="transfer-amount" class="block text-xs font-semibold text-slate-700 mb-1.5">
                                    Valor da transferência (R$)
                                </label>
                                <input
                                    id="transfer-amount"
                                    name="amount"
                                    type="text"
                                    inputmode="numeric"
                                    required
                                    x-model="transferInput"
                                    @input="transferInput = window.WalletFormatters.formatCurrencyInput($event.target.value)"
                                    placeholder="0,00"
                                    class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-slate-900 font-mono text-sm focus:outline-none focus:border-brand-navy"
                                >
                            </div>

                            <div>
                                <label for="transfer-description" class="block text-xs font-semibold text-slate-700 mb-1.5">
                                    Descrição (opcional)
                                </label>
                                <input
                                    id="transfer-description"
                                    name="description"
                                    type="text"
                                    maxlength="140"
                                    placeholder="Ex: Pagamento de serviço"
                                    class="w-full px-3.5 py-2.5 rounded-lg border border-slate-300 text-slate-900 text-sm focus:outline-none focus:border-brand-navy"
                                >
                            </div>
                        </div>
                    </div>

                    <div class="bg-white p-5 rounded-xl border border-slate-200 shadow-sm space-y-3">
                        <div class="flex items-center gap-2 text-xs font-bold text-brand-navy uppercase tracking-wider">
                            <x-icon name="user-check" class="w-4 h-4 text-brand-gold" />
                            <span>Seus contatos</span>
                        </div>

                        @if ($summary['recipients']->isEmpty())
                            <p class="text-xs text-slate-500">Ainda não há outros usuários cadastrados.</p>
                        @else
                            <div class="space-y-2 max-h-60 overflow-y-auto pr-1">
                                @foreach ($summary['recipients'] as $participant)
                                    <button
                                        type="button"
                                        @click="recipientInput = @js($participant->email)"
                                        :aria-pressed="(selectedContact?.email === @js($participant->email)).toString()"
                                        :class="selectedContact?.email === @js($participant->email) ? 'border-brand-navy bg-slate-50' : 'border-slate-200 hover:border-brand-navy hover:bg-slate-50'"
                                        class="w-full text-left p-2.5 rounded-lg border transition flex items-center justify-between text-xs cursor-pointer"
                                    >
                                        <div>
                                            <div class="font-semibold text-slate-800">{{ $participant->name }}</div>
                                            <div class="text-slate-500">{{ $participant->email }}</div>
                                        </div>
                                        <span class="font-mono text-slate-400">{{ $formatCpf($participant->cpf) }}</span>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>

                <div class="lg:col-span-5 bg-brand-navy text-white p-5 rounded-xl border border-brand-gold/40 shadow-md space-y-4">
                    <div class="text-xs uppercase tracking-wider text-brand-gold font-semibold">Resumo da transferência</div>

                    <div class="space-y-2 text-xs border-b border-white/10 pb-3">
                        <div class="flex justify-between gap-3">
                            <span class="text-slate-300">Para</span>
                            <span class="font-semibold text-white truncate" x-text="selectedContact?.name || recipientInput || '—'">—</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-300">Saldo disponível</span>
                            <span class="font-mono font-semibold {{ $isNegative ? 'text-rose-400' : 'text-white' }}">{{ $formattedBalance }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-300">Valor da transferência</span>
                            <span class="font-mono font-semibold text-rose-400" x-text="'- ' + formatCents(transferCents)">- R$ 0,00</span>
                        </div>
                    </div>

                    <div>
                        <div class="text-xs text-slate-300">Saldo após a transferência</div>
                        <div
                            class="text-xl font-bold font-mono mt-0.5"
                            :class="currentBalanceCents - transferCents < 0 ? 'text-rose-400' : 'text-brand-gold'"
                            x-text="formatCents(currentBalanceCents - transferCents)"
                        >{{ $formattedBalance }}</div>
                        <p x-show="hasInsufficientBalance" x-cloak class="text-xs text-rose-300 mt-1">
                            Saldo insuficiente para este valor.
                        </p>
                    </div>

                    <button
                        type="submit"
                        :disabled="transferCents <= 0 || hasInsufficientBalance"
                        class="w-full py-2.5 px-4 rounded-lg bg-brand-gold hover:bg-brand-goldLight disabled:opacity-60 disabled:cursor-not-allowed text-brand-dark font-bold text-xs sm:text-sm flex items-center justify-center gap-2 transition cursor-pointer"
                    >
                        <x-icon name="send" class="w-4 h-4" />
                        <span>Confirmar transferência</span>
                    </button>
                </div>
            </form>
        </section>

        {{-- ===================== Extrato & Estornos ===================== --}}
        <section x-show="activeTab === 'statement'" x-cloak class="space-y-6">
            <header class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 pb-4">
                <div>
                    <h2 class="text-lg font-bold text-brand-navy">Extrato & Estornos</h2>
                    <p class="text-xs sm:text-sm text-slate-600 mt-0.5">
                        Histórico completo das suas operações. Um estorno não apaga nada: ele cria um novo lançamento ligado à operação original.
                    </p>
                    <p class="text-xs text-slate-500 mt-1">
                        Prazos: quem enviou pode estornar em até 15 minutos; quem recebeu e depósitos, em até 24 horas.
                    </p>
                </div>

                <div class="flex flex-wrap gap-1.5 text-xs">
                    @foreach ($filters as $filter)
                        <button
                            type="button"
                            @click="filterType = '{{ $filter['key'] }}'"
                            :aria-pressed="(filterType === '{{ $filter['key'] }}').toString()"
                            :class="filterType === '{{ $filter['key'] }}' ? 'bg-brand-navy text-brand-gold border border-brand-gold/40' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-100'"
                            class="px-3 py-1.5 rounded-lg font-semibold transition cursor-pointer"
                        >
                            {{ $filter['label'] }}
                        </button>
                    @endforeach
                </div>
            </header>

            @include('wallet.partials.flash')

            <div class="bg-white rounded-xl border border-slate-200 shadow-sm divide-y divide-slate-100">
                @forelse ($summary['transactions'] as $tx)
                    @php
                        $incoming = $isIncoming($tx);
                        $isReversed = $tx->status === 'REVERSED';
                        $isReversalEntry = $tx->type === 'REVERSAL';
                        $reversalState = TransactionDomainRules::reversalState(
                            type: $tx->type,
                            status: $tx->status,
                            actorWalletId: $walletId,
                            senderWalletId: $tx->sender_wallet_id === null ? null : (int) $tx->sender_wallet_id,
                            receiverWalletId: (int) $tx->receiver_wallet_id,
                            createdAt: $tx->created_at,
                        );

                        $counterpart = match (true) {
                            $tx->type === 'DEPOSIT' => 'Depósito em conta',
                            $isReversalEntry => 'Estorno da operação #'.$tx->original_transaction_id,
                            $incoming => 'Recebido de '.($tx->senderWallet?->user?->name ?? 'Usuário'),
                            default => 'Enviado para '.($tx->receiverWallet?->user?->name ?? 'Usuário'),
                        };
                    @endphp

                    <div x-show="filterType === 'ALL' || filterType === '{{ $tx->type }}'" class="p-4 space-y-3">
                        <div class="flex flex-wrap items-center justify-between gap-4">
                            <div class="flex items-start gap-3">
                                <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0 {{ $isReversalEntry || $isReversed ? 'bg-amber-100 text-amber-700' : ($incoming ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700') }}">
                                    <x-icon :name="$isReversalEntry ? 'rotate-ccw' : ($incoming ? 'arrow-down-left' : 'arrow-up-right')" class="w-4 h-4" />
                                </div>

                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-sm font-semibold {{ $isReversed ? 'line-through text-slate-400' : 'text-slate-900' }}">
                                            {{ $tx->description ?: $tx->type }}
                                        </span>
                                        @if ($isReversalEntry)
                                            <span class="px-2 py-0.5 rounded bg-sky-100 text-sky-800 text-[11px] font-semibold">Estorno</span>
                                        @elseif ($isReversed)
                                            <span class="px-2 py-0.5 rounded bg-amber-100 text-amber-800 text-[11px] font-semibold">Estornada</span>
                                        @else
                                            <span class="px-2 py-0.5 rounded bg-emerald-100 text-emerald-800 text-[11px] font-semibold">Concluída</span>
                                        @endif
                                    </div>
                                    <div class="text-xs text-slate-500 flex flex-wrap items-center gap-2 mt-0.5">
                                        <span class="font-mono text-slate-400">#{{ $tx->id }}</span>
                                        <span>•</span>
                                        <span>{{ $tx->created_at?->format('d/m/Y, H:i') }}</span>
                                        <span>•</span>
                                        <span>{{ $counterpart }}</span>
                                    </div>
                                    @if ($tx->reversal_reason)
                                        <div class="text-xs text-amber-700 mt-1">Motivo: {{ $tx->reversal_reason }}</div>
                                    @endif
                                </div>
                            </div>

                            <div class="flex items-center gap-3">
                                <span class="font-mono text-sm font-bold {{ $isReversed ? 'line-through text-slate-400' : ($incoming ? 'text-emerald-600' : 'text-rose-600') }}">
                                    {{ $incoming ? '+' : '-' }} {{ Money::fromBalanceCents($tx->amount_cents)->formatBrl() }}
                                </span>

                                @if ($reversalState === ReversalState::AVAILABLE)
                                    <button
                                        type="button"
                                        @click="reversingTxId = (reversingTxId === {{ $tx->id }} ? null : {{ $tx->id }})"
                                        x-text="reversingTxId === {{ $tx->id }} ? 'Cancelar' : 'Estornar'"
                                        class="px-3 py-1 rounded-md border border-amber-300 bg-amber-50 hover:bg-amber-100 text-amber-800 text-xs font-semibold transition cursor-pointer"
                                    >Estornar</button>
                                @elseif ($reversalState === ReversalState::EXPIRED)
                                    <span class="px-2.5 py-1 rounded-md bg-slate-100 text-slate-500 text-[11px] font-medium">
                                        Prazo de estorno expirado
                                    </span>
                                @endif
                            </div>
                        </div>

                        @if ($reversalState === ReversalState::AVAILABLE)
                            <form
                                x-show="reversingTxId === {{ $tx->id }}"
                                x-cloak
                                method="POST"
                                action="{{ route('wallet.reverse', ['transaction' => $tx->id]) }}"
                                class="p-3 rounded-lg bg-slate-50 border border-slate-200 flex flex-wrap items-center justify-between gap-3 text-xs"
                            >
                                @csrf
                                <label for="reason-{{ $tx->id }}" class="font-semibold text-slate-700">Motivo do estorno (opcional):</label>
                                <input
                                    id="reason-{{ $tx->id }}"
                                    type="text"
                                    name="reason"
                                    maxlength="200"
                                    placeholder="Ex: Valor digitado errado"
                                    class="flex-1 min-w-[200px] px-3 py-1.5 rounded border border-slate-300 bg-white text-slate-900 focus:outline-none focus:border-brand-navy"
                                >
                                <button
                                    type="submit"
                                    class="px-4 py-1.5 rounded bg-brand-navy text-brand-gold font-semibold hover:bg-brand-dark transition cursor-pointer"
                                >
                                    Confirmar estorno
                                </button>
                            </form>
                        @endif
                    </div>
                @empty
                    <div class="p-8 text-center text-sm text-slate-500">Nenhuma movimentação ainda.</div>
                @endforelse

                @if ($summary['transactions']->isNotEmpty())
                    <div x-show="filterType !== 'ALL' && !typeCounts[filterType]" x-cloak class="p-8 text-center text-sm text-slate-500">
                        Nenhuma movimentação para este filtro.
                    </div>
                @endif
            </div>
        </section>
    </main>
</div>
@endsection
