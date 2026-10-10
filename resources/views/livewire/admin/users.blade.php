<div class="space-y-6">
    <x-page-header description="Accounts for everyone who uses KaffeTrack. Managers can access everything; staff use the POS, order queue, cash drawer and stock-out screens.">
        <x-slot:actions>
            <button type="button" class="btn-primary" wire:click="create"><x-icon name="plus" class="size-4" /> Add user</button>
        </x-slot:actions>
    </x-page-header>

    <section class="panel overflow-hidden">
        <div class="panel-header flex-wrap">
            <div class="flex flex-wrap gap-2">
                <x-search-input wire:model.live.debounce.300ms="search" placeholder="Search name, username, email" />
                <select wire:model.live="role" class="field w-auto py-2 pr-8" aria-label="Role">
                    <option value="">All roles</option><option value="manager">Managers</option><option value="staff">Staff</option>
                </select>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Last sign-in</th><th class="text-right">Orders (30 d)</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                    @forelse ($users as $u)
                        <tr wire:key="u-{{ $u->id }}" class="{{ $u->is_active ? '' : 'opacity-60' }}">
                            <td>
                                <div class="flex items-center gap-3">
                                    <span class="grid size-9 place-items-center rounded-full {{ $u->isManager() ? 'bg-pine-900 text-white' : 'bg-leaf-100 text-leaf-800' }} font-display text-sm font-bold">{{ $u->initials() }}</span>
                                    <div><p class="font-semibold">{{ $u->name }} @if ($u->id === auth()->id())<span class="badge-gray ml-1">You</span>@endif</p><p class="text-xs text-sage-500">{{ $u->email }}</p></div>
                                </div>
                            </td>
                            <td class="font-mono text-[13px]">{{ $u->username }}</td>
                            <td><span class="{{ $u->isManager() ? 'badge-crema' : 'badge-gray' }}">{{ ucfirst($u->role) }}</span></td>
                            <td class="text-sage-600">{{ $u->last_login_at ? $u->last_login_at->diffForHumans() : 'Never' }}</td>
                            <td class="text-right tabular">{{ number_format($u->orders_count) }}</td>
                            <td><x-status-badge :status="$u->is_active ? 'active' : 'inactive'" /></td>
                            <td>
                                <div class="flex justify-end gap-1">
                                    <button type="button" class="btn-icon" wire:click="edit({{ $u->id }})" aria-label="Edit {{ $u->name }}" title="Edit"><x-icon name="pencil" class="size-4" /></button>
                                    <button type="button" class="btn-icon" wire:click="openPassword({{ $u->id }})" aria-label="Reset password for {{ $u->name }}" title="Reset password"><x-icon name="lock" class="size-4" /></button>
                                    @if ($u->id !== auth()->id())
                                        <button type="button" class="btn-icon" title="{{ $u->is_active ? 'Deactivate' : 'Activate' }}" aria-label="{{ $u->is_active ? 'Deactivate' : 'Activate' }} {{ $u->name }}"
                                                @click="$confirm({ title: @js(($u->is_active ? 'Deactivate ' : 'Activate ').$u->name.'?'), message: @js($u->is_active ? 'They will be signed out and unable to sign in until reactivated.' : 'They will be able to sign in again.'), confirmText: @js($u->is_active ? 'Deactivate' : 'Activate'), tone: @js($u->is_active ? 'warn' : 'primary') }, () => $wire.toggleActive({{ $u->id }}))">
                                            <x-icon :name="$u->is_active ? 'eye-off' : 'eye'" class="size-4" />
                                        </button>
                                        <button type="button" class="btn-icon hover:text-danger-700" title="Archive" aria-label="Archive {{ $u->name }}"
                                                @click="$confirm({ title: @js('Archive '.$u->name.'?'), message: 'The account is removed from the system but kept in the database so their orders and logs stay intact. Restore it from Archive.', confirmText: 'Archive account', tone: 'danger' }, () => $wire.archive({{ $u->id }}))">
                                            <x-icon name="archive" class="size-4" />
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><x-empty-state icon="users" title="No users match" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <x-modal wire:model="showForm" :title="$editingId ? 'Edit user' : 'Add user'" size="lg" icon="user">
        <form id="user-form" wire:submit="save" class="grid gap-4 sm:grid-cols-2">
            <x-input label="Full name" name="form.name" wire:model.blur="form.name" required class="sm:col-span-2" />
            <x-input label="Username" name="form.username" wire:model.blur="form.username" required autocomplete="off" help="Used to sign in. Letters, numbers, . _ -" />
            <x-input label="Email" name="form.email" type="email" wire:model.blur="form.email" required autocomplete="off" />
            <x-input label="Phone" name="form.phone" type="tel" wire:model.blur="form.phone" optional />
            <fieldset>
                <legend class="field-label">Role <span class="text-danger-600">*</span></legend>
                <div class="grid grid-cols-2 gap-2">
                    @foreach (['staff' => 'Staff', 'manager' => 'Manager'] as $r => $label)
                        <label class="cursor-pointer rounded-xl border-2 px-3 py-2.5 text-center text-sm font-semibold transition {{ ($form['role'] ?? '') === $r ? 'border-leaf-600 bg-leaf-50' : 'border-sage-200' }}">
                            <input type="radio" class="sr-only" wire:model.live="form.role" value="{{ $r }}"> {{ $label }}
                        </label>
                    @endforeach
                </div>
                @error('form.role')<p class="field-message">{{ $message }}</p>@enderror
            </fieldset>
            @unless ($editingId)
                <div x-data="{ show: false }" class="sm:col-span-2 grid gap-4 sm:grid-cols-2">
                    <x-input label="Password" name="form.password" ::type="show ? 'text' : 'password'" type="password" wire:model.blur="form.password" required autocomplete="new-password" help="At least 8 characters with letters and numbers." />
                    <x-input label="Confirm password" name="form.password_confirmation" ::type="show ? 'text' : 'password'" type="password" wire:model.blur="form.password_confirmation" required autocomplete="new-password" />
                    <label class="flex items-center gap-2 text-sm text-sage-600 sm:col-span-2"><input type="checkbox" x-model="show" class="accent-leaf-600"> Show passwords</label>
                </div>
            @endunless
            @if (($form['role'] ?? '') === 'manager')
                <x-input label="Refund approval PIN" name="form.pin" type="password" inputmode="numeric" maxlength="6" wire:model.blur="form.pin" :required="$pinRequired" autocomplete="off"
                         :help="$pinRequired ? '4–6 digits. Entered at the POS to approve refunds.' : 'Leave blank to keep the current PIN.'" class="sm:col-span-2" />
            @endif
            <div class="sm:col-span-2"><x-toggle label="Active" description="Inactive users cannot sign in." wire:model="form.is_active" /></div>
        </form>
        <x-slot:footer>
            <button type="button" class="btn-secondary" @click="show = false">Cancel</button>
            <x-submit form="user-form" target="save">{{ $editingId ? 'Save changes' : 'Create account' }}</x-submit>
        </x-slot:footer>
    </x-modal>

    <x-modal wire:model="showPassword" title="Reset password" :description="$passwordUser ? 'Set a new password for '.$passwordUser->name.'. Tell them privately.' : null" size="sm" icon="lock">
        <form id="pw-form" wire:submit="resetPassword" class="space-y-4">
            <x-input label="New password" name="newPassword" type="password" wire:model.blur="newPassword" required autocomplete="new-password" help="At least 8 characters with letters and numbers." />
            <x-input label="Confirm new password" name="newPassword_confirmation" type="password" wire:model.blur="newPassword_confirmation" required autocomplete="new-password" />
        </form>
        <x-slot:footer>
            <button type="button" class="btn-secondary" @click="show = false">Cancel</button>
            <x-submit form="pw-form" target="resetPassword">Reset password</x-submit>
        </x-slot:footer>
    </x-modal>
</div>
