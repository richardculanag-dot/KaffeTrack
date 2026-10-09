<div class="space-y-6">
    <x-page-header description="Discounts the cashier can apply to a whole order at the POS.">
        <x-slot:actions>
            <button type="button" class="btn-primary" wire:click="create"><x-icon name="plus" class="size-4" /> Add discount</button>
        </x-slot:actions>
    </x-page-header>

    <div class="flex gap-3 rounded-xl border border-info-600/20 bg-info-50 p-4 text-sm text-info-600">
        <x-icon name="info" class="size-5" />
        <p><span class="font-semibold">Senior Citizen & PWD (RA 9994 / RA 10754):</span> turn on “VAT-exempt”. KaffeTrack removes the 12% VAT first, then applies the 20% discount, and records the sale as VAT-exempt with the customer's ID number.</p>
    </div>

    <section class="panel overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Discount</th><th>Value</th><th>Rules</th><th class="text-right">Used (30 days)</th><th class="text-right">Given away</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @forelse ($discounts as $d)
                        <tr wire:key="d-{{ $d->id }}">
                            <td class="font-semibold">{{ $d->name }}</td>
                            <td class="font-display text-lg font-semibold">{{ $d->label() }}</td>
                            <td>
                                <div class="flex flex-wrap gap-1.5">
                                    @if ($d->is_vat_exempt)<span class="badge-blue">VAT-exempt</span>@endif
                                    @if ($d->requires_id)<span class="badge-gray">ID required</span>@endif
                                    @if (! $d->is_vat_exempt && ! $d->requires_id)<span class="text-sm text-sage-400">—</span>@endif
                                </div>
                            </td>
                            <td class="text-right tabular">{{ number_format($usage[$d->id]->n ?? 0) }}</td>
                            <td class="text-right tabular">{{ peso($usage[$d->id]->total ?? 0) }}</td>
                            <td><x-status-badge :status="$d->is_active ? 'active' : 'inactive'" /></td>
                            <td>
                                <div class="flex justify-end gap-1">
                                    <button type="button" class="btn-icon" wire:click="edit({{ $d->id }})" aria-label="Edit {{ $d->name }}"><x-icon name="pencil" class="size-4" /></button>
                                    <button type="button" class="btn-icon hover:text-danger-700" aria-label="Archive {{ $d->name }}"
                                            @click="$confirm({ title: @js('Archive “'.$d->name.'”?'), message: 'Cashiers will no longer be able to apply it. Past orders keep the discount name.', confirmText: 'Archive', tone: 'danger' }, () => $wire.archive({{ $d->id }}))"><x-icon name="archive" class="size-4" /></button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><x-empty-state icon="tag" title="No discounts yet" description="Add Senior Citizen and PWD discounts first — they're required by law." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <x-modal wire:model="showForm" :title="$editingId ? 'Edit discount' : 'Add discount'" icon="tag">
        <form id="discount-form" wire:submit="save" class="space-y-4">
            <x-input label="Name" name="form.name" wire:model.blur="form.name" required placeholder="e.g. Senior Citizen" />
            <div class="grid grid-cols-2 gap-4">
                <x-select label="Type" name="form.type" wire:model.live="form.type" :options="['percentage' => 'Percentage (%)', 'fixed' => 'Fixed amount (₱)']" required />
                <x-input label="Value" name="form.value" type="number" step="0.01" min="0" wire:model.blur="form.value" required
                         :prefix="($form['type'] ?? '') === 'fixed' ? '₱' : null" :suffix="($form['type'] ?? '') === 'percentage' ? '%' : null" />
            </div>
            <x-toggle label="VAT-exempt (Senior Citizen / PWD)" description="Remove VAT before applying the discount." wire:model="form.is_vat_exempt" />
            <x-toggle label="Require an ID number" description="The cashier must enter the customer's ID before charging." wire:model="form.requires_id" />
            <x-toggle label="Active" wire:model="form.is_active" />
        </form>
        <x-slot:footer>
            <button type="button" class="btn-secondary" @click="show = false">Cancel</button>
            <x-submit form="discount-form" target="save">{{ $editingId ? 'Save changes' : 'Add discount' }}</x-submit>
        </x-slot:footer>
    </x-modal>
</div>
