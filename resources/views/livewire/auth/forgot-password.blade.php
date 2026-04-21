<?php

use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Session;

new #[Layout('components.layouts.auth')] class extends Component {
    public string $email = '';

    public function sendPasswordResetLink(): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        Password::sendResetLink(['email' => $this->email]);

        Session::flash('status', __('access.password_help.contact_management'));
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header :title="__('access.password_help.title')" :description="__('access.password_help.description')" />

    <div class="rounded-2xl border border-emerald-400/20 bg-emerald-500/10 px-4 py-4 text-center text-sm leading-7 text-emerald-50">
        {{ __('access.password_help.contact_management') }}
    </div>

    <div class="text-center text-sm text-zinc-400">
        <x-text-link href="{{ route('login') }}">{{ __('access.password_help.back_to_login') }}</x-text-link>
    </div>
</div>
