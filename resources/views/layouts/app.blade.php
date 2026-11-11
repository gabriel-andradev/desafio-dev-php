<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Cobuccio Wallet | Carteira Financeira Digital</title>
    <style>[x-cloak] { display: none !important; }</style>

    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        {{-- Inter, self-hosted by Vite: the same typeface the Node version loads via next/font. --}}
        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        <script src="https://cdn.tailwindcss.com"></script>
        <script>
            tailwind.config = {
                theme: {
                    extend: {
                        colors: {
                            brand: {
                                navy: '#06264E',
                                dark: '#041A36',
                                gold: '#D4AD50',
                                goldLight: '#E5C678',
                            }
                        }
                    }
                }
            };
        </script>
    @endif

    <script>
        window.WalletFormatters = window.WalletFormatters || {
            formatCpf(value) {
                const digits = String(value || '').replace(/\D/g, '').slice(0, 11);
                if (digits.length <= 3) return digits;
                if (digits.length <= 6) return `${digits.slice(0, 3)}.${digits.slice(3)}`;
                if (digits.length <= 9) {
                    return `${digits.slice(0, 3)}.${digits.slice(3, 6)}.${digits.slice(6)}`;
                }
                return `${digits.slice(0, 3)}.${digits.slice(3, 6)}.${digits.slice(6, 9)}-${digits.slice(9, 11)}`;
            },
            formatCurrencyInput(value) {
                const digits = String(value || '').replace(/\D/g, '').slice(0, 11);
                if (!digits) return '';
                const cents = parseInt(digits, 10);
                if (Number.isNaN(cents) || cents <= 0) return '';
                const reais = Math.floor(cents / 100);
                const fraction = String(cents % 100).padStart(2, '0');
                return `${reais.toLocaleString('pt-BR')},${fraction}`;
            },
            parseCurrencyToCents(value) {
                const digits = String(value || '').replace(/\D/g, '');
                if (!digits) return 0;
                const parsed = parseInt(digits, 10);
                return Number.isNaN(parsed) || parsed <= 0 ? 0 : parsed;
            },
            formatCurrencyFromCents(cents) {
                const numeric = Number(cents) || 0;
                const prefix = numeric < 0 ? '-R$ ' : 'R$ ';
                const abs = Math.abs(numeric);
                const reais = Math.floor(abs / 100).toLocaleString('pt-BR');
                const fraction = String(abs % 100).padStart(2, '0');
                return `${prefix}${reais},${fraction}`;
            },
            formatRecipientInput(value) {
                const raw = String(value || '');
                if (/^[\d.\-\s]+$/.test(raw) && /\d/.test(raw)) {
                    return this.formatCpf(raw);
                }
                return raw;
            }
        };
    </script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js"></script>
</head>
<body class="antialiased bg-slate-100 text-slate-900">
    @yield('content')
</body>
</html>

