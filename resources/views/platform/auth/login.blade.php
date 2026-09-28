<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ config('app.supported_locales.'.app()->getLocale().'.direction', 'ltr') }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('platform.login.title') }}</title>@vite(['resources/css/app.css', 'resources/js/app.js'])</head>
<body class="min-h-screen bg-zinc-50 p-6 dark:bg-zinc-950"><main class="mx-auto max-w-md rounded-xl bg-white p-6 shadow dark:bg-zinc-900">
    <div class="flex flex-col gap-6">
        <h1 class="text-2xl font-bold">{{ __('platform.login.title') }}</h1><p>{{ __('platform.login.description') }}</p>

        <form method="POST" action="{{ route('platform.login.store') }}" class="flex flex-col gap-6">
            @csrf

            <div>
                <flux:input
                    :label="__('platform.login.email')"
                    type="email"
                    name="email"
                    :value="old('email')"
                    required
                    autofocus
                    autocomplete="email"
                />

                @error('email')
                    <div class="mt-2 text-sm font-medium text-red-600">{{ $message }}</div>
                @enderror
            </div>

            <div>
                <flux:input
                    :label="__('platform.login.password')"
                    type="password"
                    name="password"
                    required
                    autocomplete="current-password"
                />
            </div>

            <label class="inline-flex items-center gap-3 text-sm text-zinc-700 dark:text-zinc-300">
                <input
                    type="checkbox"
                    name="remember"
                    value="1"
                    @checked(old('remember'))
                    class="h-4 w-4 rounded border-zinc-300 text-[var(--brand-primary)] focus:ring-[var(--brand-primary)]"
                >
                <span>{{ __('platform.login.remember') }}</span>
            </label>

            <flux:button variant="primary" type="submit" class="w-full">{{ __('platform.login.submit') }}</flux:button>
        </form>
    </div>
</main></body></html>
