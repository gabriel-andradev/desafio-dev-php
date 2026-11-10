@extends('layouts.app')

@section('title', 'Cadastrar • Grupo Adriano Cobuccio')

@section('content')
<main
    x-data="{ cpf: '{{ old('cpf', '') }}' }"
    class="min-h-screen bg-brand-dark text-white flex flex-col justify-center items-center px-4 py-10"
>
    <div class="w-full max-w-lg rounded-2xl bg-brand-navy/90 border border-brand-gold/30 shadow-2xl p-6 sm:p-8 space-y-6">
        <header class="flex flex-wrap items-center justify-between gap-4 border-b border-white/10 pb-5">
            <div class="flex items-center gap-3">
                <div class="w-11 h-11 rounded-xl bg-brand-dark border border-brand-gold flex items-center justify-center font-serif font-bold text-brand-gold text-base shadow-inner">
                    AC
                </div>
                <div>
                    <h1 class="text-sm sm:text-base font-bold tracking-wide text-white">
                        GRUPO ADRIANO COBUCCIO
                    </h1>
                    <p class="text-xs text-brand-gold font-medium">
                        Brasil Card • Internet Banking
                    </p>
                </div>
            </div>

            <nav aria-label="Alternar modo de autenticação" class="flex rounded-lg bg-brand-dark p-1 border border-white/10 text-xs">
                <a
                    href="{{ route('login') }}"
                    class="px-3 py-1.5 rounded-md font-semibold transition text-slate-300 hover:text-white"
                >
                    Entrar
                </a>
                <a
                    href="{{ route('register') }}"
                    class="px-3 py-1.5 rounded-md font-semibold transition bg-brand-gold text-brand-dark"
                >
                    Cadastrar
                </a>
            </nav>
        </header>

        <div class="space-y-1">
            <h2 class="text-lg font-bold text-white">
                Abra sua Conta Financeira
            </h2>
            <p class="text-xs text-slate-300">
                Preencha seus dados para criar sua carteira digital com proteção transacional ACID.
            </p>
        </div>

        @if ($errors->any())
            <div role="alert" class="flex items-center gap-2.5 p-3 rounded-lg bg-rose-500/15 border border-rose-500/40 text-rose-200 text-xs">
                <svg class="w-4 h-4 shrink-0 text-rose-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
                <span>{{ $errors->first() }}</span>
            </div>
        @endif

        <form method="POST" action="{{ route('auth.register') }}" class="space-y-4 text-xs sm:text-sm">
            @csrf

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="name" class="block text-xs font-medium text-slate-300 mb-1.5">
                        Nome Completo
                    </label>
                    <input
                        id="name"
                        name="name"
                        type="text"
                        required
                        minlength="3"
                        value="{{ old('name') }}"
                        placeholder="Ex: Gabriel Silva"
                        class="w-full px-3.5 py-2.5 rounded-lg bg-brand-dark border border-white/15 text-white placeholder-slate-500 focus:outline-none focus:border-brand-gold transition"
                    >
                </div>

                <div>
                    <label for="cpf" class="block text-xs font-medium text-slate-300 mb-1.5">
                        CPF
                    </label>
                    <input
                        id="cpf"
                        name="cpf"
                        type="text"
                        inputmode="numeric"
                        required
                        maxlength="14"
                        x-model="cpf"
                        @input="cpf = window.WalletFormatters.formatCpf($event.target.value)"
                        placeholder="000.000.000-00"
                        class="w-full px-3.5 py-2.5 rounded-lg bg-brand-dark border border-white/15 text-white font-mono placeholder-slate-500 focus:outline-none focus:border-brand-gold transition"
                    >
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="email" class="block text-xs font-medium text-slate-300 mb-1.5">
                        E-mail de Acesso
                    </label>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        required
                        value="{{ old('email') }}"
                        placeholder="voce@cobuccio.com"
                        class="w-full px-3.5 py-2.5 rounded-lg bg-brand-dark border border-white/15 text-white placeholder-slate-500 focus:outline-none focus:border-brand-gold transition"
                    >
                </div>

                <div>
                    <label for="password" class="block text-xs font-medium text-slate-300 mb-1.5">
                        Senha (mín. 8 caracteres)
                    </label>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        required
                        minlength="8"
                        placeholder="••••••••"
                        class="w-full px-3.5 py-2.5 rounded-lg bg-brand-dark border border-white/15 text-white placeholder-slate-500 focus:outline-none focus:border-brand-gold transition"
                    >
                </div>
            </div>

            <button
                type="submit"
                class="w-full py-3 px-4 rounded-lg bg-brand-gold hover:bg-brand-goldLight text-brand-dark font-bold flex items-center justify-center gap-2 transition shadow-lg cursor-pointer"
            >
                <span>Criar Conta e Carteira Digital</span>
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-7-7 7 7-7 7" />
                </svg>
            </button>
        </form>

        <footer class="pt-4 border-t border-white/10 flex items-center justify-between text-xs text-slate-400">
            <span class="flex items-center gap-1.5">
                <svg class="w-4 h-4 text-brand-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
                </svg>
                Sessão protegida via Cookie HttpOnly + CSRF
            </span>
            <a href="{{ route('login') }}" class="text-brand-gold hover:underline font-medium">
                Já tenho conta
            </a>
        </footer>
    </div>
</main>
@endsection

