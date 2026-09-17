<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Landlord\PlatformAdministrator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PlatformAuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('platform.auth.login');
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $administrator = PlatformAdministrator::query()
            ->where('email', $validated['email'])
            ->first();

        if ($administrator === null || ! Hash::check($validated['password'], $administrator->password)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        if (! $administrator->is_active) {
            throw ValidationException::withMessages([
                'email' => __('access.login.inactive'),
            ]);
        }

        Auth::guard('platform')->login($administrator, (bool) ($validated['remember'] ?? false));
        $request->session()->regenerate();

        return redirect()->intended(route('platform.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('platform')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('platform.login');
    }
}
