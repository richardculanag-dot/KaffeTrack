<div class="mx-auto max-w-3xl space-y-6">
    <div class="panel flex items-center gap-4 p-5">
        <span class="grid size-14 place-items-center rounded-full bg-pine-900 font-display text-xl font-bold text-white">{{ auth()->user()->initials() }}</span>
        <div>
            <p class="font-display text-xl font-semibold">{{ auth()->user()->name }}</p>
            <p class="text-sm text-sage-600">{{ ucfirst(auth()->user()->role) }} · signs in as <span class="font-mono">{{ auth()->user()->username }}</span></p>
        </div>
    </div>

    <form wire:submit="saveProfile" class="panel">
        <div class="panel-header"><h2 class="panel-title">Personal details</h2></div>
        <div class="grid gap-4 p-5 sm:grid-cols-2">
            <x-input label="Full name" name="name" wire:model.blur="name" required class="sm:col-span-2" />
            <x-input label="Email" name="email" type="email" wire:model.blur="email" required />
            <x-input label="Phone" name="phone" type="tel" wire:model.blur="phone" optional />
        </div>
        <div class="flex justify-end border-t border-sage-200 px-5 py-4"><x-submit target="saveProfile">Save details</x-submit></div>
    </form>

    <form wire:submit="changePassword" class="panel" x-data="{ show: false }">
        <div class="panel-header"><h2 class="panel-title">Change password</h2></div>
        <div class="grid gap-4 p-5 sm:grid-cols-2">
            <x-input label="Current password" name="current_password" type="password" ::type="show ? 'text' : 'password'" wire:model="current_password" required autocomplete="current-password" class="sm:col-span-2" />
            <x-input label="New password" name="password" type="password" ::type="show ? 'text' : 'password'" wire:model.blur="password" required autocomplete="new-password" help="At least 8 characters with letters and numbers." />
            <x-input label="Confirm new password" name="password_confirmation" type="password" ::type="show ? 'text' : 'password'" wire:model.blur="password_confirmation" required autocomplete="new-password" />
            <label class="flex items-center gap-2 text-sm text-sage-600"><input type="checkbox" x-model="show" class="accent-leaf-600"> Show passwords</label>
        </div>
        <div class="flex justify-end border-t border-sage-200 px-5 py-4"><x-submit target="changePassword">Change password</x-submit></div>
    </form>

    @if (auth()->user()->isManager())
        <form wire:submit="changePin" class="panel">
            <div class="panel-header">
                <div><h2 class="panel-title">Refund approval PIN</h2><p class="text-[13px] text-sage-500">Entered at the POS when a staff member needs your approval for a refund.</p></div>
            </div>
            <div class="grid gap-4 p-5 sm:grid-cols-2">
                <x-input label="New PIN" name="pin" type="password" inputmode="numeric" maxlength="6" wire:model="pin" required autocomplete="off" help="4–6 digits" />
                <x-input label="Your password" name="pin_password" type="password" wire:model="pin_password" required autocomplete="current-password" help="To confirm it's you." />
            </div>
            <div class="flex justify-end border-t border-sage-200 px-5 py-4"><x-submit target="changePin">Update PIN</x-submit></div>
        </form>
    @endif
</div>
