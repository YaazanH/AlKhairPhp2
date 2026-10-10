<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class PlatformRequiredPasswordChangeController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if (! $request->user('platform')->must_change_password) {
            return redirect()->route('platform.dashboard');
        }

        return view('platform.auth.required-password-change');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ]);
        $administrator = $request->user('platform');

        if (! Hash::check($data['current_password'], $administrator->password)) {
            return back()->withErrors(['current_password' => 'The current password is incorrect.']);
        }

        $administrator->update(['password' => $data['password'], 'must_change_password' => false, 'password_changed_at' => now()]);
        $request->session()->regenerate();

        return redirect()->route('platform.dashboard')->with('status', 'Your password has been changed.');
    }
}
