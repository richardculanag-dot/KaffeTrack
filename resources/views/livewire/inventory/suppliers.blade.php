<div class="space-y-6">
    <x-page-header description="Who you buy ingredients and supplies from. Suppliers are chosen when receiving stock.">
        <x-slot:actions>
            <button type="button" class="btn-primary" wire:click="create"><x-icon name="plus" class="size-4" /> Add supplier</button>
        </x-slot:actions>
    </x-page-header>

    <section class="panel overflow-hidden">
        <div class="panel-header"><x-search-input wire:model.live.debounce.300ms="search" placeholder="Search suppliers" /></div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Supplier</th><th>Contact</th><th>Deliveries</th><th>Last delivery</th><th class="text-right">Total purchased</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                    @forelse ($suppliers as $s)
                        <tr wire:key="sup-{{ $s->id }}">
                            <td><p class="font-semibold">{{ $s->name }}</p><p class="text-xs text-sage-500">{{ $s->address ?: '—' }}</p></td>
                            <td>
                                <p>{{ $s->contact_person ?: '—' }}</p>
                                <p class="text-xs text-sage-500">
                                    @if ($s->phone)<a href="tel:{{ preg_replace('/\s+/', '', $s->phone) }}" class="hover:underline">{{ $s->phone }}</a>@endif
                                    @if ($s->email) · <a href="mailto:{{ $s->email }}" class="hover:underline">{{ $s->email }}</a>@endif
                                </p>
                            </td>
                            <td>{{ $s->stock_ins_count }}</td>
                            <td>{{ $s->stock_ins_max_received_date ? \Illuminate\Support\Carbon::parse($s->stock_ins_max_received_date)->format('M j, Y') : '—' }}</td>
                            <td class="text-right font-semibold tabular">{{ peso($s->stock_ins_sum_total_cost ?? 0) }}</td>
                            <td><x-status-badge :status="$s->is_active ? 'active' : 'inactive'" /></td>
                            <td>
                                <div class="flex justify-end gap-1">
                                    <a href="{{ route('inventory.stock-ins', ['supplier' => $s->id]) }}" wire:navigate class="btn-ghost btn-sm">Deliveries</a>
                                    <button type="button" class="btn-icon" wire:click="edit({{ $s->id }})" aria-label="Edit {{ $s->name }}"><x-icon name="pencil" class="size-4" /></button>
                                    <button type="button" class="btn-icon hover:text-danger-700" aria-label="Archive {{ $s->name }}"
                                            @click="$confirm({ title: @js('Archive '.$s->name.'?'), message: 'The supplier will no longer appear when receiving stock. Past deliveries are kept. You can restore it from Archive.', confirmText: 'Archive supplier', tone: 'danger' }, () => $wire.archive({{ $s->id }}))">
                                        <x-icon name="archive" class="size-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7">
                            <x-empty-state icon="truck" title="{{ $search ? 'No suppliers match “'.$search.'”' : 'No suppliers yet' }}" description="Add the roasters, dairies and shops you buy from.">
                                @unless ($search)<button type="button" class="btn-primary" wire:click="create"><x-icon name="plus" class="size-4" /> Add supplier</button>@endunless
                            </x-empty-state>
                        </td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($suppliers->hasPages())<div class="border-t border-sage-200 px-5 py-3">{{ $suppliers->links() }}</div>@endif
    </section>

    <x-modal wire:model="showForm" :title="$editingId ? 'Edit supplier' : 'Add supplier'" size="lg" icon="truck">
        <form id="supplier-form" wire:submit="save" class="grid gap-4 sm:grid-cols-2">
            <x-input label="Supplier name" name="form.name" wire:model.blur="form.name" required class="sm:col-span-2" />
            <x-input label="Contact person" name="form.contact_person" wire:model.blur="form.contact_person" optional />
            <x-input label="Phone" name="form.phone" type="tel" wire:model.blur="form.phone" optional placeholder="0917 123 4567" />
            <x-input label="Email" name="form.email" type="email" wire:model.blur="form.email" optional class="sm:col-span-2" />
            <x-input label="Address" name="form.address" wire:model.blur="form.address" optional class="sm:col-span-2" />
            <x-textarea label="Notes" name="form.notes" rows="2" wire:model.blur="form.notes" optional class="sm:col-span-2" placeholder="e.g. delivers Mon & Thu, 30-day terms" />
            <div class="sm:col-span-2"><x-toggle label="Active" description="Inactive suppliers can't be chosen for new deliveries." wire:model="form.is_active" /></div>
        </form>
        <x-slot:footer>
            <button type="button" class="btn-secondary" @click="show = false">Cancel</button>
            <x-submit form="supplier-form" target="save">{{ $editingId ? 'Save changes' : 'Add supplier' }}</x-submit>
        </x-slot:footer>
    </x-modal>
</div>
