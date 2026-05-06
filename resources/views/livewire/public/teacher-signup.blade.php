<?php

use App\Models\AppSetting;
use App\Models\Teacher;
use App\Services\ManagedUserService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.auth')] class extends Component {
    use WithFileUploads;

    public string $first_name = '';
    public string $last_name = '';
    public string $phone = '';
    public string $username = '';
    public string $password = '';
    public $photo_upload = null;

    public function mount(): void
    {
        $this->ensureSignupEnabled();
    }

    public function submit(): void
    {
        $this->ensureSignupEnabled();

        $validated = $this->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'username' => ['required', 'string', 'max:255', Rule::unique('users', 'username')],
            'password' => ['required', 'string', 'min:8'],
            'photo_upload' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $fullName = trim($validated['first_name'].' '.$validated['last_name']);

        $result = app(ManagedUserService::class)->syncLinkedUser(
            null,
            [
                'name' => $fullName,
                'username' => $validated['username'],
                'phone' => $validated['phone'],
                'password' => $validated['password'],
                'is_active' => false,
            ],
            'teacher',
        );

        $teacher = Teacher::query()->create([
            'user_id' => $result['user']->id,
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'phone' => $validated['phone'],
            'access_role_id' => null,
            'course_id' => null,
            'status' => 'pending',
            'is_helping' => false,
            'hired_at' => null,
            'notes' => null,
        ]);

        $teacher->forceFill([
            'photo_path' => $validated['photo_upload']->store('teachers/photos/'.$teacher->id, 'public'),
        ])->save();

        session()->flash('status', __('access.teacher_signup.messages.submitted'));

        $this->reset([
            'first_name',
            'last_name',
            'phone',
            'username',
            'password',
            'photo_upload',
        ]);

        $this->resetValidation();
    }

    protected function ensureSignupEnabled(): void
    {
        abort_unless((bool) (AppSetting::groupValues('website')->get('teacher_signup_enabled') ?? true), 404);
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header :title="__('access.teacher_signup.title')" :description="__('access.teacher_signup.description')" />

    <x-auth-session-status class="text-center" :status="session('status')" />

    <form wire:submit="submit" class="flex flex-col gap-6">
        <flux:input
            wire:model="first_name"
            :label="__('access.teacher_signup.fields.first_name')"
            type="text"
            name="first_name"
            required
            autofocus
            :placeholder="__('access.teacher_signup.fields.first_name')"
        />

        <flux:input
            wire:model="last_name"
            :label="__('access.teacher_signup.fields.last_name')"
            type="text"
            name="last_name"
            required
            :placeholder="__('access.teacher_signup.fields.last_name')"
        />

        <flux:input
            wire:model="phone"
            :label="__('access.teacher_signup.fields.phone')"
            type="text"
            name="phone"
            required
            autocomplete="tel"
            :placeholder="__('access.teacher_signup.fields.phone')"
        />

        <flux:input
            wire:model="username"
            :label="__('access.teacher_signup.fields.username')"
            type="text"
            name="username"
            required
            autocomplete="username"
            :placeholder="__('access.teacher_signup.fields.username')"
        />

        <flux:input
            wire:model="password"
            :label="__('access.teacher_signup.fields.password')"
            type="password"
            name="password"
            required
            autocomplete="new-password"
            :placeholder="__('access.teacher_signup.fields.password')"
        />

        <div class="grid gap-2">
            <label for="teacher-signup-photo" class="text-sm font-medium text-white">{{ __('access.teacher_signup.fields.personal_photo') }}</label>
            <input
                id="teacher-signup-photo"
                wire:model="photo_upload"
                type="file"
                accept="image/*"
                class="rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-sm text-neutral-200 file:mr-4 file:rounded-xl file:border-0 file:bg-emerald-500 file:px-3 file:py-2 file:text-sm file:font-medium file:text-white"
            >
            <p class="text-xs leading-5 text-neutral-400">{{ __('access.teacher_signup.help.photo') }}</p>
            @error('photo_upload')
                <div class="text-sm text-red-400">{{ $message }}</div>
            @enderror

            @if ($photo_upload)
                <img src="{{ $photo_upload->temporaryUrl() }}" alt="{{ __('access.teacher_signup.fields.personal_photo') }}" class="mt-2 h-24 w-24 rounded-3xl object-cover">
            @endif
        </div>

        <div class="flex items-center justify-end">
            <flux:button variant="primary" type="submit" class="w-full">{{ __('access.teacher_signup.actions.submit') }}</flux:button>
        </div>
    </form>
</div>
