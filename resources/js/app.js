import './bootstrap';
import Chart from 'chart.js/auto';
import pos from './pos';

window.Chart = Chart;

/*
 |--------------------------------------------------------------------------
 | Chart defaults — match the KaffeTrack palette and typography
 |--------------------------------------------------------------------------
 */
Chart.defaults.font.family = "'Instrument Sans Variable', 'Instrument Sans', ui-sans-serif, system-ui, sans-serif";
Chart.defaults.font.size = 12;
Chart.defaults.color = '#6b7d71';
Chart.defaults.borderColor = '#e4ebe5';
Chart.defaults.plugins.legend.labels.boxWidth = 10;
Chart.defaults.plugins.legend.labels.boxHeight = 10;
Chart.defaults.plugins.legend.labels.useBorderRadius = true;
Chart.defaults.plugins.legend.labels.borderRadius = 3;
Chart.defaults.plugins.tooltip.backgroundColor = '#173a2c';
Chart.defaults.plugins.tooltip.padding = 10;
Chart.defaults.plugins.tooltip.cornerRadius = 8;
Chart.defaults.plugins.tooltip.titleFont = { weight: '600' };
Chart.defaults.animation.duration = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 600;

window.KT = {
    palette: ['#2f6b4f', '#b57a3e', '#63a079', '#2e6a8e', '#a3660f', '#98c1a3', '#87572a', '#6b7d71'],
    peso: (v) => '₱' + Number(v || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
    pesoShort: (v) => {
        v = Number(v || 0);
        if (Math.abs(v) >= 1_000_000) return '₱' + (v / 1_000_000).toFixed(1) + 'M';
        if (Math.abs(v) >= 1_000) return '₱' + (v / 1_000).toFixed(1) + 'k';
        return '₱' + v.toFixed(0);
    },
};

/*
 |--------------------------------------------------------------------------
 | Alpine stores: toasts + confirmation dialog
 |--------------------------------------------------------------------------
 | Livewire ships Alpine, so we register on `alpine:init`.
 |   Toast from PHP:   $this->dispatch('toast', type: 'success', message: 'Saved.');
 |   Confirm in Blade: x-on:click="$confirm({ title, message, confirmText, tone }, () => $wire.archive(1))"
 |   Confirm + reason: $confirm({ ..., input: { label: 'Reason', required: true } }, (reason) => $wire.cancel(1, reason))
 */
document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    Alpine.store('toasts', {
        items: [],
        // action: { label: 'Undo', handler: () => … } renders a button inside the toast.
        push({ type = 'success', message = '', title = null, timeout = null, action = null }) {
            const id = Date.now() + Math.random();
            this.items.push({ id, type, message, title, action });
            // Errors stay longer so they can actually be read (WCAG 2.2.1 timing adjustable).
            setTimeout(() => this.remove(id), timeout ?? (type === 'error' ? 8000 : 4500));
        },
        remove(id) {
            this.items = this.items.filter((t) => t.id !== id);
        },
    });

    Alpine.store('confirm', {
        open: false,
        title: '',
        message: '',
        confirmText: 'Confirm',
        cancelText: 'Cancel',
        tone: 'primary',
        input: null,
        value: '',
        error: '',
        callback: null,
        ask(options, callback) {
            Object.assign(this, {
                title: options.title ?? 'Are you sure?',
                message: options.message ?? '',
                confirmText: options.confirmText ?? 'Confirm',
                cancelText: options.cancelText ?? 'Cancel',
                tone: options.tone ?? 'primary',
                input: options.input ?? null,
                value: options.input?.value ?? '',
                error: '',
                callback,
            });
            this.open = true;
        },
        accept() {
            if (this.input?.required && !String(this.value).trim()) {
                this.error = `${this.input.label ?? 'This field'} is required.`;
                return;
            }
            const cb = this.callback;
            const value = this.value;
            this.close();
            cb && cb(value);
        },
        close() {
            this.open = false;
            this.callback = null;
        },
    });

    /*
     | Chart wrapper: <div x-data="chart(@js($config))"><canvas x-ref="canvas"></canvas></div>
     | Config is plain Chart.js JSON built in PHP; `money: 'y'` (or 'x') formats that axis + tooltips in pesos.
     | Give the element a wire:key that changes with the data so Livewire swaps it and the chart rebuilds.
     */
    Alpine.data('pos', pos);

    Alpine.data('chart', (config) => ({
        chart: null,
        init() {
            const cfg = structuredClone(config);
            const moneyAxis = cfg.money;
            delete cfg.money;
            cfg.options ??= {};
            cfg.options.maintainAspectRatio = false;
            cfg.options.responsive = true;
            if (moneyAxis) {
                cfg.options.scales ??= {};
                cfg.options.scales[moneyAxis] ??= {};
                cfg.options.scales[moneyAxis].ticks = { ...(cfg.options.scales[moneyAxis].ticks ?? {}), callback: (v) => window.KT.pesoShort(v) };
                cfg.options.plugins ??= {};
                cfg.options.plugins.tooltip = {
                    ...(cfg.options.plugins.tooltip ?? {}),
                    callbacks: {
                        label: (ctx) => {
                            const isMoney = ctx.dataset.money !== false && (ctx.dataset.yAxisID ?? moneyAxis) === moneyAxis;
                            const raw = ctx.parsed[moneyAxis === 'x' ? 'x' : 'y'] ?? ctx.parsed;
                            return ` ${ctx.dataset.label ?? ctx.label}: ${isMoney ? window.KT.peso(raw) : raw}`;
                        },
                    },
                };
            }
            if (cfg.type === 'doughnut' && cfg.peso) {
                delete cfg.peso;
                cfg.options.plugins ??= {};
                cfg.options.plugins.tooltip = { callbacks: { label: (ctx) => ` ${ctx.label}: ${window.KT.peso(ctx.parsed)}` } };
            }
            this.chart = new window.Chart(this.$refs.canvas, cfg);
        },
        destroy() {
            this.chart?.destroy();
        },
    }));

    Alpine.magic('confirm', () => (options, callback) => Alpine.store('confirm').ask(options, callback));
    Alpine.magic('toast', () => (message, type = 'success') => Alpine.store('toasts').push({ message, type }));
});

// Livewire `dispatch('toast', …)` arrives as a browser event.
window.addEventListener('toast', (e) => {
    const detail = Array.isArray(e.detail) ? e.detail[0] : e.detail;
    window.Alpine?.store('toasts').push(detail);
});

/*
 |--------------------------------------------------------------------------
 | Global keyboard shortcuts (shown in the help popover in the top bar)
 |--------------------------------------------------------------------------
 |  /   focus the page's search box
 */
document.addEventListener('keydown', (e) => {
    const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName) || document.activeElement?.isContentEditable;
    if (e.key === '/' && !typing) {
        const search = document.querySelector('[data-search]');
        if (search) {
            e.preventDefault();
            search.focus();
            search.select?.();
        }
    }
});
