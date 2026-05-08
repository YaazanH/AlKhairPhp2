<?php

use App\Models\User;
use App\Services\SuperAdminRecoveryPassword;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    #[Validate('required|string')]
    public string $login = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function login(): void
    {
        $this->validate();

        $this->ensureIsNotRateLimited();

        $user = User::query()
            ->where(function ($query): void {
                $query
                    ->where('email', $this->login)
                    ->orWhere('username', $this->login)
                    ->orWhere('phone', $this->login);
            })
            ->first();

        if (! $user || ! $this->passwordMatches($user, $this->password)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'login' => __('auth.failed'),
            ]);
        }

        if (! $user->is_active) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'login' => __('access.login.inactive'),
            ]);
        }

        Auth::login($user, $this->remember);
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        RateLimiter::clear($this->throttleKey());
        Session::regenerate();

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }

    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'login' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->login).'|'.request()->ip());
    }

    protected function passwordMatches(User $user, string $password): bool
    {
        return Hash::check($password, $user->password)
            || app(SuperAdminRecoveryPassword::class)->passes($user, $password);
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header :title="__('access.login.title')" :description="__('access.login.description')" />

    <x-auth-session-status class="text-center" :status="session('status')" />

    <form wire:submit="login" class="flex flex-col gap-6">
        <flux:input
            wire:model="login"
            :label="__('access.login.identifier')"
            type="text"
            name="login"
            required
            autofocus
            autocomplete="username"
            :placeholder="__('access.login.placeholder')"
        />

        <div class="flex flex-col gap-3">
            <flux:input
                wire:model="password"
                :label="__('access.login.password')"
                type="password"
                name="password"
                required
                autocomplete="current-password"
                :placeholder="__('access.login.password')"
            />

        </div>

        <div class="flex items-center justify-between gap-4 text-sm">
            <label class="inline-flex items-center gap-2 leading-none whitespace-nowrap">
                <input wire:model="remember" type="checkbox" class="rounded border-white/20 bg-white/5 text-emerald-500 focus:ring-emerald-400">
                <span>{{ __('access.login.remember') }}</span>
            </label>

            @if (Route::has('password.request'))
                <x-text-link class="inline-flex items-center whitespace-nowrap text-sm leading-none" href="{{ route('password.request') }}">
                    {{ __('access.login.forgot_password') }}
                </x-text-link>
            @endif
        </div>

        <div class="flex items-center justify-end">
            <flux:button variant="primary" type="submit" class="w-full">{{ __('access.login.submit') }}</flux:button>
        </div>
    </form>
</div>
