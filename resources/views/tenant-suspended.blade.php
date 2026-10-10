<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $tenant->name }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-zinc-950 text-white">
    <main class="mx-auto grid min-h-screen max-w-2xl place-items-center px-6 py-16 text-center">
        <section class="rounded-3xl border border-white/10 bg-white/5 p-8 shadow-2xl">
            <div class="mx-auto grid size-14 place-items-center rounded-2xl bg-amber-300/15 text-2xl text-amber-200">!</div>
            <h1 class="mt-6 text-3xl font-bold">{{ __('subscription.suspended.title') }}</h1>
            <p class="mt-4 leading-7 text-zinc-300">{{ __('subscription.suspended.message', ['organisation' => $tenant->name]) }}</p>
        </section>
    </main>
</body>
</html>
