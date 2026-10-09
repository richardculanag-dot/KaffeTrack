@props(['title' => null])
@php
    $user = auth()->user();
    $isManager = $user?->isManager();

    // Navigation is defined once here; staff only see the items they are allowed to use.
    $nav = [
        ['group' => null, 'items' => [
            ['Dashboard', 'dashboard', 'dashboard', 'dashboard', true],
        ]],
        ['group' => 'Sales', 'items' => [
            ['Point of sale', 'pos', 'pos', 'pos', false],
            ['Order queue', 'orders.index', 'orders.*', 'orders', false],
            ['Cash drawer', 'shifts.index', 'shifts.*', 'drawer', false],
        ]],
        ['group' => 'Inventory', 'items' => [
            ['Stock on hand', 'inventory.items', 'inventory.items', 'package', false],
            ['Stock in', 'inventory.stock-ins', 'inventory.stock-ins*', 'truck', true],
            ['Stock movements', 'inventory.movements', 'inventory.movements', 'arrows', false],
            ['Suppliers', 'inventory.suppliers', 'inventory.suppliers', 'users', true],
        ]],
        ['group' => 'Menu', 'items' => [
            ['Products', 'products.index', 'products.index', 'coffee', true],
            ['Categories & add-ons', 'products.options', 'products.options', 'layers', true],
            ['Discounts', 'products.discounts', 'products.discounts', 'tag', true],
        ]],
        ['group' => 'Business', 'items' => [
            ['Finance', 'finance.index', 'finance.*', 'finance', true],
            ['Reports', 'reports.index', 'reports.*', 'report', true],
        ]],
        ['group' => 'Administration', 'items' => [
            ['Users', 'users.index', 'users.*', 'user', true],
            ['Audit logs', 'audit.index', 'audit.*', 'shield', true],
            ['Archive', 'archive.index', 'archive.*', 'archive', true],
            ['Settings', 'settings', 'settings', 'settings', true],
        ]],
    ];
@endphp
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}KaffeTrack</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full" x-data="{ navOpen: false }" @keydown.escape.window="navOpen = false">
<a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[100] btn-primary">Skip to content</a>

