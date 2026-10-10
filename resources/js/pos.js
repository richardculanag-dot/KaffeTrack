/*
 |--------------------------------------------------------------------------
 | POS cart (Alpine component)
 |--------------------------------------------------------------------------
 | The cart is client-side for instant feedback; the server recomputes everything on checkout.
 | computeTotals() mirrors App\Services\OrderPricing so the cashier sees exactly what will be charged.
 | The cart is saved to localStorage so an accidental refresh doesn't lose an order (error recovery).
 */
const STORAGE_KEY = 'kaffetrack.pos.cart.v1';

export default (catalog, discounts, vat, providers, maxTable = 10) => ({
    catalog,
    discounts,
    vat,
    providers,
    maxTable, // App\Models\Order::tableCount() — number of dine-in tables

    activeCategory: catalog[0]?.id ?? null,
    search: '',

    cart: [],
    meta: { order_type: 'takeout', customer_name: '', table_number: '', notes: '', discount_id: '', discount_id_number: '' },

    // Product customiser
    picker: { open: false, product: null, variantId: null, addonIds: [], quantity: 1, notes: '', editIndex: null },

    // Payment
    pay: { open: false, method: 'cash', tendered: '', provider: '', reference: '', busy: false, error: '' },
    cartOpen: false, // small screens
    result: null, // success screen

    init() {
        try {
            const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
            if (saved?.cart?.length) {
                this.cart = saved.cart;
                this.meta = { ...this.meta, ...saved.meta };
                this.$nextTick(() => this.$store.toasts.push({ type: 'info', message: 'Restored the order you were working on.' }));
            }
        } catch (e) { /* storage unavailable — fine */ }

        this.$watch('cart', () => this.persist());
        this.$watch('meta', () => this.persist());

        window.addEventListener('beforeunload', (e) => {
            if (this.cart.length && !this.result) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    },

    persist() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({ cart: this.cart, meta: this.meta }));
        } catch (e) { /* ignore */ }
    },

    /* ── Catalogue ─────────────────────────────────────────── */
    get visibleProducts() {
        const q = this.search.trim().toLowerCase();
        if (q) {
            return this.catalog.flatMap((c) => c.products).filter((p) => p.name.toLowerCase().includes(q));
        }
        return this.catalog.find((c) => c.id === this.activeCategory)?.products ?? [];
    },

    priceLabel(p) {
        const prices = p.variants.map((v) => v.price);
        const min = Math.min(...prices);
        const max = Math.max(...prices);
        return min === max ? this.peso(min) : `${this.peso(min)} – ${this.peso(max)}`;
    },

    inCartCount(productId) {
        return this.cart.filter((l) => l.product_id === productId).reduce((n, l) => n + l.quantity, 0);
    },

    /* ── Adding items ─────────────────────────────────────── */
    choose(product) {
        // Fast path: one size, no add-ons → straight into the cart.
        if (product.variants.length === 1 && product.addons.length === 0) {
            this.addLine(product, product.variants[0], [], 1, '');
            return;
        }
        this.picker = { open: true, product, variantId: product.variants[0].id, addonIds: [], quantity: 1, notes: '', editIndex: null };
    },

    editLine(index) {
        const line = this.cart[index];
        const product = this.catalog.flatMap((c) => c.products).find((p) => p.id === line.product_id);
        if (!product) return;
        this.picker = { open: true, product, variantId: line.variant_id, addonIds: [...line.addon_ids], quantity: line.quantity, notes: line.notes, editIndex: index };
    },

    get pickerVariant() {
        return this.picker.product?.variants.find((v) => v.id === this.picker.variantId);
    },

    get pickerTotal() {
        if (!this.picker.product) return 0;
        const addons = this.picker.product.addons.filter((a) => this.picker.addonIds.includes(a.id)).reduce((s, a) => s + a.price, 0);
        return ((this.pickerVariant?.price ?? 0) + addons) * this.picker.quantity;
    },

    toggleAddon(id) {
        const i = this.picker.addonIds.indexOf(id);
        i === -1 ? this.picker.addonIds.push(id) : this.picker.addonIds.splice(i, 1);
    },

    confirmPicker() {
        const p = this.picker.product;
        const addons = p.addons.filter((a) => this.picker.addonIds.includes(a.id));
        if (this.picker.editIndex !== null) {
            this.cart.splice(this.picker.editIndex, 1);
        }
        this.addLine(p, this.pickerVariant, addons, this.picker.quantity, this.picker.notes, this.picker.editIndex);
        this.picker.open = false;
    },

    addLine(product, variant, addons, quantity, notes, at = null) {
        const addonIds = addons.map((a) => a.id).sort((a, b) => a - b);
        const key = `${variant.id}|${addonIds.join(',')}|${(notes || '').trim().toLowerCase()}`;
        const existing = this.cart.findIndex((l) => l.key === key);

        if (existing !== -1 && at === null) {
            this.cart[existing].quantity = Math.min(99, this.cart[existing].quantity + quantity);
            this.flash(existing);
        } else {
            const line = {
                key,
                product_id: product.id,
                variant_id: variant.id,
                name: product.name,
                variant: variant.name,
                unit_price: variant.price,
                addon_ids: addonIds,
                addon_names: addons.map((a) => a.name),
                addons_total: addons.reduce((s, a) => s + a.price, 0),
                quantity,
                notes: (notes || '').trim(),
                flash: 0,
            };
            at === null ? this.cart.push(line) : this.cart.splice(at, 0, line);
            this.flash(at ?? this.cart.length - 1);
        }
    },

    flash(index) {
        // Brief highlight so the cashier sees which line changed.
        this.cart[index].flash++;
        this.$nextTick(() => document.getElementById('cart-end')?.scrollIntoView({ block: 'nearest', behavior: 'smooth' }));
    },

    inc(i) { if (this.cart[i].quantity < 99) this.cart[i].quantity++; },
    dec(i) {
        if (this.cart[i].quantity > 1) {
            this.cart[i].quantity--;
        } else {
            this.removeLine(i);
        }
    },
    removeLine(i) {
        const [removed] = this.cart.splice(i, 1);
        // Undo instead of a confirmation dialog for a cheap, reversible action.
        this.lastRemoved = { line: removed, index: i };
        this.$store.toasts.push({ type: 'info', message: `Removed ${removed.name}.`, action: { label: 'Undo', handler: () => this.undoRemove() } });
    },
    undoRemove() {
        if (!this.lastRemoved) return;
        this.cart.splice(this.lastRemoved.index, 0, this.lastRemoved.line);
        this.lastRemoved = null;
    },

    clearCart(force = false) {
        if (!this.cart.length) return;
        const reset = () => {
            this.cart = [];
            this.meta = { order_type: 'takeout', customer_name: '', table_number: '', notes: '', discount_id: '', discount_id_number: '' };
        };
        if (force) return reset();
        this.$confirm({ title: 'Clear this order?', message: `All ${this.itemCount} item(s) will be removed from the cart.`, confirmText: 'Clear order', tone: 'danger' }, reset);
    },

    /* ── Totals (mirror of OrderPricing.php) ──────────────── */
    lineTotal(l) {
        return this.round((l.unit_price + l.addons_total) * l.quantity);
    },
    get itemCount() {
        return this.cart.reduce((n, l) => n + l.quantity, 0);
    },
    get selectedDiscount() {
        return this.discounts.find((d) => String(d.id) === String(this.meta.discount_id)) ?? null;
    },
    get totals() {
        const subtotal = this.round(this.cart.reduce((s, l) => s + this.lineTotal(l), 0));
        const d = this.selectedDiscount;
        const divisor = 1 + this.vat.rate / 100;
        const off = (amount) => this.round(Math.min(d.type === 'percentage' ? amount * (d.value / 100) : d.value, amount));
        let lessVat = 0, discount = 0, vatable = 0, vatAmount = 0, vatExempt = 0, total;

        if (d && d.vat_exempt) {
            const base = this.vat.registered ? this.round(subtotal / divisor) : subtotal;
            lessVat = this.round(subtotal - base);
            discount = off(base);
            total = this.round(base - discount);
            vatExempt = this.vat.registered ? base : 0;
        } else {
            discount = d ? off(subtotal) : 0;
            total = this.round(subtotal - discount);
            if (this.vat.registered) {
                vatable = this.round(total / divisor);
                vatAmount = this.round(total - vatable);
            }
        }
        return { subtotal, lessVat, discount, vatable, vatAmount, vatExempt, total: Math.max(0, total) };
    },

    /* ── Checkout ─────────────────────────────────────────── */
    /**
     * '' when the table choice is fine (or not needed). Tables are picked from buttons, so this only
     * catches a stale value restored from a saved cart, e.g. after the number of tables was reduced.
     */
    get tableError() {
        if (this.meta.order_type !== 'dine_in' || this.meta.table_number === '' || this.meta.table_number == null) return '';
        const n = Number(this.meta.table_number);
        return Number.isInteger(n) && n >= 1 && n <= this.maxTable ? '' : `Choose a table from 1 to ${this.maxTable}.`;
    },

    validateOrder() {
        if (!this.cart.length) {
            this.$store.toasts.push({ type: 'error', message: 'The cart is empty. Tap a product to add it.' });
            return false;
        }
        if (this.tableError) {
            this.$store.toasts.push({ type: 'error', message: this.tableError });
            this.$refs.tableNone?.focus();
            return false;
        }
        if (this.selectedDiscount?.requires_id && !this.meta.discount_id_number.trim()) {
            this.$store.toasts.push({ type: 'error', message: `Enter the ${this.selectedDiscount.name} ID number before charging.` });
            this.$refs.discountId?.focus();
            return false;
        }
        return true;
    },

    openPayment() {
        if (!this.validateOrder()) return;
        this.pay = { open: true, method: 'cash', tendered: '', provider: '', reference: '', busy: false, error: '' };
        this.$nextTick(() => this.$refs.tendered?.focus());
    },

    get quickCash() {
        const t = this.totals.total;
        const set = new Set([Math.ceil(t), Math.ceil(t / 50) * 50, Math.ceil(t / 100) * 100, Math.ceil(t / 500) * 500, Math.ceil(t / 1000) * 1000]);
        return [...set].filter((v) => v >= t).slice(0, 4);
    },

    get change() {
        const tendered = parseFloat(this.pay.tendered);
        return isNaN(tendered) ? 0 : this.round(tendered - this.totals.total);
    },

    get canPay() {
        if (this.pay.busy) return false;
        if (this.pay.method === 'cash') return parseFloat(this.pay.tendered) >= this.totals.total;
        return this.pay.reference.trim().length >= 4;
    },

    payload() {
        return {
            cart: this.cart.map((l) => ({ variant_id: l.variant_id, quantity: l.quantity, addon_ids: l.addon_ids, notes: l.notes || null })),
            meta: {
                ...this.meta,
                discount_id: this.meta.discount_id || null,
                // Sent as a number ("07" → 7); empty or takeout → no table.
                table_number: this.meta.order_type === 'dine_in' && this.meta.table_number !== '' ? Number(this.meta.table_number) : null,
            },
        };
    },

    async submit(withPayment) {
        if (!this.validateOrder()) return;
        const { cart, meta } = this.payload();
        let payment = null;

        if (withPayment) {
            if (!this.canPay) {
                this.pay.error = this.pay.method === 'cash'
                    ? 'Cash received must be at least the amount due.'
                    : 'Enter the transaction reference number (at least 4 characters).';
                return;
            }
            payment = {
                method: this.pay.method,
                amount_tendered: this.pay.method === 'cash' ? parseFloat(this.pay.tendered) : null,
                provider: this.pay.method === 'cash' ? null : this.pay.provider || null,
                reference_number: this.pay.method === 'cash' ? null : this.pay.reference.trim(),
            };
            this.pay.busy = true;
            this.pay.error = '';
        }

        const res = await this.$wire.checkout(cart, meta, payment);
        this.pay.busy = false;

        if (!res?.ok) {
            const message = res?.message ?? 'Something went wrong. Please try again.';
            withPayment ? (this.pay.error = message) : this.$store.toasts.push({ type: 'error', message });
            return;
        }

        this.pay.open = false;
        this.cartOpen = false;
        this.result = res;
        this.clearCart(true);
    },

    saveUnpaid() {
        if (!this.validateOrder()) return;
        this.$confirm({
            title: 'Save without payment?',
            message: 'The order goes to the queue as unpaid. Collect payment from the Order queue before handing it over.',
            confirmText: 'Save unpaid order',
        }, () => this.submit(false));
    },

    newOrder() {
        this.result = null;
        this.$nextTick(() => document.querySelector('[data-search]')?.focus());
    },

    /* ── Utils ───────────────────────────────────────────── */
    round(v) { return Math.round((v + Number.EPSILON) * 100) / 100; },
    peso(v) { return window.KT.peso(v); },

    shortcuts(e) {
        if (this.picker.open || this.pay.open || this.result) return;
        if (e.key === 'F9') { e.preventDefault(); this.openPayment(); }
        if (e.key === 'F4') { e.preventDefault(); this.clearCart(); }
    },
});
