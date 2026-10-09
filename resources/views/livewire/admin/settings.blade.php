<form wire:submit="save" class="mx-auto max-w-3xl space-y-6">
    <x-page-header description="Store details appear on receipts and report headers. Tax settings change how totals are computed at the POS." />

    <section class="panel">
        <div class="panel-header"><h2 class="panel-title">Store details</h2></div>
        <div class="grid gap-4 p-5 sm:grid-cols-2">
            <x-input label="Store name" name="form.store_name" wire:model.blur="form.store_name" required class="sm:col-span-2" />
            <x-input label="Address" name="form.store_address" wire:model.blur="form.store_address" required class="sm:col-span-2" />
            <x-input label="Contact number" name="form.store_contact" wire:model.blur="form.store_contact" optional />
            <x-input label="TIN" name="form.store_tin" wire:model.blur="form.store_tin" optional placeholder="000-000-000-00000" />
            <x-textarea label="Receipt footer" name="form.receipt_footer" rows="2" wire:model.blur="form.receipt_footer" optional class="sm:col-span-2" />
        </div>
    </section>

    <section class="panel">
        <div class="panel-header"><h2 class="panel-title">Tax</h2></div>
        <div class="space-y-4 p-5">
            <x-toggle label="VAT-registered" description="Show VATable sales and VAT on receipts, and remove VAT before Senior/PWD discounts." wire:model.live="form.vat_registered" />
            @if ($form['vat_registered'])
                <x-input label="VAT rate" name="form.vat_rate" type="number" step="0.01" min="0" max="30" suffix="%" wire:model.blur="form.vat_rate" required class="max-w-48" />
            @endif
            <div class="flex gap-3 rounded-xl bg-warn-50 p-3.5 text-sm text-warn-700">
                <x-icon name="alert" class="size-5" />
                <p>Changes apply to new orders only. Existing orders keep the tax amounts they were saved with.</p>
            </div>
        </div>
    </section>

    <div class="flex justify-end">
        <x-submit target="save" class="btn-lg">Save settings</x-submit>
    </div>
</form>
