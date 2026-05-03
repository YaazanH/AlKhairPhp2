<?php

use App\Models\Teacher;
use App\Services\ManagedUserService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.auth')] class extends Component {
    use WithFileUploads;

    public string $full_name = '';
    public string $username = '';
    public string $password = '';
    public $photo_upload = null;

    public function submit(): void
    {
        $validated = $this->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', Rule::unique('users', 'username')],
            'password' => ['required', 'string', 'min:8'],
            'photo_upload' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        [$firstName, $lastName] = $this->splitFullName($validated['full_name']);

        $result = app(ManagedUserService::class)->syncLinkedUser(
            null,
            [
                'name' => trim($validated['full_name']),
                'username' => $validated['username'],
                'password' => $validated['password'],
                'is_active' => false,
            ],
            'teacher',
        );

        $teacher = Teacher::query()->create([
            'user_id' => $result['user']->id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => '',
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
            'full_name',
            'username',
            'password',
            'photo_upload',
        ]);

        $this->resetValidation();
    }

    protected function splitFullName(string $fullName): array
    {
        $parts = collect(preg_split('/\s+/u', trim($fullName)) ?: [])
            ->filter(fn (?string $part) => filled($part))
            ->values();

        if ($parts->isEmpty()) {
            return ['', ''];
        }

        if ($parts->count() === 1) {
            return [$parts[0], $parts[0]];
        }

        return [
            (string) $parts->shift(),
            $parts->implode(' '),
        ];
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header :title="__('access.teacher_signup.title')" :description="__('access.teacher_signup.description')" />

    <x-auth-session-status class="text-center" :status="session('status')" />

    <form wire:submit="submit" class="flex flex-col gap-6">
        <flux:input
            wire:model="full_name"
            :label="__('access.teacher_signup.fields.full_name')"
            type="text"
            name="full_name"
            required
            autofocus
            :placeholder="__('access.teacher_signup.fields.full_name')"
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

    <div class="space-x-1 text-center text-sm text-zinc-600 dark:text-zinc-400">
        <x-text-link href="{{ route('home') }}">{{ __('access.teacher_signup.actions.back_home') }}</x-text-link>
    </div>
</div>