<div class="flex min-h-full">
    {{-- Mobile backdrop --}}
    <div x-show="navOpen" x-cloak x-transition.opacity @click="navOpen = false" class="fixed inset-0 z-40 bg-pine-950/50 lg:hidden"></div>

    {{-- Sidebar --}}
    <aside class="fixed inset-y-0 left-0 z-50 flex w-[260px] flex-col bg-pine-900 text-white transition-transform duration-200 lg:translate-x-0"
           :class="navOpen ? 'translate-x-0' : '-translate-x-full'" aria-label="Main navigation">
        <div class="flex items-center gap-3 px-5 pt-5 pb-4">
            <x-brand-mark class="size-10" />
            <div class="leading-tight">
                <p class="font-display text-[19px] font-bold tracking-tight">KaffeTrack</p>
                <p class="text-[12px] text-leaf-200/75">{{ \App\Models\Setting::get('store_name') }}</p>
            </div>
            <button type="button" class="ml-auto rounded-lg p-1.5 text-leaf-100/70 hover:bg-white/10 lg:hidden" @click="navOpen = false" aria-label="Close menu">
                <x-icon name="x" />
            </button>
        </div>

        <nav class="flex-1 overflow-y-auto px-3 pb-6">
            @foreach ($nav as $section)
                @php $items = collect($section['items'])->filter(fn ($i) => ! $i[4] || $isManager); @endphp
                @continue($items->isEmpty())
                @if ($section['group'])
                    <p class="nav-group">{{ $section['group'] }}</p>
                @endif
                <ul class="space-y-0.5">
                    @foreach ($items as [$label, $route, $pattern, $icon])
                            <li>
                                <a href="{{ route($route) }}" wire:navigate class="nav-link" @if (request()->routeIs($pattern)) aria-current="page" @endif>
                                    <x-icon :name="$icon" class="size-[18px]" />
                                    <span>{{ $label }}</span>
                                </a>
                            </li>
                    @endforeach
                </ul>
            @endforeach
        </nav>

        <div class="border-t border-white/10 p-3">
            <a href="{{ route('profile') }}" wire:navigate class="flex items-center gap-3 rounded-xl p-2 hover:bg-white/8">
                <span class="grid size-9 place-items-center rounded-full bg-leaf-300 font-display text-sm font-bold text-pine-900">{{ $user->initials() }}</span>
                <span class="min-w-0 leading-tight">
                    <span class="block truncate text-sm font-semibold">{{ $user->name }}</span>
                    <span class="block text-[12px] text-leaf-200/75">{{ ucfirst($user->role) }}</span>
                </span>
            </a>
        </div>
    </aside>

    {{-- Main column --}}
    <div class="flex min-w-0 flex-1 flex-col lg:pl-[260px]">
        <header class="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-sage-200 bg-sage-50/90 px-4 backdrop-blur sm:px-6">
            <button type="button" class="btn-icon lg:hidden" @click="navOpen = true" aria-label="Open menu">
                <x-icon name="menu" />
            </button>

            <div class="min-w-0">
                <h1 class="truncate font-display text-xl font-semibold">{{ $title }}</h1>
            </div>

            <div class="ml-auto flex items-center gap-2">
                <livewire:shell.status-bar />

                {{-- Keyboard shortcuts help (recognition rather than recall) --}}
                <div x-data="{ open: false }" class="relative hidden sm:block">
                    <button type="button" class="btn-icon" @click="open = !open" :aria-expanded="open" aria-label="Keyboard shortcuts" title="Keyboard shortcuts">
                        <x-icon name="keyboard" />
                    </button>
                    <div x-show="open" x-cloak x-transition.origin.top.right @click.outside="open = false"
                         class="absolute right-0 mt-2 w-72 rounded-xl border border-sage-200 bg-white p-4 shadow-xl">
                        <p class="mb-3 font-display font-semibold">Keyboard shortcuts</p>
                        <dl class="space-y-2 text-sm">
                            <div class="flex justify-between"><dt>Focus search</dt><dd><kbd class="rounded border border-sage-300 bg-sage-50 px-1.5 text-xs">/</kbd></dd></div>
                            <div class="flex justify-between"><dt>Close dialog</dt><dd><kbd class="rounded border border-sage-300 bg-sage-50 px-1.5 text-xs">Esc</kbd></dd></div>
                            <div class="flex justify-between"><dt>POS: charge order</dt><dd><kbd class="rounded border border-sage-300 bg-sage-50 px-1.5 text-xs">F9</kbd></dd></div>
                            <div class="flex justify-between"><dt>POS: clear cart</dt><dd><kbd class="rounded border border-sage-300 bg-sage-50 px-1.5 text-xs">F4</kbd></dd></div>
                        </dl>
                    </div>
                </div>

                {{-- User menu --}}
                <div x-data="{ open: false }" class="relative">
                    <button type="button" @click="open = !open" :aria-expanded="open" aria-haspopup="menu"
                            class="flex items-center gap-2 rounded-full border border-sage-200 bg-white py-1 pl-1 pr-2.5 hover:border-sage-300">
                        <span class="grid size-8 place-items-center rounded-full bg-leaf-600 font-display text-[13px] font-bold text-white">{{ $user->initials() }}</span>
                        <x-icon name="chevron-down" class="size-4 text-sage-500" />
                    </button>
                    <div x-show="open" x-cloak x-transition.origin.top.right @click.outside="open = false" role="menu"
                         class="absolute right-0 mt-2 w-60 overflow-hidden rounded-xl border border-sage-200 bg-white shadow-xl">
                        <div class="border-b border-sage-100 px-4 py-3">
                            <p class="truncate text-sm font-semibold">{{ $user->name }}</p>
                            <p class="truncate text-xs text-sage-500">{{ $user->email }}</p>
                        </div>
                        <a href="{{ route('profile') }}" wire:navigate class="flex items-center gap-2.5 px-4 py-2.5 text-sm hover:bg-sage-50" role="menuitem">
                            <x-icon name="user" class="size-4" /> My profile
                        </a>
                        <form method="POST" action="{{ route('logout') }}" x-ref="logout">
                            @csrf
                            <button type="button" role="menuitem" class="flex w-full items-center gap-2.5 px-4 py-2.5 text-sm text-danger-700 hover:bg-danger-50"
                                    @click="$confirm({ title: 'Log out?', message: 'You will need to sign in again to use KaffeTrack.', confirmText: 'Log out', tone: 'danger' }, () => $refs.logout.submit())">
                                <x-icon name="logout" class="size-4" /> Log out
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main id="main" class="flex-1 px-4 py-6 sm:px-6 lg:px-8">
            {{ $slot }}
        </main>
    </div>
</div>

<x-toasts />
<x-confirm-dialog />

{{-- Flash toast after a redirect (also works with wire:navigate) --}}
@if (session('toast'))
    <div x-data x-init="$store.toasts.push(@js(session('toast')))" class="hidden"></div>
@endif
</body>
</html>
