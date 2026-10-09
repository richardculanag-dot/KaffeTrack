<div class="space-y-6">
    <x-page-header description="Choose a report and a period, preview it, then download it as a PDF ready to print or submit." />

    <div class="grid gap-6 xl:grid-cols-3">
        {{-- Step 1: report type --}}
        <section class="xl:col-span-2">
            <h2 class="mb-3 font-display text-lg font-semibold">1 · Choose a report</h2>
            <div class="grid gap-3 sm:grid-cols-2" role="radiogroup" aria-label="Report type">
                @foreach ($types as $key => [$label, $desc, $icon])
                    <button type="button" role="radio" aria-checked="{{ $type === $key ? 'true' : 'false' }}" wire:click="$set('type', '{{ $key }}')"
                            class="flex items-start gap-3 rounded-[var(--radius-panel)] border-2 bg-white p-4 text-left transition {{ $type === $key ? 'border-leaf-600 bg-leaf-50' : 'border-sage-200 hover:border-sage-300' }}">
                        <span class="grid size-10 shrink-0 place-items-center rounded-xl {{ $type === $key ? 'bg-leaf-600 text-white' : 'bg-sage-100 text-sage-600' }}"><x-icon :name="$icon" class="size-5" /></span>
                        <span>
                            <span class="block font-semibold">{{ $label }}</span>
                            <span class="block text-[13px] text-sage-600">{{ $desc }}</span>
                        </span>
                    </button>
                @endforeach
            </div>
        </section>

        {{-- Step 2: period + export --}}
        <section>
            <h2 class="mb-3 font-display text-lg font-semibold">2 · Pick a period</h2>
            <div class="panel space-y-4 p-5">
                <div class="flex flex-wrap gap-2">
                    @foreach (\App\Livewire\Reports\Index::PRESETS as $key => $label)
                        <button type="button" wire:click="$set('preset', '{{ $key }}')" aria-pressed="{{ $preset === $key ? 'true' : 'false' }}"
                                class="rounded-full border px-3 py-1.5 text-sm font-semibold transition {{ $preset === $key ? 'border-leaf-600 bg-leaf-600 text-white' : 'border-sage-300 hover:bg-sage-50' }}">{{ $label }}</button>
                    @endforeach
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <x-input label="From" name="from" type="date" wire:model.live="from" max="{{ today()->toDateString() }}" />
                    <x-input label="To" name="to" type="date" wire:model.live="to" max="{{ today()->toDateString() }}" />
                </div>
                @if ($type === 'audit')
                    <x-select label="Module" name="module" wire:model.live="module" :options="$modules" placeholder="All modules" />
                @endif

                @if ($errorsList)
                    <div class="rounded-xl bg-danger-50 p-3 text-sm text-danger-700" role="alert">
                        @foreach ($errorsList as $err)<p class="flex gap-2"><x-icon name="alert" class="size-4" /> {{ $err }}</p>@endforeach
                    </div>
                @elseif ($summary)
                    <div class="rounded-xl bg-sage-100 p-4 text-sm" wire:loading.class="opacity-50">
                        <p class="font-semibold">{{ $rangeLabel }}</p>
                        <dl class="mt-2 grid grid-cols-2 gap-y-1.5">
                            <dt class="text-sage-600">Net sales</dt><dd class="text-right font-semibold tabular">{{ peso($summary['sales']) }}</dd>
                            <dt class="text-sage-600">Paid orders</dt><dd class="text-right font-semibold tabular">{{ number_format($summary['orders']) }}</dd>
                            <dt class="text-sage-600">Expenses</dt><dd class="text-right font-semibold tabular">{{ peso($summary['expenses']) }}</dd>
                            <dt class="text-sage-600">Net income</dt><dd class="text-right font-semibold tabular {{ $summary['net_income'] < 0 ? 'text-danger-700' : 'text-leaf-800' }}">{{ peso($summary['net_income']) }}</dd>
                        </dl>
                    </div>
                @endif

                <div class="flex flex-col gap-2">
                    <button type="button" class="btn-secondary" wire:click="$set('showPreview', true)" @disabled($errorsList)><x-icon name="eye" class="size-4" /> Preview</button>
                    <a href="{{ $errorsList ? '#' : $downloadUrl }}" class="btn-primary {{ $errorsList ? 'pointer-events-none opacity-55' : '' }}"
                       x-data @click="$store.toasts.push({ type: 'info', message: 'Preparing your PDF — the download starts in a moment.' })">
                        <x-icon name="download" class="size-4" /> Download PDF
                    </a>
                </div>
            </div>
        </section>
    </div>

    {{-- Preview: the actual PDF, so what you see is exactly what you download --}}
    <x-modal wire:model="showPreview" :title="$types[$type][0].' — preview'" :description="$rangeLabel" size="xl" icon="report">
        @if ($showPreview)
            <div class="relative -mx-6 -my-5 h-[70vh] bg-sage-100" x-data="{ loading: true }">
                <div x-show="loading" class="absolute inset-0 grid place-items-center text-sm text-sage-600">
                    <span class="flex items-center gap-2"><x-spinner class="size-5 text-leaf-600" /> Building the report…</span>
                </div>
                <iframe src="{{ $previewUrl }}" title="Report preview" class="size-full" @load="loading = false"></iframe>
            </div>
        @endif
        <x-slot:footer>
            <button type="button" class="btn-secondary" @click="show = false">Close</button>
            <a href="{{ $downloadUrl }}" class="btn-primary"><x-icon name="download" class="size-4" /> Download PDF</a>
        </x-slot:footer>
    </x-modal>
</div>
