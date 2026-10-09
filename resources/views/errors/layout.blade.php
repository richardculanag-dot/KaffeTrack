<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · KaffeTrack</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    @vite(['resources/css/app.css'])
</head>
<body class="grid h-full place-items-center bg-sage-50 p-6">
    <main class="w-full max-w-md text-center">
        <x-brand-mark class="mx-auto size-12" />
        <p class="mt-8 font-display text-6xl font-bold text-leaf-700">@yield('code')</p>
        <h1 class="mt-3 font-display text-2xl font-semibold">@yield('title')</h1>
        <p class="mt-2 text-sage-600">@yield('message')</p>
        <div class="mt-8 flex justify-center gap-2">
            <a href="javascript:history.back()" class="btn-secondary">Go back</a>
            <a href="{{ url('/') }}" class="btn-primary">Go to my home screen</a>
        </div>
    </main>
</body>
</html>
