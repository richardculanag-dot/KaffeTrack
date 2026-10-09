{{-- Toast notifications: polite live region so screen readers announce feedback (WCAG 4.1.3). --}}
<div x-data class="pointer-events-none fixed right-4 top-[4.5rem] z-[90] flex w-[calc(100%-2rem)] max-w-sm flex-col gap-2" aria-live="polite" aria-atomic="false">
    <template x-for="toast in $store.toasts.items" :key="toast.id">
        <div class="pointer-events-auto flex animate-toast-in items-start gap-3 rounded-xl border bg-white p-3.5 pr-2.5 shadow-[0_12px_32px_-8px_rgba(15,38,29,.28)]"
             :class="{
                'border-leaf-200': toast.type === 'success',
                'border-danger-600/30': toast.type === 'error',
                'border-warn-600/30': toast.type === 'warning',
                'border-info-600/30': toast.type === 'info',
             }"
             :role="toast.type === 'error' ? 'alert' : 'status'">
            <span class="mt-0.5 grid size-7 shrink-0 place-items-center rounded-full"
                  :class="{
                    'bg-leaf-100 text-leaf-700': toast.type === 'success',
                    'bg-danger-50 text-danger-700': toast.type === 'error',
                    'bg-warn-50 text-warn-700': toast.type === 'warning',
                    'bg-info-50 text-info-600': toast.type === 'info',
                  }">
                <template x-if="toast.type === 'success'"><x-icon name="check" class="size-4" stroke="2.5" /></template>
                <template x-if="toast.type === 'error'"><x-icon name="x" class="size-4" stroke="2.5" /></template>
                <template x-if="toast.type === 'warning'"><x-icon name="alert" class="size-4" /></template>
                <template x-if="toast.type === 'info'"><x-icon name="info" class="size-4" /></template>
            </span>
            <div class="min-w-0 flex-1 pt-0.5">
                <p x-show="toast.title" class="text-sm font-semibold" x-text="toast.title"></p>
                <p class="text-sm text-sage-700" x-text="toast.message"></p>
            </div>
            <template x-if="toast.action">
                <button type="button" class="btn-ghost btn-sm text-leaf-700" x-text="toast.action.label"
                        @click="toast.action.handler(); $store.toasts.remove(toast.id)"></button>
            </template>
            <button type="button" class="rounded-md p-1 text-sage-400 hover:bg-sage-100 hover:text-ink" @click="$store.toasts.remove(toast.id)" aria-label="Dismiss">
                <x-icon name="x" class="size-4" />
            </button>
        </div>
    </template>
</div>
