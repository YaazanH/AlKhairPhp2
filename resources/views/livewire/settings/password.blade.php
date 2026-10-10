<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component {
    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => ['required', 'string', 'current_password'],
                'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        Auth::user()->update([
            'password' => Hash::make($validated['password']),
            'issued_password' => null,
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        $this->dispatch('password-updated');
    }
}; ?>

<section class="w-full">
    <div class="page-stack">
        <section class="page-hero p-6 lg:p-8">
            <div class="eyebrow">{{ __('ui.nav.settings') }}</div>
            <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('settings.account.password.title') }}</h1>
            <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('settings.account.password.subtitle') }}</p>
        </section>

        <x-settings.layout :heading="__('settings.account.password.form_title')" :subheading="__('settings.account.password.form_subtitle')">
        <form wire:submit="updatePassword" class="admin-form-grid">
            <div class="admin-form-field admin-form-field--full">
                <label for="update_password_current_password" class="mb-1 block text-sm font-medium">{{ __('settings.account.password.fields.current_password') }}</label>
                <input id="update_password_current_password" wire:model="current_password" type="password" name="current_password" required autocomplete="current-password" class="w-full rounded-xl px-4 py-3 text-sm">
                @error('current_password') <div class="mt-1 text-sm text-red-200">{{ $message }}</div> @enderror
            </div>

            <div class="admin-form-field admin-form-field--full">
                <label for="update_password_password" class="mb-1 block text-sm font-medium">{{ __('settings.account.password.fields.password') }}</label>
                <input id="update_password_password" wire:model="password" type="password" name="password" required autocomplete="new-password" class="w-full rounded-xl px-4 py-3 text-sm">
                @error('password') <div class="mt-1 text-sm text-red-200">{{ $message }}</div> @enderror
            </div>

            <div class="admin-form-field admin-form-field--full">
                <label for="update_password_password_confirmation" class="mb-1 block text-sm font-medium">{{ __('settings.account.password.fields.password_confirmation') }}</label>
                <input id="update_password_password_confirmation" wire:model="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" class="w-full rounded-xl px-4 py-3 text-sm">
            </div>

            <div class="admin-action-cluster admin-form-field--full">
                <button type="submit" class="pill-link pill-link--accent">{{ __('settings.common.actions.save') }}</button>
                <x-action-message class="text-sm text-emerald-200" on="password-updated">
                    {{ __('settings.account.password.saved') }}
                </x-action-message>
            </div>
        </form>
        </x-settings.layout>
    </div>
</section>
