@props(['title' => 'Platform Administration'])
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $title }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="app-body">
<div class="app-backdrop"><div class="app-backdrop__orb app-backdrop__orb--gold"></div><div class="app-backdrop__orb app-backdrop__orb--emerald"></div></div>
<div class="app-shell flex min-h-screen">
    <flux:sidebar sticky stashable class="app-sidebar-shell border-r">
        <flux:sidebar.toggle class="lg:hidden" icon="x-mark"/>
        <x-platform-navigation />
    </flux:sidebar>
    <main class="app-main flex-1">
        <div class="px-4 pt-4 lg:hidden"><flux:sidebar.toggle icon="bars-2" class="rounded-xl border bg-white shadow-sm"/></div>
        <div class="app-main-inner space-y-6">
        @if ($errors->any())<div class="rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-900" role="alert"><p class="font-semibold">Please correct the following:</p><ul class="mt-2 list-disc space-y-1 pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @if (session('status'))<div class="rounded-xl border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-900" role="status">{{ session('status') }}</div>@endif
        {{ $slot }}
    </div></main>
</div>
@fluxScripts
</body>
</html>
