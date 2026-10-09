<div class="grid min-h-full lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
    {{-- Brand panel --}}
    <section class="relative hidden overflow-hidden bg-pine-900 p-12 text-white lg:flex lg:flex-col">
        <div class="flex items-center gap-3">
            <x-brand-mark class="size-11" />
            <div class="leading-tight">
                <p class="font-display text-xl font-bold">KaffeTrack</p>
                <p class="text-sm text-leaf-200/75">{{ \App\Models\Setting::get('store_name') }}</p>
            </div>
        </div>

        <div class="mt-auto max-w-md">
            <p class="font-display text-[2.6rem] font-semibold leading-[1.08] tracking-tight">Every cup, every peso, every bag of beans.</p>
            <p class="mt-4 text-[15px] leading-relaxed text-leaf-100/80">
                Take orders, track what's opened at the bar, and see how the café is doing today — in one place built for HEIM.
            </p>
        </div>

        <p class="mt-12 text-sm text-leaf-200/60">{{ now()->format('l, F j, Y') }}</p>

        {{-- Decorative leaf, purely visual --}}
        <svg class="pointer-events-none absolute -right-24 -top-16 size-[420px] text-pine-800" viewBox="0 0 40 40" fill="currentColor" aria-hidden="true">
            <path d="M8 35C4.5 21 12 6 36 3c2.2 19.6-8.8 32.8-28 32Z"/>
        </svg>
    </section>

    {{-- Form --}}
    <section class="flex items-center justify-center px-5 py-12 sm:px-10">
        <div class="w-full max-w-sm">
            <div class="mb-8 flex items-center gap-3 lg:hidden">
                <x-brand-mark class="size-10" />
                <p class="font-display text-xl font-bold">KaffeTrack</p>
            </div>

            <h1 class="font-display text-3xl font-semibold">Sign in</h1>
            <p class="mt-1.5 text-sage-600">Use the account your manager created for you.</p>

            @if (session('status'))
                <div class="mt-6 flex gap-2.5 rounded-xl border border-leaf-200 bg-leaf-50 p-3 text-sm text-leaf-800" role="status">
                    <x-icon name="info" class="size-5" /> {{ session('status') }}
                </div>
            @endif

            <form wire:submit="authenticate" class="mt-8 space-y-5" novalidate
                  x-data="{ shake: false }" @login-failed.window="shake = true; setTimeout(() => shake = false, 400)" :class="shake && 'animate-shake'">
                <x-input label="Username or email" name="login" wire:model="login" autocomplete="username" autofocus required
                         placeholder="e.g. staff or staff@heim.cafe" />

                <div x-data="{ show: false, caps: false }">
                    <x-field label="Password" for="password" error="password" required>
                        <div class="relative">
                            <input id="password" :type="show ? 'text' : 'password'" wire:model="password" autocomplete="current-password"
                                   class="field pr-11 @error('password') field-error @enderror" aria-required="true"
                                   @keyup="caps = $event.getModifierState && $event.getModifierState('CapsLock')">
                            <button type="button" class="absolute inset-y-0 right-0 grid w-11 place-items-center text-sage-500 hover:text-ink"
                                    @click="show = !show" :aria-label="show ? 'Hide password' : 'Show password'" :aria-pressed="show">
                                <x-icon name="eye" class="size-[18px]" x-show="!show" />
                                <x-icon name="eye-off" class="size-[18px]" x-show="show" x-cloak />
                            </button>
                        </div>
                    </x-field>
                    <p x-show="caps" x-cloak class="mt-1.5 flex items-center gap-1.5 text-[13px] text-warn-700">
                        <x-icon name="alert" class="size-3.5" /> Caps Lock is on
                    </p>
                </div>

                <label class="flex items-center gap-2.5 text-sm text-sage-700">
                    <input type="checkbox" wire:model="remember" class="size-4 rounded border-sage-300 accent-leaf-600">
                    Keep me signed in on this device
                </label>

                <x-submit target="authenticate" class="w-full btn-lg">Sign in</x-submit>
            </form>

            @if (app()->environment('local'))
                <details class="mt-8 rounded-xl border border-dashed border-sage-300 p-4 text-sm text-sage-600">
                    <summary class="cursor-pointer font-semibold text-sage-700">Demo accounts</summary>
                    <dl class="mt-3 space-y-1.5">
                        <div class="flex justify-between"><dt>Manager</dt><dd><code>manager</code> / <code>Manager@123</code> · PIN 1234</dd></div>
                        <div class="flex justify-between"><dt>Staff</dt><dd><code>staff</code> / <code>Staff@123</code></dd></div>
                    </dl>
                    <p class="mt-2 text-xs text-sage-500">Shown only while APP_ENV=local.</p>
                </details>
            @endif
        </div>
    </section>
</div>
