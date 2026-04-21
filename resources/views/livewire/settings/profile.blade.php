<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    public string $name = '';
    public string $email = '';

    public function mount(): void
    {
        $this->name = (string) Auth::user()->name;
        $this->email = (string) Auth::user()->email;
    }

    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($user->id),
            ],
        ]);

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        $this->dispatch('profile-updated', name: $user->name);
    }
}; ?>

<section class="w-full">
    <div class="page-stack">
        <section class="page-hero p-6 lg:p-8">
            <div class="eyebrow">{{ __('ui.nav.settings') }}</div>
            <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('settings.account.profile.title') }}</h1>
            <p class="mt-4 max-w-3xl text-base leading-7 text-neutral-200">{{ __('settings.account.profile.subtitle') }}</p>
        </section>

        <x-settings.layout :heading="__('settings.account.profile.form_title')" :subheading="__('settings.account.profile.form_subtitle')">
            <form wire:submit="updateProfileInformation" class="admin-form-grid">
                <div class="admin-form-field admin-form-field--full">
                    <label for="profile-name" class="mb-1 block text-sm font-medium">{{ __('settings.account.profile.fields.name') }}</label>
                    <input id="profile-name" wire:model="name" type="text" name="name" required autofocus autocomplete="name" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('name') <div class="mt-1 text-sm text-red-200">{{ $message }}</div> @enderror
                </div>

                <div class="admin-form-field admin-form-field--full">
                    <label for="profile-email" class="mb-1 block text-sm font-medium">{{ __('settings.account.profile.fields.email') }}</label>
                    <input id="profile-email" wire:model="email" type="email" name="email" required autocomplete="email" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('email') <div class="mt-1 text-sm text-red-200">{{ $message }}</div> @enderror
                    <p class="mt-2 text-xs leading-5 text-neutral-400">{{ __('settings.account.profile.email_help') }}</p>
                </div>

                <div class="admin-action-cluster admin-form-field--full">
                    <button type="submit" class="pill-link pill-link--accent">{{ __('settings.common.actions.save') }}</button>
                    <x-action-message class="text-sm text-emerald-200" on="profile-updated">
                        {{ __('settings.account.profile.saved') }}
                    </x-action-message>
                </div>
            </form>
        </x-settings.layout>
    </div>
</section>
