/**
 * Client-side currency and CPF formatting utilities for the banking interface.
 */
window.WalletFormatters = {
    /**
     * Formats a raw string into a progressive Brazilian CPF mask (000.000.000-00).
     */
    formatCpf(value) {
        const digits = String(value || '').replace(/\D/g, '').slice(0, 11);
        if (digits.length <= 3) return digits;
        if (digits.length <= 6) return `${digits.slice(0, 3)}.${digits.slice(3)}`;
        if (digits.length <= 9) {
            return `${digits.slice(0, 3)}.${digits.slice(3, 6)}.${digits.slice(6)}`;
        }
        return `${digits.slice(0, 3)}.${digits.slice(3, 6)}.${digits.slice(6, 9)}-${digits.slice(9, 11)}`;
    },

    /**
     * Formats raw numeric input into a cent-based BRL string (e.g., "1.450,00").
     */
    formatCurrencyInput(value) {
        const digits = String(value || '').replace(/\D/g, '').slice(0, 11);
        if (!digits) return '';
        const cents = parseInt(digits, 10);
        if (Number.isNaN(cents) || cents <= 0) return '';
        const reais = Math.floor(cents / 100);
        const fraction = String(cents % 100).padStart(2, '0');
        const formattedReais = reais.toLocaleString('pt-BR');
        return `${formattedReais},${fraction}`;
    },

    /**
     * Converts a formatted BRL string ("1.450,00") into integer cents (145000).
     */
    parseCurrencyToCents(value) {
        const digits = String(value || '').replace(/\D/g, '');
        if (!digits) return 0;
        const parsed = parseInt(digits, 10);
        return Number.isNaN(parsed) || parsed <= 0 ? 0 : parsed;
    },

    /**
     * Formats an integer cent value into a full BRL currency label ("R$ 1.450,00" or "-R$ 250,00").
     */
    formatCurrencyFromCents(cents) {
        const numeric = Number(cents) || 0;
        const prefix = numeric < 0 ? '-R$ ' : 'R$ ';
        const abs = Math.abs(numeric);
        const reais = Math.floor(abs / 100).toLocaleString('pt-BR');
        const fraction = String(abs % 100).padStart(2, '0');
        return `${prefix}${reais},${fraction}`;
    },

    /**
     * Formats recipient input as CPF when strictly numeric, or preserves email input as-is.
     */
    formatRecipientInput(value) {
        const raw = String(value || '');
        if (/^[\d.\-\s]+$/.test(raw) && /\d/.test(raw)) {
            return this.formatCpf(raw);
        }
        return raw;
    },
};
