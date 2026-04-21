<x-layouts.auth>
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('access.login.title')" :description="__('access.login.description')" />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
            @csrf

            <div>
                <flux:input
                    :label="__('access.login.identifier')"
                    type="text"
                    name="login"
                    :value="old('login')"
                    required
                    autofocus
                    autocomplete="username"
                    :placeholder="__('access.login.placeholder')"
                />

                @error('login')
                    <div class="mt-2 text-sm font-medium text-red-600">{{ $message }}</div>
                @enderror
            </div>

            <div class="relative">
                <flux:input
                    :label="__('access.login.password')"
                    type="password"
                    name="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('access.login.password')"
                />

                @if (Route::has('password.request'))
                    <x-text-link class="mt-2 block text-end text-sm" href="{{ route('password.request') }}">
                        {{ __('access.login.forgot_password') }}
                    </x-text-link>
                @endif

                @error('password')
                    <div class="mt-2 text-sm font-medium text-red-600">{{ $message }}</div>
                @enderror
            </div>

            <label class="flex items-center gap-3 text-sm text-zinc-700 dark:text-zinc-300">
                <input
                    type="checkbox"
                    name="remember"
                    value="1"
                    @checked(old('remember'))
                    class="h-4 w-4 rounded border-zinc-300 text-[var(--brand-primary)] focus:ring-[var(--brand-primary)]"
                >
                <span>{{ __('access.login.remember') }}</span>
            </label>

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full">{{ __('access.login.submit') }}</flux:button>
            </div>
        </form>
    </div>
</x-layouts.auth>
