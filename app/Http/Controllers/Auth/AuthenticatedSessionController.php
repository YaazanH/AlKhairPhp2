<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $this->ensureIsNotRateLimited($request, $validated['login']);

        $user = User::query()
            ->where(function ($query) use ($validated): void {
                $query
                    ->where('email', $validated['login'])
                    ->orWhere('username', $validated['login'])
                    ->orWhere('phone', $validated['login']);
            })
            ->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($this->throttleKey($request, $validated['login']));

            throw ValidationException::withMessages([
                'login' => __('auth.failed'),
            ]);
        }

        if (! $user->is_active) {
            RateLimiter::hit($this->throttleKey($request, $validated['login']));

            throw ValidationException::withMessages([
                'login' => __('access.login.inactive'),
            ]);
        }

        Auth::login($user, (bool) ($validated['remember'] ?? false));
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        RateLimiter::clear($this->throttleKey($request, $validated['login']));
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    /**
     * @throws ValidationException
     */
    protected function ensureIsNotRateLimited(Request $request, string $login): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($request, $login), 5)) {
            return;
        }

        event(new Lockout($request));

        $seconds = RateLimiter::availableIn($this->throttleKey($request, $login));

        throw ValidationException::withMessages([
            'login' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    protected function throttleKey(Request $request, string $login): string
    {
        return Str::transliterate(Str::lower($login).'|'.$request->ip());
    }
}
