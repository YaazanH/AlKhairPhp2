<?php

use App\Services\ManagedUserService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public $profile_photo_upload = null;

    public string $username = '';

    public string $email = '';

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(): void
    {
        $this->username = (string) Auth::user()->username;
        $this->email = (string) Auth::user()->email;
    }

    public function saveProfile(ManagedUserService $managedUsers): void
    {
        $user = Auth::user()->loadMissing(['studentProfile', 'teacherProfile']);

        if ($user->hasImmutableUsername() && $this->username !== $user->username) {
            throw ValidationException::withMessages(['username' => __('access.profile_accounts.username_locked')]);
        }

        $validated = $this->validate([
            'username' => ['required', 'string', 'max:255'],
            'profile_photo_upload' => ['nullable', 'image', 'max:'.config('uploads.image_max_kb')],
        ]);

        if (! $user->hasImmutableUsername()) {
            $username = $managedUsers->uniqueUsername($validated['username'], $user->name, $user->id);
            $email = $managedUsers->uniqueEmail(null, $username, $user->id);

            $user->forceFill([
                'username' => $username,
                'email' => $email,
            ])->save();

            $this->username = $username;
            $this->email = $email;
            $this->dispatch('username-updated');
        }

        if ($this->profile_photo_upload) {
            $user->storeProfilePhotoUpload($this->profile_photo_upload);
            $this->reset('profile_photo_upload');
        }

        $this->dispatch('profile-updated', name: $user->name);
        $this->dispatch('profile-saved');
    }

    public function updateUsername(ManagedUserService $managedUsers): void
    {
        $this->saveProfile($managedUsers);
    }

    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => ['required', 'string', 'current_password'],
                'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            ]);
        } catch (ValidationException $exception) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $exception;
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
            <div class="eyebrow">{{ __('settings.account.eyebrow') }}</div>
            <h1 class="font-display mt-4 text-4xl leading-none text-white md:text-5xl">{{ __('settings.account.title') }}</h1>
        </section>

        @php($profileUser = Auth::user()->loadMissing(['studentProfile', 'teacherProfile']))

        <section class="surface-panel p-5 lg:p-6" data-account-profile-section>
            <form wire:submit="saveProfile">
                <div class="mb-6 flex items-center justify-between gap-4">
                    <h2 class="font-display text-3xl text-white">{{ __('settings.account.profile.form_title') }}</h2>
                    <button type="submit" class="admin-icon-button admin-icon-button--accent shrink-0" title="{{ __('settings.common.actions.save') }}" aria-label="{{ __('settings.common.actions.save') }}" wire:loading.attr="disabled" wire:target="saveProfile,profile_photo_upload" data-account-profile-save-action>
                        <x-admin-action-icon name="save" />
                    </button>
                </div>

                <div class="grid gap-5 lg:grid-cols-2 lg:items-stretch">
                    <div class="rounded-3xl border border-white/10 bg-white/5 p-5" data-account-profile-photo-card>
                        <div class="flex h-full min-w-0 items-center gap-4" data-account-profile-photo-layout>
                            <x-user-avatar :user="$profileUser" size="lg" />

                            <div class="min-w-0 flex-1">
                                <label for="account-profile-photo" class="text-sm font-semibold text-white">{{ __('settings.account.profile.fields.photo') }}</label>
                                <input id="account-profile-photo" wire:model="profile_photo_upload" type="file" accept="image/*" class="mt-3 block w-full min-w-0 max-w-full overflow-hidden text-sm">
                                @error('profile_photo_upload') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>

                    <div class="rounded-3xl border border-white/10 bg-white/5 p-5">
                        <label for="account-username" class="mb-1 block text-sm font-medium">{{ __('settings.account.profile.fields.username') }}</label>
                        <input id="account-username" wire:model="username" @readonly($profileUser->hasImmutableUsername()) type="text" required autocomplete="username" class="w-full rounded-xl px-4 py-3 text-sm">
                        @error('username') <div class="mt-1 text-sm text-red-400">{{ $message }}</div> @enderror
                    </div>
                </div>

                <x-action-message class="mt-3 text-sm text-emerald-200" on="profile-saved">
                    {{ __('settings.account.profile.saved') }}
                </x-action-message>
            </form>
        </section>

        <section class="surface-panel p-5 lg:p-6" data-account-password-section>
            <div class="mb-6">
                <h2 class="font-display text-3xl text-white">{{ __('settings.account.password.form_title') }}</h2>
            </div>

            <form wire:submit="updatePassword" class="admin-form-grid">
                <div class="admin-form-field admin-form-field--full">
                    <label for="update_password_current_password" class="mb-1 block text-sm font-medium">{{ __('settings.account.password.fields.current_password') }}</label>
                    <input id="update_password_current_password" wire:model="current_password" type="password" name="current_password" required autocomplete="current-password" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('current_password') <div class="mt-1 text-sm text-red-200">{{ $message }}</div> @enderror
                </div>

                <div class="admin-form-field">
                    <label for="update_password_password" class="mb-1 block text-sm font-medium">{{ __('settings.account.password.fields.password') }}</label>
                    <input id="update_password_password" wire:model="password" type="password" name="password" required autocomplete="new-password" class="w-full rounded-xl px-4 py-3 text-sm">
                    @error('password') <div class="mt-1 text-sm text-red-200">{{ $message }}</div> @enderror
                </div>

                <div class="admin-form-field">
                    <label for="update_password_password_confirmation" class="mb-1 block text-sm font-medium">{{ __('settings.account.password.fields.password_confirmation') }}</label>
                    <input id="update_password_password_confirmation" wire:model="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" class="w-full rounded-xl px-4 py-3 text-sm">
                </div>

                <div class="admin-action-cluster admin-form-field--full">
                    <button type="submit" class="pill-link pill-link--accent">{{ __('settings.account.password.actions.reset') }}</button>
                    <x-action-message class="text-sm text-emerald-200" on="password-updated">
                        {{ __('settings.account.password.saved') }}
                    </x-action-message>
                </div>
            </form>
        </section>
    </div>
</section>
