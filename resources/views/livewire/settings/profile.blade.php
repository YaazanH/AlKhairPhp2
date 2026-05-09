<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public $profile_photo_upload = null;

    public function updatedProfilePhotoUpload(): void
    {
        $user = Auth::user()->loadMissing(['studentProfile', 'teacherProfile']);

        $this->validate([
            'profile_photo_upload' => ['required', 'image', 'max:2048'],
        ]);

        $user->storeProfilePhotoUpload($this->profile_photo_upload);

        $this->reset('profile_photo_upload');
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
            @php($profileUser = Auth::user()->loadMissing(['studentProfile', 'teacherProfile']))

            <div class="mb-6 rounded-3xl border border-white/10 bg-white/5 p-4">
                <div class="grid gap-4 md:grid-cols-[auto_minmax(0,1fr)] md:items-center">
                    <x-user-avatar :user="$profileUser" size="lg" />

                    <div>
                        <div class="text-sm font-semibold text-white">{{ __('settings.account.profile.fields.photo') }}</div>
                        <p class="mt-1 text-sm leading-6 text-neutral-400">
                            {{ $profileUser->usesLinkedProfilePhoto() ? __('settings.account.profile.photo_managed_by_profile') : __('settings.account.profile.photo_help') }}
                        </p>

                        <input wire:model.live="profile_photo_upload" type="file" accept="image/*" class="mt-3 block w-full text-sm">
                        @error('profile_photo_upload') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                    <div class="text-xs uppercase tracking-[0.18em] text-neutral-500">{{ __('settings.account.profile.fields.name') }}</div>
                    <div class="mt-2 text-base font-semibold text-white">{{ $profileUser->name }}</div>
                </div>
                <div class="rounded-2xl border border-white/10 bg-white/[0.03] p-4">
                    <div class="text-xs uppercase tracking-[0.18em] text-neutral-500">{{ __('settings.account.profile.fields.email') }}</div>
                    <div class="mt-2 text-base font-semibold text-white">{{ $profileUser->email ?: '-' }}</div>
                    <p class="mt-2 text-xs leading-5 text-neutral-400">{{ __('settings.account.profile.identity_locked') }}</p>
                </div>
            </div>

            <x-action-message class="mt-4 text-sm text-emerald-200" on="profile-updated">
                {{ __('settings.account.profile.saved') }}
            </x-action-message>
        </x-settings.layout>
    </div>
</section>
