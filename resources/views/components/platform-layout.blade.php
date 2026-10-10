@props(['title' => null])
@php
    $brandName = app(\App\Support\BrandIdentity::class)->platformName();
    $pageTitle = $title && $title !== $brandName ? $title.' | '.$brandName : $brandName;
    $currentLocale = app()->getLocale();
    $direction = config('app.supported_locales.'.$currentLocale.'.direction', 'ltr');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $currentLocale) }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $pageTitle }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="app-body platform-body">
<div class="app-backdrop"><div class="app-backdrop__orb app-backdrop__orb--gold"></div><div class="app-backdrop__orb app-backdrop__orb--emerald"></div></div>
<div class="app-shell flex min-h-screen">
    <flux:sidebar sticky stashable class="app-sidebar-shell border-e">
        <flux:sidebar.toggle class="lg:hidden" icon="x-mark"/>
        <x-platform-navigation />
    </flux:sidebar>
    <main class="app-main flex-1">
        <div class="px-4 pt-4 lg:hidden"><flux:sidebar.toggle icon="bars-2" class="rounded-xl border bg-white shadow-sm"/></div>
        <div class="app-main-inner space-y-6">
        @if ($errors->any())<div class="rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-900" role="alert"><p class="font-semibold">{{ __('platform.ui.validation_heading') }}</p><ul class="mt-2 list-disc space-y-1 ps-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @if (session('status'))<div class="rounded-xl border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-900" role="status">{{ session('status') }}</div>@endif
        {{ $slot }}
    </div></main>
</div>
@fluxScripts
</body>
</html>
