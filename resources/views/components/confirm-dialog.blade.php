{{--
    Global confirmation dialog (one instance per page, driven by Alpine.store('confirm')).
    Prevents slips on destructive or irreversible actions (Nielsen heuristic #5: error prevention).
--}}
<div x-data x-show="$store.confirm.open" x-cloak class="fixed inset-0 z-[80] flex items-end justify-center p-4 sm:items-center"
     @keydown.escape.window="$store.confirm.open && $store.confirm.close()" role="dialog" aria-modal="true" aria-labelledby="confirm-title">
    <div x-show="$store.confirm.open" x-transition.opacity class="absolute inset-0 bg-pine-950/45" @click="$store.confirm.close()"></div>

    <div x-show="$store.confirm.open"
         x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="opacity-0 translate-y-3 scale-[.97]" x-transition:enter-end="opacity-100 translate-y-0 scale-100"
         x-transition:leave="transition duration-150 ease-in" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0 scale-[.98]"
         x-trap.noscroll="$store.confirm.open"
         class="relative w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
        <div class="flex gap-4">
            <span class="grid size-11 shrink-0 place-items-center rounded-full"
                  :class="{ 'bg-danger-50 text-danger-600': $store.confirm.tone === 'danger', 'bg-warn-50 text-warn-600': $store.confirm.tone === 'warn', 'bg-leaf-100 text-leaf-700': $store.confirm.tone === 'primary' }">
                <template x-if="$store.confirm.tone === 'primary'"><x-icon name="help" /></template>
                <template x-if="$store.confirm.tone !== 'primary'"><x-icon name="alert" /></template>
            </span>
            <div class="min-w-0 flex-1">
                <h2 id="confirm-title" class="font-display text-lg font-semibold" x-text="$store.confirm.title"></h2>
                <p class="mt-1 text-sm text-sage-600" x-text="$store.confirm.message"></p>

                <template x-if="$store.confirm.input">
                    <div class="mt-4">
                        <label class="field-label" for="confirm-input" x-text="$store.confirm.input.label"></label>
                        <textarea id="confirm-input" rows="2" class="field" :class="$store.confirm.error && 'field-error'"
                                  x-model="$store.confirm.value" :placeholder="$store.confirm.input.placeholder ?? ''"
                                  @keydown.enter.prevent="$store.confirm.accept()" x-init="$nextTick(() => $el.focus())"></textarea>
                        <p class="field-message" x-show="$store.confirm.error" x-text="$store.confirm.error"></p>
                    </div>
                </template>
            </div>
        </div>

        <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <button type="button" class="btn-secondary" @click="$store.confirm.close()" x-text="$store.confirm.cancelText"></button>
            <button type="button" @click="$store.confirm.accept()" x-text="$store.confirm.confirmText"
                    :class="{ 'btn-danger': $store.confirm.tone === 'danger', 'btn-warn': $store.confirm.tone === 'warn', 'btn-primary': $store.confirm.tone === 'primary' }"></button>
        </div>
    </div>
</div>
